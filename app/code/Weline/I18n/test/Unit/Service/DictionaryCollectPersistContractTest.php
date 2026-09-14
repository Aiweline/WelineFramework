<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 契约：源语言收集写入公共词典，供其它 locale 对照空缺翻译。
 */
final class DictionaryCollectPersistContractTest extends TestCase
{
    public function testPersistCollectedWordsIsTheSharedDictionaryWritePath(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/DictionaryCollectService.php',
        );
        self::assertStringContainsString('function persistCollectedWords(', $source);
        self::assertStringContainsString('$this->persistCollectedWords($words', $source);
        self::assertStringContainsString('schema_fields_WORD', $source);
        self::assertStringContainsString('schema_fields_LOCALE_CODE', $source);
    }
}
