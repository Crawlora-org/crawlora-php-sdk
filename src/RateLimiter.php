<?php

declare(strict_types=1);

namespace Crawlora;

/**
 * Optional client-side throttle. Spaces requests to a maximum rate (requests
 * per second). PHP requests are synchronous, so the concurrency cap is a
 * best-effort no-op guard kept for API parity with the other SDKs.
 */
final class RateLimiter
{
    private float $interval;

    private float $nextAt = 0.0;

    public function __construct(float|null $rps, int|null $concurrency = null)
    {
        $this->interval = ($rps !== null && $rps > 0) ? 1.0 / $rps : 0.0;
        // $concurrency is accepted for parity; synchronous PHP issues one
        // request at a time, so there is nothing to bound.
        unset($concurrency);
    }

    /**
     * @template T
     *
     * @param callable():T $fn
     *
     * @return T
     */
    public function run(callable $fn): mixed
    {
        $this->space();

        return $fn();
    }

    private function space(): void
    {
        if ($this->interval <= 0.0) {
            return;
        }
        $now = microtime(true);
        $wait = max(0.0, $this->nextAt - $now);
        $this->nextAt = max($now, $this->nextAt) + $this->interval;
        if ($wait > 0) {
            usleep((int) round($wait * 1_000_000));
        }
    }
}
