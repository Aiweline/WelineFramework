<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model\Locale;

use PHPUnit\Framework\TestCase;

final class DictionaryAssertLocaleGateContractTest extends TestCase
{
    public function testLocaleDictionaryGatesWordAndLocaleOnSaveAndUpsert(): void
    {
        $path = dirname(__DIR__, 4) . '/Model/Locale/Dictionary.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('function assertLocaleCode', $src);
        self::assertStringContainsString('function save_before', $src);
        self::assertStringContainsString('WordDictionary::assertWord', $src);
        self::assertStringContainsString('self::assertLocaleCode', $src);
    }

    public function testAiTranslationSaveAssertsWordBeforeInsert(): void
    {
        $path = dirname(__DIR__, 4) . '/Service/AiTranslationService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('Dictionary::assertWord($word)', $src);
        self::assertStringContainsString('LocaleDictionary::assertLocaleCode($localeCode)', $src);
    }
}
