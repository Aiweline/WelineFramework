<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Acl;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllAclContractsPass().
 */
final class AclContractsTest extends TestCase
{
    public function testAllAclContractsPass(): void
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
            'RoleAclEntriesProvider::testDeliveryAccessPolicyResolvesFrameworkAclInterface' => function () {
            $this->assert_RoleAclEntriesProvider_testDeliveryAccessPolicyResolvesFrameworkAclInterface();
        },
            'RoleAclEntriesProvider::testNoFrameworkBackendRestAliasFile' => function () {
            $this->assert_RoleAclEntriesProvider_testNoFrameworkBackendRestAliasFile();
        },
        ];
    }

private function assert_RoleAclEntriesProvider_testDeliveryAccessPolicyResolvesFrameworkAclInterface(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Event/Async/Admin/DeliveryAccessPolicy.php');
    self::assertStringContainsString('RoleAclEntriesProviderInterface', $src);
    self::assertStringNotContainsString('Acl\Service\AclService', $src);
}

private function assert_RoleAclEntriesProvider_testNoFrameworkBackendRestAliasFile(): void
{
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/App/Controller/BackendRestController.php');
}
}
