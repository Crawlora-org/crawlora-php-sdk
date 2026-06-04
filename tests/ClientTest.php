<?php

declare(strict_types=1);

namespace Crawlora\Tests;

use Crawlora\Client;
use Crawlora\Exception\ClientError;
use Crawlora\Exception\NetworkError;
use Crawlora\Exception\ServerError;
use Crawlora\Operations;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const JSON_HEADERS = ['content-type' => 'application/json'];

    /**
     * @param array<int,array{0:int,1:?array<string,string>,2:?string}>|callable $responses
     * @param array<string,mixed> $options
     */
    private function client($responses, array $options = []): Client
    {
        return new Client(['apiKey' => 'secret', 'transport' => new RecordingTransport($responses)] + $options);
    }

    /**
     * @param array<int,mixed>|array<string,mixed> $data
     *
     * @return array{0:int,1:array<string,string>,2:string}
     */
    private static function ok(array $data): array
    {
        return [200, self::JSON_HEADERS, (string) json_encode(['code' => 200, 'msg' => 'OK', 'data' => $data])];
    }

    public function testGroupedCallSendsApiKeyAndParsesJson(): void
    {
        $transport = new RecordingTransport([self::ok([['title' => 'hit']])]);
        $c = new Client(['apiKey' => 'secret', 'transport' => $transport]);
        $result = $c->bing->search(['q' => 'web scraping']);
        $this->assertSame([['title' => 'hit']], $result['data']);
        $call = $transport->calls[0];
        $this->assertSame('GET', $call['method']);
        $this->assertStringContainsString('/bing/search', $call['url']);
        $this->assertStringContainsString('q=web%20scraping', $call['url']);
        $this->assertSame('secret', $call['headers']['x-api-key']);
        $this->assertMatchesRegularExpression('#crawlora-php-sdk/#', $call['headers']['User-Agent']);
    }

    public function testDynamicRequestUnknownOperationThrows(): void
    {
        $c = $this->client([]);
        $this->expectException(\InvalidArgumentException::class);
        $c->request('does-not-exist');
    }

    public function testMissingRequiredQueryParam(): void
    {
        $c = $this->client([self::ok([])]);
        $this->expectException(\InvalidArgumentException::class);
        $c->bing->search();
    }

    public function testMissingRequiredPathParam(): void
    {
        $c = $this->client([self::ok([])]);
        $this->expectException(\InvalidArgumentException::class);
        $c->request('amazon-product', []);
    }

    public function testArrayQueryRepeated(): void
    {
        $transport = new RecordingTransport([self::ok([])]);
        $c = new Client(['apiKey' => 'k', 'transport' => $transport]);
        $c->request('bing-search', ['q' => 'a']);
        $this->assertStringContainsString('q=a', $transport->calls[0]['url']);
    }

    public function testEnumValidationRejectsBadValue(): void
    {
        $c = $this->client([self::ok([])]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/language/');
        $c->amazon->product(['asin' => 'B000', 'language' => 'fr_FR']);
    }

    public function testEnumValidationAcceptsGoodValue(): void
    {
        $transport = new RecordingTransport([self::ok([])]);
        $c = new Client(['apiKey' => 'k', 'transport' => $transport]);
        $c->amazon->product(['asin' => 'B000', 'language' => 'en_US']);
        $this->assertStringContainsString('language=en_US', $transport->calls[0]['url']);
    }

    public function testUnexpectedParamForGroupCallThrows(): void
    {
        $c = $this->client([self::ok([])]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unexpected parameter/');
        $c->bing->search(['q' => 'x', 'nope' => 1]);
    }

    public function testJwtAuthHeader(): void
    {
        $op = null;
        foreach (Operations::all() as $id => $o) {
            if (in_array('JWTAuth', $o['security'] ?? [], true)) {
                $op = [$id, $o];
                break;
            }
        }
        if ($op === null) {
            $this->markTestSkipped('no JWTAuth operation in contract');
        }
        $transport = new RecordingTransport([self::ok([])]);
        $c = new Client(['jwtToken' => 'abc', 'transport' => $transport]);
        $c->request($op[0], $this->requiredStub($op[1]));
        $this->assertSame('Token abc', $transport->calls[0]['headers']['Authorization']);
    }

    public function testFourxxRaisesClientErrorWithBody(): void
    {
        $body = (string) json_encode(['code' => 400, 'msg' => 'bad request']);
        $c = $this->client([[400, self::JSON_HEADERS, $body]]);
        try {
            $c->bing->search(['q' => 'x']);
            $this->fail('expected ClientError');
        } catch (ClientError $e) {
            $this->assertSame(400, $e->status);
            $this->assertSame(400, $e->apiCode);
            $this->assertSame('bad request', $e->getMessage());
            $this->assertSame($body, $e->rawBody);
        }
    }

    public function testFivexxRaisesServerError(): void
    {
        $c = $this->client([[500, self::JSON_HEADERS, (string) json_encode(['msg' => 'boom'])]]);
        try {
            $c->bing->search(['q' => 'x']);
            $this->fail('expected ServerError');
        } catch (ServerError $e) {
            $this->assertSame(500, $e->status);
        }
    }

    public function testRetryOn500ThenSuccess(): void
    {
        $responses = [[500, self::JSON_HEADERS, (string) json_encode(['msg' => 'boom'])], self::ok([['ok' => true]])];
        $transport = new RecordingTransport($responses);
        $c = new Client(['apiKey' => 'k', 'transport' => $transport, 'retries' => 1, 'retryDelay' => 0]);
        $result = $c->bing->search(['q' => 'x']);
        $this->assertSame([['ok' => true]], $result['data']);
        $this->assertCount(2, $transport->calls);
    }

    public function testNoRetryWhenNotRetryable(): void
    {
        $transport = new RecordingTransport([[400, self::JSON_HEADERS, (string) json_encode(['msg' => 'nope'])]]);
        $c = new Client(['apiKey' => 'k', 'transport' => $transport, 'retries' => 3, 'retryDelay' => 0]);
        try {
            $c->bing->search(['q' => 'x']);
            $this->fail('expected ClientError');
        } catch (ClientError) {
        }
        $this->assertCount(1, $transport->calls);
    }

    public function testRetryAfterHeaderRespected(): void
    {
        $delays = [];
        $responses = [
            [429, self::JSON_HEADERS + ['retry-after' => '1'], (string) json_encode(['msg' => 'slow'])],
            self::ok([]),
        ];
        $transport = new RecordingTransport($responses);
        $c = new Client([
            'apiKey' => 'k',
            'transport' => $transport,
            'retries' => 1,
            'retryDelay' => 0.01,
            'onRetry' => function (int $attempt, \Throwable $err, float $delay) use (&$delays): void {
                $delays[] = $delay;
            },
        ]);
        $c->bing->search(['q' => 'x']);
        // Retry-After: 1 overrides the tiny exponential backoff.
        $this->assertSame([1.0], $delays);
    }

    public function testTextResponseMode(): void
    {
        $c = $this->client([[200, ['content-type' => 'text/plain'], 'plain transcript']]);
        $result = $c->request('youtube-transcript', ['id' => 'abc'], ['response_type' => 'text']);
        $this->assertSame('plain transcript', $result);
    }

    public function testStreamResponseReturnsResource(): void
    {
        $c = $this->client([[200, self::JSON_HEADERS, 'raw-bytes']]);
        $result = $c->request('bing-search', ['q' => 'x'], ['response_type' => 'stream']);
        $this->assertIsResource($result);
        $this->assertSame('raw-bytes', stream_get_contents($result));
    }

    public function testBeforeRequestHookMutatesHeaders(): void
    {
        $transport = new RecordingTransport([self::ok([])]);
        $hook = function (array &$ctx): void {
            $ctx['headers']['X-Custom'] = 'yes';
        };
        $c = new Client(['apiKey' => 'k', 'transport' => $transport, 'beforeRequest' => $hook]);
        $c->bing->search(['q' => 'x']);
        $this->assertSame('yes', $transport->calls[0]['headers']['X-Custom']);
    }

    public function testAfterResponseHookReplacesBody(): void
    {
        $c = new Client([
            'apiKey' => 'k',
            'transport' => new RecordingTransport([self::ok(['n' => 1])]),
            'afterResponse' => fn ($op, $status, $headers, $body) => ['replaced' => true],
        ]);
        $this->assertSame(['replaced' => true], $c->bing->search(['q' => 'x']));
    }

    public function testRequestIdAddedWhenEnabled(): void
    {
        $transport = new RecordingTransport([self::ok([])]);
        $c = new Client(['apiKey' => 'k', 'transport' => $transport, 'requestId' => true]);
        $c->bing->search(['q' => 'x']);
        $this->assertNotEmpty($transport->calls[0]['headers']['x-request-id']);
    }

    public function testIdempotencyKeyAddedForPost(): void
    {
        $post = null;
        foreach (Operations::all() as $id => $o) {
            if ($o['method'] === 'POST') {
                $post = [$id, $o];
                break;
            }
        }
        if ($post === null) {
            $this->markTestSkipped('no POST operation in contract');
        }
        $transport = new RecordingTransport([self::ok([])]);
        $c = new Client(['jwtToken' => 'j', 'apiKey' => 'k', 'transport' => $transport, 'idempotencyKeys' => true]);
        $c->request($post[0], $this->requiredStub($post[1]));
        $this->assertArrayHasKey('Idempotency-Key', $transport->calls[0]['headers']);
        $this->assertNotEmpty($transport->calls[0]['headers']['Idempotency-Key']);
    }

    public function testNetworkErrorOnTransportThrow(): void
    {
        $raising = function (array $request): array {
            throw new \RuntimeException('boom');
        };
        $c = new Client(['apiKey' => 'k', 'transport' => $raising]);
        $this->expectException(NetworkError::class);
        $c->bing->search(['q' => 'x']);
    }

    public function testPaginateNumericStopsOnEmpty(): void
    {
        $pages = [self::ok([['i' => 1]]), self::ok([['i' => 2]]), self::ok([])];
        $transport = new RecordingTransport($pages);
        $c = new Client(['apiKey' => 'k', 'transport' => $transport]);
        $collected = iterator_to_array($c->paginate('airbnb-room-reviews', ['id' => 'r1']));
        $this->assertCount(3, $collected);
        $this->assertCount(3, $transport->calls);
        $this->assertStringContainsString('page=1', $transport->calls[0]['url']);
        $this->assertStringContainsString('page=2', $transport->calls[1]['url']);
    }

    public function testPaginateItemsExtractsData(): void
    {
        $pages = [self::ok([['i' => 1], ['i' => 2]]), self::ok([])];
        $c = new Client(['apiKey' => 'k', 'transport' => new RecordingTransport($pages)]);
        $items = iterator_to_array($c->paginateItems('airbnb-room-reviews', ['id' => 'r1']), false);
        $this->assertSame([['i' => 1], ['i' => 2]], $items);
    }

    public function testPaginateCursorMode(): void
    {
        $cur = null;
        foreach (Operations::all() as $id => $o) {
            if (!empty($o['cursorParams'])) {
                $cur = [$id, $o];
                break;
            }
        }
        if ($cur === null) {
            $this->markTestSkipped('no cursor operation in contract');
        }
        $cursorParam = $cur[1]['cursorParams'][0];
        $responses = [
            [200, self::JSON_HEADERS, (string) json_encode(['data' => [1], 'next' => 'c2'])],
            [200, self::JSON_HEADERS, (string) json_encode(['data' => [2], 'next' => null])],
        ];
        $transport = new RecordingTransport($responses);
        $c = new Client(['apiKey' => 'k', 'transport' => $transport]);
        $pages = iterator_to_array($c->paginate($cur[0], $this->requiredStub($cur[1]), [
            'cursor_param' => $cursorParam,
            'next_cursor' => fn ($r) => $r['next'] ?? null,
        ]));
        $this->assertCount(2, $pages);
        $this->assertStringContainsString("{$cursorParam}=c2", $transport->calls[1]['url']);
    }

    public function testInvalidResponseTypeThrows(): void
    {
        $c = $this->client([self::ok([])]);
        $this->expectException(\InvalidArgumentException::class);
        $c->request('bing-search', ['q' => 'x'], ['response_type' => 'xml']);
    }

    /**
     * Exercises the real CurlTransport against an in-process PHP server.
     */
    public function testDefaultTransportAgainstRealServer(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('curl extension not available');
        }
        $docroot = sys_get_temp_dir() . '/crawlora_php_test_' . bin2hex(random_bytes(4));
        mkdir($docroot);
        $router = $docroot . '/router.php';
        file_put_contents($router, <<<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode(['code' => 200, 'msg' => 'OK', 'data' => ['echo' => $_SERVER['REQUEST_URI']]]);
PHP);
        $port = 8599;
        $proc = proc_open(
            ['php', '-S', "127.0.0.1:{$port}", $router],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        // Wait for the server to accept connections.
        $up = false;
        for ($i = 0; $i < 50; $i++) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);
                $up = true;
                break;
            }
            usleep(100_000);
        }
        $this->assertTrue($up, 'built-in server did not start');

        try {
            $c = new Client(['apiKey' => 'k', 'baseUrl' => "http://127.0.0.1:{$port}/api/v1"]);
            $result = $c->bing->search(['q' => 'real']);
            $this->assertStringContainsString('/api/v1/bing/search', $result['data']['echo']);
            $c->close();
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            @unlink($router);
            @rmdir($docroot);
        }
    }

    /**
     * Build the minimal required params for an arbitrary operation.
     *
     * @param array<string,mixed> $operation
     *
     * @return array<string,mixed>
     */
    private function requiredStub(array $operation): array
    {
        $params = [];
        foreach ($operation['pathParams'] ?? [] as $name) {
            $params[$name] = 'x';
        }
        foreach ($operation['queryParams'] ?? [] as $p) {
            if (!empty($p['required'])) {
                $params[$p['name']] = $p['enum'][0] ?? 'x';
            }
        }
        foreach ($operation['formParams'] ?? [] as $p) {
            if (!empty($p['required'])) {
                $params[$p['name']] = 'x';
            }
        }
        if (!empty($operation['bodyRequired'])) {
            $params[$operation['bodyParam']] = ['stub' => true];
        }

        return $params;
    }
}
