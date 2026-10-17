<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Architecture;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllArchitectureContractsPass().
 */
final class ArchitectureContractsTest extends TestCase
{
    public function testAllArchitectureContractsPass(): void
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
            'QueryShellOwnership::testBackendQueryOwnedByBackendModule' => function () {
            $this->assert_QueryShellOwnership_testBackendQueryOwnedByBackendModule();
        },
            'QueryShellOwnership::testFrontendRestAndQueryShellsOwnedByFrontendModule' => function () {
            $this->assert_QueryShellOwnership_testFrontendRestAndQueryShellsOwnedByFrontendModule();
        },
            'QueryShellOwnership::testRouterHardcodesOwningModuleControllers' => function () {
            $this->assert_QueryShellOwnership_testRouterHardcodesOwningModuleControllers();
        },
        ];
    }

private function assert_QueryShellOwnership_testBackendQueryOwnedByBackendModule(): void
{
    $home = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Backend/Controller/Api/Query.php';
    self::assertFileExists($home);
    $src = (string) file_get_contents($home);
    self::assertStringContainsString('namespace Weline\Backend\Controller\Api;', $src);
    self::assertStringContainsString('FrameworkQueryService', $src);
    $alias = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Controller/Backend/Api/Query.php';
    self::assertFileExists($alias);
    $aliasSrc = (string) file_get_contents($alias);
    self::assertStringContainsString('@deprecated', $aliasSrc);
    self::assertStringContainsString('Weline\Backend\Controller\Api\Query', $aliasSrc);
}

private function assert_QueryShellOwnership_testFrontendRestAndQueryShellsOwnedByFrontendModule(): void
{
    $weline = '/Users/weline/Project/Official/框架/app/code/Weline';
    foreach (['/Frontend/Controller/FrontendRestController.php', '/Frontend/Controller/Api/QueryBin.php', '/Frontend/Controller/Api/BinQuery.php', '/Frontend/Controller/Api/Stream.php'] as $rel) {
        self::assertFileExists($weline . $rel, $rel);
    }
    $queryBin = (string) file_get_contents($weline . '/Frontend/Controller/Api/QueryBin.php');
    self::assertStringContainsString('namespace Weline\Frontend\Controller\Api;', $queryBin);
    self::assertStringContainsString('SIGNED_PATH', $queryBin);
    foreach (['/Framework/App/Controller/FrontendRestController.php', '/Framework/Controller/Api/QueryBin.php', '/Framework/Controller/Api/BinQuery.php', '/Framework/Controller/Api/Stream.php'] as $rel) {
        $aliasSrc = (string) file_get_contents($weline . $rel);
        self::assertStringContainsString('@deprecated', $aliasSrc, $rel);
    }
}

private function assert_QueryShellOwnership_testRouterHardcodesOwningModuleControllers(): void
{
    $router = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Router/Core.php');
    self::assertStringContainsString('Weline\Backend\Controller\Api\Query::class', $router);
    self::assertStringContainsString('Weline\Frontend\Controller\Api\QueryBin::class', $router);
    self::assertStringContainsString('Weline\Frontend\Controller\Api\BinQuery::class', $router);
    self::assertStringContainsString('Weline\Frontend\Controller\Api\Stream::class', $router);
    self::assertStringNotContainsString('Weline\Framework\Controller\Backend\Api\Query::class', $router);
    self::assertStringNotContainsString('Weline\Framework\Controller\Api\QueryBin::class', $router);
}
}
