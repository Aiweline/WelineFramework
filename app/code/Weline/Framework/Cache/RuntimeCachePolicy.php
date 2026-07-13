<?php

declare(strict_types=1);

namespace Weline\Framework\Cache;

use Weline\Framework\App\Env;

final class RuntimeCachePolicy
{
    public function ttl(string $path, int $default, int $min = 1, int $max = 86400): int
    {
        $value = $this->value($path, $default);
        if ($value === '' || !is_numeric($value)) {
            $value = $default;
        }
        return max($min, min($max, (int)$value));
    }

    public function secondsFloat(string $path, float $default, float $min = 0.001, float $max = 5.0): float
    {
        $value = $this->value($path, $default);
        if ($value === '' || !is_numeric($value)) {
            $value = $default;
        }
        return max($min, min($max, (float)$value));
    }

    public function memoryOptions(array $options = []): array
    {
        $options['connect_timeout'] = $this->secondsFloat('memory.connect_timeout', 0.08);
        $options['timeout'] = $this->secondsFloat('memory.timeout', 0.12);
        $options['acquire_timeout'] = $this->secondsFloat('memory.acquire_timeout', 0.08);
        return $options;
    }

    private function value(string $path, mixed $default): mixed
    {
        $cacheConfig = (array)Env::getInstance()->getConfig('cache');
        $value = is_array($cacheConfig['runtime_policy'] ?? null) ? $cacheConfig['runtime_policy'] : [];
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}
