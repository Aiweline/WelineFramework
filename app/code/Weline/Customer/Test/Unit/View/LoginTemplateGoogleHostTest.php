<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class LoginTemplateGoogleHostTest extends TestCase
{
    public function testLoginTemplateKeepsRedirectUrlAndProviderHookHostContract(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('$redirectUrl = (string) ($this->getData(\'redirect_url\')', $content);
        $this->assertStringContainsString('id="loginForm"', $content);
        $this->assertStringContainsString('action="@var($loginSubmitUrl)"', $content);
        $this->assertStringContainsString('method="post"', $content);
        $this->assertStringContainsString('name="redirect_url"', $content);
        $this->assertStringContainsString('value="<?= $safe($redirectUrl) ?>"', $content);

        $this->assertStringContainsString('Weline_Customer::frontend::account::login::providers', $content);
        $this->assertStringNotContainsString('WeShop_GoogleAuth::templates/Frontend/Auth/login-provider-button.phtml', $content);
        $this->assertStringNotContainsString('getModuleStatus(\'WeShop_GoogleAuth\')', $content);
        $this->assertStringContainsString('/customer/account/forgot-password', $content);
        $this->assertStringContainsString('data-w-component="account-login"', $content);
        $this->assertStringContainsString('data-w-login-submit', $content);

        $loginJs = (string) file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/account-login.js'
        );
        $this->assertStringContainsString('username.value.trim()', $loginJs);
        $this->assertStringContainsString('password.value', $loginJs);
    }

    public function testCustomerModuleDeclaresLoginProviderHook(): void
    {
        $hooks = (string) file_get_contents(dirname(__DIR__, 3) . '/hook.php');

        $this->assertStringContainsString('Weline_Customer::frontend::account::login::providers', $hooks);
    }
}
