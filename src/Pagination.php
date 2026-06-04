<?php

declare(strict_types=1);

namespace Crawlora;

/**
 * Pagination helpers shared by the client's paginate() / paginateItems().
 */
final class Pagination
{
    public const PAGE_PARAM_NAMES = ['page', 'offset'];

    /**
     * First page/offset query parameter an operation exposes, or null.
     *
     * @param array<string,mixed> $operation
     */
    public static function detectPageParam(array $operation): string|null
    {
        $names = array_map(
            static fn (array $p): string => (string) $p['name'],
            $operation['queryParams'] ?? [],
        );
        foreach (self::PAGE_PARAM_NAMES as $candidate) {
            if (in_array($candidate, $names, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A page is empty when its `data` array (Crawlora envelope) or the page
     * itself is empty/blank.
     */
    public static function pageIsEmpty(mixed $response): bool
    {
        $data = (is_array($response) && array_key_exists('data', $response)) ? $response['data'] : $response;
        if ($data === null || $data === '') {
            return true;
        }
        if (is_array($data)) {
            return $data === [];
        }

        return !$data;
    }

    public static function defaultStart(string $pageParam): int
    {
        return $pageParam === 'offset' ? 0 : 1;
    }

    /**
     * Default item extractor: the response's `data` list (Crawlora envelope),
     * or the response itself when it is already a list.
     *
     * @return array<int,mixed>
     */
    public static function defaultItems(mixed $response): array
    {
        if (is_array($response) && isset($response['data']) && is_array($response['data'])) {
            return array_values($response['data']);
        }
        if (is_array($response) && array_is_list($response)) {
            return $response;
        }

        return [];
    }
}
