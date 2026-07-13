# Crawlora PHP SDK

PHP client for the public [Crawlora](https://crawlora.net) web-scraping API. It
wraps every public endpoint with grouped helpers and a dynamic call interface,
plus retries, pagination, middleware hooks, and client-side rate limiting.

- **Base URL:** `https://api.crawlora.net/api/v1`
- **Auth:** API key (`x-api-key`) or JWT (`Authorization`)
- **PHP:** 8.1+ (uses the `curl` and `json` extensions; no runtime dependencies)
- Operation reference: [`docs/operations.md`](docs/operations.md) · recipes: [`docs/recipes.md`](docs/recipes.md)

## Install

Published on [Packagist](https://packagist.org/packages/crawlora/sdk). The SDK
releases from the `main` branch (the shared `-sdk.N` tag scheme is not a valid
Composer version), so install the aliased dev release:

```sh
composer require crawlora/sdk:^1.18@dev
```

## Quick start

```php
<?php
require 'vendor/autoload.php';

use Crawlora\Client;

// Reads CRAWLORA_API_KEY from the environment if apiKey is omitted.
$client = new Client(['apiKey' => getenv('CRAWLORA_API_KEY') ?: '']);

$result = $client->bing->search(['q' => 'web scraping']);
foreach ($result['data'] as $item) {
    echo $item['title'] ?? '', "\n";
}

$client->close(); // release the pooled curl connection
```

## Calling operations

Grouped helpers map directly to the API (`$client-><group>-><method>(...)`):

```php
$client->youtube->video(['id' => 'dQw4w9WgXcQ']);
$client->google->search(['q' => 'crawlora', 'country' => 'US']);
$client->amazon->product(['asin' => 'B07FZ8S74R', 'language' => 'en_US']);
```

The first argument is the params array; an optional second argument carries
per-call options (`response_type`, `timeout`, `headers`, `retries`,
`retry_predicate`):

```php
$client->bing->search(['q' => 'x'], ['timeout' => 5.0]);
```

Or call any operation dynamically by its id — handy for metaprogramming:

```php
$client->request('bing-search', ['q' => 'web scraping', 'page' => 2]);

use Crawlora\OperationId;
$client->request(OperationId::BING_SEARCH, ['q' => 'web scraping']);

// Discover operations:
Crawlora\Operations::OPERATION_COUNT;   // total operations
Crawlora\Operations::GROUPS;            // group => [method => operationId]
Crawlora\Operations::OPERATIONS;        // operationId => metadata
```

## Authentication

Pass `apiKey` (sent as `x-api-key`) or `jwtToken` (sent as
`Authorization: Token <jwt>`, unless the value already starts with `Token ` or
`Bearer `). `CRAWLORA_API_KEY` and `CRAWLORA_BASE_URL` are read from the
environment as fallbacks.

## Retries

```php
$client = new Client([
    'apiKey' => '...',
    'retries' => 3,
    'retryDelay' => 0.25,        // base backoff, seconds
    'maxRetryDelay' => 30.0,     // cap; Retry-After is honored up to this
    'onRetry' => function (int $attempt, Throwable $err, float $delay) {
        error_log("retry #{$attempt} after {$delay}s: {$err->getMessage()}");
    },
]);
```

By default network failures and `408/409/425/429`/`5xx` are retried with
exponential backoff and jitter, honoring `Retry-After`. Override the retryable
set with `retryStatuses` or a `retryPredicate`.

## Pagination

```php
// Numeric pagination: advances page/offset, stops on an empty page.
foreach ($client->paginate('airbnb-room-reviews', ['id' => 'r1']) as $page) {
    // ... each $page is a full response
}

// Flatten to items (default extractor: the `data` array).
foreach ($client->paginateItems('airbnb-room-reviews', ['id' => 'r1']) as $item) {
    // ...
}

// Cursor pagination.
$pages = $client->paginate('producthunt-leaderboard', [], [
    'cursor_param' => 'cursor',
    'next_cursor' => fn ($resp) => $resp['next'] ?? null,
]);
```

## Response modes

`response_type` may be `auto` (default; JSON when the content type is JSON, else
text), `json`, `text`, or `stream` (returns a readable PHP stream resource).

```php
$text = $client->request('youtube-transcript', ['id' => 'abc'], ['response_type' => 'text']);
$stream = $client->request('bing-search', ['q' => 'x'], ['response_type' => 'stream']);
```

## Middleware hooks

```php
$client = new Client([
    'apiKey' => '...',
    // Mutate the outgoing request (pass $ctx by reference to edit url/headers).
    'beforeRequest' => function (array &$ctx) {
        $ctx['headers']['X-Trace'] = bin2hex(random_bytes(8));
    },
    // Inspect or replace the parsed body (return a value to replace it).
    'afterResponse' => function (string $op, int $status, array $headers, $body) {
        return $body;
    },
]);
```

`requestId => true` adds an `x-request-id` header; `idempotencyKeys => true`
adds an `Idempotency-Key` on POST/PATCH.

## Rate limiting

`rateLimit` spaces requests to at most N requests/second. Because PHP requests
are synchronous, `maxConcurrency` is accepted for API parity but is a no-op
(only one request is in flight at a time).

## Custom transport

Inject any callable `function (array $request): array` returning
`['status' => int, 'headers' => array, 'body' => string]` — useful for tests or
a different HTTP stack:

```php
$client = new Client([
    'transport' => function (array $req): array {
        return ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{"data":[]}'];
    },
]);
```

## Errors

All errors extend `Crawlora\Exception\CrawloraError` (carries `status`,
`apiCode`, `body`, `rawBody`, `headers`, `requestId`):

- `ClientError` — 4xx
- `ServerError` — 5xx
- `NetworkError` — transport failures and timeouts

## License

MIT
