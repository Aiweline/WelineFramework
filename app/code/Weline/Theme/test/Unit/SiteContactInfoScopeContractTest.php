<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class SiteContactInfoScopeContractTest extends TestCase
{
    public function testSiteContactInfoResolvesScopedSystemConfigKeys(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Helper/SiteContactInfo.php');
        self::assertStringContainsString('function resolve(?string $storageScope', $src);
        self::assertStringContainsString('SiteContactSeedService::KEY_ADDRESS', $src);
        self::assertStringContainsString('SiteContactSeedService::KEY_PHONE', $src);
        self::assertStringContainsString('SiteContactSeedService::DEFAULT_ADDRESS_EN', $src);
        self::assertStringContainsString('resolveConfig(', $src);
        self::assertStringContainsString('Weline_Websites', $src);
        self::assertStringContainsString('smtpWebsiteFromEmail(', $src);
        self::assertStringContainsString('resolvePublicFromEmail', $src);
        self::assertStringContainsString('PLACEHOLDER_EMAIL', $src);
    }

    public function testSiteContactInfoLocalizesServiceHoursForStorefrontLocale(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/Helper/SiteContactInfo.php');
        self::assertStringContainsString('localizeServiceHours(', $src);
        self::assertStringContainsString('WebsiteBrandIdentitySeedService::serviceHoursForLocale', $src);
        self::assertStringContainsString('WebsiteBrandIdentitySeedService::SEED_SERVICE_HOURS', $src);
        self::assertStringContainsString('WidgetI18n::storefrontLocale()', $src);
    }
}
