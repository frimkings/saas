<?php

namespace App\Support;

use Closure;

/**
 * Values looked up once per request (or queued job) and reused. Bound as scoped, so every
 * request and job starts empty; call forget() when the underlying rows change.
 */
class RequestMemo
{
    private array $values = [];

    public function remember(string $key, Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $resolve();
        }

        return $this->values[$key];
    }

    /** Drops every key starting with $prefix. */
    public function forget(string $prefix): void
    {
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->values[$key]);
            }
        }
    }
}
