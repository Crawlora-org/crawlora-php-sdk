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

## Threads Public Lookups

```php
$profile = $client->threads->profile(['username' => 'zuck']);
$post = $client->threads->post(['username' => 'zuck', 'code' => 'DakyAavlKLZ']);
$results = $client->threads->search(['q' => 'openai']);
$posts = $client->threads->profilePosts(['username' => 'zuck']);
$replies = $client->threads->postReplies(['username' => 'zuck', 'code' => 'DakyAavlKLZ']);
```

## Box Office Mojo Dataset

Search theatrical box-office records, fetch one title, and facet the same filter set.

```php
$titles = $client->datasets->boxofficemojoSearch(['q' => 'avatar', 'sort' => 'worldwide_desc']);
$avatar = $client->datasets->boxofficemojoItem(['title_id' => 'tt0499549']);
$years = $client->datasets->boxofficemojoFacets(['facet' => 'years_active', 'gross_band' => 'over_1b']);
```

## Software, Reviews, And Market Datasets

Build a Chrome extension competitive-intelligence view without downloading the
whole catalog: create a high-adoption shortlist, load chart-ready market
metrics, watch movers, and audit permission changes or one item's history.

```php
$extensions = $client->datasets->chromeExtensionsSearch(['q' => 'productivity', 'min_users' => 10000, 'sort' => 'users_desc', 'page_size' => 20]);
$metrics = $client->datasets->chromeExtensionsMetrics(['days' => 30, 'limit' => 10]);
$movers = $client->datasets->chromeExtensionsTrending(['item_type' => 'extension', 'page_size' => 20]);
$permissionChanges = $client->datasets->chromeExtensionsChanges(['change_type' => 'permissions', 'limit' => 25]);
$history = $client->datasets->chromeExtensionsHistory(['id' => 'fjgncogppolhfdpijihbpfmeohpaadpc', 'limit' => 90]);

$cities = $client->datasets->numbeoCitiesSearch(['country' => 'Portugal', 'sort' => 'quality_of_life_desc']);
$software = $client->capterra->search(['q' => 'project management']);
$games = $client->metacritic->browse(['type' => 'game', 'sort' => 'score']);
```

## Airbnb Host Profiles

Look up a public Airbnb host, then page through their listings and guest reviews.

```php
$host = $client->airbnb->host(['id' => '65056940']);
$listings = $client->airbnb->hostListings(['id' => '65056940', 'page' => 1]);
$reviews = $client->airbnb->hostReviews(['id' => '65056940', 'page' => 1]);
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

## TrustMRR Verified Startup Revenues

Browse verified startup revenues and the acquisition marketplace on TrustMRR: the marketplace snapshot, the revenue leaderboard, startup detail, and categories.

```php
$deals = $client->trustMrr->trustmrrMarketplace();
$board = $client->trustMrr->trustmrrLeaderboard(['metric' => 'mrr']);
$startup = $client->trustMrr->trustmrrStartup(['slug' => 'stan']);
$cats = $client->trustMrr->trustmrrCategories();
$saas = $client->trustMrr->trustmrrCategory(['slug' => 'saas']);
```
