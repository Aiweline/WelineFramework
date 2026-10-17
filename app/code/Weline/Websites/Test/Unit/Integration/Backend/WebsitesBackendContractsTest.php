<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Integration\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesBackendContractsPass().
 */
final class WebsitesBackendContractsTest extends TestCase
{
    public function testAllWebsitesBackendContractsPass(): void
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
            'CurrentWebsiteStorefrontUrlProvider::testProviderIsRegisteredForBackendRuntime' => function () {
            $this->assert_CurrentWebsiteStorefrontUrlProvider_testProviderIsRegisteredForBackendRuntime();
        },
        ];
    }

private function assert_CurrentWebsiteStorefrontUrlProvider_testProviderIsRegisteredForBackendRuntime(): void
{
    $module = require BP . '/app/code/Weline/Websites/etc/module.php';
    $provides = $module['provides'] ?? [];
    self::assertArrayHasKey(\Weline\Backend\Api\Runtime\CurrentWebsiteStorefrontUrlProviderInterface::class, $provides);
    self::assertSame(\Weline\Websites\Integration\Backend\CurrentWebsiteStorefrontUrlProvider::class, $provides[\Weline\Backend\Api\Runtime\CurrentWebsiteStorefrontUrlProviderInterface::class]);
}
}
