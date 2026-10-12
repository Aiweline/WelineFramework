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
        self::assertStringContainsString('Weline_Framework_Phrase::cli_language_options', $xml);
        self::assertStringContainsString('CliLanguageOptions', $xml);
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
        self::assertStringContainsString('getDictionaryPublishLocaleCodes', $source);
        self::assertStringContainsString('republishEnabledLocalesFromDictionary', $source);
        self::assertStringContainsString('DictionaryCollectBatchService', $source);
        self::assertStringContainsString('pullClassifiedCompileWordAreas', $source);
        self::assertStringContainsString('DictionaryAreaReseedService', $source);
        self::assertStringContainsString('recordCompileAfterSummary', $source);
        self::assertStringContainsString('private readonly I18n $i18n', $source);
        self::assertStringContainsString('allocateNextBatch', $source);
        self::assertStringContainsString('commitBatch', $source);
        self::assertStringContainsString('DictionaryStaleWordPruneService', $source);
        self::assertStringContainsString('pruneNotCurrentBatch', $source);
        self::assertStringContainsString('prune_stale', $source);
        self::assertStringContainsString('collectBatch', $source);
        // 导出集独立于 AI 目标集；禁止再依赖 getEnabledLocaleCodes（总开关关会空）
        self::assertStringNotContainsString('getEnabledLocaleCodes', $source);
        self::assertStringNotContainsString('=== $source', $source);
        self::assertStringContainsString('唯一导出入口', $source);
    }

    public function testPublishLocaleCallersOnlyGoThroughAiTranslationPublisher(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/test/') || str_contains($path, '/Test/')) {
                continue;
            }
            $src = (string) file_get_contents($path);
            if (!str_contains($src, 'publishLocale(')) {
                continue;
            }
            $rel = substr($path, strlen($root) + 1);
            $hits[] = $rel;
        }

        sort($hits);
        self::assertSame(
            [
                'Observer/DictionaryCompileAfterObserver.php',
                'Service/AiTranslationExportService.php',
                'Service/AiTranslationPublisher.php',
                'Service/AiTranslationService.php',
                'Service/RemoteDictionaryAssistService.php',
            ],
            $hits,
            'publishLocale( must stay on AiTranslationPublisher + known callers only',
        );
    }
}
