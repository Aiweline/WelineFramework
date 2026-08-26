<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Setup\Db\Migration;

use PHPUnit\Framework\TestCase;

final class CompareModeMigrationContractTest extends TestCase
{
    public function testMigrationTargetsCompareModeColumn(): void
    {
        $path = dirname(__DIR__, 5) . '/Setup/Db/Migration/add_eav_attribute_compare_mode_20260826-v1.2.0.php';
        self::assertFileExists($path);

        $source = (string)file_get_contents($path);
        self::assertStringContainsString('compare_mode', $source);
        self::assertStringContainsString('schema_fields_compare_mode', $source);
        self::assertStringContainsString('schema_table', $source);
        self::assertStringContainsString("DEFAULT 'none'", $source);
    }
}
