<?php

declare(strict_types=1);

namespace Weline\Database\Test\Unit\Service\Index;

use PHPUnit\Framework\TestCase;
use Weline\Database\Model\SchemaIndexJob;
use Weline\Database\Model\SchemaIndexState;
use Weline\Database\Service\Index\SchemaIndexChangeSink;
use Weline\Database\Service\Index\SchemaIndexConfig;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaIndexChangeSinkInterface;
use Weline\Framework\Database\Schema\SchemaParser;

final class SchemaIndexChangeSinkContractTest extends TestCase
{
    public function testInterfaceAndExecutorHookArePresent(): void
    {
        self::assertTrue(interface_exists(SchemaIndexChangeSinkInterface::class));
        self::assertTrue(method_exists(SchemaIndexChangeSinkInterface::class, 'handleIndexOp'));

        $executor = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Framework/Database/Schema/SchemaMigrationExecutor.php'
        );
        self::assertStringContainsString('SchemaIndexChangeSinkInterface', $executor);
        self::assertStringContainsString('handleIndexOp', $executor);
    }

    public function testJobAndStateModelsAreDeclarative(): void
    {
        $parser = new SchemaParser();

        $job = $parser->parse(SchemaIndexJob::class);
        self::assertNotNull($job);
        self::assertStringContainsString('weline_schema_index_job', $job->tableName);
        $jobFields = array_map(static fn($column): string => $column->name, $job->columns);
        self::assertContains('table_name', $jobFields);
        self::assertContains('index_name', $jobFields);
        self::assertContains('action', $jobFields);
        self::assertContains('definition_hash', $jobFields);
        self::assertContains('status', $jobFields);
        self::assertContains('definition_json', $jobFields);
        $jobIndexes = array_map(static fn($index): string => $index->name, $job->indexes);
        self::assertContains('uk_job_identity', $jobIndexes);
        self::assertContains('idx_job_status', $jobIndexes);

        $state = $parser->parse(SchemaIndexState::class);
        self::assertNotNull($state);
        self::assertStringContainsString('weline_schema_index_state', $state->tableName);
        $stateFields = array_map(static fn($column): string => $column->name, $state->columns);
        self::assertContains('table_name', $stateFields);
        self::assertContains('index_name', $stateFields);
        self::assertContains('status', $stateFields);
        self::assertContains('last_job_id', $stateFields);
        $stateIndexes = array_map(static fn($index): string => $index->name, $state->indexes);
        self::assertContains('uk_state_identity', $stateIndexes);
    }

    public function testDeferPolicyKeepsConstraintsAndStructuralTablesSynchronous(): void
    {
        $sink = $this->makeSink(true);
        $add = new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_INDEX,
            'w_i18n_locale_dictionary',
            new IndexDefinition('idx_locale_word', ['locale_code', 'word']),
        );

        self::assertTrue($sink->shouldDefer([$add], $add, true));
        self::assertFalse($sink->shouldDefer([$add], $add, false));

        $addColumn = new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_COLUMN,
            'w_i18n_locale_dictionary',
            null,
        );
        self::assertFalse($sink->shouldDefer([$add, $addColumn], $add, true));

        $unique = new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_INDEX,
            'w_i18n_locale_dictionary',
            new IndexDefinition('uk_locale_word', ['locale_code', 'word'], 'UNIQUE'),
        );
        self::assertFalse($sink->shouldDefer([$unique], $unique, true));

        $drop = new SchemaDiffOp(
            SchemaDiffOp::KIND_DROP_INDEX,
            'w_i18n_locale_dictionary',
            new IndexDefinition('idx_old', ['locale_code']),
        );
        self::assertTrue($sink->shouldDefer([$drop], $drop, true));
    }

    public function testDeferPolicyRespectsDisabledConfig(): void
    {
        $sink = $this->makeSink(false);
        $add = new SchemaDiffOp(
            SchemaDiffOp::KIND_ADD_INDEX,
            'w_i18n_locale_dictionary',
            new IndexDefinition('idx_locale_word', ['locale_code', 'word']),
        );

        self::assertFalse($sink->shouldDefer([$add], $add, true));
    }

    private function makeSink(bool $deferOnUpgrade): SchemaIndexChangeSink
    {
        $sink = (new \ReflectionClass(SchemaIndexChangeSink::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($sink, 'config'))->setValue(
            $sink,
            new SchemaIndexConfig(null, $deferOnUpgrade),
        );

        return $sink;
    }
}
