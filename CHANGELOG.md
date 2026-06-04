# Changelog

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
