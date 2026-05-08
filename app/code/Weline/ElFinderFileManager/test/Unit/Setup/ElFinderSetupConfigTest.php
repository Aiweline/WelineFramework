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

        self::assertStringContainsString('is_dir($vendor_path)', $config);
        self::assertStringContainsString('RecursiveIteratorIterator', $config);
        self::assertStringNotContainsString('exec(', $config);
        self::assertStringNotContainsString('xcopy', $config);
        self::assertStringNotContainsString('cp -r', $config);
    }
}
