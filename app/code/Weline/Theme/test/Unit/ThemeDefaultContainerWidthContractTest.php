<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ThemeDefaultContainerWidthContractTest extends TestCase
{
    public function testDefaultThemeContainerWidthTokensUse1440(): void
    {
        $root = dirname(__DIR__, 6);

        $welineThemeCss = $root . '/app/design/Weline/default/frontend/assets/css/theme.css';
        $weshopSpacingCss = $root . '/app/design/WeShop/default/frontend/variables/_spacing.css';
        $frameworkSpacingCss = $root . '/app/code/Weline/Theme/view/theme/frontend/variables/_spacing.css';
        $frameworkThemeCss = $root . '/app/code/Weline/Theme/view/theme/frontend/assets/css/theme.css';

        self::assertFileExists($welineThemeCss);
        self::assertFileExists($weshopSpacingCss);
        self::assertFileExists($frameworkSpacingCss);
        self::assertFileExists($frameworkThemeCss);

        self::assertStringContainsString('--theme-container-max-width: 1440px;', (string)file_get_contents($welineThemeCss));
        self::assertStringContainsString('--weshop-container-max-width: 1440px;', (string)file_get_contents($weshopSpacingCss));
        self::assertStringContainsString('--spacing-container-max-width: 1440px;', (string)file_get_contents($frameworkSpacingCss));
        self::assertStringContainsString('var(--weshop-container-max-width, 1440px)', (string)file_get_contents($frameworkThemeCss));
    }

    public function testDefaultDesignThemeDoesNotKeepHardCoded1280ContainerWidths(): void
    {
        $root = dirname(__DIR__, 6);

        foreach ([
            $root . '/app/design/Weline/default/frontend',
            $root . '/app/design/WeShop/default/frontend',
        ] as $directory) {
            self::assertDirectoryExists($directory);

            foreach ($this->frontendStyleFiles($directory) as $file) {
                $content = (string)file_get_contents($file->getPathname());

                self::assertStringNotContainsString(
                    'max-width: 1280px;',
                    $content,
                    $file->getPathname() . ' should not cap default theme containers at 1280px.'
                );
            }
        }
    }

    public function testThemeTailwindContainerUtilitiesResolveTo1440LayoutWidth(): void
    {
        $root = dirname(__DIR__, 6);

        $weshopMainCss = $root . '/app/design/WeShop/default/frontend/assets/css/main.css';
        $motorBaseLayout = $root . '/app/design/WeShop/motor/frontend/layouts/base.phtml';

        self::assertFileExists($weshopMainCss);
        self::assertFileExists($motorBaseLayout);

        $weshopMainContent = (string)file_get_contents($weshopMainCss);
        $motorBaseContent = (string)file_get_contents($motorBaseLayout);

        self::assertStringContainsString('--weshop-layout-content-max-width', $weshopMainContent);
        self::assertStringContainsString('.layout-container .max-w-7xl', $weshopMainContent);
        self::assertStringContainsString('max-width: var(--weshop-layout-content-max-width);', $weshopMainContent);

        self::assertStringContainsString('--motor-layout-content-max-width', $motorBaseContent);
        self::assertStringContainsString('html[data-theme="motor"] .max-w-7xl', $motorBaseContent);
        self::assertStringContainsString('max-width: var(--motor-layout-content-max-width);', $motorBaseContent);
    }

    public function testAccountLayoutsUseShared1440LayoutWidth(): void
    {
        $root = dirname(__DIR__, 6);

        $accountDefaultLayout = $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/account/default.phtml';
        $accountDashboardLayout = $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/account/dashboard.phtml';
        $motorAccountCss = $root . '/app/design/WeShop/motor/frontend/assets/css/motor-account-dashboard.css';

        self::assertFileExists($accountDefaultLayout);
        self::assertFileExists($accountDashboardLayout);
        self::assertFileExists($motorAccountCss);

        $accountDefaultContent = (string)file_get_contents($accountDefaultLayout);
        $accountDashboardContent = (string)file_get_contents($accountDashboardLayout);
        $motorAccountContent = (string)file_get_contents($motorAccountCss);

        self::assertStringContainsString(
            'max-width: var(--weline-layout-content-max-width, var(--layout-max-width, var(--container-max-width, 1440px)));',
            $accountDefaultContent
        );
        self::assertStringContainsString(
            '--account-page-top-gap: clamp(1.5rem, 2.2vw, 2.25rem);',
            $accountDefaultContent
        );
        self::assertStringContainsString(
            'padding: var(--account-page-top-gap) var(--weline-layout-content-padding-inline, 1rem) var(--account-page-bottom-gap);',
            $accountDefaultContent
        );
        self::assertStringContainsString('box-shadow: inset 0 10px 14px -16px rgba(15, 23, 42, 0.55);', $accountDefaultContent);
        self::assertStringContainsString('box-sizing: border-box;', $accountDefaultContent);
        self::assertStringContainsString(
            'max-width: var(--weline-layout-content-max-width, var(--layout-max-width, var(--container-max-width, 1440px)));',
            $accountDashboardContent
        );
        self::assertStringContainsString(
            '--account-page-top-gap: clamp(1.5rem, 2.2vw, 2.25rem);',
            $accountDashboardContent
        );
        self::assertStringContainsString(
            'padding: var(--account-page-top-gap) var(--weline-layout-content-padding-inline, 1rem) var(--account-page-bottom-gap);',
            $accountDashboardContent
        );
        self::assertStringContainsString('box-shadow: inset 0 10px 14px -16px rgba(15, 23, 42, 0.55);', $accountDashboardContent);
        self::assertStringContainsString('box-sizing: border-box;', $accountDashboardContent);
        self::assertStringNotContainsString('max-width: var(--container-max-width, 1400px);', $accountDefaultContent);
        self::assertStringNotContainsString('max-width: var(--layout-max-width, 1600px);', $accountDashboardContent);

        self::assertStringContainsString('--motor-account-content-max-width', $motorAccountContent);
        self::assertStringNotContainsString('width: min(1360px', $motorAccountContent);
    }

    public function testFrameworkMainLayoutContainersUseShared1440WidthAndPadding(): void
    {
        $root = dirname(__DIR__, 6);

        $files = [
            $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/default/default.phtml',
            $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml',
            $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/cms_page/default.phtml',
            $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/category/default.phtml',
            $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/product/default.phtml',
            $root . '/app/code/Weline/Theme/view/theme/frontend/layouts/product_list/default.phtml',
        ];

        foreach ($files as $file) {
            self::assertFileExists($file);
            $content = (string)file_get_contents($file);

            self::assertStringContainsString('1440px', $content, $file . ' should include the default 1440px layout width.');
            self::assertStringContainsString(
                'weline-layout-content-padding-inline',
                $content,
                $file . ' should use the same horizontal padding token as the header container.'
            );
            self::assertStringContainsString('box-sizing: border-box;', $content, $file . ' should include padding inside the layout width.');
            self::assertStringNotContainsString('max-width: var(--weline-layout-content-max-width, 1400px);', $content);
            self::assertStringNotContainsString('max-width: var(--weline-layout-content-max-width, 1200px);', $content);
            self::assertStringNotContainsString('max-width: var(--layout-max-width, 1600px);', $content);
        }
    }

    public function testFrameworkHeaderFooterContainerFallbacksUse1440(): void
    {
        $root = dirname(__DIR__, 6);

        foreach ([
            $root . '/app/code/Weline/Theme/view/theme/frontend/partials/head/default.phtml',
            $root . '/app/code/Weline/Theme/view/theme/frontend/partials/header/default.phtml',
            $root . '/app/code/Weline/Theme/view/theme/frontend/partials/footer/default.phtml',
        ] as $file) {
            self::assertFileExists($file);

            $content = (string)file_get_contents($file);

            self::assertStringContainsString(
                'var(--weline-layout-content-max-width, var(--layout-max-width, 1440px))',
                $content,
                $file . ' should fall back to the default 1440px layout width.'
            );
            self::assertStringNotContainsString(
                'var(--weline-layout-content-max-width, var(--layout-max-width, 1400px))',
                $content,
                $file . ' should not keep the old 1400px layout fallback.'
            );
        }
    }

    /**
     * @return iterable<SplFileInfo>
     */
    private function frontendStyleFiles(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (!\in_array($file->getExtension(), ['css', 'phtml'], true)) {
                continue;
            }

            yield $file;
        }
    }
}
