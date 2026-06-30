<?php

declare(strict_types=1);

namespace Weline\ElFinderFileManager\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

class ElFinderSetupConfigTest extends TestCase
{
    public function testConfigUsesGuardedPhpCopyInsteadOfShellCopy(): void
    {
        $config = file_get_contents(__DIR__ . '/../../../config.php');
        self::assertIsString($config);

        self::assertStringContainsString('is_dir($vendorPath)', $config);
        self::assertStringContainsString('RecursiveIteratorIterator', $config);
        self::assertStringContainsString('removeLegacyElFinderServerStaticPath', $config);
        self::assertStringContainsString("'main.default.js'", $config);
        self::assertStringNotContainsString("            'php',", $config);
        self::assertStringNotContainsString('exec(', $config);
        self::assertStringNotContainsString('xcopy', $config);
        self::assertStringNotContainsString('cp -r', $config);
    }
}
