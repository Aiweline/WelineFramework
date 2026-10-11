<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase\Test;

use PHPUnit\Framework\TestCase;

/**
 * Persistent Workers must never include generated/language/{locale}.php (request-must-not-die).
 */
final class PersistentWorkerForbidsWholeLocaleIncludeContractTest extends TestCase
{
    public function testLoadLocaleWordsHardGatesPersistentBeforeInclude(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__) . '/Parser.php'
        );
        self::assertStringContainsString('Runtime::isPersistent()', $src);
        self::assertStringContainsString('phrase_include_locale_php_skipped', $src);
        self::assertStringContainsString('persistent_worker_no_whole_locale_include', $src);

        $gatePos = \strpos($src, 'persistent_worker_no_whole_locale_include');
        $includePos = \strpos($src, '(array)include $words_file');
        self::assertNotFalse($gatePos);
        self::assertNotFalse($includePos);
        self::assertLessThan($includePos, $gatePos, 'Hard gate must precede locale.php include');
    }

    public function testBuildWordsFromWorkerCacheSkipsLocaleFileMergeWhenPersistent(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__) . '/Parser.php'
        );
        self::assertMatchesRegularExpression(
            '/function buildWordsFromWorkerCache.*?if \(Runtime::isPersistent\(\)\) \{\s*return \$module_words;/s',
            $src
        );
    }

    public function testShedColdLocalesForHeadroomApiExists(): void
    {
        self::assertTrue(\method_exists(
            \Weline\Framework\Phrase\DictionaryCacheNamespace::class,
            'shedColdLocalesForHeadroom'
        ));
    }

    public function testPersistentModulePrefetchOmitsNullSourceWholeBagKey(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__) . '/Parser.php');
        self::assertStringContainsString('withNullSourceGlobalModule', $src);
        self::assertStringContainsString('must not request NULL-source whole bags', $src);
        self::assertMatchesRegularExpression(
            '/function withNullSourceGlobalModule.*?if \(!Runtime::isPersistent\(\)\) \{\s*'
            . '\$modules\[\] = ModuleGlobalDictionaryProviderInterface::NULL_SOURCE_MODULE_KEY;/s',
            $src
        );
    }
}
