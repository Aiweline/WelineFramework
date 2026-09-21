<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\DbSchemaReader;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderRegistry;
use Weline\Framework\Database\Service\BackupService;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Handle;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Setup\Model\Migration;
use Weline\Framework\Setup\Stage\SchemaDiffStage;

final class SchemaDiffStageOwnershipTest extends TestCase
{
    private array $temporaryDatabases = [];
    private array $checkpointReads = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([OwnerAModel::class, OwnerBModel::class, ConflictingOwnerBModel::class] as $class) {
            $instance = new $class();
            \Weline\Framework\Manager\ObjectManager::setInstance($class, $instance);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDatabases as $path) {
            @unlink($path);
        }
        foreach ([OwnerAModel::class, OwnerBModel::class, ConflictingOwnerBModel::class] as $class) {
            \Weline\Framework\Manager\ObjectManager::removeInstance($class);
        }
        parent::tearDown();
    }

    public function testCurrentCheckpointOwnerAndEmptyCompatibilityModuleSurviveScanOrder(): void
    {
        $current = ['Test_A' => [], 'Test_B' => ['w_schema_owner_shared' => 'previous-fingerprint']];
        $first = $this->prepare(['Test_A', 'Test_B'], $current);
        $second = $this->prepare(['Test_B', 'Test_A'], $current);

        self::assertSame([], $first->getModuleSchemaFingerprints()['Test_A']);
        self::assertSame(['w_schema_owner_shared'], array_keys($first->getModuleSchemaFingerprints()['Test_B']));
        self::assertEquals($first->getModuleSchemaFingerprints(), $second->getModuleSchemaFingerprints());
        self::assertEquals($first->getModuleSchemaFingerprintCandidates(), $second->getModuleSchemaFingerprintCandidates());
        self::assertCount(1, $this->diffOps($first));
        self::assertSame(OwnerBModel::class, $this->diffOps($first)[0]->payload->modelClass);
    }

    public function testNewVersionKeepsPreviousCheckpointOwner(): void
    {
        $stage = $this->prepare(
            ['Test_A', 'Test_B'],
            ['Test_A' => []],
            ['Test_B' => ['w_schema_owner_shared' => 'older-fingerprint']],
        );
        self::assertSame([], $stage->getModuleSchemaFingerprints()['Test_A']);
        self::assertSame(['w_schema_owner_shared'], array_keys($stage->getModuleSchemaFingerprints()['Test_B']));
        self::assertSame(['Test_B'], $this->checkpointReads['previous']);
    }

    public function testCurrentEmptyCheckpointDoesNotResurrectHistoricalOwnership(): void
    {
        $stage = $this->prepare(
            ['Test_A', 'Test_B'],
            ['Test_A' => [], 'Test_B' => ['w_schema_owner_shared' => 'current-fingerprint']],
            ['Test_A' => ['w_schema_owner_shared' => 'obsolete-fingerprint']],
        );
        self::assertSame([], $stage->getModuleSchemaFingerprints()['Test_A']);
        self::assertSame([], $this->checkpointReads['previous']);
    }

    public function testFirstInstallationUsesStableOrderWhenNoCheckpointOwnsTheTable(): void
    {
        $forward = $this->prepare(['Test_A', 'Test_B']);
        $reverse = $this->prepare(['Test_B', 'Test_A']);
        self::assertSame(['w_schema_owner_shared'], array_keys($forward->getModuleSchemaFingerprints()['Test_A']));
        self::assertSame([], $reverse->getModuleSchemaFingerprints()['Test_B']);
        self::assertEquals($forward->getModuleSchemaFingerprints(), $reverse->getModuleSchemaFingerprints());
        self::assertSame(OwnerAModel::class, $this->diffOps($reverse)[0]->payload->modelClass);
    }

    public function testMultipleHistoricalOwnersKeepBothCheckpointMapsButOnlyOnePhysicalDiff(): void
    {
        $current = [
            'Test_A' => ['w_schema_owner_shared' => 'a-old-fingerprint'],
            'Test_B' => ['w_schema_owner_shared' => 'b-old-fingerprint'],
        ];
        $stage = $this->prepare(['Test_B', 'Test_A'], $current);
        $maps = $stage->getModuleSchemaFingerprints();
        self::assertSame(['w_schema_owner_shared'], array_keys($maps['Test_A']));
        self::assertSame($maps['Test_A'], $maps['Test_B']);
        self::assertCount(1, $this->diffOps($stage));
        self::assertSame(OwnerAModel::class, $this->diffOps($stage)[0]->payload->modelClass);
    }

    public function testDifferentSchemasOnOnePhysicalTableFailBeforePhysicalDiff(): void
    {
        $this->expectException(\Weline\Framework\App\Exception::class);
        $this->expectExceptionMessage('w_schema_owner_shared');
        // The assertion is about Schema conflict detection; preserve the real
        // formatter while preventing unrelated dictionary I/O during this error.
        $stateMethod = new \ReflectionMethod(\Weline\Framework\Phrase\Parser::class, 'requestState');
        $stateMethod->setAccessible(true);
        /** @var \Weline\Framework\Phrase\ParserRequestState $phraseState */
        $phraseState = $stateMethod->invoke(null);
        $previous = $phraseState->isLoadingWords;
        $phraseState->isLoadingWords = true;
        try {
            $this->prepare(['Test_A', 'Test_B'], [], [], true);
        } finally {
            $phraseState->isLoadingWords = $previous;
        }
    }

    public function testSameModuleProjectionsKeepTheFirstDeclarationWithoutOwnershipReads(): void
    {
        foreach ([
            [OwnerAModel::class, ConflictingOwnerBModel::class],
            [ConflictingOwnerBModel::class, OwnerAModel::class],
        ] as $models) {
            $stage = $this->prepare(['Test_A'], modelsByModule: ['Test_A' => $models]);
            self::assertCount(1, $this->diffOps($stage));
            self::assertSame($models[0], $this->diffOps($stage)[0]->payload->modelClass);
            self::assertSame(['current' => [], 'previous' => []], $this->checkpointReads);
        }
    }

    public function testSameModuleProjectionDoesNotChangeCrossModuleHistoricalOwner(): void
    {
        $current = ['Test_A' => [], 'Test_B' => ['w_schema_owner_shared' => 'previous-fingerprint']];
        $models = ['Test_A' => [OwnerAModel::class, ConflictingOwnerBModel::class]];
        $forward = $this->prepare(['Test_A', 'Test_B'], $current, modelsByModule: $models);
        $reverse = $this->prepare(['Test_B', 'Test_A'], $current, modelsByModule: $models);

        self::assertSame([], $forward->getModuleSchemaFingerprints()['Test_A']);
        self::assertSame(['w_schema_owner_shared'], array_keys($forward->getModuleSchemaFingerprints()['Test_B']));
        self::assertEquals($forward->getModuleSchemaFingerprints(), $reverse->getModuleSchemaFingerprints());
        self::assertCount(1, $this->diffOps($reverse));
        self::assertSame(OwnerBModel::class, $this->diffOps($reverse)[0]->payload->modelClass);
    }

    public function testUniqueTableDoesNotReadCheckpointOwnership(): void
    {
        $stage = $this->prepare(['Test_A']);
        self::assertCount(1, $this->diffOps($stage));
        self::assertSame(['current' => [], 'previous' => []], $this->checkpointReads);
    }

    private function prepare(array $order, array $current = [], array $previous = [], bool $conflict = false, array $modelsByModule = []): SchemaDiffStage
    {
        $this->checkpointReads = ['current' => [], 'previous' => []];
        $path = tempnam(sys_get_temp_dir(), 'weline-schema-owner-');
        $this->temporaryDatabases[] = $path;
        $connector = new Connector(new ConfigProvider([
            'type' => 'sqlite', 'database' => '', 'path' => $path, 'persistent' => false,
        ]));
        $factory = $this->createMock(ConnectionFactory::class);
        $factory->method('getConnector')->willReturn($connector);
        $handle = $this->createMock(Handle::class);
        $handle->method('getModules')->willReturn(array_map(
            static fn(string $name): array => ['name' => $name, 'version' => '2.0.0'],
            $order,
        ));
        $reader = $this->createMock(ModuleFileReader::class);
        $reader->method('readClass')->willReturnCallback(static fn($module): array => $modelsByModule[$module->getName()] ?? [
            $module->getName() === 'Test_A' ? OwnerAModel::class : ($conflict ? ConflictingOwnerBModel::class : OwnerBModel::class),
        ]);
        $migration = $this->createMock(Migration::class);
        $migration->method('getSchemaCheckpoint')->willReturnCallback(function (string $module, string $version) use ($current): ?array {
            $this->checkpointReads['current'][] = $module;
            return array_key_exists($module, $current) ? $this->checkpoint($module, $version, $current[$module]) : null;
        });
        $migration->method('getLatestSchemaCheckpointBefore')->willReturnCallback(function (string $module) use ($previous): ?array {
            $this->checkpointReads['previous'][] = $module;
            return array_key_exists($module, $previous) ? $this->checkpoint($module, '1.0.0', $previous[$module]) : null;
        });
        $executor = new SchemaMigrationExecutor(
            $this->createMock(EventsManager::class), $migration, $this->createMock(BackupService::class),
        );
        $stage = new SchemaDiffStage(
            $handle, $reader, $factory, new SchemaParser(), new DbSchemaReader(), new SchemaDiffEngine(),
            $executor, $this->createMock(Printing::class),
            new ShardSchemaFamilyProviderRegistry(manualFamilyProviders: [], scanExtends: false),
        );
        // 只验证 Schema 声明选择；异常格式化不触发无关词典数据库读取。
        $stateMethod = new \ReflectionMethod(\Weline\Framework\Phrase\Parser::class, 'requestState');
        $stateMethod->setAccessible(true);
        /** @var \Weline\Framework\Phrase\ParserRequestState $phraseState */
        $phraseState = $stateMethod->invoke(null);
        $previousLoading = $phraseState->isLoadingWords;
        $phraseState->isLoadingWords = true;
        try {
            $stage->prepare();
        } finally {
            $phraseState->isLoadingWords = $previousLoading;
        }
        return $stage;
    }

    private function checkpoint(string $module, string $version, array $tables): array
    {
        return [
            'migration_id' => 1, 'module_name' => $module, 'version' => $version,
            'format' => 2, 'checksum' => 'validated-by-Migration', 'tables' => $tables,
        ];
    }

    private function diffOps(SchemaDiffStage $stage): array
    {
        return (new \ReflectionProperty(SchemaDiffStage::class, 'diffOps'))->getValue($stage);
    }
}

abstract class OwnershipFixtureModel extends Model
{
    public function __construct() {}
    public function getTable(string $table = ''): string { return 'w_schema_owner_shared'; }
}

final class OwnerAModel extends OwnershipFixtureModel
{
    #[Col(type: 'int', nullable: false, primaryKey: true)]
    public const schema_fields_ID = 'id';
}

final class OwnerBModel extends OwnershipFixtureModel
{
    #[Col(type: 'int', nullable: false, primaryKey: true)]
    public const schema_fields_ID = 'id';
}

final class ConflictingOwnerBModel extends OwnershipFixtureModel
{
    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true)]
    public const schema_fields_ID = 'id';
}
