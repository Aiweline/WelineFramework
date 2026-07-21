<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Model\SystemConfig;

final class SystemConfigTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::cleanup();
    }

    protected function tearDown(): void
    {
        RequestContext::cleanup();
    }

    public function testGetConfigCachesResultAcrossRequests(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['singleLoads' => 0, 'rowsLoads' => 0];

        $first = $this->createConfigModel($cache, $counter, 'logo-dark.png');
        self::assertSame('logo-dark.png', $first->getConfig('logo_dark', 'Weline_Backend', SystemConfig::area_BACKEND));
        self::assertSame(1, $counter->singleLoads);

        RequestContext::cleanup();

        $second = $this->createConfigModel($cache, $counter, 'logo-dark-updated.png');
        self::assertSame('logo-dark.png', $second->getConfig('logo_dark', 'Weline_Backend', SystemConfig::area_BACKEND));
        self::assertSame(1, $counter->singleLoads);
    }

    public function testGetConfigRechecksCachedNullMissesAcrossRequests(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['singleLoads' => 0, 'rowsLoads' => 0];

        $first = $this->createConfigModel($cache, $counter, null);
        self::assertNull($first->getConfig('missing_key', 'Weline_Backend', SystemConfig::area_BACKEND));
        self::assertSame(1, $counter->singleLoads);

        RequestContext::cleanup();

        $second = $this->createConfigModel($cache, $counter, 'should-not-load');
        self::assertSame('should-not-load', $second->getConfig('missing_key', 'Weline_Backend', SystemConfig::area_BACKEND));
        self::assertSame(2, $counter->singleLoads);
    }

    public function testGetConfigMapByModuleNormalizesRowsAndCachesThem(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['singleLoads' => 0, 'rowsLoads' => 0];
        $rows = [
            [
                SystemConfig::schema_fields_KEY => 'logo_dark',
                SystemConfig::schema_fields_VALUE => 'logo-dark.png',
            ],
            [
                SystemConfig::schema_fields_KEY => 'site_name',
                SystemConfig::schema_fields_VALUE => 'Weline',
            ],
        ];

        $first = $this->createConfigModel($cache, $counter, null, $rows);
        self::assertSame(
            ['logo_dark' => 'logo-dark.png', 'site_name' => 'Weline'],
            $first->getConfigMapByModule('Weline_Backend', SystemConfig::area_BACKEND)
        );
        self::assertSame(1, $counter->rowsLoads);

        RequestContext::cleanup();

        $second = $this->createConfigModel($cache, $counter, null, [
            [
                SystemConfig::schema_fields_KEY => 'logo_dark',
                SystemConfig::schema_fields_VALUE => 'changed.png',
            ],
        ]);
        self::assertSame(
            ['logo_dark' => 'logo-dark.png', 'site_name' => 'Weline'],
            $second->getConfigMapByModule('Weline_Backend', SystemConfig::area_BACKEND)
        );
        self::assertSame(1, $counter->rowsLoads);
    }

    public function testGetConfigTreatsMissingSystemConfigTableAsUnconfigured(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['singleLoads' => 0, 'rowsLoads' => 0];

        $model = new class($cache, $counter) extends SystemConfig {
            public function __construct(
                CachePoolInterface $cache,
                private readonly object $counter
            ) {
                $this->_cache = $cache;
            }

            protected function loadSingleConfigValue(string $key, string $module, string $area): mixed
            {
                $this->counter->singleLoads++;
                throw new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: system_config');
            }

            protected function dispatchConfigGetEvent(string $key, string $module, string $area, mixed $value): mixed
            {
                return $value;
            }
        };

        self::assertNull($model->getConfig('start_page_path', 'Weline_Backend', SystemConfig::area_BACKEND));
        self::assertSame(1, $counter->singleLoads);
    }

    public function testGetConfigMapTreatsMissingSystemConfigTableAsEmpty(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['singleLoads' => 0, 'rowsLoads' => 0];

        $model = new class($cache, $counter) extends SystemConfig {
            public function __construct(
                CachePoolInterface $cache,
                private readonly object $counter
            ) {
                $this->_cache = $cache;
            }

            protected function loadConfigRowsByModule(string $module, string $area): array
            {
                $this->counter->rowsLoads++;
                throw new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: system_config');
            }
        };

        self::assertSame([], $model->getConfigMapByModule('Weline_Backend', SystemConfig::area_BACKEND));
        self::assertSame(1, $counter->rowsLoads);
    }

    private function createConfigModel(
        CachePoolInterface $cache,
        object $counter,
        mixed $singleValue,
        array $rows = []
    ): SystemConfig {
        return new class($cache, $counter, $singleValue, $rows) extends SystemConfig {
            public function __construct(
                CachePoolInterface $cache,
                private readonly object $counter,
                private readonly mixed $singleValue,
                private readonly array $rows
            ) {
                $this->_cache = $cache;
            }

            protected function loadSingleConfigValue(string $key, string $module, string $area): mixed
            {
                $this->counter->singleLoads++;
                return $this->singleValue;
            }

            protected function loadConfigRowsByModule(string $module, string $area): array
            {
                $this->counter->rowsLoads++;
                return $this->rows;
            }

            protected function dispatchConfigGetEvent(string $key, string $module, string $area, mixed $value): mixed
            {
                return $value;
            }
        };
    }
}

final class SystemConfigTestCachePool implements CachePoolInterface
{
    /** @var array<string, mixed> */
    private array $storage = [];

    public function __construct(
        private readonly string $identity
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->storage[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->storage = [];
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->storage);
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getTip(): string
    {
        return 'test';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }
        return $values;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
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
        bool $currency = false
    ): mixed {
        return $this->get($key);
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->set($key, $value, $ttl);
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->delete($key);
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->has($key);
    }
}
