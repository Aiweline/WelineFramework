<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarHookHostTest extends TestCase
{
    public function testAccountIndexUsesCanonicalSidebarContentHost(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/account/index.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('<hook>account.sidebar.content</hook>', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::orders', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::subscriptions', $content);
        $this->assertStringContainsString('data-account-section="profile"', $content);
        $this->assertStringContainsString('data-account-section="security"', $content);
        $this->assertStringContainsString('data-account-section="login-info"', $content);
    }

    public function testSidebarTemplateKeepsCanonicalSidebarHookHost(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/account/sidebar/side.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('<hook>account.sidebar</hook>', $content);
        $this->assertStringContainsString('data-account-nav-link="true"', $content);
        $this->assertStringNotContainsString('#orders', $content);
        $this->assertStringNotContainsString('#subscriptions', $content);
    }
}
