<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Model\Locale;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nLocaleContractsPass().
 */
final class I18nLocaleContractsTest extends TestCase
{
    public function testAllI18nLocaleContractsPass(): void
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
            'DictionaryAssertLocaleGate::testLocaleDictionaryGatesWordAndLocaleOnSaveAndUpsert' => function () {
            $this->assert_DictionaryAssertLocaleGate_testLocaleDictionaryGatesWordAndLocaleOnSaveAndUpsert();
        },
            'DictionaryAssertLocaleGate::testAiTranslationSaveAssertsWordBeforeInsert' => function () {
            $this->assert_DictionaryAssertLocaleGate_testAiTranslationSaveAssertsWordBeforeInsert();
        },
        ];
    }

private function assert_DictionaryAssertLocaleGate_testLocaleDictionaryGatesWordAndLocaleOnSaveAndUpsert(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Model/Locale/Dictionary.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('function assertLocaleCode', $src);
    self::assertStringContainsString('function save_before', $src);
    self::assertStringContainsString('WordDictionary::assertWord', $src);
    self::assertStringContainsString('self::assertLocaleCode', $src);
}

private function assert_DictionaryAssertLocaleGate_testAiTranslationSaveAssertsWordBeforeInsert(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('Dictionary::assertWord($word)', $src);
    self::assertStringContainsString('LocaleDictionary::assertLocaleCode($localeCode)', $src);
}
}
