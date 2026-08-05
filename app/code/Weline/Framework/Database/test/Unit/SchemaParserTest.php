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
