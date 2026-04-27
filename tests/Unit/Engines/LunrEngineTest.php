<?php

declare(strict_types=1);

use IllumaLaw\ScoutLunr\Contracts\LunrConfigurable;
use IllumaLaw\ScoutLunr\Engines\LunrEngine;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Builder;

function makeEngine(): LunrEngine
{
    return new LunrEngine(
        workerUrl: 'https://lunr.test',
        secret: 'test-secret',
        timeout: 5,
    );
}

function makeModel(string $id, string $index = 'posts', array $searchable = []): object
{
    $model = Mockery::mock(Model::class);
    $model->shouldReceive('getScoutKey')->andReturn($id);
    $model->shouldReceive('searchableAs')->andReturn($index);
    $model->shouldReceive('toSearchableArray')->andReturn(
        array_merge(['title' => "Post {$id}", 'body' => 'content'], $searchable)
    );

    return $model;
}

function makeConfigurableModel(
    string $id,
    string $index = 'posts',
    array $boosts = ['title' => 10, 'body' => 1],
    array $bm25 = ['k1' => 1.3, 'b' => 0.0],
    string $language = 'fr',
): object {
    $model = Mockery::mock(Model::class, LunrConfigurable::class);
    $model->shouldReceive('getScoutKey')->andReturn($id);
    $model->shouldReceive('searchableAs')->andReturn($index);
    $model->shouldReceive('toSearchableArray')->andReturn(['title' => "Post {$id}", 'body' => 'content']);
    $model->shouldReceive('searchableBoosts')->andReturn($boosts);
    $model->shouldReceive('searchableBm25')->andReturn($bm25);
    $model->shouldReceive('searchableLanguage')->andReturn($language);

    return $model;
}

function makeCollection(array $models): EloquentCollection
{
    return new EloquentCollection($models);
}

it('sends POST /update with correct index and document payload', function () {
    Http::fake(['https://lunr.test/update' => Http::response(['success' => true, 'count' => 1])]);

    makeEngine()->update(makeCollection([makeModel('42', 'posts')]));

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://lunr.test/update'
            && $request->method() === 'POST'
            && $body['index'] === 'posts'
            && count($body['documents']) === 1
            && $body['documents'][0]['id'] === '42'
            && $body['documents'][0]['title'] === 'Post 42';
    });
});

it('sends Authorization Bearer header on update', function () {
    Http::fake(['https://lunr.test/update' => Http::response(['success' => true])]);

    makeEngine()->update(makeCollection([makeModel('1')]));

    Http::assertSent(fn (Request $r) => $r->header('Authorization')[0] === 'Bearer test-secret');
});

it('skips HTTP call when update receives empty collection', function () {
    Http::fake();

    makeEngine()->update(makeCollection([]));

    Http::assertNothingSent();
});

it('throws RuntimeException when update returns non-2xx', function () {
    Http::fake(['https://lunr.test/update' => Http::response('error', 500)]);

    expect(fn () => makeEngine()->update(makeCollection([makeModel('1')])))
        ->toThrow(RuntimeException::class, '/update failed');
});

it('sends fieldBoosts from LunrConfigurable model', function () {
    Http::fake(['https://lunr.test/update' => Http::response(['success' => true])]);

    $model = makeConfigurableModel('1', boosts: ['title' => 10, 'body' => 2]);
    makeEngine()->update(makeCollection([$model]));

    Http::assertSent(function (Request $request) {
        $boosts = $request->data()['fieldBoosts'] ?? [];

        return $boosts['title'] === 10 && $boosts['body'] === 2;
    });
});

it('sends bm25 from LunrConfigurable model', function () {
    Http::fake(['https://lunr.test/update' => Http::response(['success' => true])]);

    $model = makeConfigurableModel('1', bm25: ['k1' => 1.3, 'b' => 0.0]);
    makeEngine()->update(makeCollection([$model]));

    Http::assertSent(function (Request $request) {
        $bm25 = $request->data()['bm25'] ?? [];

        return $bm25['k1'] === 1.3 && $bm25['b'] === 0.0;
    });
});

it('sends language from LunrConfigurable model', function () {
    Http::fake(['https://lunr.test/update' => Http::response(['success' => true])]);

    $model = makeConfigurableModel('1', language: 'de');
    makeEngine()->update(makeCollection([$model]));

    Http::assertSent(fn (Request $r) => $r->data()['language'] === 'de');
});

it('falls back to global config when model does not implement LunrConfigurable', function () {
    Http::fake(['https://lunr.test/update' => Http::response(['success' => true])]);

    config(['scout-lunr.k1' => 1.5, 'scout-lunr.b' => 0.5, 'scout-lunr.language' => 'nl']);

    makeEngine()->update(makeCollection([makeModel('1')]));

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $body['bm25']['k1'] === 1.5
            && $body['bm25']['b'] === 0.5
            && $body['language'] === 'nl';
    });
});

it('sends POST /delete with correct index and ids', function () {
    Http::fake(['https://lunr.test/delete' => Http::response(['success' => true])]);

    $models = makeCollection([makeModel('10', 'posts'), makeModel('11', 'posts')]);

    makeEngine()->delete($models);

    Http::assertSent(function (Request $request) {
        $body = $request->data();

        return $request->url() === 'https://lunr.test/delete'
            && $body['index'] === 'posts'
            && $body['ids'] === ['10', '11'];
    });
});

it('throws RuntimeException when delete returns non-2xx', function () {
    Http::fake(['https://lunr.test/delete' => Http::response('error', 500)]);

    expect(fn () => makeEngine()->delete(makeCollection([makeModel('1')])))
        ->toThrow(RuntimeException::class, '/delete failed');
});

it('sends POST /flush with correct index name', function () {
    Http::fake(['https://lunr.test/flush' => Http::response(['success' => true])]);

    makeEngine()->flush(makeModel('1', 'articles'));

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://lunr.test/flush'
            && $request->data()['index'] === 'articles';
    });
});

it('throws RuntimeException when flush returns non-2xx', function () {
    Http::fake(['https://lunr.test/flush' => Http::response('error', 500)]);

    expect(fn () => makeEngine()->flush(makeModel('1')))
        ->toThrow(RuntimeException::class, '/flush failed');
});

it('sends GET /search with index and query params', function () {
    Http::fake(['https://lunr.test/search*' => Http::response(['hits' => [], 'total' => 0])]);

    $model = makeModel('1', 'posts');
    $builder = new Builder($model, 'cloudflare');

    makeEngine()->search($builder);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/search')
            && $request->method() === 'GET'
            && str_contains($request->url(), 'index=posts')
            && str_contains($request->url(), 'q=cloudflare');
    });
});

it('returns empty result without HTTP call when query is empty', function () {
    Http::fake();

    $model = makeModel('1', 'posts');
    $builder = new Builder($model, '');

    $result = makeEngine()->search($builder);

    expect($result)->toBe(['hits' => [], 'total' => 0]);
    Http::assertNothingSent();
});

it('returns empty result and logs warning when search returns non-2xx', function () {
    Http::fake(['https://lunr.test/search*' => Http::response('error', 503)]);
    Log::shouldReceive('warning')->once()->with('Lunr Worker /search failed', Mockery::type('array'));

    $model = makeModel('1', 'posts');
    $builder = new Builder($model, 'laravel');

    $result = makeEngine()->search($builder);

    expect($result)->toBe(['hits' => [], 'total' => 0]);
});

it('forwards limit from Builder to the search request', function () {
    Http::fake(['https://lunr.test/search*' => Http::response(['hits' => [], 'total' => 0])]);

    $model = makeModel('1', 'posts');
    $builder = new Builder($model, 'laravel');
    $builder->limit = 10;

    makeEngine()->search($builder);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'limit=10'));
});

it('omits limit param when Builder has no limit set', function () {
    Http::fake(['https://lunr.test/search*' => Http::response(['hits' => [], 'total' => 0])]);

    $model = makeModel('1', 'posts');
    $builder = new Builder($model, 'laravel');

    makeEngine()->search($builder);

    Http::assertSent(fn (Request $r) => ! str_contains($r->url(), 'limit='));
});

it('slices results for pagination while preserving total', function () {
    $allHits = array_map(fn ($i) => ['ref' => (string) $i, 'score' => 1.0], range(1, 10));

    Http::fake([
        'https://lunr.test/search*' => Http::response(['hits' => $allHits, 'total' => 10]),
    ]);

    $model = makeModel('1', 'posts');
    $builder = new Builder($model, 'test');

    $result = makeEngine()->paginate($builder, perPage: 3, page: 2);

    expect($result['total'])->toBe(10)
        ->and($result['hits'])->toHaveCount(3)
        ->and($result['hits'][0]['ref'])->toBe('4');
});

it('extracts refs as the ID list', function () {
    $results = [
        'hits' => [
            ['ref' => '5', 'score' => 0.9],
            ['ref' => '3', 'score' => 0.7],
        ],
        'total' => 2,
    ];

    expect(makeEngine()->mapIds($results)->all())->toBe(['5', '3']);
});

it('returns empty collection for empty hits', function () {
    expect(makeEngine()->mapIds(['hits' => [], 'total' => 0])->all())->toBe([]);
});

it('returns total from result total field', function () {
    expect(makeEngine()->getTotalCount(['hits' => [], 'total' => 42]))->toBe(42);
});

it('falls back to counting hits when total is absent', function () {
    expect(makeEngine()->getTotalCount(['hits' => [['ref' => '1'], ['ref' => '2']]]))->toBe(2);
});

it('preserves Lunr score order when mapping to Eloquent models', function () {
    $results = [
        'hits' => [
            ['ref' => '3', 'score' => 0.9],
            ['ref' => '1', 'score' => 0.7],
            ['ref' => '2', 'score' => 0.5],
        ],
        'total' => 3,
    ];

    $modelA = Mockery::mock(Model::class);
    $modelA->shouldReceive('getScoutKey')->andReturn('1');

    $modelB = Mockery::mock(Model::class);
    $modelB->shouldReceive('getScoutKey')->andReturn('2');

    $modelC = Mockery::mock(Model::class);
    $modelC->shouldReceive('getScoutKey')->andReturn('3');

    $dbCollection = new EloquentCollection([$modelA, $modelB, $modelC]);

    $baseModel = Mockery::mock(Model::class);
    $baseModel->shouldReceive('searchableAs')->andReturn('posts');
    $baseModel->shouldReceive('getScoutModelsByIds')->andReturn($dbCollection);
    $baseModel->shouldReceive('newCollection')->andReturn(new EloquentCollection);

    $builder = new Builder($baseModel, 'test');

    $mapped = makeEngine()->map($builder, $results, $baseModel);

    expect($mapped->map(fn ($m) => $m->getScoutKey())->values()->all())
        ->toBe(['3', '1', '2']);
});

it('returns empty collection when hits are empty', function () {
    $baseModel = Mockery::mock(Model::class);
    $baseModel->shouldReceive('newCollection')->andReturn(new EloquentCollection);
    $baseModel->shouldReceive('searchableAs')->andReturn('posts');

    $builder = new Builder($baseModel, '');

    $result = makeEngine()->map($builder, ['hits' => [], 'total' => 0], $baseModel);

    expect($result)->toBeInstanceOf(EloquentCollection::class)
        ->and($result->isEmpty())->toBeTrue();
});

it('applies Builder where filters to mapped models', function () {
    $results = [
        'hits' => [
            ['ref' => '1', 'score' => 0.9],
            ['ref' => '2', 'score' => 0.8],
        ],
        'total' => 2,
    ];

    $modelA = Mockery::mock(Model::class);
    $modelA->shouldReceive('getScoutKey')->andReturn('1');
    $modelA->shouldReceive('getAttribute')->with('status')->andReturn('published');
    $modelA->shouldReceive('offsetExists')->with('status')->andReturn(true);
    $modelA->shouldReceive('offsetGet')->with('status')->andReturn('published');

    $modelB = Mockery::mock(Model::class);
    $modelB->shouldReceive('getScoutKey')->andReturn('2');
    $modelB->shouldReceive('getAttribute')->with('status')->andReturn('draft');
    $modelB->shouldReceive('offsetExists')->with('status')->andReturn(true);
    $modelB->shouldReceive('offsetGet')->with('status')->andReturn('draft');

    $dbCollection = new EloquentCollection([$modelA, $modelB]);

    $baseModel = Mockery::mock(Model::class);
    $baseModel->shouldReceive('searchableAs')->andReturn('posts');
    $baseModel->shouldReceive('getScoutModelsByIds')->andReturn($dbCollection);
    $baseModel->shouldReceive('newCollection')->andReturn(new EloquentCollection);

    $builder = new Builder($baseModel, 'test');
    $builder->wheres = ['status' => 'published'];

    $mapped = makeEngine()->map($builder, $results, $baseModel);

    expect($mapped)->toHaveCount(1)
        ->and($mapped->first()->getScoutKey())->toBe('1');
});

it('calls GET /info and returns decoded metadata', function () {
    $meta = [
        'index'         => 'posts',
        'documentCount' => 42,
        'fields'        => ['title', 'body'],
        'language'      => 'en',
        'bm25'          => ['k1' => 1.2, 'b' => 0.75],
    ];

    Http::fake(['https://lunr.test/info*' => Http::response($meta)]);

    $result = makeEngine()->info('posts');

    expect($result)->toBe($meta);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'index=posts'));
});

it('returns empty array and logs warning when info returns non-2xx', function () {
    Http::fake(['https://lunr.test/info*' => Http::response('not found', 404)]);
    Log::shouldReceive('warning')->once()->with('Lunr Worker /info failed', Mockery::type('array'));

    expect(makeEngine()->info('missing'))->toBe([]);
});
