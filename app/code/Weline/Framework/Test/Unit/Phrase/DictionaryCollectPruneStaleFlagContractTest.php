<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Phrase;

use PHPUnit\Framework\TestCase;

final class DictionaryCollectPruneStaleFlagContractTest extends TestCase
{
    public function testCompilerAfterPayloadCarriesPruneStaleFlag(): void
    {
        $compiler = dirname(__DIR__, 3) . '/Phrase/DictionaryCompiler.php';
        $collect = dirname(__DIR__, 3) . '/Console/Console/I18n/Collect.php';
        $compilerSrc = (string)file_get_contents($compiler);
        $collectSrc = (string)file_get_contents($collect);
        self::assertStringContainsString('bool $pruneStale = true', $compilerSrc);
        self::assertStringContainsString("'prune_stale' => \$pruneStale", $compilerSrc);
        self::assertStringContainsString('--no-prune-stale', $collectSrc);
        self::assertStringContainsString('compile($moduleName !== \'\' ? $moduleName : null, false, $pruneStale)', $collectSrc);
    }

    public function testFlattenCollectedWordsUsesChineseCsvPlusSourceScanOnly(): void
    {
        $compilerSrc = (string)file_get_contents(dirname(__DIR__, 3) . '/Phrase/DictionaryCompiler.php');
        self::assertStringContainsString('仅默认语（中文）CSV 原词', $compilerSrc);
        self::assertStringContainsString('$defaultWords = $localsWords[$defaultLocale] ?? []', $compilerSrc);
        self::assertStringContainsString('源码扫描原词（同样视为中文原词）', $compilerSrc);
        // 禁止用其它语 CSV 键扩 alive
        self::assertStringNotContainsString('foreach ($localsWords as $localeWords)', $compilerSrc);
    }
}
