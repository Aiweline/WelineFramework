<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Storefront preview publish must hit same-origin frontend route and Token actor auth.
 */
final class ThemePreviewStorefrontPublishAndExitContractTest extends TestCase
{
    public function testFloatInjectsFrontendPublishUrlNotBackend(): void
    {
        $path = \dirname(__DIR__, 4) . '/Observer/LayoutSlotRenderer.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString(
            "getFrontendUrl('theme/frontend/theme-preview/publish-and-exit')",
            $source,
        );
        self::assertStringNotContainsString(
            "getBackendUrl('theme/backend/theme-editor/publish-and-exit')",
            $source,
        );
    }

    public function testFrontendPublishControllerAuthorizesTokenActorThenDelegates(): void
    {
        $path = \dirname(__DIR__, 4) . '/Controller/Frontend/ThemePreview/PublishAndExit.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString('file_access_actor_id', $source);
        self::assertStringContainsString('ThemePreviewPublishActorBinder::install', $source);
        self::assertStringContainsString('ThemePreviewPublishActorBinder::clear', $source);
        self::assertStringContainsString('postPublishAndExit()', $source);
        self::assertStringContainsString('theme_publish_requires_login', $source);
        self::assertStringContainsString('createDirectThemeEditor', $source);
        self::assertStringContainsString("getMethod()) !== 'POST'", $source);
        self::assertStringNotContainsString('getBackendUrl', $source);
    }

    public function testAssetEditorProducerAcceptsPreviewPublishBinder(): void
    {
        $path = \dirname(__DIR__, 4) . '/Service/Scoped/ThemeAssetEditorContextProducer.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString('ThemePreviewPublishActorBinder::current()', $source);
        self::assertStringContainsString('function resolveActor()', $source);
    }

    public function testPublishActorBinderRejectsDisabledActor(): void
    {
        $path = \dirname(__DIR__, 4) . '/Service/ThemePreviewPublishActorBinder.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString('theme_preview_publish_actor_invalid', $source);
        self::assertStringContainsString('getIsEnabled()', $source);
    }
}
