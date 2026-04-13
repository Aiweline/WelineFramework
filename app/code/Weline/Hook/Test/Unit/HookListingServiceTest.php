<?php

declare(strict_types=1);

namespace Weline\Hook\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Hook\HookRegistry;
use Weline\Hook\Service\HookListingService;

final class HookListingServiceTest extends TestCase
{
    public function testGetAllHooksUsesRegistryImplementationsAndCachesByRegistryMtime(): void
    {
        $registry = $this->createMock(HookRegistry::class);
        $registry->expects(self::once())->method('initialize');
        $registry->expects(self::once())
            ->method('getHooks')
            ->willReturn([
                'Weline_Admin::backend::layouts::dashboard::main-tabs' => [
                    'name' => 'Dashboard Tabs',
                    'description' => 'Tab extensions',
                    'module' => 'Weline_Admin',
                    'has_spec' => true,
                    'has_doc' => true,
                    'doc' => 'backend/dashboard/main-tabs.md',
                    'doc_path' => 'doc/hook/backend/dashboard/main-tabs.md',
                    'implementations' => [
                        'Weline_Visitor' => [
                            'file' => 'Weline_Admin/backend/layouts/dashboard/main-tabs.phtml',
                            'priority' => 150,
                            'sort_order' => 0,
                            'solo' => false,
                        ],
                    ],
                ],
            ]);
        $registry->expects(self::once())
            ->method('getAllRegisteredHooks')
            ->willReturn(['Weline_Admin::backend::layouts::dashboard::main-tabs']);
        $registry->expects(self::once())
            ->method('getHookInfoFromInterface')
            ->with('Weline_Admin::backend::layouts::dashboard::main-tabs')
            ->willReturn(['constant' => 'DASHBOARD_MAIN_TABS']);

        $service = new HookListingService();

        $property = new \ReflectionProperty(HookListingService::class, 'hookRegistry');
        $property->setAccessible(true);
        $property->setValue($service, $registry);

        $hooksFirst = $service->getAllHooks();
        $hooksSecond = $service->getAllHooks();

        self::assertSame($hooksFirst, $hooksSecond);
        self::assertArrayHasKey('Weline_Admin::backend::layouts::dashboard::main-tabs', $hooksFirst);

        $hook = $hooksFirst['Weline_Admin::backend::layouts::dashboard::main-tabs'];
        self::assertSame('Dashboard Tabs', $hook['display_name']);
        self::assertSame('DASHBOARD_MAIN_TABS', $hook['constant']);
        self::assertSame(['Weline_Visitor'], $hook['using_modules']);
        self::assertSame(
            ['Weline_Visitor::view/hooks/Weline_Admin/backend/layouts/dashboard/main-tabs.phtml'],
            $hook['files']
        );
        self::assertSame(1, $hook['file_count']);
        self::assertTrue($hook['has_files']);
    }
}
