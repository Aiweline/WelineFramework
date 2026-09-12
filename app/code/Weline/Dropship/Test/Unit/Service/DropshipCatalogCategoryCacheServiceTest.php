<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Interface\DropshipCatalogBrowseProviderInterface;
use Weline\Dropship\Service\DropshipCatalogCategoryCacheService;
use Weline\Framework\Cache\Contract\CachePoolInterface;

final class DropshipCatalogCategoryCacheServiceTest extends TestCase
{
    public function testCacheKeyStableByProviderLocaleCountry(): void
    {
        $a = DropshipCatalogCategoryCacheService::cacheKey('CJ', 'zh-Hans-CN', 'us', true);
        $b = DropshipCatalogCategoryCacheService::cacheKey('cj', 'zh_Hans_CN', 'US', true);
        self::assertSame($a, $b);
        self::assertStringContainsString('cats:cj:zh_Hans_CN:US', $a);

        $noCountry = DropshipCatalogCategoryCacheService::cacheKey('fake', 'en_US', 'US', false);
        self::assertStringEndsWith(':_', $noCountry);
    }

    public function testSecondCallHitsCacheWithoutSecondRemote(): void
    {
        $remoteCalls = 0;
        $provider = new class($remoteCalls) implements DropshipCatalogBrowseProviderInterface {
            public function __construct(private int &$calls)
            {
            }

            public function getCode(): string
            {
                return 'fake';
            }

            public function getCapabilities(): array
            {
                return ['browse' => true];
            }

            public function getDisplayMetadata(): array
            {
                return ['title' => 'Fake'];
            }

            public function getConfigSchema(): array
            {
                return [];
            }

            public function probeConnection(array $context = []): array
            {
                return ['ok' => true];
            }

            public function searchProducts(array $query): array
            {
                return [];
            }

            public function getProduct(array $identity): ?DropshipCatalogSnapshot
            {
                return null;
            }

            public function syncCatalogSnapshot(array $listingRow): ?DropshipCatalogSnapshot
            {
                return null;
            }

            public function listCategories(array $query = []): array
            {
                $this->calls++;

                return [
                    ['id' => 'c1', 'name' => 'Cat', 'parent_id' => '', 'level' => 1, 'path' => 'Cat'],
                ];
            }
        };

        $svc = new DropshipCatalogCategoryCacheService($this->arrayPool());
        $q = ['locale' => 'zh_Hans_CN', 'country_code' => 'US'];
        $first = $svc->getCategories($provider, 'fake', $q, true, false);
        $second = $svc->getCategories($provider, 'fake', $q, true, false);
        $forced = $svc->getCategories($provider, 'fake', $q, true, true);

        self::assertSame('miss', $first['cache']);
        self::assertSame('hit', $second['cache']);
        self::assertSame('bypass', $forced['cache']);
        self::assertCount(1, $first['categories']);
        self::assertSame($first['categories'], $second['categories']);
        self::assertSame(2, $remoteCalls);
    }

    private function arrayPool(): CachePoolInterface
    {
        return new class implements CachePoolInterface {
            /** @var array<string, mixed> */
            private array $data = [];

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? false;
            }

            public function set(string $key, mixed $value, int $ttl = 0): bool
            {
                $this->data[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->data[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->data = [];

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }

            public function getIdentity(): string
            {
                return 'test';
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
                $out = [];
                foreach ($keys as $k) {
                    $out[$k] = $this->get((string)$k);
                }

                return $out;
            }

            public function setMultiple(array $values, int $ttl = 0): bool
            {
                foreach ($values as $k => $v) {
                    $this->set((string)$k, $v, $ttl);
                }

                return true;
            }

            public function deleteMultiple(array $keys): bool
            {
                foreach ($keys as $k) {
                    $this->delete((string)$k);
                }

                return true;
            }

            public function getStats(): array
            {
                return ['identity' => 'test', 'hits' => 0, 'misses' => 0, 'hit_ratio' => 0.0, 'permanent' => false];
            }

            public function getCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): mixed
            {
                return $this->get($key);
            }

            public function setCustom(string $key, mixed $value, int $ttl = 0, bool $website = false, bool $lang = false, bool $currency = false): bool
            {
                return $this->set($key, $value, $ttl);
            }

            public function deleteCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): bool
            {
                return $this->delete($key);
            }

            public function hasCustom(string $key, bool $website = false, bool $lang = false, bool $currency = false): bool
            {
                return $this->has($key);
            }
        };
    }
}
