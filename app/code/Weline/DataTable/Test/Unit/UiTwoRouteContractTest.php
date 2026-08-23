<?php

declare(strict_types=1);

namespace Weline\DataTable\Test\Unit;

use Weline\Framework\Test\TestCore;

final class UiTwoRouteContractTest extends TestCore
{
    public function testDemoRoutesUseOnlyWelineUiWithoutCompatibilityGlobalsOrInlineUi(): void
    {
        $frontendController = $this->read('app/code/Weline/DataTable/Controller/Test.php');
        self::assertStringContainsString('$this->renderDefaultLayout(', $frontendController);
        self::assertStringNotContainsString('return $this->template(', $frontendController);

        $controller = $this->read('app/code/Weline/DataTable/Controller/Backend/Test/Comprehensive.php');
        self::assertStringNotContainsString('buildFrontendApiBootstrap', $controller);
        self::assertStringNotContainsString('DataTableManager', $controller);
        self::assertStringNotContainsString('DataTableFormManager', $controller);
        self::assertStringNotContainsString('window.$', $controller);
        self::assertStringNotContainsString('window.jQuery', $controller);
        self::assertStringNotContainsString('mdi mdi-', $controller);

        $templates = glob(BP . '/app/code/Weline/DataTable/view/templates/frontend/test/*.phtml');
        self::assertIsArray($templates);
        self::assertCount(9, $templates);
        foreach ($templates as $path) {
            $content = file_get_contents($path);
            self::assertIsString($content, $path);
            self::assertStringNotContainsString('<style', $content, $path);
            self::assertDoesNotMatchRegularExpression(
                '/<script(?!\s+type="(?:module|application\/json)")/i',
                $content,
                $path
            );
            self::assertStringNotContainsString('DataTableManager', $content, $path);
            self::assertStringNotContainsString('DataTableFormManager', $content, $path);
            self::assertStringNotContainsString('<main', $content, $path);
            self::assertDoesNotMatchRegularExpression(
                '/class="[^"]*(?:^|\s)(?:btn|card|row|col-[^\s"]*|form-control)(?:\s|$)/i',
                $content,
                $path
            );
        }

        $datatableCss = $this->read('app/code/Weline/DataTable/view/statics/css/datatable.css');
        self::assertStringContainsString('contain: inline-size paint;', $datatableCss);
        self::assertStringContainsString('overscroll-behavior-inline: contain;', $datatableCss);
        self::assertStringContainsString('overflow: auto;', $datatableCss);

        $manager = $this->read('app/code/Weline/DataTable/view/statics/js/datatable-manager.js');
        self::assertStringContainsString("dataset.wSticky = 'end'", $manager);
        self::assertStringContainsString('data-w-sticky-end', $manager);
    }

    public function testDemoMigrationPreservesEveryOriginalCapabilityScenario(): void
    {
        $basic = $this->read('app/code/Weline/DataTable/view/templates/frontend/test/basic.phtml');
        self::assertStringContainsString('id="demo-basic-table"', $basic);
        self::assertStringContainsString('editable="true"', $basic);
        self::assertStringContainsString('searchable="true"', $basic);
        self::assertStringContainsString('sortable="true"', $basic);
        self::assertStringContainsString('id="demo-basic-form"', $basic);

        $form = $this->read('app/code/Weline/DataTable/view/templates/frontend/test/form.phtml');
        self::assertStringContainsString('id="demo-standalone-form"', $form);
        self::assertStringContainsString('id="demo-standalone-table"', $form);

        $upload = $this->read('app/code/Weline/DataTable/view/templates/frontend/test/upload.phtml');
        self::assertStringContainsString('include_fields="name,email,photo,attachment"', $upload);
        self::assertStringContainsString('id="demo-upload-table"', $upload);

        $transaction = $this->read('app/code/Weline/DataTable/view/templates/frontend/test/transaction.phtml');
        self::assertStringContainsString('dependencies="u.id->o.user_id"', $transaction);
        self::assertStringContainsString('transaction="true"', $transaction);
        self::assertStringContainsString('id="demo-transaction-table"', $transaction);

        $dependency = $this->read('app/code/Weline/DataTable/view/templates/frontend/test/dependency.phtml');
        self::assertStringContainsString('dependencies="u.id->o.user_id"', $dependency);
        self::assertStringContainsString('transaction="false"', $dependency);
        self::assertStringContainsString('id="demo-dependency-table"', $dependency);

        $cascade = $this->read('app/code/Weline/DataTable/view/templates/frontend/test/cascade.phtml');
        self::assertStringContainsString('id="demo-cascade-users"', $cascade);
        self::assertStringContainsString('id="demo-cascade-orders"', $cascade);
        self::assertStringContainsString('data-w-datatable-demo-action="refresh-cascade"', $cascade);

        $performance = $this->read('app/code/Weline/DataTable/view/templates/frontend/test/performance.phtml');
        self::assertStringContainsString('id="demo-performance-table"', $performance);
        self::assertStringContainsString('show-config="true"', $performance);
        self::assertStringContainsString('data-w-datatable-demo-action="reload-performance"', $performance);
    }

    public function testBackendDemoRoutesUseTheSameWelineUiShellWithoutLosingTools(): void
    {
        foreach ([
            'app/code/Weline/DataTable/Controller/Backend/Test/Index.php',
            'app/code/Weline/DataTable/Controller/Backend/Test/TagTest.php',
        ] as $controllerPath) {
            $controller = $this->read($controllerPath);
            self::assertStringNotContainsString('mdi mdi-', $controller, $controllerPath);
        }

        $templates = [
            'app/code/Weline/DataTable/view/backend/templates/test/index.phtml',
            'app/code/Weline/DataTable/view/backend/templates/test/doc.phtml',
            'app/code/Weline/DataTable/view/backend/templates/test/tag-test.phtml',
            'app/code/Weline/DataTable/view/backend/templates/test/layout-switcher.phtml',
            'app/code/Weline/DataTable/view/templates/Test/Comprehensive/index.phtml',
            'app/code/Weline/DataTable/view/templates/Test/Comprehensive/inheritance.phtml',
        ];
        foreach ($templates as $templatePath) {
            $content = $this->read($templatePath);
            self::assertStringNotContainsString('<style', $content, $templatePath);
            self::assertDoesNotMatchRegularExpression(
                '/<script(?!\s+type="application\/json")/i',
                $content,
                $templatePath
            );
            self::assertDoesNotMatchRegularExpression('/\bmdi(?:\s|-)\b/i', $content, $templatePath);
            self::assertDoesNotMatchRegularExpression(
                '/class="[^"]*(?<![a-z0-9_-])(?:btn|card|row|col-[a-z0-9_-]+|d-flex|form-control|text-muted|mt-[0-9]+|mb-[0-9]+)(?![a-z0-9_-])/i',
                $content,
                $templatePath
            );
        }

        $dashboard = $this->read($templates[0]);
        self::assertStringContainsString('data-testid="datatable-admin-init"', $dashboard);
        self::assertStringContainsString('data-testid="datatable-admin-clear"', $dashboard);
        self::assertStringContainsString('$scenarios', $dashboard);
        self::assertStringContainsString('$models', $dashboard);
        self::assertStringContainsString('$docs', $dashboard);

        $tagVerification = $this->read($templates[2]);
        self::assertStringContainsString('$report', $tagVerification);
        self::assertStringContainsString('data-testid="datatable-tag-refresh"', $tagVerification);

        $comprehensive = $this->read($templates[4]);
        self::assertStringContainsString('data-testid="backend-demo-init"', $comprehensive);
        self::assertStringContainsString('data-testid="backend-demo-clear"', $comprehensive);
        self::assertStringContainsString('data-testid="backend-demo-verify"', $comprehensive);

        $inheritance = $this->read($templates[5]);
        self::assertStringContainsString('data-testid="datatable-inheritance-run"', $inheritance);

        $manifest = json_decode(
            $this->read('app/code/Weline/Theme/etc/weline-ui-assets.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        self::assertArrayHasKey('datatable.backend.demo', $manifest['routes'] ?? []);
        self::assertContains('datatable-demo-admin-js', $manifest['routes']['datatable.backend.demo']['bundles'] ?? []);
        foreach ($templates as $templatePath) {
            self::assertContains($templatePath, $manifest['routes']['datatable.backend.demo']['templates'] ?? []);
        }

        foreach (['weline-datatable.js', 'weline-datatable-form.js'] as $compiledModule) {
            self::assertMatchesRegularExpression(
                "/from '\\.\/datatable-common\\.js\\?v=[a-f0-9]{12}';/",
                $this->read('app/code/Weline/Theme/view/statics/ui/components/' . $compiledModule)
            );
        }
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . '/' . $path);
        self::assertIsString($content, $path);
        return $content;
    }
}
