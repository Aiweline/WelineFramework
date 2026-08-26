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
    }
}
