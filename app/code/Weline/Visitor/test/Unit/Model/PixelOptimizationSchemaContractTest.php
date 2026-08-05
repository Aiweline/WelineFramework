<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Visitor\Model\Pixel;

/**
 * Guards the module-version boundary required for setup:upgrade to materialize
 * the PageBuilder optimization attribution schema from the Pixel model.
 */
final class PixelOptimizationSchemaContractTest extends TestCase
{
    public function testOptimizationAttributionSchemaIsDiscoverable(): void
    {
        $schema = (new SchemaParser())->parse(Pixel::class);
        self::assertNotNull($schema);

        $columnNames = array_column(array_map('get_object_vars', $schema->columns), 'name');
        foreach ([
            Pixel::schema_fields_ATTRIBUTION_VERSION,
            Pixel::schema_fields_PAGE_TYPE,
            Pixel::schema_fields_BLOCK_KEY,
            Pixel::schema_fields_PLAN_REVISION,
            Pixel::schema_fields_CONTENT_FINGERPRINT,
            Pixel::schema_fields_EXPERIMENT_ID,
            Pixel::schema_fields_VARIANT,
        ] as $field) {
            self::assertContains($field, $columnNames);
        }

        self::assertTrue($this->hasIndex(
            $schema->indexes,
            'idx_optimization_site_created',
            [Pixel::schema_fields_WEBSITE_ID, Pixel::schema_fields_ATTRIBUTION_VERSION, Pixel::schema_fields_CREATED_AT],
        ));
        self::assertTrue($this->hasIndex(
            $schema->indexes,
            'idx_optimization_block_created',
            [Pixel::schema_fields_WEBSITE_ID, Pixel::schema_fields_PAGE_TYPE, Pixel::schema_fields_BLOCK_KEY, Pixel::schema_fields_PLAN_REVISION, Pixel::schema_fields_CREATED_AT],
        ));
        self::assertTrue($this->hasIndex(
            $schema->indexes,
            'idx_optimization_fingerprint_created',
            [Pixel::schema_fields_WEBSITE_ID, Pixel::schema_fields_CONTENT_FINGERPRINT, Pixel::schema_fields_CREATED_AT],
        ));
        self::assertTrue($this->hasIndex(
            $schema->indexes,
            'idx_optimization_experiment_created',
            [Pixel::schema_fields_WEBSITE_ID, Pixel::schema_fields_EXPERIMENT_ID, Pixel::schema_fields_VARIANT, Pixel::schema_fields_CREATED_AT],
        ));
    }

    public function testModuleVersionAdvancesPastThePreAttributionSchemaCheckpoint(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $module = require $moduleRoot . '/etc/module.php';
        $registerSource = \file_get_contents($moduleRoot . '/register.php');

        self::assertSame('Weline_Visitor', $module['name']);
        self::assertSame('1.0.5', (string)$module['version']);
        self::assertIsString($registerSource);
        self::assertMatchesRegularExpression(
            "/'Weline_Visitor'\\s*,\\s*__DIR__\\s*,\\s*'1\\.0\\.5'/s",
            $registerSource,
            'Register and module metadata must expose the same upgrade version so setup:upgrade cannot stop at 1.0.4.',
        );
        self::assertTrue(
            \version_compare((string)$module['version'], '1.0.4', '>'),
            'Pixel optimization attribution schema must advance the module version beyond the existing 1.0.4 checkpoint.',
        );
    }

    /** @param list<object> $indexes @param list<string> $columns */
    private function hasIndex(array $indexes, string $name, array $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index->name === $name && $index->columns === $columns) {
                return true;
            }
        }

        return false;
    }
}
