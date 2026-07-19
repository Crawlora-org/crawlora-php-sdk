<?php

declare(strict_types=1);

namespace Crawlora;

use Crawlora\Exception\ClientError;
use Crawlora\Exception\CrawloraError;
use Crawlora\Exception\NetworkError;
use Crawlora\Exception\ServerError;

/**
 * Synchronous client for the Crawlora API.
 *
 * Call operations via grouped helpers (`$client->bing->search(['q' => '...'])`)
 * or dynamically (`$client->request('bing-search', ['q' => '...'])`). Supports
 * configurable retries, an `onRetry` hook, opt-in `requestId` and
 * `idempotencyKeys`, `beforeRequest`/`afterResponse` middleware, client-side
 * `rateLimit`, pagination (`paginate`/`paginateItems`), and stream/text response
 * modes. Uses a reused curl connection by default; call `close()` to release it.
 *
 * Group accessors (`$client->bing->search([...])`) are declared on the generated
 * {@see \Crawlora\Generated\ClientGroups} mixin for IDE discoverability, and on
 * the generated PHPStan stub (.phpstan/Client.stub) for static analysis.
 *
 * @mixin \Crawlora\Generated\ClientGroups
 */
final class Client
{
    public const VERSION = '1.23.0-sdk.1';

    public const DEFAULT_BASE_URL = 'https://api.crawlora.net/api/v1';

    public const DEFAULT_MAX_RETRY_DELAY = 30.0;

    public const DEFAULT_RETRY_STATUSES = [408, 409, 425, 429];

    public const RESPONSE_TYPES = ['auto', 'json', 'text', 'stream'];

    public string $apiKey;

    public string $jwtToken;

    public string $baseUrl;

    public float $timeout;

    public int $retries;

    public float $retryDelay;

    public float $maxRetryDelay;

    /** @var array<int,int>|null */
    public array|null $retryStatuses;

    /** @var (callable(int,?\Throwable):bool)|null */
    private $retryPredicate;

    /** @var (callable(int,\Throwable,float):void)|null */
    private $onRetry;

    public bool $requestId;

    public bool $idempotencyKeys;

    /** @var (callable(array<string,mixed>):void)|null */
    private $logger;

    /** @var array<int,callable(array<string,mixed>):void> */
    private array $beforeRequest;

    /** @var array<int,callable(string,int,array<string,string>,mixed):mixed> */
    private array $afterResponse;

    /** @var array<string,string> */
    public array $headers;

    public string $userAgent;

    private RateLimiter|null $rateLimiter;

    /** @var callable(array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float}):array<string,mixed> */
    private $transport;

    /** @var array<string,Group> */
    private array $groups = [];

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(array $options = [])
    {
        $env = static fn (string $key): string|null => (($v = getenv($key)) === false || $v === '') ? null : $v;

        // Precedence: explicit option > environment variable > default.
        $this->apiKey = (string) ($options['apiKey'] ?? $env('CRAWLORA_API_KEY') ?? '');
        $this->jwtToken = (string) ($options['jwtToken'] ?? '');
        $baseUrl = (string) ($options['baseUrl'] ?? $env('CRAWLORA_BASE_URL') ?? self::DEFAULT_BASE_URL);
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = (float) ($options['timeout'] ?? 30);
        $this->retries = max(0, (int) ($options['retries'] ?? 0));
        $this->retryDelay = max(0.0, (float) ($options['retryDelay'] ?? 0.25));
        $this->maxRetryDelay = max(0.0, (float) ($options['maxRetryDelay'] ?? self::DEFAULT_MAX_RETRY_DELAY));
        $this->retryStatuses = isset($options['retryStatuses'])
            ? array_map('intval', (array) $options['retryStatuses'])
            : null;
        $this->retryPredicate = $options['retryPredicate'] ?? null;
        $this->onRetry = $options['onRetry'] ?? null;
        $this->requestId = (bool) ($options['requestId'] ?? false);
        $this->idempotencyKeys = (bool) ($options['idempotencyKeys'] ?? false);
        $this->logger = $options['logger'] ?? null;
        $this->beforeRequest = self::asHookList($options['beforeRequest'] ?? null);
        $this->afterResponse = self::asHookList($options['afterResponse'] ?? null);
        /** @var array<string,string> $headers */
        $headers = (array) ($options['headers'] ?? []);
        $this->headers = $headers;
        $this->userAgent = (string) ($options['userAgent'] ?? 'crawlora-php-sdk/' . self::VERSION);

        $rateLimit = $options['rateLimit'] ?? null;
        $maxConcurrency = $options['maxConcurrency'] ?? null;
        $this->rateLimiter = ($rateLimit !== null || $maxConcurrency !== null)
            ? new RateLimiter($rateLimit !== null ? (float) $rateLimit : null, $maxConcurrency !== null ? (int) $maxConcurrency : null)
            : null;

        $this->transport = $options['transport'] ?? new CurlTransport();

        foreach (Operations::groups() as $groupName => $operations) {
            $this->groups[$groupName] = new Group($this, $operations);
        }
    }

    public function __get(string $name): Group
    {
        if (isset($this->groups[$name])) {
            return $this->groups[$name];
        }

        throw new \InvalidArgumentException("unknown Crawlora group: {$name}");
    }

    public function __isset(string $name): bool
    {
        return isset($this->groups[$name]);
    }

    /** Release the pooled transport connection, if the transport supports it. */
    public function close(): void
    {
        if (is_object($this->transport) && method_exists($this->transport, 'close')) {
            $this->transport->close();
        }
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $options
     */
    public function operation(string $operationId, array $params = [], array $options = []): mixed
    {
        return $this->request($operationId, $params, $options);
    }

    /**
     * Dynamic operation call.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $options options: response_type, timeout, headers, retries, retry_predicate
     */
    public function request(string $operationId, array $params = [], array $options = []): mixed
    {
        $operation = Operations::get($operationId);
        if ($operation === null) {
            throw new \InvalidArgumentException("unknown Crawlora operation: {$operationId}");
        }

        $responseType = self::validateResponseType((string) ($options['response_type'] ?? 'auto'));
        $timeout = isset($options['timeout']) ? (float) $options['timeout'] : null;
        /** @var array<string,string>|null $headers */
        $headers = $options['headers'] ?? null;
        /** @var (callable(int,?\Throwable):bool)|null $retryPredicate */
        $retryPredicate = $options['retry_predicate'] ?? null;

        $this->log(['event' => 'request', 'operation' => $operationId]);
        $maxRetries = isset($options['retries']) ? max(0, (int) $options['retries']) : $this->retries;
        $idempotencyKey = ($this->idempotencyKeys && in_array($operation['method'], ['POST', 'PATCH'], true))
            ? bin2hex(random_bytes(16))
            : null;

        $attempt = 0;
        while (true) {
            try {
                return $this->send($operation, $params, $responseType, $timeout, $headers, $idempotencyKey);
            } catch (CrawloraError $e) {
                $retryable = $retryPredicate !== null
                    ? (bool) $retryPredicate($e->status, $e)
                    : $this->isRetryable($e->status, $e);
                if ($attempt >= $maxRetries || !$retryable) {
                    throw $e;
                }
                $attempt++;
                $delay = $this->computeRetryDelay($attempt, $e->headers);
                $this->log(['event' => 'retry', 'operation' => $operationId, 'attempt' => $attempt, 'status' => $e->status, 'delay' => $delay]);
                if ($this->onRetry !== null) {
                    ($this->onRetry)($attempt, $e, $delay);
                }
                if ($delay > 0) {
                    usleep((int) round($delay * 1_000_000));
                }
            }
        }
    }

    /**
     * Yield successive pages of a paginated operation.
     *
     * Numeric mode (default) advances the `page`/`offset` query parameter and
     * stops on an empty page. Cursor mode (pass both `cursor_param` and a
     * `next_cursor` callable) sends the cursor parameter and stops when
     * `next_cursor` returns a falsy value.
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $options page_param, cursor_param, next_cursor, start, step, max_pages, response_type, timeout, headers
     *
     * @return \Generator<int,mixed>
     */
    public function paginate(string $operationId, array $params = [], array $options = []): \Generator
    {
        $operation = Operations::get($operationId);
        if ($operation === null) {
            throw new \InvalidArgumentException("unknown Crawlora operation: {$operationId}");
        }

        $pageParam = $options['page_param'] ?? null;
        $cursorParam = $options['cursor_param'] ?? null;
        $nextCursor = $options['next_cursor'] ?? null;
        $start = $options['start'] ?? null;
        $step = (int) ($options['step'] ?? 1);
        $maxPages = $options['max_pages'] ?? null;
        $requestOptions = array_intersect_key($options, array_flip(['response_type', 'timeout', 'headers']));

        $baseParams = $params;
        $fetched = 0;

        if ($cursorParam !== null || $nextCursor !== null) {
            if ($cursorParam === null || $nextCursor === null) {
                throw new \InvalidArgumentException('cursor pagination requires both cursor_param and next_cursor');
            }
            $queryNames = array_map(static fn (array $p): string => $p['name'], $operation['queryParams'] ?? []);
            if (!in_array($cursorParam, $queryNames, true)) {
                throw new \InvalidArgumentException("cursor_param '{$cursorParam}' is not a query parameter of operation {$operationId}");
            }
            $cursor = $start;
            while ($maxPages === null || $fetched < (int) $maxPages) {
                $pageParams = $baseParams;
                if ($cursor !== null) {
                    $pageParams[$cursorParam] = $cursor;
                }
                $response = $this->request($operationId, $pageParams, $requestOptions);
                yield $response;
                $fetched++;
                $cursor = $nextCursor($response);
                if (!$cursor) {
                    break;
                }
            }

            return;
        }

        $pageParam ??= Pagination::detectPageParam($operation);
        if ($pageParam === null) {
            throw new \InvalidArgumentException("operation {$operationId} has no page or offset query parameter to paginate");
        }
        $pageValue = $start === null ? Pagination::defaultStart($pageParam) : $start;
        while ($maxPages === null || $fetched < (int) $maxPages) {
            $pageParams = $baseParams;
            $pageParams[$pageParam] = $pageValue;
            $response = $this->request($operationId, $pageParams, $requestOptions);
            yield $response;
            $fetched++;
            if (Pagination::pageIsEmpty($response)) {
                break;
            }
            $pageValue += $step;
        }
    }

    /**
     * Yield individual items across pages. The `items` option extracts the list
     * from a page (default: the Crawlora `data` array).
     *
     * @param array<string,mixed> $params
     * @param array<string,mixed> $options same as paginate(), plus `items` (callable)
     *
     * @return \Generator<int,mixed>
     */
    public function paginateItems(string $operationId, array $params = [], array $options = []): \Generator
    {
        /** @var callable(mixed):array<int,mixed> $extract */
        $extract = $options['items'] ?? [Pagination::class, 'defaultItems'];
        unset($options['items']);
        foreach ($this->paginate($operationId, $params, $options) as $page) {
            foreach ($extract($page) as $item) {
                yield $item;
            }
        }
    }

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $params
     * @param array<string,string>|null $headers
     */
    private function send(array $operation, array $params, string $responseType, float|null $timeout, array|null $headers, string|null $idempotencyKey): mixed
    {
        [$url, $body, $bodyHeaders] = $this->buildRequest($this->baseUrl, $operation, $params);
        $requestHeaders = self::mergeHeaders(
            $this->headers,
            self::authHeaders($operation['security'] ?? [], $this->apiKey, $this->jwtToken),
            $this->userAgent === '' ? [] : ['User-Agent' => $this->userAgent],
            $bodyHeaders,
            $headers ?? [],
        );

        if ($this->requestId) {
            $reqId = self::ensureRequestId($requestHeaders);
        } else {
            $existing = self::headerValue($requestHeaders, 'x-request-id');
            $reqId = $existing === '' ? null : $existing;
        }
        if ($idempotencyKey !== null && self::headerValue($requestHeaders, 'idempotency-key') === '') {
            $requestHeaders['Idempotency-Key'] = $idempotencyKey;
        }
        if ($this->beforeRequest !== []) {
            $ctx = ['operation' => $operation['id'], 'method' => $operation['method'], 'url' => $url, 'headers' => $requestHeaders];
            foreach ($this->beforeRequest as $hook) {
                $hook($ctx);
            }
            $url = $ctx['url'];
            /** @var array<string,string> $requestHeaders */
            $requestHeaders = $ctx['headers'];
        }

        $requestTimeout = $timeout ?? $this->timeout;
        $invoke = fn (): array => ($this->transport)([
            'method' => $operation['method'],
            'url' => $url,
            'headers' => $requestHeaders,
            'body' => $body,
            'timeout' => $requestTimeout,
        ]);

        try {
            $response = $this->rateLimiter !== null ? $this->rateLimiter->run($invoke) : $invoke();
        } catch (\Throwable $e) {
            $message = self::isTimeoutError($e) ? 'Crawlora request timed out' : 'Crawlora transport error';
            throw new NetworkError($message, requestId: $reqId, previous: $e);
        }

        $status = (int) $response['status'];
        /** @var array<string,string> $responseHeaders */
        $responseHeaders = $response['headers'] ?? [];
        $rawBody = (string) ($response['body'] ?? '');
        $isError = $status < 200 || $status >= 300;

        if ($responseType === 'stream' && !$isError) {
            $stream = fopen('php://temp', 'r+');
            if ($stream === false) {
                throw new CrawloraError('failed to open stream', status: $status, rawBody: $rawBody, headers: $responseHeaders, requestId: $reqId);
            }
            fwrite($stream, $rawBody);
            rewind($stream);

            return $stream;
        }

        $parseMode = $responseType === 'stream' ? 'auto' : $responseType;
        try {
            $parsed = self::parseResponse($rawBody, self::headerValue($responseHeaders, 'content-type'), $parseMode);
        } catch (\JsonException $e) {
            throw new CrawloraError('Crawlora JSON parse error', status: $status, rawBody: $rawBody, headers: $responseHeaders, requestId: $reqId, previous: $e);
        }

        if ($isError) {
            $code = (is_array($parsed) && isset($parsed['code'])) ? (int) $parsed['code'] : null;
            $message = (is_array($parsed) && isset($parsed['msg']) && (string) $parsed['msg'] !== '')
                ? (string) $parsed['msg']
                : "HTTP {$status}";
            $class = self::errorClassFor($status);

            throw new $class($message, status: $status, code: $code, body: $parsed, rawBody: $rawBody, headers: $responseHeaders, requestId: $reqId);
        }

        return $this->applyAfterResponse((string) $operation['id'], $status, $responseHeaders, $parsed);
    }

    /**
     * Run the after_response hooks, letting each return a replacement body.
     *
     * @param array<string,string> $headers
     */
    private function applyAfterResponse(string $operationId, int $status, array $headers, mixed $parsed): mixed
    {
        foreach ($this->afterResponse as $hook) {
            $result = $hook($operationId, $status, $headers, $parsed);
            if ($result !== null) {
                $parsed = $result;
            }
        }

        return $parsed;
    }

    private function isRetryable(int $status, \Throwable|null $exc): bool
    {
        if ($this->retryPredicate !== null) {
            return (bool) ($this->retryPredicate)($status, $exc);
        }
        if ($this->retryStatuses !== null) {
            return $status === 0 || in_array($status, $this->retryStatuses, true);
        }

        return $status === 0 || in_array($status, self::DEFAULT_RETRY_STATUSES, true) || $status >= 500;
    }

    /**
     * @param array<string,string> $headers
     */
    private function computeRetryDelay(int $attempt, array $headers): float
    {
        $retryAfter = self::retryAfterDelay($headers, $this->maxRetryDelay);
        if ($retryAfter !== null) {
            return $retryAfter;
        }
        if ($this->retryDelay <= 0) {
            return 0.0;
        }
        $delay = $this->retryDelay * (2 ** max(0, $attempt - 1));
        $jitter = (mt_rand() / mt_getrandmax()) * ($this->retryDelay / 2);

        return $delay + $jitter;
    }

    /**
     * @param array<string,mixed> $event
     */
    private function log(array $event): void
    {
        if ($this->logger !== null) {
            ($this->logger)($event);
        }
    }

    /**
     * @return array<int,callable>
     */
    private static function asHookList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_callable($value)) {
            return [$value];
        }

        return array_values((array) $value);
    }

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $params
     *
     * @return array{0:string,1:?string,2:array<string,string>}
     */
    private function buildRequest(string $baseUrl, array $operation, array $params): array
    {
        self::validateRequiredParams($operation, $params);
        self::validateEnumParams($operation, $params);

        $path = (string) $operation['path'];
        foreach ($operation['pathParams'] ?? [] as $name) {
            // Presence is already enforced by validateRequiredParams() above.
            $path = str_replace('{' . $name . '}', rawurlencode((string) self::stringifyParam($params[$name])), $path);
        }

        $query = [];
        foreach ($operation['queryParams'] ?? [] as $parameter) {
            $name = $parameter['name'];
            $value = $params[$name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $query[] = rawurlencode($name) . '=' . rawurlencode(self::stringifyParam($item));
                }
            } else {
                $query[] = rawurlencode($name) . '=' . rawurlencode(self::stringifyParam($value));
            }
        }
        $url = $baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . implode('&', $query);
        }

        if (!empty($operation['formParams'])) {
            [$formBody, $formHeaders] = self::multipartBody($operation['formParams'], $params);

            return [$url, $formBody, $formHeaders];
        }

        $bodyParam = $operation['bodyParam'] ?? null;
        if ($bodyParam !== null) {
            $value = $params[$bodyParam] ?? ($params['body'] ?? null);
            if ($value !== null) {
                return [$url, json_encode($value, JSON_THROW_ON_ERROR), ['content-type' => 'application/json']];
            }
        }

        return [$url, null, []];
    }

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $params
     */
    private static function validateRequiredParams(array $operation, array $params): void
    {
        foreach ($operation['pathParams'] ?? [] as $name) {
            if (self::isMissing($params[$name] ?? null)) {
                throw new \InvalidArgumentException("missing required path parameter: {$name}");
            }
        }
        foreach (['queryParams', 'formParams'] as $location) {
            foreach ($operation[$location] ?? [] as $parameter) {
                if (!empty($parameter['required']) && self::isMissing($params[$parameter['name']] ?? null)) {
                    $loc = $parameter['in'] ?? 'request';
                    throw new \InvalidArgumentException("missing required {$loc} parameter: {$parameter['name']}");
                }
            }
        }
        if (!empty($operation['bodyRequired'])) {
            $bodyParam = $operation['bodyParam'] ?? null;
            if (self::isMissing($params[$bodyParam] ?? null) && self::isMissing($params['body'] ?? null)) {
                throw new \InvalidArgumentException("missing required body parameter: {$bodyParam}");
            }
        }
    }

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $params
     */
    private static function validateEnumParams(array $operation, array $params): void
    {
        foreach (['queryParams', 'formParams'] as $location) {
            foreach ($operation[$location] ?? [] as $parameter) {
                $enumValues = $parameter['enum'] ?? [];
                $value = $params[$parameter['name']] ?? null;
                if ($enumValues === [] || self::isMissing($value)) {
                    continue;
                }
                $values = is_array($value) ? $value : [$value];
                foreach ($values as $item) {
                    if (!in_array(self::stringifyParam($item), $enumValues, true)) {
                        $loc = $parameter['in'] ?? 'request';
                        throw new \InvalidArgumentException(
                            "invalid {$loc} parameter {$parameter['name']}: expected one of " . implode(', ', $enumValues),
                        );
                    }
                }
            }
        }
    }

    private static function isMissing(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    /**
     * @param array<int,array<string,mixed>> $formParams
     * @param array<string,mixed> $params
     *
     * @return array{0:string,1:array<string,string>}
     */
    private static function multipartBody(array $formParams, array $params): array
    {
        $boundary = 'crawlora-' . bin2hex(random_bytes(16));
        $chunks = '';
        foreach ($formParams as $parameter) {
            $name = $parameter['name'];
            if (!array_key_exists($name, $params) || $params[$name] === null) {
                continue;
            }
            $value = $params[$name];
            $chunks .= "--{$boundary}\r\n";
            if (($parameter['type'] ?? null) === 'file') {
                [$filename, $data] = self::readFileValue($value);
                $chunks .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
                $chunks .= "Content-Type: application/octet-stream\r\n\r\n";
                $chunks .= $data . "\r\n";
            } else {
                $chunks .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n" . (string) $value . "\r\n";
            }
        }
        $chunks .= "--{$boundary}--\r\n";

        return [$chunks, ['content-type' => "multipart/form-data; boundary={$boundary}"]];
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function readFileValue(mixed $value): array
    {
        if (is_string($value) && is_file($value)) {
            return [basename($value), (string) file_get_contents($value)];
        }

        return ['upload.bin', (string) $value];
    }

    /**
     * @param array<int,string> $security
     *
     * @return array<string,string>
     */
    private static function authHeaders(array $security, string $apiKey, string $jwtToken): array
    {
        $headers = [];
        if (in_array('ApiKeyAuth', $security, true) && $apiKey !== '') {
            $headers['x-api-key'] = $apiKey;
        }
        if (in_array('JWTAuth', $security, true) && $jwtToken !== '') {
            $lower = strtolower($jwtToken);
            $prefixed = str_starts_with($lower, 'token ') || str_starts_with($lower, 'bearer ');
            $headers['Authorization'] = $prefixed ? $jwtToken : "Token {$jwtToken}";
        }

        return $headers;
    }

    /**
     * @param array<string,string> ...$sources
     *
     * @return array<string,string>
     */
    private static function mergeHeaders(array ...$sources): array
    {
        $headers = [];
        $names = [];
        foreach ($sources as $source) {
            foreach ($source as $name => $value) {
                $lower = strtolower((string) $name);
                $existing = $names[$lower] ?? null;
                if ($existing !== null && $existing !== $name) {
                    unset($headers[$existing]);
                }
                $headers[$name] = (string) $value;
                $names[$lower] = $name;
            }
        }

        return $headers;
    }

    private static function validateResponseType(string $responseType): string
    {
        if (in_array($responseType, self::RESPONSE_TYPES, true)) {
            return $responseType;
        }

        throw new \InvalidArgumentException('invalid response_type: expected one of ' . implode(', ', self::RESPONSE_TYPES));
    }

    private static function parseResponse(string $body, string $contentType, string $responseType): mixed
    {
        if ($responseType === 'text') {
            return $body;
        }
        if ($responseType === 'json' || str_contains(strtolower($contentType), 'application/json')) {
            return $body === '' ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }

        return $body;
    }

    private static function stringifyParam(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param array<string,string> $headers
     */
    private static function ensureRequestId(array &$headers): string
    {
        $existing = self::headerValue($headers, 'x-request-id');
        if ($existing !== '') {
            return $existing;
        }
        $requestId = bin2hex(random_bytes(16));
        $headers['x-request-id'] = $requestId;

        return $requestId;
    }

    /**
     * @param array<string,string> $headers
     */
    private static function retryAfterDelay(array $headers, float $cap): float|null
    {
        $value = self::headerValue($headers, 'retry-after');
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $seconds = (float) $value;
            if ($seconds > 0) {
                return min($seconds, $cap);
            }
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }
        $delay = $timestamp - time();

        return $delay > 0 ? min((float) $delay, $cap) : null;
    }

    /**
     * @param array<string,string> $headers
     */
    private static function headerValue(array $headers, string $name): string
    {
        $name = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === $name) {
                return (string) $value;
            }
        }

        return '';
    }

    private static function isTimeoutError(\Throwable $exc): bool
    {
        // CurlTransport sets the exception code to the curl error number;
        // 28 is CURLE_OPERATION_TIMEOUTED. Fall back to the message for custom
        // transports that don't use curl error codes.
        if ($exc->getCode() === 28) {
            return true;
        }

        return str_contains(strtolower($exc->getMessage()), 'timed out')
            || str_contains(strtolower($exc->getMessage()), 'timeout');
    }

    /**
     * @return class-string<CrawloraError>
     */
    private static function errorClassFor(int $status): string
    {
        if ($status >= 400 && $status < 500) {
            return ClientError::class;
        }
        if ($status >= 500) {
            return ServerError::class;
        }

        return CrawloraError::class;
    }
}
