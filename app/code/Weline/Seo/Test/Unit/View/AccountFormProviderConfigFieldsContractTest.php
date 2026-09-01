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
    }
}
