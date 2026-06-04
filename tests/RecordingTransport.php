<?php

declare(strict_types=1);

namespace Crawlora\Tests;

/**
 * Transport double: records every call and returns canned responses — either an
 * array of `[status, headers, body]` triples consumed in order, or a callable
 * receiving the recorded call array.
 */
final class RecordingTransport
{
    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float}> */
    public array $calls = [];

    /** @var array<int,array{0:int,1:?array<string,string>,2:?string}>|callable */
    private $responses;

    /**
     * @param array<int,array{0:int,1:?array<string,string>,2:?string}>|callable $responses
     */
    public function __construct($responses)
    {
        $this->responses = $responses;
    }

    /**
     * @param array{method:string,url:string,headers:array<string,string>,body:?string,timeout:float} $request
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function __invoke(array $request): array
    {
        $this->calls[] = $request;
        if (is_callable($this->responses)) {
            $resp = ($this->responses)($request);
        } else {
            $resp = array_shift($this->responses);
        }
        [$status, $headers, $body] = $resp;

        return ['status' => $status, 'headers' => $headers ?? [], 'body' => $body ?? ''];
    }
}
