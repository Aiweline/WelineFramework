<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\WhiteAclSource;
use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * @runInSeparateProcess
 */
final class MenuUrlValidatorTest extends TestCase
{
    private array $originalInstances = [];

    protected function setUp(): void
    {
        parent::setUp();

        $instances = ObjectManager::getInstances();
        foreach ([CacheManager::class, WhiteAclSource::class] as $class) {
            if (isset($instances[$class])) {
                $this->originalInstances[$class] = $instances[$class];
            }
        }

        $cachePool = new class() implements CachePoolInterface {
            private array $data = [];
            private int $hits = 0;
            private int $misses = 0;

            public function get(string $key): mixed
            {
                if (!array_key_exists($key, $this->data)) {
                    $this->misses++;
                    return false;
                }

                $this->hits++;
                return $this->data[$key];
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
                return 'default';
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
                $total = $this->hits + $this->misses;

                return [
                    'identity' => 'default',
                    'hits' => $this->hits,
                    'misses' => $this->misses,
                    'hit_ratio' => $total === 0 ? 0.0 : $this->hits / $total,
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
        };

        $cacheManager = new class($cachePool) extends CacheManager {
            public function __construct(private readonly CachePoolInterface $cachePool)
            {
            }

            public function pool(string $identity): CachePoolInterface
            {
                return $this->cachePool;
            }

            public function hasPool(string $identity): bool
            {
                return true;
            }

            public function getPoolIdentities(): array
            {
                return ['default'];
            }

            public function invalidateTag(string $tag): void
            {
            }

            public function clearAll(): void
            {
                $this->cachePool->clear();
            }

            public function flushAll(): void
            {
                $this->cachePool->clear();
            }

            public function getAllStats(): array
            {
                return ['default' => $this->cachePool->getStats()];
            }
        };

        ObjectManager::setInstance(CacheManager::class, $cacheManager);
        MenuUrlValidator::clearCache();
    }

    protected function tearDown(): void
    {
        MenuUrlValidator::clearCache();

        foreach ([WhiteAclSource::class, CacheManager::class] as $class) {
            if (isset($this->originalInstances[$class])) {
                ObjectManager::setInstance($class, $this->originalInstances[$class]);
                continue;
            }

            ObjectManager::removeInstance($class);
        }

        parent::tearDown();
    }

    public function testGetWhitelistPathsUsesIteratorForUnboundedSelect(): void
    {
        $whiteAclSource = new class([
            ['path' => 'admin/login'],
            ['path' => 'admin/captcha'],
        ]) extends WhiteAclSource {
            public function __construct(private readonly array $rows)
            {
            }

            public function fields(...$args): static
            {
                return $this;
            }

            public function where(...$args): static
            {
                return $this;
            }

            public function select(): static
            {
                return $this;
            }

            public function fetchArray(): array
            {
                throw new \RuntimeException('fetchArray should not be used for whitelist loading.');
            }

            public function fetchIterator(string $model_class = '', int $batchSize = 1): \Generator
            {
                foreach ($this->rows as $row) {
                    yield $row;
                }
            }
        };

        ObjectManager::setInstance(WhiteAclSource::class, $whiteAclSource);

        self::assertSame(['admin/login', 'admin/captcha'], MenuUrlValidator::getWhitelistPaths());
        self::assertSame(['admin/login', 'admin/captcha'], MenuUrlValidator::getWhitelistPaths());
    }
}
