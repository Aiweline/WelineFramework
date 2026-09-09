<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AccountFormProviderConfigFieldsContractTest extends TestCase
{
    public function testProvidersDeclareOwnFieldsAndFormRendersDynamically(): void
    {
        $root = dirname(__DIR__, 3);
        $template = $root . '/view/templates/Backend/Account/form.phtml';
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
        $googleSrc = (string) file_get_contents($root . '/Service/Adapter/GoogleIndexingApiAdapter.php');

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

        self::assertStringContainsString('先在 Google Search Console 验证', $templateSrc);
        self::assertStringContainsString('Google Search Console 配置顺序', $templateSrc);
        self::assertStringContainsString('与 GSC 属性字符串完全一致', $templateSrc);

        $gscAdapterSrc = (string) file_get_contents($root . '/Service/Adapter/GoogleSearchConsoleAdapter.php');
        self::assertStringContainsString('须与 GSC 左侧属性名完全一致', $gscAdapterSrc);
        self::assertStringContainsString('加成所有者后，再粘贴到此处', $gscAdapterSrc);
        self::assertStringContainsString('属性 URL 不一致', $adapterSrc);
        self::assertStringContainsString('enable_discover_stats', $gscAdapterSrc);
        self::assertStringContainsString('youtube_channel_url', $gscAdapterSrc);
        self::assertStringContainsString("'type' => 'section'", $gscAdapterSrc);
        self::assertStringContainsString('seo-config-section', $templateSrc);
        self::assertStringContainsString('Platform Property', $templateSrc);
    }
}
