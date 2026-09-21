<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * StoreMusic must declare required default_injection so restore-original re-applies it.
 */
class StoreMusicDefaultInjectionContractTest extends TestCase
{
    public function testWidgetDeclaresRequiredHomepageContentInjection(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $widgets */
        $widgets = require $path;
        self::assertArrayHasKey('store-music', $widgets);
        $widget = $widgets['store-music'];
        self::assertSame('store-music', $widget['code'] ?? null);
        self::assertSame('Weline_StoreMusic::templates/frontend/widgets/store-music.phtml', $widget['template'] ?? null);
        $injection = $widget['default_injections'][0] ?? [];
        self::assertSame('homepage', $injection['layout_type'] ?? null);
        self::assertSame('content', $injection['slot'] ?? null);
        self::assertTrue(!empty($injection['required']));
    }

    public function testWidgetDeclaresComponentConfigParams(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_StoreMusic/widget.php';
        /** @var array<string, mixed> $widgets */
        $widgets = require $path;
        $params = $widgets['store-music']['params'] ?? [];
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
        self::assertSame('array', $params['tracks']['type'] ?? null);
        self::assertSame('media_image', $params['tracks']['item_schema']['url']['type'] ?? null);
        self::assertSame('audio', $params['tracks']['item_schema']['url']['media_options']['kind'] ?? null);
        self::assertArrayHasKey('title', $params['tracks']['item_schema'] ?? []);
        self::assertArrayHasKey('intro', $params['tracks']['item_schema'] ?? []);
        self::assertTrue(($params['tracks']['item_schema']['intro']['i18n'] ?? false) === true);
        self::assertSame('选择曲目添加', $params['tracks']['add_with_media_label'] ?? null);
    }

    public function testHookDefersToSharedWidgetTemplate(): void
    {
        $hook = dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        $src = (string)file_get_contents($hook);
        self::assertStringContainsString("BP . '/app/code/Weline/StoreMusic/view/templates/frontend/widgets/store-music.phtml'", $src);
        self::assertStringNotContainsString('data-store-music-config', $src);
        self::assertStringNotContainsString("__DIR__", $src);
        self::assertStringContainsString('StoreMusicLayoutPresence', $src);
        self::assertStringContainsString('layoutPlacesWidget', $src);
        self::assertStringNotContainsString('isThemeEditorCanvas', $src);
        self::assertStringNotContainsString('visual_editor', $src);
        self::assertStringNotContainsString("getGet('preview'", $src);
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
        self::assertStringContainsString('getActiveTheme', $src);
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
