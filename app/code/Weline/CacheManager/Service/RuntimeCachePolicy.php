<?php

declare(strict_types=1);

namespace Weline\CacheManager\Service;

use Weline\Framework\App\Env;

final class RuntimeCachePolicy
{
    public const CONFIG_KEY = 'runtime_policy';

    private const DEFAULTS = [
        'page' => [
            'home_view_ttl' => 120,
            'category_view_ttl' => 300,
            'product_view_ttl' => 120,
        ],
        'search' => [
            'browse_result_ttl' => 300,
        ],
        'theme' => [
            'runtime_data_ttl' => 300,
            'partial_output_ttl' => 300,
            'slot_layout_ttl' => 120,
            'widget_output_ttl' => 120,
        ],
        'site' => [
            'currency_ttl' => 300,
            'i18n_locale_ttl' => 300,
        ],
        'dev' => [
            'trace_ttl' => 60,
            'routes_ttl' => 300,
            'docs_ttl' => 60,
        ],
        'memory' => [
            'connect_timeout' => 0.08,
            'timeout' => 0.12,
            'acquire_timeout' => 0.08,
        ],
    ];

    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return self::DEFAULTS;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $cacheConfig = (array)Env::getInstance()->getConfig('cache');
        $policy = \is_array($cacheConfig[self::CONFIG_KEY] ?? null)
            ? $cacheConfig[self::CONFIG_KEY]
            : [];

        $this->config = $this->mergeDefaults(self::DEFAULTS, $policy);

        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function saveConfig(array $config): void
    {
        $normalized = $this->normalizeConfig($config);
        $cacheConfig = (array)Env::getInstance()->getConfig('cache');
        $cacheConfig[self::CONFIG_KEY] = $normalized;

        if (!Env::getInstance()->setConfig('cache', $cacheConfig)) {
            throw new \RuntimeException((string)__('cache.runtime_policy 配置写入失败，请检查 app/etc/env.php 权限'));
        }

        $this->config = $this->mergeDefaults(self::DEFAULTS, $normalized);
    }

    public function ttl(string $path, int $default, int $min = 1, int $max = 86400): int
    {
        return $this->normalizeInt($this->value($path, $default), $default, $min, $max);
    }

    public function secondsFloat(string $path, float $default, float $min = 0.001, float $max = 5.0): float
    {
        return $this->normalizeFloat($this->value($path, $default), $default, $min, $max);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function memoryOptions(array $options = []): array
    {
        $options['connect_timeout'] = $this->secondsFloat('memory.connect_timeout', 0.08, 0.001, 5.0);
        $options['timeout'] = $this->secondsFloat('memory.timeout', 0.12, 0.001, 5.0);
        $options['acquire_timeout'] = $this->secondsFloat('memory.acquire_timeout', 0.08, 0.001, 5.0);

        return $options;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeConfig(array $config): array
    {
        $merged = $this->mergeDefaults(self::DEFAULTS, $config);

        return [
            'page' => [
                'home_view_ttl' => $this->normalizeInt($merged['page']['home_view_ttl'] ?? null, 120, 1, 86400),
                'category_view_ttl' => $this->normalizeInt($merged['page']['category_view_ttl'] ?? null, 300, 1, 86400),
                'product_view_ttl' => $this->normalizeInt($merged['page']['product_view_ttl'] ?? null, 120, 1, 86400),
            ],
            'search' => [
                'browse_result_ttl' => $this->normalizeInt($merged['search']['browse_result_ttl'] ?? null, 300, 1, 86400),
            ],
            'theme' => [
                'runtime_data_ttl' => $this->normalizeInt($merged['theme']['runtime_data_ttl'] ?? null, 300, 1, 86400),
                'partial_output_ttl' => $this->normalizeInt($merged['theme']['partial_output_ttl'] ?? null, 300, 1, 86400),
                'slot_layout_ttl' => $this->normalizeInt($merged['theme']['slot_layout_ttl'] ?? null, 120, 1, 86400),
                'widget_output_ttl' => $this->normalizeInt($merged['theme']['widget_output_ttl'] ?? null, 120, 1, 86400),
            ],
            'site' => [
                'currency_ttl' => $this->normalizeInt($merged['site']['currency_ttl'] ?? null, 300, 1, 86400),
                'i18n_locale_ttl' => $this->normalizeInt($merged['site']['i18n_locale_ttl'] ?? null, 300, 1, 86400),
            ],
            'dev' => [
                'trace_ttl' => $this->normalizeInt($merged['dev']['trace_ttl'] ?? null, 60, 1, 86400),
                'routes_ttl' => $this->normalizeInt($merged['dev']['routes_ttl'] ?? null, 300, 1, 86400),
                'docs_ttl' => $this->normalizeInt($merged['dev']['docs_ttl'] ?? null, 60, 1, 86400),
            ],
            'memory' => [
                'connect_timeout' => $this->normalizeFloat($merged['memory']['connect_timeout'] ?? null, 0.08, 0.001, 5.0),
                'timeout' => $this->normalizeFloat($merged['memory']['timeout'] ?? null, 0.12, 0.001, 5.0),
                'acquire_timeout' => $this->normalizeFloat($merged['memory']['acquire_timeout'] ?? null, 0.08, 0.001, 5.0),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function mergeDefaults(array $defaults, array $config): array
    {
        foreach ($defaults as $key => $value) {
            if (\is_array($value)) {
                $config[$key] = $this->mergeDefaults($value, \is_array($config[$key] ?? null) ? $config[$key] : []);
                continue;
            }

            if (!\array_key_exists($key, $config) || $config[$key] === '') {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    private function value(string $path, mixed $default): mixed
    {
        $value = $this->getConfig();
        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function normalizeInt(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null || $value === '' || !\is_numeric($value)) {
            $value = $default;
        }

        return \max($min, \min($max, (int)$value));
    }

    private function normalizeFloat(mixed $value, float $default, float $min, float $max): float
    {
        if ($value === null || $value === '' || !\is_numeric($value)) {
            $value = $default;
        }

        return \max($min, \min($max, (float)$value));
    }
}
