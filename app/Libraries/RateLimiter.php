<?php

namespace App\Libraries;

/**
 * Fixed-window counter on top of the cache service.
 */
class RateLimiter
{
    public function tooMany(string $key, int $max): bool
    {
        return $this->read($key)['count'] >= $max;
    }

    public function hit(string $key, int $window): int
    {
        $state = $this->read($key);
        $now   = time();

        if ($state['resetAt'] <= $now) {
            $state = ['count' => 0, 'resetAt' => $now + $window];
        }

        $state['count']++;
        cache()->save($this->cacheKey($key), $state, max(1, $state['resetAt'] - $now));

        return $state['count'];
    }

    public function retryAfter(string $key): int
    {
        return max(1, $this->read($key)['resetAt'] - time());
    }

    public function clear(string $key): void
    {
        cache()->delete($this->cacheKey($key));
    }

    /** @return array{count: int, resetAt: int} */
    private function read(string $key): array
    {
        $state = cache($this->cacheKey($key));

        if (! is_array($state) || ($state['resetAt'] ?? 0) <= time()) {
            return ['count' => 0, 'resetAt' => 0];
        }

        return $state;
    }

    private function cacheKey(string $key): string
    {
        return 'rl_' . hash('sha256', $key);
    }
}
