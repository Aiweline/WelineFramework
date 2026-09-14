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

    public function testRenderedChromeSeparatesOriginsButReusesTheSameOriginAcrossRequests(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        $server = $_SERVER;
        $query = $_GET;
        $previousContext = \Weline\Framework\Context::hasCurrent() ? \Weline\Framework\Context::current() : null;
        if ($previousContext !== null) {
            \Weline\Framework\Context::leave();
        }
        \Weline\Framework\Context::enter(new \Weline\Framework\Context());
        $env = ['base_url' => \w_env('base_url', ''), 'request.uri' => \w_env('request.uri', '')];
        try {
            $_GET = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_SCHEME'] = 'https';
            $_SERVER['HTTP_HOST'] = 'shop.test:9555';
            $_SERVER['WELINE_WEBSITE_URL'] = 'https://shop.test:9555/';
            \w_env_set('base_url', 'https://shop.test:9555');
            \Weline\Framework\Runtime\RequestContext::setId('chrome-origin-first');
            \Weline\Framework\App\State::resetRequestPathLocalizationCache();
            \Weline\Framework\App\State::setRequestLanguageOverride('zh_Hans_CN');
            \Weline\Framework\Cache\StorefrontCacheKeyContext::install(new \Weline\Framework\Cache\StorefrontCacheKeyContext(
                \Weline\Framework\Runtime\ScopeIdentity::channel(0, 'default', 'default', 'default', \Weline\Framework\Runtime\ScopeIdentity::MODE_NORMAL),
                'zh_Hans_CN', 'CNY', str_repeat('a', 64), str_repeat('b', 64), true, '', 'zh_Hans_CN', ['zh_Hans_CN'],
            ));
            $partials = (new ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
            $requestProperty = new ReflectionProperty(Partials::class, 'request');
            $request = $this->getMockBuilder($requestProperty->getType()->getName())
                ->disableOriginalConstructor()
                ->onlyMethods(['getGet', 'getQuery', 'getParam'])
                ->addMethods(['getPathInfo'])
                ->getMock();
            $request->method('getPathInfo')->willReturn('/');
            foreach (['getGet', 'getQuery', 'getParam'] as $methodName) {
                $request->method($methodName)->willReturnCallback(static fn($name, $default = '') => $default);
            }
            $requestProperty->setValue($partials, $request);
            $resolve = new ReflectionMethod(Partials::class, 'resolvePartialOutputCacheContext');
            $key = static fn(string $type) => $resolve->invoke($partials, 'frontend', $type, 'default', [], ['mode' => 'chrome', 'auth' => 'guest', 'ttl' => 300]);
            foreach (['header', 'footer'] as $type) {
                $original = $key($type);
                self::assertIsString($original, 'The real chrome key path must be active.');
                \w_env_set('base_url', 'https://shop.test:19655');
                self::assertNotSame($original, $key($type), 'Absolute rendered links require the actual base URL in the key.');
                \w_env_set('base_url', 'https://shop.test:9555');

                $_SERVER['WELINE_WEBSITE_URL'] = 'https://shop.test:19655/';
                self::assertNotSame($original, $key($type), 'Configured website URLs are part of the rendered origin.');
                $_SERVER['WELINE_WEBSITE_URL'] = 'https://shop.test:9555/';

                $_SERVER['HTTP_HOST'] = 'shop.test:19655';
                self::assertNotSame($original, $key($type), 'Host-derived URLs cannot share another origin chrome.');
                $_SERVER['HTTP_HOST'] = 'shop.test:9555';

                \Weline\Framework\Runtime\RequestContext::setId('chrome-origin-next');
                \w_env_set('request.uri', '/another-page?irrelevant=1');
                $_GET['irrelevant'] = '1';
                self::assertSame($original, $key($type), 'Same-origin shared chrome must remain independent of request IDs and unrelated routes/query.');
            }
        } finally {
            foreach ($env as $key => $value) {
                \w_env_set($key, $value);
            }
            $_SERVER = $server;
            $_GET = $query;
            \Weline\Framework\App\State::resetRequestPathLocalizationCache();
            \Weline\Framework\Context::leave();
            if ($previousContext !== null) {
                \Weline\Framework\Context::enter($previousContext);
            }
        }
    }

    public function testComponentMetaParserReadsNestedCacheMeta(): void
    {
        $file = BP . 'app/code/Weline/Theme/view/theme/backend/partials/sidebar/left.phtml';
        self::assertFileExists($file);

        $parsed = ComponentMetaParser::parse($file);
        $cache = $parsed['meta']['cache'] ?? [];

        self::assertIsArray($cache);
        // 侧栏菜单高亮依赖当前路由，禁止 chrome 跨页复用
        self::assertSame('off', (string)($cache['mode']['default'] ?? ''));
        self::assertSame('user', (string)($cache['auth']['default'] ?? ''));
    }

    public function testBackendNavPathMatchScoreAlignsIndexSuffix(): void
    {
        $js = (string)\file_get_contents(BP . 'app/code/Weline/Theme/view/statics/ui/weline-ui.js');
        self::assertStringContainsString('菜单常带 …/index，当前路由常省略 /index', $js);
        self::assertStringContainsString("menuSegments.length === currentSegments.length + 1", $js);
        $jsUi = (string)\file_get_contents(BP . 'app/code/Weline/Theme/view/ui/js/weline-ui.js');
        self::assertStringContainsString('菜单常带 …/index，当前路由常省略 /index', $jsUi);
    }

    public function testFrontendHeadDeclaresCacheOffNotChrome(): void
    {
        $file = BP . 'app/code/Weline/Theme/view/theme/frontend/partials/head/default.phtml';
        self::assertFileExists($file);

        $parsed = ComponentMetaParser::parse($file);
        $cache = $parsed['meta']['cache'] ?? [];

        self::assertIsArray($cache);
        // Head embeds page SEO/JSON-LD — must never be chrome-shared across URLs.
        self::assertSame('off', (string)($cache['mode']['default'] ?? ''));
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

    public function testStorefrontChromeUsesTheDeclarativeThemePolicy(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Block/Partials.php');

        self::assertStringContainsString('StorefrontThemeCacheCoordinator::storefrontChromePolicy', $source);
        self::assertStringContainsString('$hotCache->rememberPolicy(', $source);
        self::assertStringNotContainsString("['website' => true, 'lang' => true]", $source);
    }

    public function testFrontendHeadIsExcludedFromSharedStorefrontChromeCache(): void
    {
        $partials = (new ReflectionClass(Partials::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Partials::class, 'shouldUseSharedStorefrontChromeCache');
        $method->setAccessible(true);

        // Head embeds page SEO/JSON-LD — never share chrome cache across URLs.
        self::assertFalse($method->invoke($partials, 'frontend', 'head'));
        self::assertTrue($method->invoke($partials, 'frontend', 'header'));
        self::assertTrue($method->invoke($partials, 'frontend', 'footer'));
        self::assertFalse($method->invoke($partials, 'backend', 'head'));
    }

    public function testFrontendHeadBypassesPartialOutputCachePath(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Block/Partials.php');
        self::assertStringContainsString("strtolower(\$type) === 'head'", $source);
        self::assertStringContainsString('Product head can never be replayed', $source);
        self::assertStringContainsString("['header', 'footer']", $source);
    }

    public function testChromePartialCacheSchemaPinsStateLangOverStorefrontCookie(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Block/Partials.php');
        self::assertStringContainsString("'schema' => 'chrome-partial-v12-guest-chrome'", $source);
        self::assertStringContainsString("return 'frontend-auth:0';", $source);
        self::assertStringContainsString('always guest-SSR', $source);
        self::assertStringContainsString("'i18n_switcher_markup'", $source);
        self::assertStringContainsString('SWITCHER_MARKUP_VERSION', $source);
        self::assertStringContainsString("'lang' => (string)State::getLang()", $source);
        self::assertStringContainsString(
            'Theme-preview request language override must win over storefront',
            $source,
        );
    }

    public function testFrontendHeaderFingerprintIncludesNestedNavigationTemplates(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Theme/Block/Partials.php');

        self::assertStringContainsString('categories-horizontal-nav.phtml', $source);
        self::assertStringContainsString('categories-sidebar-nav.phtml', $source);
        self::assertStringContainsString('mega-menu-panel.phtml', $source);
        self::assertStringContainsString('/widgets/header/mini-cart-icon/default.phtml', $source);
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
