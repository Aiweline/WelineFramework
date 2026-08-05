<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\App\Env;

final class AsyncEventConfig
{
    public function producerEnabled(): bool
    {
        return $this->bool('event.async.producer_enabled', false);
    }

    public function relayEnabled(): bool
    {
        return $this->bool('event.async.relay_enabled', false);
    }

    private function bool(string $key, bool $default): bool
    {
        $value = Env::get($key, null);
        if ($value === null) {
            $value = Env::module_env('Weline_Framework');
            foreach (explode('.', $key) as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    $value = $default;
                    break;
                }
                $value = $value[$segment];
            }
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
