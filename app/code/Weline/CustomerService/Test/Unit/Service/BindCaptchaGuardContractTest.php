<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\BindCaptchaGuard;

final class BindCaptchaGuardContractTest extends TestCase
{
    public function testBindCaptchaGuardDeclaresStableIntentAndFormId(): void
    {
        $this->assertSame('customerservice.bind_email', BindCaptchaGuard::INTENT);
        $this->assertSame('cs-bind-form', BindCaptchaGuard::FORM_ID);
    }

    public function testBindControllerVerifiesCaptchaBeforeSendingEmail(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Bind.php');

        $this->assertStringContainsString('BindCaptchaGuard', $controller);
        $this->assertStringContainsString('$this->bindCaptchaGuard->verify($submission, $this->request)', $controller);
        $this->assertStringContainsString('getCaptchaChallenge', $controller);
    }

    public function testQueryProviderVerifiesCaptchaBeforeSendingEmail(): void
    {
        $provider = (string) file_get_contents(dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php');

        $this->assertStringContainsString('BindCaptchaGuard', $provider);
        $this->assertStringContainsString('$this->bindCaptchaGuard->verify($params, $this->request)', $provider);
        $this->assertStringContainsString('captcha_provider', $provider);
        $this->assertStringContainsString('人机验证失败或已过期，请重试', $provider);
        $this->assertStringContainsString('captcha_degrade', $provider);
        $this->assertStringNotContainsString(
            'Captcha verification failed or expired. Please try again.',
            $provider
        );
    }

    public function testBindCaptchaChallengeSupportsLocalPrefer(): void
    {
        $controller = (string) file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Bind.php');
        $guard = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/BindCaptchaGuard.php');
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/customer-service.js');

        $this->assertStringContainsString("getGet('prefer'", $controller);
        $this->assertStringContainsString('allowsLocalDegrade', $guard);
        $this->assertStringContainsString("prefer', 'local_image'", $js);
        $this->assertStringContainsString('captcha_degrade', $js);
        $this->assertStringContainsString("cache: 'no-store'", $js);
        $this->assertStringContainsString('async function showBindPrompt', $js);
        $this->assertStringContainsString('SharedResponseCachePolicy::forbid', $controller);
    }
}
