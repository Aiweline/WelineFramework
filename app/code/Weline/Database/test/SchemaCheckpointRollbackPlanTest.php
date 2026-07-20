<?php

declare(strict_types=1);

namespace Weline\Database\test;

use Weline\Database\Model\Migration;
use Weline\Database\Service\SchemaRollbackService;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;

final class SchemaCheckpointRollbackPlanTest extends TestCore
{
    private const MODULE_PREFIX = 'Weline_CheckpointTest';

    private Migration $migration;
    private SchemaRollbackService $schemaRollbackService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = ObjectManager::getInstance(Migration::class, [], false);
        $this->schemaRollbackService = ObjectManager::getInstance(SchemaRollbackService::class, [], false);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testCheckpointIsIdempotentAndImmutablePerModuleVersion(): void
    {
        $module = self::MODULE_PREFIX . '_Immutable';
        $tables = ['checkpoint_table' => hash('sha256', 'schema-v1')];

        $firstId = $this->migration->recordSchemaCheckpoint($module, '1.0.0', $tables);
        $secondId = $this->migration->recordSchemaCheckpoint($module, '1.0.0', $tables);

        self::assertGreaterThan(0, $firstId);
        self::assertSame($firstId, $secondId);
        self::assertSame($tables, $this->migration->getSchemaCheckpoint($module, '1.0.0')['tables'] ?? []);

        $this->expectException(\RuntimeException::class);
        $this->migration->recordSchemaCheckpoint(
            $module,
            '1.0.0',
            ['checkpoint_table' => hash('sha256', 'schema-mutated-without-version-bump')],
        );
    }

    public function testCompleteSchemaChainIsAcceptedAndCheckpointed(): void
    {
        $module = self::MODULE_PREFIX . '_Complete';
        $table = 'checkpoint_complete';
        $before = hash('sha256', 'schema-v1');
        $after = hash('sha256', 'schema-v2');
        $this->migration->recordSchemaCheckpoint($module, '1.0.0', [$table => $before]);
        $migrationId = $this->recordInstalledSchemaDdl($module, $table, $before, $after);
        $this->migration->recordSchemaCheckpoint($module, '1.1.0', [$table => $after]);

        $plan = $this->schemaRollbackService->createPlan($module, '1.0.0', '1.1.0');

        self::assertSame([], $plan['blockers']);
        self::assertCount(1, $plan['operations']);
        self::assertSame($migrationId, $plan['operations'][0]['migration_id']);
        self::assertSame('1.1.0', $plan['checkpoints']['current']['version'] ?? null);
        self::assertSame('1.0.0', $plan['checkpoints']['target']['version'] ?? null);
    }

    public function testDifferentCheckpointsWithoutReverseDdlAreBlockedDuringPreflight(): void
    {
        $module = self::MODULE_PREFIX . '_Incomplete';
        $table = 'checkpoint_incomplete';
        $this->migration->recordSchemaCheckpoint($module, '1.0.0', [
            $table => hash('sha256', 'schema-v1'),
        ]);
        $this->migration->recordSchemaCheckpoint($module, '1.1.0', [
            $table => hash('sha256', 'schema-v2'),
        ]);

        $plan = $this->schemaRollbackService->createPlan($module, '1.0.0', '1.1.0');

        self::assertSame([], $plan['operations']);
        self::assertNotSame([], $plan['blockers']);
        self::assertStringContainsString('无法重建目标 checkpoint', implode('\n', $plan['blockers']));
    }

    public function testIdenticalCheckpointsNeedNoDdl(): void
    {
        $module = self::MODULE_PREFIX . '_Noop';
        $tables = ['checkpoint_noop' => hash('sha256', 'same-schema')];
        $this->migration->recordSchemaCheckpoint($module, '1.0.0', $tables);
        $this->migration->recordSchemaCheckpoint($module, '1.1.0', $tables);

        $plan = $this->schemaRollbackService->createPlan($module, '1.0.0', '1.1.0');

        self::assertSame([], $plan['blockers']);
        self::assertSame([], $plan['operations']);
        self::assertNotSame([], $plan['warnings']);
    }

    private function recordInstalledSchemaDdl(
        string $module,
        string $table,
        string $before,
        string $after,
    ): int {
        $migrationId = $this->migration->recordSchemaDdl(
            $module,
            $table,
            'default',
            'ALTER TABLE checkpoint_complete ADD COLUMN rollback_value VARCHAR(64)',
            'ALTER TABLE checkpoint_complete DROP COLUMN rollback_value',
            self::class,
            '1.1.0',
            'schema-checkpoint-test-batch',
            1,
            SchemaDiffOp::KIND_ADD_COLUMN,
            $before,
            $after,
            '',
            ['name' => 'rollback_value'],
        );
        self::assertGreaterThan(0, $migrationId);
        self::assertTrue($this->migration->updateStatus(Migration::STATUS_INSTALLED));
        return $migrationId;
    }

    private function cleanup(): void
    {
        ObjectManager::getInstance(Migration::class, [], false)
            ->reset()
            ->where(Migration::schema_fields_MODULE, self::MODULE_PREFIX . '%', 'LIKE')
            ->delete()
            ->fetch();
    }
}
