<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * Regression: 保存品牌 POST 曾因 Catalog::csrf()=form_key 与 w:form csrf=auto 写入的 csrf 字段不一致，
 * 在控制器 isAllowed() 中 noRouter(404)。本契约锁定字段名、POST 动作命名与 ACL source。
 */
final class ProductBrandSaveCsrfContractTest extends TestCase
{
    private function moduleFile(string $relative): string
    {
        $path = dirname(__DIR__, 4) . '/' . ltrim($relative, '/');
        $content = file_get_contents($path);
        self::assertNotFalse($content, 'Missing file: ' . $relative);

        return $content;
    }

    public function testCatalogCsrfTokenNameMatchesWformAutoCsrfField(): void
    {
        $controller = $this->moduleFile('Controller/Backend/Catalog.php');
        $template = $this->moduleFile('view/templates/backend/catalog/brands.phtml');
        $formRenderer = file_get_contents(
            dirname(__DIR__, 5) . '/Framework/View/Form/FormRenderer.php'
        );
        $csrfBlock = file_get_contents(
            dirname(__DIR__, 5) . '/Framework/View/Block/Csrf.php'
        );

        self::assertNotFalse($formRenderer);
        self::assertNotFalse($csrfBlock);

        // w:form csrf=auto → FormRenderer → Csrf::render('csrf') → name="csrf"
        self::assertStringContainsString("->render('csrf')", $formRenderer);
        self::assertStringContainsString('name=\'$name\'', $csrfBlock);
        self::assertStringContainsString('csrf="auto"', $template);
        self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/save-brand')\"", $template);
        self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/disable-brand')\"", $template);

        // Catalog must validate the same field name; form_key regresses to POST 404.
        self::assertMatchesRegularExpression(
            '/protected function csrf\(\): string\s*\{\s*return \'csrf\';/s',
            $controller
        );
        self::assertStringNotContainsString('FormKey::key_name', $controller);
        self::assertStringNotContainsString("return 'form_key';", $controller);
        self::assertStringNotContainsString('use Weline\\Framework\\Ui\\FormKey;', $controller);
    }

    public function testBrandMutationsArePostActionsWithDistinctAclSources(): void
    {
        $controller = $this->moduleFile('Controller/Backend/Catalog.php');

        self::assertStringContainsString('function postSaveBrand()', $controller);
        self::assertStringContainsString('function postDisableBrand()', $controller);
        self::assertStringNotContainsString('function saveBrand()', $controller);
        self::assertStringNotContainsString('function disableBrand()', $controller);

        self::assertStringContainsString(
            "'Weline_Product::commerce:catalog:brands:save'",
            $controller
        );
        self::assertStringContainsString(
            "'Weline_Product::commerce:catalog:brands:disable'",
            $controller
        );
        self::assertStringContainsString(
            "'Weline_Product::commerce:catalog:brands'",
            $controller
        );
    }
}
