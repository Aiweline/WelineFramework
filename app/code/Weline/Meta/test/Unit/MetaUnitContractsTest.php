<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMetaUnitContractsPass().
 */
final class MetaUnitContractsTest extends TestCase
{
    public function testAllMetaUnitContractsPass(): void
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
            'DictionaryEvents::testMetaWritePathsDoNotRegisterDictionaryMeta' => function () {
            $this->assert_DictionaryEvents_testMetaWritePathsDoNotRegisterDictionaryMeta();
        },
            'DictionaryEvents::testAppearanceTokenGroupWritesMetaLocal' => function () {
            $this->assert_DictionaryEvents_testAppearanceTokenGroupWritesMetaLocal();
        },
        ];
    }

private function assert_DictionaryEvents_testMetaWritePathsDoNotRegisterDictionaryMeta(): void
{
    $metaModel = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Model/Meta.php');
    $templateCompile = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Observer/TemplateCompile.php');
    $scan = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Console/Meta/ScanConvention.php');
    $metaData = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Helper/MetaData.php');
    $cron = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Meta' . '/Cron/MetaTranslation.php');
    self::assertStringNotContainsString('DictionaryEvents::register', $metaModel);
    self::assertStringContainsString('MetaTranslation::setTranslatedValue', $metaModel);
    self::assertStringNotContainsString('DictionaryEvents::register', $templateCompile);
    self::assertStringContainsString('MetaTranslation::setTranslatedValueWithScope', $templateCompile);
    self::assertStringNotContainsString('DictionaryEvents::register', $scan);
    self::assertStringContainsString('MetaTranslation::setTranslatedValue', $scan);
    self::assertStringNotContainsString('dictionaryRepository()->upsert($translationKey', $metaData);
    self::assertStringContainsString('MetaTranslation::setTranslatedValueWithScope', $metaData);
    self::assertStringNotContainsString("word_prefix' => '@meta::'", $cron);
    self::assertStringContainsString('MetaLocalTranslationService', $cron);
    self::assertStringNotContainsString('DictionaryEvents::translate', $cron);
}

private function assert_DictionaryEvents_testAppearanceTokenGroupWritesMetaLocal(): void
{
    $helper = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/Helper/AppearanceTokenGroupMeta.php');
    self::assertStringNotContainsString('DictionaryEvents::register', $helper);
    self::assertStringContainsString('MetaTranslation::setTranslatedValueWithScope', $helper);
}
}
