<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static contracts for Website/Store/Channel-scoped theme application on scope info forms.
 */
final class WebsiteThemeBindingContractTest extends TestCase
{
    public function testServiceOwnsWebsiteApplicationAndThemeDefaultFallback(): void
    {
        $service = $this->read('Service/WebsiteThemeBindingService.php');
        self::assertStringContainsString('class WebsiteThemeBindingService', $service);
        self::assertStringContainsString('function summarize', $service);
        self::assertStringContainsString('function summarizeStore', $service);
        self::assertStringContainsString('function summarizeChannel', $service);
        self::assertStringContainsString('function summarizeForScope', $service);
        self::assertStringContainsString('function bindThemeForWebsite', $service);
        self::assertStringContainsString('function bindThemeForScope', $service);
        self::assertStringContainsString('function scheduleSaveFromWebsiteForm', $service);
        self::assertStringContainsString('function scheduleSaveFromStoreForm', $service);
        self::assertStringContainsString('function scheduleSaveFromChannelForm', $service);
        self::assertStringContainsString('function scheduleSaveFromScopeForm', $service);
        self::assertStringContainsString('afterCommit', $service);
        self::assertStringContainsString('ScopeIdentity::website', $service);
        self::assertStringContainsString('ScopeIdentity::store', $service);
        self::assertStringContainsString('ScopeIdentity::channel', $service);
        self::assertStringContainsString('ThemeApplicationInterface', $service);
        self::assertStringContainsString('DefaultThemeInterface', $service);
        self::assertStringContainsString('WebsiteCatalogInterface', $service);
        self::assertStringContainsString('StoreCatalogInterface', $service);
        self::assertStringNotContainsString('RESOURCE_THEME_BINDING', $service);
        self::assertStringContainsString('function isDefaultTheme', $service);
        self::assertStringContainsString("'store'", $service);
        self::assertStringContainsString("'channel'", $service);

        $observer = $this->read('Observer/WebsiteSaveAfter.php');
        self::assertStringContainsString('WebsiteThemeBindingService', $observer);
        self::assertStringContainsString('scheduleSaveFromWebsiteForm', $observer);
        self::assertStringContainsString('websites_theme_application', $observer);

        $storeObserver = $this->read('Observer/StoreSaveAfter.php');
        self::assertStringContainsString('WebsiteThemeBindingService', $storeObserver);
        self::assertStringContainsString('scheduleSaveFromStoreForm', $storeObserver);

        $channelObserver = $this->read('Observer/ChannelSaveAfter.php');
        self::assertStringContainsString('WebsiteThemeBindingService', $channelObserver);
        self::assertStringContainsString('scheduleSaveFromChannelForm', $channelObserver);

        $eventXml = $this->read('etc/event.xml');
        self::assertStringContainsString('Weline_Websites::website_save_after', $eventXml);
        self::assertStringContainsString('Weline\\Theme\\Observer\\WebsiteSaveAfter', $eventXml);
        self::assertStringContainsString('Weline_Websites::store_save_after', $eventXml);
        self::assertStringContainsString('Weline\\Theme\\Observer\\StoreSaveAfter', $eventXml);
        self::assertStringContainsString('Weline_Websites::channel_save_after', $eventXml);
        self::assertStringContainsString('Weline\\Theme\\Observer\\ChannelSaveAfter', $eventXml);

        $websiteHook = $this->read('view/hooks/Weline_Websites/backend/website/form/sections-after.phtml');
        self::assertStringContainsString('scope-theme-binding-section.phtml', $websiteHook);
        self::assertStringContainsString('summarize(', $websiteHook);

        $storeHook = $this->read('view/hooks/Weline_Websites/backend/store/form/sections-after.phtml');
        self::assertStringContainsString('scope-theme-binding-section.phtml', $storeHook);
        self::assertStringContainsString('summarizeStore', $storeHook);

        $channelHook = $this->read('view/hooks/Weline_Websites/backend/channel/form/sections-after.phtml');
        self::assertStringContainsString('scope-theme-binding-section.phtml', $channelHook);
        self::assertStringContainsString('summarizeChannel', $channelHook);

        $partial = $this->read('view/templates/backend/partials/scope-theme-binding-section.phtml');
        self::assertStringContainsString('extensions[theme][theme_id]', $partial);
        self::assertStringContainsString('extensions[theme][version_id]', $partial);
        self::assertStringContainsString('<w:theme:select', $partial);
        self::assertStringContainsString('<w:theme:version:select', $partial);
        self::assertStringNotContainsString('<select class="w-select"', $partial);
        self::assertStringContainsString('店面主题', $partial);
        self::assertStringContainsString('data-testid="scope-theme-binding"', $partial);

        $list = $this->read('view/templates/backend/index.phtml');
        self::assertStringContainsString('网站 / 店铺 / 渠道信息 → 店面主题', $list);
        self::assertStringContainsString('Theme 注册的 Default', $list);
        self::assertStringContainsString('各网站/店铺/渠道店面主题在对应范围信息中配置', $list);
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        self::assertFileExists($path);
        $src = file_get_contents($path);
        self::assertIsString($src);

        return $src;
    }
}
