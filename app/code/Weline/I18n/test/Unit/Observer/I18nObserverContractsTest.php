<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nObserverContractsPass().
 */
final class I18nObserverContractsTest extends TestCase
{
    public function testAllI18nObserverContractsPass(): void
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
            'DictionaryObserver::testI18nObserversRegisteredForFrameworkPhraseEvents' => function () {
            $this->assert_DictionaryObserver_testI18nObserversRegisteredForFrameworkPhraseEvents();
        },
            'DictionaryObserver::testLegacyCollectTranslationsUsesShimOnly' => function () {
            $this->assert_DictionaryObserver_testLegacyCollectTranslationsUsesShimOnly();
        },
            'DictionaryObserver::testCompileAfterPersistsSourceWordsThenEnqueuesGapFill' => function () {
            $this->assert_DictionaryObserver_testCompileAfterPersistsSourceWordsThenEnqueuesGapFill();
        },
            'DictionaryObserver::testPublishLocaleCallersOnlyGoThroughAiTranslationPublisher' => function () {
            $this->assert_DictionaryObserver_testPublishLocaleCallersOnlyGoThroughAiTranslationPublisher();
        },
            'DictionaryRegisterSkipInvalidWord::testRegisterAndLegacyCollectSkipAssertWordFailures' => function () {
            $this->assert_DictionaryRegisterSkipInvalidWord_testRegisterAndLegacyCollectSkipAssertWordFailures();
        },
        ];
    }

private function assert_DictionaryObserver_testI18nObserversRegisteredForFrameworkPhraseEvents(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/etc/event.xml');
    self::assertStringContainsString('Weline_Framework_Phrase::dictionary_compile', $xml);
    self::assertStringContainsString('Weline_Framework_Phrase::cli_language_options', $xml);
    self::assertStringContainsString('CliLanguageOptions', $xml);
    self::assertStringContainsString('DictionaryCompileObserver', $xml);
    self::assertStringContainsString('DictionaryCompileAfterObserver', $xml);
    self::assertStringContainsString('DictionaryRegisterObserver', $xml);
    self::assertStringContainsString('DictionaryTranslateObserver', $xml);
    self::assertStringContainsString('CollectTranslationsShimObserver', $xml);
}

private function assert_DictionaryObserver_testLegacyCollectTranslationsUsesShimOnly(): void
{
    $xml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/etc/event.xml');
    self::assertStringNotContainsString('Observer\CollectTranslations"', $xml);
}

private function assert_DictionaryObserver_testCompileAfterPersistsSourceWordsThenEnqueuesGapFill(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Observer/DictionaryCompileAfterObserver.php');
    self::assertStringContainsString('DictionaryCollectService', $source);
    self::assertStringContainsString('persistCollectedWords', $source);
    self::assertStringContainsString('enqueueEnabledLocales', $source);
    self::assertStringContainsString('collected_words', $source);
    self::assertStringContainsString('I18n::clearLocalWordsCache', $source);
    self::assertStringContainsString('AiTranslationPublisher', $source);
    self::assertStringContainsString('publishLocales', $source);
    self::assertStringContainsString('DictionaryExportLocaleResolver', $source);
    self::assertStringContainsString('DictionaryDirtyLocaleSet', $source);
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
    self::assertStringContainsString('republish_all', $source);
    self::assertStringContainsString('collectBatch', $source);
    // 导出集经 Resolver；禁止 Observer 直接依赖 getEnabledLocaleCodes
    self::assertStringNotContainsString('getEnabledLocaleCodes', $source);
    self::assertStringNotContainsString('=== $source', $source);
    self::assertStringContainsString('唯一导出入口', $source);
    // 单模块不跑全表 area reseed
    self::assertStringContainsString("if (\$moduleName === '')", $source);
    self::assertStringContainsString('reseedFromPathEvidence', $source);
}

private function assert_DictionaryObserver_testPublishLocaleCallersOnlyGoThroughAiTranslationPublisher(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/I18n';
    $hits = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/test/') || str_contains($path, '/Test/')) {
            continue;
        }
        $src = (string) file_get_contents($path);
        if (!str_contains($src, 'publishLocale(') && !str_contains($src, 'publishLocales(')) {
            continue;
        }
        $rel = substr($path, strlen($root) + 1);
        $hits[] = $rel;
    }
    sort($hits);
    self::assertSame(['Observer/DictionaryCompileAfterObserver.php', 'Service/AiTranslationExportService.php', 'Service/AiTranslationPublisher.php', 'Service/AiTranslationService.php', 'Service/RemoteDictionaryAssistService.php'], $hits, 'publishLocale(s) must stay on AiTranslationPublisher + known callers only');
}

private function assert_DictionaryRegisterSkipInvalidWord_testRegisterAndLegacyCollectSkipAssertWordFailures(): void
{
    $register = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Observer/DictionaryRegisterObserver.php');
    $legacy = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Observer/CollectTranslations.php');
    self::assertStringContainsString('Dictionary::assertWord', $register);
    self::assertStringContainsString('catch (\Throwable)', $register);
    self::assertStringContainsString('continue;', $register);
    self::assertStringContainsString('Dictionary::assertWord', $legacy);
    self::assertStringContainsString('catch (\Throwable)', $legacy);
}
}
