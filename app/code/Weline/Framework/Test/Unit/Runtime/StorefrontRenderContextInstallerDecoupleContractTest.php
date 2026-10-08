<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * W1：Installer / Timezone 不得 soft-pull 外模块 FQCN。
 */
final class StorefrontRenderContextInstallerDecoupleContractTest extends TestCase
{
    public function testInstallerDoesNotSoftPullForeignModules(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Runtime/StorefrontRenderContextInstaller.php'
        );
        self::assertStringNotContainsString('LanguageSwitcher', $src);
        self::assertStringNotContainsString('ScopeMaintenanceGate', $src);
        self::assertStringNotContainsString('WebsiteData', $src);
        self::assertStringContainsString('preferredInstalledLanguageCodes', $src);
        self::assertStringContainsString('LocalizationProviderRegistry', $src);
    }

    public function testTimezoneDoesNotSoftPullWebsiteData(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/DateTime/Timezone.php'
        );
        self::assertStringNotContainsString('WebsiteData', $src);
        self::assertStringContainsString('getWelineTimezone', $src);
    }
}
