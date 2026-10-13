<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\I18n\Api\Localization\LocalModel;
use Weline\Inventory\Model\WarehouseCodeLabel\LocalDescription as WarehouseCodeLabelLocal;
use Weline\Websites\Model\Website\LocalDescription as WebsiteLocalDescription;

/**
 * Contract: LocalModel declared primary key MUST include local_code when that
 * column exists — matching physical PRIMARY (entity_id, local_code).
 *
 * Prevents SchemaDiff "physical primary key differs from declared primary key;
 * refuse silent rewrite" after webhook setup:upgrade (whack-a-mole per table).
 */
final class SchemaLocalModelDeclaredPkContractTest extends TestCase
{
    public function testLocalModelSingleSchemaPrimaryKeyIncludesLocalCode(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(SchemaParserLocalModelSinglePkFixture::class);

        self::assertNotNull($schema);

        $declaredPks = [];
        foreach ($schema->columns as $column) {
            if ($column->primaryKey) {
                $declaredPks[] = $column->name;
            }
        }

        self::assertSame(['label_id', 'local_code'], $declaredPks);
    }

    public function testLocalModelWithoutLocaleColPrimaryKeyDoesNotExpand(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(SchemaParserLocalModelFieldsIdFixture::class);

        self::assertNotNull($schema);

        $declaredPks = [];
        foreach ($schema->columns as $column) {
            if ($column->primaryKey) {
                $declaredPks[] = $column->name;
            }
        }

        // local_code Col lacks primaryKey — surrogate/entity-only PK stays (Currency shape).
        self::assertSame(['entity_id'], $declaredPks);
    }

    public function testCurrencyLocalDescriptionKeepsSurrogatePrimaryOnly(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(\Weline\Currency\Model\Currency\LocalDescription::class);

        self::assertNotNull($schema);

        $declaredPks = [];
        foreach ($schema->columns as $column) {
            if ($column->primaryKey) {
                $declaredPks[] = $column->name;
            }
        }

        self::assertSame(['local_description_id'], $declaredPks);
    }

    public function testNonLocalModelWithLocalCodeColumnDoesNotAutoExpandPk(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(SchemaParserNonLocalWithLocalCodeFixture::class);

        self::assertNotNull($schema);

        $declaredPks = [];
        foreach ($schema->columns as $column) {
            if ($column->primaryKey) {
                $declaredPks[] = $column->name;
            }
        }

        self::assertSame(['row_id'], $declaredPks);
        self::assertFalse(
            (static function () use ($schema): bool {
                foreach ($schema->columns as $column) {
                    if ($column->name === 'local_code') {
                        return $column->primaryKey;
                    }
                }

                return false;
            })()
        );
    }

    public function testWarehouseCodeLabelLocalDeclaredPkMatchesPhysicalComposite(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(WarehouseCodeLabelLocal::class);

        self::assertNotNull($schema);

        $declaredPks = [];
        foreach ($schema->columns as $column) {
            if ($column->primaryKey) {
                $declaredPks[] = $column->name;
            }
        }

        self::assertSame(['label_id', 'local_code'], $declaredPks);
    }

    public function testWebsiteLocalDescriptionKeepsExplicitCompositePk(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(WebsiteLocalDescription::class);

        self::assertNotNull($schema);

        $declaredPks = [];
        foreach ($schema->columns as $column) {
            if ($column->primaryKey) {
                $declaredPks[] = $column->name;
            }
        }

        self::assertSame(['website_id', 'local_code'], $declaredPks);
    }
}

/**
 * Mirrors Inventory WarehouseCodeLabel LocalDescription: schema_primary_key=entity only,
 * Col primaryKey on both entity + local_code — parser must still declare composite PK.
 */
class SchemaParserLocalModelSinglePkFixture extends LocalModel
{
    public const schema_table = 'schema_parser_local_model_single_pk';
    public const schema_primary_key = 'label_id';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: 'Label ID')]
    public const schema_fields_ID = 'label_id';

    #[Col(type: 'varchar', length: 20, nullable: false, primaryKey: true, comment: 'Locale code')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Localized name')]
    public const schema_fields_LABEL_NAME = 'label_name';
}

/** schema_fields_ID alone; local_code Col without primaryKey → no auto-expand (Currency shape). */
class SchemaParserLocalModelFieldsIdFixture extends LocalModel
{
    public const schema_table = 'schema_parser_local_model_fields_id';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: 'Entity ID')]
    public const schema_fields_ID = 'entity_id';

    #[Col(type: 'varchar', length: 20, nullable: false, comment: 'Locale code')]
    public const schema_fields_LOCALE_CODE = self::schema_fields_local_code;

    #[Col(type: 'varchar', length: 255, nullable: true, comment: 'Name')]
    public const schema_fields_NAME = 'name';
}

/** Non-LocalModel with a local_code column must NOT auto-expand declared PK. */
class SchemaParserNonLocalWithLocalCodeFixture extends Model
{
    public const schema_table = 'schema_parser_non_local_local_code';
    public const schema_primary_key = 'row_id';

    #[Col(type: 'int', nullable: false, primaryKey: true, comment: 'Row')]
    public const schema_fields_ID = 'row_id';

    #[Col(type: 'varchar', length: 20, nullable: false, comment: 'Locale')]
    public const schema_fields_LOCAL_CODE = 'local_code';
}
