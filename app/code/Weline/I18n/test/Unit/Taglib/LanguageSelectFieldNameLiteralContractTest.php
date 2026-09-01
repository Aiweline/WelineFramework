<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Taglib\LanguageSelect;

final class LanguageSelectFieldNameLiteralContractTest extends TestCase
{
    public function testFormNameIsCompiledAsHtmlLiteral(): void
    {
        $compiled = (LanguageSelect::callback())(
            'w:i18n:language:select',
            [],
            [],
            ['id' => 'cms-page-locale', 'name' => 'locale_code'],
        );

        self::assertStringContainsString("\$Taglib__name = 'locale_code';", $compiled);
        self::assertStringContainsString('$Taglib__multiple = false;', $compiled);
    }

    public function testOmittedMultipleForcesSingleSelectEvenAfterLeak(): void
    {
        $compiled = (LanguageSelect::callback())(
            'w:i18n:language:select',
            [],
            [],
            ['id' => 'website_default_language_selector', 'name' => 'default_language', 'allow-empty' => 'true'],
        );

        self::assertStringContainsString('$Taglib__multiple = false;', $compiled);
        self::assertStringContainsString("\$Taglib__name = 'default_language';", $compiled);
    }
}
