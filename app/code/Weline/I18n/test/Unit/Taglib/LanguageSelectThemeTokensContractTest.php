<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class LanguageSelectThemeTokensContractTest extends TestCase
{
    public function testLanguageSelectStylesPreferThemeTokensOverBackendFallbacks(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/LanguageSelect.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('--weline-theme-surface', $content);
        self::assertStringContainsString('--weline-theme-text', $content);
        self::assertStringContainsString('--weline-theme-border-strong', $content);
        self::assertStringContainsString('--weline-theme-surface-muted', $content);
        self::assertStringContainsString('--weline-theme-primary', $content);
        self::assertStringContainsString(
            'background: var(--weline-theme-surface, var(--backend-color-card-bg, #fff));',
            $content
        );
        self::assertStringNotContainsString(
            "background: var(--backend-color-card-bg, #fff);\n",
            $content
        );
    }
}
