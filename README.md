# Scout Lunr

![Scout Lunr package banner](art/banner.png)

A Laravel Scout engine that sends search and indexing requests to a Lunr.js
service running on Cloudflare Workers with Cloudflare KV. This Composer
package contains the Laravel engine; deploy and configure the Worker separately.

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Requires PHP 8.2+, Laravel 12 or 13, and Laravel Scout 10.

## Install

```bash
composer require illuma-law/scout-lunr
php artisan vendor:publish --tag=scout-lunr-config
```

Set the Worker URL and matching bearer token in `.env`:

```dotenv
SCOUT_DRIVER=lunr
LUNR_WORKER_URL=https://your-worker.example.workers.dev
LUNR_BEARER_TOKEN=your-secret-token
```

The Worker must implement the authenticated `/update`, `/delete`, `/flush`,
`/search`, and `/info` endpoints expected by this engine. The Worker source and
Cloudflare deployment configuration are maintained separately from this package.

## Search a model

Add Scout's `Searchable` trait and define the fields to index:

```php
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}
```

Import existing records, then search as usual with Scout:

```bash
php artisan scout:import "App\Models\Post"
```

```php
$posts = Post::search('laravel')->take(10)->get();
$published = Post::search('laravel')->where('status', 'published')->get();
```

Scout filters such as `where()` are applied after the Worker returns results.
This engine supports simple equality filters; it does not provide indexed range
or `whereIn` filtering.

## Query builder

Use `LunrQueryBuilder` to create advanced Lunr query syntax safely:

```php
use IllumaLaw\ScoutLunr\Query\LunrQueryBuilder;

$query = LunrQueryBuilder::make()
    ->required('laravel')
    ->inField('title', 'search')
    ->excluded('legacy')
    ->build();

$posts = Post::search($query)->get();
```

Models can implement `LunrConfigurable` to set per-model field boosts, BM25
parameters, and language. The same options can be set globally in
`config/scout-lunr.php`; non-English languages also need the matching
`lunr-languages` plugin in the Worker.

## Tests

```bash
composer install
composer test
```

## License

MIT. See [LICENSE](LICENSE).
