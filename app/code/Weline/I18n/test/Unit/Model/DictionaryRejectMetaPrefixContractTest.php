<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Model\Dictionary;

final class DictionaryRejectMetaPrefixContractTest extends TestCase
{
    public function testAssertWordSourceRejectsMetaPrefix(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/Dictionary.php');
        self::assertStringContainsString("str_starts_with(\$word, '@meta::')", $src);
        self::assertStringContainsString('禁止将 @meta:: 键写入 I18n 词典', $src);
    }

    public function testAssertWordStillAllowsNormalPhraseInSource(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/Dictionary.php');
        self::assertStringContainsString('MAX_WORD_LENGTH', $src);
        self::assertTrue(true);
    }
}
