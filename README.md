# illuma-law/scout-lunr

A **Laravel Scout engine** that delegates full-text search to a [Lunr.js](https://lunrjs.com/) microservice running on Cloudflare Workers, with indexes stored in Cloudflare KV.

Zero infrastructure cost. No Elasticsearch. No Algolia bill. Globally distributed.

---

## Requirements

| Dependency | Version |
|-----------|---------|
| PHP | `^8.2` |
| Laravel | `^11 \| ^12 \| ^13` |
| laravel/scout | `^10` |
| Node.js | `^18` (Worker development only) |
| Wrangler CLI | `^3` (Worker deployment only) |

---

## Installation

### 1. Install the PHP package

```bash
composer require illuma-law/scout-lunr
```

### 2. Publish configuration files

```bash
# Scout's own config (creates config/scout.php)
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"

# Lunr engine config (creates config/scout-lunr.php)
php artisan vendor:publish --tag="scout-lunr-config"
```

### 3. Set environment variables

```dotenv
SCOUT_DRIVER=lunr
LUNR_WORKER_URL=https://lunr-search.your-subdomain.workers.dev
LUNR_BEARER_TOKEN=your-secret-token
```

---

## Cloudflare Worker Setup

The Worker is the search microservice. It stores raw documents and serialized Lunr indexes in Cloudflare KV.

### 1. Create a KV namespace

```bash
# In your Worker project directory
wrangler kv:namespace create LUNR_INDEX_KV
wrangler kv:namespace create LUNR_INDEX_KV --preview
```

Copy the returned `id` and `preview_id` into `wrangler.toml`.

### 2. Configure `wrangler.toml`

```toml
name = "lunr-search"
main = "src/index.js"
compatibility_date = "2024-01-01"

[[kv_namespaces]]
binding = "LUNR_INDEX_KV"
id = "<your-kv-namespace-id>"
preview_id = "<your-preview-kv-namespace-id>"
```

### 3. Set the Bearer token secret

```bash
# Production secret (stored encrypted by Cloudflare)
wrangler secret put LUNR_BEARER_TOKEN

# Local development (.dev.vars — never commit this file)
echo "LUNR_BEARER_TOKEN=your-local-secret" > .dev.vars
```

### 4. Install dependencies

```bash
npm install
```

### 5. Start locally / Deploy

```bash
npm run dev     # Local dev at http://localhost:8787
npm run deploy  # Deploy to Cloudflare
```

---

## Basic Usage

### Add `Searchable` to a model

```php
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'body'  => $this->body,
        ];
    }
}
```

### Import existing records

```bash
php artisan scout:import "App\Models\Post"
```

### Search

```php
// Basic search (OR logic across all indexed fields)
Post::search('laravel')->get();

// Paginate results
Post::search('laravel')->paginate(15);

// Limit results
Post::search('laravel')->take(5)->get();
```

---

## Field Boosting

Title matches are often more relevant than body matches. Use field boosts to amplify a field's contribution to the relevance score.

### Per-model boosts (recommended)

Implement `LunrConfigurable` and define `searchableBoosts()`:

```php
use Illuminate\Database\Eloquent\Model;
use IllumaLaw\ScoutLunr\Contracts\LunrConfigurable;
use Laravel\Scout\Searchable;

class Post extends Model implements LunrConfigurable
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }

    public function searchableBoosts(): array
    {
        return ['title' => 10, 'body' => 1];
    }

    public function searchableBm25(): array  { return []; }
    public function searchableLanguage(): string { return 'en'; }
}
```

### Global boosts (applies to all models that don't implement `LunrConfigurable`)

```php
// config/scout-lunr.php
'field_boosts' => ['title' => 10, 'body' => 1],
```

---

## BM25 Tuning

Lunr uses the [BM25](https://en.wikipedia.org/wiki/Okapi_BM25) ranking algorithm. Two parameters control its behaviour.

| Parameter | Default | Effect |
|-----------|---------|--------|
| `k1` | `1.2` | Term-frequency saturation. Higher = more weight to repeated terms. |
| `b` | `0.75` | Document-length normalisation. `0.0` = disable (good for long docs). |

### Per-model BM25

```php
public function searchableBm25(): array
{
    // Legal documents: ignore length, slight term-frequency increase
    return ['k1' => 1.3, 'b' => 0.0];
}
```

### Global BM25

```dotenv
LUNR_K1=1.3
LUNR_B=0.0
```

---

## Language Support

English is built into Lunr. Additional languages are provided by the [`lunr-languages`](https://github.com/MihaiValentin/lunr-languages) package (already included in the Worker's `package.json`).

**Supported language codes:** `de`, `da`, `es`, `fi`, `fr`, `hu`, `it`, `ja`, `nl`, `no`, `pt`, `ro`, `ru`, `th`

### Per-model language

```php
public function searchableLanguage(): string
{
    return 'fr'; // French stemmer + stop words
}
```

### Global language

```dotenv
LUNR_LANGUAGE=fr
```

> **Note:** The language plugin is loaded lazily inside the Worker on the first `/update` call. Switching languages for an existing index requires flushing and re-importing: `php artisan scout:flush "App\Models\Post" && php artisan scout:import "App\Models\Post"`.

---

## Advanced Query Syntax

Lunr supports a rich query syntax beyond plain text. Use `LunrQueryBuilder` to compose queries programmatically:

```php
use IllumaLaw\ScoutLunr\Query\LunrQueryBuilder;

$query = LunrQueryBuilder::make()
    ->required('laravel')       // +laravel  — must be present
    ->term('framework')         // framework — nice to have
    ->excluded('lumen')         // -lumen    — must not be present
    ->build();

Post::search($query)->get();
```

### Available methods

| Method | Lunr syntax | Description |
|--------|-------------|-------------|
| `->term('foo')` | `foo` | Plain term (OR logic with others) |
| `->required('foo')` | `+foo` | Term must appear in every result |
| `->excluded('foo')` | `-foo` | Term must not appear in any result |
| `->inField('title', 'foo')` | `title:foo` | Restrict term to a specific field |
| `->wildcard('foo')` | `foo*` | Matches any suffix (autocomplete) |
| `->fuzzy('foo', 1)` | `foo~1` | Fuzzy match within edit distance 1 or 2 |
| `->boost('foo', 10)` | `foo^10` | Multiply score contribution by 10 |

### Examples

```php
// Autocomplete: find posts whose title starts with "lara"
$query = LunrQueryBuilder::make()->inField('title', '')->wildcard('lara')->build();
// → "title:lara*"

// Typo-tolerant: "fraemwork" still matches "framework"
$query = LunrQueryBuilder::make()->required('laravel')->fuzzy('fraemwork', 1)->build();
// → "+laravel fraemwork~1"

// Boost title matches over body matches
$query = LunrQueryBuilder::make()
    ->boost('laravel', 10)   // title hit → big score boost
    ->term('laravel')        // body hit → normal score
    ->build();
// → "laravel^10 laravel"
```

---

## Filtering with `where()`

Lunr has no server-side WHERE clause. When you chain `->where()` on a Scout Builder, the engine fetches all Lunr hits first, hydrates the Eloquent models, and then applies the equality filter in PHP:

```php
// Returns published posts matching "laravel", sorted by Lunr relevance
Post::search('laravel')->where('status', 'published')->get();
```

> **Limitation:** Only simple equality filters (`where('field', 'value')`) are supported. Range queries, `whereIn`, and `orWhere` are not supported by Lunr and must be handled at the application level (e.g. by wrapping Scout results in a further Eloquent query).

---

## Pagination

```php
// Standard paginator — wraps Lunr results in a Laravel paginator
$posts = Post::search('laravel')->paginate(15);

// Cursor-based / simple paginator
$posts = Post::search('laravel')->simplePaginate(15);
```

Pagination is applied in PHP after the Worker returns the full ranked result list. For very large indexes, use `->take(N)` to cap the number of hits the Worker sends back:

```php
// Worker returns at most 100 hits; PHP paginates those 100
Post::search('laravel')->take(100)->paginate(15);
```

---

## Soft Deletes

When a model is soft-deleted, Scout automatically calls the engine's `delete()` method, which removes the document from the Lunr index on the Worker. No additional configuration is needed.

If you query with `->withTrashed()`, soft-deleted models may be restored from Eloquent even though they are absent from the index — their IDs simply won't appear in the Lunr results.

---

## Debugging with `info()`

Inspect a live index directly from PHP:

```php
$engine = app(\Laravel\Scout\EngineManager::class)->engine();

$info = $engine->info('posts');
// [
//   'index'         => 'posts',
//   'documentCount' => 42,
//   'fields'        => ['title', 'body'],
//   'language'      => 'en',
//   'bm25'          => ['k1' => 1.2, 'b' => 0.75],
// ]
```

You can also query the Worker directly:

```bash
curl -H "Authorization: Bearer your-secret" \
  "https://lunr-search.your-subdomain.workers.dev/info?index=posts"
```

---

## Architecture Overview

```
Laravel App                    Cloudflare Worker              Cloudflare KV
─────────────────────────────  ───────────────────────────    ─────────────────
Post::search('laravel')
  → LunrEngine::search()
  → GET /search?index=posts
    &q=laravel              ──▶  lunr.Index.load(kvData)    ← {posts}:index
                                  .search('laravel')
                            ◀──  { hits: [...], total: N }

Post::all()->searchable()
  → LunrEngine::update()
  → POST /update            ──▶  merge raw docs              → {posts}:raw
                                  buildIndex(docs)            → {posts}:index
                                  save metadata              → {posts}:meta
```

**KV keys per index:**

| Key | Contents |
|-----|----------|
| `{index}:raw` | JSON array of all raw documents (for incremental updates) |
| `{index}:index` | JSON-serialized Lunr index (deserialized on every search) |
| `{index}:meta` | Metadata: `fields`, `language`, `bm25`, `documentCount` |

**Error handling:**
- Write operations (`update`, `delete`, `flush`) throw `RuntimeException` on Worker failure — Scout's queue will retry them.
- Read operations (`search`, `info`) log a warning and return an empty result — a degraded Worker does not take down the application.

---

## Testing

```bash
# Run the package test suite
cd packages/illuma-law/scout-lunr
php vendor/bin/pest --configuration phpunit.xml

# Test against the real Worker locally
wrangler dev &
php artisan tinker --execute '
    App\Models\Post::factory(5)->create();
    App\Models\Post::makeAllSearchable();
    dump(App\Models\Post::search("laravel")->get()->pluck("title"));
'
```

---

## Configuration Reference

All values in `config/scout-lunr.php` can be overridden via environment variables:

| Key | Env | Default | Description |
|-----|-----|---------|-------------|
| `worker_url` | `LUNR_WORKER_URL` | — | Cloudflare Worker base URL |
| `secret` | `LUNR_BEARER_TOKEN` | — | Bearer token for Worker auth |
| `timeout` | `LUNR_TIMEOUT` | `25` | HTTP timeout in seconds |
| `language` | `LUNR_LANGUAGE` | `en` | Default index language |
| `k1` | `LUNR_K1` | `1.2` | BM25 term-frequency saturation |
| `b` | `LUNR_B` | `0.75` | BM25 document-length normalisation |
| `field_boosts` | — | `[]` | Global field boost map |
