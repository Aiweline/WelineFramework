<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;

/**
 * Source-string contract: entity disk path segments match hard-cutover plan.
 */
final class ThemeLayoutEntityPathsContractTest extends TestCase
{
    public function testPathSegmentsMatchEntityLayout(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);

        self::assertStringContainsString("ROOT_SEGMENT = 'theme-layout-entities'", $src);
        self::assertStringContainsString("var/runtime", $src);
        self::assertStringContainsString("'tv'", $src);
        self::assertStringContainsString("'chrome'", $src);
        self::assertStringContainsString("'chrome.phtml'", $src);
        self::assertStringContainsString("'chrome-config.json'", $src);
        self::assertStringContainsString("'pages'", $src);
        self::assertStringContainsString("'layout.phtml'", $src);
        self::assertStringContainsString("'page-config.json'", $src);
        self::assertStringContainsString("'structure.json'", $src);
        self::assertStringContainsString('function scopeKey', $src);
        self::assertStringContainsString('function identityKey', $src);
        self::assertStringContainsString('sha1', $src);
        self::assertStringContainsString('substr($identityHash, 0, 16)', $src);
        // Sentinels like __channel__ must keep trailing underscores in disk keys.
        self::assertStringContainsString("\\trim(\$sanitized, '.-')", $src);
        self::assertStringNotContainsString("\\trim(\$sanitized, '._-')", $src);
    }

    public function testScopeKeyPreservesChannelSentinel(): void
    {
        require_once \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntityPaths.php';
        $paths = new \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPaths();
        self::assertSame(
            'default.__store__.__channel__',
            $paths->scopeKey('default.__store__.__channel__'),
        );
    }
}
