<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Schema;

\defined('BP') || \define('BP', \dirname(__DIR__, 8) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('DEV') || \define('DEV', false);
\defined('DEBUG') || \define('DEBUG', false);
\defined('SANDBOX') || \define('SANDBOX', false);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\Connection\Api\Sql\CreatesTableFromSchemaTrait;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\TimestampColumnOriginAuditor;
use Weline\Framework\Database\Schema\TimestampColumnOriginMarkers;
use Weline\Framework\Test\Unit\Database\Schema\Fixture\FrameworkBaseTimestampFixtureModel;

/**
 * C-RC-1 / C-RC-2 / C-RC-3 — dual-track timestamp origin markers (R-C C2).
 */
final class TimestampColumnOriginContractTest extends TestCase
{
    /** C-RC-1: declarative createTableFromSchema must call create(false). */
    public function testDeclarativeTraitLocksCreateFalse(): void
    {
        $path = (new \ReflectionClass(CreatesTableFromSchemaTrait::class))->getFileName();
        self::assertIsString($path);
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString(
            'create(false)',
            $source,
            'C-RC-1: CreatesTableFromSchemaTrait must pass create(false).',
        );
        self::assertMatchesRegularExpression(
            '/\$create->create\s*\(\s*false\s*\)/',
            $source,
            'C-RC-1: create(false) must be invoked on the Create builder.',
        );
    }

    /** C-RC-2: AbstractModel inherited timestamps → framework_base_timestamps, not pollution. */
    public function testInheritedAbstractModelTimestampsMarkFrameworkBaseNotPollution(): void
    {
        $auditor = new TimestampColumnOriginAuditor();
        $model = FrameworkBaseTimestampFixtureModel::class;

        self::assertSame(
            TimestampColumnOriginMarkers::FRAMEWORK_BASE,
            $auditor->classifyInheritedTimestamp($model, TimestampColumnOriginMarkers::CREATE_TIME),
        );
        self::assertSame(
            TimestampColumnOriginMarkers::FRAMEWORK_BASE,
            $auditor->classifyInheritedTimestamp($model, TimestampColumnOriginMarkers::UPDATE_TIME),
        );

        $audit = $auditor->auditModel($model);
        self::assertCount(2, $audit);
        foreach ($audit as $row) {
            self::assertSame(TimestampColumnOriginMarkers::FRAMEWORK_BASE, $row['marker']);
            self::assertFalse(
                $auditor->isUndeclaredPollution($row['marker']),
                'C-RC-2: framework_base_timestamps must not be undeclared pollution.',
            );
            self::assertNotSame(
                TimestampColumnOriginMarkers::UNDECLARED_POLLUTION,
                $row['marker'],
            );
        }

        self::assertSame(
            TimestampColumnOriginMarkers::FRAMEWORK_BASE,
            'framework_base_timestamps',
        );
    }

    /** C-RC-3: legacy create()/create(true) still injects; marker = legacy_implicit_timestamps. */
    public function testLegacyCreateInjectsTimestampsAndMarksLegacyImplicit(): void
    {
        $auditor = new TimestampColumnOriginAuditor();
        self::assertSame(
            TimestampColumnOriginMarkers::LEGACY_IMPLICIT,
            $auditor->markerForCreateCall(true),
        );
        self::assertNull($auditor->markerForCreateCall(false));
        self::assertSame(
            TimestampColumnOriginMarkers::LEGACY_IMPLICIT,
            'legacy_implicit_timestamps',
        );
        self::assertFalse(
            $auditor->isUndeclaredPollution(TimestampColumnOriginMarkers::LEGACY_IMPLICIT),
        );

        if (!\in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $dbPath = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline_rc_c3_' . \uniqid('', true) . '.sqlite';
        $connector = new Connector(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $dbPath,
            'persistent' => false,
        ]));

        try {
            $legacyTableName = 'w_rc_c3_legacy_default';
            $legacyCreate = $connector->createTable();
            $legacyCreate->createTable($legacyTableName);
            $legacyCreate->addColumn('id', 'int', null, 'PRIMARY KEY AUTO_INCREMENT', '');
            $legacyCreate->addAdditional('');
            $legacyCreate->create();

            $reader = new DbSchemaReader();
            $legacyTable = $reader->readTable($connector, $legacyTableName);
            $legacyColumns = \array_map(
                static fn(ColumnDefinition $column): string => $column->name,
                $legacyTable?->columns ?? [],
            );
            self::assertContains('create_time', $legacyColumns);
            self::assertContains('update_time', $legacyColumns);

            // Parallel to ShardSchemaProvisionerTest: legacy path keeps injection + dual-track marker.
            self::assertSame(
                TimestampColumnOriginMarkers::LEGACY_IMPLICIT,
                $auditor->markerForCreateCall(true),
                'C-RC-3: legacy create() injection must be tagged legacy_implicit_timestamps.',
            );
        } finally {
            $connector->close();
            if (\is_file($dbPath)) {
                \unlink($dbPath);
            }
        }
    }
}
