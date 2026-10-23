<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 6 *ContractTest files).
 * Observe one overall pass/fail via testAllAdminViewContractsPass().
 */
final class AdminViewContractsTest extends TestCase
{
    public function testAllAdminViewContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BackendMenuPathMatch::testPathMatchRejectsSiblingFeatureLeavesAndKeepsActionAliases' => function () {
            $this->assert_BackendMenuPathMatch_testPathMatchRejectsSiblingFeatureLeavesAndKeepsActionAliases();
        },
            'BackendMenuPathMatch::testActiveMenuMarkupIncludesDataState' => function () {
            $this->assert_BackendMenuPathMatch_testActiveMenuMarkupIncludesDataState();
        },
            'BackendMenuPathMatch::testActiveCssIsDistinctFromHover' => function () {
            $this->assert_BackendMenuPathMatch_testActiveCssIsDistinctFromHover();
        },
            'BackendMenuPathMatch::testThemeNavFilterPathMatchIsHyphenInsensitive' => function () {
            $this->assert_BackendMenuPathMatch_testThemeNavFilterPathMatchIsHyphenInsensitive();
        },
            'BackendTopbarStorefrontLink::testTopbarExposesStorefrontLinkInNewTabBeforeLanguageSwitcher' => function () {
            $this->assert_BackendTopbarStorefrontLink_testTopbarExposesStorefrontLinkInNewTabBeforeLanguageSwitcher();
        },
            'BackendTopbarUniversalSearch::testTopbarHostsBackendUniversalSearchAndSidebarIsMenuOnly' => function () {
            $this->assert_BackendTopbarUniversalSearch_testTopbarHostsBackendUniversalSearchAndSidebarIsMenuOnly();
        },
            'LoginFlashI18n::testAdminEnUsCoversLoginFlashAndMessageTitles' => function () {
            $this->assert_LoginFlashI18n_testAdminEnUsCoversLoginFlashAndMessageTitles();
        },
            'LoginFlashI18n::testGateExposesTranslatableChineseSourceKeys' => function () {
            $this->assert_LoginFlashI18n_testGateExposesTranslatableChineseSourceKeys();
        },
            'LoginStorefrontLink::testLoginToolbarUsesSameStorefrontLinkAsBackendTopbar' => function () {
            $this->assert_LoginStorefrontLink_testLoginToolbarUsesSameStorefrontLinkAsBackendTopbar();
        },
            'LoginSiteIdentity::testLoginPrefersCurrentWebsiteNameOverBackendSiteName' => function () {
            $this->assert_LoginSiteIdentity_testLoginPrefersCurrentWebsiteNameOverBackendSiteName();
        },
            'ThemeColorMode::testAdminControlsUseTheNativeThreeStateThemeContract' => function () {
            $this->assert_ThemeColorMode_testAdminControlsUseTheNativeThreeStateThemeContract();
        },
            'ThemeColorMode::testBackendShellHasOneWelineUiAssetOwner' => function () {
            $this->assert_ThemeColorMode_testBackendShellHasOneWelineUiAssetOwner();
        },
            'BackendMenuSignalDeepLink::testSidebarHostsSignalPanelAndClickableBadgeHooks' => function () {
            $this->assert_BackendMenuSignalDeepLink_testSidebarHostsSignalPanelAndClickableBadgeHooks();
        },
        ];
    }

private function assert_BackendMenuSignalDeepLink_testSidebarHostsSignalPanelAndClickableBadgeHooks(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Admin';
    $sidebar = (string) file_get_contents($moduleRoot . '/view/templates/common/left-sidebar.phtml');
    $js = (string) file_get_contents($moduleRoot . '/view/statics/js/backend-menu-signals.js');
    $css = (string) file_get_contents($moduleRoot . '/view/statics/css/backend-menu-signals.css');

    self::assertStringContainsString('id="w-backend-menu-signal-menu"', $sidebar);
    self::assertStringContainsString('data-testid="backend-menu-signal-panel"', $sidebar);
    self::assertStringContainsString('data-w-component="menu"', $sidebar);
    self::assertStringContainsString('data-w-menu-panel', $sidebar);
    self::assertStringContainsString('data-i18n-signal-panel', $sidebar);
    self::assertStringContainsString('菜单待办', $sidebar);
    self::assertStringContainsString('查看对应菜单：%s', $sidebar);

    self::assertStringContainsString('collectContributingLeaves', $js);
    self::assertStringContainsString('collectPanelEntries', $js);
    self::assertStringContainsString('normalizeCodeEntry', $js);
    self::assertStringContainsString('applyLeafHrefOverride', $js);
    self::assertStringContainsString('data-backend-menu-href-base', $js);
    self::assertStringContainsString('primary_href', $js);
    self::assertStringContainsString('openSignalPanel', $js);
    self::assertStringContainsString('navigateToLeaf', $js);
    self::assertStringContainsString('function noteViewed()', $js);
    self::assertStringContainsString('function releaseViewed()', $js);
    self::assertStringContainsString("STREAM_CHANNEL = 'admin.menuSignalWatch'", $js);
    self::assertStringContainsString("handle.addEventListener('menu_signals'", $js);
    self::assertStringContainsString('function startStream()', $js);
    self::assertStringContainsString('SSE unavailable, polling.', $js);
    self::assertStringContainsString('omitViewedItems', $js);
    self::assertStringContainsString('viewedHrefKeys', $js);
    self::assertStringContainsString('anchorSignalTriggerToBadge', $js);
    self::assertStringContainsString('is-clickable', $js);
    self::assertStringContainsString('expandOverlay', $js);
    self::assertStringContainsString('data-backend-menu-signal-target', $js);
    self::assertStringContainsString('data-backend-menu-signal-item', $js);
    self::assertStringNotContainsString('Weline_CustomerService::console', $js);
    self::assertStringNotContainsString('full_page_cache', $js);
    self::assertStringNotContainsString('customerservice/backend/console', $js);

    $itemsApi = (string) file_get_contents($moduleRoot . '/Api/BackendMenuSignalItemsProviderInterface.php');
    self::assertStringContainsString('function items(int $backendUserId)', $itemsApi);
    $aggregator = (string) file_get_contents($moduleRoot . '/Service/BackendMenuSignalAggregator.php');
    self::assertStringContainsString('BackendMenuSignalItemsProviderInterface', $aggregator);
    self::assertStringContainsString('primary_href', $aggregator);
    self::assertStringContainsString('MAX_ITEMS_PER_CODE', $aggregator);
    self::assertStringContainsString('isUnreadFlag', $aggregator);

    self::assertStringContainsString('.w-backend-nav__signal-badge.is-clickable:not([hidden])', $css);
    self::assertStringContainsString('pointer-events: auto', $css);
    self::assertStringContainsString('.w-backend-nav__signal-panel', $css);
}

private function assert_BackendMenuPathMatch_testPathMatchRejectsSiblingFeatureLeavesAndKeepsActionAliases(): void
{
    $js = $this->read_BackendMenuPathMatch('view/statics/assets/js/app.js');
    self::assertStringContainsString('禁止 products↔offers', $js);
    self::assertStringContainsString('actionAliases', $js);
    self::assertStringContainsString('actionAliases[menuAction] && actionAliases[currentAction]', $js);
    self::assertStringContainsString('菜单常带 …/index，当前路由常省略 /index', $js);
    self::assertStringNotContainsString('同级页面匹配（路径长度相同，前N-1段匹配，只有最后一段不同）', $js);
}

private function assert_BackendMenuPathMatch_testActiveMenuMarkupIncludesDataState(): void
{
    $php = $this->read_BackendMenuPathMatch('Service/MenuRenderService.php');
    self::assertStringContainsString("aria-current=\"page\" data-state=\"active\"", $php);
    self::assertStringContainsString('stripLocalizationRouteSegments', $php);
}

private function assert_BackendMenuPathMatch_testActiveCssIsDistinctFromHover(): void
{
    foreach (['/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/weline-backend.css', '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/ui/css/backend.css'] as $cssPath) {
        self::assertFileExists($cssPath, $cssPath);
        $css = (string) file_get_contents($cssPath);
        self::assertStringContainsString('.w-backend-nav__item:hover {', $css);
        self::assertStringContainsString('.w-backend-nav__item[aria-current="page"]', $css);
        self::assertStringContainsString('var(--weline-theme-primary)', $css);
        self::assertStringNotContainsString('.w-backend-nav__item:hover, .w-backend-nav__item[aria-current="page"], .w-backend-nav__item[data-state="active"]', $css);
    }
}

private function assert_BackendMenuPathMatch_testThemeNavFilterPathMatchIsHyphenInsensitive(): void
{
    $jsPath = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/ui/js/weline-ui.js';
    self::assertFileExists($jsPath, $jsPath);
    $js = (string) file_get_contents($jsPath);
    self::assertStringContainsString("replace(/-/g, '')", $js);
    self::assertStringContainsString('control-center ↔ controlcenter', $js);
}

private function read_BackendMenuPathMatch(string $relative): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Admin' . '/' . ltrim($relative, '/');
    self::assertFileExists($path, $path);
    $content = file_get_contents($path);
    self::assertIsString($content);
    return $content;
}

private function assert_BackendTopbarStorefrontLink_testTopbarExposesStorefrontLinkInNewTabBeforeLanguageSwitcher(): void
{
    $topbar = $this->read_BackendTopbarStorefrontLink('app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
    $block = $this->read_BackendTopbarStorefrontLink('app/code/Weline/Admin/Block/Backend/Page/Topbar.php');
    self::assertStringContainsString('$storefrontUrl', $topbar);
    self::assertStringContainsString('target="_blank"', $topbar);
    self::assertStringContainsString('rel="noopener noreferrer"', $topbar);
    self::assertStringContainsString('访问前端', $topbar);
    self::assertStringContainsString('<w:icon name="globe"', $topbar);
    $switcherPos = (int) \strpos($topbar, '<w:i18n:switcher');
    $linkPos = (int) \strpos($topbar, '访问前端');
    self::assertGreaterThan(0, $switcherPos);
    self::assertGreaterThan(0, $linkPos);
    self::assertLessThan($switcherPos, $linkPos);
    self::assertStringContainsString('CurrentWebsiteStorefrontUrlProviderInterface', $block);
    self::assertStringContainsString("'storefront_url'", $block);
}

private function read_BackendTopbarStorefrontLink(string $path): string
{
    $content = \file_get_contents(BP . '/' . $path);
    self::assertIsString($content, $path . ' must be readable');
    return $content;
}

private function assert_BackendTopbarUniversalSearch_testTopbarHostsBackendUniversalSearchAndSidebarIsMenuOnly(): void
{
    $moduleRoot = '/Users/weline/Project/Official/框架/app/code/Weline/Admin';
    $topBar = $moduleRoot . '/view/blocks/backend/public/top-bar.phtml';
    $sidebar = $moduleRoot . '/view/templates/common/left-sidebar.phtml';
    $hook = $moduleRoot . '/hook.php';
    self::assertFileExists($topBar);
    self::assertFileExists($sidebar);
    self::assertFileExists($hook);
    $topBarSrc = (string) \file_get_contents($topBar);
    $sidebarSrc = (string) \file_get_contents($sidebar);
    $hookSrc = (string) \file_get_contents($hook);
    self::assertStringContainsString('w-backend-topbar__center', $topBarSrc);
    self::assertStringContainsString('area="backend"', $topBarSrc);
    self::assertStringContainsString('navigate-hits="true"', $topBarSrc);
    self::assertStringContainsString('w-backend-universal-search', $topBarSrc);
    self::assertStringContainsString('header-search.js', $topBarSrc);
    self::assertStringContainsString('搜索菜单…', $sidebarSrc);
    self::assertStringContainsString('没有匹配的菜单', $sidebarSrc);
    self::assertStringNotContainsString('nav-search-extras', $sidebarSrc);
    self::assertStringNotContainsString('搜索菜单或配置', $sidebarSrc);
    self::assertStringNotContainsString('nav-search-extras', $hookSrc);
}

private function assert_LoginFlashI18n_testAdminEnUsCoversLoginFlashAndMessageTitles(): void
{
    $csv = $this->loadCsv_LoginFlashI18n('/Users/weline/Project/Official/框架/app/code/Weline/Admin' . '/i18n/en_US.csv');
    $required = ['验证码错误！' => 'Incorrect verification code!', '请输入验证码！' => 'Please enter the verification code!', '错误！' => 'Error!', '提示！' => 'Prompt!', '警告！' => 'Warning!', '异常警告！' => 'Exception warning!', '操作成功！' => 'Operation successful!', '登录凭据错误！' => 'Invalid login credentials!', '人机验证失败或已过期，请重试' => null, '账户不存在！' => null, '账户被禁用！' => null];
    foreach ($required as $source => $exact) {
        self::assertArrayHasKey($source, $csv, "missing Admin en_US key: {$source}");
        self::assertNotSame($source, $csv[$source], "untranslated Admin en_US key: {$source}");
        if ($exact !== null) {
            self::assertSame($exact, $csv[$source], "unexpected translation for {$source}");
        }
    }
}

private function assert_LoginFlashI18n_testGateExposesTranslatableChineseSourceKeys(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Admin' . '/Service/BackendVerificationCodeGate.php');
    self::assertStringContainsString("'请输入验证码！'", $source);
    self::assertStringContainsString("'验证码错误！'", $source);
    $login = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Admin' . '/Controller/Login.php');
    self::assertStringContainsString('MessageManager::error(__($verificationCodeState[\'error_message\']))', $login);
}

/**
 * @return array<string, string>
 */
private function loadCsv_LoginFlashI18n(string $path): array
{
    self::assertFileExists($path);
    $handle = fopen($path, 'rb');
    self::assertNotFalse($handle);
    $map = [];
    try {
        while (($row = fgetcsv($handle)) !== false) {
            if (!isset($row[0], $row[1])) {
                continue;
            }
            $map[trim((string) $row[0])] = trim((string) $row[1]);
        }
    } finally {
        fclose($handle);
    }
    return $map;
}

private function assert_LoginStorefrontLink_testLoginToolbarUsesSameStorefrontLinkAsBackendTopbar(): void
{
    $login = $this->read_LoginStorefrontLink('app/code/Weline/Admin/view/templates/Login/index.phtml');
    $topbar = $this->read_LoginStorefrontLink('app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
    $controller = $this->read_LoginStorefrontLink('app/code/Weline/Admin/Controller/Login.php');
    self::assertStringContainsString('$storefrontUrl', $login);
    self::assertStringContainsString('w-login-card__toolbar-start', $login);
    self::assertStringContainsString('w-login-card__toolbar-end', $login);
    self::assertStringContainsString('target="_blank"', $login);
    self::assertStringContainsString('rel="noopener noreferrer"', $login);
    self::assertStringContainsString('访问前端', $login);
    self::assertStringContainsString('<w:icon name="globe"', $login);
    self::assertStringContainsString('w-backend-topbar__label', $login);
    self::assertStringContainsString('class="w-button"', $login);
    self::assertStringContainsString('data-tone="quiet"', $login);
    self::assertStringContainsString('data-size="sm"', $login);
    $topbarAnchor = $this->extractStorefrontButtonAnchor_LoginStorefrontLink($topbar);
    $loginAnchor = $this->extractStorefrontButtonAnchor_LoginStorefrontLink($login);
    self::assertSame($topbarAnchor, $loginAnchor);
    self::assertStringContainsString('CurrentWebsiteStorefrontUrlProviderInterface', $controller);
    self::assertStringContainsString("'storefront_url'", $controller);
}

private function assert_LoginSiteIdentity_testLoginPrefersCurrentWebsiteNameOverBackendSiteName(): void
{
    $controller = $this->read_LoginStorefrontLink('app/code/Weline/Admin/Controller/Login.php');
    $template = $this->read_LoginStorefrontLink('app/code/Weline/Admin/view/templates/Login/index.phtml');
    self::assertStringContainsString('resolveLoginSiteIdentity', $controller);
    self::assertStringContainsString('WebsiteAclGrantService', $controller);
    self::assertStringContainsString('currentWebsiteId', $controller);
    self::assertStringContainsString('getName()', $controller);
    self::assertStringContainsString('getDescription()', $controller);
    self::assertStringContainsString("'login_site_name'", $controller);
    self::assertStringContainsString('$siteIdentity[\'name\']', $controller);
    self::assertStringContainsString('$siteIdentity[\'description\']', $controller);
    // Must not assign login title solely from Backend site_name without Website-first resolution.
    self::assertDoesNotMatchRegularExpression(
        '/\$siteName\s*=\s*\(string\)\(\$backendConfigs\[\s*[\'"]site_name[\'"]\s*\]/',
        $controller
    );
    self::assertStringNotContainsString('长安汉服', $controller);
    self::assertStringNotContainsString('长安汉服', $template);
    self::assertStringContainsString('login_site_name', $template);
    self::assertStringContainsString("登录 %{name} 管理面板", $template);
}

private function extractStorefrontButtonAnchor_LoginStorefrontLink(string $source): string
{
    if (!\preg_match('/\<\?php if \(\$storefrontUrl !== \'\'\): \?\>\s*(<a[\s\S]*?<\/a>)/', $source, $matches)) {
        self::fail('Storefront button anchor markup not found');
        return '';
    }
    return \preg_replace('/\s+/', ' ', \trim($matches[1])) ?? '';
}

private function read_LoginStorefrontLink(string $path): string
{
    $content = \file_get_contents(BP . '/' . $path);
    self::assertIsString($content, $path . ' must be readable');
    return $content;
}

private function assert_ThemeColorMode_testAdminControlsUseTheNativeThreeStateThemeContract(): void
{
    $runtime = $this->read_ThemeColorMode('app/code/Weline/Theme/view/ui/js/weline-ui.js');
    self::assertStringContainsString("['system', 'light', 'dark'].includes(value)", $runtime);
    self::assertStringContainsString('root.dataset.themePreference = preference;', $runtime);
    self::assertStringContainsString('root.dataset.theme = theme;', $runtime);
    self::assertStringContainsString("media?.addEventListener('change', onSystemChange);", $runtime);
    self::assertStringContainsString("closest('[data-w-theme-preference]')", $runtime);
    self::assertStringNotContainsString('window.bootstrap', $runtime);
    self::assertStringNotContainsString('window.jQuery', $runtime);
    $settings = $this->read_ThemeColorMode('app/code/Weline/Admin/view/templates/common/right-sidebar.phtml');
    self::assertSame(3, substr_count($settings, 'data-w-theme-preference='));
    foreach (['system', 'light', 'dark'] as $preference) {
        self::assertStringContainsString('data-w-theme-preference="' . $preference . '"', $settings);
    }
    self::assertStringContainsString('data-w-component="drawer"', $settings);
    self::assertStringNotContainsString('data-bs-', $settings);
    $topbar = $this->read_ThemeColorMode('app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
    self::assertStringContainsString('data-w-component="menu"', $topbar);
    self::assertStringContainsString('data-w-menu-trigger', $topbar);
    self::assertStringContainsString('data-w-menu-panel', $topbar);
    self::assertSame(3, substr_count($topbar, 'data-w-theme-preference='));
    self::assertStringNotContainsString('data-bs-', $topbar);
    self::assertStringNotContainsString('dropdown-toggle', $topbar);
}

private function assert_ThemeColorMode_testBackendShellHasOneWelineUiAssetOwner(): void
{
    $adminHead = $this->read_ThemeColorMode('app/code/Weline/Admin/view/templates/common/head.phtml');
    $backendHeader = $this->read_ThemeColorMode('app/code/Weline/Backend/view/blocks/header/base.phtml');
    $themeHead = $this->read_ThemeColorMode('app/code/Weline/Theme/view/theme/backend/partials/head/default.phtml');
    $sources = $adminHead . "\n" . $backendHeader . "\n" . $themeHead;
    self::assertSame(1, substr_count($sources, 'Weline_Theme::ui/weline-foundation.css'));
    self::assertSame(1, substr_count($sources, 'Weline_Theme::ui/weline-backend.css'));
    self::assertSame(1, substr_count($sources, 'Weline_Theme::ui/weline-ui.js'));
    self::assertStringContainsString('type="module"', $themeHead);
    foreach (['jquery', 'bootstrap', 'backend-components.js', 'assets/js/theme.js', 'data-bs-'] as $forbidden) {
        self::assertStringNotContainsString($forbidden, strtolower($sources));
    }
}

private function read_ThemeColorMode(string $path): string
{
    $content = file_get_contents(BP . '/' . $path);
    self::assertIsString($content, $path . ' must be readable');
    return $content;
}
}
