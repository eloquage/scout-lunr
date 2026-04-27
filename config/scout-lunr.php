<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Worker URL
    |--------------------------------------------------------------------------
    |
    | The base URL of your deployed Cloudflare Worker. All API calls (update,
    | delete, search, flush, info) are made to this host. Do not include a
    | trailing slash.
    |
    | Example: https://lunr-search.your-subdomain.workers.dev
    |
    */

    'worker_url' => env('LUNR_WORKER_URL'),

    /*
    |--------------------------------------------------------------------------
    | Worker Bearer Token
    |--------------------------------------------------------------------------
    |
    | Every request to the Worker is authenticated with this secret via an
    | `Authorization: Bearer <token>` header. Set the same value in the
    | Worker's `.dev.vars` (local) and as a Cloudflare Workers secret
    | (production: `wrangler secret put LUNR_BEARER_TOKEN`).
    |
    */

    'secret' => env('LUNR_BEARER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum seconds to wait for a Worker response before the Http facade
    | throws a connection timeout exception. Cloudflare Workers have a 30-second
    | wall-clock limit on the free plan, so 25 s is a safe ceiling.
    |
    */

    'timeout' => env('LUNR_TIMEOUT', 25),

    /*
    |--------------------------------------------------------------------------
    | Default Language
    |--------------------------------------------------------------------------
    |
    | ISO 639-1 language code used for stemming and tokenisation.
    | English (`en`) is built into Lunr and requires no extra setup.
    |
    | All other languages require the `lunr-languages` npm package in the
    | Worker project (`npm install lunr-languages`). Supported codes:
    |   de, da, es, fi, fr, hu, it, ja, nl, no, pt, ro, ru, th
    |
    | Individual models can override this via the LunrConfigurable interface:
    |   public function searchableLanguage(): string { return 'fr'; }
    |
    */

    'language' => env('LUNR_LANGUAGE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Default BM25 Parameters
    |--------------------------------------------------------------------------
    |
    | Lunr uses the BM25 ranking algorithm. These two parameters control its
    | behaviour globally. Individual models can override them by implementing
    | the LunrConfigurable interface.
    |
    | k1 — Term-frequency saturation (default 1.2).
    |       Higher values give more weight to repeated terms.
    |       Lower values (towards 0) flatten the curve so one occurrence
    |       counts almost as much as many.
    |
    | b  — Document-length normalisation (default 0.75).
    |       1.0 = fully normalise by length (penalises long documents).
    |       0.0 = disable length normalisation entirely (good for long docs
    |             such as legal texts, transcripts, or product descriptions
    |             where length should not hurt relevance).
    |
    */

    'k1' => (float) env('LUNR_K1', 1.2),

    'b' => (float) env('LUNR_B', 0.75),

    /*
    |--------------------------------------------------------------------------
    | Default Field Boosts
    |--------------------------------------------------------------------------
    |
    | Global field boost weights applied when a model does not implement
    | LunrConfigurable::searchableBoosts(). Keys must match field names
    | returned by the model's toSearchableArray(). Values are positive
    | numbers; a field with boost 10 contributes 10× more to relevance
    | than a field with boost 1.
    |
    | Example:
    |   'field_boosts' => ['title' => 10, 'body' => 1],
    |
    */

    'field_boosts' => [],

];
