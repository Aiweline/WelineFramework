<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Attribute\Option;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Schema\EavAttributeOptionSchema;
use Weline\Eav\Service\AttributeOptionStore;

final class AttributeOptionScopeContractTest extends TestCase
{
    public function testSchemaUniqueKeyIncludesScopeInstanceId(): void
    {
        $schema = new EavAttributeOptionSchema();
        self::assertSame(
            [
                EavAttributeOptionSchema::FIELD_ATTRIBUTE_ID,
                EavAttributeOptionSchema::FIELD_CODE,
                EavAttributeOptionSchema::FIELD_SCOPE_INSTANCE_ID,
            ],
            $schema->getUniqueKey(),
        );
        self::assertArrayHasKey(
            EavAttributeOptionSchema::FIELD_SCOPE_INSTANCE_ID,
            $schema->getColumns(),
        );
    }

    public function testOptionModelUnitKeysMatchSchema(): void
    {
        $schema = new EavAttributeOptionSchema();
        $model = new Option();
        self::assertSame($schema->getUniqueKey(), $model->_unit_primary_keys);
        self::assertSame(0, Option::SCOPE_SHARED);
        self::assertSame(
            EavAttributeOptionSchema::FIELD_SCOPE_INSTANCE_ID,
            Option::schema_fields_scope_instance_id,
        );
    }

    public function testStoreInterfaceExposesScopeHelpers(): void
    {
        self::assertTrue(interface_exists(AttributeOptionStoreInterface::class));
        self::assertTrue(method_exists(AttributeOptionStoreInterface::class, 'ensureInScope'));
        self::assertTrue(method_exists(AttributeOptionStoreInterface::class, 'findInScope'));
        self::assertTrue(method_exists(AttributeOptionStoreInterface::class, 'assertUsableByInstance'));
        self::assertTrue(class_exists(AttributeOptionStore::class));
    }

    public function testFilterServiceAndManagerGuardSharedScopeInSource(): void
    {
        $filter = file_get_contents(
            dirname(__DIR__, 4) . '/Service/AttributeFilterService.php',
        );
        self::assertIsString($filter);
        self::assertStringContainsString(
            'Option::schema_fields_scope_instance_id, Option::SCOPE_SHARED',
            $filter,
        );

        $manager = file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Backend/Manager.php',
        );
        self::assertIsString($manager);
        self::assertStringContainsString(
            'Option::schema_fields_scope_instance_id, Option::SCOPE_SHARED',
            $manager,
        );

        $migration = file_get_contents(
            dirname(__DIR__, 4) . '/Setup/Db/Migration/add_eav_option_scope_instance_id_20260904-v1.2.4.php',
        );
        self::assertIsString($migration);
        self::assertStringContainsString('scope_instance_id', $migration);
        self::assertStringContainsString('uk_eav_attribute_option_attr_code_scope', $migration);

        $optionsQuery = file_get_contents(
            dirname(__DIR__, 4) . '/Service/EavOptionsQuery.php',
        );
        self::assertIsString($optionsQuery);
        self::assertStringContainsString("'scope_instance_id'", $optionsQuery);
        self::assertStringContainsString(
            '[Option::SCOPE_SHARED, $scopeInstanceId]',
            $optionsQuery,
        );
    }
}
