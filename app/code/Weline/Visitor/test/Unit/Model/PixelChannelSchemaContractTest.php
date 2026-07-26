<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Visitor\Model\PixelChannel;

/**
 * B01：PixelChannel schema 契约（不依赖 setup:upgrade / 真实库表）。
 */
final class PixelChannelSchemaContractTest extends TestCase
{
    public function testSchemaDeclaresTableAndCompositeUniqueKey(): void
    {
        self::assertSame('pixel_channel', PixelChannel::schema_table);
        self::assertSame('pixel_channel_id', PixelChannel::schema_primary_key);

        $parser = new SchemaParser();
        $schema = $parser->parse(PixelChannel::class);
        self::assertNotNull($schema);

        $columnNames = array_column(array_map('get_object_vars', $schema->columns), 'name');
        foreach ([
            PixelChannel::schema_fields_ID,
            PixelChannel::schema_fields_KIND,
            PixelChannel::schema_fields_CODE,
            PixelChannel::schema_fields_NAME,
            PixelChannel::schema_fields_TRAFFIC_TYPE,
            PixelChannel::schema_fields_UTM_SOURCE,
            PixelChannel::schema_fields_UTM_MEDIUM,
            PixelChannel::schema_fields_UTM_CAMPAIGN,
            PixelChannel::schema_fields_MATCH_MODE,
            PixelChannel::schema_fields_MATCH_VALUE,
            PixelChannel::schema_fields_PRIORITY,
            PixelChannel::schema_fields_ENABLED,
            PixelChannel::schema_fields_WEBSITE_ID,
            PixelChannel::schema_fields_DESCRIPTION,
            PixelChannel::schema_fields_CREATED_AT,
        ] as $field) {
            self::assertContains($field, $columnNames);
        }

        self::assertTrue($this->hasUniqueIndex(
            $schema->indexes,
            'uk_pixel_channel_website_code',
            [PixelChannel::schema_fields_WEBSITE_ID, PixelChannel::schema_fields_CODE],
        ));
    }

    public function testKindTrafficAndMatchModeEnumsAreStable(): void
    {
        self::assertSame(['campaign', 'rule'], PixelChannel::KINDS);
        self::assertContains(PixelChannel::TRAFFIC_PAID, PixelChannel::TRAFFIC_TYPES);
        self::assertContains(PixelChannel::TRAFFIC_SOCIAL, PixelChannel::TRAFFIC_TYPES);
        self::assertContains(PixelChannel::MATCH_REFERER_HOST, PixelChannel::MATCH_MODES);
        self::assertContains(PixelChannel::MATCH_CLICK_ID, PixelChannel::MATCH_MODES);
    }

    public function testModelControllerValidationAndCreateServiceExist(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertFileExists($root . '/Model/PixelChannel.php');
        self::assertFileExists($root . '/Service/PixelChannelValidationService.php');
        self::assertFileExists($root . '/Service/PixelChannelCreateService.php');
        self::assertFileExists($root . '/Controller/Backend/TrafficChannel.php');
        $src = (string)\file_get_contents($root . '/Controller/Backend/TrafficChannel.php');
        self::assertStringContainsString('function index', $src);
        self::assertStringContainsString('function getAdd', $src);
        self::assertStringContainsString('function postAdd', $src);
    }

    /** @param list<IndexDefinition> $indexes @param list<string> $columns */
    private function hasUniqueIndex(array $indexes, string $name, array $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index->name === $name && $index->type === 'UNIQUE' && $index->columns === $columns) {
                return true;
            }
        }

        return false;
    }
}
