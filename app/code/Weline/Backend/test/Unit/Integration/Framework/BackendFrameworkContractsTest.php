<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Integration\Framework;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendFrameworkContractsPass().
 */
final class BackendFrameworkContractsTest extends TestCase
{
    public function testAllBackendFrameworkContractsPass(): void
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
            'FrontendWorkerBackendAuthorizationProvider::testDeniedMessageDistinguishesMissingAclSourceForSuperAdmin' => function () {
            $this->assert_FrontendWorkerBackendAuthorizationProvider_testDeniedMessageDistinguishesMissingAclSourceForSuperAdmin();
        },
        ];
    }

private function assert_FrontendWorkerBackendAuthorizationProvider_testDeniedMessageDistinguishesMissingAclSourceForSuperAdmin(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Backend/Integration/Framework/FrontendWorkerBackendAuthorizationProvider.php');
    self::assertStringContainsString('ACL 资源未注册或未启用：%{1}', $source);
    self::assertStringContainsString('当前站点关闭了超管 ACL 旁路，且角色未授权资源：%{1}', $source);
    self::assertStringContainsString('isRegisteredBackendSource', $source);
    self::assertStringContainsString('deniedMessage', $source);
    self::assertStringContainsString('Super-admin bypass still requires the exact source to exist', $source);
}
}
