<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Lazy challenge inject must execute provider bind scripts (Google badge / degrade).
 */
final class CaptchaLazyScriptExecContractTest extends TestCase
{
    public function testLazyRuntimeExecutesFragmentScriptsAfterReplace(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/captcha-lazy.js',
        );
        self::assertStringContainsString('function executeFragmentScripts', $js);
        self::assertStringContainsString('executeFragmentScripts(wrap, mountParent)', $js);
        self::assertStringContainsString('20260924-no-dev-fallback1', (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LazyCaptchaClientRuntime.php',
        ));
    }

    public function testLazyStylesheetHasNoDevShapedFallbackLiteral(): void
    {
        $js = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/captcha-lazy.js',
        );
        self::assertStringNotContainsString('/Weline/Captcha/view/statics/', $js);
        self::assertStringNotContainsString('STYLESHEET_FALLBACK', $js);
        self::assertStringContainsString("Weline_Captcha::css/captcha-local.css", $js);
        self::assertStringContainsString('resolveStaticPath', $js);
        self::assertStringContainsString('Weline.loader', $js);
    }
}
