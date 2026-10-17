<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Fpc;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllServerFpcContractsPass().
 */
final class ServerFpcContractsTest extends TestCase
{
    public function testAllServerFpcContractsPass(): void
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
            'WlsFpcStoreAdapterPurgeUrls::testPurgeUrlsDelegatesToCoordinator' => function () {
            $this->assert_WlsFpcStoreAdapterPurgeUrls_testPurgeUrlsDelegatesToCoordinator();
        },
        ];
    }

private function assert_WlsFpcStoreAdapterPurgeUrls_testPurgeUrlsDelegatesToCoordinator(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Server' . '/extends/module/Weline_Framework/Fpc/Store/WlsFpcStoreAdapter.php');
    self::assertStringContainsString('FullPageCacheCoordinator::purgeUrls', $src);
    self::assertStringContainsString('router-fpc-path-index', $src);
    self::assertStringContainsString('RecursiveDirectoryIterator', $src);
}
}
