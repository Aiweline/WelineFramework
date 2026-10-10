<?php

declare(strict_types=1);

namespace Weline\Social\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class SocialPlatformAppConfigWebsiteScopeContractTest extends TestCase
{
    public function testAppConfigDeclaresWebsiteScopeHelpers(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/SocialPlatformAppConfig.php');
        self::assertStringContainsString('function scopeForWebsite(int $websiteId, string $websiteCode = \'\'): string', $src);
        self::assertStringContainsString('function getPlatformAppForWebsite(', $src);
        self::assertStringContainsString('function getPlatformAppFromContext(', $src);
        self::assertStringContainsString('default.default.default', $src);
        self::assertStringContainsString('.default.default', $src);
        self::assertStringContainsString("'id-' . \$websiteId", $src);
    }

    public function testProvidersResolveAppConfigFromAccountContext(): void
    {
        $facebook = (string) file_get_contents(dirname(__DIR__, 3) . '/Platform/Social/FacebookProvider.php');
        self::assertStringContainsString("resolveAppConfig('facebook', \$accountContext)", $facebook);
        self::assertStringContainsString("resolveAppConfig('facebook'", $facebook);

        $abstract = (string) file_get_contents(dirname(__DIR__, 3) . '/Platform/AbstractSocialPlatformProvider.php');
        self::assertStringContainsString('function resolveAppConfig(', $abstract);
        self::assertStringContainsString('getPlatformAppFromContext', $abstract);
    }
}
