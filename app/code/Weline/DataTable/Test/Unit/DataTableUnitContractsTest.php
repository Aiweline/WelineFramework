<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Helper\UiAssets;
use Weline\DataTable\Taglib\Field;
use Weline\DataTable\Taglib\Form;
use Weline\DataTable\Taglib\Table;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllDataTableUnitContractsPass().
 */
final class DataTableUnitContractsTest extends TestCase
{
    public function testAllDataTableUnitContractsPass(): void
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
            'TaglibFrontend::testTableAttrExposesFrontendContract' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testTableAttrExposesFrontendContract();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testFormAttrExposesFrontendContract' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testFormAttrExposesFrontendContract();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testTableCallbackEmitsProviderOperationsAndModelConfig' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testTableCallbackEmitsProviderOperationsAndModelConfig();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testTaglibPreservesExplicitAliasPrefixedHeaderFields' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testTaglibPreservesExplicitAliasPrefixedHeaderFields();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testTableFooterShowsCurrentPageSizeAndAccessibleSelector' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testTableFooterShowsCurrentPageSizeAndAccessibleSelector();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testDisplayColumnCompositionIsClientOnlyAndReversible' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testDisplayColumnCompositionIsClientOnlyAndReversible();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testTableDimensionsUseValidatedScalarAttributesWithoutInlineStyle' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testTableDimensionsUseValidatedScalarAttributesWithoutInlineStyle();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testStandaloneFormLoadsOnlyItsOwnedComponentBundle' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testStandaloneFormLoadsOnlyItsOwnedComponentBundle();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testFormCallbackInheritsFrontendOptionsFromTableContext' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testFormCallbackInheritsFrontendOptionsFromTableContext();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testLocalModeDisablesRemoteMutationAndExportCapabilities' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testLocalModeDisablesRemoteMutationAndExportCapabilities();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'TaglibFrontend::testStandaloneFormFieldUsesRenderStackContext' => function () {
            $this->setUp_TaglibFrontend();
            try {
                $this->assert_TaglibFrontend_testStandaloneFormFieldUsesRenderStackContext();
            } finally {
                $this->tearDown_TaglibFrontend();
            }
        },
            'UiTwoRoute::testDemoRoutesUseOnlyWelineUiWithoutCompatibilityGlobalsOrInlineUi' => function () {
            $this->assert_UiTwoRoute_testDemoRoutesUseOnlyWelineUiWithoutCompatibilityGlobalsOrInlineUi();
        },
            'UiTwoRoute::testDemoMigrationPreservesEveryOriginalCapabilityScenario' => function () {
            $this->assert_UiTwoRoute_testDemoMigrationPreservesEveryOriginalCapabilityScenario();
        },
            'UiTwoRoute::testBackendDemoRoutesUseTheSameWelineUiShellWithoutLosingTools' => function () {
            $this->assert_UiTwoRoute_testBackendDemoRoutesUseTheSameWelineUiShellWithoutLosingTools();
        },
        ];
    }

private function setUp_TaglibFrontend(): void
{
    parent::setUp();
    TableContext::clearAll();
    UiAssets::resetRequestState();
}

private function tearDown_TaglibFrontend(): void
{
    TableContext::clearAll();
    UiAssets::resetRequestState();
    parent::tearDown();
}

private function assert_TaglibFrontend_testTableAttrExposesFrontendContract(): void
{
    $attributes = Table::attr();
    $this->assertArrayHasKey('allow-frontend', $attributes);
    $this->assertArrayHasKey('api-provider', $attributes);
    $this->assertArrayHasKey('resource', $attributes);
    $this->assertArrayHasKey('dependencies', $attributes);
    $this->assertArrayHasKey('transaction', $attributes);
    $this->assertArrayHasKey('write-order', $attributes);
    $this->assertArrayHasKey('composite-write', $attributes);
    $this->assertArrayHasKey('filter-mode', $attributes);
    $this->assertArrayHasKey('filter-advanced', $attributes);
    $this->assertArrayHasKey('filter-collapsible', $attributes);
    $this->assertArrayHasKey('confirm-write', $attributes);
    $this->assertFalse($attributes['allow-frontend']);
    $this->assertFalse($attributes['api-provider']);
    $this->assertArrayNotHasKey('api-url', $attributes);
    $this->assertArrayNotHasKey('field-api-url', $attributes);
}

private function assert_TaglibFrontend_testFormAttrExposesFrontendContract(): void
{
    $attributes = Form::attr();
    $this->assertArrayHasKey('allow-frontend', $attributes);
    $this->assertArrayHasKey('api-provider', $attributes);
    $this->assertArrayHasKey('resource', $attributes);
    $this->assertArrayHasKey('dependencies', $attributes);
    $this->assertArrayHasKey('transaction', $attributes);
    $this->assertArrayHasKey('write-order', $attributes);
    $this->assertArrayHasKey('composite-write', $attributes);
    $this->assertArrayHasKey('confirm-write', $attributes);
    $this->assertArrayHasKey('auto_fields', $attributes);
    $this->assertArrayNotHasKey('api-url', $attributes);
    $this->assertArrayNotHasKey('field-api-url', $attributes);
}

private function assert_TaglibFrontend_testTableCallbackEmitsProviderOperationsAndModelConfig(): void
{
    $callback = Table::callback();
    $html = $callback('d-table', [], ['', '', '<w:t-header></w:t-header>'], ['id' => 'frontend-table', 'model' => 'Weline\DataTable\Model\TestUser as u, Weline\DataTable\Model\TestOrder as o', 'scope' => 'frontend-table-scope', 'allow-frontend' => 'true', 'api-provider' => 'datatable', 'dependencies' => 'u.id->o.user_id', 'transaction' => 'true']);
    $this->assertIsString($html);
    $this->assertStringContainsString('frontend-table', $html);
    $this->assertStringContainsString('data-w-datatable-action="config.open"', $html);
    $tableConfig = $this->configById_TaglibFrontend($html, 'frontend-table');
    $formConfig = $this->configById_TaglibFrontend($html, 'form-frontend-table');
    $this->assertSame('datatable', $tableConfig['apiProvider']);
    $this->assertSame('data', $tableConfig['operations']['data']);
    $this->assertSame('previewWrite', $tableConfig['operations']['previewWrite']);
    $this->assertSame('executeWrite', $tableConfig['operations']['executeWrite']);
    $this->assertSame('formFields', $formConfig['operations']['formFields']);
    $this->assertSame('demo.users,demo.orders', $tableConfig['resource']);
    $this->assertSame('u.id->o.user_id', $tableConfig['dependencies']);
    $this->assertTrue($tableConfig['transaction']);
    $this->assertSame('Weline\DataTable\Model\TestUser', $tableConfig['modelConfig']['models']['u']);
    $this->assertSame('Weline\DataTable\Model\TestOrder', $tableConfig['modelConfig']['models']['o']);
}

private function assert_TaglibFrontend_testTaglibPreservesExplicitAliasPrefixedHeaderFields(): void
{
    $source = <<<'HTML'
    <w:d-table
        id="join-contract"
        model="Weline\DataTable\Model\TestUser as u, Weline\DataTable\Model\TestOrder as o"
        join="left o on u.id=o.user_id"
        scope="join-contract"
        allow-frontend="true">
        <w:t-header>
            <w:field belong="t-header" name="u.name">User Name</w:field>
            <w:field belong="t-header" name="o.order_no">Order No</w:field>
        </w:t-header>
    </w:d-table>
    HTML;
    $taglib = ObjectManager::getInstance(Taglib::class);
    $template = ObjectManager::getInstance(Template::class);
    $html = $taglib->tagReplace($template, $source);
    $this->assertStringContainsString('data-field="u.name"', $html);
    $this->assertStringContainsString('data-field="o.order_no"', $html);
    $this->assertStringNotContainsString('data-field="phone"', $html);
}

private function assert_TaglibFrontend_testTableFooterShowsCurrentPageSizeAndAccessibleSelector(): void
{
    $callback = \Weline\DataTable\Taglib\TableFooter::callback();
    $html = $callback('t-footer', [], ['', '', ''], ['model' => 'Weline\DataTable\Model\TestUser', 'scope' => 'page-size-contract', 'page-size' => '6']);
    $this->assertStringContainsString('data-w-datatable-page-size-value>6</output>', $html);
    $this->assertStringContainsString('data-w-datatable-page-size aria-label="每页显示"', $html);
    $this->assertStringContainsString('<option value="10">10</option>', $html);
}

private function assert_TaglibFrontend_testDisplayColumnCompositionIsClientOnlyAndReversible(): void
{
    $source = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/DataTable' . '/view/statics/js/datatable-manager.js');
    $this->assertIsString($source);
    $this->assertStringContainsString('data-w-datatable-merge-field', $source);
    $this->assertStringContainsString("translate('独立显示')", $source);
    $this->assertStringContainsString('state.columnMerges = columnMerges', $source);
    $this->assertSame(2, substr_count($source, 'if (isLocal || capabilities.preferences !== true)'));
    $this->assertStringContainsString('fields: state.displayFields.map', $source);
}

private function assert_TaglibFrontend_testTableDimensionsUseValidatedScalarAttributesWithoutInlineStyle(): void
{
    $callback = Table::callback();
    $html = $callback('d-table', [], ['', '', '<w:t-header></w:t-header>'], ['id' => 'dimension-table', 'model' => 'Weline\DataTable\Model\TestUser', 'scope' => 'dimension-table-scope', 'height' => '36rem', 'width' => '100%']);
    $this->assertIsString($html);
    $this->assertStringContainsString('data-w-datatable-height="36rem"', $html);
    $this->assertStringContainsString('data-w-datatable-width="100%"', $html);
    $this->assertStringNotContainsString('style=', $html);
    $this->assertStringNotContainsString('--w-datatable-height:', $html);
    $this->assertStringNotContainsString('--w-datatable-width:', $html);
}

private function assert_TaglibFrontend_testStandaloneFormLoadsOnlyItsOwnedComponentBundle(): void
{
    $callback = Form::callback();
    $html = $callback('d-form', [], ['', '', ''], ['id' => 'standalone-form-assets', 'model' => 'Weline\DataTable\Model\TestUser', 'scope' => 'standalone-form-assets', 'form-mode' => 'inline']);
    $this->assertIsString($html);
    $this->assertStringContainsString('weline-datatable.css', $html);
    $this->assertStringContainsString('weline-datatable-form.js', $html);
    $this->assertMatchesRegularExpression('/weline-datatable\.css\?v=(?:dev_)?[a-f0-9]{12}(?:&amp;v=[a-f0-9]{12})?/', $html);
    $this->assertMatchesRegularExpression('/weline-datatable-form\.js\?v=(?:dev_)?[a-f0-9]{12}(?:&amp;v=[a-f0-9]{12})?/', $html);
    $this->assertStringNotContainsString('components/weline-datatable.js', $html);
}

private function assert_TaglibFrontend_testFormCallbackInheritsFrontendOptionsFromTableContext(): void
{
    TableContext::setTableContext('demo-multi-scope', ['id' => 'frontend-parent-table', 'scope' => 'demo-multi-scope', 'model' => 'Weline\DataTable\Model\TestUser as u, Weline\DataTable\Model\TestOrder as o', 'model_config' => ['models' => ['u' => 'Weline\DataTable\Model\TestUser', 'o' => 'Weline\DataTable\Model\TestOrder'], 'main_model' => 'Weline\DataTable\Model\TestUser', 'aliases' => ['Weline\DataTable\Model\TestUser' => 'u', 'Weline\DataTable\Model\TestOrder' => 'o']], 'allow-frontend' => true, 'api-provider' => 'datatable', 'dependencies' => 'u.id->o.user_id', 'transaction' => true]);
    $callback = Form::callback();
    $html = $callback('d-form', [], ['', '', '<fieldset id="u"><legend>User</legend></fieldset><fieldset id="o"><legend>Order</legend></fieldset>'], ['id' => 'frontend-child-form', 'scope' => 'demo-multi-scope', 'form-mode' => 'inline', 'title' => 'Child Form', 'allow-frontend' => 'true', 'auto_fields' => 'false']);
    $this->assertIsString($html);
    $this->assertStringContainsString('frontend-child-form', $html);
    $formConfig = $this->configById_TaglibFrontend($html, 'frontend-child-form');
    $this->assertSame('datatable', $formConfig['apiProvider']);
    $this->assertSame('u.id->o.user_id', $formConfig['dependencies']);
    $this->assertTrue($formConfig['transaction']);
    $this->assertSame('Weline\DataTable\Model\TestUser', $formConfig['modelConfig']['models']['u']);
    $this->assertSame('Weline\DataTable\Model\TestOrder', $formConfig['modelConfig']['models']['o']);
    $this->assertSame('u', $formConfig['modelConfig']['aliases']['Weline\DataTable\Model\TestUser']);
    $this->assertSame('o', $formConfig['modelConfig']['aliases']['Weline\DataTable\Model\TestOrder']);
}

private function assert_TaglibFrontend_testLocalModeDisablesRemoteMutationAndExportCapabilities(): void
{
    $html = Table::callback()('d-table', [], ['', '', '<w:t-header></w:t-header>'], ['id' => 'local-contract', 'model' => 'Weline\DataTable\Model\TestUser', 'scope' => 'local-contract', 'mode' => 'local', 'local-data-el' => '#local-contract-data', 'allow-frontend' => 'true']);
    $config = $this->configById_TaglibFrontend((string) $html, 'local-contract');
    $this->assertTrue($config['capabilities']['read']);
    $this->assertTrue($config['capabilities']['preferences']);
    $this->assertFalse($config['capabilities']['create']);
    $this->assertFalse($config['capabilities']['update']);
    $this->assertFalse($config['capabilities']['delete']);
    $this->assertFalse($config['capabilities']['export']);
    $this->assertStringContainsString('data-w-datatable-search-form', (string) $html);
    $this->assertStringNotContainsString('data-w-datatable-action="form.open"', (string) $html);
}

private function assert_TaglibFrontend_testStandaloneFormFieldUsesRenderStackContext(): void
{
    TableContext::pushChildTag('d-form', 'frontend-standalone-form', ['type' => 'd-form', 'scope' => 'frontend-standalone-form', 'model' => 'Weline\DataTable\Model\TestUser', 'attributes' => ['scope' => 'frontend-standalone-form', 'model' => 'Weline\DataTable\Model\TestUser', 'allow-frontend' => true]]);
    $callback = Field::callback();
    $html = $callback('field', [], ['', '', 'Name'], ['belong' => 'd-form', 'name' => 'name', 'type' => 'text', 'label' => 'Name']);
    TableContext::popTag();
    $this->assertIsString($html);
    $this->assertStringContainsString('data-field="name"', $html);
    $this->assertStringContainsString('name="name"', $html);
    $fieldConfig = $this->jsonAttributes_TaglibFrontend($html, 'data-w-field')[0] ?? [];
    $this->assertSame('d-form', $fieldConfig['belong'] ?? null);
    $this->assertSame('frontend-standalone-form', $fieldConfig['formId'] ?? null);
}

/** @return array<string,mixed> */
private function configById_TaglibFrontend(string $html, string $id): array
{
    foreach ($this->jsonAttributes_TaglibFrontend($html, 'data-w-config') as $config) {
        if (($config['id'] ?? null) === $id) {
            return $config;
        }
    }
    $this->fail('Missing data-w-config for ' . $id);
}

/** @return list<array<string,mixed>> */
private function jsonAttributes_TaglibFrontend(string $html, string $attribute): array
{
    preg_match_all('/\b' . preg_quote($attribute, '/') . '="([^"]*)"/u', $html, $matches);
    $configs = [];
    foreach ($matches[1] ?? [] as $encoded) {
        $decoded = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($value)) {
            $configs[] = $value;
        }
    }
    return $configs;
}

private function assert_UiTwoRoute_testDemoRoutesUseOnlyWelineUiWithoutCompatibilityGlobalsOrInlineUi(): void
{
    $frontendController = $this->read_UiTwoRoute('app/code/Weline/DataTable/Controller/Test.php');
    self::assertStringContainsString('$this->renderDefaultLayout(', $frontendController);
    self::assertStringNotContainsString('return $this->template(', $frontendController);
    $controller = $this->read_UiTwoRoute('app/code/Weline/DataTable/Controller/Backend/Test/Comprehensive.php');
    self::assertStringNotContainsString('buildFrontendApiBootstrap', $controller);
    self::assertStringNotContainsString('DataTableManager', $controller);
    self::assertStringNotContainsString('DataTableFormManager', $controller);
    self::assertStringNotContainsString('window.$', $controller);
    self::assertStringNotContainsString('window.jQuery', $controller);
    self::assertStringNotContainsString('mdi mdi-', $controller);
    $templates = glob(BP . '/app/code/Weline/DataTable/view/templates/frontend/test/*.phtml');
    self::assertIsArray($templates);
    $templates = array_values(array_filter($templates, static fn(string $path): bool => !str_starts_with(basename($path), '_')));
    self::assertCount(9, $templates);
    foreach ($templates as $path) {
        $content = file_get_contents($path);
        self::assertIsString($content, $path);
        self::assertStringNotContainsString('<style', $content, $path);
        self::assertDoesNotMatchRegularExpression('/<script(?!\s+type="(?:module|application\/json)")/i', $content, $path);
        self::assertStringNotContainsString('DataTableManager', $content, $path);
        self::assertStringNotContainsString('DataTableFormManager', $content, $path);
        self::assertStringNotContainsString('<main', $content, $path);
        self::assertDoesNotMatchRegularExpression('/class="[^"]*(?:^|\s)(?:btn|card|row|col-[^\s"]*|form-control)(?:\s|$)/i', $content, $path);
    }
    $datatableCss = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/statics/css/datatable.css');
    self::assertStringContainsString('contain: inline-size paint;', $datatableCss);
    self::assertStringContainsString('overscroll-behavior-inline: contain;', $datatableCss);
    self::assertStringContainsString('overflow: auto;', $datatableCss);
    $manager = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/statics/js/datatable-manager.js');
    self::assertStringContainsString("dataset.wSticky = 'end'", $manager);
    self::assertStringContainsString('data-w-sticky-end', $manager);
    self::assertStringContainsString('config.showActions !== false && hasRowActions', $manager);
    self::assertStringContainsString("table.removeAttribute('data-w-sticky-end')", $manager);
    self::assertStringContainsString("--w-datatable-column-count", $manager);
    self::assertStringContainsString('--w-datatable-column-min', $datatableCss);
}

private function assert_UiTwoRoute_testDemoMigrationPreservesEveryOriginalCapabilityScenario(): void
{
    $basic = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/templates/frontend/test/basic.phtml');
    self::assertStringContainsString('id="demo-basic-table"', $basic);
    self::assertStringContainsString('editable="true"', $basic);
    self::assertStringContainsString('searchable="true"', $basic);
    self::assertStringContainsString('sortable="true"', $basic);
    self::assertStringContainsString('id="demo-basic-form"', $basic);
    $form = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/templates/frontend/test/form.phtml');
    self::assertStringContainsString('id="demo-standalone-form"', $form);
    self::assertStringContainsString('id="demo-standalone-table"', $form);
    $upload = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/templates/frontend/test/upload.phtml');
    self::assertStringContainsString('include_fields="name,email,photo,attachment"', $upload);
    self::assertStringContainsString('id="demo-upload-table"', $upload);
    $transaction = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/templates/frontend/test/transaction.phtml');
    self::assertStringContainsString('dependencies="u.id->o.user_id"', $transaction);
    self::assertStringContainsString('transaction="true"', $transaction);
    self::assertStringContainsString('id="demo-transaction-table"', $transaction);
    $dependency = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/templates/frontend/test/dependency.phtml');
    self::assertStringContainsString('dependencies="u.id->o.user_id"', $dependency);
    self::assertStringContainsString('transaction="false"', $dependency);
    self::assertStringContainsString('id="demo-dependency-table"', $dependency);
    $cascade = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/templates/frontend/test/cascade.phtml');
    self::assertStringContainsString('id="demo-cascade-users"', $cascade);
    self::assertStringContainsString('id="demo-cascade-orders"', $cascade);
    self::assertStringContainsString('data-w-datatable-demo-action="refresh-cascade"', $cascade);
    $performance = $this->read_UiTwoRoute('app/code/Weline/DataTable/view/templates/frontend/test/performance.phtml');
    self::assertStringContainsString('id="demo-performance-table"', $performance);
    self::assertStringContainsString('show-config="true"', $performance);
    self::assertStringContainsString('data-w-datatable-demo-action="reload-performance"', $performance);
    self::assertStringContainsString('id="demo-local-table"', $performance);
    self::assertStringContainsString('filter-mode="client"', $performance);
}

private function assert_UiTwoRoute_testBackendDemoRoutesUseTheSameWelineUiShellWithoutLosingTools(): void
{
    foreach (['app/code/Weline/DataTable/Controller/Backend/Test/Index.php', 'app/code/Weline/DataTable/Controller/Backend/Test/TagTest.php'] as $controllerPath) {
        $controller = $this->read_UiTwoRoute($controllerPath);
        self::assertStringNotContainsString('mdi mdi-', $controller, $controllerPath);
    }
    $templates = ['app/code/Weline/DataTable/view/backend/templates/test/index.phtml', 'app/code/Weline/DataTable/view/backend/templates/test/doc.phtml', 'app/code/Weline/DataTable/view/backend/templates/test/tag-test.phtml', 'app/code/Weline/DataTable/view/backend/templates/test/layout-switcher.phtml', 'app/code/Weline/DataTable/view/templates/Test/Comprehensive/index.phtml', 'app/code/Weline/DataTable/view/templates/Test/Comprehensive/inheritance.phtml'];
    foreach ($templates as $templatePath) {
        $content = $this->read_UiTwoRoute($templatePath);
        self::assertStringNotContainsString('<style', $content, $templatePath);
        self::assertDoesNotMatchRegularExpression('/<script(?!\s+type="application\/json")/i', $content, $templatePath);
        self::assertDoesNotMatchRegularExpression('/\bmdi(?:\s|-)\b/i', $content, $templatePath);
        self::assertDoesNotMatchRegularExpression('/class="[^"]*(?<![a-z0-9_-])(?:btn|card|row|col-[a-z0-9_-]+|d-flex|form-control|text-muted|mt-[0-9]+|mb-[0-9]+)(?![a-z0-9_-])/i', $content, $templatePath);
    }
    $dashboard = $this->read_UiTwoRoute($templates[0]);
    self::assertStringContainsString('data-testid="datatable-admin-init"', $dashboard);
    self::assertStringContainsString('data-testid="datatable-admin-clear"', $dashboard);
    self::assertStringContainsString('$scenarios', $dashboard);
    self::assertStringContainsString('$models', $dashboard);
    self::assertStringContainsString('$docs', $dashboard);
    $tagVerification = $this->read_UiTwoRoute($templates[2]);
    self::assertStringContainsString('$report', $tagVerification);
    self::assertStringContainsString('data-testid="datatable-tag-refresh"', $tagVerification);
    $comprehensive = $this->read_UiTwoRoute($templates[4]);
    self::assertStringContainsString('data-testid="backend-demo-init"', $comprehensive);
    self::assertStringContainsString('data-testid="backend-demo-clear"', $comprehensive);
    self::assertStringContainsString('data-testid="backend-demo-verify"', $comprehensive);
    $inheritance = $this->read_UiTwoRoute($templates[5]);
    self::assertStringContainsString('data-testid="datatable-inheritance-run"', $inheritance);
    $manifest = json_decode($this->read_UiTwoRoute('app/code/Weline/Theme/etc/weline-ui-assets.json'), true, 512, JSON_THROW_ON_ERROR);
    self::assertArrayHasKey('datatable.backend.demo', $manifest['routes'] ?? []);
    self::assertContains('datatable-demo-admin-js', $manifest['routes']['datatable.backend.demo']['bundles'] ?? []);
    foreach ($templates as $templatePath) {
        self::assertContains($templatePath, $manifest['routes']['datatable.backend.demo']['templates'] ?? []);
    }
    foreach (['weline-datatable.js', 'weline-datatable-form.js'] as $compiledModule) {
        self::assertMatchesRegularExpression("/from '\\.\\/datatable-common\\.js\\?v=[a-f0-9]{12}';/", $this->read_UiTwoRoute('app/code/Weline/Theme/view/statics/ui/components/' . $compiledModule));
    }
}

private function read_UiTwoRoute(string $path): string
{
    $content = file_get_contents(BP . '/' . $path);
    self::assertIsString($content, $path);
    return $content;
}
}
