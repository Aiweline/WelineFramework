<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\Ga4Vendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\GtmVendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\SystemVendor;
use Weline\Visitor\Interface\PixelEventVendorInterface;
use Weline\Visitor\Service\PixelEventVendorManager;

final class PixelEventVendorContractTest extends TestCase
{
    public function testBuiltInVendorsImplementInterfaceAndDefaultSandbox(): void
    {
        $ga4 = new Ga4Vendor();
        $gtm = new GtmVendor();
        $system = new SystemVendor();
        self::assertInstanceOf(PixelEventVendorInterface::class, $ga4);
        self::assertInstanceOf(PixelEventVendorInterface::class, $gtm);
        self::assertInstanceOf(PixelEventVendorInterface::class, $system);
        self::assertSame('ga4', $ga4->getCode());
        self::assertSame('gtm', $gtm->getCode());
        self::assertSame('weline', $system->getCode());
        self::assertSame('sandbox', $ga4->getDefaultMode());
        self::assertSame('sandbox', $gtm->getDefaultMode());
        self::assertSame('sandbox', $system->getDefaultMode());
        self::assertArrayHasKey('checkout_success', $ga4->getDefaultEventMap());
        self::assertSame('purchase', $ga4->getDefaultEventMap()['checkout_success']);
        self::assertSame('checkout_success', $system->getDefaultEventMap()['checkout_success'] ?? null);
        self::assertArrayHasKey('measurement_id', $ga4->getConfigSchema());
        self::assertArrayHasKey('container_id', $gtm->getConfigSchema());
        self::assertTrue(!empty($system->getCapabilities()['system_native']));
        self::assertNotEmpty($ga4->cspDirectives()['script-src'] ?? []);
        self::assertNotEmpty($gtm->cspDirectives()['frame-src'] ?? []);
        self::assertSame([], $system->cspDirectives());
    }

    public function testSystemVendorAlwaysEnabledAndLocked(): void
    {
        $ref = new \ReflectionClass(PixelEventVendorManager::class);
        $manager = $ref->newInstanceWithoutConstructor();
        $seed = $ref->getMethod('legacyEnabledSeed');
        $seed->setAccessible(true);
        self::assertTrue($seed->invoke($manager, 'weline', 0));
        self::assertTrue($seed->invoke($manager, 'weline', 1));
        $isSys = $ref->getMethod('isSystemVendorCode');
        $isSys->setAccessible(true);
        self::assertTrue($isSys->invoke($manager, 'weline'));
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/PixelEventVendorManager.php');
        self::assertStringContainsString('isSystemVendorCode($code)', $src);
        self::assertMatchesRegularExpression(
            '/isSystemVendorCode\(\$code\)[\s\S]{0,40}return\s+true;/',
            $src
        );
        $tpl = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml');
        self::assertStringContainsString('data-locked="1"', $tpl);
        self::assertStringContainsString('系统像素默认启用且不可关闭', $tpl);
    }

    public function testFilterMappableMapDropsSkipGtmPushEvents(): void
    {

        $ref = new \ReflectionClass(PixelEventVendorManager::class);
        $manager = $ref->newInstanceWithoutConstructor();
        $filtered = $manager->filterMappableMap([
            'page_view' => 'page_view',
            'cta_click' => 'cta_click',
            'checkout_success' => 'purchase',
        ]);
        self::assertArrayNotHasKey('page_view', $filtered);
        self::assertSame('cta_click', $filtered['cta_click'] ?? null);
        self::assertSame('purchase', $filtered['checkout_success'] ?? null);
    }

    public function testExtendsDeclaresPixelEventVendorPoint(): void
    {
        $extends = include \dirname(__DIR__, 3) . '/extends.php';
        self::assertIsArray($extends);
        self::assertArrayHasKey('extends', $extends);
        self::assertArrayHasKey('PixelEventVendor', $extends['extends']);
        self::assertSame(
            PixelEventVendorInterface::class,
            $extends['extends']['PixelEventVendor']['interface']
        );
    }

    public function testAdminSplitLayoutTemplateExists(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('data-variant-winner="2"', $src);
        self::assertStringContainsString('data-layout="split"', $src);
        self::assertStringContainsString('tv-nav', $src);
        self::assertStringContainsString('事件搭接', $src);
        self::assertStringContainsString('范围', $src);
        self::assertStringContainsString('data-tab="scope"', $src);
        self::assertStringContainsString('DEV：不上报', $src);
        self::assertStringContainsString('tracking-vendor-admin.css', $src);
        self::assertStringContainsString('tvp-detail__foot', $src);
        self::assertStringContainsString('data-tv-js-editor', $src);
        self::assertStringContainsString('data-tv-js-format', $src);
        self::assertStringContainsString('data-tv-js-theme-float', $src);
        self::assertStringContainsString('data-tv-js-theme="material-darker"', $src);
        self::assertStringContainsString('data-tv-js-theme="dracula"', $src);
        self::assertStringNotContainsString('data-testid="tv-js-theme"', $src);
        self::assertStringNotContainsString('<select class="w-input" data-size="sm" data-tv-js-theme', $src);
        self::assertStringContainsString('tracking-vendor-js-editor.js', $src);
        self::assertStringContainsString('codemirror.js', $src);
        self::assertStringContainsString('tv-start-picker', $src);
        self::assertStringContainsString('tv-map-accumulate', $src);
        self::assertStringContainsString('markMapCustomized', $src);
        self::assertStringContainsString('tv-use-default-map', $src);
        self::assertStringContainsString('tv-default-map-hint', $src);
        self::assertStringContainsString('（自定义）', $src);
        $tvPhp = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php');
        self::assertStringContainsString('mergeEventMapKeysIntoMappable', $tvPhp);
        self::assertStringContainsString('已保存搭接中的自定义 key 必须进入下拉', $tvPhp);
        $css = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/css/tracking-vendor-admin.css');
        self::assertStringContainsString('max-inline-size: none', $css);
        self::assertStringContainsString('inline-size: 100%', $css);
        self::assertFileExists(\dirname(__DIR__, 3) . '/view/statics/js/tracking-vendor-js-editor.js');
        self::assertFileExists(\dirname(__DIR__, 3) . '/view/statics/libs/codemirror/theme/material-darker.css');
        self::assertFileExists(\dirname(__DIR__, 3) . '/view/statics/libs/codemirror/theme/dracula.css');
    }

    public function testTrackingVendorRedirectsKeepBackendAreaSegment(): void
    {
        $path = \dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString("redirect('visitor/backend/tracking-vendor/index'", $src);
        self::assertStringNotContainsString("redirect('*/tracking-vendor/index'", $src);
        // * 仅展开为 module router「visitor」，会生成 /visitor/tracking-vendor → 404
        self::assertStringNotContainsString("'*/tracking-vendor/", $src);

        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/backend/tracking-vendor-work-scope.js');
        self::assertStringContainsString('/visitor/backend/tracking-vendor/', $js);
        self::assertStringContainsString("replace('/visitor/tracking-vendor/', '/visitor/backend/tracking-vendor/')", $js);
    }

    public function testSaveCustomClearsDefaultMapWhenWritingEventMap(): void
    {
        $mgr = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/PixelEventVendorManager.php');
        self::assertStringContainsString('自定义搭接门禁', $mgr);
        self::assertStringContainsString('providerDefaultEventMap', $mgr);
        self::assertStringContainsString('误标「使用默认」却含自定义键', $mgr);
        self::assertStringContainsString('array_diff_key($current, $defaultMap)', $mgr);
        $picker = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Analytics/EventPicker.php');
        self::assertStringContainsString("'use_default_map' => false", $picker);
        $rest = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Api/Rest/V1/EventPicker.php');
        self::assertStringContainsString("'use_default_map' => false", $rest);
    }

    public function testAutosaveAndTabPersistContract(): void
    {
        $tv = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php');
        self::assertStringContainsString('function postAutosave', $tv);
        self::assertStringContainsString('payloadFromSaveRequest', $tv);
        self::assertStringContainsString("'tab' => \$uiTab", $tv);
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml');
        self::assertStringContainsString('autosave_url', $src);
        self::assertStringContainsString('scheduleAutosave', $src);
        self::assertStringContainsString('tvp_ui_tab_v1', $src);
        self::assertStringContainsString('activateTab', $src);
        self::assertStringContainsString('tv-autosave-status', $src);
        self::assertStringContainsString('name="ui_tab"', $src);
        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/tracking-vendor-js-editor.js');
        self::assertStringContainsString('__tvFlushJsEditors', $js);
        self::assertStringContainsString('tv:js-dirty', $js);
    }

    public function testMenuRegistersTrackingVendor(): void
    {
        $menu = (string)\file_get_contents(\dirname(__DIR__, 3) . '/etc/backend/menu.xml');
        self::assertStringContainsString('Weline_Visitor::tracking_vendor', $menu);
        self::assertStringContainsString('visitor/backend/tracking-vendor/index', $menu);
        self::assertStringContainsString('title="事件供应商"', $menu);
    }

    public function testPixelJsFansOutVendors(): void
    {
        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/pixel.js');
        self::assertStringContainsString('__fanoutPixelVendors', $js);
        self::assertStringContainsString('publishVendor', $js);
        self::assertStringContainsString('ensureVendorFrame', $js);
        self::assertStringContainsString('__vendorScopeMatches', $js);
    }
}
