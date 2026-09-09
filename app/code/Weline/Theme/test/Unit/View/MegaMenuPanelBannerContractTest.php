<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class MegaMenuPanelBannerContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = BP . $relative;
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    public function testMegaMenuPanelShowsIntroAndHonorsBannerFlag(): void
    {
        $panel = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/header/mega-menu-panel.phtml');
        self::assertStringContainsString('show_banner_with_children', $panel);
        self::assertStringContainsString('mega-menu-panel__intro', $panel);
        self::assertStringContainsString('mega-menu-panel__banner', $panel);
        self::assertStringContainsString('data-testid="mega-menu-category-intro"', $panel);
        self::assertStringContainsString('$resolveBanner', $panel);
        self::assertStringContainsString('$resolveIntroDescription', $panel);
        self::assertStringNotContainsString('暂无子分类', $panel);

        $assignNeedle = "\$childTextPlain = trim((string)(\$child['text'] ?? \$child['name'] ?? ''));";
        $sidebarPlainPos = strpos($panel, $assignNeedle);
        self::assertNotFalse($sidebarPlainPos, 'sidebar loop must define $childTextPlain');
        self::assertStringContainsString('mega-menu-sidebar-item__media', $panel);
        self::assertMatchesRegularExpression(
            '/mega-menu-sidebar-item__media[\s\S]{0,240}alt=""/',
            $panel,
            'sidebar thumbs must use empty decorative alt beside visible labels'
        );
    }

    public function testCategoryMenuWidgetExposesBannerParam(): void
    {
        $widget = $this->read('app/code/Weline/Theme/view/theme/frontend/widgets/navigation/category-menu/default.phtml');
        self::assertStringContainsString('@param show_banner_with_children', $widget);
        self::assertStringContainsString('data-show-banner-with-children', $widget);
    }

    public function testHeaderPassesBannerFlagIntoMegaPanel(): void
    {
        $header = $this->read('app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml');
        self::assertStringContainsString('@param.show_banner_with_children', $header);
        self::assertStringContainsString("'show_banner_with_children' => \$headerShowBannerWithChildren", $header);
    }
}
