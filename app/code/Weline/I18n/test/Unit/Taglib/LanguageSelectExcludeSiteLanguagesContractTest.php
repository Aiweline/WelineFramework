<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;

final class LanguageSelectExcludeSiteLanguagesContractTest extends TestCase
{
    public function testLanguageSelectDeclaresExcludeSiteLanguagesAttribute(): void
    {
        $path = dirname(__DIR__, 3) . '/Taglib/LanguageSelect.php';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString("'exclude-site-languages' => false", $content);
        self::assertStringContainsString('resolveSiteLanguageCodes', $content);
        self::assertStringContainsString('data-w-exclude-site-languages', $content);
        self::assertStringContainsString('data-w-site-language="true"', $content);
        self::assertStringContainsString('data-w-excluded-label', $content);
    }

    public function testLanguageSupportRequestFormUsesExcludeSiteLanguages(): void
    {
        $path = dirname(__DIR__, 3) . '/view/templates/Frontend/language-support-request.phtml';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('exclude-site-languages="true"', $content);
        self::assertStringContainsString('data-language-request-form-shell', $content);
        self::assertStringNotContainsString('disabled-values="disabled_languages"', $content);
        self::assertStringNotContainsString('<script', $content);
    }
}
