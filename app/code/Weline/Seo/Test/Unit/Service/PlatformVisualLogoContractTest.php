<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\PlatformVisual;

final class PlatformVisualLogoContractTest extends TestCase
{
    public function testMainstreamPlatformsRenderRealLogoAssetsNotLetterBlocks(): void
    {
        $visual = new PlatformVisual();
        self::assertDirectoryExists($visual->logoDirectory());
        self::assertFileExists($visual->logoDirectory() . '/google.svg');
        self::assertFileExists($visual->logoDirectory() . '/bing.svg');
        self::assertFileExists($visual->logoDirectory() . '/baidu.svg');

        foreach (['google', 'bing', 'baidu', 'yandex', 'duckduckgo', 'indexnow'] as $code) {
            self::assertTrue($visual->hasLogoAsset($code), $code . ' should have logo asset');
            $html = $visual->renderIcon($code, strtoupper($code), null, 30, 'seo-platform-icon');
            self::assertStringContainsString('data-seo-platform-logo="' . $code . '"', $html);
            self::assertStringContainsString('<svg', $html);
            self::assertStringNotContainsString('data-seo-platform-logo-fallback=', $html);
            // Old letter-block used a magnifier circle decoration.
            self::assertStringNotContainsString('circle cx="20.5" cy="11.5"', $html);
        }

        $google = $visual->renderIcon('google_search_console', 'Google', null, 28);
        self::assertStringContainsString('data-seo-platform-logo="google"', $google);
        self::assertStringContainsString('#4285F4', $google);
    }
}
