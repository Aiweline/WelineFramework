<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use ReflectionMethod;
use Weline\DataTable\Exception\DataTableException;
use Weline\DataTable\Extends\Module\Weline_Framework\Query\DataTableQueryProvider;
use Weline\DataTable\Helper\ErrorHandler;
use Weline\DataTable\Model\TestOrder;
use Weline\DataTable\Model\TestUser;
use Weline\DataTable\Service\DataTableResourceRegistry;
use Weline\DataTable\Service\DemoTableService;
use Weline\Framework\Test\TestCore;

class ResourceSecurityTest extends TestCore
{
    public function testRegistryExposesOnlyExplicitDemoResources(): void
    {
        $this->assertSame('demo.users', DataTableResourceRegistry::resourceForModel(TestUser::class));
        $this->assertNull(DataTableResourceRegistry::resourceForModel(\stdClass::class));
        $this->assertCount(5, DataTableResourceRegistry::all());
    }

    public function testLegacyModelValidationRejectsUnregisteredClass(): void
    {
        $this->expectException(DataTableException::class);
        $this->expectExceptionCode(DataTableException::CODE_PERMISSION_DENIED);

        ErrorHandler::validateModel(\stdClass::class);
    }

    public function testLegacyModelValidationAcceptsRegisteredCompositeDeclaration(): void
    {
        ErrorHandler::validateModel(TestUser::class . ' as u, ' . TestOrder::class . ' as o');
        $this->addToAssertionCount(1);
    }

    public function testProviderRequiresBackendAuthorizationForEveryMutation(): void
    {
        $descriptor = (new DataTableQueryProvider(new DemoTableService()))->getDescriptor();
        $operations = [];
        foreach ($descriptor['operations'] as $operation) {
            $operations[$operation['name']] = $operation;
        }

        foreach (['formRecord', 'create', 'update', 'saveData', 'deleteData', 'saveConfig', 'clearConfig', 'previewWrite', 'executeWrite', 'initData', 'clearData'] as $name) {
            $this->assertSame('backend', $operations[$name]['auth'], $name);
            $this->assertTrue($operations[$name]['backend'], $name);
            $this->assertSame('Weline_DataTable::datatable_test_comprehensive', $operations[$name]['backend_acl']['source_id'], $name);
            $this->assertFalse($operations[$name]['external'], $name);
        }

        foreach (['data', 'fields', 'metadata', 'formFields', 'exportData'] as $name) {
            $this->assertSame('any', $operations[$name]['auth'], $name);
            $this->assertFalse($operations[$name]['backend'], $name);
            $this->assertArrayNotHasKey('backend_acl', $operations[$name], $name);
        }
    }

    public function testDependencyOrderRejectsCycles(): void
    {
        $method = new ReflectionMethod(DemoTableService::class, 'resolveDependencyOrder');
        $method->setAccessible(true);

        $this->expectException(\Throwable::class);
        $method->invoke(new DemoTableService(), ['u', 'o'], [
            ['source_alias' => 'u', 'target_alias' => 'o'],
            ['source_alias' => 'o', 'target_alias' => 'u'],
        ]);
    }
}
