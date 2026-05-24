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
        $this->assertStringContainsString('<form id="loginForm" class="auth-form__body" action="/customer/account/login" method="post">', $content);
        $this->assertStringContainsString('name="redirect_url"', $content);
        $this->assertStringContainsString('value="<?= htmlspecialchars($redirectUrl, ENT_QUOTES) ?>"', $content);

        $this->assertStringContainsString('Weline_Customer::frontend::account::login::providers', $content);
        $this->assertStringNotContainsString('WeShop_GoogleAuth::templates/Frontend/Auth/login-provider-button.phtml', $content);
        $this->assertStringNotContainsString('getModuleStatus(\'WeShop_GoogleAuth\')', $content);
        $this->assertStringContainsString('/customer/account/forgot-password', $content);
        $this->assertStringContainsString('function setLoginLoading(loading)', $content);
        $this->assertStringContainsString('if ((username.value || \'\').trim() && password.value)', $content);
    }

    public function testCustomerModuleDeclaresLoginProviderHook(): void
    {
        $hooks = require dirname(__DIR__, 3) . '/hook.php';

        $this->assertIsArray($hooks);
        $this->assertArrayHasKey('Weline_Customer::frontend::account::login::providers', $hooks);
    }
}
