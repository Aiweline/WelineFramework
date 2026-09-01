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
}
