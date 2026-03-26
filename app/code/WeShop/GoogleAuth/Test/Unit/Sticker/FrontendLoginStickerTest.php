<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Sticker;

use PHPUnit\Framework\TestCase;

class FrontendLoginStickerTest extends TestCase
{
    public function testCustomerLoginStickerIsNoLongerRequiredAfterTemplateIntegration(): void
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
}
