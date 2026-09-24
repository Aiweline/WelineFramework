<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup\Stage;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Setup\Model\MigrationBackup;
use Weline\Framework\Setup\Stage\FrameworkDbBootstrapStage;

final class MigrationBackupDataColumnWideningTest extends TestCase
{
    public function testModelDeclaresLongtextBackupData(): void
    {
        $attributes = (new \ReflectionClassConstant(MigrationBackup::class, 'schema_fields_BACKUP_DATA'))->getAttributes();
        self::assertNotEmpty($attributes);
        self::assertSame('longtext', $attributes[0]->getArguments()['type'] ?? null);
    }

    public function testMysqlTextBackupDataIsWidenedToLongtext(): void
    {
        $executed = $this->widen('mysql', 'text');
        self::assertCount(1, $executed);
        self::assertStringContainsString('MODIFY `backup_data` LONGTEXT', $executed[0]);
    }

    public function testAlreadyLongtextOrNonMysqlIsLeftAlone(): void
    {
        self::assertSame([], $this->widen('mysql', 'longtext'));
        self::assertSame([], $this->widen('pgsql', 'text'));
    }

    /** @return list<string> */
    private function widen(string $dbType, string $columnType): array
    {
        $executed = [];
        $config = $this->createMock(ConfigProviderInterface::class);
        $config->method('getDbType')->willReturn($dbType);
        $query = $this->createMock(QueryInterface::class);
        $connector = $this->getMockBuilder(ConnectorInterface::class)
            ->addMethods(['formatTableName'])
            ->getMockForAbstractClass();
        $connector->method('getConfigProvider')->willReturn($config);
        $connector->method('formatTableName')->willReturnCallback(static fn (string $t): string => '`' . $t . '`');
        $connector->method('query')->willReturnCallback(static function (string $sql) use (&$executed, $query) {
            $executed[] = $sql;
            return $query;
        });

        $stage = (new \ReflectionClass(FrameworkDbBootstrapStage::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(FrameworkDbBootstrapStage::class, 'widenMysqlBackupDataColumn'))->invoke(
            $stage,
            $connector,
            'weline_database_backups',
            [['name' => 'backup_id', 'type' => 'int'], ['name' => 'backup_data', 'type' => $columnType]],
        );

        return $executed;
    }
}
