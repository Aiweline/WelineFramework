<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class I18nJsSwitchLangSamePathContractTest extends TestCase
{
    public function testSwitchLangReloadsWhenTargetUrlMatchesCurrentPath(): void
    {
        $path = dirname(__DIR__, 3) . '/view/statics/js/i18n.js';
        self::assertFileExists($path);
        $content = (string) file_get_contents($path);

        self::assertStringContainsString('async function switchLang(lang, authoritativeHref = \'\')', $content);
        self::assertStringContainsString('window.__WelineI18nNavigating', $content);
        self::assertStringContainsString("configuredHref || buildLanguageUrl", $content);
        self::assertStringContainsString('writeLanguagePreference(lang)', $content);
        self::assertStringContainsString('samePath', $content);
        self::assertStringContainsString('window.location.reload()', $content);
        self::assertStringContainsString('window.location.replace(target.href)', $content);
        self::assertStringContainsString('setTimeout(navigate, 0)', $content);
        self::assertStringContainsString('empty document', $content);
        self::assertStringContainsString('__weline_i18n_recover', $content);
        self::assertStringContainsString('installBlankDocumentRecovery', $content);
    }
}
