<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Console;

use PHPUnit\Framework\TestCase;

final class AiImportCsvArgsContractTest extends TestCase
{
    public function testImportCsvReadsCliArgsNotOnlyCommandMetadata(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Console/Ai/ImportCsv.php');
        self::assertStringContainsString("\$args['locale'] ?? \$args['l']", $source);
        self::assertStringContainsString("\$args['file'] ?? \$args['f']", $source);
        self::assertStringContainsString("isset(\$args['all']) || isset(\$args['a'])", $source);
    }

    public function testImportFromCsvSkipsCjkIdentityRowsForNonChineseLocales(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/AiTranslationService.php');
        self::assertStringContainsString("function importFromCsv", $source);
        self::assertStringContainsString("str_starts_with(strtolower(\$localeCode), 'zh')", $source);
        self::assertStringContainsString('/[\\x{4e00}-\x{9fff}]/u', $source);
    }
}
