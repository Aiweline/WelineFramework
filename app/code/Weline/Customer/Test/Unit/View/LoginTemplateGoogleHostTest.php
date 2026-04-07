<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class LoginTemplateGoogleHostTest extends TestCase
{
    public function testLoginTemplateKeepsRedirectUrlAndGoogleProviderHostContract(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/account/login.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('$redirectUrl = (string) ($this->getData(\'redirect_url\')', $content);
        $this->assertStringContainsString('<form id="loginForm" class="auth-form__body" action="/customer/account/login" method="post">', $content);
        $this->assertStringContainsString('name="redirect_url"', $content);
        $this->assertStringContainsString('value="<?= htmlspecialchars($redirectUrl, ENT_QUOTES) ?>"', $content);

        $this->assertStringContainsString('WeShop_GoogleAuth::templates/Frontend/Auth/login-provider-button.phtml', $content);
        $this->assertStringContainsString('$googleLoginButton = trim((string) $this->fetch(', $content);
        $this->assertStringContainsString('if ($googleLoginButton !== \'\')', $content);
        $this->assertStringContainsString('class="auth-form__social-buttons"', $content);
        $this->assertStringContainsString('/customer/account/forgot-password', $content);
    }
}
