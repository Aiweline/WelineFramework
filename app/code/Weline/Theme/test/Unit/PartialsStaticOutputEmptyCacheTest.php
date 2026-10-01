<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Theme\Block\Partials;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Theme/Block/Partials.php';

final class PartialsStaticOutputEmptyCacheTest extends TestCase
{
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private array $outputCacheBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputCacheBackup = $this->readStaticProperty('partialOutputCache');
        Partials::clearOutputCache();
    }

    protected function tearDown(): void
    {
        $this->writeStaticProperty('partialOutputCache', $this->outputCacheBackup);
        $bytes = new ReflectionProperty(Partials::class, 'partialOutputCacheBytes');
        $bytes->setAccessible(true);
        $bytes->setValue(null, 0);
        foreach ($this->outputCacheBackup as $entry) {
            if (\is_array($entry)) {
                $bytes->setValue(null, (int)$bytes->getValue() + \strlen((string)($entry['html'] ?? '')));
            }
        }
        parent::tearDown();
    }

    public function testRememberPartialOutputEvictsByByteBudget(): void
    {
        Partials::clearOutputCache();
        $partials = (new \ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $remember = new ReflectionMethod(Partials::class, 'rememberPartialOutput');
        $remember->setAccessible(true);

        // 12 × 200KB = 2.4MB > 2MB budget → must stay ≤ MAX_BYTES.
        $chunk = \str_repeat('a', 200_000);
        for ($i = 0; $i < 12; $i++) {
            $remember->invoke($partials, 'partial.output.budget.' . $i, $chunk, 'fresh', 60);
        }

        $cache = $this->readStaticProperty('partialOutputCache');
        self::assertLessThanOrEqual(48, \count($cache));
        self::assertLessThanOrEqual(2_097_152, Partials::processPartialOutputCacheBytes());
        self::assertGreaterThan(0, \count($cache));
    }

    public function testRememberPartialOutputSkipsOversizedEntry(): void
    {
        Partials::clearOutputCache();
        $partials = (new \ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $remember = new ReflectionMethod(Partials::class, 'rememberPartialOutput');
        $remember->setAccessible(true);
        $remember->invoke($partials, 'partial.output.huge', \str_repeat('b', 300_000), 'fresh', 60);

        self::assertSame([], $this->readStaticProperty('partialOutputCache'));
        self::assertSame(0, Partials::processPartialOutputCacheBytes());
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

    public function testClearAllCachesContractClearsStaticPartialOutputCache(): void
    {
        $cacheKey = 'partial.output.clear-all';
        $now = \microtime(true);
        $this->writeStaticProperty('partialOutputCache', [
            $cacheKey => [
                'fresh_until' => $now + 60,
                'stale_until' => $now + 120,
                'html' => '<header>cached</header>',
            ],
        ]);

        Partials::clearAllCaches();

        self::assertSame([], $this->readStaticProperty('partialOutputCache'));
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
