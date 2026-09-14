<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;

final class DictionaryObserverContractTest extends TestCase
{
    public function testI18nObserversRegisteredForFrameworkPhraseEvents(): void
    {
        $xml = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('Weline_Framework_Phrase::dictionary_compile', $xml);
        self::assertStringContainsString('DictionaryCompileObserver', $xml);
        self::assertStringContainsString('DictionaryCompileAfterObserver', $xml);
        self::assertStringContainsString('DictionaryRegisterObserver', $xml);
        self::assertStringContainsString('DictionaryTranslateObserver', $xml);
        self::assertStringContainsString('CollectTranslationsShimObserver', $xml);
    }

    public function testLegacyCollectTranslationsUsesShimOnly(): void
    {
        $xml = (string)file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringNotContainsString('Observer\\CollectTranslations"', $xml);
    }

    public function testCompileAfterPersistsSourceWordsThenEnqueuesGapFill(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Observer/DictionaryCompileAfterObserver.php',
        );
        self::assertStringContainsString('DictionaryCollectService', $source);
        self::assertStringContainsString('persistCollectedWords', $source);
        self::assertStringContainsString('enqueueEnabledLocales', $source);
        self::assertStringContainsString('collected_words', $source);
        self::assertStringContainsString('I18n::clearLocalWordsCache', $source);
        self::assertStringContainsString('AiTranslationPublisher', $source);
        self::assertStringContainsString('publishLocale', $source);
        self::assertStringContainsString('getEnabledLocaleCodes', $source);
        self::assertStringContainsString('republishEnabledLocalesFromDictionary', $source);
    }
}
