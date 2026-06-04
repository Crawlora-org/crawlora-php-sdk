<?php

declare(strict_types=1);

namespace Crawlora;

/**
 * Default transport using the curl extension. A transport is any callable
 * `function(array $request): array` that, given
 * `['method','url','headers','body','timeout']`, returns
 * `['status' => int, 'headers' => array<string,string>, 'body' => string]`.
 *
 * Reuses one curl handle per instance so repeated calls share a connection.
 */
final class CurlTransport
{
    /** @var \CurlHandle|null */
    private mixed $handle = null;

    /**
     * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float} $request
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function __invoke(array $request): array
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('the curl extension is required for the default transport');
        }
        $handle = $this->handle ??= curl_init();
        if ($handle === false) {
            throw new \RuntimeException('failed to initialize curl');
        }

        $headerLines = [];
        foreach ($request['headers'] as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_reset($handle);
        curl_setopt_array($handle, [
            CURLOPT_URL => $request['url'],
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($request['timeout'] * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round($request['timeout'] * 1000),
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($request['body'] !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request['body']);
        }

        $body = curl_exec($handle);
        if ($body === false) {
            // Carry the curl error number as the exception code so the client can
            // classify timeouts (CURLE_OPERATION_TIMEOUTED = 28) without parsing
            // the message string.
            $errno = curl_errno($handle);
            throw new \RuntimeException('curl error ' . $errno . ': ' . curl_error($handle), $errno);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'body' => is_string($body) ? $body : '',
        ];
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            curl_close($this->handle);
            $this->handle = null;
        }
    }
}
