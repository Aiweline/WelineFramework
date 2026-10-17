<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Controller\Admin\Website;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesAdminContractsPass().
 */
final class WebsitesAdminContractsTest extends TestCase
{
    public function testAllWebsitesAdminContractsPass(): void
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
            'WebsiteAdminTemplate::testIndexTemplateUsesScopeTreeShell' => function () {
            $this->assert_WebsiteAdminTemplate_testIndexTemplateUsesScopeTreeShell();
        },
            'WebsiteAdminTemplate::testDatatablePartialDoesNotContainMutableStoreInputs' => function () {
            $this->assert_WebsiteAdminTemplate_testDatatablePartialDoesNotContainMutableStoreInputs();
        },
            'WebsiteAdminTemplate::testWebsiteFormScriptMountsComponentAfterDefine' => function () {
            $this->assert_WebsiteAdminTemplate_testWebsiteFormScriptMountsComponentAfterDefine();
        },
            'WebsiteAdminTemplate::testWebsiteFormTemplateBansLocaleCurrencyInSubPath' => function () {
            $this->assert_WebsiteAdminTemplate_testWebsiteFormTemplateBansLocaleCurrencyInSubPath();
        },
            'WebsiteAdminTemplate::testWebsiteControllerAssertsSubPathOnAddAndEdit' => function () {
            $this->assert_WebsiteAdminTemplate_testWebsiteControllerAssertsSubPathOnAddAndEdit();
        },
            'WebsiteAdminTemplate::testWebsiteFormTemplateUsesCollapsedTimezoneSelect' => function () {
            $this->assert_WebsiteAdminTemplate_testWebsiteFormTemplateUsesCollapsedTimezoneSelect();
        },
            'WebsiteAdminTemplate::testWebsiteFormTemplateUsesCurrencySelectTaglib' => function () {
            $this->assert_WebsiteAdminTemplate_testWebsiteFormTemplateUsesCurrencySelectTaglib();
        },
            'WebsiteAdminTemplate::testEmbeddedWebsiteFormSuppressesBlankLayoutPageHeader' => function () {
            $this->assert_WebsiteAdminTemplate_testEmbeddedWebsiteFormSuppressesBlankLayoutPageHeader();
        },
            'WebsiteAdminTemplate::testWebsiteFormHidesCardTitleWhenEmbedded' => function () {
            $this->assert_WebsiteAdminTemplate_testWebsiteFormHidesCardTitleWhenEmbedded();
        },
            'WebsiteAdminTemplate::testTreeEmbeddedWebsiteFormShowsSaveActions' => function () {
            $this->assert_WebsiteAdminTemplate_testTreeEmbeddedWebsiteFormShowsSaveActions();
        },
            'WebsiteAdminTemplate::testWebsiteFormKeepsWFormContiguousForFiberCapture' => function () {
            $this->assert_WebsiteAdminTemplate_testWebsiteFormKeepsWFormContiguousForFiberCapture();
        },
            'WebsiteAdminTemplate::testEditSaveUsesSnapshotCodeForStartPageConfig' => function () {
            $this->assert_WebsiteAdminTemplate_testEditSaveUsesSnapshotCodeForStartPageConfig();
        },
            'WebsiteAdminTemplate::testTreeSaveRedirectPreservesWebsitesRouterAndNode' => function () {
            $this->assert_WebsiteAdminTemplate_testTreeSaveRedirectPreservesWebsitesRouterAndNode();
        },
            'WebsiteEditTargetId::testResolverFallsBackFromIdToWebsiteId' => function () {
            $this->assert_WebsiteEditTargetId_testResolverFallsBackFromIdToWebsiteId();
        },
            'WebsiteEditTargetId::testEditUsesSharedResolverNotBareIdParam' => function () {
            $this->assert_WebsiteEditTargetId_testEditUsesSharedResolverNotBareIdParam();
        },
            'WebsiteEditTargetId::testRequireWebsiteIdAcceptsCanonicalZero' => function () {
            $this->assert_WebsiteEditTargetId_testRequireWebsiteIdAcceptsCanonicalZero();
        },
            'WebsiteMutationApiJson::testPrefersApiJsonDetectsAjaxHeaderAcceptAndWorkerReferer' => function () {
            $this->assert_WebsiteMutationApiJson_testPrefersApiJsonDetectsAjaxHeaderAcceptAndWorkerReferer();
        },
            'WebsiteMutationApiJson::testFinishWebsiteMutationReturnsJsonWhenApiPreferred' => function () {
            $this->assert_WebsiteMutationApiJson_testFinishWebsiteMutationReturnsJsonWhenApiPreferred();
        },
            'WebsiteMutationApiJson::testEditEarlyErrorsUseSharedOffcanvasResponder' => function () {
            $this->assert_WebsiteMutationApiJson_testEditEarlyErrorsUseSharedOffcanvasResponder();
        },
        ];
    }

private function assert_WebsiteAdminTemplate_testIndexTemplateUsesScopeTreeShell(): void
{
    $index = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/index.phtml');
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
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Controller/Admin/Website.php');
    self::assertStringContainsString('wantsTreeEditorPanel', $controller);
    self::assertStringContainsString('treeEditorPanelAjax', $controller);
    $asyncJs = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/statics/js/websites-scope-tree.js');
    self::assertStringContainsString("searchParams.set('panel', '1')", $asyncJs);
    self::assertStringContainsString('history.pushState', $asyncJs);
    self::assertStringContainsString('preventDefault', $asyncJs);
    self::assertStringContainsString('w-catalog-admin__layout', $index);
    self::assertStringContainsString('method="get"', $index);
    self::assertStringNotContainsString('<w:d-table', $index);
    self::assertStringNotContainsString('openWebsiteEditDrawer', $index);
    self::assertStringNotContainsString('alert(', $index);
    self::assertStringNotContainsString('confirm(', $index);
    $panel = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/tree-editor-panel.phtml');
    self::assertStringContainsString("fetch('Weline_Websites::templates/Admin/Website/form.phtml')", $panel);
    self::assertStringContainsString('tree-create-store.phtml', $panel);
    self::assertStringContainsString('tree-create-channel.phtml', $panel);
}

private function assert_WebsiteAdminTemplate_testDatatablePartialDoesNotContainMutableStoreInputs(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/datatable.phtml');
    self::assertStringContainsString('<w:d-table', $source);
    self::assertDoesNotMatchRegularExpression('/<(?:input|select|textarea)[^>]+name=["\'][^"\']*(?:store|channel)/i', $source);
}

private function assert_WebsiteAdminTemplate_testWebsiteFormScriptMountsComponentAfterDefine(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/statics/js/website-form.js');
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

private function assert_WebsiteAdminTemplate_testWebsiteFormTemplateBansLocaleCurrencyInSubPath(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/form.phtml');
    self::assertStringContainsString('data-sub-path-ban-languages', $source);
    self::assertStringContainsString('data-sub-path-ban-currencies', $source);
    self::assertStringContainsString('data-sub-path-ban-msg-language-prefix', $source);
    self::assertStringContainsString('data-sub-path-ban-msg-currency-prefix', $source);
    self::assertStringContainsString('data-w-sub-path-input', $source);
    self::assertStringContainsString('data-w-sub-path-error', $source);
    self::assertStringContainsString('禁止使用语言编码或货币编码', $source);
}

private function assert_WebsiteAdminTemplate_testWebsiteControllerAssertsSubPathOnAddAndEdit(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Controller/Admin/Website.php');
    self::assertStringContainsString('WebsiteSubPathValidator', $source);
    self::assertStringContainsString('assertValidSubPath', $source);
    self::assertStringContainsString('assignSubPathBanCatalog', $source);
    self::assertGreaterThanOrEqual(2, substr_count($source, 'assertValidSubPath((string)($data[\'sub_path\']'));
}

private function assert_WebsiteAdminTemplate_testWebsiteFormTemplateUsesCollapsedTimezoneSelect(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/form.phtml');
    self::assertStringContainsString('id="website_timezone_selector"', $source);
    self::assertStringContainsString('id="website_timezone_trigger"', $source);
    self::assertStringContainsString('data-w-timezone-label', $source);
    self::assertStringContainsString('data-w-timezone-field', $source);
    self::assertStringContainsString('name="default_timezone"', $source);
    self::assertStringContainsString('点击选择时区', $source);
    self::assertStringNotContainsString('size="6"', $source);
}

private function assert_WebsiteAdminTemplate_testWebsiteFormTemplateUsesCurrencySelectTaglib(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/form.phtml');
    self::assertStringContainsString('<w:currency:select', $source);
    self::assertStringContainsString('id="website_default_currency_selector"', $source);
    self::assertStringContainsString('name="default_currency"', $source);
    self::assertStringContainsString('id="website_related_currencies_selector"', $source);
    self::assertStringContainsString('name="currency_codes[]"', $source);
    self::assertStringContainsString('multiple="true"', $source);
    self::assertStringContainsString('weline-currency-select.css', $source);
    self::assertDoesNotMatchRegularExpression('/website_default_currency_selector[^>]+multiple="true"/', $source);
    self::assertStringNotContainsString('name="currency_codes[]" id="currency_codes" multiple>', $source);
    self::assertStringContainsString('<w:i18n:language:select', $source);
    self::assertStringContainsString('id="website_default_language_selector"', $source);
    self::assertStringContainsString('name="default_language"', $source);
    self::assertStringContainsString('id="website_related_languages_selector"', $source);
    self::assertStringContainsString('name="language_codes[]"', $source);
    self::assertDoesNotMatchRegularExpression('/website_default_language_selector[^>]+multiple="true"/', $source);
}

private function assert_WebsiteAdminTemplate_testEmbeddedWebsiteFormSuppressesBlankLayoutPageHeader(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Controller/Admin/Website.php');
    self::assertStringContainsString('suppressPageChromeForEmbeddedForm', $source);
    self::assertStringContainsString("assign('layoutShowPageHeader', false)", $source);
    self::assertStringContainsString("assign('is_embedded_form', true)", $source);
    self::assertStringContainsString('suppressPageChromeForEmbeddedForm((string)__(\'添加网站\'))', $source);
    self::assertStringContainsString('suppressPageChromeForEmbeddedForm((string)__(\'编辑网站\'))', $source);
}

private function assert_WebsiteAdminTemplate_testWebsiteFormHidesCardTitleWhenEmbedded(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/form.phtml');
    self::assertStringContainsString('$isEmbeddedForm', $source);
    self::assertStringContainsString('is-embedded', $source);
    self::assertStringContainsString('if (!$isEmbeddedForm)', $source);
    self::assertStringContainsString('w-card__title', $source);
}

private function assert_WebsiteAdminTemplate_testTreeEmbeddedWebsiteFormShowsSaveActions(): void
{
    $form = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/form.phtml');
    self::assertStringContainsString('$treeReturnMode', $form);
    self::assertStringContainsString('$showFormSaveActions = !$isEmbeddedForm || $treeReturnMode', $form);
    self::assertStringContainsString('data-testid="website-form-save"', $form);
    self::assertStringContainsString('id="website-admin-edit-form"', $form);
    self::assertStringContainsString('if (!$isEmbeddedForm):', $form);
    self::assertStringContainsString('Weline_Component::message.phtml', $form);
    $panel = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/tree-editor-panel.phtml');
    self::assertStringContainsString('data-testid="websites-scope-editor-save"', $panel);
    self::assertStringContainsString("'website' => 'website-admin-edit-form'", $panel);
    self::assertStringContainsString("'store' => 'store-admin-edit-form'", $panel);
    self::assertStringContainsString("'channel' => 'channel-admin-edit-form'", $panel);
    self::assertStringContainsString('data-scope-editor-save="1"', $panel);
}

private function assert_WebsiteAdminTemplate_testWebsiteFormKeepsWFormContiguousForFiberCapture(): void
{
    $form = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/view/templates/Admin/Website/form.phtml');
    self::assertStringContainsString('$websiteFormAction', $form);
    self::assertStringContainsString('<w:form action="<?= $websiteFormAction ?>"', $form);
    self::assertStringContainsString('</w:form>', $form);
    // Anti-pattern: open native <form> / <w:form> on opposite if/else branches then endif
    // before the shared body — Fiber try{ capture cannot cross endif.
    self::assertDoesNotMatchRegularExpression('/<\?php if \(\$useTreeFormAction\):\s*\?>\s*<form[\s\S]*?<\?php else:\s*\?>\s*<w:form/', $form);
    self::assertDoesNotMatchRegularExpression('/<\?php if \(\$useTreeFormAction\):\s*\?>\s*<\/form>\s*<\?php else:\s*\?>\s*<\/w:form>/', $form);
}

private function assert_WebsiteAdminTemplate_testEditSaveUsesSnapshotCodeForStartPageConfig(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Controller/Admin/Website.php');
    self::assertStringContainsString('schema_fields_CODE', $source);
    self::assertStringContainsString('saveStartPagePathConfig(', $source);
    self::assertMatchesRegularExpression('/saveStartPagePathConfig\(\s*\$postWebsiteId,\s*trim\(\(string\)\(\$before\[/', $source);
}

private function assert_WebsiteAdminTemplate_testTreeSaveRedirectPreservesWebsitesRouterAndNode(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Websites' . '/Controller/Admin/Website.php');
    self::assertStringContainsString('websitesAdminWebsiteIndexPath', $source);
    self::assertStringContainsString("\$router . '/admin/website'", $source);
    self::assertStringContainsString("\$router = 'websites'", $source);
    self::assertStringContainsString("\$params['node'] = \$returnNode", $source);
    self::assertStringContainsString('resolveTreeReturnTarget', $source);
    self::assertStringContainsString('tree_return_url', $source);
    self::assertStringContainsString('return_url', $source);
    self::assertStringNotContainsString("redirect('*/admin/website/index', \$params)", $source);
}

private function assert_WebsiteEditTargetId_testResolverFallsBackFromIdToWebsiteId(): void
{
    $resolver = $this->methodSource_WebsiteEditTargetId('resolveEditTargetWebsiteIdFromRequest');
    self::assertStringContainsString("getParam('id')", $resolver);
    self::assertStringContainsString("getParam('website_id')", $resolver);
    self::assertStringContainsString('requireWebsiteId($raw)', $resolver);
    self::assertMatchesRegularExpression('/\$raw\s*===\s*null\s*\|\|\s*\$raw\s*===\s*[\'"]{2}/', $resolver);
}

private function assert_WebsiteEditTargetId_testEditUsesSharedResolverNotBareIdParam(): void
{
    $edit = $this->methodSource_WebsiteEditTargetId('edit');
    self::assertStringContainsString('resolveEditTargetWebsiteIdFromRequest()', $edit);
    self::assertStringNotContainsString("requireWebsiteId(\$this->request->getParam('id'))", $edit);
    self::assertStringContainsString("getParam('website_id')", $edit);
}

private function assert_WebsiteEditTargetId_testRequireWebsiteIdAcceptsCanonicalZero(): void
{
    $require = $this->methodSource_WebsiteEditTargetId('requireWebsiteId');
    self::assertStringContainsString('/^(0|[1-9][0-9]*)$/D', $require);
    self::assertStringContainsString('$websiteId < 0', $require);
}

private function methodSource_WebsiteEditTargetId(string $methodName): string
{
    $method = new \ReflectionMethod(Website::class, $methodName);
    $fileName = $method->getFileName();
    self::assertIsString($fileName);
    $lines = file($fileName);
    self::assertIsArray($lines);
    return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}

private function assert_WebsiteMutationApiJson_testPrefersApiJsonDetectsAjaxHeaderAcceptAndWorkerReferer(): void
{
    $source = $this->methodSource_WebsiteMutationApiJson('prefersApiJsonResponse');
    self::assertStringContainsString('isAjax()', $source);
    self::assertStringContainsString("getHeader('X-Weline-Api'", $source);
    self::assertStringContainsString('application/json', $source);
    self::assertStringContainsString('weline-api-worker.js', $source);
}

private function assert_WebsiteMutationApiJson_testFinishWebsiteMutationReturnsJsonWhenApiPreferred(): void
{
    $source = $this->methodSource_WebsiteMutationApiJson('finishWebsiteMutation');
    self::assertStringContainsString('prefersApiJsonResponse()', $source);
    self::assertStringContainsString('fetchJson($payload)', $source);
    self::assertStringContainsString("'success' => !{$isError}", $source);
    self::assertStringContainsString("'website_id' => \$websiteId", $source);
    self::assertStringContainsString('noteApiWebsiteMutationCall', $source);
    self::assertStringContainsString("component/backend/offcanvas/getSuccess", $source);
    self::assertStringContainsString("component/backend/offcanvas/getError", $source);
}

private function assert_WebsiteMutationApiJson_testEditEarlyErrorsUseSharedOffcanvasResponder(): void
{
    $edit = $this->methodSource_WebsiteMutationApiJson('edit');
    self::assertStringContainsString('respondWebsiteOffcanvasError', $edit);
    self::assertStringNotContainsString("redirect('component/backend/offcanvas/getError'", $edit);
}

private function methodSource_WebsiteMutationApiJson(string $methodName): string
{
    $method = new \ReflectionMethod(Website::class, $methodName);
    $fileName = $method->getFileName();
    self::assertIsString($fileName);
    $lines = file($fileName);
    self::assertIsArray($lines);
    return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}
}
