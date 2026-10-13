<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\SchemaParser;

final class SchemaParserTest extends TestCase
{
    public function testModelWithoutOwnColumnDeclarationsIsSkipped(): void
    {
        $parser = new SchemaParser();

        self::assertNull($parser->parse(SchemaParserNoOwnColumnsModel::class));
    }

    public function testCustomIdCanReuseInheritedColumnMetadata(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(SchemaParserCustomIdModel::class);

        self::assertNotNull($schema);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertArrayHasKey('custom_id', $columns);
        self::assertArrayNotHasKey('id', $columns);
        self::assertTrue($columns['custom_id']->primaryKey);
        self::assertTrue($columns['custom_id']->autoIncrement);
        self::assertArrayHasKey('code', $columns);
    }

    public function testExplicitCustomPrimaryColumnWinsOverInheritedIdMetadata(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(SchemaParserExplicitCustomIdModel::class);

        self::assertNotNull($schema);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertArrayHasKey('path', $columns);
        self::assertSame('varchar', $columns['path']->type);
        self::assertSame(255, $columns['path']->length);
        self::assertTrue($columns['path']->primaryKey);
        self::assertFalse($columns['path']->autoIncrement);
        self::assertArrayNotHasKey('id', $columns);
    }

    public function testSchemaPrimaryKeysMarksCompositeColumnsAsPrimaryKey(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(SchemaParserCompositePrimaryKeysModel::class);

        self::assertNotNull($schema);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertArrayHasKey('role_id', $columns);
        self::assertArrayHasKey('source_id', $columns);
        self::assertTrue($columns['role_id']->primaryKey);
        self::assertTrue($columns['source_id']->primaryKey);
        self::assertArrayNotHasKey('id', $columns);
    }

    /**
     * Acl strategy A: schema_primary_key=acl_id wins; schema_fields_ID=source_id stays UNIQUE business key.
     */
    public function testSchemaPrimaryKeyWinsOverBusinessIdField(): void
    {
        $parser = new SchemaParser();
        $schema = $parser->parse(SchemaParserPhysicalPkBusinessIdModel::class);

        self::assertNotNull($schema);

        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertArrayHasKey('acl_id', $columns);
        self::assertArrayHasKey('source_id', $columns);
        self::assertTrue($columns['acl_id']->primaryKey);
        self::assertFalse($columns['source_id']->primaryKey);
        self::assertTrue($columns['source_id']->unique);
        self::assertArrayNotHasKey('id', $columns);
    }
}

class SchemaParserNoOwnColumnsModel extends Model
{
    public const schema_table = 'schema_parser_no_own_columns';
}

class SchemaParserCustomIdModel extends Model
{
    public const schema_table = 'schema_parser_custom_id';
    public const schema_fields_ID = 'custom_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Code')]
    public const schema_fields_CODE = 'code';
}

class SchemaParserExplicitCustomIdModel extends Model
{
    public const schema_table = 'schema_parser_explicit_custom_id';
    public const schema_fields_ID = 'path';

    #[Col(type: 'varchar', length: 255, primaryKey: true, nullable: false, comment: 'Path')]
    public const schema_fields_PATH = 'path';

    #[Col(type: 'varchar', length: 10, nullable: false, default: 'pc', comment: 'Type')]
    public const schema_fields_TYPE = 'type';
}

class SchemaParserCompositePrimaryKeysModel extends Model
{
    public const schema_table = 'schema_parser_composite_pk';
    public const schema_primary_keys = ['role_id', 'source_id'];
    public const schema_fields_ID = 'role_id';

    #[Col(type: 'int', nullable: false, comment: 'Role')]
    public const schema_fields_ROLE_ID = 'role_id';

    #[Col(type: 'varchar', length: 64, nullable: false, comment: 'Source')]
    public const schema_fields_SOURCE_ID = 'source_id';
}

class SchemaParserPhysicalPkBusinessIdModel extends Model
{
    public const schema_table = 'schema_parser_physical_pk_business_id';
    public const schema_primary_key = 'acl_id';
    public const schema_fields_ID = 'source_id';

    #[Col(type: 'int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'Surrogate PK')]
    public const schema_fields_ACL_ID = 'acl_id';

    #[Col(type: 'varchar', length: 127, nullable: false, unique: true, comment: 'Business key')]
    public const schema_fields_SOURCE_ID = 'source_id';
}
