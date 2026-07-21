<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Theme\Block\Partials;
use Weline\Theme\Helper\ComponentMetaParser;

final class PartialsChromeCachePolicyTest extends TestCase
{
    /** @var array<string, array{fresh_until: float, stale_until: float, html: string}> */
    private array $outputCacheBackup = [];

    /** @var array<string, array{mode: string, auth: string, ttl: int}> */
    private array $policyCacheBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputCacheBackup = $this->readStaticProperty('partialOutputCache');
        $this->policyCacheBackup = $this->readStaticProperty('chromePolicyCache');
        $this->writeStaticProperty('partialOutputCache', []);
        $this->writeStaticProperty('chromePolicyCache', []);
    }

    protected function tearDown(): void
    {
        $this->writeStaticProperty('partialOutputCache', $this->outputCacheBackup);
        $this->writeStaticProperty('chromePolicyCache', $this->policyCacheBackup);
        parent::tearDown();
    }

    public function testComponentMetaParserReadsNestedCacheMeta(): void
    {
        $file = BP . 'app/code/Weline/Theme/view/theme/backend/partials/sidebar/left.phtml';
        self::assertFileExists($file);

        $parsed = ComponentMetaParser::parse($file);
        $cache = $parsed['meta']['cache'] ?? [];

        self::assertIsArray($cache);
        self::assertSame('chrome', (string)($cache['mode']['default'] ?? ''));
        self::assertSame('role', (string)($cache['auth']['default'] ?? ''));
    }

    public function testTopbarChromeAuthDefaultsToUser(): void
    {
        $file = BP . 'app/code/Weline/Theme/view/theme/backend/partials/topbar/default.phtml';
        $parsed = ComponentMetaParser::parse($file);
        $cache = $parsed['meta']['cache'] ?? [];

        self::assertSame('chrome', (string)($cache['mode']['default'] ?? ''));
        self::assertSame('user', (string)($cache['auth']['default'] ?? ''));
    }

    public function testResolveChromeCachePolicyFromModulePath(): void
    {
        $partials = (new \ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Partials::class, 'resolveChromeCachePolicy');
        $method->setAccessible(true);

        $policy = $method->invoke(
            $partials,
            'Weline_Theme::theme/backend/partials/loading/default.phtml',
            []
        );

        self::assertIsArray($policy);
        self::assertSame('chrome', $policy['mode']);
        self::assertSame('role', $policy['auth']);
        self::assertGreaterThan(0, (int)$policy['ttl']);
    }

    public function testRememberPartialOutputEvictsOldestWhenFull(): void
    {
        $partials = (new \ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $remember = new ReflectionMethod(Partials::class, 'rememberPartialOutput');
        $remember->setAccessible(true);

        $maxProp = new ReflectionClass(Partials::class);
        $max = (int)$maxProp->getConstant('PARTIAL_OUTPUT_CACHE_MAX');
        for ($i = 0; $i < $max; $i++) {
            $remember->invoke($partials, 'key-' . $i, 'html-' . $i, 'fresh', 60);
        }

        $cache = $this->readStaticProperty('partialOutputCache');
        self::assertCount($max, $cache);
        self::assertArrayHasKey('key-0', $cache);

        $remember->invoke($partials, 'key-new', 'html-new', 'fresh', 60);
        $cache = $this->readStaticProperty('partialOutputCache');
        self::assertCount($max, $cache);
        self::assertArrayNotHasKey('key-0', $cache);
        self::assertArrayHasKey('key-new', $cache);
    }

    /**
     * @return array<string, mixed>
     */
    private function readStaticProperty(string $name): array
    {
        $property = new ReflectionProperty(Partials::class, $name);
        $property->setAccessible(true);
        /** @var array<string, mixed> $value */
        $value = $property->getValue();
        return $value;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function writeStaticProperty(string $name, array $value): void
    {
        $property = new ReflectionProperty(Partials::class, $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}
