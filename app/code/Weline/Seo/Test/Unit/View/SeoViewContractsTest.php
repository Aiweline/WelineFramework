<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 13 *ContractTest files).
 * Observe one overall pass/fail via testAllSeoViewContractsPass().
 */
final class SeoViewContractsTest extends TestCase
{
    public function testAllSeoViewContractsPass(): void
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
            'AccountFormAutoSubmitDefaults::testNewAccountFormDefaultsCronSwitchesOn' => function () {
            $this->assert_AccountFormAutoSubmitDefaults_testNewAccountFormDefaultsCronSwitchesOn();
        },
            'AccountFormPlatformNameFormat::testFormRequiresPlatformBeforePrefixedAccountName' => function () {
            $this->assert_AccountFormPlatformNameFormat_testFormRequiresPlatformBeforePrefixedAccountName();
        },
            'AccountFormProviderConfigFields::testProvidersDeclareOwnFieldsAndFormRendersDynamically' => function () {
            $this->assert_AccountFormProviderConfigFields_testProvidersDeclareOwnFieldsAndFormRendersDynamically();
        },
            'AccountFormWebsiteBindingSelect::testAccountFormExposesMultiWebsiteSelectBinding' => function () {
            $this->assert_AccountFormWebsiteBindingSelect_testAccountFormExposesMultiWebsiteSelectBinding();
        },
            'AccountIndexTheme::testAccountIndexUsesNativeThemeSurfacesWithoutHardcodedLightPalette' => function () {
            $this->assert_AccountIndexTheme_testAccountIndexUsesNativeThemeSurfacesWithoutHardcodedLightPalette();
        },
            'DashboardOverviewTheme::testDashboardOverviewUsesSeoAdminEmptyGuide' => function () {
            $this->assert_DashboardOverviewTheme_testDashboardOverviewUsesSeoAdminEmptyGuide();
        },
            'DuplicatePanelTheme::testDuplicateIndexUsesSeoFormSectionsAndEmptyState' => function () {
            $this->assert_DuplicatePanelTheme_testDuplicateIndexUsesSeoFormSectionsAndEmptyState();
        },
            'DuplicatePanelTheme::testDuplicateReportUsesMetaAndGradeChips' => function () {
            $this->assert_DuplicatePanelTheme_testDuplicateReportUsesMetaAndGradeChips();
        },
            'InspectorProductGroupJsonLd::testPanelRulesAcceptProductGroupAndOfferOrVariant' => function () {
            $this->assert_InspectorProductGroupJsonLd_testPanelRulesAcceptProductGroupAndOfferOrVariant();
        },
            'SeoFooterDeveloperGate::testFooterHookGatesOnDeveloperAccessPolicy' => function () {
            $this->assert_SeoFooterDeveloperGate_testFooterHookGatesOnDeveloperAccessPolicy();
        },
            'SeoFooterLayoutCoverage::testThemeFrontendLayoutsAllHangSeoFooter' => function () {
            $this->assert_SeoFooterLayoutCoverage_testThemeFrontendLayoutsAllHangSeoFooter();
        },
            'SeoFooterLayoutCoverage::testModuleStorefrontLayoutsHangSeoFooter' => function () {
            $this->assert_SeoFooterLayoutCoverage_testModuleStorefrontLayoutsHangSeoFooter();
        },
            'SeoFooterLayoutCoverage::testAccountInteriorLayoutsDoNotHangSeoFooter' => function () {
            $this->assert_SeoFooterLayoutCoverage_testAccountInteriorLayoutsDoNotHangSeoFooter();
        },
            'SeoHeadHookSingleSource::testDefaultSeoHeadHookDoesNotEmitCoreSeoTaglib' => function () {
            $this->assert_SeoHeadHookSingleSource_testDefaultSeoHeadHookDoesNotEmitCoreSeoTaglib();
        },
            'SeoHeadHookSingleSource::testFrontendPublicHeadRemainsSingleSeoSource' => function () {
            $this->assert_SeoHeadHookSingleSource_testFrontendPublicHeadRemainsSingleSeoSource();
        },
            'SeoInspectorPanelGate::testInspectorLoadsOnlyAfterPanelSeoTab' => function () {
            $this->assert_SeoInspectorPanelGate_testInspectorLoadsOnlyAfterPanelSeoTab();
        },
            'WebsiteAccountEnablementTheme::testWidgetUsesNativeThemeTokensWithoutInlineHardcodedPalette' => function () {
            $this->assert_WebsiteAccountEnablementTheme_testWidgetUsesNativeThemeTokensWithoutInlineHardcodedPalette();
        },
        ];
    }

private function assert_AccountFormAutoSubmitDefaults_testNewAccountFormDefaultsCronSwitchesOn(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/backend/Account/form.phtml';
    $model = $root . '/Model/SeoAccount.php';
    $adminService = $root . '/Service/Admin/SeoAdminAccountService.php';
    self::assertFileExists($template);
    self::assertFileExists($model);
    self::assertFileExists($adminService);
    $templateSrc = (string) file_get_contents($template);
    $modelSrc = (string) file_get_contents($model);
    $serviceSrc = (string) file_get_contents($adminService);
    self::assertStringContainsString('ENABLE_CRON_SITEMAP) : 1;', $templateSrc, '新建账户表单应默认勾选 Sitemap 定时提交');
    self::assertStringContainsString('ENABLE_CRON_PUSH_URLS) : 1;', $templateSrc, '新建账户表单应默认勾选 URL 定时推送');
    self::assertStringContainsString('name="enable_cron_sitemap" value="0"', $templateSrc, '未勾选时需 hidden=0 才能显式关闭');
    self::assertStringContainsString('wasUnsupported', $templateSrc, '从不支持切回可支持时应恢复默认勾选');
    self::assertMatchesRegularExpression('/default:\s*1,\s*comment:\s*\'是否启用Sitemap定时提交\'/', $modelSrc);
    self::assertStringContainsString("array_key_exists('enable_cron_sitemap', \$params)", $serviceSrc);
    self::assertStringContainsString("? !empty(\$params['enable_cron_sitemap']) : 1)", $serviceSrc);
}

private function assert_AccountFormPlatformNameFormat_testFormRequiresPlatformBeforePrefixedAccountName(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/backend/Account/form.phtml';
    $controller = $root . '/Controller/Backend/Account.php';
    self::assertFileExists($template);
    self::assertFileExists($controller);
    $templateSrc = (string) file_get_contents($template);
    $controllerSrc = (string) file_get_contents($controller);
    $platformPos = strpos($templateSrc, 'class="seo-platform-grid"');
    $namePos = strpos($templateSrc, 'id="seoAccountNameField"');
    self::assertNotFalse($platformPos);
    self::assertNotFalse($namePos);
    self::assertGreaterThan($platformPos, $namePos);
    self::assertStringContainsString('function syncAccountNameFormat', $templateSrc);
    self::assertStringContainsString('function enforceAccountNamePrefix', $templateSrc);
    self::assertStringContainsString('function appendSafeGuideStep', $templateSrc);
    self::assertStringContainsString('appendSafeGuideStep(item, step)', $templateSrc);
    self::assertStringNotContainsString('item.textContent = step;', $templateSrc);
    self::assertStringContainsString('强制要求填写用户名的格式是：', $templateSrc);
    self::assertStringContainsString('data-name-prefix', $templateSrc);
    self::assertStringContainsString("账户名称必须符合格式：%{1}{账户名}", $controllerSrc);
    self::assertStringContainsString('$expectedPrefix', $controllerSrc);
    self::assertStringContainsString('str_starts_with($name, $expectedPrefix)', $controllerSrc);
}

private function assert_AccountFormProviderConfigFields_testProvidersDeclareOwnFieldsAndFormRendersDynamically(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/backend/Account/form.phtml';
    $capability = $root . '/Service/SeoPlatformCapabilityService.php';
    $interface = $root . '/Interface/SearchEngineAdapterInterface.php';
    self::assertFileExists($template);
    self::assertFileExists($capability);
    self::assertFileExists($interface);
    $templateSrc = (string) file_get_contents($template);
    $capabilitySrc = (string) file_get_contents($capability);
    $interfaceSrc = (string) file_get_contents($interface);
    self::assertStringContainsString('function getAccountConfigFields(): array', $interfaceSrc);
    self::assertStringContainsString('resolveAccountConfigFields', $capabilitySrc);
    self::assertStringContainsString('config_fields', $capabilitySrc);
    self::assertStringContainsString('function renderProviderConfigFields', $templateSrc);
    self::assertStringContainsString('seoProviderConfigFields', $templateSrc);
    self::assertStringContainsString('该平台无需填写 API 凭据', $templateSrc);
    self::assertStringNotContainsString('账户配置(JSON)', $templateSrc);
    self::assertStringNotContainsString('fillTemplateBtn', $templateSrc);
    self::assertStringNotContainsString('直接上传从 Google Cloud 下载的 JSON 密钥文件', $templateSrc);
    $baiduSrc = (string) file_get_contents($root . '/Service/Adapter/BaiduSearchEngineAdapter.php');
    $googleSrc = (string) file_get_contents($root . '/Service/Adapter/GoogleSearchConsoleAdapter.php');
    self::assertStringContainsString("'key' => 'token'", $baiduSrc);
    self::assertStringContainsString("'key' => 'site'", $baiduSrc);
    self::assertStringContainsString("'type' => 'website_url'", $baiduSrc);
    self::assertStringNotContainsString("'key' => 'service_account'", $baiduSrc);
    self::assertStringContainsString("'key' => 'service_account'", $googleSrc);
    self::assertStringContainsString('w:websites:website:select', $templateSrc);
    self::assertStringContainsString('seo_account_website_pick', $templateSrc);
    self::assertStringContainsString('handleSeoAccountWebsiteSelect', $templateSrc);
    self::assertStringContainsString('website_url', $capabilitySrc);
    self::assertStringContainsString('搜索站点名称或域名', $templateSrc);
    self::assertStringContainsString('seo-field-preview', $templateSrc);
    self::assertStringContainsString('seo-field-preview__code', $templateSrc);
    self::assertStringNotContainsString('data-tone="success"', $templateSrc);
    self::assertStringNotContainsString('background: #f8f9fa', $templateSrc);
    self::assertStringContainsString('Weline_Seo::css/seo-admin.css', $templateSrc);
    $jsSrc = (string) file_get_contents($root . '/view/statics/js/seo-admin.js');
    self::assertStringContainsString("setAttribute('aria-busy', 'true')", $jsSrc);
    self::assertStringContainsString('data-seo-account-feedback', $jsSrc);
    self::assertStringContainsString('showAccountFeedback', $jsSrc);
    self::assertStringContainsString('keepBusinessResult', $jsSrc);
    self::assertStringContainsString('dialogApi.request', $jsSrc);
    self::assertStringNotContainsString('spinner-border', $jsSrc);
    self::assertStringContainsString('data-seo-account-feedback', $templateSrc);
    self::assertStringContainsString('data-seo-account-verify', $templateSrc);
    self::assertStringContainsString('data-loading-label', $templateSrc);
    self::assertStringContainsString('data-verifying', $templateSrc);
    self::assertStringContainsString('data-seo-capability-switch', $templateSrc);
    self::assertStringContainsString('seoCronPushHint', $templateSrc);
    self::assertStringContainsString('当前平台不支持 URL 定时推送', $templateSrc);
    self::assertStringContainsString('function syncSubmitToggles', $templateSrc);
    self::assertStringContainsString('seoPlatformOutboundHint', $templateSrc);
    self::assertStringContainsString('outbound_hint', $templateSrc);
    self::assertStringContainsString('oauth2.googleapis.com', $templateSrc);
    self::assertStringContainsString('data-open-gsc', $templateSrc);
    self::assertStringContainsString('data-verify-failed-title', $templateSrc);
    $adapterSrc = (string) file_get_contents($root . '/Adapter/GoogleSitemapAdapter.php');
    self::assertStringContainsString('buildSearchConsoleVerifyFailureMessage', $adapterSrc);
    self::assertStringContainsString('gsc_site_not_found', $adapterSrc);
    self::assertStringContainsString('search.google.com/search-console', $adapterSrc);
    self::assertStringContainsString('helpUrl', $jsSrc);
    self::assertStringContainsString('openGsc', $jsSrc);
    self::assertStringContainsString('dialogApi.request', $jsSrc);
    self::assertStringContainsString('先在 GSC 验证属性', $templateSrc);
    self::assertStringContainsString('Google Search Console 配置顺序', $templateSrc);
    self::assertStringContainsString('与 GSC 属性字符串完全一致', $templateSrc);
    self::assertStringContainsString('绑定站点」不能代替本步', $templateSrc);
    self::assertStringContainsString('这不是 Search Console 属性', $templateSrc);
    self::assertStringContainsString('Bing 配置顺序（先 IndexNow 推送', $templateSrc);
    self::assertStringContainsString('使用 IndexNow 推送页面 URL', $templateSrc);
    self::assertStringContainsString('IndexNow 中枢配置顺序', $templateSrc);
    self::assertStringContainsString('Yandex IndexNow 配置顺序', $templateSrc);
    self::assertStringContainsString('yandex.com/indexnow', $templateSrc);
    self::assertStringContainsString('api.indexnow.org', $templateSrc);
    self::assertStringContainsString('attack_guard', $templateSrc);
    self::assertStringContainsString('outbound_hint', $templateSrc);
    $bingAdapterSrc = (string) file_get_contents($root . '/Service/Adapter/BingSearchEngineAdapter.php');
    $indexNowAdapterSrc = (string) file_get_contents($root . '/Service/Adapter/IndexNowSearchEngineAdapter.php');
    self::assertStringContainsString("'key' => '__section_indexnow'", $bingAdapterSrc);
    self::assertStringContainsString('IndexNow URL 推送（推荐）', $bingAdapterSrc);
    self::assertStringContainsString("'key' => '__section_key'", $indexNowAdapterSrc);
    self::assertStringContainsString('openssl rand -hex 16', $indexNowAdapterSrc);
    $capabilitySpecializeSrc = (string) file_get_contents($root . '/Service/SeoPlatformCapabilityService.php');
    self::assertStringContainsString('specializeIndexNowFieldsForPlatform', $capabilitySpecializeSrc);
    self::assertStringContainsString('yandex.com/indexnow', $capabilitySpecializeSrc);
    $gscAdapterSrc = (string) file_get_contents($root . '/Service/Adapter/GoogleSearchConsoleAdapter.php');
    self::assertStringContainsString('须与 GSC 左侧属性名完全一致', $gscAdapterSrc);
    self::assertStringContainsString('加成「所有者」，再粘贴到此处', $gscAdapterSrc);
    self::assertStringContainsString('要把服务账号加进这条属性', $gscAdapterSrc);
    self::assertStringContainsString('下方会根据 JSON 解析出的服务账号邮箱', $gscAdapterSrc);
    self::assertStringContainsString('本处填写的 GSC 属性', $adapterSrc);
    self::assertStringContainsString('不要改成它', $adapterSrc);
    self::assertStringContainsString('ACCOUNT_SERVICE_CLIENT_EMAIL', $templateSrc);
    self::assertStringContainsString('updateGscOwnerActionHint', $templateSrc);
    self::assertStringContainsString('data-seo-gsc-owner-action', $templateSrc);
    self::assertStringContainsString('resolveServiceAccountClientEmail', $templateSrc);
    self::assertStringContainsString("'key' => 'service_account'", $gscAdapterSrc);
    $platformCodeSrc = (string) file_get_contents($root . '/Service/SeoPlatformCode.php');
    self::assertStringContainsString('google_search_console', $platformCodeSrc);
    self::assertStringContainsString("GOOGLE = 'google'", $platformCodeSrc);
    self::assertStringContainsString('enable_discover_stats', $gscAdapterSrc);
    self::assertStringContainsString('youtube_channel_url', $gscAdapterSrc);
    self::assertStringContainsString("'type' => 'section'", $gscAdapterSrc);
    self::assertStringContainsString('seo-config-section', $templateSrc);
    self::assertStringContainsString('Platform Property', $templateSrc);
}

private function assert_AccountFormWebsiteBindingSelect_testAccountFormExposesMultiWebsiteSelectBinding(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/backend/Account/form.phtml';
    $js = $root . '/view/statics/js/seo-admin.js';
    $service = $root . '/Service/Admin/SeoAdminAccountService.php';
    $provider = $root . '/extends/module/Weline_Framework/Query/SeoAdminQueryProvider.php';
    self::assertFileExists($template);
    self::assertFileExists($js);
    self::assertFileExists($service);
    self::assertFileExists($provider);
    $templateSrc = (string) file_get_contents($template);
    $jsSrc = (string) file_get_contents($js);
    $serviceSrc = (string) file_get_contents($service);
    $providerSrc = (string) file_get_contents($provider);
    self::assertStringContainsString('data-seo-account-website-bindings', $templateSrc);
    self::assertStringContainsString('seo_account_bound_websites', $templateSrc);
    self::assertStringContainsString('w:websites:website:select', $templateSrc);
    self::assertStringContainsString('multiple="true"', $templateSrc);
    self::assertStringContainsString('boundWebsiteIdsValue', $templateSrc);
    self::assertStringContainsString('readAccountBoundWebsiteIds', $jsSrc);
    self::assertStringContainsString('payload.website_ids', $jsSrc);
    self::assertStringContainsString('seo_account_bound_websites', $jsSrc);
    self::assertStringContainsString("array_key_exists('website_ids', \$params)", $serviceSrc);
    self::assertStringContainsString('saveAccountWebsiteBindings', $serviceSrc);
    self::assertStringContainsString("'website_ids' => ['type' => 'list'", $providerSrc);
}

private function assert_AccountIndexTheme_testAccountIndexUsesNativeThemeSurfacesWithoutHardcodedLightPalette(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/backend/Account/index.phtml';
    $css = $root . '/view/statics/css/seo-admin.css';
    self::assertFileExists($template);
    self::assertFileExists($css);
    $templateSrc = (string) file_get_contents($template);
    $cssSrc = (string) file_get_contents($css);
    self::assertStringContainsString('<css>Weline_Seo::css/seo-admin.css</css>', $templateSrc);
    self::assertStringContainsString('w-stat-tiles seo-account-stats', $templateSrc);
    self::assertStringContainsString('w-card seo-account-panel', $templateSrc);
    self::assertStringContainsString('w-empty seo-account-empty', $templateSrc);
    self::assertStringContainsString('w-badge', $templateSrc);
    self::assertStringContainsString("'icon' => 'users'", $templateSrc);
    self::assertStringContainsString('<w:icon name="user"', $templateSrc);
    self::assertStringContainsString('data-seo-account-search', $templateSrc);
    self::assertStringContainsString('data-testid="seo-account-add"', $templateSrc);
    self::assertStringContainsString('data-testid="seo-account-website-filter"', $templateSrc);
    self::assertStringContainsString('w:websites:website:select', $templateSrc);
    self::assertStringContainsString('data-testid="seo-account-site-binding"', $templateSrc);
    self::assertStringContainsString('data-seo-delete-account', $templateSrc);
    self::assertStringContainsString('data-account-confirm-delete', $templateSrc);
    self::assertStringNotContainsString('class="mdi', $templateSrc);
    self::assertStringNotContainsString('seo-empty-state', $templateSrc);
    self::assertStringNotContainsString('<style>', $templateSrc);
    self::assertStringNotContainsString('background:#fff', $templateSrc);
    self::assertStringContainsString('--weline-theme-surface', $cssSrc);
    self::assertStringContainsString('--weline-theme-text', $cssSrc);
    self::assertStringContainsString('.seo-account-page', $cssSrc);
    self::assertStringContainsString('--seo-primary: var(--weline-theme-primary', $cssSrc);
    self::assertStringNotContainsString('--seo-primary: #1e40af;', $cssSrc);
}

private function assert_DashboardOverviewTheme_testDashboardOverviewUsesSeoAdminEmptyGuide(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/backend/Dashboard/index.phtml';
    $css = $root . '/view/statics/css/seo-admin.css';
    $proto = $root . '/view/statics/prototype/seo-dashboard-overview-ui.html';
    self::assertFileExists($template);
    self::assertFileExists($css);
    self::assertFileExists($proto);
    $src = (string) file_get_contents($template);
    $cssSrc = (string) file_get_contents($css);
    $protoSrc = (string) file_get_contents($proto);
    self::assertStringContainsString('data-testid="seo-dashboard-management"', $src);
    self::assertStringContainsString('data-seo-dash-ui-variant="A"', $src);
    self::assertStringContainsString('seo-admin__header', $src);
    self::assertStringContainsString('w-stat-tiles seo-dashboard-stats', $src);
    self::assertStringContainsString('data-testid="seo-dashboard-empty"', $src);
    self::assertStringContainsString('seo-dashboard-next', $src);
    self::assertStringContainsString('推荐下一步', $src);
    self::assertStringContainsString('seo/backend/embed/index', $src);
    self::assertStringNotContainsString('stat-card', $src);
    self::assertStringNotContainsString('class="empty-state"', $src);
    self::assertStringContainsString('.seo-dashboard-next__grid', $cssSrc);
    self::assertStringContainsString('--weline-theme-surface', $cssSrc);
    self::assertStringContainsString('?variant=A|B|C', $protoSrc);
    self::assertStringContainsString("verdict:'A'", $protoSrc);
}

private function assert_DuplicatePanelTheme_testDuplicateIndexUsesSeoFormSectionsAndEmptyState(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/backend/Duplicate/index.phtml';
    $css = $root . '/view/statics/css/seo-admin.css';
    $proto = $root . '/view/statics/prototype/duplicate-panel-ui.html';
    self::assertFileExists($template);
    self::assertFileExists($css);
    self::assertFileExists($proto);
    $src = (string) file_get_contents($template);
    $cssSrc = (string) file_get_contents($css);
    $protoSrc = (string) file_get_contents($proto);
    self::assertStringContainsString('data-testid="seo-duplicate-panel"', $src);
    self::assertStringContainsString('data-seo-dup-ui-variant="A"', $src);
    self::assertStringContainsString('seo-form-section', $src);
    self::assertStringContainsString('seo-form-grid', $src);
    self::assertStringContainsString('seo-form-actions', $src);
    self::assertStringContainsString('seo-switch-card', $src);
    self::assertStringContainsString('class="w-field"', $src);
    self::assertStringContainsString('class="w-input"', $src);
    self::assertStringContainsString('class="w-select"', $src);
    self::assertStringContainsString('seo-empty-state', $src);
    self::assertStringContainsString('data-testid="seo-dup-runs-empty"', $src);
    self::assertStringNotContainsString('class="w-form-row"', $src);
    self::assertStringNotContainsString('<style>', $src);
    self::assertStringContainsString('.seo-dup-meta', $cssSrc);
    self::assertStringContainsString('--weline-theme-primary', $cssSrc);
    self::assertStringContainsString('?variant=A|B|C', $protoSrc);
    self::assertStringContainsString('verdict_default: \'A\'', $protoSrc);
}

private function assert_DuplicatePanelTheme_testDuplicateReportUsesMetaAndGradeChips(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/backend/Duplicate/report.phtml';
    $src = (string) file_get_contents($template);
    self::assertStringContainsString('data-testid="seo-duplicate-report"', $src);
    self::assertStringContainsString('seo-form-section', $src);
    self::assertStringContainsString('seo-dup-meta', $src);
    self::assertStringContainsString('seo-dup-grade-filter', $src);
    self::assertStringContainsString('report_path_all', $src);
    self::assertStringContainsString('panel_path', $src);
    self::assertStringNotContainsString('href="/seo/backend/duplicate"', $src);
    self::assertStringNotContainsString('href="/seo/backend/duplicate/report?run_id=', $src);
    self::assertStringNotContainsString('<style>', $src);
}

private function assert_InspectorProductGroupJsonLd_testPanelRulesAcceptProductGroupAndOfferOrVariant(): void
{
    $inspector = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/view/statics/seo-inspector/inspector.js');
    self::assertStringContainsString('Product: ["Product", "ProductGroup"]', $inspector);
    self::assertStringContainsString('requiredFields: ["name", "image", "offers|hasVariant"]', $inspector);
    self::assertStringContainsString('"offers.price|offers.lowPrice"', $inspector);
    self::assertStringContainsString('if (node.review) pushNode(node.review)', $inspector);
    self::assertStringContainsString('已对接评论评分', $inspector);
    self::assertStringContainsString('optional: ["Review"]', $inspector);
    self::assertStringContainsString('toolUrlPageKey', $inspector);
    self::assertStringContainsString('currentToolUrlPageKey', $inspector);
    self::assertStringContainsString('weline-seo-panel__url-mismatch', $inspector);
    self::assertStringContainsString('检测 URL 与地址栏当前页不一致', $inspector);
    self::assertStringNotContainsString("requiredTypes: [\"Product\", \"BreadcrumbList\"]", $inspector);
}

private function assert_SeoFooterDeveloperGate_testFooterHookGatesOnDeveloperAccessPolicy(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/view/hooks/seo/footer.phtml';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('DeveloperAccessPolicy', $src);
    self::assertStringContainsString('shouldInjectBootstrap', $src);
    self::assertStringContainsString("return;\n", $src);
    self::assertStringContainsString('<w:seo slot="footer"/>', $src);
    // Gate must run before <w:seo> — public traffic never enters SlotProvider.
    $gate = strpos($src, 'shouldInjectBootstrap');
    $seo = strpos($src, '<w:seo slot="footer"/>');
    self::assertNotFalse($gate);
    self::assertNotFalse($seo);
    self::assertGreaterThan($gate, $seo);
}

private function assert_SeoFooterLayoutCoverage_testThemeFrontendLayoutsAllHangSeoFooter(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/layouts';
    $this->assertDirectoryExists($root);
    $missing = $this->layoutsMissingSeoFooter_SeoFooterLayoutCoverage($root);
    self::assertSame([], $missing, "Theme layouts missing seo::footer:\n" . implode("\n", $missing));
}

private function assert_SeoFooterLayoutCoverage_testModuleStorefrontLayoutsHangSeoFooter(): void
{
    $welineRoot = '/Users/weline/Project/Official/框架/app/code/Weline';
    $candidates = [$welineRoot . '/Product/view/theme/frontend/layouts', $welineRoot . '/Customer/view/theme/frontend/layouts'];
    $missing = [];
    foreach ($candidates as $root) {
        if (!is_dir($root)) {
            continue;
        }
        foreach ($this->layoutsMissingSeoFooter_SeoFooterLayoutCoverage($root) as $rel) {
            $missing[] = $rel;
        }
    }
    self::assertSame([], $missing, "Module layouts missing seo::footer:\n" . implode("\n", $missing));
}

private function assert_SeoFooterLayoutCoverage_testAccountInteriorLayoutsDoNotHangSeoFooter(): void
{
    $customerRoot = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Customer/view/theme/frontend/layouts';
    $interior = [$customerRoot . '/account/default.phtml', $customerRoot . '/account/dashboard.phtml', $customerRoot . '/account/challenge.phtml', $customerRoot . '/account/logout/default.phtml', $customerRoot . '/account/orders/default.phtml', $customerRoot . '/account/profile/default.phtml'];
    foreach ($interior as $path) {
        self::assertFileExists($path);
        self::assertStringNotContainsString('seo::footer', (string) file_get_contents($path), $path . ' should not register SEO panel inside account center');
    }
    $authShell = $customerRoot . '/account/auth.phtml';
    self::assertFileExists($authShell);
    self::assertStringContainsString('seo::footer', (string) file_get_contents($authShell), $authShell . ' login/register shell may keep SEO panel');
}

/**
 * @return list<string>
 */
private function layoutsMissingSeoFooter_SeoFooterLayoutCoverage(string $root): array
{
    $missing = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'phtml') {
            continue;
        }
        $path = $file->getPathname();
        // Mini-cart drawer preview is not a crawlable storefront page shell.
        if (str_contains($path, '/mini-cart/')) {
            continue;
        }
        // Logged-in account center interiors do not need SEO panel bootstrap.
        // Keep login/register shells (account/auth).
        if ($this->isAccountInteriorLayout_SeoFooterLayoutCoverage($path)) {
            continue;
        }
        $src = (string) file_get_contents($path);
        if (!preg_match('/<\/body>/i', $src)) {
            continue;
        }
        if (!str_contains($src, 'seo::footer')) {
            $missing[] = $path;
        }
    }
    sort($missing);
    return $missing;
}

private function isAccountInteriorLayout_SeoFooterLayoutCoverage(string $path): bool
{
    $normalized = str_replace('\\', '/', $path);
    if (str_contains($normalized, '/layouts/account/auth.phtml') || str_contains($normalized, '/layouts/account/login/') || str_contains($normalized, '/layouts/account/register/') || str_contains($normalized, '/layouts/account/forgot-password/') || str_contains($normalized, '/layouts/account/set-password/') || str_contains($normalized, '/layouts/account/social-login/')) {
        return false;
    }
    return (bool) preg_match('#/layouts/account(/|$)#', $normalized);
}

private function assert_SeoHeadHookSingleSource_testDefaultSeoHeadHookDoesNotEmitCoreSeoTaglib(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/view/hooks/seo/head.phtml';
    self::assertFileExists($path);
    $contents = (string) file_get_contents($path);
    $executable = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;
    $executable = preg_replace('#^\s*//.*$#m', '', $executable) ?? $executable;
    self::assertStringNotContainsString('<w:seo', $executable, 'seo::head must stay an extension point; core SEO is rendered by Frontend public/head.phtml');
    self::assertStringContainsString('public/head.phtml', $contents);
}

private function assert_SeoHeadHookSingleSource_testFrontendPublicHeadRemainsSingleSeoSource(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Frontend/view/templates/public/head.phtml';
    self::assertFileExists($path);
    $contents = (string) file_get_contents($path);
    self::assertSame(1, substr_count($contents, '<w:seo slot="head"/>'), 'Frontend public head must keep exactly one core SEO head slot');
}

private function assert_SeoInspectorPanelGate_testInspectorLoadsOnlyAfterPanelSeoTab(): void
{
    $renderer = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/Head/HeadRenderer.php');
    $inspector = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/view/statics/seo-inspector/inspector.js');
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/DeveloperWorkspace/extends/module/Weline_Framework/Query/DeveloperWorkspaceAdminQueryProvider.php');
    self::assertStringContainsString('ensureInspectorScript', $renderer);
    self::assertStringContainsString('data-weline-panel-seo-bootstrap', $renderer);
    self::assertStringContainsString('startLiveCwvObservers', $inspector);
    self::assertStringContainsString('data-weline-seo-copy-html', $inspector);
    self::assertStringContainsString('seo/gsc/inspect', $inspector);
    self::assertStringContainsString("requiredTypes: [\"BreadcrumbList\", \"Organization\", \"WebSite\"]", $inspector);
    self::assertStringContainsString("requiredTypes: [\"AboutPage\", \"BreadcrumbList\"]", $inspector);
    self::assertStringContainsString('if (/\/about(?:\/|$)/.test(path)) return "about";', $inspector);
    self::assertStringContainsString('if (/\/policy(?:\/|$)/.test(path)) return "legal";', $inspector);
    self::assertStringContainsString('policy: "legal"', $inspector);
    self::assertStringContainsString('accessibility: "legal"', $inspector);
    self::assertStringContainsString('function aliasSeoType', $inspector);
    self::assertStringContainsString('function auditAccessibilityStatementCompleteness', $inspector);
    self::assertStringContainsString('function buildAccessibilityCompletenessReport', $inspector);
    self::assertStringContainsString('function renderAccessibilityTab', $inspector);
    self::assertStringContainsString('data-weline-tab="a11y"', $inspector);
    self::assertStringContainsString('data-weline-a11y-completeness', $inspector);
    self::assertStringContainsString('__WELINE_PANEL_SEO_A11Y_REPORT__', $inspector);
    self::assertStringContainsString('无障碍声明完整度', $inspector);
    self::assertStringContainsString('cookie: "legal"', $inspector);
    self::assertStringContainsString('shipping: "legal"', $inspector);
    self::assertStringContainsString('refund: "legal"', $inspector);
    self::assertStringContainsString('disclaimer: "legal"', $inspector);
    self::assertStringContainsString('term_condition: "legal"', $inspector);
    self::assertStringContainsString('privacy: "legal"', $inspector);
    self::assertStringContainsString('terms: "legal"', $inspector);
    self::assertStringContainsString('about: { required: ["AboutPage", "Organization", "WebSite", "BreadcrumbList"]', $inspector);
    self::assertStringContainsString('if (/\/blog$/.test(path)) return "collection";', $inspector);
    self::assertStringContainsString('explicitNorm === "blog_list" || explicitNorm === "blog_category"', $inspector);
    self::assertStringContainsString('never invent "… +N" placeholders', $inspector);
    self::assertStringContainsString('urlTemplate 必须含字面量 {search_term_string}', $inspector);
    self::assertStringContainsString('博客列表 CollectionPage + Article/BlogPosting ItemList', $inspector);
    self::assertStringNotContainsString('.concat(["… +"', $inspector);
    self::assertStringNotContainsString('if (/\/blog(?:\/|$)/.test(path) || /\/post(?:\/|$)/.test(path)) return "blog";', $inspector);
    self::assertStringContainsString('pushRichFact(facts, "路径"', $inspector);
    self::assertStringContainsString('/#breadcrumb\b/i', $inspector);
    self::assertStringContainsString('ListItem.item 须为绝对 URL', $inspector);
    self::assertStringContainsString('不要给 BreadcrumbList 加 @id', $inspector);
    self::assertStringContainsString('Google Carousel/ItemList 不支持电商 Product', $inspector);
    self::assertStringContainsString('Google defaults to index,follow', $inspector);
    self::assertStringContainsString('large third-party JavaScript', $inspector);
    self::assertStringContainsString('SEARCH_ENGINE_RULE_CATALOG', $inspector);
    self::assertStringContainsString('function buildEngineRows', $inspector);
    self::assertStringContainsString('SITEMAP_DISCOVERY_ROBOTS_TXT', $inspector);
    self::assertStringContainsString('ensureSitemapProbe', $inspector);
    self::assertStringContainsString('关注点（非失败项）', $inspector);
    self::assertStringContainsString('large CSS resources (DEV)', $inspector);
    self::assertStringContainsString('说明·不扣分', $inspector);
    self::assertStringContainsString('scoringExempt', $inspector);
    self::assertStringContainsString("data-w-component='drawer'", $inspector);
    self::assertStringContainsString('product-native-detail__description-body', $inspector);
    self::assertStringContainsString('项 · 通过 ', $inspector);
    self::assertStringContainsString('EEAT_STRICT_RULES', $inspector);
    self::assertStringContainsString('function auditEeatStrict', $inspector);
    self::assertStringContainsString('function buildEeatStrictReport', $inspector);
    self::assertStringContainsString('function renderEeatStrictSection', $inspector);
    self::assertStringContainsString('Google Helpful Content 自测', $inspector);
    self::assertStringContainsString('who_person_url_or_sameas', $inspector);
    self::assertStringContainsString('who_visible_byline', $inspector);
    self::assertStringContainsString('why_primary_audience', $inspector);
    self::assertStringContainsString('"Why: audience & intent (manual)"', $inspector);
    self::assertStringContainsString('与上方「对照 Google 官方示例」', $inspector);
    self::assertStringContainsString('Why（意图）', $inspector);
    self::assertStringContainsString('eeat-pass-summary', $inspector);
    self::assertStringContainsString('eeatFindRule("why_primary_audience")', $inspector);
    self::assertStringContainsString('if (!whyMainOk)', $inspector);
    self::assertStringNotContainsString('机检代理已通过（主内容服务读者）', $inspector);
    self::assertStringContainsString('eeat_org_sameas', $inspector);
    self::assertStringContainsString('eeat_article_author_shallow', $inspector);
    self::assertStringContainsString('Content substance (not Experience)', $inspector);
    self::assertStringContainsString('{ id: "eeat", title:', $inspector);
    self::assertStringNotContainsString("'</strong><span>Passed</span></div>'", $inspector);
    self::assertStringNotContainsString('favicon svg', $inspector);
    self::assertStringNotContainsString('sizes="32x32"', $inspector);
    self::assertStringNotContainsString('YAHOO_001_TITLE_NOT_ACCURATE', $inspector);
    self::assertStringNotContainsString('DDG_003_ENTITY_SOURCE_WEAK', $inspector);
    self::assertStringNotContainsString('EQ_004_PRIVACY_PAGE_MISSING', $inspector);
    self::assertStringNotContainsString('YANDEX_004_BREADCRUMB_JSONLD_INVALID', $inspector);
    self::assertStringNotContainsString("'@id' => \$url . '#breadcrumb'", $renderer);
    self::assertStringContainsString("'seo/gsc/inspect'", $provider);
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline' . '/DeveloperWorkspace/Api/Rest/V1/Seo/Gsc.php');
    self::assertFileExists('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Service/PanelGoogleUrlInspectionService.php');
    self::assertStringContainsString('function inspectUrl', (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Seo' . '/Adapter/GoogleSitemapAdapter.php'));
}

private function assert_WebsiteAccountEnablementTheme_testWidgetUsesNativeThemeTokensWithoutInlineHardcodedPalette(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Seo';
    $template = $root . '/view/templates/Widget/WebsiteAccountEnablement.phtml';
    $css = $root . '/view/statics/css/seo-admin.css';
    $hook = $root . '/view/hooks/Weline_Websites/backend/website/form/sections-after.phtml';
    self::assertFileExists($template);
    self::assertFileExists($css);
    self::assertFileExists($hook);
    $templateSrc = (string) file_get_contents($template);
    $cssSrc = (string) file_get_contents($css);
    $hookSrc = (string) file_get_contents($hook);
    self::assertStringContainsString('<css>Weline_Seo::css/seo-admin.css</css>', $templateSrc);
    self::assertStringContainsString('w-card seo-widget-shell', $templateSrc);
    self::assertStringContainsString('w-empty seo-empty', $templateSrc);
    self::assertStringContainsString('w-badge seo-chip', $templateSrc);
    // Fiber capture: do not wrap shared body with split <w:form>…endif…</w:form>
    self::assertStringNotContainsString('<w:form', $templateSrc);
    self::assertStringContainsString('<form class="seo-widget-form"', $templateSrc);
    self::assertStringNotContainsString('</w:form>', $templateSrc);
    self::assertStringNotContainsString('<style>', $templateSrc);
    self::assertStringNotContainsString('ms-2', $templateSrc);
    self::assertStringNotContainsString('--seo-widget-bg: #fff', $templateSrc);
    self::assertStringContainsString('.seo-website-account-widget', $cssSrc);
    self::assertStringContainsString('--weline-theme-surface', $cssSrc);
    self::assertStringContainsString('--weline-theme-border', $cssSrc);
    self::assertStringContainsString('--weline-theme-surface-muted', $cssSrc);
    self::assertStringNotContainsString('w-disclosure__trigger collapsed', $hookSrc);
    self::assertStringContainsString('SEO 协议与搜索提交', $hookSrc);
}
}
