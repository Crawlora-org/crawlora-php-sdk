<?php

declare(strict_types=1);

namespace Crawlora;

/**
 * Dispatches `$client->bing->search([...])` style calls to the underlying
 * operation id, validating that supplied param keys are accepted by the
 * operation.
 */
final class Group
{
    /**
     * @param array<string,string> $operations method name => operation id
     */
    public function __construct(
        private Client $client,
        private array $operations,
    ) {
    }

    /**
     * @param array{0?:array<string,mixed>,1?:array<string,mixed>} $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $operationId = $this->operations[$name] ?? null;
        if ($operationId === null) {
            throw new \BadMethodCallException("unknown method {$name} on Crawlora group");
        }

        /** @var array<string,mixed> $params */
        $params = $arguments[0] ?? [];
        /** @var array<string,mixed> $options */
        $options = $arguments[1] ?? [];

        $allowed = self::allowedParams($operationId);
        $unknown = array_diff(array_keys($params), $allowed);
        if ($unknown !== []) {
            sort($unknown);
            throw new \InvalidArgumentException(
                "unexpected parameter(s) for {$operationId}: " . implode(', ', $unknown),
            );
        }

        return $this->client->request($operationId, $params, $options);
    }

    public function has(string $name): bool
    {
        return isset($this->operations[$name]);
    }

    /**
     * @return array<int,string>
     */
    private static function allowedParams(string $operationId): array
    {
        $operation = Operations::get($operationId) ?? [];
        $allowed = $operation['pathParams'] ?? [];
        foreach ($operation['queryParams'] ?? [] as $p) {
            $allowed[] = $p['name'];
        }
        foreach ($operation['formParams'] ?? [] as $p) {
            $allowed[] = $p['name'];
        }
        if (!empty($operation['bodyParam'])) {
            $allowed[] = $operation['bodyParam'];
        }
        $allowed[] = 'body';

        return $allowed;
    }
}
