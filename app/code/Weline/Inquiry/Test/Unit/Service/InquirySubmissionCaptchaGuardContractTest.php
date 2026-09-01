<?php

declare(strict_types=1);

namespace Weline\Inquiry\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class InquirySubmissionCaptchaGuardContractTest extends TestCase
{
    public function testGuardDefinesInquirySubmitIntent(): void
    {
        self::assertSame('inquiry.submit', \Weline\Inquiry\Service\InquirySubmissionCaptchaGuard::INTENT);
    }

    public function testSubmissionServiceVerifiesCaptchaBeforePersist(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SubmissionService.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('InquirySubmissionCaptchaGuard', $src);
        self::assertStringContainsString('$this->captchaGuard->verify($params)', $src);
    }

    public function testRendererInjectsLazyCaptchaHostWhenModulePresent(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/InquiryRenderer.php';
        $src = (string)file_get_contents($path);
        self::assertStringNotContainsString('LazyCaptchaClientRuntime::onceScriptHtml', $src);
        self::assertStringContainsString('captchaEnabled', $src);
        self::assertStringContainsString('captchaModule', $src);
        self::assertStringContainsString('ensureCaptchaModule', $src);
        self::assertStringContainsString('captchaModulePath', $src);
        self::assertStringContainsString('LazyCaptchaClientRuntime::resolveScriptUrl', $src);
        self::assertStringContainsString('Weline.load(moduleName,modulePath||null)', $src);
        self::assertStringContainsString('data-weline-captcha-lazy', $src);
        self::assertStringContainsString('captchaPayload(dataForm)', $src);
        self::assertStringContainsString('inquiry.submit', $src);
    }

    public function testCaptchaModuleRegistersLazyRuntime(): void
    {
        $path = dirname(__DIR__, 4) . '/Captcha/view/statics/frontend/weline.modules.js';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('captchaLazy', $src);
        self::assertStringContainsString('captcha-lazy.js', $src);
        self::assertStringContainsString('globalVar: null', $src);
    }

    public function testQueryProviderAcceptsCaptchaFieldsOnSubmit(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/InquiryQueryProvider.php';
        $src = (string)file_get_contents($path);
        self::assertStringContainsString("'captcha_provider'", $src);
        self::assertStringContainsString("'captcha_token'", $src);
    }
}
