<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Setup\Db\Migration;

use PHPUnit\Framework\TestCase;

final class FixEavLocalDescriptionCompositePkContractTest extends TestCase
{
    public function testMigrationUsesPrefixedModelTablesAndCompositePk(): void
    {
        $path = dirname(__DIR__, 5) . '/Setup/Db/Migration/fix_eav_local_description_composite_pk_20260827-v1.2.3.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('GroupLocalDescription::class', $source);
        self::assertStringContainsString('getTable()', $source);
        self::assertStringContainsString('PRIMARY KEY (id, local_code)', $source);
        self::assertStringContainsString('DROP DEFAULT', $source);
        self::assertStringContainsString('hasCompositePrimaryKey', $source);
        // Must not target unprefixed bare names that skip m_ tables on prod.
        self::assertStringNotContainsString("'eav_attribute_group_local_description'", $source);
    }
}
