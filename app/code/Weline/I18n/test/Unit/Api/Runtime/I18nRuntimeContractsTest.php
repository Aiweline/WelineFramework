<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Api\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\I18n\Api\Runtime\ProcessCacheResetter;
use Weline\I18n\Taglib\LanguageSelect;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nRuntimeContractsPass().
 */
final class I18nRuntimeContractsTest extends TestCase
{
    public function testAllI18nRuntimeContractsPass(): void
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
            'ProcessCacheResetterMemoryPressure::testSoftMemoryPressureClearsChromeBagsButNotFullPhraseWipe' => function () {
            $this->assert_ProcessCacheResetterMemoryPressure_testSoftMemoryPressureClearsChromeBagsButNotFullPhraseWipe();
        },
            'ProcessCacheResetterMemoryPressure::testAggressiveMemoryPressureClearsLanguageSelectProcessCaches' => function () {
            $this->assert_ProcessCacheResetterMemoryPressure_testAggressiveMemoryPressureClearsLanguageSelectProcessCaches();
        },
            'ProcessCacheResetterMemoryPressure::testSoftUnknownReasonDoesNotClearLanguageCaches' => function () {
            $this->assert_ProcessCacheResetterMemoryPressure_testSoftUnknownReasonDoesNotClearLanguageCaches();
        },
        ];
    }

private function assert_ProcessCacheResetterMemoryPressure_testSoftMemoryPressureClearsChromeBagsButNotFullPhraseWipe(): void
{
    LanguageSelect::clearProcessCaches();
    $prop = new \ReflectionProperty(LanguageSelect::class, 'countryNamesByDisplayLocale');
    $prop->setAccessible(true);
    $prop->setValue(null, ['zh_Hans_CN' => ['CN' => '中国']]);
    $resetter = new ProcessCacheResetter();
    $cleared = $resetter->resetProcessCaches(new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, false));
    // Soft: LanguageSwitcher + LanguageSelect chrome only (2); Phrase cold locales via Guard.
    self::assertSame(2, $cleared);
    self::assertSame([], $prop->getValue());
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Api/Runtime/ProcessCacheResetter.php');
    self::assertStringContainsString('isSoftContent()', $src);
    self::assertMatchesRegularExpression('/isSoftContent\(\)[\s\S]*?LanguageSwitcher::clearProcessCaches\(\)[\s\S]*?return 2;/', $src);
    self::assertDoesNotMatchRegularExpression('/isSoftContent\(\)[\s\S]*?Parser::clearWorkerCaches\(\)[\s\S]*?return 2;/', $src);
    LanguageSelect::clearProcessCaches();
}

private function assert_ProcessCacheResetterMemoryPressure_testAggressiveMemoryPressureClearsLanguageSelectProcessCaches(): void
{
    LanguageSelect::clearProcessCaches();
    $prop = new \ReflectionProperty(LanguageSelect::class, 'countryNamesByDisplayLocale');
    $prop->setAccessible(true);
    $prop->setValue(null, ['zh_Hans_CN' => ['CN' => '中国']]);
    $resetter = new ProcessCacheResetter();
    $cleared = $resetter->resetProcessCaches(new ProcessCacheResetContext(ProcessCacheResetContext::REASON_MEMORY_PRESSURE, true));
    self::assertGreaterThan(0, $cleared);
    self::assertSame([], $prop->getValue());
}

private function assert_ProcessCacheResetterMemoryPressure_testSoftUnknownReasonDoesNotClearLanguageCaches(): void
{
    $prop = new \ReflectionProperty(LanguageSelect::class, 'countryNamesByDisplayLocale');
    $prop->setAccessible(true);
    $prop->setValue(null, ['en' => ['US' => 'United States']]);
    $resetter = new ProcessCacheResetter();
    $cleared = $resetter->resetProcessCaches(new ProcessCacheResetContext('noop_soft', false));
    self::assertSame(0, $cleared);
    self::assertSame(['en' => ['US' => 'United States']], $prop->getValue());
    LanguageSelect::clearProcessCaches();
}
}
