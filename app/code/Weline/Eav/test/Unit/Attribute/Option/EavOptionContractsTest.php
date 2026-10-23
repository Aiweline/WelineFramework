<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Attribute\Option;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Api\Attribute\Option\AttributeOptionStoreInterface;
use Weline\Eav\Model\EavAttribute\Option;
use Weline\Eav\Schema\EavAttributeOptionSchema;
use Weline\Eav\Service\AttributeOptionStore;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllEavOptionContractsPass().
 */
final class EavOptionContractsTest extends TestCase
{
    public function testAllEavOptionContractsPass(): void
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
            'AttributeOptionScope::testSchemaUniqueKeyIncludesScopeInstanceId' => function () {
            $this->assert_AttributeOptionScope_testSchemaUniqueKeyIncludesScopeInstanceId();
        },
            'AttributeOptionScope::testOptionModelUnitKeysMatchSchema' => function () {
            $this->assert_AttributeOptionScope_testOptionModelUnitKeysMatchSchema();
        },
            'AttributeOptionScope::testStoreInterfaceExposesScopeHelpers' => function () {
            $this->assert_AttributeOptionScope_testStoreInterfaceExposesScopeHelpers();
        },
            'AttributeOptionScope::testFilterServiceAndManagerGuardSharedScopeInSource' => function () {
            $this->assert_AttributeOptionScope_testFilterServiceAndManagerGuardSharedScopeInSource();
        },
        ];
    }

private function assert_AttributeOptionScope_testSchemaUniqueKeyIncludesScopeInstanceId(): void
{
    $schema = new EavAttributeOptionSchema();
    self::assertSame([EavAttributeOptionSchema::FIELD_ATTRIBUTE_ID, EavAttributeOptionSchema::FIELD_CODE, EavAttributeOptionSchema::FIELD_SCOPE_INSTANCE_ID], $schema->getUniqueKey());
    self::assertArrayHasKey(EavAttributeOptionSchema::FIELD_SCOPE_INSTANCE_ID, $schema->getColumns());
}

private function assert_AttributeOptionScope_testOptionModelUnitKeysMatchSchema(): void
{
    $schema = new EavAttributeOptionSchema();
    $model = new Option();
    self::assertSame($schema->getUniqueKey(), $model->_unit_primary_keys);
    self::assertSame(0, Option::SCOPE_SHARED);
    self::assertSame(EavAttributeOptionSchema::FIELD_SCOPE_INSTANCE_ID, Option::schema_fields_scope_instance_id);
}

private function assert_AttributeOptionScope_testStoreInterfaceExposesScopeHelpers(): void
{
    self::assertTrue(interface_exists(AttributeOptionStoreInterface::class));
    self::assertTrue(method_exists(AttributeOptionStoreInterface::class, 'ensureInScope'));
    self::assertTrue(method_exists(AttributeOptionStoreInterface::class, 'findInScope'));
    self::assertTrue(method_exists(AttributeOptionStoreInterface::class, 'assertUsableByInstance'));
    self::assertTrue(class_exists(AttributeOptionStore::class));
}

private function assert_AttributeOptionScope_testFilterServiceAndManagerGuardSharedScopeInSource(): void
{
    $filter = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Service/AttributeFilterService.php');
    self::assertIsString($filter);
    self::assertStringContainsString('Option::schema_fields_scope_instance_id, Option::SCOPE_SHARED', $filter);
    $manager = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Controller/Backend/Manager.php');
    self::assertIsString($manager);
    self::assertStringContainsString('Option::schema_fields_scope_instance_id, Option::SCOPE_SHARED', $manager);
    $optionsQuery = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Service/EavOptionsQuery.php');
    self::assertIsString($optionsQuery);
    self::assertStringContainsString("'scope_instance_id'", $optionsQuery);
    self::assertStringContainsString('[Option::SCOPE_SHARED, $scopeInstanceId]', $optionsQuery);
}
}
