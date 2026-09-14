<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class HindiIdentityChipCsvContractTest extends TestCase
{
    public function testHindiCsvTranslatesEditEmailIdentity(): void
    {
        $path = dirname(__DIR__, 3) . '/i18n/hi_IN.csv';
        self::assertFileExists($path);
        $hi = '';
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $row = str_getcsv($line, ',', '"', '\\');
            if (($row[0] ?? '') === '修改邮箱身份') {
                $hi = (string)($row[1] ?? '');
                break;
            }
        }
        self::assertNotSame('', $hi);
        self::assertNotSame('修改邮箱身份', $hi);
        self::assertMatchesRegularExpression('/\p{Devanagari}/u', $hi);
    }

    public function testBodyEndUsesPathLocaleAndWidgetI18n(): void
    {
        $path = dirname(__DIR__, 3)
            . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('WidgetI18n::localeFromRequestUri', $src);
        self::assertStringContainsString('storefrontLocale', $src);
        self::assertStringContainsString("\$cs('修改邮箱身份')", $src);
    }

    public function testWidgetTranslationServiceIncludesHindi(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetTranslationService.php',
        );
        self::assertStringContainsString("'hi_IN'", $src);
    }
}
