<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Runtime layout resolver must expose overlay-on-existing-layout for entity hard-cut.
 */
final class ThemeRuntimeLayoutLocaleOverlayContractTest extends TestCase
{
    public function testOverlayLocaleOnLayoutIsPublicAndUsedByResolveForRender(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/ThemeRuntimeLayoutResolver.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString('public function overlayLocaleOnLayout(', $src);
        self::assertStringContainsString('applyLayoutLocaleOverlay(', $src);
        // identity locale_code=default must not block RequestContext storefront locale.
        self::assertStringContainsString(
            "if (\$locale === '' || \\strcasecmp(\$locale, 'default') === 0)",
            $src,
        );

        $start = \strpos($src, 'public function resolveLayoutForRender(');
        self::assertNotFalse($start);
        $next = \strpos($src, "\n    public function ", $start + 10);
        self::assertNotFalse($next);
        $body = \substr($src, $start, $next - $start);
        self::assertStringContainsString('overlayLocaleOnLayout(', $body);
    }
}
