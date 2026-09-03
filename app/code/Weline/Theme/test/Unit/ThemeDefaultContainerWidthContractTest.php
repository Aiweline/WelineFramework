<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ThemeDefaultContainerWidthContractTest extends TestCase
{
    public function testCanonicalContainerWidthIsDefinedOnlyAtTheVariableLayer(): void
    {
        $spacing = $this->readThemeFile('variables/_spacing.css');

        self::assertStringContainsString('--spacing-container-max-width: 1440px;', $spacing);
        self::assertStringContainsString(
            '--weline-layout-content-max-width: var(--layout-max-width);',
            $spacing
        );
        self::assertStringContainsString(
            '--weline-layout-content-padding-inline: clamp(',
            $spacing
        );
        self::assertStringContainsString(
            'calc((var(--spacing-container-max-width) - 100vw) * 9999)',
            $spacing
        );
        self::assertStringContainsString(
            'var(--spacing-container-padding)',
            $spacing
        );
        self::assertSame(
            1,
            substr_count($spacing, '1440px'),
            'The canonical 1440px value belongs only to --spacing-container-max-width.'
        );
        self::assertStringNotContainsString('1280px', $spacing);
    }

    public function testThemeCssContainerAliasesUseSharedTokensWithoutPixelFallbacks(): void
    {
        $themeCss = $this->readThemeFile('assets/css/theme.css');

        self::assertStringContainsString(
            '--weline-theme-container-max: var(--weline-layout-content-max-width);',
            $themeCss
        );
        self::assertStringContainsString(
            '--weline-layout-content-max-width: var(--layout-max-width);',
            $themeCss
        );
        self::assertStringContainsString(
            '--weline-layout-content-padding-inline: clamp(',
            $themeCss
        );
        self::assertStringContainsString(
            'calc((var(--spacing-container-max-width) - 100vw) * 9999)',
            $themeCss
        );
        self::assertStringNotContainsString('1440px', $themeCss);
        self::assertStringNotContainsString('1280px', $themeCss);
    }

    public function testFrameworkLayoutChromeDoesNotReintroducePrivatePixelFallbacks(): void
    {
        foreach ([
            'layouts/default/default.phtml',
            'partials/head/default.phtml',
            'partials/footer/default.phtml',
        ] as $relativePath) {
            $contents = $this->readThemeFile($relativePath);
            self::assertStringNotContainsString(
                '1440px',
                $contents,
                $relativePath . ' must consume the shared content-width token.'
            );
            self::assertStringNotContainsString(
                '1280px',
                $contents,
                $relativePath . ' must not restore a legacy private width.'
            );
        }

        self::assertStringContainsString(
            'class="w-container w-frontend-page"',
            $this->readThemeFile('layouts/default/default.phtml')
        );
    }

    private function readThemeFile(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/' . ltrim($relativePath, '/');
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
