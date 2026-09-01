<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;

final class WebsiteAdminTemplateContractTest extends TestCase
{
    public function testIndexTemplateUsesDataTableComponent(): void
    {
        $index = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/index.phtml',
        );
        $datatable = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/datatable.phtml',
        );
        self::assertStringContainsString('templates/Admin/Website/datatable.phtml', $index);
        self::assertStringContainsString('<w:d-table', $datatable);
        self::assertStringContainsString('mode="local"', $datatable);
        self::assertStringContainsString('name="site_head"', $datatable);
        self::assertStringContainsString('name="access_entry"', $datatable);
        self::assertStringContainsString('name="market_cluster"', $datatable);
        self::assertStringContainsString('name="store_channel_summary"', $datatable);
        self::assertStringContainsString('website-admin-local-rows', $datatable);
        self::assertStringContainsString('weline:datatable:row-action', $index);
        self::assertStringContainsString("getElementById('w-datatable-website-admin-list')", $index);
        self::assertStringContainsString('openWebsiteEditDrawer', $index);
        self::assertStringContainsString('method="get"', $index);
        self::assertStringNotContainsString('weline-websites-compact-table', $index);
        self::assertStringNotContainsString('alert(', $index);
        self::assertStringNotContainsString('confirm(', $index);
    }

    public function testDatatablePartialDoesNotContainMutableStoreInputs(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/datatable.phtml',
        );
        self::assertStringContainsString('<w:d-table', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<(?:input|select|textarea)[^>]+name=["\'][^"\']*(?:store|channel)/i',
            $source,
        );
    }

    public function testWebsiteFormScriptMountsComponentAfterDefine(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/statics/js/website-form.js',
        );
        self::assertStringContainsString("UI.define('website-form'", $source);
        self::assertStringContainsString('UI.mount(document)', $source);
        self::assertStringContainsString('weline:ui:ready', $source);
        self::assertStringContainsString('syncCurrencies', $source);
        self::assertStringContainsString('weline:ui:currency-select:change', $source);
        self::assertStringContainsString('ensureFormSelectComponents', $source);
        self::assertStringContainsString('remountFormSelects', $source);
        self::assertStringContainsString('weline-currency-select.js', $source);
        self::assertStringContainsString('already (defined|registered)', $source);
        self::assertStringContainsString('website_timezone_selector', $source);
        self::assertStringContainsString('openTimezone', $source);
        self::assertStringContainsString('data-w-timezone-label', $source);
    }

    public function testWebsiteFormTemplateUsesCollapsedTimezoneSelect(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/form.phtml',
        );
        self::assertStringContainsString('id="website_timezone_selector"', $source);
        self::assertStringContainsString('id="website_timezone_trigger"', $source);
        self::assertStringContainsString('data-w-timezone-label', $source);
        self::assertStringContainsString('data-w-timezone-field', $source);
        self::assertStringContainsString('name="default_timezone"', $source);
        self::assertStringContainsString('点击选择时区', $source);
        self::assertStringNotContainsString('size="6"', $source);
    }

    public function testWebsiteFormTemplateUsesCurrencySelectTaglib(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/form.phtml',
        );

        self::assertStringContainsString('<w:currency:select', $source);
        self::assertStringContainsString('id="website_default_currency_selector"', $source);
        self::assertStringContainsString('name="default_currency"', $source);
        self::assertStringContainsString('id="website_related_currencies_selector"', $source);
        self::assertStringContainsString('name="currency_codes[]"', $source);
        self::assertStringContainsString('multiple="true"', $source);
        self::assertStringContainsString('weline-currency-select.css', $source);
        self::assertDoesNotMatchRegularExpression(
            '/website_default_currency_selector[^>]+multiple="true"/',
            $source,
        );
        self::assertStringNotContainsString('name="currency_codes[]" id="currency_codes" multiple>', $source);

        self::assertStringContainsString('<w:i18n:language:select', $source);
        self::assertStringContainsString('id="website_default_language_selector"', $source);
        self::assertStringContainsString('name="default_language"', $source);
        self::assertStringContainsString('id="website_related_languages_selector"', $source);
        self::assertStringContainsString('name="language_codes[]"', $source);
        self::assertDoesNotMatchRegularExpression(
            '/website_default_language_selector[^>]+multiple="true"/',
            $source,
        );
    }

    public function testEmbeddedWebsiteFormSuppressesBlankLayoutPageHeader(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Admin/Website.php',
        );
        self::assertStringContainsString('suppressPageChromeForEmbeddedForm', $source);
        self::assertStringContainsString("assign('layoutShowPageHeader', false)", $source);
        self::assertStringContainsString("assign('is_embedded_form', true)", $source);
        self::assertStringContainsString('suppressPageChromeForEmbeddedForm((string)__(\'添加网站\'))', $source);
        self::assertStringContainsString('suppressPageChromeForEmbeddedForm((string)__(\'编辑网站\'))', $source);
    }

    public function testWebsiteFormHidesCardTitleWhenEmbedded(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/form.phtml',
        );
        self::assertStringContainsString('$isEmbeddedForm', $source);
        self::assertStringContainsString('is-embedded', $source);
        self::assertStringContainsString('if (!$isEmbeddedForm)', $source);
        self::assertStringContainsString('w-card__title', $source);
    }
}
