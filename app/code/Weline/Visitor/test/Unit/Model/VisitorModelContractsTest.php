<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelChannel;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllVisitorModelContractsPass().
 */
final class VisitorModelContractsTest extends TestCase
{
    public function testAllVisitorModelContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'PixelChannelSchema::testSchemaDeclaresTableAndCompositeUniqueKey' => function () {
            $this->assert_PixelChannelSchema_testSchemaDeclaresTableAndCompositeUniqueKey();
        },
            'PixelChannelSchema::testKindTrafficAndMatchModeEnumsAreStable' => function () {
            $this->assert_PixelChannelSchema_testKindTrafficAndMatchModeEnumsAreStable();
        },
            'PixelChannelSchema::testModelControllerValidationAndCreateServiceExist' => function () {
            $this->assert_PixelChannelSchema_testModelControllerValidationAndCreateServiceExist();
        },
            'PixelOptimizationSchema::testOptimizationAttributionSchemaIsDiscoverable' => function () {
            $this->assert_PixelOptimizationSchema_testOptimizationAttributionSchemaIsDiscoverable();
        },
            'PixelOptimizationSchema::testModuleVersionAdvancesPastThePreAttributionSchemaCheckpoint' => function () {
            $this->assert_PixelOptimizationSchema_testModuleVersionAdvancesPastThePreAttributionSchemaCheckpoint();
        },
        ];
    }

private function assert_PixelChannelSchema_testSchemaDeclaresTableAndCompositeUniqueKey(): void
{
    self::assertSame('pixel_channel', PixelChannel::schema_table);
    self::assertSame('pixel_channel_id', PixelChannel::schema_primary_key);
    $parser = new SchemaParser();
    $schema = $parser->parse(PixelChannel::class);
    self::assertNotNull($schema);
    $columnNames = array_column(array_map('get_object_vars', $schema->columns), 'name');
    foreach ([PixelChannel::schema_fields_ID, PixelChannel::schema_fields_KIND, PixelChannel::schema_fields_CODE, PixelChannel::schema_fields_NAME, PixelChannel::schema_fields_TRAFFIC_TYPE, PixelChannel::schema_fields_UTM_SOURCE, PixelChannel::schema_fields_UTM_MEDIUM, PixelChannel::schema_fields_UTM_CAMPAIGN, PixelChannel::schema_fields_MATCH_MODE, PixelChannel::schema_fields_MATCH_VALUE, PixelChannel::schema_fields_PRIORITY, PixelChannel::schema_fields_ENABLED, PixelChannel::schema_fields_WEBSITE_ID, PixelChannel::schema_fields_DESCRIPTION, PixelChannel::schema_fields_CREATED_AT] as $field) {
        self::assertContains($field, $columnNames);
    }
    self::assertTrue($this->hasUniqueIndex_PixelChannelSchema($schema->indexes, 'uk_pixel_channel_website_code', [PixelChannel::schema_fields_WEBSITE_ID, PixelChannel::schema_fields_CODE]));
}

private function assert_PixelChannelSchema_testKindTrafficAndMatchModeEnumsAreStable(): void
{
    self::assertSame(['campaign', 'rule'], PixelChannel::KINDS);
    self::assertContains(PixelChannel::TRAFFIC_PAID, PixelChannel::TRAFFIC_TYPES);
    self::assertContains(PixelChannel::TRAFFIC_SOCIAL, PixelChannel::TRAFFIC_TYPES);
    self::assertContains(PixelChannel::MATCH_REFERER_HOST, PixelChannel::MATCH_MODES);
    self::assertContains(PixelChannel::MATCH_CLICK_ID, PixelChannel::MATCH_MODES);
}

private function assert_PixelChannelSchema_testModelControllerValidationAndCreateServiceExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    self::assertFileExists($root . '/Model/PixelChannel.php');
    self::assertFileExists($root . '/Service/PixelChannelValidationService.php');
    self::assertFileExists($root . '/Service/PixelChannelCreateService.php');
    self::assertFileExists($root . '/Controller/Backend/TrafficChannel.php');
    $src = (string) \file_get_contents($root . '/Controller/Backend/TrafficChannel.php');
    self::assertStringContainsString('function index', $src);
    self::assertStringContainsString('function getAdd', $src);
    self::assertStringContainsString('function postAdd', $src);
}

/** @param list<IndexDefinition> $indexes @param list<string> $columns */
private function hasUniqueIndex_PixelChannelSchema(array $indexes, string $name, array $columns): bool
{
    foreach ($indexes as $index) {
        if ($index->name === $name && $index->type === 'UNIQUE' && $index->columns === $columns) {
            return true;
        }
    }
    return false;
}

private function assert_PixelOptimizationSchema_testOptimizationAttributionSchemaIsDiscoverable(): void
{
    $schema = (new SchemaParser())->parse(Pixel::class);
    self::assertNotNull($schema);
    $columnNames = array_column(array_map('get_object_vars', $schema->columns), 'name');
    foreach ([Pixel::schema_fields_ATTRIBUTION_VERSION, Pixel::schema_fields_PAGE_TYPE, Pixel::schema_fields_BLOCK_KEY, Pixel::schema_fields_PLAN_REVISION, Pixel::schema_fields_CONTENT_FINGERPRINT, Pixel::schema_fields_EXPERIMENT_ID, Pixel::schema_fields_VARIANT] as $field) {
        self::assertContains($field, $columnNames);
    }
    self::assertTrue($this->hasIndex_PixelOptimizationSchema($schema->indexes, 'idx_optimization_site_created', [Pixel::schema_fields_WEBSITE_ID, Pixel::schema_fields_ATTRIBUTION_VERSION, Pixel::schema_fields_CREATED_AT]));
    self::assertTrue($this->hasIndex_PixelOptimizationSchema($schema->indexes, 'idx_optimization_block_created', [Pixel::schema_fields_WEBSITE_ID, Pixel::schema_fields_PAGE_TYPE, Pixel::schema_fields_BLOCK_KEY, Pixel::schema_fields_PLAN_REVISION, Pixel::schema_fields_CREATED_AT]));
    self::assertTrue($this->hasIndex_PixelOptimizationSchema($schema->indexes, 'idx_optimization_fingerprint_created', [Pixel::schema_fields_WEBSITE_ID, Pixel::schema_fields_CONTENT_FINGERPRINT, Pixel::schema_fields_CREATED_AT]));
    self::assertTrue($this->hasIndex_PixelOptimizationSchema($schema->indexes, 'idx_optimization_experiment_created', [Pixel::schema_fields_WEBSITE_ID, Pixel::schema_fields_EXPERIMENT_ID, Pixel::schema_fields_VARIANT, Pixel::schema_fields_CREATED_AT]));
}

private function assert_PixelOptimizationSchema_testModuleVersionAdvancesPastThePreAttributionSchemaCheckpoint(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $module = require $moduleRoot . '/etc/module.php';
    $registerSource = \file_get_contents($moduleRoot . '/register.php');
    self::assertSame('Weline_Visitor', $module['name']);
    self::assertSame('1.0.6', (string) $module['version']);
    self::assertIsString($registerSource);
    self::assertMatchesRegularExpression("/'Weline_Visitor'\\s*,\\s*__DIR__\\s*,\\s*'1\\.0\\.6'/s", $registerSource, 'Register and module metadata must expose the same upgrade version so setup:upgrade cannot stop at 1.0.4.');
    self::assertTrue(\version_compare((string) $module['version'], '1.0.5', '>'), 'Pixel optimization attribution schema must advance the module version beyond the existing 1.0.5 checkpoint.');
}

/** @param list<object> $indexes @param list<string> $columns */
private function hasIndex_PixelOptimizationSchema(array $indexes, string $name, array $columns): bool
{
    foreach ($indexes as $index) {
        if ($index->name === $name && $index->columns === $columns) {
            return true;
        }
    }
    return false;
}
}
