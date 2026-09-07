<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class EmbargoHookContractTest extends TestCase
{
    public function testWebsiteStoreChannelHooksAndObserversExist(): void
    {
        $base = dirname(__DIR__, 3) . '/';
        foreach ([
            'view/hooks/Weline_Websites/backend/website/form/sections-after.phtml',
            'view/hooks/Weline_Websites/backend/store/form/sections-after.phtml',
            'view/hooks/Weline_Websites/backend/channel/form/sections-after.phtml',
            'Observer/WebsiteSaveAfter.php',
            'Observer/StoreSaveAfter.php',
            'Observer/ChannelSaveAfter.php',
            'Model/EmbargoRegion.php',
            'Service/EmbargoService.php',
            'Service/EmbargoAdminService.php',
            'etc/event.xml',
        ] as $rel) {
            self::assertFileExists($base . $rel, $rel);
        }
        $eventXml = (string)file_get_contents($base . 'etc/event.xml');
        self::assertStringContainsString('Weline_Websites::website_save_after', $eventXml);
        self::assertStringContainsString('Weline_Websites::store_save_after', $eventXml);
        self::assertStringContainsString('Weline_Websites::channel_save_after', $eventXml);
        $module = (string)file_get_contents($base . 'etc/module.php');
        self::assertStringContainsString("'Weline_Websites'", $module);
        $shared = (string)file_get_contents($base . 'view/templates/backend/partials/embargo-form-section.phtml');
        self::assertStringContainsString('extensions[shipping][embargo]', $shared);
        self::assertStringContainsString('data-w-address', $shared);
        self::assertStringContainsString('__welineShippingEmbargoReady', $shared);
        self::assertStringContainsString('selection', $shared);
        self::assertStringContainsString("'multi'", $shared);
        self::assertStringContainsString('搜索并添加国家/地区', $shared);
        self::assertStringNotContainsString('data-embargo-add', $shared);
        self::assertStringNotContainsString('data-embargo-table', $shared);
        $websiteHook = (string)file_get_contents($base . 'view/hooks/Weline_Websites/backend/website/form/sections-after.phtml');
        self::assertStringContainsString('embargo-form-section.phtml', $websiteHook);
        self::assertStringContainsString('destination-form-section.phtml', $websiteHook);
        self::assertFileExists($base . 'view/templates/backend/partials/destination-form-section.phtml');
        self::assertFileExists($base . 'Model/CarrierRegion.php');
        self::assertFileExists($base . 'Model/DestinationRegion.php');
        self::assertFileExists($base . 'Service/CarrierCoverageMatchService.php');
        $module = (string)file_get_contents($base . 'etc/module.php');
        self::assertStringContainsString('shipping.carrier_coverage.default', $module);
    }
}
