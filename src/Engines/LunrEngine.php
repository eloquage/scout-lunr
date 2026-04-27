<?php

declare(strict_types=1);

namespace IllumaLaw\ScoutLunr\Engines;

use IllumaLaw\ScoutLunr\Contracts\LunrConfigurable;
use IllumaLaw\ScoutLunr\Query\LunrQueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

/**
 * @see https://lunrjs.com/guides/searching.html Lunr query syntax reference
 */
final class LunrEngine extends Engine
{
    /**
     * @param  string  $workerUrl  Base URL of the Cloudflare Worker (no trailing slash)
     * @param  string  $secret  Bearer token sent on every request
     * @param  int  $timeout  HTTP timeout in seconds
     */
    public function __construct(
        protected readonly string $workerUrl,
        protected readonly string $secret,
        protected readonly int $timeout = 25,
    ) {}

    /**
     * If the model implements {@see LunrConfigurable}, per-model field boosts,
     *
     * @param  EloquentCollection<int, Model>  $models
     *
     * @throws \RuntimeException When the Worker returns a non-2xx response.
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $first = $models->first();

        $documents = $models->map(function ($model) {
            return array_merge(
                ['id' => (string) $model->getScoutKey()],
                $model->toSearchableArray(),
            );
        })->values()->all();

        $payload = [
            'index'       => $first->searchableAs(),
            'documents'   => $documents,
            'fieldBoosts' => $this->resolveFieldBoosts($first),
            'bm25'        => $this->resolveBm25($first),
            'language'    => $this->resolveLanguage($first),
        ];

        $response = $this->http()->post("{$this->workerUrl}/update", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Lunr Worker /update failed [{$response->status()}]: {$response->body()}"
            );
        }
    }

    /**
     * @param  EloquentCollection<int, Model>  $models
     *
     * @throws \RuntimeException When the Worker returns a non-2xx response.
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $ids = $models->map(fn ($model) => (string) $model->getScoutKey())->values()->all();

        $response = $this->http()->post("{$this->workerUrl}/delete", [
            'index' => $models->first()->searchableAs(),
            'ids'   => $ids,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Lunr Worker /delete failed [{$response->status()}]: {$response->body()}"
            );
        }
    }

    /**
     * @param  Model  $model
     *
     * @throws \RuntimeException When the Worker returns a non-2xx response.
     */
    public function flush($model): void
    {
        $response = $this->http()->post("{$this->workerUrl}/flush", [
            'index' => $model->searchableAs(),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Lunr Worker /flush failed [{$response->status()}]: {$response->body()}"
            );
        }
    }

    /**
     * @param  string  $name  Index name (typically the model's table name)
     * @param  array<string, mixed>  $options  Ignored
     */
    public function createIndex($name, array $options = []): void
    {
        //
    }

    /**
     * @throws \RuntimeException When the Worker returns a non-2xx response.
     */
    public function deleteIndex($name): void
    {
        $response = $this->http()->post("{$this->workerUrl}/flush", [
            'index' => $name,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Lunr Worker /flush failed [{$response->status()}]: {$response->body()}"
            );
        }
    }

    /**
     * This array is then passed to {@see map()}, {@see mapIds()}, and
     * {@see getTotalCount()} by Scout's internals.
     * Use {@see LunrQueryBuilder} to build
     *
     * @return array{hits: list<array{ref: string, score: float}>, total: int}
     */
    public function search(Builder $builder): array
    {
        return $this->performSearch($builder);
    }

    /**
     * @param  int  $perPage  Number of results per page
     * @param  int  $page  1-based page number
     * @return array{hits: list<array{ref: string, score: float}>, total: int}
     */
    public function paginate(Builder $builder, $perPage, $page): array
    {
        $results = $this->performSearch($builder);

        $offset = ($page - 1) * $perPage;
        $results['hits'] = array_slice($results['hits'], $offset, $perPage);

        return $results;
    }

    /**
     * This collection is used by Scout internally and by {@see map()} to
     *
     * @param  array{hits: list<array{ref: string, score: float}>}  $results
     * @return Collection<int, string>
     */
    public function mapIds($results): Collection
    {
        return collect(array_column($results['hits'] ?? [], 'ref'));
    }

    /**
     * @param  array{hits: list<array{ref: string, score: float}>, total: int}  $results
     * @return EloquentCollection<int, Model>
     */
    public function map(Builder $builder, $results, $model): EloquentCollection
    {
        if (empty($results['hits'])) {
            return $model->newCollection();
        }

        $ids = $this->mapIds($results);
        $positions = $ids->flip();

        $models = $model->getScoutModelsByIds($builder, $ids->all());
        $models = $this->applyWhereFilters($builder, $models);

        return $models->sortBy(function ($item) use ($positions) {
            return $positions[(string) $item->getScoutKey()] ?? PHP_INT_MAX;
        })->values();
    }

    /**
     * Identical to {@see map()} but returns a {@see LazyCollection} backed by
     *
     * @param  array{hits: list<array{ref: string, score: float}>, total: int}  $results
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        if (empty($results['hits'])) {
            return LazyCollection::empty();
        }

        $ids = $this->mapIds($results);
        $positions = $ids->flip();

        return $model->queryScoutModelsByIds($builder, $ids->all())
            ->cursor()
            ->sortBy(function ($item) use ($positions) {
                return $positions[(string) $item->getScoutKey()] ?? PHP_INT_MAX;
            });
    }

    /**
     * @param  array{hits: list<mixed>, total?: int}  $results
     */
    public function getTotalCount($results): int
    {
        return (int) ($results['total'] ?? count($results['hits'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    public function info(string $index): array
    {
        $response = $this->http()->get("{$this->workerUrl}/info", ['index' => $index]);

        if (! $response->successful()) {
            Log::warning('Lunr Worker /info failed', [
                'status' => $response->status(),
                'index'  => $index,
            ]);

            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * @return array{hits: list<array{ref: string, score: float}>, total: int}
     */
    private function performSearch(Builder $builder): array
    {
        if (empty($builder->query)) {
            return ['hits' => [], 'total' => 0];
        }

        $params = [
            'index' => $builder->index ?: $builder->model->searchableAs(),
            'q'     => $builder->query,
        ];

        if ($builder->limit > 0) {
            $params['limit'] = $builder->limit;
        }

        $response = $this->http()->get("{$this->workerUrl}/search", $params);

        if (! $response->successful()) {
            Log::warning('Lunr Worker /search failed', [
                'status' => $response->status(),
                'index'  => $params['index'],
                'query'  => $builder->query,
            ]);

            return ['hits' => [], 'total' => 0];
        }

        return $response->json() ?? ['hits' => [], 'total' => 0];
    }

    /**
     * @param  EloquentCollection<int, Model>  $models
     * @return EloquentCollection<int, Model>
     */
    private function applyWhereFilters(Builder $builder, EloquentCollection $models): EloquentCollection
    {
        foreach ($builder->wheres as $field => $value) {
            $models = $models->where($field, $value);
        }

        return $models->values();
    }

    /**
     * @return array<string, int|float>
     */
    private function resolveFieldBoosts(mixed $model): array
    {
        if ($model instanceof LunrConfigurable) {
            return $model->searchableBoosts();
        }

        return (array) config('scout-lunr.field_boosts', []);
    }

    /**
     * @return array{k1: float, b: float}
     */
    private function resolveBm25(mixed $model): array
    {
        if ($model instanceof LunrConfigurable) {
            $bm25 = $model->searchableBm25();

            return [
                'k1' => (float) ($bm25['k1'] ?? config('scout-lunr.k1', 1.2)),
                'b'  => (float) ($bm25['b'] ?? config('scout-lunr.b', 0.75)),
            ];
        }

        return [
            'k1' => (float) config('scout-lunr.k1', 1.2),
            'b'  => (float) config('scout-lunr.b', 0.75),
        ];
    }

    private function resolveLanguage(mixed $model): string
    {
        if ($model instanceof LunrConfigurable) {
            return $model->searchableLanguage();
        }

        return (string) config('scout-lunr.language', 'en');
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->secret)->timeout($this->timeout);
    }
}
