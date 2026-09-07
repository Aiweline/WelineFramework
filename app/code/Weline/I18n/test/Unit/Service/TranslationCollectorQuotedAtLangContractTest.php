<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class TranslationCollectorQuotedAtLangContractTest extends TestCase
{
    public function testCollectorStripsWrappingQuotesFromAtLangBrace(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/TranslationCollector.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('剥离包裹引号', $src);
        self::assertStringContainsString("@lang\\{(.*?)}", $src);
        self::assertStringContainsString('$quoted[2]', $src);
    }

    public function testJsExtractorStripsWrappingQuotesFromAtLangBrace(): void
    {
        $path = dirname(__DIR__, 3) . '/Helper/JsTranslationsExtractor.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('剥离包裹引号', $src);
        self::assertStringContainsString('$quoted[2]', $src);
    }
}
