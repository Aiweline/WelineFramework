<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class ProductSupplierSaveCsrfContractTest extends TestCase
{
    private function moduleFile(string $relative): string
    {
        $path = dirname(__DIR__, 4) . '/' . ltrim($relative, '/');
        $content = file_get_contents($path);
        self::assertNotFalse($content, 'Missing file: ' . $relative);

        return $content;
    }

    public function testSupplierMutationsArePostActionsWithDistinctAclSources(): void
    {
        $controller = $this->moduleFile('Controller/Backend/Catalog.php');
        $template = $this->moduleFile('view/templates/backend/catalog/suppliers.phtml');

        self::assertMatchesRegularExpression(
            '/protected function csrf\(\): string\s*\{\s*return \'csrf\';/s',
            $controller
        );
        self::assertStringContainsString('csrf="auto"', $template);
        self::assertStringContainsString('function postSaveSupplier()', $controller);
        self::assertStringContainsString('function postDisableSupplier()', $controller);
        self::assertStringNotContainsString('function saveSupplier()', $controller);
        self::assertStringNotContainsString('function disableSupplier()', $controller);
        self::assertStringContainsString("'Weline_Product::commerce:catalog:suppliers:save'", $controller);
        self::assertStringContainsString("'Weline_Product::commerce:catalog:suppliers:disable'", $controller);
        self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/save-supplier')\"", $template);
        self::assertStringContainsString("action=\"@backend-url('*/backend/catalog/disable-supplier')\"", $template);
    }
}
