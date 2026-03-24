<?php

declare(strict_types=1);

namespace WeShop\Compliance\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class DefaultThemeCompliancePageTest extends TestCase
{
    public function testIndexPageKeepsComplianceCleanRouteLinksAndHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/compliance/index.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("getUrl('compliance/consent/save')", $template);
        $this->assertStringContainsString("getUrl('compliance/privacy')", $template);
        $this->assertStringContainsString("getUrl('compliance/consent')", $template);
        $this->assertStringContainsString('WeShop_Compliance::frontend::layouts::compliance-page::before', $template);
        $this->assertStringContainsString('WeShop_Compliance::frontend::partials::consent-item::after', $template);
    }

    public function testConsentPageKeepsConsentActionAndHooks(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/compliance/consent.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("getUrl('compliance/consent/save')", $template);
        $this->assertStringContainsString("getUrl('compliance/privacy')", $template);
        $this->assertStringContainsString('WeShop_Compliance::frontend::layouts::compliance-page::before', $template);
        $this->assertStringContainsString('WeShop_Compliance::frontend::partials::consent-item::after', $template);
    }

    public function testPrivacyPageKeepsNavigationLinksAndHook(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../../../../design/WeShop/default/frontend/pages/compliance/privacy.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("getUrl('compliance/consent')", $template);
        $this->assertStringContainsString("getUrl('compliance')", $template);
        $this->assertStringContainsString('WeShop_Compliance::frontend::layouts::compliance-page::before', $template);
    }
}
