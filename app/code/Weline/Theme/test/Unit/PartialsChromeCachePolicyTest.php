<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Weline\Theme\Block\Partials;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Observer\ControllerFetchFileBefore;

\defined('BP') || \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);

require_once BP . 'app/autoload.php';
require_once BP . 'app/code/Weline/Theme/Block/Partials.php';

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
        self::assertSame('user', (string)($cache['auth']['default'] ?? ''));
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

    public function testExplicitOffPolicyIsNeverPromotedByAChromeTypeFallback(): void
    {
        $partials = (new \ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Partials::class, 'resolveChromeCachePolicy');
        $method->setAccessible(true);

        self::assertNull($method->invoke(
            $partials,
            'unit-test-explicit-off.phtml',
            ['cache' => ['mode' => ['default' => 'off']]],
            'head',
        ));
    }

    public function testBackendHeadCacheIdentityRetainsPageAndLayoutData(): void
    {
        $partials = (new \ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Partials::class, 'resolveChromePartialCacheDataContext');
        $method->setAccessible(true);

        $first = $method->invoke($partials, 'backend', 'head', [
            'meta' => ['title' => 'Orders'],
            'layout' => ['type' => 'admin', 'option' => 'wide'],
        ]);
        $second = $method->invoke($partials, 'backend', 'head', [
            'meta' => ['title' => 'Customers'],
            'layout' => ['type' => 'admin', 'option' => 'compact'],
        ]);

        self::assertNotSame($first, $second);
    }

    public function testChromeOutputPathHasNoSharedRuntimeCacheHook(): void
    {
        $class = new ReflectionClass(Partials::class);

        self::assertFalse($class->hasProperty('runtimeCache'));
        self::assertFalse($class->hasMethod('readRuntimePartialOutputCache'));
        self::assertFalse($class->hasMethod('acquirePartialRefreshLock'));
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

    public function testBackendChromeAndThemeDataRemainHotAcrossAnIdleDay(): void
    {
        $partials = (new ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $staleTtl = new ReflectionMethod(Partials::class, 'partialOutputStaleTtl');
        $staleTtl->setAccessible(true);

        $themeDataTtl = new ReflectionMethod(ThemeData::class, 'runtimeCacheTtl');
        $themeDataTtl->setAccessible(true);
        $observerTtl = new ReflectionMethod(ControllerFetchFileBefore::class, 'runtimeCacheTtl');
        $observerTtl->setAccessible(true);

        self::assertGreaterThanOrEqual(86400, $staleTtl->invoke($partials));
        self::assertGreaterThanOrEqual(86400, $themeDataTtl->invoke(null));
        self::assertGreaterThanOrEqual(86400, $observerTtl->invoke(null));
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
