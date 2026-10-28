<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Index;

use PHPUnit\Framework\TestCase;
use Weline\Database\Service\Index\SchemaIndexDeltaDetector;

/**
 * 索引增量探测器：一条查询取全库指纹 → 与上次快照比较 → 写 flagged 交给 auto_apply。
 */
final class SchemaIndexDeltaDetectorTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'weline_schema_index_delta_' . bin2hex(random_bytes(6)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->stateFile)) {
            @unlink($this->stateFile);
        }
    }

    public function testFirstRunTreatsEveryTableAsChangedAndFlagsThem(): void
    {
        $detector = $this->detector(new \ArrayObject([
            ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 10, 'UPDATE_TIME' => '2026-10-28 10:00:00'],
            ['TABLE_NAME' => 'w_b', 'TABLE_ROWS' => 0, 'UPDATE_TIME' => null],
        ]));

        self::assertSame(['w_a', 'w_b'], $detector->changedTables());
        self::assertSame([], $detector->flaggedTables(), '首次运行还没 remember，flagged 应为空');

        $detector->remember();

        self::assertSame(['w_a', 'w_b'], $detector->flaggedTables());
        self::assertSame([], $detector->changedTables(), 'remember 之后基线已推进，本轮应无增量');
        self::assertFileExists($this->stateFile);
    }

    public function testRowGrowthIsReportedAsDelta(): void
    {
        $this->seedState(['w_a' => ['rows' => 10, 'updated' => '2026-10-28 10:00:00']]);
        $rows = new \ArrayObject([
            ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 10, 'UPDATE_TIME' => '2026-10-28 10:00:00'],
        ]);
        $detector = $this->detector($rows);

        self::assertSame([], $detector->changedTables());

        $rows[0] = ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 17, 'UPDATE_TIME' => '2026-10-28 10:00:00'];
        self::assertSame(['w_a'], $detector->changedTables());

        $detector->remember();
        self::assertSame(['w_a'], $detector->flaggedTables());
        self::assertSame([], $detector->changedTables());
    }

    public function testUpdateTimeChangeIsReportedAsDelta(): void
    {
        $this->seedState(['w_a' => ['rows' => 10, 'updated' => '2026-10-28 10:00:00']]);
        $detector = $this->detector(new \ArrayObject([
            ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 10, 'UPDATE_TIME' => '2026-10-28 10:05:00'],
        ]));

        self::assertSame(['w_a'], $detector->changedTables());
    }

    public function testNewTableIsReportedAsDelta(): void
    {
        $this->seedState(['w_a' => ['rows' => 10, 'updated' => '2026-10-28 10:00:00']]);
        $detector = $this->detector(new \ArrayObject([
            ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 10, 'UPDATE_TIME' => '2026-10-28 10:00:00'],
            ['TABLE_NAME' => 'w_new', 'TABLE_ROWS' => 0, 'UPDATE_TIME' => null],
        ]));

        self::assertSame(['w_new'], $detector->changedTables());
    }

    public function testNullUpdateTimeOnlyComparesRowCount(): void
    {
        // MySQL 的 UPDATE_TIME 常为 NULL：同表同行数不得报增量，行数变化必须报。
        $this->seedState(['w_a' => ['rows' => 10, 'updated' => '']]);
        $rows = new \ArrayObject([
            ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 10, 'UPDATE_TIME' => null],
        ]);
        $detector = $this->detector($rows);

        self::assertSame([], $detector->changedTables());

        $rows[0] = ['TABLE_NAME' => 'w_a', 'TABLE_ROWS' => 11, 'UPDATE_TIME' => null];
        self::assertSame(['w_a'], $detector->changedTables());
    }

    public function testPgsqlStatShapeIsNormalized(): void
    {
        $this->seedState(['w_a' => ['rows' => 10, 'updated' => '2026-10-28 10:00:00+08']]);
        $detector = $this->detector(new \ArrayObject([
            ['relname' => 'w_a', 'n_live_tup' => 10, 'update_time' => '2026-10-28 10:00:00+08'],
        ]));

        self::assertSame([], $detector->changedTables());
    }

    public function testMarkProcessedRemovesFlaggedTables(): void
    {
        $this->seedState([], ['w_a', 'w_b']);
        $detector = $this->detector(new \ArrayObject([]));

        $detector->markProcessed(['w_a']);

        self::assertSame(['w_b'], $detector->flaggedTables());
    }

    public function testFlagTablesUnionsIdempotentlyAndKeepsExistingFlags(): void
    {
        $this->seedState(['w_a' => ['rows' => 1, 'updated' => '']], ['w_keep']);
        $detector = $this->detector(new \ArrayObject([]));

        $detector->flagTables(['w_keep', 'w_new', 'w_new', ' ']);

        self::assertSame(['w_keep', 'w_new'], $detector->flaggedTables());

        $detector->flagTables(['w_new']);
        self::assertSame(['w_keep', 'w_new'], $detector->flaggedTables(), '重复补标不得产生重复项');
    }

    public function testQueryFailureDegradesToNoDeltaWithoutThrowing(): void
    {
        $detector = new SchemaIndexDeltaDetector(
            null,
            $this->stateFile,
            static function (): array {
                throw new \RuntimeException('snapshot boom');
            },
        );

        self::assertSame([], $detector->snapshot());
        self::assertSame([], $detector->changedTables());
        $detector->remember();
        self::assertSame([], $detector->flaggedTables());
    }

    public function testSnapshotSqlCoversMysqlAndPgsql(): void
    {
        $mysql = SchemaIndexDeltaDetector::snapshotSql('mysql');
        self::assertStringContainsString('information_schema.TABLES', $mysql);
        self::assertStringContainsString('TABLE_SCHEMA = DATABASE()', $mysql);
        self::assertStringContainsString('UPDATE_TIME', $mysql);

        $pgsql = SchemaIndexDeltaDetector::snapshotSql('pgsql');
        self::assertStringContainsString('pg_stat_user_tables', $pgsql);
        self::assertStringContainsString('n_live_tup', $pgsql);
        self::assertStringContainsString('last_autoanalyze', $pgsql);

        $fallback = SchemaIndexDeltaDetector::snapshotSql('pgsql', true);
        self::assertStringContainsString('pg_class', $fallback);
        self::assertStringContainsString('reltuples', $fallback);
        self::assertStringContainsString('relpages', $fallback);
    }

    public function testUnsupportedDbTypeThrowsSoSnapshotCanDegrade(): void
    {
        $this->expectException(\RuntimeException::class);

        SchemaIndexDeltaDetector::snapshotSql('sqlite');
    }

    /**
     * @param array<string, mixed> $tables
     * @param list<string> $flagged
     */
    private function seedState(array $tables, array $flagged = []): void
    {
        file_put_contents($this->stateFile, (string)json_encode([
            'version' => 1,
            'snapshot_at' => '2026-10-28 10:00:00',
            'tables' => $tables,
            'flagged' => $flagged,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** @param \ArrayObject<int,array<string,mixed>> $rows */
    private function detector(\ArrayObject $rows): SchemaIndexDeltaDetector
    {
        return new SchemaIndexDeltaDetector(
            null,
            $this->stateFile,
            static fn(): array => $rows->getArrayCopy(),
        );
    }
}
