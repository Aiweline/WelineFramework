<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * theme.js language/currency fallback must peel live preview and not re-stack /~site.
 */
final class ThemeJsLivePreviewLocalizedPathContractTest extends TestCase
{
    public function testBuildLocalizedFrontendPathPeelsLivePreviewMount(): void
    {
        $themeJs = $this->read('view/theme/frontend/assets/js/theme.js');

        self::assertStringContainsString('peelLivePreviewPathMount', $themeJs);
        self::assertStringContainsString('LIVE_PREVIEW_TOKEN_PATTERN', $themeJs);
        self::assertStringContainsString('never re-stack', $themeJs);
        self::assertStringContainsString('data-website-mount', $themeJs);

        $resolveAt = strpos($themeJs, 'function resolveThemeWebsiteMountPath');
        self::assertNotFalse($resolveAt);
        $resolveBody = substr($themeJs, $resolveAt, 900);
        self::assertStringContainsString('peelLivePreviewPathMount(window.location.pathname', $resolveBody);
        self::assertStringContainsString("return '';", $resolveBody);

        $builderAt = strpos($themeJs, 'function buildLocalizedFrontendPath');
        self::assertNotFalse($builderAt);
        $builderBody = substr($themeJs, $builderAt, 2800);
        self::assertStringContainsString('peelLivePreviewPathMount(pathOnly)', $builderBody);
        self::assertStringContainsString("live.token ? '' : resolveThemeWebsiteMountPath()", $builderBody);
        self::assertStringContainsString('/~preview/${live.token}', $builderBody);
        self::assertStringContainsString("part === '~preview'", $builderBody);
        self::assertStringContainsString("part === '~site'", $builderBody);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        $content = file_get_contents($path);
        self::assertIsString($content, $path);

        return $content;
    }
}
