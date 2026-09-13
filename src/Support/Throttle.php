<?php

declare(strict_types=1);

namespace Thayron\LunarCjDropshipping\Support;

/**
 * Spaces CJdropshipping API calls made by this process (0 disables it).
 */
final class Throttle
{
    private float $lastRequestAt = 0.0;

    public function __construct(private readonly int $requestsPerSecond) {}

    public function wait(): void
    {
        if ($this->requestsPerSecond <= 0) {
            return;
        }

        $interval = 1 / $this->requestsPerSecond;
        $elapsed = microtime(true) - $this->lastRequestAt;

        if ($this->lastRequestAt > 0 && $elapsed < $interval) {
            usleep((int) (($interval - $elapsed) * 1_000_000));
        }

        $this->lastRequestAt = microtime(true);
    }
}
