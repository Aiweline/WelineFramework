<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ResolvedLanguageCatalogContractTest extends TestCase
{
    public function testInterfaceAndServiceExistWithInheritanceContract(): void
    {
        $iface = \dirname(__DIR__, 3) . '/Api/Localization/ResolvedLanguageCatalogInterface.php';
        $svc = \dirname(__DIR__, 3) . '/Service/Localization/ResolvedLanguageCatalog.php';
        $override = \dirname(__DIR__, 3) . '/Model/ScopeLanguageOverride.php';
        self::assertFileExists($iface);
        self::assertFileExists($svc);
        self::assertFileExists($override);

        $ifaceSrc = (string)\file_get_contents($iface);
        $svcSrc = (string)\file_get_contents($svc);
        $overrideSrc = (string)\file_get_contents($override);

        self::assertStringContainsString('getResolvedLanguageCodes', $ifaceSrc);
        self::assertStringContainsString('saveOverride', $ifaceSrc);
        self::assertStringContainsString('clearOverride', $ifaceSrc);
        self::assertStringContainsString('hasOverride', $ifaceSrc);

        self::assertStringContainsString('CHANNEL_ID_STORE_SCOPE = -1', $overrideSrc);
        self::assertStringContainsString('CHANNEL_ID_STORE_SCOPE', $svcSrc);
        self::assertStringContainsString('expandWebsiteUnionAndWarmup', $svcSrc);
    }

    public function testQueryProviderExposesResolvedLanguageCodes(): void
    {
        $path = \dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString("'getResolvedLanguageCodes'", $src);
        self::assertStringContainsString('getResolvedLanguageCodes($params)', $src);
    }

    public function testModuleRegistersResolvedLanguageCatalog(): void
    {
        $module = require \dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            \Weline\Websites\Service\Localization\ResolvedLanguageCatalog::class,
            $module['provides'][\Weline\Websites\Api\Localization\ResolvedLanguageCatalogInterface::class] ?? null,
        );
    }
}
