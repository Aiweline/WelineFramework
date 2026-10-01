<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Theme\Api\Runtime\ProcessCacheResetter;
use Weline\Theme\Block\Partials;

final class ProcessCacheResetterMemoryPressureTest extends TestCase
{
    protected function tearDown(): void
    {
        Partials::clearAllCaches();
        StorefrontScopeHotCache::resetProcessCache();
        parent::tearDown();
    }

    public function testSoftPressureClearsPartialOutputAndTrimsHotCache(): void
    {
        $this->seedPartialOutput();
        $this->seedHotCacheEntries(8);

        $cleared = (new ProcessCacheResetter())->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false),
        );

        self::assertSame(3, $cleared);
        self::assertSame(0, $this->partialOutputCount());
    }

    public function testHardPressureResetsHotCacheProcessBag(): void
    {
        $this->seedPartialOutput();
        $this->seedHotCacheEntries(4);

        $cleared = (new ProcessCacheResetter())->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, true),
        );

        self::assertSame(7, $cleared);
        self::assertSame(0, $this->partialOutputCount());

        $rp = new \ReflectionProperty(StorefrontScopeHotCache::class, 'processCache');
        $rp->setAccessible(true);
        self::assertSame([], $rp->getValue());
    }

    private function seedPartialOutput(): void
    {
        $rp = new \ReflectionProperty(Partials::class, 'partialOutputCache');
        $rp->setAccessible(true);
        $rp->setValue(null, [
            'k1' => ['html' => \str_repeat('h', 1024), 'fresh_until' => \microtime(true) + 60, 'stale_until' => \microtime(true) + 120],
        ]);
    }

    private function partialOutputCount(): int
    {
        $rp = new \ReflectionProperty(Partials::class, 'partialOutputCache');
        $rp->setAccessible(true);
        $cache = $rp->getValue();

        return \is_array($cache) ? \count($cache) : 0;
    }

    private function seedHotCacheEntries(int $n): void
    {
        $adapter = new class implements \Weline\Framework\Cache\Contract\CacheAdapterInterface {
            /** @var array<string, mixed> */
            private array $data = [];
            public function get(string $key): mixed { return $this->data[$key] ?? false; }
            public function set(string $key, mixed $value, int $ttl = 0): bool { $this->data[$key] = $value; return true; }
            public function delete(string $key): bool { unset($this->data[$key]); return true; }
            public function clear(): bool { $this->data = []; return true; }
            public function has(string $key): bool { return \array_key_exists($key, $this->data); }
        };
        $pool = new \Weline\Framework\Cache\Pool\CachePool('unit_theme_pressure', $adapter, jitterRatio: 0.0);
        $manager = $this->createMock(\Weline\Framework\Cache\CacheManager::class);
        $manager->method('pool')->willReturn($pool);
        $service = new StorefrontScopeHotCache($manager);
        for ($i = 0; $i < $n; $i++) {
            $service->remember('unit_theme_pressure', 'k.' . $i, 60, static fn (): string => 'v' . $i, []);
        }
    }
}
