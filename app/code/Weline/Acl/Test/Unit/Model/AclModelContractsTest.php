<?php

declare(strict_types=1);

namespace Weline\Acl\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\Role;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllAclModelContractsPass().
 */
final class AclModelContractsTest extends TestCase
{
    public function testAllAclModelContractsPass(): void
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
            'SuperAdminRoleImmutable::testSuperAdminRoleIdIsFixedOne' => function () {
            $this->assert_SuperAdminRoleImmutable_testSuperAdminRoleIdIsFixedOne();
        },
            'SuperAdminRoleImmutable::testDeleteBeforeRejectsSuperAdminRole' => function () {
            $this->assert_SuperAdminRoleImmutable_testDeleteBeforeRejectsSuperAdminRole();
        },
            'SuperAdminRoleImmutable::testSetupUpgradeObserverEnsuresRoleBeforeGrant' => function () {
            $this->assert_SuperAdminRoleImmutable_testSetupUpgradeObserverEnsuresRoleBeforeGrant();
        },
        ];
    }

private function assert_SuperAdminRoleImmutable_testSuperAdminRoleIdIsFixedOne(): void
{
    self::assertSame(1, Role::ID_SUPER_ADMIN);
}

private function assert_SuperAdminRoleImmutable_testDeleteBeforeRejectsSuperAdminRole(): void
{
    $src = (string) file_get_contents(BP . '/app/code/Weline/Acl/Model/Role.php');
    self::assertStringContainsString('ID_SUPER_ADMIN = 1', $src);
    self::assertStringContainsString('不能删除超级管理员', $src);
    self::assertMatchesRegularExpression('/function delete_before\(\)[\s\S]*ID_SUPER_ADMIN[\s\S]*不能删除超级管理员/u', $src);
    $controller = (string) file_get_contents(BP . '/app/code/Weline/Acl/Controller/Backend/Acl/Role.php');
    self::assertStringContainsString('Role::ID_SUPER_ADMIN', $controller);
    self::assertStringContainsString('不能删除超级管理员', $controller);
}

private function assert_SuperAdminRoleImmutable_testSetupUpgradeObserverEnsuresRoleBeforeGrant(): void
{
    $src = (string) file_get_contents(BP . '/app/code/Weline/Acl/Observer/SetupUpgradeGrantSuperAdmin.php');
    self::assertStringContainsString('Role::ensureSuperAdminRoleExists()', $src);
    self::assertStringContainsString('Role::ID_SUPER_ADMIN', $src);
}
}
