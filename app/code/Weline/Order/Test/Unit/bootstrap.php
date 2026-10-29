<?php

declare(strict_types=1);

/**
 * Minimal bootstrap for Order Facade unit tests (no full app bootstrap).
 */
if (!\function_exists('__')) {
    function __(string $text, array|string|int $params = ''): string
    {
        $out = $text;
        if (!\is_array($params)) {
            $params = $params === '' ? [] : [$params];
        }
        foreach ($params as $i => $value) {
            $out = str_replace('%{' . ($i + 1) . '}', (string) $value, $out);
        }

        return $out;
    }
}

if (!\function_exists('w_log_error')) {
    function w_log_error(string $message): void
    {
    }
}

if (!\defined('BP')) {
    \define('BP', \dirname(__DIR__, 6) . DIRECTORY_SEPARATOR);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}
if (!\defined('APP_PATH')) {
    \define('APP_PATH', BP . 'app' . DS);
}
if (!\defined('APP_CODE_PATH')) {
    \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
}
if (!\defined('APP_ETC_PATH')) {
    \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
}
if (!\defined('PUB')) {
    \define('PUB', BP . 'pub' . DS);
}
if (!\defined('VENDOR_PATH')) {
    \define('VENDOR_PATH', BP . 'vendor' . DS);
}
if (!\defined('DEBUG')) {
    \define('DEBUG', false);
}
if (!\defined('CLI')) {
    \define('CLI', true);
}
if (!\defined('SANDBOX')) {
    \define('SANDBOX', false);
}
if (!\defined('DEV')) {
    \define('DEV', true);
}
if (!\defined('PROD')) {
    \define('PROD', false);
}

$autoload = \dirname(__DIR__, 6) . '/vendor/autoload.php';
if (\is_file($autoload)) {
    require_once $autoload;
}

if (!\function_exists('w_cache')) {
    function w_cache(string $identity = 'default'): object
    {
        static $pools = [];

        return $pools[$identity] ??= new OrderUnitCachePool($identity);
    }
}

final class OrderUnitCachePool implements \Weline\Framework\Cache\Contract\CachePoolInterface
{
    /** @var array<string, mixed> */
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
        return 'Order unit cache';
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

$codeRoot = \dirname(__DIR__, 3); // app/code/Weline

spl_autoload_register(static function (string $class) use ($codeRoot): void {
    $map = [
        'Weline\\Order\\' => $codeRoot . '/Order/',
        'Weline\\Acl\\' => $codeRoot . '/Acl/',
        'Weline\\Payment\\' => $codeRoot . '/Payment/',
        'Weline\\Framework\\' => $codeRoot . '/Framework/',
    ];
    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $candidates = [
            $base . $relative . '.php',
            $base . str_replace('Extends/', 'extends/', $relative) . '.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                require_once $file;

                return;
            }
        }
    }
});
