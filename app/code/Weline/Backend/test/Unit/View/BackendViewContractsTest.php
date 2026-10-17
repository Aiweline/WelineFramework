<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 8 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendViewContractsPass().
 */
final class BackendViewContractsTest extends TestCase
{
    public function testAllBackendViewContractsPass(): void
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
            'BackendApiBackendAclDeniedUi::testBackendApiSplitsAclDeniedUiByEnvironment' => function () {
            $this->assert_BackendApiBackendAclDeniedUi_testBackendApiSplitsAclDeniedUiByEnvironment();
        },
            'BackendApiCircuitBreaker::testWorkerMarksRedirectAsHttpRedirectWithLocation' => function () {
            $this->assert_BackendApiCircuitBreaker_testWorkerMarksRedirectAsHttpRedirectWithLocation();
        },
            'BackendApiCircuitBreaker::testClientCircuitAndHtmlFormGuard' => function () {
            $this->assert_BackendApiCircuitBreaker_testClientCircuitAndHtmlFormGuard();
        },
            'BackendApiCircuitBreaker::testDirectFetchRejectsHttpRedirectWithoutFollowing' => function () {
            $this->assert_BackendApiCircuitBreaker_testDirectFetchRejectsHttpRedirectWithoutFollowing();
        },
            'BackendBinQueryPathLocale::testQueryBinConfigResolvesLocaleFromPathNotCookie' => function () {
            $this->assert_BackendBinQueryPathLocale_testQueryBinConfigResolvesLocaleFromPathNotCookie();
        },
            'BackendBinQueryWorkerReuse::testDevCacheBustIsCachedAndWorkerIsNotTerminatedOnResend' => function () {
            $this->assert_BackendBinQueryWorkerReuse_testDevCacheBustIsCachedAndWorkerIsNotTerminatedOnResend();
        },
            'BackendBinQueryWorkerReuse::testBackendWarmupSkipsAfterOneTimeProofConsumed' => function () {
            $this->assert_BackendBinQueryWorkerReuse_testBackendWarmupSkipsAfterOneTimeProofConsumed();
        },
            'BackendBinQueryWorkerReuse::testBackendRuntimeAndQueryBinCarryThemePublishedVersionId' => function () {
            $this->assert_BackendBinQueryWorkerReuse_testBackendRuntimeAndQueryBinCarryThemePublishedVersionId();
        },
            'BackendSidebarCollapsePersist::testBackendShellUsesNativeWelineDrawerContract' => function () {
            $this->assert_BackendSidebarCollapsePersist_testBackendShellUsesNativeWelineDrawerContract();
        },
            'BackendSidebarCollapsePersist::testBackendSidebarCollapseUsesShellComponentAndPersistedToggle' => function () {
            $this->assert_BackendSidebarCollapsePersist_testBackendSidebarCollapseUsesShellComponentAndPersistedToggle();
        },
            'NotificationCenterMarkAllRead::testIndexTemplateWiresMarkAllReadActionAndScript' => function () {
            $this->assert_NotificationCenterMarkAllRead_testIndexTemplateWiresMarkAllReadActionAndScript();
        },
            'NotificationCenterMarkAllRead::testDetailTemplateWiresMarkTopicReadActionAndScript' => function () {
            $this->assert_NotificationCenterMarkAllRead_testDetailTemplateWiresMarkTopicReadActionAndScript();
        },
            'SettingsTemplatePath::testPlaceholderSettingsControllersAndTemplatesAreRemoved' => function () {
            $this->assert_SettingsTemplatePath_testPlaceholderSettingsControllersAndTemplatesAreRemoved();
        },
            'SettingsTemplatePath::testRootControllerTemplatesLiveBesideControllerPath' => function () {
            $this->assert_SettingsTemplatePath_testRootControllerTemplatesLiveBesideControllerPath();
        },
            'ThemeColorMode::testBackendPersistsOnlySupportedPreferencesAndKeepsLegacyCompatibility' => function () {
            $this->assert_ThemeColorMode_testBackendPersistsOnlySupportedPreferencesAndKeepsLegacyCompatibility();
        },
            'ThemeColorMode::testResolvedSystemAttributeIsRejected' => function () {
            $this->assert_ThemeColorMode_testResolvedSystemAttributeIsRejected();
        },
        ];
    }

private function assert_BackendApiBackendAclDeniedUi_testBackendApiSplitsAclDeniedUiByEnvironment(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js');
    self::assertStringContainsString("backend_acl_denied", $source);
    self::assertStringContainsString('FrontendWorkerBackendAuthorizationException', $source);
    self::assertStringContainsString('isBackendAclDeniedError', $source);
    self::assertStringContainsString('notifyBackendAclDeniedUi', $source);
    self::assertStringContainsString('showBackendAclDeniedUi', $source);
    self::assertStringContainsString('dialog.alert', $source);
    self::assertStringContainsString('isDevMode()', $source);
    self::assertStringContainsString('toast.error', $source);
    self::assertStringContainsString('notifyBackendAclDeniedUi(error)', $source);
    self::assertStringContainsString('notifyBackendAclDeniedUi(businessError)', $source);
    self::assertMatchesRegularExpression('/if\s*\(\s*isDevMode\(\)\s*\)\s*\{[\s\S]*?dialog\.alert/', $source);
}

private function assert_BackendApiCircuitBreaker_testWorkerMarksRedirectAsHttpRedirectWithLocation(): void
{
    $worker = (string) \file_get_contents(BP . 'app/code/Weline/Backend/view/statics/js/weline-api-worker.js');
    self::assertStringContainsString("redirectError.code = 'http_redirect'", $worker);
    self::assertStringContainsString('redirectError.location = location', $worker);
    self::assertStringContainsString('body.code = error.code', $worker);
    self::assertStringContainsString('body.location = error.location', $worker);
}

private function assert_BackendApiCircuitBreaker_testClientCircuitAndHtmlFormGuard(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js');
    self::assertStringContainsString("code: 'http_redirect'", $source);
    self::assertStringContainsString('acquireRequestCircuit', $source);
    self::assertStringContainsString('releaseRequestCircuit', $source);
    self::assertStringContainsString('REQUEST_CIRCUIT_COOLDOWN_MS', $source);
    self::assertStringContainsString('isBackendHtmlFormUrl', $source);
    self::assertStringContainsString('allowHtmlForm', $source);
    self::assertStringContainsString('html_form_refused', $source);
    self::assertStringContainsString("X-Weline-Api", $source);
    self::assertStringContainsString('ensureApiJsonHeaders', $source);
    self::assertStringContainsString('websites\/admin\/website\/(edit|add)', $source);
}

private function assert_BackendApiCircuitBreaker_testDirectFetchRejectsHttpRedirectWithoutFollowing(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js');
    self::assertStringContainsString("status === 301 || status === 302 || status === 303 || status === 307 || status === 308", $source);
    self::assertStringContainsString("code: 'http_redirect'", $source);
    self::assertStringContainsString("redirect: requestOptions.redirect || 'manual'", $source);
}

private function assert_BackendBinQueryPathLocale_testQueryBinConfigResolvesLocaleFromPathNotCookie(): void
{
    $source = (string) \file_get_contents(\BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js');
    self::assertStringContainsString('Path/query language: never read WELINE_USER_LANG cookie', $source);
    self::assertStringContainsString('detectPathLanguage', $source);
    self::assertStringContainsString('readQueryLanguage', $source);
    self::assertStringContainsString('detectPathCurrency', $source);
    self::assertStringContainsString('readQueryCurrency', $source);
    self::assertStringContainsString('resolveCurrentLanguage', $source);
    self::assertStringContainsString('pathname: config.pathname', $source);
    self::assertDoesNotMatchRegularExpression('/buildQueryBinConfig[\s\S]{0,800}?document\.cookie/', $source);
}

private function assert_BackendBinQueryWorkerReuse_testDevCacheBustIsCachedAndWorkerIsNotTerminatedOnResend(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js');
    self::assertStringContainsString('cachedDevWorkerUrls', $source);
    self::assertStringContainsString('Reuse the live worker for the whole page', $source);
    self::assertStringContainsString('if (this.worker) {', $source);
    self::assertStringNotContainsString('if (this.worker && this.workerUrl === config.workerUrl)', $source);
    self::assertDoesNotMatchRegularExpression('/BackendQueryBinClient\.prototype\.ensureWorker[\s\S]{0,400}?this\.worker\.terminate\(/', $source);
}

private function assert_BackendBinQueryWorkerReuse_testBackendWarmupSkipsAfterOneTimeProofConsumed(): void
{
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js');
    self::assertMatchesRegularExpression('/BackendQueryBinClient\.prototype\.warmup\s*=\s*function[\s\S]*?' . 'if\s*\(this\.backendWarmupComplete\)\s*\{\s*' . 'return Promise\.resolve\(null\);/', $source);
    self::assertMatchesRegularExpression('/BackendQueryBinClient\.prototype\.warmup\s*=\s*function[\s\S]*?' . '\.finally\(function\s*\(\)\s*\{\s*' . 'if\s*\(!this\.backendWarmupComplete\)\s*\{\s*' . 'this\.backendWarmupPromise\s*=\s*null;/', $source);
}

private function assert_BackendBinQueryWorkerReuse_testBackendRuntimeAndQueryBinCarryThemePublishedVersionId(): void
{
    $header = (string) \file_get_contents(BP . 'app/code/Weline/Backend/view/blocks/header/base.phtml');
    $source = (string) \file_get_contents(BP . 'app/code/Weline/Backend/view/statics/js/weline-api.js');
    self::assertStringContainsString('themePublishedVersionId', $header);
    self::assertStringContainsString("'themePublishedVersionId' => \$themePublishedVersionId", $header);
    self::assertStringContainsString("'api' => \$apiConfig", $header);
    self::assertStringContainsString('themePublishedVersionId:', $source);
    self::assertStringContainsString('themePublishedVersionId: config.themePublishedVersionId || \'\'', $source);
}

private function assert_BackendSidebarCollapsePersist_testBackendShellUsesNativeWelineDrawerContract(): void
{
    $sidebar = \file_get_contents(BP . '/app/code/Weline/Admin/view/templates/common/left-sidebar.phtml');
    self::assertIsString($sidebar);
    self::assertStringContainsString('id="w-backend-sidebar"', $sidebar);
    self::assertStringContainsString('data-w-component="drawer"', $sidebar);
    self::assertStringContainsString('data-w-keep-mounted="true"', $sidebar);
    self::assertStringNotContainsString('data-simplebar', $sidebar);
    self::assertStringNotContainsString('metismenu', $sidebar);
    $topBar = \file_get_contents(BP . '/app/code/Weline/Admin/view/blocks/backend/public/top-bar.phtml');
    self::assertIsString($topBar);
    self::assertStringContainsString('data-w-action="drawer.open"', $topBar);
    self::assertStringContainsString('data-w-target="#w-backend-sidebar"', $topBar);
    self::assertStringNotContainsString('data-bs-', $topBar);
    self::assertStringNotContainsString('<script>', $topBar);
}

private function assert_BackendSidebarCollapsePersist_testBackendSidebarCollapseUsesShellComponentAndPersistedToggle(): void
{
    $layout = \file_get_contents(BP . '/app/code/Weline/Theme/view/theme/backend/layouts/default/default.phtml');
    self::assertIsString($layout);
    self::assertStringContainsString('data-w-component="backend-sidebar-collapse"', $layout);
    $sidebar = \file_get_contents(BP . '/app/code/Weline/Admin/view/templates/common/left-sidebar.phtml');
    self::assertIsString($sidebar);
    self::assertStringContainsString('w-backend-sidebar-collapse', $sidebar);
    self::assertStringContainsString('data-w-action="backend-sidebar-collapse.toggle"', $sidebar);
    self::assertStringContainsString('data-w-label-expanded', $sidebar);
    self::assertStringContainsString('data-w-label-collapsed', $sidebar);
    $runtime = \file_get_contents(BP . '/app/code/Weline/Theme/view/ui/js/weline-ui.js');
    self::assertIsString($runtime);
    self::assertStringContainsString("define('backend-sidebar-collapse'", $runtime);
    self::assertStringContainsString('weline_backend_sidebar_collapsed', $runtime);
    self::assertStringContainsString('backend-sidebar-collapse.toggle', $runtime);
    $prepaint = \file_get_contents(BP . '/app/code/Weline/Theme/view/ui/js/theme-prepaint.js');
    self::assertIsString($prepaint);
    self::assertStringContainsString('weline_backend_sidebar_collapsed', $prepaint);
    self::assertStringContainsString('backendSidebarCollapsed', $prepaint);
    $backendCss = \file_get_contents(BP . '/app/code/Weline/Theme/view/ui/css/backend.css');
    self::assertIsString($backendCss);
    self::assertStringContainsString('--backend-theme-sidebar-collapsed-width', $backendCss);
    self::assertStringContainsString('[data-sidebar-collapsed="true"]', $backendCss);
    self::assertStringNotContainsString('.w-backend-nav__search:hover', $backendCss);
    self::assertStringNotContainsString('[data-sidebar-overlay="true"]', $backendCss);
    self::assertStringNotContainsString('.w-backend-nav__entry:hover', $backendCss);
    self::assertStringContainsString('expandSidebarForSearch', $runtime);
    self::assertStringContainsString('expandSidebarOverlay', $runtime);
    self::assertStringContainsString('expandTopLevelEntry', $runtime);
    self::assertStringContainsString('expandOverlay', $runtime);
    self::assertStringContainsString('dismissOverlay', $runtime);
    self::assertStringContainsString('overlayActive', $runtime);
    self::assertStringContainsString('const expand = () =>', $runtime);
    self::assertStringContainsString('const collapse = () =>', $runtime);
    self::assertStringContainsString("listen(document, 'pointerdown'", $runtime);
    self::assertStringContainsString('!overlayActive', $runtime);
    self::assertStringContainsString("details.w-backend-nav__disclosure[open]", $runtime);
    self::assertStringContainsString('Open means open', $runtime);
    // Outside click must not auto-collapse a permanently expanded sidebar.
    self::assertDoesNotMatchRegularExpression("/listen\\(document, 'pointerdown'[\\s\\S]*?collapse\\(\\);/", $runtime);
    $menuService = \file_get_contents(BP . '/app/code/Weline/Admin/Service/MenuRenderService.php');
    self::assertIsString($menuService);
    self::assertStringContainsString('resolveMenuIconName', $menuService);
    self::assertStringContainsString('LegacyIconNameMap', $menuService);
    self::assertStringContainsString('w-backend-nav__entry--group', $menuService);
}

private function backendRoot_NotificationCenterMarkAllRead(): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/Backend';
}

private function assert_NotificationCenterMarkAllRead_testIndexTemplateWiresMarkAllReadActionAndScript(): void
{
    $template = (string) file_get_contents($this->backendRoot_NotificationCenterMarkAllRead() . '/view/templates/backend/Notification/index.phtml');
    $script = (string) file_get_contents($this->backendRoot_NotificationCenterMarkAllRead() . '/view/statics/js/notification-center.js');
    self::assertStringContainsString('data-w-component="notification-center"', $template);
    self::assertStringContainsString('data-w-notification-action="mark-all-read"', $template);
    self::assertStringContainsString('data-w-mark-all-url=', $template);
    self::assertStringContainsString("system/backend/notification/markAllRead", $template);
    self::assertStringContainsString('Weline_Backend::js/notification-center.js', $template);
    self::assertStringContainsString("mark-all-read", $script);
    self::assertStringContainsString('data-w-mark-all-url', $script);
    // 全部已读必须走 binquery（Weline.adminRequest → query-bin），不能直接
    // window.fetch：会被 ApiModule 的 monkey-patch 路由进 Weline.Api，
    // 其按 METHOD+pathname 的 MAX_IN_FLIGHT=1 闸门会让快速连点报
    // "too many in-flight requests for POST ..."。这是历史 bug 锁定的回归断言。
    self::assertStringNotContainsString('fetch(', $script);
    self::assertStringContainsString("adminRequest('backend_admin'", $script);
    self::assertStringContainsString('POST', $script);
}

private function assert_NotificationCenterMarkAllRead_testDetailTemplateWiresMarkTopicReadActionAndScript(): void
{
    $template = (string) file_get_contents($this->backendRoot_NotificationCenterMarkAllRead() . '/view/templates/backend/Notification/detail.phtml');
    $script = (string) file_get_contents($this->backendRoot_NotificationCenterMarkAllRead() . '/view/statics/js/notification-center.js');
    self::assertStringContainsString('data-w-component="notification-center"', $template);
    self::assertStringContainsString('data-w-notification-action="mark-topic-read"', $template);
    self::assertStringContainsString('data-w-mark-topic-url=', $template);
    self::assertStringContainsString('Weline_Backend::js/notification-center.js', $template);
    self::assertStringContainsString('mark-topic-read', $script);
    self::assertStringContainsString('topic_code', $script);
    self::assertStringContainsString('ArrowLeft', $script);
    self::assertStringContainsString('ArrowRight', $script);
}

private function assert_SettingsTemplatePath_testPlaceholderSettingsControllersAndTemplatesAreRemoved(): void
{
    $root = BP . 'app/code/Weline/Backend/';
    foreach (['Basic', 'Email', 'Storage'] as $name) {
        self::assertFileDoesNotExist($root . 'Controller/Settings/' . $name . '.php');
        self::assertFileDoesNotExist($root . 'view/templates/Settings/' . $name . '/index.phtml');
        self::assertFileDoesNotExist($root . 'view/templates/backend/Settings/' . $name . '/index.phtml');
    }
    self::assertDirectoryDoesNotExist($root . 'Controller/Settings');
    self::assertDirectoryDoesNotExist($root . 'view/templates/Settings');
    self::assertDirectoryDoesNotExist($root . 'view/templates/backend/Settings');
}

/** @return list<string> */
private function expectedRelativeTemplates_SettingsTemplatePath(): array
{
    return ['Maintenance/index.phtml', 'Backup/index.phtml', 'Monitor/index.phtml', 'Statistics/index.phtml', 'AccessLog/index.phtml'];
}

private function assert_SettingsTemplatePath_testRootControllerTemplatesLiveBesideControllerPath(): void
{
    $base = BP . 'app/code/Weline/Backend/view/templates/';
    foreach (self::expectedRelativeTemplates_SettingsTemplatePath() as $relative) {
        $path = $base . $relative;
        self::assertFileExists($path, 'Missing template for default fetch(): templates/' . \dirname($relative) . '/index');
        $legacy = $base . 'Backend/' . $relative;
        self::assertFileDoesNotExist($legacy, 'Stale Backend/ prefix would not match Controller root path: ' . $legacy);
    }
}

private function assert_ThemeColorMode_testBackendPersistsOnlySupportedPreferencesAndKeepsLegacyCompatibility(): void
{
    self::assertSame('system', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [[]]));
    self::assertSame('system', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => 'system']]));
    self::assertSame('light', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => 'light']]));
    self::assertSame('dark', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => 'dark']]));
    self::assertSame('light', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['dark-mode-switch' => 'false']]));
    self::assertSame('dark', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['dark-mode-switch' => 'true']]));
    self::assertSame('dark', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['light-mode-switch' => 'false']]));
    self::assertSame('light', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => 'unsupported']]));
    self::assertSame('light', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['theme-mode-switch' => false]]));
    self::assertSame('system', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['layouts' => ['data-theme-preference' => 'system']]]));
    self::assertSame('dark', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['layouts' => ['data-theme-preference' => 'dark']]]));
    self::assertSame('system', $this->invoke_ThemeColorMode(\Weline\Backend\Block\ThemeConfig::class, 'resolveThemeModeFromConfig', [['dark-mode-switch' => true, 'layouts' => ['data-theme-preference' => 'system', 'data-theme-mode' => 'dark']]]));
    $systemPayload = $this->invoke_ThemeColorMode(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['theme-mode-switch' => 'system', 'layouts' => ['data-theme-mode' => 'dark', 'data-layout-mode' => 'dark', 'data-topbar' => 'dark', 'data-sidebar' => 'dark']]]);
    self::assertSame('system', $systemPayload['theme-mode-switch']);
    foreach (['data-theme-mode', 'data-layout-mode', 'data-topbar', 'data-sidebar'] as $resolvedAttribute) {
        self::assertArrayNotHasKey($resolvedAttribute, $systemPayload['layouts']);
    }
    self::assertSame('light', $this->invoke_ThemeColorMode(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['dark-mode-switch' => 'false']])['theme-mode-switch']);
    self::assertSame('dark', $this->invoke_ThemeColorMode(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['dark-mode-switch' => 'true']])['theme-mode-switch']);
    self::assertSame('system', $this->invoke_ThemeColorMode(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['dark-mode-switch' => true, 'layouts' => ['data-theme-preference' => 'system', 'data-theme-mode' => 'dark']]])['theme-mode-switch']);
    self::assertSame('dark', $this->invoke_ThemeColorMode(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['layouts' => ['data-theme-preference' => 'dark']]])['theme-mode-switch']);
    $themeConfigSource = $this->read_ThemeColorMode('app/code/Weline/Backend/Block/ThemeConfig.php');
    self::assertStringContainsString('public function getThemeHtmlAttributes(): string;', $this->read_ThemeColorMode('app/code/Weline/Backend/Api/View/BackendThemeConfigInterface.php'));
    self::assertStringContainsString("'data-theme=\"' . \$resolvedMode", $themeConfigSource);
    self::assertLessThan(strpos($themeConfigSource, "if (!empty(\$themeConfig['rtl-mode-switch']))"), strpos($themeConfigSource, "if (\$themeMode === 'dark')"));
    $this->expectException(\InvalidArgumentException::class);
    $this->invoke_ThemeColorMode(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['theme-mode-switch' => 'invalid']]);
}

private function assert_ThemeColorMode_testResolvedSystemAttributeIsRejected(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->invoke_ThemeColorMode(\Weline\Backend\Controller\ThemeConfig\Set::class, 'normalizeThemePayload', [['layouts' => ['data-theme-mode' => 'system']]]);
}

/** @return iterable<string, array{0:array<string,mixed>}> */
private function invalidDirectThemeConfigValues_ThemeColorMode(): iterable
{
    yield 'empty preference' => [['layouts' => ['data-theme-preference' => '']]];
    yield 'null preference' => [['layouts' => ['data-theme-preference' => null]]];
    yield 'whitespace resolved mode' => [['layouts' => ['data-theme-mode' => ' ']]];
    yield 'empty layout mode' => [['layouts' => ['data-layout-mode' => '']]];
    yield 'layouts null' => [['layouts' => null]];
    yield 'layouts empty string' => [['layouts' => '']];
    yield 'layouts scalar' => [['layouts' => 'dark']];
}

/** @return iterable<string, array{0:array<string,mixed>}> */
private function invalidExplicitThemeValues_ThemeColorMode(): iterable
{
    yield 'switch null' => [['theme-mode-switch' => null]];
    yield 'switch empty' => [['theme-mode-switch' => '']];
    yield 'switch whitespace' => [['theme-mode-switch' => '   ']];
    yield 'switch array' => [['theme-mode-switch' => ['dark']]];
    yield 'layouts null' => [['layouts' => null]];
    yield 'layouts empty string' => [['layouts' => '']];
    yield 'layouts scalar' => [['layouts' => 'dark']];
    yield 'preference array' => [['layouts' => ['data-theme-preference' => ['system']]]];
    yield 'resolved system' => [['layouts' => ['data-theme-mode' => 'system']]];
    yield 'resolved invalid' => [['layouts' => ['data-layout-mode' => 'purple']]];
    yield 'resolved object' => [['layouts' => ['data-layout-mode' => new \stdClass()]]];
}

private function invoke_ThemeColorMode(string $class, string $method, array $arguments): mixed
{
    $reflection = new \ReflectionClass($class);
    $instance = $reflection->newInstanceWithoutConstructor();
    $target = $reflection->getMethod($method);
    $target->setAccessible(true);
    return $target->invokeArgs($instance, $arguments);
}

private function read_ThemeColorMode(string $path): string
{
    $content = file_get_contents(BP . '/' . $path);
    self::assertIsString($content, $path . ' must be readable');
    return $content;
}
}
