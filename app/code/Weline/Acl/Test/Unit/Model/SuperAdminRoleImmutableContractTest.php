<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\Role;

final class SuperAdminRoleImmutableContractTest extends TestCase
{
    public function testSuperAdminRoleIdIsFixedOne(): void
    {
        self::assertSame(1, Role::ID_SUPER_ADMIN);
    }

    public function testDeleteBeforeRejectsSuperAdminRole(): void
    {
        $src = (string)file_get_contents(BP . '/app/code/Weline/Acl/Model/Role.php');
        self::assertStringContainsString('ID_SUPER_ADMIN = 1', $src);
        self::assertStringContainsString('不能删除超级管理员', $src);
        self::assertMatchesRegularExpression(
            '/function delete_before\(\)[\s\S]*ID_SUPER_ADMIN[\s\S]*不能删除超级管理员/u',
            $src
        );

        $controller = (string)file_get_contents(BP . '/app/code/Weline/Acl/Controller/Backend/Acl/Role.php');
        self::assertStringContainsString('Role::ID_SUPER_ADMIN', $controller);
        self::assertStringContainsString('不能删除超级管理员', $controller);
    }

    public function testSetupUpgradeObserverEnsuresRoleBeforeGrant(): void
    {
        $src = (string)file_get_contents(
            BP . '/app/code/Weline/Acl/Observer/SetupUpgradeGrantSuperAdmin.php'
        );
        self::assertStringContainsString('Role::ensureSuperAdminRoleExists()', $src);
        self::assertStringContainsString('Role::ID_SUPER_ADMIN', $src);
    }
}
