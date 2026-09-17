<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\SiteContactSeedService;

final class SiteContactConfigContractTest extends TestCase
{
    public function testSiteContactSystemConfigDeclaresScopedAddress(): void
    {
        $path = dirname(__DIR__, 2) . '/extends/module/Weline_SystemConfig/Config/backend/site-contact.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('website/contact/address', $src);
        self::assertStringContainsString('website/contact/phone', $src);
        self::assertStringContainsString('website/contact/service_hours', $src);
        self::assertStringContainsString('scope="global,website,store"', $src);
        self::assertStringContainsString(SiteContactSeedService::DEFAULT_ADDRESS_EN, $src);
        self::assertStringContainsString('Chengdu Amayun Technology', $src);
    }

    public function testUpgradeSeedsGlobalContactAddress(): void
    {
        $upgrade = (string)file_get_contents(dirname(__DIR__, 2) . '/Setup/Upgrade.php');
        self::assertStringContainsString('SiteContactSeedService', $upgrade);
        self::assertStringContainsString('ensureGlobalDefaults', $upgrade);

        $seed = (string)file_get_contents(dirname(__DIR__, 2) . '/Service/SiteContactSeedService.php');
        self::assertStringContainsString('KEY_ADDRESS', $seed);
        self::assertStringContainsString('SCOPE_GLOBAL', $seed);
        self::assertStringContainsString('Chengdu Amayun Technology Co., Ltd.', $seed);
        self::assertStringContainsString('Chengdu High-tech Zone', $seed);
        self::assertStringContainsString('P.R. China', $seed);
    }
}
