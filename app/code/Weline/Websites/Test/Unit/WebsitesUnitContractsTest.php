<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\SiteContactSeedService;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesUnitContractsPass().
 */
final class WebsitesUnitContractsTest extends TestCase
{
    public function testAllWebsitesUnitContractsPass(): void
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
            'SiteContactConfig::testSiteContactSystemConfigDeclaresScopedAddress' => function () {
            $this->assert_SiteContactConfig_testSiteContactSystemConfigDeclaresScopedAddress();
        },
            'SiteContactConfig::testUpgradeSeedsGlobalContactAddress' => function () {
            $this->assert_SiteContactConfig_testUpgradeSeedsGlobalContactAddress();
        },
            'SiteContactEmbed::testWebsiteStoreChannelHooksEmbedContactGroup' => function () {
            $this->assert_SiteContactEmbed_testWebsiteStoreChannelHooksEmbedContactGroup();
        },
            'SiteContactEmbed::testModuleVersionDeclaresReleasedSemver' => function () {
            $this->assert_SiteContactEmbed_testModuleVersionDeclaresReleasedSemver();
        },
        ];
    }

private function assert_SiteContactConfig_testSiteContactSystemConfigDeclaresScopedAddress(): void
{
    $path = $this->moduleRoot() . '/extends/module/Weline_SystemConfig/Config/backend/site-contact.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('website/contact/address', $src);
    self::assertStringContainsString('website/contact/phone', $src);
    self::assertStringContainsString('website/contact/service_hours', $src);
    self::assertStringContainsString('scope="global,website,store"', $src);
    self::assertStringContainsString(SiteContactSeedService::DEFAULT_ADDRESS_EN, $src);
    self::assertStringContainsString('Chengdu Amayun Technology', $src);
}

private function assert_SiteContactConfig_testUpgradeSeedsGlobalContactAddress(): void
{
    $root = $this->moduleRoot();
    $upgrade = (string) file_get_contents($root . '/Setup/Upgrade.php');
    self::assertStringContainsString('SiteContactSeedService', $upgrade);
    self::assertStringContainsString('ensureGlobalDefaults', $upgrade);
    $seed = (string) file_get_contents($root . '/Service/SiteContactSeedService.php');
    self::assertStringContainsString('KEY_ADDRESS', $seed);
    self::assertStringContainsString('SCOPE_GLOBAL', $seed);
    self::assertStringContainsString('Chengdu Amayun Technology Co., Ltd.', $seed);
    self::assertStringContainsString('Chengdu High-tech Zone', $seed);
    self::assertStringContainsString('P.R. China', $seed);
}

private function assert_SiteContactEmbed_testWebsiteStoreChannelHooksEmbedContactGroup(): void
{
    $root = $this->moduleRoot();
    $websiteHook = (string) file_get_contents($root . '/view/hooks/Weline_Websites/backend/website/form/sections-after.phtml');
    $storeHook = (string) file_get_contents($root . '/view/hooks/Weline_Websites/backend/store/form/sections-after.phtml');
    $channelHook = (string) file_get_contents($root . '/view/hooks/Weline_Websites/backend/channel/form/sections-after.phtml');
    $partial = (string) file_get_contents($root . '/view/templates/Admin/partials/site-contact-embed-section.phtml');
    $mapper = (string) file_get_contents($root . '/Service/SiteContactScopeMapper.php');
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

private function assert_SiteContactEmbed_testModuleVersionDeclaresReleasedSemver(): void
{
    $module = include $this->moduleRoot() . '/etc/module.php';
    self::assertIsArray($module);
    $version = $module['version'] ?? null;
    self::assertIsString($version);
    self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, 'etc/module.php must declare a released semver');
}

private function moduleRoot(): string
{
    return BP . 'app/code/Weline/Websites';
}
}
