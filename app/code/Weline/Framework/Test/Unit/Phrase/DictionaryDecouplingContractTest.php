<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;

final class DictionaryDecouplingContractTest extends TestCase
{
    public function testFrameworkCollectCommandDoesNotReferenceI18n(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Console/Console/I18n/Collect.php',
        );
        self::assertStringNotContainsString('Weline\\I18n', $source);
        self::assertStringContainsString("ALIASES = ['i18n:collect']", $source);
    }

    public function testDictionaryCompilerDispatchesCompileAfterWithoutI18n(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Phrase/DictionaryCompiler.php',
        );
        self::assertStringContainsString('EVENT_DICTIONARY_COMPILE_AFTER', $source);
        self::assertStringNotContainsString('Weline\\I18n', $source);
    }

    public function testParserUsesEventDictionary(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Phrase/Parser.php');
        self::assertStringContainsString('EventDictionary::', $source);
        self::assertStringContainsString('translateFromEventDictionary', $source);
    }

    public function testDictionaryEventsDefinesColdPathEvents(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Phrase/DictionaryEvents.php');
        foreach (['dictionary_compile', 'dictionary_register', 'dictionary_translate'] as $event) {
            self::assertStringContainsString($event, $source);
        }
    }
}
