<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllStoreMusicI18nContractsPass().
 */
final class StoreMusicI18nContractsTest extends TestCase
{
    public function testAllStoreMusicI18nContractsPass(): void
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
            'HindiTransportAriaCsv::testHindiCsvTranslatesTransportAriaLabels' => function () {
            $this->assert_HindiTransportAriaCsv_testHindiCsvTranslatesTransportAriaLabels();
        },
            'HindiTransportAriaCsv::testTransportAriaUsesRuntimeTranslateNotCompileLang' => function () {
            $this->assert_HindiTransportAriaCsv_testTransportAriaUsesRuntimeTranslateNotCompileLang();
        },
            'StoreMusicIntroExpandCsv::testNonZhLocalesTranslateIntroExpandCollapse' => function () {
            $this->assert_StoreMusicIntroExpandCsv_testNonZhLocalesTranslateIntroExpandCollapse();
        },
            'StoreMusicIntroExpandCsv::testUrduLocalizesPlaylistChrome' => function () {
            $this->assert_StoreMusicIntroExpandCsv_testUrduLocalizesPlaylistChrome();
        },
        ];
    }

private function assert_HindiTransportAriaCsv_testHindiCsvTranslatesTransportAriaLabels(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/i18n/hi_IN.csv';
    self::assertFileExists($path);
    $hi = (string) file_get_contents($path);
    self::assertStringContainsString('播放,चलाएँ', $hi);
    self::assertStringContainsString('下一首,अगला', $hi);
    self::assertMatchesRegularExpression('/^停止播放,"?बंद करें"?$/mu', $hi);
    self::assertDoesNotMatchRegularExpression('/^播放,播放$/m', $hi);
}

private function assert_HindiTransportAriaCsv_testTransportAriaUsesRuntimeTranslateNotCompileLang(): void
{
    $tpl = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/view/templates/frontend/widgets/store-music.phtml';
    self::assertFileExists($tpl);
    $src = (string) file_get_contents($tpl);
    self::assertStringContainsString("\$t('播放')", $src);
    self::assertStringContainsString("\$t('下一首')", $src);
    self::assertStringNotContainsString('aria-label="@lang(播放)"', $src);
    self::assertStringNotContainsString('<lang>', $src);
}

/** @return list<string> */
private function localeFiles_StoreMusicIntroExpandCsv(): array
{
    $dir = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/i18n';
    $files = glob($dir . '/*.csv') ?: [];
    sort($files);
    return $files;
}

private function assert_StoreMusicIntroExpandCsv_testNonZhLocalesTranslateIntroExpandCollapse(): void
{
    $files = $this->localeFiles_StoreMusicIntroExpandCsv();
    self::assertNotEmpty($files);
    foreach ($files as $path) {
        $loc = basename($path, '.csv');
        $raw = (string) file_get_contents($path);
        self::assertStringNotContainsString("﻿", $raw, $loc . ' must not have UTF-8 BOM');
        if ($loc === 'zh_Hans_CN') {
            continue;
        }
        self::assertDoesNotMatchRegularExpression('/^展开简介,展开简介$/mu', $raw, $loc);
        self::assertDoesNotMatchRegularExpression('/^收起简介,收起简介$/mu', $raw, $loc);
        self::assertMatchesRegularExpression('/^展开简介,.+$/mu', $raw, $loc);
        self::assertMatchesRegularExpression('/^收起简介,.+$/mu', $raw, $loc);
    }
}

private function assert_StoreMusicIntroExpandCsv_testUrduLocalizesPlaylistChrome(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/StoreMusic' . '/i18n/ur_PK.csv';
    $raw = (string) file_get_contents($path);
    self::assertStringContainsString('播放列表,"پلے لسٹ"', $raw);
    self::assertStringContainsString('展开简介,"تعارف دیکھیں"', $raw);
    self::assertStringContainsString('收起简介,"تعارف چھپائیں"', $raw);
    self::assertStringContainsString('"{n} ٹریکس"', $raw);
}
}
