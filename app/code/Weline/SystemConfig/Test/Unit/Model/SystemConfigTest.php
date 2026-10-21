<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\SystemConfig\Model\SystemConfig;

final class SystemConfigTest extends TestCase
{
    private const MODULE = 'Weline_Backend';
    private const AREA = SystemConfig::area_BACKEND;
    private const SCOPE = 'default.default.default';
    private const LOCALE = 'default';

    protected function setUp(): void
    {
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        \w_cache('system_config')->clear();
        SystemConfig::clearProcessCache();
    }

    protected function tearDown(): void
    {
        SystemConfig::clearProcessCache();
        RequestContext::cleanup();
        Context::leave();
    }

    public function testCompleteModuleSnapshotIsCachedAcrossRequests(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['batchReads' => 0, 'singleReads' => 0];

        $first = $this->createConfigModel($cache, $counter, [$this->row('logo_dark', 'logo-dark.png')]);
        self::assertSame('logo-dark.png', $first->getConfig('logo_dark', self::MODULE, self::AREA, locale: self::LOCALE));
        self::assertSame(1, $counter->batchReads);

        RequestContext::cleanup();

        $second = $this->createConfigModel($cache, $counter, [$this->row('logo_dark', 'changed.png')]);
        self::assertSame('logo-dark.png', $second->getConfig('logo_dark', self::MODULE, self::AREA, locale: self::LOCALE));
        self::assertSame(1, $counter->batchReads, 'a warm module snapshot must not reload the module');
        self::assertSame(0, $counter->singleReads);
    }

    public function testMissingKeyIsNotHiddenByEmptySnapshotAcrossRequests(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['batchReads' => 0, 'singleReads' => 0];

        $first = $this->createConfigModel($cache, $counter, []);
        self::assertNull($first->getConfig('missing_key', self::MODULE, self::AREA, locale: self::LOCALE));
        self::assertSame(1, $counter->batchReads);

        RequestContext::cleanup();

        $second = $this->createConfigModel($cache, $counter, [$this->row('missing_key', 'should-not-load')]);
        self::assertSame('should-not-load', $second->getConfig('missing_key', self::MODULE, self::AREA, locale: self::LOCALE));
        self::assertSame(1, $counter->batchReads);
        self::assertSame(1, $counter->singleReads, 'empty snapshots must keep the exact-row fallback');
    }

    public function testConfigMapIsCachedAcrossRequests(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['batchReads' => 0, 'singleReads' => 0];
        $rows = [
            $this->row('logo_dark', 'logo-dark.png'),
            $this->row('site_name', 'Weline'),
        ];

        $first = $this->createConfigModel($cache, $counter, $rows);
        self::assertSame(
            ['logo_dark' => 'logo-dark.png', 'site_name' => 'Weline'],
            $first->getConfigMapByModule(self::MODULE, self::AREA, locale: self::LOCALE)
        );
        self::assertSame(1, $counter->batchReads);

        RequestContext::cleanup();

        $second = $this->createConfigModel($cache, $counter, [$this->row('logo_dark', 'changed.png')]);
        self::assertSame(
            ['logo_dark' => 'logo-dark.png', 'site_name' => 'Weline'],
            $second->getConfigMapByModule(self::MODULE, self::AREA, locale: self::LOCALE)
        );
        self::assertSame(1, $counter->batchReads, 'a warm map envelope must not reload the module');
    }

    public function testGetConfigTreatsMissingSystemConfigTableAsUnconfigured(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['batchReads' => 0, 'singleReads' => 0];
        $model = $this->createConfigModel(
            $cache,
            $counter,
            [],
            new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: w_system_config')
        );

        self::assertNull($model->getConfig('start_page_path', self::MODULE, self::AREA, locale: self::LOCALE));
        self::assertSame(1, $counter->batchReads);
        self::assertSame(0, $counter->singleReads);
    }

    public function testGetConfigMapTreatsMissingSystemConfigTableAsEmpty(): void
    {
        $cache = new SystemConfigTestCachePool('system_config');
        $counter = (object) ['batchReads' => 0, 'singleReads' => 0];
        $model = $this->createConfigModel(
            $cache,
            $counter,
            [],
            new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: w_system_config')
        );

        self::assertSame([], $model->getConfigMapByModule(self::MODULE, self::AREA, locale: self::LOCALE));
        self::assertSame(1, $counter->batchReads);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createConfigModel(
        CachePoolInterface $cache,
        object $counter,
        array $rows,
        ?\Throwable $loadException = null
    ): SystemConfig {
        return new class($cache, $counter, $rows, $loadException) extends SystemConfig {
            public function __construct(
                CachePoolInterface $cache,
                private readonly object $counter,
                private readonly array $rows,
                private readonly ?\Throwable $loadException
            ) {
                $this->_cache = $cache;
            }

            protected function loadConfigRowsByModuleScopes(
                string $module,
                string $area,
                array $scopes,
                array $locales
            ): array {
                ++$this->counter->batchReads;
                if ($this->loadException !== null) {
                    throw $this->loadException;
                }

                return array_values(array_filter(
                    $this->rows,
                    static fn(array $row): bool => \in_array($row['scope'] ?? '', $scopes, true)
                        && \in_array($row['locale'] ?? '', $locales, true)
                ));
            }

            protected function loadSingleConfigRow(
                string $key,
                string $module,
                string $area,
                string $scope,
                string $locale
            ): ?array {
                ++$this->counter->singleReads;
                if ($this->loadException !== null) {
                    throw $this->loadException;
                }
                foreach ($this->rows as $row) {
                    if (($row['key'] ?? '') === $key
                        && ($row['scope'] ?? '') === $scope
                        && ($row['locale'] ?? '') === $locale
                    ) {
                        return $row;
                    }
                }

                return null;
            }

            protected function dispatchConfigGetEvent(
                string $key,
                string $module,
                string $area,
                mixed $value,
                string $scope,
                string $locale
            ): mixed {
                return $value;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $key, mixed $value): array
    {
        return [
            SystemConfig::schema_fields_KEY => $key,
            SystemConfig::schema_fields_VALUE => $value,
            SystemConfig::schema_fields_VALUE_TYPE => SystemConfig::VALUE_TYPE_STRING,
            SystemConfig::schema_fields_MODULE => self::MODULE,
            SystemConfig::schema_fields_AREA => self::AREA,
            SystemConfig::schema_fields_SCOPE => self::SCOPE,
            SystemConfig::schema_fields_LOCALE => self::LOCALE,
            SystemConfig::schema_fields_IS_ACTIVE => 1,
            SystemConfig::schema_fields_IS_SENSITIVE => 0,
            SystemConfig::schema_fields_VERSION => 1,
            SystemConfig::schema_fields_METADATA => null,
        ];
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
        return \array_key_exists($key, $this->storage);
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

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): mixed {
        return $this->get($this->customKey($key, $website, $lang, $currency));
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->set($this->customKey($key, $website, $lang, $currency), $value, $ttl);
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->delete($this->customKey($key, $website, $lang, $currency));
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->has($this->customKey($key, $website, $lang, $currency));
    }

    private function customKey(string $key, bool $website, bool $lang, bool $currency): string
    {
        return $key . '|' . (int) $website . (int) $lang . (int) $currency;
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
}
