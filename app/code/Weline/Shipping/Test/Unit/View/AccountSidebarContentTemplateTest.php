<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarContentTemplateTest extends TestCase
{
    public function testMarkupRenderingClosuresKeepTemplateObjectBinding(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/account.sidebar.content.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('$renderAddressCard = function', $content);
        $this->assertStringContainsString('$renderAddressForm = function', $content);
        $this->assertStringNotContainsString('$renderAddressCard = static function', $content);
        $this->assertStringNotContainsString('$renderAddressForm = static function', $content);
        $this->assertStringContainsString('<w:form', $content);
        $this->assertStringContainsString('<w:theme:address', $content);
    }

    public function testAddressRequestDropsBlankIdsAndSerializesExistingIdsAsIntegers(): void
    {
        $scriptFile = dirname(__DIR__, 3) . '/view/statics/frontend/js/account-address-v3.js';

        $this->assertFileExists($scriptFile);
        $content = (string) file_get_contents($scriptFile);

        $this->assertStringContainsString('function normalizeAddressPayload(payload)', $content);
        $this->assertStringContainsString("delete payload[field];", $content);
        $this->assertStringContainsString("payload[field] = Number(value);", $content);
        $this->assertStringContainsString('body = normalizeAddressPayload(body);', $content);
        $this->assertSame(2, substr_count($content, 'upsertCard(panel, data.data || {});'));
    }

    public function testAddressCardStylesLoadFromAStaticAsset(): void
    {
        $moduleDirectory = dirname(__DIR__, 3);
        $templateFile = $moduleDirectory . '/view/hooks/account.sidebar.content.phtml';
        $stylesheetFile = $moduleDirectory . '/view/statics/frontend/css/account-address.css';

        $template = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('Weline_Shipping::frontend/css/account-address.css', $template);
        $this->assertStringNotContainsString('<style>', $template);
        $this->assertFileExists($stylesheetFile);

        $stylesheet = (string) file_get_contents($stylesheetFile);
        $this->assertStringContainsString('.account-address-card__value', $stylesheet);
        $this->assertStringContainsString('.account-address-part__icon svg', $stylesheet);
    }
}
