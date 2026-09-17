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
        self::assertStringContainsString('20260915-script-exec1', (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/LazyCaptchaClientRuntime.php',
        ));
    }
}
