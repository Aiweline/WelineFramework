<?php

declare(strict_types=1);

if (!\function_exists('__')) {
    function __(string $text, array $params = []): string
    {
        $out = $text;
        foreach ($params as $index => $value) {
            $out = \str_replace('%{' . ($index + 1) . '}', (string)$value, $out);
        }

        return $out;
    }
}

if (!\defined('BP')) {
    \define('BP', \dirname(__DIR__, 6) . DIRECTORY_SEPARATOR);
}
if (!\defined('DS')) {
    \define('DS', DIRECTORY_SEPARATOR);
}

$autoload = \dirname(__DIR__, 6) . '/vendor/autoload.php';
if (\is_file($autoload)) {
    require_once $autoload;
}

if (!\function_exists('w_cache')) {
    function w_cache(string $identity = 'default'): object
    {
        static $pools = [];
        return $pools[$identity] ??= new SystemConfigUnitCachePool($identity);
    }
}

final class SystemConfigUnitCachePool implements \Weline\Framework\Cache\Contract\CachePoolInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct(
        private readonly string $identity,
    ) {
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
        return 'SystemConfig unit cache';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if ($this->has($key)) {
                $values[$key] = $this->values[$key];
            }
        }
        return $values;
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

$codeRoot = \dirname(__DIR__, 3);
\spl_autoload_register(static function (string $class) use ($codeRoot): void {
    $map = [
        'Weline\\SystemConfig\\' => $codeRoot . '/SystemConfig/',
        'Weline\\Acl\\' => $codeRoot . '/Acl/',
        'Weline\\Backend\\' => $codeRoot . '/Backend/',
        'Weline\\Framework\\' => $codeRoot . '/Framework/',
        'Weline\\Websites\\' => $codeRoot . '/Websites/',
    ];
    foreach ($map as $prefix => $base) {
        if (!\str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
        $candidates = [
            $base . $relative . '.php',
            $base . \str_replace('Extends/', 'extends/', $relative) . '.php',
        ];
        foreach ($candidates as $file) {
            if (\is_file($file)) {
                require_once $file;

                return;
            }
        }
    }
}, true, true);
