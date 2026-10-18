<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutOriginWatchService;
use Weline\Theme\Service\ThemeApplicationUsageService;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * Behavior tests: layout embed scan, module identity, external (__ext__) classification.
 */
final class ThemeLayoutOriginWatchEmbedScanTest extends TestCase
{
    private ThemeLayoutOriginWatchService $bare;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bare = (new ReflectionClass(ThemeLayoutOriginWatchService::class))
            ->newInstanceWithoutConstructor();
    }

    public function testLayoutHtmlEmbedsWidgetMatchesRealTagNotAcceptList(): void
    {
        $html = <<<'HTML'
<section accept="banner,promo-banner,content">
  <w:widget type="banner" name="hero-slider" />
  <w:widget type="banner" name="promo-banner" />
</section>
HTML;
        self::assertTrue($this->invokeBare('layoutHtmlEmbedsWidget', $html, 'banner', 'promo-banner', 'Weline_Theme'));
        self::assertFalse($this->invokeBare('layoutHtmlEmbedsWidget', $html, 'banner', 'promo-banner', 'Weline_Payment'));
        self::assertFalse($this->invokeBare('layoutHtmlEmbedsWidget', $html, 'content', 'promo-banner', 'Weline_Theme'));

        $acceptOnly = '<div accept="layout-bottom,banner,promo-banner,content"></div>';
        self::assertFalse($this->invokeBare('layoutHtmlEmbedsWidget', $acceptOnly, 'banner', 'promo-banner', 'Weline_Theme'));
    }

    public function testLayoutHtmlEmbedsWidgetHonorsModuleAttribute(): void
    {
        $html = '<w:widget module="Weline_Payment" type="product" name="product-express-payment" />';
        self::assertTrue($this->invokeBare(
            'layoutHtmlEmbedsWidget',
            $html,
            'product',
            'product-express-payment',
            'Weline_Payment'
        ));
        self::assertFalse($this->invokeBare(
            'layoutHtmlEmbedsWidget',
            $html,
            'product',
            'product-express-payment',
            'Weline_Theme'
        ));
    }

    public function testClassifyExternalExtPathCarriesModule(): void
    {
        $class = $this->invokeBare(
            'classifyRelativePath',
            '__ext__/Weline_Payment/product/product-express-payment.phtml'
        );
        self::assertIsArray($class);
        self::assertSame('widget', $class['kind']);
        self::assertSame('Weline_Payment', $class['module']);
        self::assertSame('product', $class['type']);
        self::assertSame('product-express-payment', $class['code']);
    }

    public function testClassifyExternalWidgetAssetCssAsWidgetWithAssetFlag(): void
    {
        $class = $this->invokeBare(
            'classifyRelativePath',
            '__ext__/Weline_Demo/content/origin-watch-probe/asset/widget-origin-watch-probe.css'
        );
        self::assertIsArray($class);
        self::assertSame('widget', $class['kind']);
        self::assertTrue(!empty($class['asset']));
        self::assertSame('Weline_Demo', $class['module']);
        self::assertSame('content', $class['type']);
        self::assertSame('origin-watch-probe', $class['code']);
    }

    public function testClassifyExternalWidgetAssetJsAsWidgetWithAssetFlag(): void
    {
        $class = $this->invokeBare(
            'classifyRelativePath',
            '__ext__/Weline_Demo/content/origin-watch-probe/asset/widget-origin-watch-probe.js'
        );
        self::assertIsArray($class);
        self::assertSame('widget', $class['kind']);
        self::assertTrue(!empty($class['asset']));
        self::assertSame('Weline_Demo', $class['module']);
    }

    public function testClassifyModulePrefixStaticJsAsModuleStatic(): void
    {
        $class = $this->invokeBare(
            'classifyRelativePath',
            '__modstatic__/Weline/Framework/view/statics/js/dom-observer.js'
        );
        self::assertIsArray($class);
        self::assertSame('module_static', $class['kind']);
    }

    public function testClassifyModulePrefixStaticNonJsSkipped(): void
    {
        $class = $this->invokeBare(
            'classifyRelativePath',
            '__modstatic__/Weline/Framework/view/statics/img/logo.png'
        );
        self::assertIsArray($class);
        self::assertSame('skip', $class['kind']);
    }

    public function testClassifyThemeWidgetDefaultsModuleWelineTheme(): void
    {
        $class = $this->invokeBare(
            'classifyRelativePath',
            'theme/frontend/widgets/banner/promo-banner/default.phtml'
        );
        self::assertIsArray($class);
        self::assertSame('widget', $class['kind']);
        self::assertSame('Weline_Theme', $class['module']);
        self::assertSame('banner', $class['type']);
        self::assertSame('promo-banner', $class['code']);
    }

    public function testHanfuPromoBannerEmbedScanFindsHomepageAndActivity(): void
    {
        $this->bootApp();
        $theme = $this->findHanfuTheme();
        if ($theme === null) {
            self::markTestSkipped('hanfu theme not bound in this environment');
        }
        $service = ObjectManager::getInstance(ThemeLayoutOriginWatchService::class);
        /** @var list<string> $types */
        $types = $this->invokeOn($service, 'layoutTypesFromWidgetEmbeds', $theme, 'banner', 'promo-banner', 'Weline_Theme');
        self::assertContains('homepage', $types);
        self::assertContains('activity', $types);
        self::assertNotContains('policy', $types);
        self::assertNotContains('about', $types);
    }

    public function testExternalSourceSkippedWhenThemeOverridesWidget(): void
    {
        $this->bootApp();
        $theme = $this->findHanfuTheme();
        if ($theme === null) {
            self::markTestSkipped('hanfu theme not bound in this environment');
        }
        $service = ObjectManager::getInstance(ThemeLayoutOriginWatchService::class);
        self::assertTrue($this->invokeOn($service, 'themeHasLocalWidgetOverride', $theme, 'banner', 'promo-banner'));
    }

    public function testResolveSolidifyCascadeUsesEmbedScanNotFullThemeForPromoBanner(): void
    {
        $this->bootApp();
        $theme = $this->findHanfuTheme();
        if ($theme === null) {
            self::markTestSkipped('hanfu theme not bound in this environment');
        }
        $service = ObjectManager::getInstance(ThemeLayoutOriginWatchService::class);
        $classified = [
            'layouts' => [],
            'chrome' => false,
            'widgets' => [[
                'module' => 'Weline_Theme',
                'type' => 'banner',
                'code' => 'promo-banner',
                'path' => 'theme/frontend/widgets/banner/promo-banner/default.phtml',
            ]],
            'templates' => [],
            'skipped' => [],
        ];
        /** @var array{full_theme:bool,layouts:list<array{type:string,option:string}>,embed_scan:bool} $cascade */
        $cascade = $this->invokeOn($service, 'resolveSolidifyCascade', $theme, $classified);
        self::assertFalse($cascade['full_theme']);
        self::assertTrue($cascade['embed_scan']);
        $types = \array_values(\array_unique(\array_map(
            static fn(array $row): string => (string)$row['type'],
            $cascade['layouts']
        )));
        self::assertContains('homepage', $types);
        self::assertContains('activity', $types);
        self::assertLessThan(10, \count($types), 'embed cascade must stay narrow vs full-theme');
    }

    /**
     * @param mixed ...$args
     */
    private function invokeBare(string $method, mixed ...$args): mixed
    {
        return $this->invokeOn($this->bare, $method, ...$args);
    }

    /**
     * @param mixed ...$args
     */
    private function invokeOn(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target, ...$args);
    }

    private function bootApp(): void
    {
        static $booted = false;
        if ($booted) {
            return;
        }
        require_once BP . 'app/bootstrap.php';
        $booted = true;
    }

    private function findHanfuTheme(): ?WelineTheme
    {
        $usage = ObjectManager::getInstance(ThemeApplicationUsageService::class);
        foreach ($usage->themesForDefaultUpgrade() as $theme) {
            $path = \str_replace('\\', '/', (string)$theme->getPath());
            if (\str_contains($path, '/design/Weline/hanfu')) {
                return $theme;
            }
        }

        return null;
    }
}
