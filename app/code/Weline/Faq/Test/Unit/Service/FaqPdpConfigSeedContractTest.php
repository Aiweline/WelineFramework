<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Faq\Service\FaqTemplatePacks;

final class FaqPdpConfigSeedContractTest extends TestCase
{
    public function testSystemConfigTemplateDeclaresScopeAllowedFields(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/frontend/faq-pdp.phtml';
        self::assertFileExists($path);
        $body = (string)file_get_contents($path);
        self::assertStringContainsString('faq/pdp/merge_enabled', $body);
        self::assertStringContainsString('faq/pdp/active_pack', $body);
        self::assertStringContainsString('scope="website,store,channel"', $body);
        self::assertStringContainsString('retail:标准零售,cross_border:跨境,virtual:虚拟商品,b2b:B2B', $body);
        foreach (FaqTemplatePacks::codes() as $code) {
            self::assertStringContainsString($code, $body);
        }
    }

    public function testTemplateSeedServiceHasFourPacks(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FaqTemplateSeedService.php');
        foreach (['FaqTemplatePacks', 'FaqSeedCopyCatalog', 'FaqSeedLocaleResolver', 'TYPE_CODE', 'zh_Hans_CN', 'en_US'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        $catalog = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FaqSeedCopyCatalog.php');
        foreach (['RETAIL', 'CROSS_BORDER', 'VIRTUAL', 'B2B', 'shipping', 'faq_key', 'How long does delivery take?', 'كم يستغرق التوصيل؟', 'hi_IN', 'ar_SA'] as $needle) {
            self::assertStringContainsString($needle, $catalog);
        }
    }

    public function testUpgradeAndModuleWireSeedsAndSystemConfigOptional(): void
    {
        $upgrade = (string)file_get_contents(dirname(__DIR__, 3) . '/Setup/Upgrade.php');
        self::assertStringContainsString('FaqTemplateSeedService', $upgrade);
        self::assertStringContainsString('migrateEmptyLocaleToZhHans', $upgrade);
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame('1.0.11', $module['version'] ?? null);
        self::assertArrayHasKey('Weline_SystemConfig', $module['optional'] ?? []);
    }

    public function testSearchSkipsTemplateType(): void
    {
        $builder = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FaqSearchIndexDocumentBuilder.php');
        self::assertStringContainsString("typeCode === 'template'", $builder);
        self::assertStringContainsString('return null', $builder);
    }
}
