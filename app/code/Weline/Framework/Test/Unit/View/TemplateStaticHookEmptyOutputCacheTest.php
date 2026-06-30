<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Framework\View\Template;

final class TemplateStaticHookEmptyOutputCacheTest extends TestCase
{
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private array $outputCacheBackup = [];

    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private array $aggregateCacheBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputCacheBackup = $this->readStaticProperty('staticHookOutputCache');
        $this->aggregateCacheBackup = $this->readStaticProperty('staticHookAggregateOutputCache');
        Template::resetInstance();
    }

    protected function tearDown(): void
    {
        $this->writeStaticProperty('staticHookOutputCache', $this->outputCacheBackup);
        $this->writeStaticProperty('staticHookAggregateOutputCache', $this->aggregateCacheBackup);
        Template::resetInstance();
        parent::tearDown();
    }

    public function testReadStaticHookOutputCacheTreatsEmptyHtmlAsHit(): void
    {
        $cacheKey = 'hook.output.empty';
        $now = \microtime(true);
        $this->writeStaticProperty('staticHookOutputCache', [
            $cacheKey => [
                'fresh_until' => $now + 60,
                'stale_until' => $now + 120,
                'html' => '',
            ],
        ]);

        $result = $this->invokePrivateMethod('readStaticHookOutputCache', $cacheKey, 60);

        self::assertSame('fresh', $result['status']);
        self::assertSame('', $result['html']);
    }

    public function testReadStaticHookAggregateCacheTreatsEmptyHtmlAsHit(): void
    {
        $cacheKey = 'hook.aggregate.empty';
        $now = \microtime(true);
        $this->writeStaticProperty('staticHookAggregateOutputCache', [
            $cacheKey => [
                'fresh_until' => $now + 60,
                'stale_until' => $now + 120,
                'html' => '',
            ],
        ]);

        $result = $this->invokePrivateMethod('readStaticHookAggregateCache', $cacheKey, 60);

        self::assertSame('fresh', $result['status']);
        self::assertSame('', $result['html']);
    }

    /**
     * @return array<string, array{fresh_until: float, stale_until: float, html: string}>
     */
    private function readStaticProperty(string $name): array
    {
        $property = new ReflectionProperty(Template::class, $name);
        $property->setAccessible(true);

        /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> $value */
        $value = $property->getValue();
        return $value;
    }

    /**
     * @param array<string, array{fresh_until: float, stale_until: float, html: string}> $value
     */
    private function writeStaticProperty(string $name, array $value): void
    {
        $property = new ReflectionProperty(Template::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function invokePrivateMethod(string $method, string $cacheKey, int $ttl): array
    {
        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionMethod(Template::class, $method);
        $reflection->setAccessible(true);

        /** @var array{status: string, html: ?string} $result */
        $result = $reflection->invoke($template, $cacheKey, $ttl);
        return $result;
    }
}
