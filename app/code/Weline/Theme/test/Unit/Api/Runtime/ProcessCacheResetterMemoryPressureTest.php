<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\StorefrontProcessL1HydrateCoordinator;
use Weline\Theme\Api\Runtime\ProcessCacheResetter;
use Weline\Theme\Block\Partials;

final class ProcessCacheResetterMemoryPressureTest extends TestCase
{
    protected function tearDown(): void
    {
        Partials::clearAllCaches();
        StorefrontScopeHotCache::resetProcessCache();
        StorefrontProcessL1HydrateCoordinator::resetForTests();
        \Weline\Framework\View\Cache\TemplateFragmentOutputCache::resetProcessCaches();
        parent::tearDown();
    }

    public function testSoftPressureTrimsWithoutWipeAndQueuesHydrateOnlyWhenFreed(): void
    {
        $source = (string)\file_get_contents(
            dirname(__DIR__, 4) . '/Api/Runtime/ProcessCacheResetter.php'
        );
        $softBody = $this->extractSoftBranch($source);
        self::assertStringNotContainsString('Partials::clearOutputCache()', $softBody);
        self::assertStringContainsString('trimOutputCacheToBudget', $softBody);
        self::assertStringContainsString('clearMetaCache()', $softBody);

        // Under budget: soft must keep partial HTML and not queue Layer B.
        $this->seedPartialOutput(1024);
        StorefrontProcessL1HydrateCoordinator::resetForTests();
        $cleared = (new ProcessCacheResetter())->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false),
        );
        self::assertSame(4, $cleared);
        self::assertSame(1, $this->partialOutputCount());
        self::assertFalse(StorefrontProcessL1HydrateCoordinator::hasPendingRequest());

        // Over budget: trim frees bytes and queues soft_reclaim.
        Partials::clearAllCaches();
        $this->seedPartialOutput(600_000);
        $this->seedPartialOutputExtra(600_000);
        StorefrontProcessL1HydrateCoordinator::resetForTests();
        (new ProcessCacheResetter())->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false),
        );
        self::assertTrue(
            StorefrontProcessL1HydrateCoordinator::hasPendingRequest()
            || Partials::processPartialOutputCacheBytes() <= 1_048_576,
        );
    }

    public function testHardPressureResetsHotCacheProcessBag(): void
    {
        $this->seedPartialOutput(1024);
        $this->seedHotCacheEntries(4);

        $cleared = (new ProcessCacheResetter())->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, true),
        );

        self::assertSame(9, $cleared);
        self::assertSame(0, $this->partialOutputCount());

        $rp = new \ReflectionProperty(StorefrontScopeHotCache::class, 'processCache');
        $rp->setAccessible(true);
        self::assertSame([], $rp->getValue());
    }

    public function testSoftPressureTrimsFragmentsInsteadOfWiping(): void
    {
        $source = (string)\file_get_contents(
            dirname(__DIR__, 4) . '/Api/Runtime/ProcessCacheResetter.php'
        );
        $softBody = $this->extractSoftBranch($source);
        self::assertStringContainsString('TemplateFragmentOutputCache::trimToBudget', $softBody);
        self::assertStringNotContainsString('TemplateFragmentOutputCache::resetProcessCaches', $softBody);

        // A typical chrome widget set (~11 fragments) must survive soft pressure.
        for ($i = 0; $i < 11; $i++) {
            $this->seedFragment('w' . $i, 4096);
        }
        (new ProcessCacheResetter())->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false),
        );
        self::assertSame(11, \Weline\Framework\View\Cache\TemplateFragmentOutputCache::itemCount());
    }

    public function testHardPressureTrimsFragmentsToFloorNotEmpty(): void
    {
        // Realistic chrome set: 11 widgets × ~6KB; the 64KB floor retains most of it.
        // The oversize entry is seeded first (coldest) so the trim drops it, not the widgets.
        $this->seedFragment('fat_extra', 300_000);
        for ($i = 0; $i < 11; $i++) {
            $this->seedFragment('w' . $i, 6144);
        }
        (new ProcessCacheResetter())->resetProcessCaches(
            new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, true),
        );
        self::assertLessThanOrEqual(
            65_536,
            \Weline\Framework\View\Cache\TemplateFragmentOutputCache::estimateBytes(),
        );
        self::assertGreaterThan(
            0,
            \Weline\Framework\View\Cache\TemplateFragmentOutputCache::itemCount(),
        );
    }

    public function testFragmentTrimToBudgetDropsColdestFirst(): void
    {
        $this->seedFragment('oldest', 100_000);
        $this->seedFragment('middle', 100_000);
        $this->seedFragment('newest', 100_000);
        $freed = \Weline\Framework\View\Cache\TemplateFragmentOutputCache::trimToBudget(210_000);
        self::assertGreaterThan(0, $freed);
        self::assertSame(
            ['middle', 'newest'],
            \array_keys($this->fragmentBag()),
        );
    }

    public function testTrimOutputCacheToBudgetFreesOldest(): void
    {
        Partials::clearAllCaches();
        $this->seedPartialOutput(800_000);
        $this->seedPartialOutputExtra(800_000);
        $freed = Partials::trimOutputCacheToBudget(500_000);
        self::assertGreaterThan(0, $freed);
        self::assertLessThanOrEqual(500_000, Partials::processPartialOutputCacheBytes());
    }

    private function extractSoftBranch(string $source): string
    {
        $pos = \strpos($source, '// Soft pressure:');
        self::assertNotFalse($pos);
        $end = \strpos($source, 'return 4;', $pos);
        self::assertNotFalse($end);

        return \substr($source, $pos, $end - $pos);
    }

    private function seedPartialOutput(int $htmlBytes): void
    {
        $rp = new \ReflectionProperty(Partials::class, 'partialOutputCache');
        $rp->setAccessible(true);
        $bytes = new \ReflectionProperty(Partials::class, 'partialOutputCacheBytes');
        $bytes->setAccessible(true);
        $html = \str_repeat('h', \max(1, $htmlBytes));
        $entry = [
            'html' => $html,
            'fresh_until' => \microtime(true) + 60,
            'stale_until' => \microtime(true) + 120,
        ];
        $rp->setValue(null, ['k1' => $entry]);
        $bytes->setValue(null, \strlen($html));
    }

    private function seedPartialOutputExtra(int $htmlBytes): void
    {
        $rp = new \ReflectionProperty(Partials::class, 'partialOutputCache');
        $rp->setAccessible(true);
        $bytes = new \ReflectionProperty(Partials::class, 'partialOutputCacheBytes');
        $bytes->setAccessible(true);
        $cache = $rp->getValue();
        if (!\is_array($cache)) {
            $cache = [];
        }
        $html = \str_repeat('x', \max(1, $htmlBytes));
        $cache['k2'] = [
            'html' => $html,
            'fresh_until' => \microtime(true) + 60,
            'stale_until' => \microtime(true) + 120,
        ];
        $rp->setValue(null, $cache);
        $bytes->setValue(null, (int)$bytes->getValue() + \strlen($html));
    }

    private function partialOutputCount(): int
    {
        $rp = new \ReflectionProperty(Partials::class, 'partialOutputCache');
        $rp->setAccessible(true);
        $cache = $rp->getValue();

        return \is_array($cache) ? \count($cache) : 0;
    }

    private function seedFragment(string $key, int $htmlBytes): void
    {
        $rp = new \ReflectionProperty(\Weline\Framework\View\Cache\TemplateFragmentOutputCache::class, 'bag');
        $rp->setAccessible(true);
        $bag = $rp->getValue();
        if (!\is_array($bag)) {
            $bag = [];
        }
        $bag[$key] = [
            'html' => \str_repeat('f', \max(1, $htmlBytes)),
            'fresh_until' => \microtime(true) + 60,
            'stale_until' => \microtime(true) + 120,
        ];
        $rp->setValue(null, $bag);
    }

    /** @return array<string, mixed> */
    private function fragmentBag(): array
    {
        $rp = new \ReflectionProperty(\Weline\Framework\View\Cache\TemplateFragmentOutputCache::class, 'bag');
        $rp->setAccessible(true);
        $bag = $rp->getValue();

        return \is_array($bag) ? $bag : [];
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
