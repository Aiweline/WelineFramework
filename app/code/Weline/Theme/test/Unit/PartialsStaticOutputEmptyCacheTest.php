<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Theme\Block\Partials;

final class PartialsStaticOutputEmptyCacheTest extends TestCase
{
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private array $outputCacheBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputCacheBackup = $this->readStaticProperty('partialOutputCache');
    }

    protected function tearDown(): void
    {
        $this->writeStaticProperty('partialOutputCache', $this->outputCacheBackup);
        parent::tearDown();
    }

    public function testReadPartialOutputCacheTreatsEmptyHtmlAsHit(): void
    {
        $cacheKey = 'partial.output.empty';
        $now = \microtime(true);
        $this->writeStaticProperty('partialOutputCache', [
            $cacheKey => [
                'fresh_until' => $now + 60,
                'stale_until' => $now + 120,
                'html' => '',
            ],
        ]);

        $result = $this->invokePrivateMethod('readPartialOutputCache', $cacheKey);

        self::assertSame('fresh', $result['status']);
        self::assertSame('', $result['html']);
    }

    /**
     * @return array<string, array{fresh_until: float, stale_until: float, html: string}>
     */
    private function readStaticProperty(string $name): array
    {
        $property = new ReflectionProperty(Partials::class, $name);
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
        $property = new ReflectionProperty(Partials::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    /**
     * @return array{status: string, html: ?string}
     */
    private function invokePrivateMethod(string $method, string $cacheKey): array
    {
        $partials = (new \ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionMethod(Partials::class, $method);
        $reflection->setAccessible(true);

        /** @var array{status: string, html: ?string} $result */
        $result = $reflection->invoke($partials, $cacheKey);
        return $result;
    }
}
