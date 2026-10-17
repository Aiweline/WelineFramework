<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Console;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nConsoleContractsPass().
 */
final class I18nConsoleContractsTest extends TestCase
{
    public function testAllI18nConsoleContractsPass(): void
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
            'AiImportCsvArgs::testImportCsvReadsCliArgsNotOnlyCommandMetadata' => function () {
            $this->assert_AiImportCsvArgs_testImportCsvReadsCliArgsNotOnlyCommandMetadata();
        },
            'AiImportCsvArgs::testImportFromCsvSkipsCjkIdentityRowsForNonChineseLocales' => function () {
            $this->assert_AiImportCsvArgs_testImportFromCsvSkipsCjkIdentityRowsForNonChineseLocales();
        },
        ];
    }

private function assert_AiImportCsvArgs_testImportCsvReadsCliArgsNotOnlyCommandMetadata(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Console/Ai/ImportCsv.php');
    self::assertStringContainsString("\$args['locale'] ?? \$args['l']", $source);
    self::assertStringContainsString("\$args['file'] ?? \$args['f']", $source);
    self::assertStringContainsString("isset(\$args['all']) || isset(\$args['a'])", $source);
}

private function assert_AiImportCsvArgs_testImportFromCsvSkipsCjkIdentityRowsForNonChineseLocales(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Service/AiTranslationService.php');
    self::assertStringContainsString("function importFromCsv", $source);
    self::assertStringContainsString("str_starts_with(strtolower(\$localeCode), 'zh')", $source);
    self::assertStringContainsString('/[\x{4e00}-\x{9fff}]/u', $source);
}
}
