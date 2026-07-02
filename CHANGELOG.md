# Changelog

## v1.10.0-sdk.2

- Google Maps dataset (`datasets_google_map_*`) and live Google Maps
  (`google_map_search`, `google_map_place`): a business with no aggregate Google
  rating now returns `rating: null` instead of `0`. Google never gives a 0.x
  average, so `0` had always meant "unrated" — this makes it explicit. A present
  `rating` is always 1.0-5.0, so skip nulls when you average; `min_rating` above 0
  still excludes unrated businesses. No endpoints added or removed.

## v1.10.0-sdk.1

- Regenerated from the public API contract (525 to 529 operations). Adds the
  **Airbnb Markets dataset** (4 endpoints): aggregate short-term-rental market
  statistics -- listing supply, Superhost share, ratings, and nightly-price bands
  -- rolled up by country, metro, and geo cell (search, item lookup, facets, and
  nearby density). Aggregate-only: no individual listings or hosts.

## v1.9.0-sdk.1

- Regenerated from the public API contract (499 to 525 operations). Adds four
  platforms/families to the client:
  - **GitHub** (16 endpoints): organizations, repositories (contributors,
    forks, languages, releases, stargazers), user profiles/events/pinned/repos,
    repository and user search, and trending repositories/developers.
  - **GitHub Users dataset** (4): search, facets, nearby, and item lookup.
  - **X** (3): post, profile, and profile posts.
  - **Apps datasets** (3): apps, apps-charts, and apps-reviews search.
  - **Creators dataset** (1): TikTok creators search.
- Removes the retired tiktok popular-trend/creator operation.

## v1.8.0-sdk.2

- Regenerated from the public API contract (499 operations, unchanged). Enriches
  the Web `antibot-check` diagnostic response with additional fields:
  `block_reason`, `block_detail`, `auth_required`, `captcha_type`,
  `captcha_types`, `captcha_mode`, `confidence_score`, `custom_vm`, and
  `vm_vendor`.
- Clarified the `google-search` and datasets `google-map-businesses/search`
  endpoint descriptions (wording only; no behavior change).

## v1.8.0-sdk.1

- Added two new platforms, regenerated from the public API contract (now 499
  operations): **Redfin** (real-estate `search`, `property`, `estimate`,
  `region-trends`, `similar`) and **Web** (generic `web-scrape`, `contact`, and
  the `antibot-check` diagnostic).
- Refreshed response schemas: `contact` gains `crawl_status`, `web-scrape` gains
  `cache_state`/`cached_at`/`max_age`, and the Spotify country-hub responses gain
  `partialErrors`.

## v1.7.0-sdk.1

- Added six new platforms, regenerated from the public API contract (now 491
  operations): **Polymarket**, **Kalshi**, and **Metaculus** (prediction
  markets); **IMDb**, **Rotten Tomatoes**, and **Box Office Mojo** (film/TV).
- Expanded **Reddit**: subreddit about/comments, multi-subreddit posts,
  domain posts, user posts/comments, and trends.

## 1.6.0-sdk.1

- Added the **Reddit** platform (`reddit->search`, `reddit->post`,
  `reddit->comments`, `reddit->subredditPosts`) and the **Brand** platform
  (`brand->retrieve`), plus Yahoo Finance `yahooFinance->lookup`. Regenerated
  from the public API contract.

## 1.5.0-sdk.3

- Robustness: the curl transport carries the curl error number on failures, and
  the client classifies timeouts by that errno (`CURLE_OPERATION_TIMEOUTED`)
  instead of matching the message string.
- Internal cleanups: extracted the after-response hook loop from `send()`,
  deduplicated path-parameter validation, and memoized each group's allowed-param
  set. No public API changes. (Published to Packagist as a `dev-main` release.)

## 1.5.0-sdk.1

- Initial release of the Crawlora PHP SDK.
- Grouped helpers (`$client-><group>-><method>([...])`) and dynamic
  `request`/`operation` calls for every public operation, generated from the
  shared OpenAPI contract.
- Configurable retries with exponential backoff, jitter, and `Retry-After`
  support; `onRetry` hook.
- Numeric and cursor pagination (`paginate` / `paginateItems`).
- `beforeRequest` / `afterResponse` middleware, opt-in `requestId` and
  `idempotencyKeys`, client-side `rateLimit` (synchronous, so `maxConcurrency`
  is a no-op kept for parity).
- `auto` / `json` / `text` / `stream` response modes.
- Typed error hierarchy: `Crawlora\Exception\CrawloraError`, `ClientError`,
  `ServerError`, `NetworkError`.
- Dependency-free at runtime (curl + json extensions only); pluggable transport.
