<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;

final class WebsiteAdminTemplateContractTest extends TestCase
{
    public function testIndexTemplateUsesScopeTreeShell(): void
    {
        $index = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/index.phtml',
        );
        self::assertStringContainsString('data-testid="websites-scope-tree"', $index);
        self::assertStringContainsString('data-w-component="tree"', $index);
        self::assertStringContainsString('data-w-tree-toggle', $index);
        self::assertStringContainsString('data-testid="websites-scope-editor"', $index);
        self::assertStringContainsString('w-catalog-tree__thumb', $index);
        self::assertStringContainsString('data-testid="websites-scope-tree-logo"', $index);
        self::assertStringContainsString('data-testid="websites-scope-tree-scope"', $index);
        self::assertStringContainsString('websites-scope-tree.js', $index);
        self::assertStringContainsString('tree-editor-panel.phtml', $index);
        // Logo 必须出现在范围徽章之前（DOM 源码顺序）
        $logoPos = strpos($index, 'data-testid="websites-scope-tree-logo"');
        $scopePos = strpos($index, 'data-testid="websites-scope-tree-scope"');
        self::assertNotFalse($logoPos);
        self::assertNotFalse($scopePos);
        self::assertLessThan($scopePos, $logoPos);

        $controller = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Admin/Website.php',
        );
        self::assertStringContainsString('wantsTreeEditorPanel', $controller);
        self::assertStringContainsString('treeEditorPanelAjax', $controller);

        $asyncJs = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/statics/js/websites-scope-tree.js',
        );
        self::assertStringContainsString("searchParams.set('panel', '1')", $asyncJs);
        self::assertStringContainsString('history.pushState', $asyncJs);
        self::assertStringContainsString('preventDefault', $asyncJs);
        self::assertStringContainsString('w-catalog-admin__layout', $index);
        self::assertStringContainsString('method="get"', $index);
        self::assertStringNotContainsString('<w:d-table', $index);
        self::assertStringNotContainsString('openWebsiteEditDrawer', $index);
        self::assertStringNotContainsString('alert(', $index);
        self::assertStringNotContainsString('confirm(', $index);

        $panel = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/tree-editor-panel.phtml',
        );
        self::assertStringContainsString("fetch('Weline_Websites::templates/Admin/Website/form.phtml')", $panel);
        self::assertStringContainsString('tree-create-store.phtml', $panel);
        self::assertStringContainsString('tree-create-channel.phtml', $panel);
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
        self::assertStringContainsString('validateSubPathValue', $source);
        self::assertStringContainsString('scheduleSubPathValidation', $source);
        self::assertStringContainsString('subPathBanCurrencies', $source);
        self::assertStringContainsString('setTimeout(runSubPathValidation, 300)', $source);
    }

    public function testWebsiteFormTemplateBansLocaleCurrencyInSubPath(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/form.phtml',
        );
        self::assertStringContainsString('data-sub-path-ban-languages', $source);
        self::assertStringContainsString('data-sub-path-ban-currencies', $source);
        self::assertStringContainsString('data-sub-path-ban-msg-language-prefix', $source);
        self::assertStringContainsString('data-sub-path-ban-msg-currency-prefix', $source);
        self::assertStringContainsString('data-w-sub-path-input', $source);
        self::assertStringContainsString('data-w-sub-path-error', $source);
        self::assertStringContainsString('禁止使用语言编码或货币编码', $source);
    }

    public function testWebsiteControllerAssertsSubPathOnAddAndEdit(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Admin/Website.php',
        );
        self::assertStringContainsString('WebsiteSubPathValidator', $source);
        self::assertStringContainsString('assertValidSubPath', $source);
        self::assertStringContainsString('assignSubPathBanCatalog', $source);
        self::assertGreaterThanOrEqual(2, substr_count($source, 'assertValidSubPath((string)($data[\'sub_path\']'));
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

    public function testTreeEmbeddedWebsiteFormShowsSaveActions(): void
    {
        $form = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/form.phtml',
        );
        self::assertStringContainsString('$treeReturnMode', $form);
        self::assertStringContainsString('$showFormSaveActions = !$isEmbeddedForm || $treeReturnMode', $form);
        self::assertStringContainsString('data-testid="website-form-save"', $form);
        self::assertStringContainsString('id="website-admin-edit-form"', $form);
        self::assertStringContainsString('if (!$isEmbeddedForm):', $form);
        self::assertStringContainsString('Weline_Component::message.phtml', $form);

        $panel = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/tree-editor-panel.phtml',
        );
        self::assertStringContainsString('data-testid="websites-scope-editor-save"', $panel);
        self::assertStringContainsString('form="website-admin-edit-form"', $panel);
        self::assertStringContainsString("editorKind === 'website'", $panel);
    }

    public function testWebsiteFormKeepsWFormContiguousForFiberCapture(): void
    {
        $form = (string)file_get_contents(
            dirname(__DIR__, 4) . '/view/templates/Admin/Website/form.phtml',
        );
        self::assertStringContainsString('$websiteFormAction', $form);
        self::assertStringContainsString('<w:form action="<?= $websiteFormAction ?>"', $form);
        self::assertStringContainsString('</w:form>', $form);
        // Anti-pattern: open native <form> / <w:form> on opposite if/else branches then endif
        // before the shared body — Fiber try{ capture cannot cross endif.
        self::assertDoesNotMatchRegularExpression(
            '/<\?php if \(\$useTreeFormAction\):\s*\?>\s*<form[\s\S]*?<\?php else:\s*\?>\s*<w:form/',
            $form,
        );
        self::assertDoesNotMatchRegularExpression(
            '/<\?php if \(\$useTreeFormAction\):\s*\?>\s*<\/form>\s*<\?php else:\s*\?>\s*<\/w:form>/',
            $form,
        );
    }

    public function testEditSaveUsesSnapshotCodeForStartPageConfig(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Admin/Website.php',
        );
        self::assertStringContainsString('schema_fields_CODE', $source);
        self::assertStringContainsString('saveStartPagePathConfig(', $source);
        self::assertMatchesRegularExpression(
            '/saveStartPagePathConfig\(\s*\$postWebsiteId,\s*trim\(\(string\)\(\$before\[/',
            $source,
        );
    }

    public function testTreeSaveRedirectPreservesWebsitesRouterAndNode(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Controller/Admin/Website.php',
        );
        self::assertStringContainsString('websitesAdminWebsiteIndexPath', $source);
        self::assertStringContainsString("\$router . '/admin/website'", $source);
        self::assertStringContainsString("\$router = 'websites'", $source);
        self::assertStringContainsString("\$params['node'] = \$returnNode", $source);
        self::assertStringContainsString('resolveTreeReturnTarget', $source);
        self::assertStringContainsString('tree_return_url', $source);
        self::assertStringContainsString('return_url', $source);
        self::assertStringNotContainsString("redirect('*/admin/website/index', \$params)", $source);
    }
}
