<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Phrase\DictionaryWordValidator;

final class DictionaryWordValidatorLengthContractTest extends TestCase
{
    public function testRejectsOversizeNewlineBomAndQuoteRun(): void
    {
        self::assertTrue(DictionaryWordValidator::isValidTranslationString('保存'));
        self::assertFalse(DictionaryWordValidator::isValidTranslationString(str_repeat('a', DictionaryWordValidator::MAX_WORD_LENGTH + 1)));
        self::assertFalse(DictionaryWordValidator::isValidTranslationString("a\nb"));
        self::assertFalse(DictionaryWordValidator::isValidTranslationString("\u{FEFF}保存"));
        self::assertFalse(DictionaryWordValidator::isValidTranslationString('""""'));
    }
}
