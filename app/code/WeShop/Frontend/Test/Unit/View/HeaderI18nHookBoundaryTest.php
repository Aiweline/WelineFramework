<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class HeaderI18nHookBoundaryTest extends TestCase
{
    public function testWeshopHeadersUseThemeOwnedI18nHooks(): void
    {
        $headerTemplates = [
            __DIR__ . '/../../../../../../design/WeShop/default/frontend/partials/header/default.phtml',
            __DIR__ . '/../../../../../../design/WeShop/motor/frontend/partials/header/default.phtml',
        ];

        foreach ($headerTemplates as $templatePath) {
            self::assertFileExists($templatePath);
            $template = (string)file_get_contents($templatePath);

            self::assertStringContainsString('header-language-switcher', $template);
            self::assertStringContainsString('header-currency-switcher', $template);
            self::assertStringNotContainsString(
                'WeShop_Frontend::frontend::partials::header::language-switcher',
                $template
            );
            self::assertStringNotContainsString(
                'WeShop_Frontend::frontend::partials::header::currency-switcher',
                $template
            );
        }
    }

    public function testWelineI18nDoesNotImplementWeshopFrontendHooks(): void
    {
        $hookRoot = __DIR__ . '/../../../../../Weline/I18n/view/hooks';
        self::assertDirectoryExists($hookRoot);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($hookRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            if ($file->getExtension() !== 'phtml') {
                continue;
            }

            self::assertStringNotContainsString(
                DIRECTORY_SEPARATOR . 'WeShop_Frontend' . DIRECTORY_SEPARATOR,
                $file->getPathname(),
                $file->getPathname()
            );

            $content = (string)file_get_contents($file->getPathname());
            self::assertStringNotContainsString(
                'WeShop_Frontend::frontend::partials::header::language-switcher',
                $content,
                $file->getPathname()
            );
            self::assertStringNotContainsString(
                'WeShop_Frontend::frontend::partials::header::currency-switcher',
                $content,
                $file->getPathname()
            );
        }
    }
}
