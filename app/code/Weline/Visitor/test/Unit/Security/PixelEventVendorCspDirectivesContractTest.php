<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Extends\Module\Weline_Framework\Security\Csp\PixelEventVendorsCsp;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\Ga4Vendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\GtmVendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\SystemVendor;

final class PixelEventVendorCspDirectivesContractTest extends TestCase
{
    public function testBuiltInVendorsDeclareCspDirectives(): void
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

    public function testPixelEventVendorsCspAggregatesProviders(): void
    {
        $contribution = (new PixelEventVendorsCsp(static fn (): array => [
            new Ga4Vendor(),
            new GtmVendor(),
            new SystemVendor(),
        ]))->contribution();

        self::assertContains('https://www.googletagmanager.com', $contribution->directives['script-src'] ?? []);
        self::assertContains('https://region1.google-analytics.com', $contribution->directives['connect-src'] ?? []);
        self::assertContains('https://www.googletagmanager.com', $contribution->directives['frame-src'] ?? []);
    }

    public function testInterfaceDeclaresCspDirectives(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3) . '/Interface/PixelEventVendorInterface.php'
        );
        self::assertStringContainsString('function cspDirectives(): array', $src);
        self::assertFileDoesNotExist(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Security/Csp/VisitorAnalyticsCsp.php'
        );
        self::assertFileExists(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Security/Csp/PixelEventVendorsCsp.php'
        );
    }
}
