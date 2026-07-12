# Changelog

## v1.14.0-sdk.1

- Regenerated from the public API contract (559 to 685 operations). A catch-up
  release bringing the SDK current with every public Crawlora endpoint. New
  platforms now covered:
  - **Discogs** (7): release, master, artist + discography, label + catalog,
    and database search — the credential-free Discogs music database.
  - **Letterboxd** (8): film detail, rating histogram, popular reviews, similar
    films, search, person filmography, popular charts, and member profiles.
  - **TMDB** (6): movie, TV show, and person detail, search, and the movie / TV
    charts — from the public themoviedb.org site.
  - **Goodreads** (6): book detail (with the full 1-5 star rating distribution),
    book reviews, search, author, author works list, and Listopia lists.
  - **SEC EDGAR** (10): company search / submissions / 360 intelligence, single
    filing + section extraction, full-text search, XBRL frames, normalized
    financial statements, insider transactions (Forms 3/4/5), and 13F holdings.
  - **Jobs** (11): Greenhouse, Lever, Ashby, Workday, and SmartRecruiters board
    / posting readers plus hiring-signal and company-search aggregators.
  - **Steam** (21): store, app, reviews, charts, tags, categories, packages,
    player counts, news, featured lists, and SteamSpy data.
  - **PlayStation Store** (8): product, concept, category, search, browse,
    deals, latest, and page collection discovery.
  - The remaining **Chrome Web Store** endpoints (developer, privacy,
    permissions).


## v1.13.0-sdk.1

- Regenerated from the public API contract (555 to 559 operations). Adds the
  **Chrome Web Store** platform (9 endpoints): item detail, search, related
  items, reviews, category / collection / top-chart listings, search
  suggestions, and the category reference taxonomy — covering Chrome Web Store
  extensions and themes.


## v1.12.0-sdk.1

- Regenerated from the public API contract (532 to 555 operations). Adds the
  **TrustMRR** platform (5 endpoints): a public database of verified startup
  revenues and a startup-acquisition marketplace. The endpoints cover the
  marketplace snapshot (recently listed startups and best deals), the verified
  revenue leaderboard (rank by MRR, 30-day revenue, all-time revenue, growth,
  traffic, or revenue per visitor), startup detail, the category directory, and
  category detail.
- Also catches the client up with public endpoints from earlier API releases that
  had not yet been regenerated into the SDKs: the ESPN and Reddit platforms; the
  Airbnb Markets, GitHub Users, and Product Hunt dataset families; Product Hunt
  category products; and the website tech-stack endpoint.

## v1.11.0-sdk.1

- Regenerated from the public API contract (529 to 532 operations). Adds three
  Airbnb host endpoints: host profile, host listings, and host reviews.

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
