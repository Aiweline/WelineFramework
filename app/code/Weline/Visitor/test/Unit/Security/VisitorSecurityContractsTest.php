<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Extends\Module\Weline_Framework\Security\Csp\PixelEventVendorsCsp;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\Ga4Vendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\GtmVendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\SystemVendor;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllVisitorSecurityContractsPass().
 */
final class VisitorSecurityContractsTest extends TestCase
{
    public function testAllVisitorSecurityContractsPass(): void
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
            'PixelEventVendorCspDirectives::testBuiltInVendorsDeclareCspDirectives' => function () {
            $this->assert_PixelEventVendorCspDirectives_testBuiltInVendorsDeclareCspDirectives();
        },
            'PixelEventVendorCspDirectives::testPixelEventVendorsCspAggregatesProviders' => function () {
            $this->assert_PixelEventVendorCspDirectives_testPixelEventVendorsCspAggregatesProviders();
        },
            'PixelEventVendorCspDirectives::testInterfaceDeclaresCspDirectives' => function () {
            $this->assert_PixelEventVendorCspDirectives_testInterfaceDeclaresCspDirectives();
        },
        ];
    }

private function assert_PixelEventVendorCspDirectives_testBuiltInVendorsDeclareCspDirectives(): void
{
    $ga4 = (new Ga4Vendor())->cspDirectives();
    self::assertContains('https://www.googletagmanager.com', $ga4['script-src'] ?? []);
    self::assertContains('https://www.google-analytics.com', $ga4['script-src'] ?? []);
    self::assertContains('https://region1.google-analytics.com', $ga4['connect-src'] ?? []);
    $gtm = (new GtmVendor())->cspDirectives();
    self::assertContains('https://www.googletagmanager.com', $gtm['script-src'] ?? []);
    self::assertContains('https://www.googletagmanager.com', $gtm['frame-src'] ?? []);
    self::assertSame([], (new SystemVendor())->cspDirectives());
}

private function assert_PixelEventVendorCspDirectives_testPixelEventVendorsCspAggregatesProviders(): void
{
    $contribution = (new PixelEventVendorsCsp(static fn(): array => [new Ga4Vendor(), new GtmVendor(), new SystemVendor()]))->contribution();
    self::assertContains('https://www.googletagmanager.com', $contribution->directives['script-src'] ?? []);
    self::assertContains('https://region1.google-analytics.com', $contribution->directives['connect-src'] ?? []);
    self::assertContains('https://www.googletagmanager.com', $contribution->directives['frame-src'] ?? []);
}

private function assert_PixelEventVendorCspDirectives_testInterfaceDeclaresCspDirectives(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Interface/PixelEventVendorInterface.php');
    self::assertStringContainsString('function cspDirectives(): array', $src);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Framework/Security/Csp/VisitorAnalyticsCsp.php');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Framework/Security/Csp/PixelEventVendorsCsp.php');
}
}
