<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class QABackendProductEditHookTest extends TestCase
{
    public function testProductEditQaHookTemplatesExposeTabAndPanel(): void
    {
        $navTemplate = file_get_contents(BP . 'app/code/WeShop/QA/view/hooks/WeShop_Product/backend/product/edit/nav-after.phtml');
        $contentTemplate = file_get_contents(BP . 'app/code/WeShop/QA/view/hooks/WeShop_Product/backend/product/edit/content-after.phtml');
        $this->assertIsString($navTemplate);
        $this->assertIsString($contentTemplate);

        $this->assertStringContainsString("href='#product_qa'", $navTemplate);
        $this->assertStringContainsString('value="product_qa"', $navTemplate);
        $this->assertStringContainsString("id='product_qa'", $contentTemplate);
        $this->assertStringContainsString('getProductQuestionAdminList', $contentTemplate);
        $this->assertStringContainsString('getProductQuestionSummary', $contentTemplate);
    }

    public function testProductEditQaHooksAreRegistered(): void
    {
        $registry = require BP . 'generated/hooks.php';

        $navImplementations = $registry['hooks']['WeShop_Product::backend::product::edit::nav-after']['implementations'] ?? [];
        $contentImplementations = $registry['hooks']['WeShop_Product::backend::product::edit::content-after']['implementations'] ?? [];

        $this->assertArrayHasKey('WeShop_QA', $navImplementations);
        $this->assertArrayHasKey('WeShop_QA', $contentImplementations);
        $this->assertSame('WeShop_Product/backend/product/edit/nav-after.phtml', $navImplementations['WeShop_QA']['file'] ?? null);
        $this->assertSame('WeShop_Product/backend/product/edit/content-after.phtml', $contentImplementations['WeShop_QA']['file'] ?? null);
    }
}
