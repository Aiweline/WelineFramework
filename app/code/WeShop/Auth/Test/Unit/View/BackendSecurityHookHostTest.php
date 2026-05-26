<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class BackendSecurityHookHostTest extends TestCase
{
    public function testBackendSecurityTemplateHostsSecurityCardsHook(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $template = (string) file_get_contents($moduleRoot . '/view/templates/Backend/Security/index.phtml');

        $this->assertStringContainsString('WeShop_Auth::backend::account::security::cards', $template);
        $this->assertStringContainsString('Weline_Component::message.phtml', $template);
    }

    public function testAuthModuleDeclaresBackendSecurityCardsHook(): void
    {
        $hooks = require dirname(__DIR__, 3) . '/hook.php';

        $this->assertIsArray($hooks);
        $this->assertArrayHasKey('WeShop_Auth::backend::account::security::cards', $hooks);
        $this->assertSame('backend/account/security/cards.md', $hooks['WeShop_Auth::backend::account::security::cards']['doc']);
    }

    public function testTwoFactorHookUsesBackendActorAndUnifiedAccountService(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $hook = (string) file_get_contents($moduleRoot . '/view/hooks/WeShop_Auth/backend/account/security/cards.phtml');

        $this->assertStringContainsString('TwoFactorAccountService::class', $hook);
        $this->assertStringContainsString('ActorContext::ACTOR_BACKEND', $hook);
        $this->assertStringContainsString("getFlowStatus('backend')", $hook);
        $this->assertStringContainsString('weshop/backend/security/two-factor', $hook);
    }

    public function testBackendSecurityMenuParentOpensHookHostPage(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $menu = (string) file_get_contents($moduleRoot . '/etc/backend/menu.xml');

        $this->assertStringContainsString('source="WeShop_Auth::security"', $menu);
        $this->assertStringContainsString('action="weshop/backend/security/index"', $menu);
    }

    public function testBackendChallengeTemplateUsesAuthModuleRoute(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $template = (string) file_get_contents($moduleRoot . '/view/templates/Frontend/Auth/backend-challenge.phtml');
        $eventXml = (string) file_get_contents($moduleRoot . '/etc/event.xml');

        $this->assertStringContainsString('challenge_token', $template);
        $this->assertStringContainsString('Weline_Admin_Login::password_verified', $eventXml);
        $this->assertStringContainsString('WeShop\\Auth\\Observer\\BackendLoginPasswordVerified', $eventXml);
    }
}
