<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\View\TemplateCacheManager;
use Weline\Framework\Support\Php84;
use Weline\Server\Service\WorkerResponseMemoryGuard;
use Weline\Server\Service\MemoryCacheService;
use Weline\Theme\Block\Partials;
use Weline\Widget\Service\WidgetData;

final class WorkerResponseMemoryGuardCompactionTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkerResponseMemoryGuard::consumeDrainAfterResponseReason();
        WorkerResponseMemoryGuard::resetThresholdCache();
        $this->resetProbeCaches();
        parent::tearDown();
    }

    public function testCompactRuntimeCachesClearsRebuildableProcessCaches(): void
    {
        $this->writeStaticProperty(TemplateCacheManager::class, 'memoryCache', [
            'tpl' => ['compiled_at' => \time()],
        ]);
        $this->writeStaticProperty(WidgetData::class, 'typeValidationCache', ['card' => true]);
        $this->writeStaticProperty(WidgetData::class, 'typeWidgetsCache', ['card' => ['demo' => ['disabled' => false]]]);
        $this->writeStaticProperty(Partials::class, 'partialsMetaCache', ['frontend|head' => ['title' => 'demo']]);
        $this->writeStaticProperty(Php84::class, 'lazyObjectCache', ['lazy' => new \stdClass()]);
        $this->writeStaticProperty(MemoryCacheService::class, 'cache', [
            'resp' => ['response' => 'demo', 'headers' => [], 'created_at' => \time(), 'ttl' => 300, 'last_access' => \time()],
        ]);
        $this->writeStaticProperty(MemoryCacheService::class, 'metadata', [
            'resp' => ['tags' => ['demo'], 'host' => 'localhost', 'url' => '/demo'],
        ]);
        $this->writeStaticProperty(MemoryCacheService::class, 'tagIndex', ['demo' => ['resp']]);
        $this->writeStaticProperty(MemoryCacheService::class, 'hostIndex', ['localhost' => ['resp']]);
        $this->writeStaticProperty(MemoryCacheService::class, 'stats', [
            'hits' => 1,
            'misses' => 0,
            'sets' => 1,
            'purges' => 0,
            'size' => 4,
            'evictions' => 0,
            'emergency_cleanups' => 0,
        ]);

        $result = WorkerResponseMemoryGuard::compactRuntimeCaches(true);

        self::assertSame([], $this->readStaticProperty(TemplateCacheManager::class, 'memoryCache'));
        self::assertSame([], $this->readStaticProperty(WidgetData::class, 'typeValidationCache'));
        self::assertSame([], $this->readStaticProperty(WidgetData::class, 'typeWidgetsCache'));
        self::assertSame([], $this->readStaticProperty(Partials::class, 'partialsMetaCache'));
        self::assertSame([], $this->readStaticProperty(Php84::class, 'lazyObjectCache'));
        self::assertSame([], $this->readStaticProperty(MemoryCacheService::class, 'cache'));
        self::assertSame([], $this->readStaticProperty(MemoryCacheService::class, 'metadata'));
        self::assertSame([], $this->readStaticProperty(MemoryCacheService::class, 'tagIndex'));
        self::assertSame([], $this->readStaticProperty(MemoryCacheService::class, 'hostIndex'));
        self::assertGreaterThanOrEqual(5, $result['cleared_process_caches']);
    }

    public function testCompactRuntimeCachesSoftModeLeavesAggressiveCachesAlone(): void
    {
        $this->seedProbeCaches();

        $result = WorkerResponseMemoryGuard::compactRuntimeCaches(false);

        self::assertSame([], $this->readStaticProperty(TemplateCacheManager::class, 'memoryCache'));
        self::assertSame([], $this->readStaticProperty(WidgetData::class, 'typeValidationCache'));
        self::assertSame([], $this->readStaticProperty(WidgetData::class, 'typeWidgetsCache'));
        self::assertSame([], $this->readStaticProperty(Partials::class, 'partialsMetaCache'));
        self::assertNotSame([], $this->readStaticProperty(Php84::class, 'lazyObjectCache'));
        self::assertNotSame([], $this->readStaticProperty(MemoryCacheService::class, 'cache'));
        self::assertGreaterThanOrEqual(3, $result['cleared_process_caches']);
    }

    public function testCompactUsesSoftThresholdForRebuildableCachesOnly(): void
    {
        $this->seedProbeCaches();
        $this->writeStaticProperty(WorkerResponseMemoryGuard::class, 'runtimeCacheThresholds', [
            'soft' => 0.70,
            'hard' => 0.85,
        ]);

        $result = $this->withMemoryPressure(0.78, static fn (): array => WorkerResponseMemoryGuard::compact());

        self::assertSame([], $this->readStaticProperty(TemplateCacheManager::class, 'memoryCache'));
        self::assertSame([], $this->readStaticProperty(WidgetData::class, 'typeValidationCache'));
        self::assertSame([], $this->readStaticProperty(WidgetData::class, 'typeWidgetsCache'));
        self::assertSame([], $this->readStaticProperty(Partials::class, 'partialsMetaCache'));
        self::assertNotSame([], $this->readStaticProperty(Php84::class, 'lazyObjectCache'));
        self::assertNotSame([], $this->readStaticProperty(MemoryCacheService::class, 'cache'));
        self::assertGreaterThanOrEqual(
            3,
            $result['runtime_cache_compactions']['cleared_process_caches']
        );
    }

    public function testCompactUsesHardThresholdForAggressiveCaches(): void
    {
        $this->seedProbeCaches();
        $this->writeStaticProperty(WorkerResponseMemoryGuard::class, 'runtimeCacheThresholds', [
            'soft' => 0.70,
            'hard' => 0.85,
        ]);

        $result = $this->withMemoryPressure(0.86, static fn (): array => WorkerResponseMemoryGuard::compact());

        self::assertSame([], $this->readStaticProperty(TemplateCacheManager::class, 'memoryCache'));
        self::assertSame([], $this->readStaticProperty(WidgetData::class, 'typeValidationCache'));
        self::assertSame([], $this->readStaticProperty(WidgetData::class, 'typeWidgetsCache'));
        self::assertSame([], $this->readStaticProperty(Partials::class, 'partialsMetaCache'));
        self::assertSame([], $this->readStaticProperty(Php84::class, 'lazyObjectCache'));
        self::assertSame([], $this->readStaticProperty(MemoryCacheService::class, 'cache'));
        self::assertSame([], $this->readStaticProperty(MemoryCacheService::class, 'metadata'));
        self::assertSame([], $this->readStaticProperty(MemoryCacheService::class, 'tagIndex'));
        self::assertSame([], $this->readStaticProperty(MemoryCacheService::class, 'hostIndex'));
        self::assertGreaterThanOrEqual(
            5,
            $result['runtime_cache_compactions']['cleared_process_caches']
        );
    }

    public function testCompactIfPressureSkipsBelowThreshold(): void
    {
        $result = $this->withMemoryPressure(
            0.60,
            static fn (): ?array => WorkerResponseMemoryGuard::compactIfPressure(0.70)
        );

        self::assertNull($result);
    }

    public function testCompactIfPressureCompactsAtThreshold(): void
    {
        $this->seedProbeCaches();
        $this->writeStaticProperty(WorkerResponseMemoryGuard::class, 'runtimeCacheThresholds', [
            'soft' => 0.70,
            'hard' => 0.85,
        ]);

        $result = $this->withMemoryPressure(
            0.78,
            static fn (): ?array => WorkerResponseMemoryGuard::compactIfPressure(0.70)
        );

        self::assertIsArray($result);
        self::assertSame([], $this->readStaticProperty(TemplateCacheManager::class, 'memoryCache'));
        self::assertGreaterThanOrEqual(
            3,
            $result['runtime_cache_compactions']['cleared_process_caches']
        );
    }

    public function testDrainAfterResponseReasonIsOneShot(): void
    {
        WorkerResponseMemoryGuard::requestDrainAfterResponse('fiber_output_buffer_overflow');

        self::assertSame('fiber_output_buffer_overflow', WorkerResponseMemoryGuard::consumeDrainAfterResponseReason());
        self::assertNull(WorkerResponseMemoryGuard::consumeDrainAfterResponseReason());
    }

    private function readStaticProperty(string $class, string $property): mixed
    {
        $reflection = new \ReflectionProperty($class, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue();
    }

    private function writeStaticProperty(string $class, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue(null, $value);
    }

    private function seedProbeCaches(): void
    {
        $this->writeStaticProperty(TemplateCacheManager::class, 'memoryCache', [
            'tpl' => ['compiled_at' => \time()],
        ]);
        $this->writeStaticProperty(WidgetData::class, 'typeValidationCache', ['card' => true]);
        $this->writeStaticProperty(WidgetData::class, 'typeWidgetsCache', ['card' => ['demo' => ['disabled' => false]]]);
        $this->writeStaticProperty(Partials::class, 'partialsMetaCache', ['frontend|head' => ['title' => 'demo']]);
        $this->writeStaticProperty(Php84::class, 'lazyObjectCache', ['lazy' => new \stdClass()]);
        $this->writeStaticProperty(MemoryCacheService::class, 'cache', [
            'resp' => ['response' => 'demo', 'headers' => [], 'created_at' => \time(), 'ttl' => 300, 'last_access' => \time()],
        ]);
        $this->writeStaticProperty(MemoryCacheService::class, 'metadata', [
            'resp' => ['tags' => ['demo'], 'host' => 'localhost', 'url' => '/demo'],
        ]);
        $this->writeStaticProperty(MemoryCacheService::class, 'tagIndex', ['demo' => ['resp']]);
        $this->writeStaticProperty(MemoryCacheService::class, 'hostIndex', ['localhost' => ['resp']]);
        $this->writeStaticProperty(MemoryCacheService::class, 'stats', [
            'hits' => 1,
            'misses' => 0,
            'sets' => 1,
            'purges' => 0,
            'size' => 4,
            'evictions' => 0,
            'emergency_cleanups' => 0,
        ]);
    }

    private function resetProbeCaches(): void
    {
        $this->writeStaticProperty(TemplateCacheManager::class, 'memoryCache', []);
        $this->writeStaticProperty(WidgetData::class, 'typeValidationCache', []);
        $this->writeStaticProperty(WidgetData::class, 'typeWidgetsCache', []);
        $this->writeStaticProperty(Partials::class, 'partialsMetaCache', []);
        $this->writeStaticProperty(Php84::class, 'lazyObjectCache', []);
        $this->writeStaticProperty(MemoryCacheService::class, 'cache', []);
        $this->writeStaticProperty(MemoryCacheService::class, 'metadata', []);
        $this->writeStaticProperty(MemoryCacheService::class, 'tagIndex', []);
        $this->writeStaticProperty(MemoryCacheService::class, 'hostIndex', []);
        $this->writeStaticProperty(MemoryCacheService::class, 'stats', [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'purges' => 0,
            'size' => 0,
            'evictions' => 0,
            'emergency_cleanups' => 0,
        ]);
    }

    private function withMemoryPressure(float $pressure, callable $callback): mixed
    {
        $previousLimit = \ini_get('memory_limit');
        $usage = \memory_get_usage(true);
        $targetLimit = \max(
            (int) \ceil($usage / $pressure),
            $usage + 1024 * 1024
        );

        if (@\ini_set('memory_limit', (string)$targetLimit) === false) {
            self::markTestSkipped('Unable to lower memory_limit for memory pressure test.');
        }

        try {
            return $callback();
        } finally {
            if ($previousLimit !== false) {
                @\ini_set('memory_limit', (string)$previousLimit);
            }
        }
    }
}
