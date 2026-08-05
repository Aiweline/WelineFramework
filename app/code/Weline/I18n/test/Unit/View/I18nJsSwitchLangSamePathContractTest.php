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
        self::assertStringContainsString("configuredHref || buildLanguageUrl", $content);
        self::assertStringContainsString('writeLanguagePreference(lang)', $content);
        self::assertStringContainsString('samePath', $content);
        self::assertStringContainsString('window.location.reload()', $content);
        self::assertStringContainsString('前台切到默认语言时 URL 往往不含语言段', $content);
        self::assertStringContainsString('onBackendPath', $content);
        self::assertStringContainsString('路由语言段最高优先', $content);
    }
}
