<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Service;

use PHPUnit\Framework\TestCase;

/**
 * Fresh install migrations may requireBackup on tables that do not exist yet.
 * Structure backup must persist existed=false and skip data, not throw.
 */
final class PhysicalBackupAbsentTableContractTest extends TestCase
{
    public function testAbsentPhysicalTableStructureIsPersistedNotRejected(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Database/Service/BackupService.php',
        );
        self::assertStringContainsString("\$result['strategy'] = 'absent'", $src);
        self::assertStringContainsString('weline.absent.table_snapshot.v1', $src);
        self::assertStringContainsString("\$payload['existed'] !== true", $src);
        self::assertStringContainsString('physicalTableExists($identity)', $src);
        self::assertDoesNotMatchRegularExpression(
            '/capturePhysicalTableSnapshot\(\$identity\)\s*;\s*'
            . '.*?empty\(\$payload\[\'existed\'\]\).*?return false;/s',
            $src,
        );
    }
}
