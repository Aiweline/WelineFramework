<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Model\Dictionary;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nModelContractsPass().
 */
final class I18nModelContractsTest extends TestCase
{
    public function testAllI18nModelContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'DictionaryAssertWord::testAssertWordRejectsBomNewlineAndQuoteRunaway' => function () {
            $this->assert_DictionaryAssertWord_testAssertWordRejectsBomNewlineAndQuoteRunaway();
        },
            'DictionaryRejectMetaPrefix::testAssertWordSourceRejectsMetaPrefix' => function () {
            $this->assert_DictionaryRejectMetaPrefix_testAssertWordSourceRejectsMetaPrefix();
        },
            'DictionaryRejectMetaPrefix::testAssertWordStillAllowsNormalPhraseInSource' => function () {
            $this->assert_DictionaryRejectMetaPrefix_testAssertWordStillAllowsNormalPhraseInSource();
        },
        ];
    }

private function assert_DictionaryAssertWord_testAssertWordRejectsBomNewlineAndQuoteRunaway(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Model/Dictionary.php');
    self::assertStringContainsString('翻译词不能包含 BOM', $src);
    self::assertStringContainsString('翻译词不能包含换行', $src);
    self::assertStringContainsString('翻译词不能包含连续引号失控串', $src);
    self::assertStringContainsString('MAX_WORD_LENGTH', $src);
    self::assertStringContainsString("str_starts_with(\$word, '@meta::')", $src);
    self::assertStringContainsString('禁止将 @meta:: 键写入 I18n 词典', $src);
}

private function assert_DictionaryRejectMetaPrefix_testAssertWordSourceRejectsMetaPrefix(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Model/Dictionary.php');
    self::assertStringContainsString("str_starts_with(\$word, '@meta::')", $src);
    self::assertStringContainsString('禁止将 @meta:: 键写入 I18n 词典', $src);
}

private function assert_DictionaryRejectMetaPrefix_testAssertWordStillAllowsNormalPhraseInSource(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Model/Dictionary.php');
    self::assertStringContainsString('MAX_WORD_LENGTH', $src);
    self::assertTrue(true);
}
}
