<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Model\EavAttribute\Type;

class EavAttributeSchemaFieldAliasTest extends TestCase
{
    public function testOptionSchemaFieldAliasesMatchLegacyFields(): void
    {
        $this->assertSame(Option::fields_ID, Option::schema_fields_ID);
        $this->assertSame(Option::fields_option_id, Option::schema_fields_option_id);
        $this->assertSame(Option::fields_attribute_id, Option::schema_fields_attribute_id);
        $this->assertSame(Option::fields_code, Option::schema_fields_code);
        $this->assertSame(Option::fields_value, Option::schema_fields_value);
        $this->assertSame(Option::fields_swatch_color, Option::schema_fields_swatch_color);
    }

    public function testTypeSchemaFieldAliasesMatchLegacyFields(): void
    {
        $this->assertSame(Type::fields_ID, Type::schema_fields_ID);
        $this->assertSame(Type::fields_type_id, Type::schema_fields_type_id);
        $this->assertSame(Type::fields_code, Type::schema_fields_code);
        $this->assertSame(Type::fields_name, Type::schema_fields_name);
        $this->assertSame(Type::fields_field_type, Type::schema_fields_field_type);
        $this->assertSame(Type::fields_field_length, Type::schema_fields_field_length);
    }
}
