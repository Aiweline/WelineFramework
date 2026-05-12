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

        $this->assertStringContainsString('<w:hook name="account.sidebar.content"/>', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::orders', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::subscriptions', $content);
        $this->assertStringContainsString('data-account-section="profile"', $content);
        $this->assertStringContainsString('data-account-section="security"', $content);
        $this->assertStringContainsString('data-account-section="login-info"', $content);
        $this->assertStringContainsString("var initialSection = raw && hasNav ? raw : 'profile';", $content);
        $this->assertStringContainsString("activeParent = nav.getAttribute('data-account-nav-parent') || '';", $content);
        $this->assertStringContainsString("var isActiveParent = activeParent && nav.getAttribute('data-section') === activeParent;", $content);
        $this->assertStringContainsString("nav.classList.remove('account-sidebar__nav-link--active');", $content);
    }

    public function testSidebarTemplateKeepsCanonicalSidebarHookHost(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/account/sidebar/side.phtml';

        $this->assertFileExists($templateFile);
        $content = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('<w:hook name="account.sidebar"/>', $content);
        $this->assertStringContainsString('data-account-nav-link="true"', $content);
        $this->assertStringNotContainsString('ri-user-line', $content);
        $this->assertStringNotContainsString('ri-lock-line', $content);
        $this->assertStringNotContainsString('ri-logout-box-line', $content);
        $this->assertStringNotContainsString('account-sidebar__nav-link account-sidebar__nav-link--active', $content);
        $this->assertStringContainsString('.account-hook-nav-link.is-active', $content);
        $this->assertStringContainsString('.account-hook-nav-link.is-active:not([data-account-nav-parent])', $content);
        $this->assertStringContainsString('.account-hook-nav-link[data-account-nav-parent].is-active', $content);
        $this->assertStringContainsString('order: 21;', $content);
        $this->assertStringContainsString('justify-content: flex-start;', $content);
        $this->assertStringContainsString('padding: 0.75rem 1rem;', $content);
        $this->assertStringContainsString('.account-hook-nav-link__label i', $content);
        $this->assertStringContainsString('display: none;', $content);
        $this->assertStringContainsString('.account-hook-nav-link[data-account-nav-parent] .account-hook-nav-link__label i', $content);
        $this->assertStringContainsString('display: inline-block;', $content);
        $this->assertStringContainsString('.account-hook-nav-link[data-account-nav-parent] .account-hook-nav-link__text span', $content);
        $this->assertStringContainsString('box-shadow: none;', $content);
        $this->assertStringNotContainsString('#orders', $content);
        $this->assertStringNotContainsString('#subscriptions', $content);
    }

    public function testTwoFactorAuthHookUsesAccountSectionProtocol(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $sidebarFile = $moduleRoot . '/TwoFactorAuth/view/hooks/account.sidebar.phtml';
        $contentFile = $moduleRoot . '/TwoFactorAuth/view/hooks/account.sidebar.content.phtml';

        $this->assertFileExists($sidebarFile);
        $this->assertFileExists($contentFile);
        $sidebar = (string) file_get_contents($sidebarFile);
        $content = (string) file_get_contents($contentFile);

        $this->assertStringContainsString('account-hook-nav-link', $sidebar);
        $this->assertStringNotContainsString('account-hook-nav-group', $sidebar);
        $this->assertStringNotContainsString('account-hook-nav-title', $sidebar);
        $this->assertStringNotContainsString('账户安全', $sidebar);
        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);
        $this->assertStringContainsString('data-section="twofa"', $sidebar);
        $this->assertStringContainsString('data-account-nav-parent="security"', $sidebar);
        $this->assertStringNotContainsString('class="nav-link"', $sidebar);
        $this->assertStringContainsString('id="twofa-section"', $content);
        $this->assertStringContainsString('data-account-section="twofa"', $content);
        $this->assertStringContainsString('hidden', $content);
        $this->assertStringContainsString('account-card__body', $content);
        $this->assertStringNotContainsString('Weline_Theme::theme/frontend/components/card.phtml', $content);
        $this->assertStringContainsString('id="authenticatorAppModal"', $content);
        $this->assertStringContainsString('data-twofa-platform="desktop"', $content);
        $this->assertStringContainsString("querySelectorAll('[data-twofa-platform]')", $content);
        $this->assertStringNotContainsString('let installInstructions =', $content);
        $this->assertStringNotContainsString('modal.innerHTML = `', $content);
    }

    public function testShippingHookUsesAccountSectionProtocol(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $sidebarFile = $moduleRoot . '/Shipping/view/hooks/account.sidebar.phtml';
        $contentFile = $moduleRoot . '/Shipping/view/hooks/account.sidebar.content.phtml';

        $this->assertFileExists($sidebarFile);
        $this->assertFileExists($contentFile);
        $sidebar = (string) file_get_contents($sidebarFile);
        $content = (string) file_get_contents($contentFile);

        $this->assertStringContainsString('account-hook-nav-link', $sidebar);
        $this->assertStringNotContainsString('account-hook-nav-group', $sidebar);
        $this->assertStringNotContainsString('account-hook-nav-title', $sidebar);
        $this->assertStringNotContainsString('地址管理', $sidebar);
        $this->assertStringContainsString('$accountIndexPath', $sidebar);
        $this->assertStringContainsString('#shipping-address"', $sidebar);
        $this->assertStringContainsString('#delivery-address"', $sidebar);
        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);
        $this->assertStringContainsString('data-section="shipping-address"', $sidebar);
        $this->assertStringContainsString('data-section="delivery-address"', $sidebar);
        $this->assertStringNotContainsString('data-account-nav-parent', $sidebar);
        $this->assertStringNotContainsString('class="nav-link"', $sidebar);
        $this->assertStringNotContainsString('shipping/address/index', $sidebar);
        $this->assertStringNotContainsString('shipping/delivery/index', $sidebar);
        $this->assertStringContainsString('id="shipping-address-section"', $content);
        $this->assertStringContainsString('id="delivery-address-section"', $content);
        $this->assertStringContainsString('data-account-section="shipping-address"', $content);
        $this->assertStringContainsString('data-account-section="delivery-address"', $content);
        $this->assertStringNotContainsString('<dd>', $content);
        $this->assertStringNotContainsString('<dt>', $content);
        $this->assertStringContainsString('hidden', $content);
    }
}
