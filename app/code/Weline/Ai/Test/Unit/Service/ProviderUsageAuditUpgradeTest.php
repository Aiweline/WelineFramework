<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\Provider\UsageRecord;
use Weline\Ai\Service\Provider\UsageAuditLegacyBackfill;
use Weline\Ai\Setup\Db\Migration\ProviderUsageAuditIdentity20260730V111;
use Weline\Framework\Database\Schema\SchemaParser;

final class ProviderUsageAuditUpgradeTest extends TestCase
{
    public function testHistoricalRowsAreBackfilledWithoutDeletingOrRenamingDuplicateRequestIds(): void
    {
        $planner = new UsageAuditLegacyBackfill();
        $rows = [
            $this->row(5, 'request-conflict', 21, 0.000029),
            $this->row(2, 'request-duplicate', 20, 0.000028),
            $this->row(1, 'request-unique', 20, 0.000028),
            $this->row(4, 'request-conflict', 20, 0.000028),
            $this->row(3, 'request-duplicate', 20, 0.000028),
            $this->row(6, '', 20, 0.000028),
        ];

        $updates = $planner->plan($rows);

        self::assertSame([1, 2, 3, 4, 5, 6], array_keys($updates));
        self::assertSame('canonical', $updates[1][UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS]);
        self::assertSame(hash('sha256', 'request-unique'), $updates[1][UsageRecord::schema_fields_REQUEST_KEY]);
        self::assertSame('canonical', $updates[2][UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS]);
        self::assertSame(hash('sha256', 'request-duplicate'), $updates[2][UsageRecord::schema_fields_REQUEST_KEY]);
        self::assertSame('legacy_duplicate', $updates[3][UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS]);
        self::assertNull($updates[3][UsageRecord::schema_fields_REQUEST_KEY]);
        self::assertSame('legacy_conflict', $updates[4][UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS]);
        self::assertSame(hash('sha256', 'request-conflict'), $updates[4][UsageRecord::schema_fields_REQUEST_KEY]);
        self::assertSame(
            'legacy_conflict_duplicate',
            $updates[5][UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS],
        );
        self::assertNull($updates[5][UsageRecord::schema_fields_REQUEST_KEY]);
        self::assertSame('legacy_missing', $updates[6][UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS]);
        self::assertNull($updates[6][UsageRecord::schema_fields_REQUEST_KEY]);

        foreach ($updates as $update) {
            self::assertSame(1, $update[UsageRecord::schema_fields_BALANCE_APPLIED]);
            self::assertArrayNotHasKey(
                UsageRecord::schema_fields_REQUEST_ID,
                $update,
                'The migration must preserve every historical request_id and row.',
            );
        }
        $nonNullKeys = array_values(array_filter(array_column(
            $updates,
            UsageRecord::schema_fields_REQUEST_KEY,
        )));
        self::assertSame($nonNullKeys, array_values(array_unique($nonNullKeys)));
    }

    public function testInterruptedDuplicateGroupBackfillResumesWithoutReassigningCanonicalKey(): void
    {
        $planner = new UsageAuditLegacyBackfill();
        $canonical = $this->row(7, 'request-resume', 20, 0.000028);
        $canonical[UsageRecord::schema_fields_REQUEST_KEY] = hash('sha256', 'request-resume');
        $canonical[UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS] =
            UsageRecord::REQUEST_IDENTITY_CANONICAL;
        $canonical[UsageRecord::schema_fields_BALANCE_APPLIED] = 1;
        $pendingDuplicate = $this->row(8, 'request-resume', 20, 0.000028);

        $updates = $planner->planUnapplied([$canonical, $pendingDuplicate]);

        self::assertSame([8], array_keys($updates));
        self::assertNull($updates[8][UsageRecord::schema_fields_REQUEST_KEY]);
        self::assertSame(
            UsageRecord::REQUEST_IDENTITY_LEGACY_DUPLICATE,
            $updates[8][UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS],
        );
        self::assertSame(1, $updates[8][UsageRecord::schema_fields_BALANCE_APPLIED]);
    }

    /**
     * @dataProvider portableDriverProvider
     */
    public function testSchemaUpgradeContractIsPortableForSqliteAndMysql(string $driver): void
    {
        $schema = (new SchemaParser())->parse(UsageRecord::class);
        self::assertNotNull($schema, $driver);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }
        self::assertArrayHasKey(UsageRecord::schema_fields_REQUEST_KEY, $columns, $driver);
        self::assertSame('varchar', $columns[UsageRecord::schema_fields_REQUEST_KEY]->type, $driver);
        self::assertSame(64, $columns[UsageRecord::schema_fields_REQUEST_KEY]->length, $driver);
        self::assertTrue($columns[UsageRecord::schema_fields_REQUEST_KEY]->nullable, $driver);
        self::assertArrayHasKey(UsageRecord::schema_fields_REQUEST_IDENTITY_STATUS, $columns, $driver);

        $indexes = [];
        foreach ($schema->indexes as $index) {
            $indexes[$index->name] = $index;
        }
        self::assertArrayHasKey('uniq_request_key', $indexes, $driver);
        self::assertSame('UNIQUE', $indexes['uniq_request_key']->type, $driver);
        self::assertSame(
            [UsageRecord::schema_fields_REQUEST_KEY],
            $indexes['uniq_request_key']->columns,
            $driver,
        );
        self::assertArrayNotHasKey(
            'uniq_request_id',
            $indexes,
            $driver . ' must not create a pre-backfill unique index over historical duplicate request_id rows.',
        );
    }

    /** @return iterable<string,array{string}> */
    public static function portableDriverProvider(): iterable
    {
        yield 'SQLite declarative schema' => ['sqlite'];
        yield 'MySQL declarative schema' => ['mysql'];
    }

    public function testDataMigrationMetadataKeepsSchemaWorkInDeclarativeModel(): void
    {
        require_once BP
            . 'app/code/Weline/Ai/Setup/Db/Migration/'
            . 'provider_usage_audit_identity_20260730-v1.1.1.php';
        $migration = new ProviderUsageAuditIdentity20260730V111(
            new UsageAuditLegacyBackfill(),
        );

        self::assertSame('1.1.1', $migration->getVersion());
        self::assertSame('data_migration', $migration->getType());
        self::assertSame([UsageRecord::schema_table], $migration->getAffectedTables());
        self::assertTrue($migration->requiresBackup());
        self::assertSame('column', $migration->getBackupStrategy()['strategy']);
    }

    public function testModuleMetadataVersionMatchesUsageAuditMigrationVersion(): void
    {
        $module = require BP . 'app/code/Weline/Ai/etc/module.php';
        $register = (string)file_get_contents(BP . 'app/code/Weline/Ai/register.php');

        self::assertSame('1.1.1', $module['version'] ?? null);
        self::assertMatchesRegularExpression(
            "/'Weline_Ai',\\s*__DIR__,\\s*'1\\.1\\.1'/s",
            $register,
        );
    }

    public function testBackfillCasUsesRawAdapterAffectedRowsInsteadOfModelFetchObject(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Ai/Service/Provider/UsageAuditLegacyBackfill.php',
        );

        self::assertStringContainsString(
            '->limit(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)',
            $source,
        );
        self::assertStringNotContainsString('->pagination(', $source);
        self::assertStringContainsString('$updateQuery = $updateModel->getQuery(false);', $source);
        self::assertStringContainsString('$statement->rowCount() === 1', $source);
        self::assertStringNotContainsString(
            '$changed = $updateModel->clear()',
            $source,
        );
    }

    /** @return array<string,mixed> */
    private function row(int $id, string $requestId, int $totalTokens, float $totalCost): array
    {
        return [
            UsageRecord::schema_fields_ID => $id,
            UsageRecord::schema_fields_REQUEST_ID => $requestId,
            UsageRecord::schema_fields_ACCOUNT_ID => 73,
            UsageRecord::schema_fields_PROVIDER_CODE => 'deepseek',
            UsageRecord::schema_fields_MODEL_CODE => 'deepseek-chat',
            UsageRecord::schema_fields_REQUEST_TYPE => 'pagebuilder_block',
            UsageRecord::schema_fields_PROMPT_TOKENS => 12,
            UsageRecord::schema_fields_COMPLETION_TOKENS => 8,
            UsageRecord::schema_fields_TOTAL_TOKENS => $totalTokens,
            UsageRecord::schema_fields_INPUT_COST => 0.000012,
            UsageRecord::schema_fields_OUTPUT_COST => 0.000016,
            UsageRecord::schema_fields_TOTAL_COST => $totalCost,
            UsageRecord::schema_fields_CURRENCY => 'USD',
            UsageRecord::schema_fields_STATUS => 'success',
            UsageRecord::schema_fields_BALANCE_APPLIED => 0,
        ];
    }
}
