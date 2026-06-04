# Recipes

Practical snippets for common tasks with the Crawlora PHP SDK. See
[`operations.md`](operations.md) for the full operation reference.

## Configure once, reuse

```php
use Crawlora\Client;

$client = new Client([
    'apiKey' => getenv('CRAWLORA_API_KEY') ?: '',
    'timeout' => 20.0,
    'retries' => 2,
]);
```

## Search and iterate results

```php
$result = $client->bing->search(['q' => 'web scraping', 'page' => 1]);
foreach ($result['data'] as $hit) {
    printf("%s — %s\n", $hit['title'] ?? '', $hit['url'] ?? '');
}
```

## Reddit and Brand

Newer platforms are grouped like every other endpoint:

```php
$posts = $client->reddit->search(['q' => 'php', 'subreddit' => 'programming']);
$brand = $client->brand->retrieve(['domain' => 'stripe.com']);
```

## Walk every page

```php
foreach ($client->paginateItems('airbnb-room-reviews', ['id' => 'r1']) as $review) {
    echo $review['comments'] ?? '', "\n";
}
```

Bound the work with `max_pages`:

```php
$client->paginate('airbnb-search', ['location' => 'Lisbon'], ['max_pages' => 5]);
```

## Cursor pagination

```php
$pages = $client->paginate('producthunt-leaderboard', [], [
    'cursor_param' => 'cursor',
    'next_cursor' => fn ($resp) => $resp['next'] ?? null,
    'max_pages' => 10,
]);
foreach ($pages as $page) {
    // ...
}
```

## Plain-text endpoints

```php
$transcript = $client->request('youtube-transcript', ['id' => 'dQw4w9WgXcQ'], [
    'response_type' => 'text',
]);
```

## Streaming a large body

```php
$stream = $client->request('bing-search', ['q' => 'x'], ['response_type' => 'stream']);
while (!feof($stream)) {
    echo fread($stream, 8192);
}
fclose($stream);
```

## Tracing requests

```php
$client = new Client([
    'apiKey' => '...',
    'requestId' => true,
    'beforeRequest' => function (array &$ctx) {
        error_log("{$ctx['method']} {$ctx['url']}");
    },
]);
```

## Handling errors

```php
use Crawlora\Exception\ClientError;
use Crawlora\Exception\ServerError;
use Crawlora\Exception\NetworkError;

try {
    $client->amazon->product(['asin' => 'B000', 'language' => 'en_US']);
} catch (ClientError $e) {
    // 4xx — bad request, auth, quota, etc.
    fwrite(STDERR, "rejected ({$e->status}): {$e->getMessage()}\n");
} catch (ServerError $e) {
    // 5xx — retry later
} catch (NetworkError $e) {
    // transport failure / timeout
}
```

## Custom retry policy

```php
$client = new Client([
    'apiKey' => '...',
    'retries' => 5,
    'retryPredicate' => fn (int $status, ?Throwable $e) => $status === 429 || $status >= 500,
]);
```

## Idempotent writes

```php
$client = new Client(['apiKey' => '...', 'idempotencyKeys' => true]);
// POST/PATCH operations get a generated Idempotency-Key header automatically.
```
