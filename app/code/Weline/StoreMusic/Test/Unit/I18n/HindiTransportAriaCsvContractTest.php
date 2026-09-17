<?php

declare(strict_types=1);

namespace Weline\StoreMusic\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class HindiTransportAriaCsvContractTest extends TestCase
{
    public function testHindiCsvTranslatesTransportAriaLabels(): void
    {
        $path = dirname(__DIR__, 3) . '/i18n/hi_IN.csv';
        self::assertFileExists($path);
        $hi = (string)file_get_contents($path);
        self::assertStringContainsString('播放,चलाएँ', $hi);
        self::assertStringContainsString('下一首,अगला', $hi);
        self::assertMatchesRegularExpression('/^停止播放,"?बंद करें"?$/mu', $hi);
        self::assertDoesNotMatchRegularExpression('/^播放,播放$/m', $hi);
    }

    public function testTransportAriaUsesRuntimeTranslateNotCompileLang(): void
    {
        $tpl = dirname(__DIR__, 3)
            . '/view/templates/frontend/widgets/store-music.phtml';
        self::assertFileExists($tpl);
        $src = (string)file_get_contents($tpl);
        self::assertStringContainsString("\$t('播放')", $src);
        self::assertStringContainsString("\$t('下一首')", $src);
        self::assertStringNotContainsString('aria-label="@lang(播放)"', $src);
        self::assertStringNotContainsString('<lang>', $src);
    }
}
