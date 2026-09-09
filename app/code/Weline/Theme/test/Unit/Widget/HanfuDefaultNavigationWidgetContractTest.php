<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

final class HanfuDefaultNavigationWidgetContractTest extends TestCase
{
    public function testCategoryListDefaultsToHanfuCatalogWithoutInventedCounts(): void
    {
        $html = $this->render('category/category-list/default.phtml');

        foreach (['女士汉服', '明制汉服', '宋制汉服', '男士汉服', '儿童汉服', '汉服配饰'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        foreach (['电子产品', '手机', '电脑', '家居生活', '美妆个护', '食品生鲜'] as $legacyLabel) {
            self::assertStringNotContainsString($legacyLabel, $html);
        }
        self::assertStringNotContainsString('class="category-count"', $html);
        self::assertStringContainsString('/search?q=', $html);
    }

    public function testBreadcrumbDefaultUsesRealCatalogDestinations(): void
    {
        $html = $this->render('breadcrumb/breadcrumb/default.phtml');

        self::assertStringContainsString('href="/categories"', $html);
        self::assertStringContainsString('全部商品', $html);
        self::assertStringNotContainsString('电子产品', $html);
        self::assertStringNotContainsString('/category/electronics', $html);
    }

    public function testMainNavigationSourceContainsNoUnusedGenericCatalogFallback(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/navigation/main-nav/default.phtml',
        );

        foreach (['电子产品', '手机通讯', '电脑办公', '智能设备', '服装鞋帽', '家居生活'] as $legacyLabel) {
            self::assertStringNotContainsString($legacyLabel, $source);
        }
        self::assertStringNotContainsString('/category/electronics', $source);
        self::assertStringContainsString("getFrontendUrl('promotion/deals')", $source);
        self::assertStringContainsString("getFrontendUrl('faq')", $source);
        self::assertStringNotContainsString("'/promotion/deals'", $source);
    }

    public function testAllMenuTriggerUsesARealButtonInsteadOfAPlaceholderLink(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/navigation/all-menu/default.phtml',
        );

        self::assertStringContainsString('<button type="button"', $source);
        self::assertStringContainsString('js-header-drawer-trigger', $source);
        self::assertStringNotContainsString('<a href="#"', $source);
        self::assertStringNotContainsString('role="button"', $source);
    }

    /** @param array<string, mixed> $data */
    private function render(string $widget, array $data = []): string
    {
        $renderer = new class($data) {
            /** @param array<string, mixed> $data */
            public function __construct(private readonly array $data)
            {
            }

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function getStaticUrl(string $path): string
            {
                return '/static/' . ltrim($path, '/');
            }

            public function render(string $path): string
            {
                ob_start();
                try {
                    include $path;
                    return (string)ob_get_clean();
                } catch (\Throwable $throwable) {
                    ob_end_clean();
                    throw $throwable;
                }
            }
        };

        return $renderer->render(
            dirname(__DIR__, 3) . '/view/theme/frontend/widgets/' . $widget,
        );
    }
}
