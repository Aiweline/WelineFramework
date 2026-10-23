<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Admin\Controller\Backend\User as BackendUserController;
use Weline\Admin\Controller\System\Menus as SystemMenusController;
use Weline\Admin\Extends\Module\Weline_Framework\Query\AdminAdminQueryProvider;
use Weline\Admin\Service\MenuManagementPresenter;
use Weline\Acl\Model\Acl;

/**
 * Aggregated contract suite for Admin Query providers.
 * Observe one overall pass/fail via testAllAdminQueryContractsPass().
 */
final class AdminQueryContractsTest extends TestCase
{
    public function testAllAdminQueryContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ': ' . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'AdminAdminQueryProviderNestedPath::testResolvesSystemMenusDetail' => function (): void {
                $this->assertResolvesPath(
                    '/admin/system/menus/detail',
                    SystemMenusController::class,
                    'detail'
                );
            },
            'AdminAdminQueryProviderNestedPath::testResolvesSystemMenusSave' => function (): void {
                $this->assertResolvesPath(
                    '/admin/system/menus/save',
                    SystemMenusController::class,
                    'save'
                );
            },
            'AdminAdminQueryProviderNestedPath::testResolvesSystemMenusIndex' => function (): void {
                $this->assertResolvesPath(
                    '/admin/system/menus',
                    SystemMenusController::class,
                    'index'
                );
            },
            'AdminAdminQueryProviderNestedPath::testResolvesBackendUser' => function (): void {
                $this->assertResolvesPath(
                    '/admin/backend/user',
                    BackendUserController::class,
                    'index'
                );
            },
            'AdminAdminQueryProviderNestedPath::testRejectsUnknownNestedPath' => function (): void {
                $provider = new AdminAdminQueryProvider();
                $unknown = $provider->execute('adminRequest', [
                    'url' => '/admin/system/not-a-real/controller/path',
                    'method' => 'GET',
                ]);
                self::assertIsArray($unknown);
                self::assertFalse($unknown['success'] ?? true);
                self::assertStringContainsString('Unsupported admin path', (string)($unknown['message'] ?? ''));
            },
            'AdminAdminQueryProvider::testMenuSignalWatchIsBackendStream' => function (): void {
                $descriptor = (new AdminAdminQueryProvider())->getDescriptor();
                $watch = null;
                foreach ($descriptor['operations'] as $operation) {
                    if (($operation['name'] ?? '') === 'menuSignalWatch') {
                        $watch = $operation;
                        break;
                    }
                }
                self::assertIsArray($watch);
                self::assertSame('stream', $watch['mode'] ?? null);
                self::assertSame('backend', $watch['auth'] ?? null);
                self::assertSame('self', $watch['backend_acl']['kind'] ?? null);
                self::assertSame('since', $watch['params'][0]['name'] ?? null);
            },
            'MenuManagementPresenter::testPresentNodeMapsAclFieldsForUi' => function (): void {
                $presenter = new MenuManagementPresenter();
                $presented = $presenter->presentNode([
                    Acl::schema_fields_ACL_ID => 42,
                    Acl::schema_fields_SOURCE_ID => 'Weline_Demo::sample',
                    Acl::schema_fields_SOURCE_NAME => '示例菜单',
                    Acl::schema_fields_PARENT_SOURCE => 'Weline_Demo::root',
                    Acl::schema_fields_ROUTE => 'admin/demo/index',
                    Acl::schema_fields_MODULE => 'Weline_Demo',
                    Acl::schema_fields_ICON => 'menu',
                    Acl::schema_fields_ORDER => 10,
                    Acl::schema_fields_IS_ENABLE => 1,
                    Acl::schema_fields_ACL_ORIGIN => 'menu_xml',
                    'children' => [],
                ]);
                self::assertSame(42, $presented['menu_id']);
                self::assertSame('Weline_Demo::sample', $presented['source']);
                self::assertSame('示例菜单', $presented['title']);
                self::assertSame('admin/demo/index', $presented['action']);
                self::assertTrue($presented['is_system']);
            },
        ];
    }

    private function assertResolvesPath(string $url, string $expectedClass, string $expectedAction): void
    {
        $provider = new AdminAdminQueryProvider();
        $method = new ReflectionMethod(AdminAdminQueryProvider::class, 'resolveControllerAction');
        $method->setAccessible(true);
        $parts = parse_url($url);
        $path = (string)($parts['path'] ?? '');
        $resolved = $method->invoke($provider, $path);
        self::assertIsArray($resolved, 'Expected resolve for ' . $url);
        self::assertSame($expectedClass, $resolved[0], 'Controller for ' . $url);
        self::assertSame($expectedAction, $resolved[1], 'Action for ' . $url);
    }
}
