<?php
declare(strict_types=1);

$root = dirname(__DIR__, 5);
require_once $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

defined('DS') || define('DS', DIRECTORY_SEPARATOR);
defined('BP') || define('BP', $root . DIRECTORY_SEPARATOR);
defined('APP_PATH') || define('APP_PATH', BP . 'app' . DS);
defined('APP_ETC_PATH') || define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
defined('VENDOR_PATH') || define('VENDOR_PATH', BP . 'vendor' . DS);
defined('APP_CODE_PATH') || define('APP_CODE_PATH', BP . 'app' . DS . 'code' . DS);

defined('DEBUG') || define('DEBUG', false);
defined('CLI') || define('CLI', true);
defined('SANDBOX') || define('SANDBOX', false);
defined('DEV') || define('DEV', true);
defined('PROD') || define('PROD', false);

if (!function_exists('__')) {
    function __(string $text, array $params = []): string
    {
        foreach ($params as $key => $value) {
            $placeholder = is_int($key) ? '%{' . ($key + 1) . '}' : '%{' . $key . '}';
            $text = str_replace($placeholder, (string)$value, $text);
        }

        return $text;
    }
}

if (!function_exists('w_env')) {
    function w_env(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}


if (!function_exists('w_cache')) {
    function w_cache(string $identity = 'default'): object
    {
        static $pools = [];

        return $pools[$identity] ??= new AppUnitCachePool($identity);
    }
}

final class AppUnitCachePool implements \Weline\Framework\Cache\Contract\CachePoolInterface
{
    private array $values = [];

    public function __construct(private readonly string $identity)
    {
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->values[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getTip(): string
    {
        return 'App unit cache';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        return \array_intersect_key($this->values, \array_flip($keys));
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string)$key);
        }
        return true;
    }

    public function getStats(): array
    {
        return [
            'identity' => $this->identity,
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
    ): mixed {
        return $this->get($key);
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
    ): bool {
        return $this->set($key, $value, $ttl);
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
    ): bool {
        return $this->delete($key);
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false,
    ): bool {
        return $this->has($key);
    }
}
