<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Inquiry\Service\InquiryFormBootstrap;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllInquiryViewContractsPass().
 */
final class InquiryViewContractsTest extends TestCase
{
    public function testAllInquiryViewContractsPass(): void
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
            'SupplierApplication::testBootstrapDefinesSupplierApplicationCode' => function () {
            $this->assert_SupplierApplication_testBootstrapDefinesSupplierApplicationCode();
        },
            'SupplierApplication::testSuppliersTemplateRendersInquiryInline' => function () {
            $this->assert_SupplierApplication_testSuppliersTemplateRendersInquiryInline();
        },
            'SupplierApplication::testAtelierLayoutCssExists' => function () {
            $this->assert_SupplierApplication_testAtelierLayoutCssExists();
        },
            'SupplierApplication::testPrototypeAssetsRemoved' => function () {
            $this->assert_SupplierApplication_testPrototypeAssetsRemoved();
        },
            'SupplierApplication::testRendererSupportsSupplierSkinOption' => function () {
            $this->assert_SupplierApplication_testRendererSupportsSupplierSkinOption();
        },
            'SupplierApplication::testSupplierGuideEnglishCsvKeysAreUnquoted' => function () {
            $this->assert_SupplierApplication_testSupplierGuideEnglishCsvKeysAreUnquoted();
        },
            'SupplierApplication::testBootstrapUsesThemeCountryFieldType' => function () {
            $this->assert_SupplierApplication_testBootstrapUsesThemeCountryFieldType();
        },
            'SupplierApplication::testSuppliersControllerUsesDefaultLayout' => function () {
            $this->assert_SupplierApplication_testSuppliersControllerUsesDefaultLayout();
        },
            'SupplierApplication::testUpgradeCallsSupplierBootstrap' => function () {
            $this->assert_SupplierApplication_testUpgradeCallsSupplierBootstrap();
        },
            'SupplierApplication::testBackendEditorExposesCountryFieldType' => function () {
            $this->assert_SupplierApplication_testBackendEditorExposesCountryFieldType();
        },
            'SupplierApplication::testBackendEditorExposesAiTranslateAction' => function () {
            $this->assert_SupplierApplication_testBackendEditorExposesAiTranslateAction();
        },
            'SupplierApplication::testBackendEditorExposesVisualI18nForm' => function () {
            $this->assert_SupplierApplication_testBackendEditorExposesVisualI18nForm();
        },
            'SupplierApplication::testBackendEditorExposesBreadcrumbAndPagePadding' => function () {
            $this->assert_SupplierApplication_testBackendEditorExposesBreadcrumbAndPagePadding();
        },
        ];
    }

private function assert_SupplierApplication_testBootstrapDefinesSupplierApplicationCode(): void
{
    self::assertSame('supplier-application', InquiryFormBootstrap::CODE_SUPPLIER_APPLICATION);
}

private function assert_SupplierApplication_testSuppliersTemplateRendersInquiryInline(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/templates/frontend/suppliers.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="inquiry-supplier-application"', $src);
    self::assertStringContainsString('InquiryRendererInterface::class', $src);
    self::assertStringContainsString('InquiryFormBootstrap::CODE_SUPPLIER_APPLICATION', $src);
    self::assertStringContainsString("'mode' => 'inline'", $src);
    self::assertStringContainsString("'skin' => 'supplier'", $src);
    self::assertStringContainsString('w-inquiry-suppliers--atelier', $src);
    self::assertStringContainsString('w-inquiry-suppliers__shell', $src);
    self::assertStringContainsString('data-testid="inquiry-supplier-guide"', $src);
    self::assertStringContainsString('w-inquiry-suppliers__toc', $src);
    self::assertStringContainsString('id="supplier-s1"', $src);
    self::assertStringContainsString('id="supplier-s6"', $src);
    self::assertStringContainsString('id="supplier-s7"', $src);
    self::assertStringContainsString('w-inquiry-suppliers__process-list', $src);
    self::assertStringContainsString('Weline_Inquiry::css/supplier-application-atelier.css', $src);
    self::assertStringContainsString('supplier-application-atelier.css?v=20260907-stretch2', $src);
    self::assertStringNotContainsString('data-supplier-prototype-switcher', $src);
    self::assertStringNotContainsString('supplier-application-atelier.css', $src);
    self::assertStringNotContainsString('supplier-application-workspace.css', $src);
    self::assertStringNotContainsString('<w:inquiry', $src);
}

private function assert_SupplierApplication_testAtelierLayoutCssExists(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/statics/css/supplier-application-atelier.css';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('.w-inquiry-suppliers--atelier', $src);
    self::assertStringContainsString('.w-inquiry-suppliers__shell', $src);
    self::assertStringContainsString('.w-inquiry-suppliers__toc', $src);
    self::assertStringContainsString('.w-inquiry-suppliers__guide', $src);
    self::assertStringContainsString('.w-inquiry-suppliers__process-list', $src);
    self::assertStringContainsString('max-height: none', $src);
    self::assertStringContainsString('align-items: stretch', $src);
    self::assertStringContainsString('--gold', $src);
    self::assertStringNotContainsString('max-height: min(78vh, 52rem)', $src);
}

private function assert_SupplierApplication_testPrototypeAssetsRemoved(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/statics';
    self::assertFileDoesNotExist($base . '/css/supplier-application-atelier.css');
    self::assertFileDoesNotExist($base . '/css/supplier-application-workspace.css');
    self::assertFileDoesNotExist($base . '/css/supplier-prototype-switcher.css');
    self::assertFileDoesNotExist($base . '/js/supplier-prototype-switcher.js');
}

private function assert_SupplierApplication_testRendererSupportsSupplierSkinOption(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Service/InquiryRenderer.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("in_array(\$input, ['supplier'], true)", $src);
    self::assertStringContainsString('weline-inquiry--', $src);
    self::assertStringContainsString('data-inquiry-skin', $src);
    self::assertStringContainsString('weline-inquiry__submit', $src);
    self::assertStringContainsString('type==="country"', $src);
    self::assertStringContainsString('data-w-address', $src);
    self::assertStringContainsString('address-loader.js', $src);
    self::assertStringContainsString('20260907-district-single2', $src);
    self::assertStringContainsString('addressScript', $src);
    self::assertStringContainsString('data-inquiry-address-direct', $src);
    self::assertStringContainsString('ensureAddressLoader(function', $src);
    self::assertStringContainsString('setAttribute("data-w-address","1")', $src);
    self::assertStringContainsString('catalog:countryCatalog', $src);
    self::assertStringContainsString('data-catalog', $src);
    self::assertStringContainsString('selection:"single"', $src);
    self::assertStringContainsString('country|province|city|district', $src);
    self::assertStringContainsString('cascade:true', $src);
    self::assertStringContainsString("'locale' => \$this->requestLocale()", $src);
    self::assertStringContainsString('fromConfig', $src);
    self::assertStringContainsString('data-lang', $src);
    self::assertStringNotContainsString('for:"country",', $src);
    self::assertStringNotContainsString('names:{country:name}', $src);
    self::assertStringNotContainsString('cascade:false', $src);
}

private function assert_SupplierApplication_testSupplierGuideEnglishCsvKeysAreUnquoted(): void
{
    $base = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/i18n';
    $en = (string) file_get_contents($base . '/en_US.csv');
    $zh = (string) file_get_contents($base . '/zh_Hans_CN.csv');
    self::assertStringContainsString('"长安 · 供应合作","Chang\'an · Supplier partnerships"', $en);
    self::assertStringContainsString('把好工艺带进更多衣橱,"Bring craft into more wardrobes"', $en);
    self::assertStringContainsString('一、合作对象,"1. Who we partner with"', $en);
    self::assertStringNotContainsString("'长安 · 供应合作'", $en);
    self::assertStringNotContainsString("'一、合作对象'", $en);
    self::assertStringContainsString('"长安 · 供应合作","长安 · 供应合作"', $zh);
    self::assertStringNotContainsString("'长安 · 供应合作'", $zh);
}

private function assert_SupplierApplication_testBootstrapUsesThemeCountryFieldType(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Service/InquiryFormBootstrap.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("'catalog' => 'global'", $src);
    self::assertStringContainsString("'levels' => 'country|province|city|district'", $src);
    self::assertStringContainsString("'selection' => 'single'", $src);
    self::assertStringContainsString("['key' => 'district', 'type' => 'text', 'required' => false]", $src);
}

private function assert_SupplierApplication_testSuppliersControllerUsesDefaultLayout(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Controller/Suppliers.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("layoutType = 'default'", $src);
    self::assertStringContainsString('templates/frontend/suppliers.phtml', $src);
    self::assertStringContainsString('供应商申请', $src);
    self::assertStringContainsString('w-inquiry-suppliers-page--atelier', $src);
    self::assertStringNotContainsString('resolvePrototypeVariant', $src);
    self::assertStringNotContainsString("getGet('variant'", $src);
}

private function assert_SupplierApplication_testUpgradeCallsSupplierBootstrap(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Setup/Upgrade.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('InquiryFormBootstrap', $src);
    self::assertStringContainsString('ensureSupplierApplication', $src);
}

private function assert_SupplierApplication_testBackendEditorExposesCountryFieldType(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/templates/backend/Inquiry/edit.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="inquiry-editor"', $src);
    self::assertStringContainsString('FormSchemaService::FIELD_TYPES', $src);
    self::assertStringContainsString('inquiry-editor.css', $src);
    self::assertStringContainsString('data-iq-country', $src);
    self::assertStringContainsString('data-testid="inquiry-editor-type"', $src);
    self::assertStringNotContainsString('border rounded p-2 mb-2', $src);
    $css = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/statics/css/inquiry-editor.css';
    self::assertFileExists($css);
    $cssSrc = (string) file_get_contents($css);
    self::assertStringContainsString('.w-inquiry-editor__field', $cssSrc);
    self::assertStringContainsString('.is-country', $cssSrc);
    self::assertStringContainsString('grid-template-areas', $cssSrc);
    self::assertStringContainsString('w-inquiry-editor__cell--key', $src);
    self::assertStringContainsString('data-inquiry-editor-responsive', $src);
}

private function assert_SupplierApplication_testBackendEditorExposesAiTranslateAction(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/templates/backend/Inquiry/edit.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="inquiry-ai-translate"', $src);
    self::assertStringContainsString('adminAiTranslate', $src);
    self::assertStringContainsString('data-iq-ai-force', $src);
    $provider = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/extends/module/Weline_Framework/Query/InquiryQueryProvider.php';
    $psrc = (string) file_get_contents($provider);
    self::assertStringContainsString("'adminAiTranslate'", $psrc);
    self::assertStringContainsString('InquiryTranslationAiService', $psrc);
}

private function assert_SupplierApplication_testBackendEditorExposesVisualI18nForm(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/templates/backend/Inquiry/edit.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="inquiry-editor-guide"', $src);
    self::assertStringContainsString('data-testid="inquiry-locale-tabs"', $src);
    self::assertStringContainsString('data-testid="inquiry-i18n-form"', $src);
    self::assertStringContainsString('data-iq-copy="title"', $src);
    self::assertStringContainsString('data-iq-i18n-fields', $src);
    self::assertStringContainsString('data-iq-translations', $src);
}

private function assert_SupplierApplication_testBackendEditorExposesBreadcrumbAndPagePadding(): void
{
    $controller = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/Controller/Backend/Inquiry.php';
    $controllerSrc = (string) file_get_contents($controller);
    self::assertStringContainsString("assign('page_title'", $controllerSrc);
    $menu = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/etc/backend/menu.xml';
    $menuSrc = (string) file_get_contents($menu);
    self::assertStringContainsString('action="inquiry/backend/inquiry"', $menuSrc);
    self::assertStringNotContainsString('action="inquiry/backend/inquiry/index"', $menuSrc);
    $css = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/statics/css/inquiry-editor.css';
    $cssSrc = (string) file_get_contents($css);
    self::assertStringNotContainsString('--iq-pad', $cssSrc);
    self::assertStringNotContainsString('calc(100% + 2 * var(--iq-pad))', $cssSrc);
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Inquiry' . '/view/templates/backend/Inquiry/edit.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringNotContainsString('--iq-pad', $src);
    self::assertStringContainsString('w-inquiry-editor__chrome', $src);
    self::assertStringContainsString('data-iq-lede', $src);
    self::assertStringContainsString('data-iq-save', $src);
    self::assertStringNotContainsString('w-backend-page__title', $src);
    self::assertLessThanOrEqual(1, substr_count($src, '编辑询盘表单'));
}
}
