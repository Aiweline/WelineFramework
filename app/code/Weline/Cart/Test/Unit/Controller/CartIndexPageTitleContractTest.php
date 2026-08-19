<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CartIndexPageTitleContractTest extends TestCase
{
    public function testCartControllerPublishesExplicitPageTitleForThemeLayouts(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Controller/Index.php');

        self::assertStringContainsString("\$this->request->setGet('theme_page_title', (string)__('购物车'));", $source);
        self::assertStringContainsString("\$this->assign('page_title', __('购物车'));", $source);
    }
}
