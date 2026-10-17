<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCartControllerContractsPass().
 */
final class CartControllerContractsTest extends TestCase
{
    public function testAllCartControllerContractsPass(): void
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
            'CartIndexPageTitle::testCartControllerPublishesExplicitPageTitleForThemeLayouts' => function () {
            $this->assert_CartIndexPageTitle_testCartControllerPublishesExplicitPageTitleForThemeLayouts();
        },
        ];
    }

private function assert_CartIndexPageTitle_testCartControllerPublishesExplicitPageTitleForThemeLayouts(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/Controller/Index.php');
    self::assertStringContainsString("\$this->request->setGet('theme_page_title', WidgetI18n::label('购物车'));", $source);
    self::assertStringContainsString("\$this->assign('page_title', WidgetI18n::label('购物车'));", $source);
}
}
