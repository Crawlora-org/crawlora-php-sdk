<?php

declare(strict_types=1);

namespace Crawlora\Exception;

/**
 * Base class for every error raised by the SDK. Carries the HTTP status, the
 * parsed API `code`/body, the raw response text, response headers, and the
 * request id (when request-id tracking is enabled).
 */
class CrawloraError extends \RuntimeException
{
    /** @var array<string,string> */
    public array $headers;

    /**
     * The API-level error code from the response body (`code` field). Named
     * `apiCode` because `\Exception::$code` is reserved by PHP for the
     * exception code.
     */
    public int|null $apiCode;

    /**
     * @param array<string,string>|null $headers
     */
    public function __construct(
        string $message,
        public int $status = 0,
        int|null $code = null,
        public mixed $body = null,
        public string $rawBody = '',
        array|null $headers = null,
        public string|null $requestId = null,
        \Throwable|null $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->apiCode = $code;
        $this->headers = $headers ?? [];
    }

    /** API-level error code from the response body, if any. */
    public function code(): int|null
    {
        return $this->apiCode;
    }
}
