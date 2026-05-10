<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Sticker;

use PHPUnit\Framework\TestCase;

class FrontendLoginStickerTest extends TestCase
{
    public function testCustomerLoginStickerIsNoLongerRequiredAfterHookIntegration(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $stickerFile = $moduleRoot . '/extends/module/Weline_Sticker/Weline/Customer/view/templates/frontend/account/login.phtml';

        $this->assertFileDoesNotExist($stickerFile);
    }

    public function testLoginProviderTemplateExistsAndTargetsFrontendStartRoute(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $templateFile = $moduleRoot . '/view/templates/Frontend/Auth/login-provider-button.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);
        $this->assertStringContainsString('if (!$oauthService->isConfigured()) {', $content);
        $this->assertStringContainsString('return;', $content);
        $this->assertStringContainsString('weshop_googleauth/frontend/auth/start', $content);
        $this->assertStringContainsString("'area' => 'frontend'", $content);
        $this->assertStringContainsString("'redirect_url' => \$redirectUrl", $content);
    }

    public function testCustomerLoginProviderHookWrapsGoogleProviderButton(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $hookFile = $moduleRoot . '/view/hooks/Weline_Customer/frontend/account/login/providers.phtml';

        $this->assertFileExists($hookFile);
        $content = (string) file_get_contents($hookFile);
        $this->assertStringContainsString('@hook-priority 200', $content);
        $this->assertStringContainsString('WeShop_GoogleAuth::templates/Frontend/Auth/login-provider-button.phtml', $content);
        $this->assertStringContainsString('class="auth-form__social-buttons"', $content);
        $this->assertStringContainsString('if ($googleLoginButton === \'\')', $content);
    }
}
