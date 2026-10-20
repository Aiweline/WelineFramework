<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase\test;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllPhraseLocalContractsPass().
 */
final class PhraseLocalContractsTest extends TestCase
{
    public function testAllPhraseLocalContractsPass(): void
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
            'ModuleCsvFallbackMustNotMaskTargetLocale::testLoadModuleWordsForLocaleChainOnlyReadsTargetLocaleCsv' => function () {
            $this->assert_ModuleCsvFallbackMustNotMaskTargetLocale_testLoadModuleWordsForLocaleChainOnlyReadsTargetLocaleCsv();
        },
            'PersistentWorkerForbidsWholeLocaleInclude::testLoadLocaleWordsHardGatesPersistentBeforeInclude' => function () {
            $this->assert_PersistentWorkerForbidsWholeLocaleInclude_testLoadLocaleWordsHardGatesPersistentBeforeInclude();
        },
            'PersistentWorkerForbidsWholeLocaleInclude::testBuildWordsFromWorkerCacheMergesAreaBagsNotWholeLocaleWhenPersistent' => function () {
            $this->assert_PersistentWorkerForbidsWholeLocaleInclude_testBuildWordsFromWorkerCacheMergesAreaBagsNotWholeLocaleWhenPersistent();
        },
            'PersistentWorkerForbidsWholeLocaleInclude::testShedColdLocalesForHeadroomApiExists' => function () {
            $this->assert_PersistentWorkerForbidsWholeLocaleInclude_testShedColdLocalesForHeadroomApiExists();
        },
            'PersistentWorkerForbidsWholeLocaleInclude::testPersistentModulePrefetchOmitsNullSourceWholeBagKey' => function () {
            $this->assert_PersistentWorkerForbidsWholeLocaleInclude_testPersistentModulePrefetchOmitsNullSourceWholeBagKey();
        },
            'ZhIdentityMustNotFallThroughToEn::testTranslationFromLoadedLayersStopsOnZhModuleIdentity' => function () {
            $this->assert_ZhIdentityMustNotFallThroughToEn_testTranslationFromLoadedLayersStopsOnZhModuleIdentity();
        },
            'ZhIdentityMustNotFallThroughToEn::testLoadLocaleWordsForLocaleChainAllowsZhIdentityToOverwriteEn' => function () {
            $this->assert_ZhIdentityMustNotFallThroughToEn_testLoadLocaleWordsForLocaleChainAllowsZhIdentityToOverwriteEn();
        },
        ];
    }

private function assert_ModuleCsvFallbackMustNotMaskTargetLocale_testLoadModuleWordsForLocaleChainOnlyReadsTargetLocaleCsv(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Phrase' . '/Parser.php');
    if (!preg_match('/private static function loadModuleWordsForLocaleChain\(.*?^\s{4}\}/ms', $source, $m)) {
        self::fail('loadModuleWordsForLocaleChain not found');
    }
    $snippet = $m[0];
    self::assertStringContainsString('$targetLocale', $snippet);
    self::assertStringContainsString('loadModuleWordsWithSharedCache($moduleName, $targetLocale', $snippet);
    self::assertStringNotContainsString('array_reverse($locales)', $snippet);
}

private function assert_PersistentWorkerForbidsWholeLocaleInclude_testLoadLocaleWordsHardGatesPersistentBeforeInclude(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Phrase' . '/Parser.php');
    self::assertStringContainsString('Runtime::isPersistent()', $src);
    self::assertStringContainsString('phrase_include_locale_php_skipped', $src);
    self::assertStringContainsString('persistent_worker_no_whole_locale_include', $src);
    self::assertStringContainsString('loadLocaleAreaBagsForWorker', $src);
    self::assertStringContainsString('loadMerged($lang, null, false)', $src);
    $gatePos = \strpos($src, 'persistent_worker_no_whole_locale_include');
    $includePos = \strpos($src, '(array)include $words_file');
    self::assertNotFalse($gatePos);
    self::assertNotFalse($includePos);
    self::assertLessThan($includePos, $gatePos, 'Hard gate must precede locale.php include');
    if (!\preg_match('/private static function loadLocaleAreaBagsForWorker\(.*?^\s{4}\}/ms', $src, $workerLoad)) {
        self::fail('loadLocaleAreaBagsForWorker not found');
    }
    $workerLoadBody = $workerLoad[0];
    $touchPos = \strpos($workerLoadBody, 'touchHeavyLocaleResident');
    $skipPos = \strpos($workerLoadBody, 'shouldSkipHeavyLocaleDictionaryLoad');
    $mergedPos = \strpos($workerLoadBody, 'loadMerged');
    self::assertNotFalse($touchPos);
    self::assertNotFalse($skipPos);
    self::assertNotFalse($mergedPos);
    self::assertLessThan($mergedPos, $touchPos, 'Resident eviction must run before area-bag include');
    self::assertLessThan($mergedPos, $skipPos, 'Pressure skip must run before area-bag include');
}

private function assert_PersistentWorkerForbidsWholeLocaleInclude_testBuildWordsFromWorkerCacheMergesAreaBagsNotWholeLocaleWhenPersistent(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Phrase' . '/Parser.php');
    self::assertMatchesRegularExpression('/function buildWordsFromWorkerCache.*?if \(Runtime::isPersistent\(\)\) \{\s*' . 'return \\\\array_merge\(self::loadLocaleAreaBagsForWorker\(\$lang\), \$module_words\);/s', $src);
    self::assertStringNotContainsString('Persistent Workers never materialize generated/language/{locale}.php', $src);
}

private function assert_PersistentWorkerForbidsWholeLocaleInclude_testShedColdLocalesForHeadroomApiExists(): void
{
    self::assertTrue(\method_exists(\Weline\Framework\Phrase\DictionaryCacheNamespace::class, 'shedColdLocalesForHeadroom'));
}

private function assert_PersistentWorkerForbidsWholeLocaleInclude_testPersistentModulePrefetchOmitsNullSourceWholeBagKey(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Phrase' . '/Parser.php');
    self::assertStringContainsString('withNullSourceGlobalModule', $src);
    self::assertStringContainsString('must not request NULL-source whole bags', $src);
    self::assertMatchesRegularExpression('/function withNullSourceGlobalModule.*?if \(!Runtime::isPersistent\(\)\) \{\s*' . '\$modules\[\] = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;/s', $src);
}

private function assert_ZhIdentityMustNotFallThroughToEn_testTranslationFromLoadedLayersStopsOnZhModuleIdentity(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Phrase' . '/Parser.php');
    if (!preg_match('/private static function translationFromLoadedLayers\(.*?^\s{4}\}/ms', $source, $m)) {
        self::fail('translationFromLoadedLayers not found');
    }
    $snippet = $m[0];
    self::assertStringContainsString('isChineseLocaleCode', $snippet);
    self::assertStringContainsString('untranslated placeholder', $snippet);
    // Must not only accept non-identity translations from modules (old bug).
    self::assertStringNotContainsString('&& $translation !== \'\' && $translation !== $word) {' . "\n" . '                return $translation;', $snippet);
}

private function assert_ZhIdentityMustNotFallThroughToEn_testLoadLocaleWordsForLocaleChainAllowsZhIdentityToOverwriteEn(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework/Phrase' . '/Parser.php');
    if (!preg_match('/private static function loadLocaleWordsForLocaleChain\(.*?^\s{4}\}/ms', $source, $m)) {
        self::fail('loadLocaleWordsForLocaleChain not found');
    }
    $snippet = $m[0];
    self::assertStringContainsString('array_merge', $snippet);
    self::assertMatchesRegularExpression('/\$words\s*=\s*\\\\array_merge\s*\(/', $snippet);
    self::assertDoesNotMatchRegularExpression('/mergePreferTranslatedWords\s*\(/', $snippet);
}
}
