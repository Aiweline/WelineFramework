<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountSidebarHookHostTest extends TestCase
{
    public function testAccountIndexUsesCanonicalSidebarContentHost(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $templateFile = $moduleRoot . '/view/templates/frontend/account/index.phtml';
        $scriptFile = $moduleRoot . '/view/statics/js/account-index.js';

        $this->assertFileExists($templateFile);
        $this->assertFileExists($scriptFile);
        $content = (string) file_get_contents($templateFile);
        $script = (string) file_get_contents($scriptFile);

        $this->assertStringContainsString('data-account-sidebar-content-mount', $content);
        $this->assertStringContainsString('data-account-sidebar-content-url', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::orders', $content);
        $this->assertStringNotContainsString('Weline_Customer::frontend::account::index::subscriptions', $content);
        $this->assertStringContainsString('data-account-section="profile"', $content);
        $this->assertStringContainsString('data-account-section="security"', $content);
        $this->assertStringContainsString('data-account-section="login-info"', $content);
        $this->assertStringContainsString('data-weline-load="api,account,customerAccount"', $content);
        $this->assertStringContainsString('20260906-profile-header-sync-1', $content);
        $this->assertStringContainsString('data-account-pending-section', $content);
        $this->assertStringContainsString('sectionLoading', $content);
        $this->assertStringContainsString('hideBuiltinSections', $content);
        $this->assertStringContainsString('__welineAccountIndexInitialized', $content);
        $this->assertStringNotContainsString('function syncFromHash()', $content);

        $this->assertStringContainsString('function parseAccountHash()', $script);
        $this->assertStringContainsString('function hasNavSection(section)', $script);
        $this->assertStringContainsString('function syncFromHash()', $script);
        $this->assertStringContainsString("targetId = 'profile';", $script);
        $this->assertStringContainsString('function ensureSectionLoadingPlaceholder(sectionName)', $script);
        $this->assertStringContainsString('function revealAccountSection(sectionName)', $script);
        $this->assertStringContainsString('function markSectionLoadFailed(sectionName, message)', $script);
        $this->assertStringContainsString('data-account-section-loading', $script);
        $this->assertStringContainsString('function clearPendingSectionFlag(sectionName)', $script);
        // FB OAuth often lands on #social-login; switching to profile must clear pending
        // or CSS keeps display:none on the profile form (blank main pane).
        $this->assertStringContainsString(
            "document.documentElement.removeAttribute('data-account-pending-section');",
            $script
        );
        $this->assertStringNotContainsString('if (!sectionName || pending === sectionName)', $script);
        $this->assertStringContainsString("activeParent = nav.getAttribute('data-account-nav-parent') || '';", $script);
        $this->assertStringContainsString("var isActiveParent = activeParent && nav.getAttribute('data-section') === activeParent;", $script);
        $this->assertStringContainsString("nav.classList.remove('account-sidebar__nav-link--active');", $script);
        $this->assertStringContainsString('function buildSidebarContentUrl(sectionName)', $script);
        $this->assertStringContainsString("'section=' + encodeURIComponent(sectionName)", $script);
        $this->assertStringContainsString('loadSidebarContent(targetId, loadingState === \'failed\' ? { force: true } : {})', $script);
        $this->assertStringContainsString('function sanitizeSidebarHtml(html)', $script);
        $this->assertStringContainsString('function loadTrustedSidebarStyles(html)', $script);
        $this->assertStringContainsString('loadTrustedSidebarStyles(payload.html)', $script);
        $this->assertStringContainsString("existing.insertAdjacentHTML('afterend', safeHtml)", $script);
        $this->assertStringContainsString("sidebarContentMount.insertAdjacentHTML('beforeend', safeHtml)", $script);
        $this->assertStringContainsString('function loadDeclaredSidebarModules(root)', $script);
        $this->assertStringContainsString('loadDeclaredSidebarModules(sidebarContentMount)', $script);
        $this->assertStringContainsString('function reloadSidebarSection(sectionName)', $script);
        $this->assertStringContainsString('loadSidebarContent(sectionName, { force: true })', $script);
        $this->assertStringContainsString('function openOrdersSectionViaBinQuery(orderUuid)', $script);
        $this->assertStringContainsString('function bindAccountOrdersSoftNavigation(root)', $script);
        $this->assertStringContainsString('[data-order-detail-link="true"]', $script);
        $this->assertStringContainsString('[data-account-orders-back="true"]', $script);
        $this->assertStringContainsString("api.resource('account').getSidebarSection(sidebarPayload)", $script);
        $this->assertStringContainsString("weline:account-sidebar-section-reload", $script);
        $this->assertStringNotContainsString('executeInsertedScripts', $script);
    }

    public function testSidebarTemplateKeepsCanonicalSidebarHookHost(): void
    {
        $moduleRoot = dirname(__DIR__, 3);
        $templateFile = $moduleRoot . '/view/templates/frontend/account/sidebar/side.phtml';
        $cssFile = $moduleRoot . '/view/statics/css/account-sidebar.css';

        $this->assertFileExists($templateFile);
        $this->assertFileExists($cssFile);
        $content = (string) file_get_contents($templateFile);
        $css = (string) file_get_contents($cssFile);

        $this->assertStringContainsString('<w:hook name="account.sidebar.group.security"/>', $content);
        $this->assertStringContainsString('<w:hook name="account.sidebar.group.commerce"/>', $content);
        $this->assertStringContainsString('<w:hook name="account.sidebar.group.addresses"/>', $content);
        $this->assertStringContainsString('<w:hook name="account.sidebar.group.connections"/>', $content);
        $this->assertStringContainsString('<w:hook name="account.sidebar.group.developer"/>', $content);
        $this->assertStringContainsString('<w:hook name="account.sidebar"/>', $content);
        $this->assertStringContainsString('data-account-nav-group="addresses"', $content);
        $this->assertStringContainsString('data-account-nav-group="commerce"', $content);
        $this->assertStringContainsString('id="account-sidebar-status"', $content);
        $this->assertStringContainsString('id="account-sidebar-nav-before"', $content);
        $this->assertStringContainsString('id="account-sidebar-nav-after"', $content);
        $this->assertStringContainsString('id="account-sidebar-footer"', $content);
        $this->assertStringContainsString('data-account-login-status="signed-in"', $content);
        $this->assertStringContainsString('position="header"', $content);
        $this->assertStringContainsString('position="sidebar"', $content);
        $this->assertStringContainsString('position="footer"', $content);
        $this->assertStringNotContainsString('position="status"', $content);
        $this->assertStringNotContainsString('position="nav-before"', $content);
        $this->assertStringNotContainsString('position="nav-after"', $content);
        $this->assertStringContainsString('data-account-nav-link="true"', $content);
        $this->assertStringNotContainsString('ri-user-line', $content);
        $this->assertStringNotContainsString('ri-lock-line', $content);
        $this->assertStringNotContainsString('ri-logout-box-line', $content);
        $this->assertStringNotContainsString('account-sidebar__nav-link account-sidebar__nav-link--active', $content);
        $this->assertStringContainsString('pruneEmptyGroups', $content);
        $this->assertStringContainsString('.account-hook-nav-group--developer', $css);
        $this->assertStringContainsString('.account-hook-nav-group--addresses', $css);
        $this->assertStringContainsString('.account-hook-nav-group--commerce', $css);
        $this->assertStringContainsString('order: 40;', $css);
        $this->assertStringContainsString('order: 21;', $css);
        $this->assertStringContainsString('justify-content: flex-start;', $css);
        $this->assertStringContainsString('padding: 0.75rem 1rem;', $css);
        $this->assertStringContainsString('.account-hook-nav-link__label i, .account-hook-nav-link__label .w-icon', $css);
        $this->assertStringContainsString('display: none;', $css);
        $this->assertStringContainsString('.account-hook-nav-link[data-account-nav-parent] .account-hook-nav-link__label .w-icon', $css);
        $this->assertStringContainsString('display: inline-block;', $css);
        $this->assertStringContainsString('.account-hook-nav-link[data-account-nav-parent] .account-hook-nav-link__text span', $css);
        $this->assertStringContainsString('box-shadow: none;', $css);
        $this->assertStringContainsString('.account-sidebar__login-status', $css);
        $this->assertStringNotContainsString('#orders', $content);
        $this->assertStringNotContainsString('#subscriptions', $content);
    }

    public function testAccountIndexHeaderAllowsLongIdentityToWrapOnMobile(): void
    {
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/account-index.css';
        $templateFile = dirname(__DIR__, 3) . '/view/templates/frontend/account/index.phtml';

        $this->assertFileExists($cssFile);
        $this->assertFileExists($templateFile);
        $css = (string) file_get_contents($cssFile);
        $template = (string) file_get_contents($templateFile);

        $this->assertStringContainsString('20260906-profile-header-sync-1', $template);
        $this->assertStringContainsString('.account-index__user-info', $css);
        $this->assertStringContainsString('max-width: 100%;', $css);
        $this->assertStringContainsString('.account-index__username', $css);
        $this->assertStringContainsString('.account-index__email', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $css);
        $this->assertStringContainsString('word-break: break-word;', $css);
        // Primary gradient header must use on-primary (not page body text-dark).
        $this->assertStringContainsString(
            '.account-index__username { font-size: 1.5rem; font-weight: 700; color: var(--weline-theme-on-primary, var(--color-on-primary));',
            $css,
        );
        $this->assertStringContainsString(
            '.account-index__welcome { margin: 0.5rem 0 0; font-size: 0.875rem; color: var(--weline-theme-on-primary, var(--color-on-primary));',
            $css,
        );
        $this->assertStringContainsString(
            '.account-index__logout { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.5rem 1rem; border-radius: var(--border-radius-lg, 0.75rem); background: color-mix(in srgb, var(--weline-theme-on-primary, var(--color-on-primary)) 18%, transparent);',
            $css,
        );
        $this->assertStringNotContainsString(
            '.account-index__username { font-size: 1.5rem; font-weight: 700; color: var(--color-text-dark);',
            $css,
        );
    }

    public function testAccountSidebarActiveNavUsesOnPrimary(): void
    {
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/account-sidebar.css';
        $this->assertFileExists($cssFile);
        $css = (string) file_get_contents($cssFile);

        $onPrimary = 'var(--weline-theme-on-primary, var(--color-on-primary))';
        $this->assertStringContainsString(
            '.account-sidebar__nav-link--active { background: var(--color-primary); color: ' . $onPrimary . ';',
            $css,
        );
        $this->assertStringContainsString(
            '.account-sidebar__nav-link--active i { color: ' . $onPrimary . ';',
            $css,
        );
        $this->assertStringContainsString(
            '.account-hook-nav-link.is-active:not([data-account-nav-parent]) { background: var(--color-primary); border-color: transparent; box-shadow: none; color: ' . $onPrimary . ';',
            $css,
        );
        $this->assertStringContainsString(
            '.account-sidebar__avatar-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-primary-dark) 100%); color: ' . $onPrimary . ';',
            $css,
        );
        $this->assertStringNotContainsString(
            '.account-sidebar__nav-link--active { background: var(--color-primary); color: var(--color-text-dark);',
            $css,
        );
        $this->assertStringNotContainsString(
            '.account-hook-nav-link.is-active:not([data-account-nav-parent]) { background: var(--color-primary); border-color: transparent; box-shadow: none; color: var(--color-text-dark);',
            $css,
        );
    }

    public function testTwoFactorAuthHookUsesAccountSectionProtocol(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $sidebarFile = $moduleRoot . '/TwoFactorAuth/view/hooks/account.sidebar.group.security.phtml';
        $contentFile = $moduleRoot . '/TwoFactorAuth/view/hooks/account.sidebar.content.phtml';
        $scriptFile = $moduleRoot . '/TwoFactorAuth/view/statics/Frontend/js/account-two-factor-inline-v2.js';

        $this->assertFileExists($sidebarFile);
        $this->assertFileExists($contentFile);
        $this->assertFileExists($scriptFile);
        $sidebar = (string) file_get_contents($sidebarFile);
        $content = (string) file_get_contents($contentFile);
        $script = (string) file_get_contents($scriptFile);

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
        $this->assertStringContainsString('data-twofa-action="disable"', $content);
        $this->assertStringContainsString('禁用两步验证', $content);
        $this->assertStringContainsString('data-weline-load="accountTwoFactor"', $content);
        $this->assertStringContainsString("action=\"@url{'two-factor-auth/frontend/setup/enable'}\"", $content);
        $this->assertStringContainsString("action=\"@url{'two-factor-auth/frontend/setup/disable'}\"", $content);
        $this->assertStringContainsString("action=\"@url{'two-factor-auth/frontend/setup/regenerate-backup-codes'}\"", $content);
        $modulesFile = $moduleRoot . '/TwoFactorAuth/view/statics/Frontend/weline.modules.js';
        $this->assertFileExists($modulesFile);
        $modules = (string) file_get_contents($modulesFile);
        $this->assertStringContainsString('accountTwoFactor', $modules);
        $this->assertStringContainsString('account-two-factor-inline-v2.js', $modules);
        $this->assertStringContainsString("window.Weline.Api.resource('twoFactor')", $script);
        $this->assertStringContainsString("action === 'regenerate'", $script);
        $this->assertStringContainsString('refreshAccountTwoFaView', $script);
        $this->assertStringContainsString('function showToast(message, tone)', $script);
        $this->assertStringContainsString('Weline.UI.toast', $script);
        $this->assertStringContainsString("weline:account-sidebar-section-reload", $script);
        $this->assertStringNotContainsString('window.location.reload()', $script);
        $this->assertStringNotContainsString("window.location.href = '/customer/account/index#twofa';", $script);
        $this->assertDoesNotMatchRegularExpression(
            '/location\\.href\\s*=\\s*[\'"]\\/customer\\/account\\/index#twofa[\'"]\\s*;\\s*window\\.location\\.reload\\s*\\(/s',
            $script
        );
    }

    public function testShippingHookUsesAccountSectionProtocol(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $sidebarFile = $moduleRoot . '/Shipping/view/hooks/account.sidebar.group.addresses.phtml';
        $contentFile = $moduleRoot . '/Shipping/view/hooks/account.sidebar.content.phtml';

        $this->assertFileExists($sidebarFile);
        $this->assertFileExists($contentFile);
        $sidebar = (string) file_get_contents($sidebarFile);
        $content = (string) file_get_contents($contentFile);

        $this->assertStringContainsString('account-hook-nav-link', $sidebar);
        $this->assertStringNotContainsString('account-hook-nav-group', $sidebar);
        $this->assertStringNotContainsString('account-hook-nav-title', $sidebar);
        $this->assertStringNotContainsString('地址管理', $sidebar);
        $this->assertStringContainsString("@url{'customer/account/index'}#shipping-address", $sidebar);
        $this->assertStringContainsString("@url{'customer/account/index'}#delivery-address", $sidebar);
        $this->assertStringContainsString('#shipping-address"', $sidebar);
        $this->assertStringContainsString('#delivery-address"', $sidebar);
        $this->assertLessThan(
            strpos($sidebar, 'data-section="shipping-address"'),
            strpos($sidebar, 'data-section="delivery-address"'),
            '收货地址入口须排在发货地址之前，对齐顶部配送地址'
        );
        $this->assertStringContainsString('data-account-nav-link="true"', $sidebar);
        $this->assertStringContainsString('data-section="shipping-address"', $sidebar);
        $this->assertStringContainsString('data-section="delivery-address"', $sidebar);
        $this->assertStringContainsString('data-account-nav-parent="addresses"', $sidebar);
        $this->assertStringNotContainsString('class="nav-link"', $sidebar);
        $this->assertStringNotContainsString('shipping/address/index', $sidebar);
        $this->assertStringNotContainsString('shipping/delivery/index', $sidebar);
        $this->assertStringContainsString('id="shipping-address-section"', $content);
        $this->assertStringContainsString('id="delivery-address-section"', $content);
        $this->assertLessThan(
            strpos($content, 'id="shipping-address-section"'),
            strpos($content, 'id="delivery-address-section"'),
            '收货地址内容区须排在发货地址之前'
        );
        $this->assertStringContainsString('去收货地址查看顶部配送地址', $content);
        $this->assertStringContainsString('data-account-section="shipping-address"', $content);
        $this->assertStringContainsString('data-account-section="delivery-address"', $content);
        $this->assertStringNotContainsString('<dd>', $content);
        $this->assertStringNotContainsString('<dt>', $content);
        $this->assertStringContainsString('hidden', $content);
    }
}