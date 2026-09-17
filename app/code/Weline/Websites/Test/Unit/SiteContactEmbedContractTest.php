<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 网站/店/渠编辑页嵌入站点联系信息分组（锁实体范围）。
 */
final class SiteContactEmbedContractTest extends TestCase
{
    public function testWebsiteStoreChannelHooksEmbedContactGroup(): void
    {
        $root = dirname(__DIR__, 2);
        $websiteHook = (string)file_get_contents(
            $root . '/view/hooks/Weline_Websites/backend/website/form/sections-after.phtml'
        );
        $storeHook = (string)file_get_contents(
            $root . '/view/hooks/Weline_Websites/backend/store/form/sections-after.phtml'
        );
        $channelHook = (string)file_get_contents(
            $root . '/view/hooks/Weline_Websites/backend/channel/form/sections-after.phtml'
        );
        $partial = (string)file_get_contents(
            $root . '/view/templates/Admin/partials/site-contact-embed-section.phtml'
        );
        $mapper = (string)file_get_contents($root . '/Service/SiteContactScopeMapper.php');

        self::assertStringContainsString('SiteContactScopeMapper', $websiteHook);
        self::assertStringContainsString('forWebsite', $websiteHook);
        self::assertStringContainsString("fetch('Weline_Websites::templates/Admin/partials/site-contact-embed-section.phtml'", $websiteHook);

        self::assertStringContainsString('forStore', $storeHook);
        self::assertStringContainsString('forChannel', $channelHook);
        self::assertStringContainsString("fetch('Weline_Websites::templates/Admin/partials/site-contact-embed-section.phtml'", $storeHook);

        self::assertStringContainsString('website_contact', $partial);
        self::assertStringContainsString('ConfigEmbedRenderer', $partial);
        self::assertStringContainsString('renderFromAttributes', $partial);
        self::assertStringContainsString('website-site-contact-embed', $partial);
        self::assertStringContainsString('data-testid="website-site-contact-section"', $partial);
        self::assertStringContainsString('必须在分区内部 echo', $partial);

        self::assertStringContainsString('KIND_WEBSITE', $mapper);
        self::assertStringContainsString('KIND_STORE', $mapper);
        self::assertStringContainsString('KIND_CHANNEL', $mapper);
        self::assertStringContainsString('resolveFromInput', $mapper);
    }

    public function testModuleVersionIs1829(): void
    {
        $module = include dirname(__DIR__, 2) . '/etc/module.php';
        self::assertIsArray($module);
        self::assertSame('1.8.29', $module['version'] ?? null);
    }
}
