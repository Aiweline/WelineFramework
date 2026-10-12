<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

final class DictionaryAssertWordContractTest extends TestCase
{
    public function testAssertWordRejectsBomNewlineAndQuoteRunaway(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Model/Dictionary.php');
        self::assertStringContainsString('翻译词不能包含 BOM', $src);
        self::assertStringContainsString('翻译词不能包含换行', $src);
        self::assertStringContainsString('翻译词不能包含连续引号失控串', $src);
        self::assertStringContainsString('MAX_WORD_LENGTH', $src);
        self::assertStringContainsString("str_starts_with(\$word, '@meta::')", $src);
        self::assertStringContainsString('禁止将 @meta:: 键写入 I18n 词典', $src);
    }
}
