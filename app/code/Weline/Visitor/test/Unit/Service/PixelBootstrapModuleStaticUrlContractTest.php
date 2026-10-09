<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * PROD moduleStaticUrl must never splice absolute Env theme.path into /static/.
 */
final class PixelBootstrapModuleStaticUrlContractTest extends TestCase
{
    public function testPixelAndPanelBootstrapNormalizeThemePathViaPublicThemeNamespace(): void
    {
        foreach ([
            dirname(__DIR__, 3) . '/Service/PixelBootstrapHtmlService.php',
            dirname(__DIR__, 3) . '/Service/VisitorPanelBootstrapHtmlService.php',
        ] as $path) {
            self::assertFileExists($path);
            $src = (string)\file_get_contents($path);
            self::assertStringContainsString('PublicThemeNamespace::resolve', $src, $path);
            self::assertDoesNotMatchRegularExpression(
                '/\$url\s*=\s*[\'"]\\/static\\/[\'"]\s*\.\s*\\\\str_replace\(/',
                $src,
                'Raw theme.path must not be str_replace-spliced into /static/: ' . $path,
            );
        }
    }
}
