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
}
