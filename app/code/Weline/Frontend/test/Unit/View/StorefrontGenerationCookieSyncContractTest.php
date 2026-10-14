<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Cookie vs page bake stamp must sync (not toast+reload) on mismatch.
 * True publish advance stays on ScopeBootstrap / QueryBin → promptStorefrontGenerationRefresh.
 */
final class StorefrontGenerationCookieSyncContractTest extends TestCase
{
    public function testCheckStorefrontGenerationIdentitySyncsCookieWithoutReloadPrompt(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/statics/js/weline.js';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('function syncStorefrontGenerationCookieToPage', $src);
        self::assertStringContainsString('function checkStorefrontGenerationIdentity', $src);
        self::assertStringContainsString('syncStorefrontGenerationCookieToPage(pageId)', $src);

        // Boot-time cookie≠page must not call prompt (reload loop on html-disk HIT).
        self::assertMatchesRegularExpression(
            '/function checkStorefrontGenerationIdentity\(\)\s*\{[\s\S]*?syncStorefrontGenerationCookieToPage\(pageId\);[\s\S]*?return true;[\s\S]*?\}/',
            $src
        );
        $checkBody = '';
        if (\preg_match(
            '/function checkStorefrontGenerationIdentity\(\)\s*\{([\s\S]*?)\n    \}/',
            $src,
            $m
        )) {
            $checkBody = $m[1];
        }
        self::assertNotSame('', $checkBody);
        self::assertStringNotContainsString('promptStorefrontGenerationRefresh', $checkBody);
        self::assertStringContainsString('promptStorefrontGenerationRefresh', $src);
    }
}
