<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Weline\Meta\Service\ParamDefinitionNormalizer;

/**
 * StoreMusic must declare required default_injection so restore-original re-applies it.
 */
class StoreMusicDefaultInjectionContractTest extends TestCase
{
    public function testWidgetDeclaresRequiredHomepageFloatStartInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
        $tpl = 'Weline_StoreMusic::templates/frontend/widgets/store-music.phtml';
        self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/store-music.phtml');
        self::assertStringContainsString('@widget.code {store-music}', $src);
        self::assertStringContainsString('layout-storefront-float-start', $src);
        self::assertStringContainsString('"layout_type":"*"', $src);
        self::assertStringContainsString('"slot":"storefront-float-start"', $src);
        self::assertStringContainsString('"area":"footer"', $src);
        self::assertStringContainsString('"required":true', $src);
        self::assertStringContainsString('"enabled":true', $src);
        self::assertStringContainsString('"tracks":[]', $src);
        self::assertStringContainsString('"try_autoplay":true', $src);
        self::assertStringContainsString('"waveform_default":true', $src);
    }

    public function testWidgetDeclaresComponentConfigParams(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/frontend/widgets/store-music.phtml');
        $params = (new ParamDefinitionNormalizer())->extractParamAnnotations($src);
        foreach ([
            'enabled',
            'tracks',
            'delay_seconds',
            'try_autoplay',
            'loop',
            'default_volume',
            'avatar_spin',
            'waveform_default',
        ] as $key) {
            self::assertArrayHasKey($key, $params, "missing param {$key}");
        }
        self::assertSame(true, $params['enabled']['default'] ?? null);
        self::assertSame([], $params['tracks']['default'] ?? null);
        self::assertSame(true, $params['try_autoplay']['default'] ?? null);
        self::assertSame(true, $params['waveform_default']['default'] ?? null);
        self::assertSame('array', $params['tracks']['type'] ?? null);
        self::assertSame('media_image', $params['tracks']['item_schema']['url']['type'] ?? null);
        self::assertSame('audio', $params['tracks']['item_schema']['url']['media_options']['kind'] ?? null);
        self::assertArrayHasKey('title', $params['tracks']['item_schema'] ?? []);
        self::assertArrayHasKey('intro', $params['tracks']['item_schema'] ?? []);
        self::assertTrue(($params['tracks']['item_schema']['intro']['i18n'] ?? false) === true);
        self::assertSame('选择曲目添加', $params['tracks']['add_with_media_label'] ?? null);
    }

    public function testFloatSlotHookRemoved(): void
    {
        $hook = dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/float-slot-start.phtml';
        self::assertFileDoesNotExist($hook);
    }

    public function testLayoutPresenceHelperExists(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/StoreMusicLayoutPresence.php';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('function layoutPlacesWidget', $src);
        self::assertStringContainsString('function layoutContainsStoreMusic', $src);
        self::assertStringContainsString('ThemeRuntimeLayoutResolver', $src);
        self::assertStringContainsString('ThemePageTypeResolver', $src);
        self::assertStringContainsString('ThemeContextService', $src);
        self::assertStringContainsString('resolveRegisteredDefaultTheme', $src);
        self::assertStringContainsString('resolvePageTypeFromUri', $src);
        self::assertStringContainsString('identityCandidates', $src);
        self::assertStringContainsString('default.__website__.default', $src);
    }

    public function testInactiveAndDuplicateRendersEmitPresenceStub(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/frontend/widgets/store-music.phtml';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('data-widget-code="store-music"', $src);
        self::assertStringContainsString('data-store-music-skipped', $src);
        self::assertStringContainsString("\$presenceStub('inactive')", $src);
        self::assertStringContainsString("\$presenceStub('already-rendered')", $src);
        self::assertStringNotContainsString("\$presenceStub('hook-owned')", $src);
        self::assertStringNotContainsString('__weline_store_music_from_hook', $src);
        self::assertStringContainsString('storefront-float-start', $src);
    }

    public function testRenderGateClaimsOnce(): void
    {
        $gate = dirname(__DIR__, 3) . '/Service/StoreMusicRenderGate.php';
        self::assertFileExists($gate);
        $src = (string)file_get_contents($gate);
        self::assertStringContainsString('function claim', $src);
        self::assertStringContainsString('function reset', $src);
        self::assertStringContainsString('weline.store_music.render_claimed', $src);
    }

    public function testRenderGateResetAllowsSecondClaim(): void
    {
        \Weline\StoreMusic\Service\StoreMusicRenderGate::reset();
        self::assertTrue(\Weline\StoreMusic\Service\StoreMusicRenderGate::claim());
        self::assertFalse(\Weline\StoreMusic\Service\StoreMusicRenderGate::claim());
        \Weline\StoreMusic\Service\StoreMusicRenderGate::reset();
        self::assertTrue(\Weline\StoreMusic\Service\StoreMusicRenderGate::claim());
        \Weline\StoreMusic\Service\StoreMusicRenderGate::reset();
    }
}
