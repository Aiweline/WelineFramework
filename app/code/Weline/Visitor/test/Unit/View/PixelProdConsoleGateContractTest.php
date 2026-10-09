<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * PROD deploy mode must not emit [WelinePixel] console logs merely because
 * the acceptance host is *.test.weline.com / *.weline.test.
 */
final class PixelProdConsoleGateContractTest extends TestCase
{
    public function testPixelTrackLogsGateOnDeployModeNotAcceptanceHost(): void
    {
        $root = \dirname(__DIR__, 3);
        foreach ([
            $root . '/view/statics/js/pixel.js',
            $root . '/view/taglib/js/pixel.phtml',
        ] as $path) {
            $src = (string) \file_get_contents($path);
            self::assertStringContainsString('function __isDevConsoleEnabled', $src);
            self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $src);
            self::assertStringContainsString('if (__isDevConsoleEnabled() && typeof console !== \'undefined\' && typeof console.log === \'function\')', $src);
            self::assertStringContainsString("console.log('[WelinePixel] track'", $src);
            self::assertStringContainsString("console.log('[WelinePixel] drop incomplete'", $src);
            // Host regex may remain for eager-load helpers elsewhere; track/drop must not OR it in.
            self::assertDoesNotMatchRegularExpression(
                '/console\.log\(\'\[WelinePixel\] track\'[\s\S]{0,800}?test\\\\?\.weline\\\\?\.com/',
                $src
            );
            self::assertDoesNotMatchRegularExpression(
                '/console\.log\(\'\[WelinePixel\] drop incomplete\'[\s\S]{0,800}?test\\\\?\.weline\\\\?\.com/',
                $src
            );
        }
    }

    public function testBootstrapDevLogGatesOnDeployMode(): void
    {
        $root = \dirname(__DIR__, 3);
        $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
        $bodyEnd = (string) \file_get_contents(
            $root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );

        foreach ([$bootstrap, $bodyEnd] as $src) {
            self::assertStringContainsString('function isDevConsoleEnabled()', $src);
            self::assertStringContainsString('if (!isDevConsoleEnabled()', $src);
            self::assertStringContainsString('if (isDevConsoleEnabled())', $src);
            self::assertStringContainsString("console.log('[WelineCTA] click'", $src);
        }

        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
    }
}
