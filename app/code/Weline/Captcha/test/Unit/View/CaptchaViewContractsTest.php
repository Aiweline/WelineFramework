<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCaptchaViewContractsPass().
 */
final class CaptchaViewContractsTest extends TestCase
{
    public function testAllCaptchaViewContractsPass(): void
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
            'CaptchaLazyScriptExec::testLazyRuntimeExecutesFragmentScriptsAfterReplace' => function () {
            $this->assert_CaptchaLazyScriptExec_testLazyRuntimeExecutesFragmentScriptsAfterReplace();
        },
            'CaptchaLazyScriptExec::testLazyStylesheetHasNoDevShapedFallbackLiteral' => function () {
            $this->assert_CaptchaLazyScriptExec_testLazyStylesheetHasNoDevShapedFallbackLiteral();
        },
        ];
    }

private function assert_CaptchaLazyScriptExec_testLazyRuntimeExecutesFragmentScriptsAfterReplace(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/view/statics/js/captcha-lazy.js');
    self::assertStringContainsString('function executeFragmentScripts', $js);
    self::assertStringContainsString('executeFragmentScripts(wrap, mountParent)', $js);
    self::assertStringContainsString('20260924-no-dev-fallback1', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Service/LazyCaptchaClientRuntime.php'));
}

private function assert_CaptchaLazyScriptExec_testLazyStylesheetHasNoDevShapedFallbackLiteral(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/view/statics/js/captcha-lazy.js');
    self::assertStringNotContainsString('/Weline/Captcha/view/statics/', $js);
    self::assertStringNotContainsString('STYLESHEET_FALLBACK', $js);
    self::assertStringContainsString("Weline_Captcha::css/captcha-local.css", $js);
    self::assertStringContainsString('resolveStaticPath', $js);
    self::assertStringContainsString('Weline.loader', $js);
}
}
