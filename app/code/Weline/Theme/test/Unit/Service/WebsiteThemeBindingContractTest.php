<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Static contracts for Website-scoped theme binding on website info form.
 */
final class WebsiteThemeBindingContractTest extends TestCase
{
    public function testServiceOwnsWebsiteBindingAndDefaultOnlyGlobalSync(): void
    {
        $service = $this->read('Service/WebsiteThemeBindingService.php');
        self::assertStringContainsString('class WebsiteThemeBindingService', $service);
        self::assertStringContainsString('function summarize', $service);
        self::assertStringContainsString('function bindThemeForWebsite', $service);
        self::assertStringContainsString('function scheduleSaveFromWebsiteForm', $service);
        self::assertStringContainsString('afterCommit', $service);
        self::assertStringContainsString('ScopeIdentity::website', $service);
        self::assertStringContainsString('RESOURCE_THEME_BINDING', $service);
        self::assertStringContainsString('function isDefaultTheme', $service);

        $context = $this->read('Service/ThemeContextService.php');
        self::assertStringContainsString('Intentionally no-op', $context);
        self::assertStringContainsString('must NOT rewrite Global theme_binding', $context);
        self::assertStringContainsString('stomps per-site bindings', $context);

        $observer = $this->read('Observer/WebsiteSaveAfter.php');
        self::assertStringContainsString('WebsiteThemeBindingService', $observer);
        self::assertStringContainsString('scheduleSaveFromWebsiteForm', $observer);

        $eventXml = $this->read('etc/event.xml');
        self::assertStringContainsString('Weline_Websites::website_save_after', $eventXml);
        self::assertStringContainsString('Weline\\Theme\\Observer\\WebsiteSaveAfter', $eventXml);

        $hook = $this->read('view/hooks/Weline_Websites/backend/website/form/sections-after.phtml');
        self::assertStringContainsString('extensions[theme][theme_id]', $hook);
        self::assertStringContainsString('extensions[theme][version_id]', $hook);
        self::assertStringContainsString('店面主题', $hook);

        $list = $this->read('view/templates/backend/index.phtml');
        self::assertStringContainsString('网站信息 → 店面主题', $list);
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
