<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class CaptchaConfigGoogleApiKeyShapeContractTest extends TestCase
{
    public function testConfigDeclaresSiteKeyShapedApiKeyDetector(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/CaptchaConfig.php';
        self::assertFileExists($path);
        $source = (string) \file_get_contents($path);
        self::assertStringContainsString('function googleApiKeyLooksLikeSiteKey', $source);
        self::assertStringContainsString("str_starts_with(\$apiKey, 'AIza')", $source);
        self::assertStringContainsString("str_starts_with(\$apiKey, '6L')", $source);
        self::assertStringContainsString('googleApiKeyLooksLikeSiteKey()', $source);
        self::assertStringContainsString('Always not-ready', $source);
        self::assertStringContainsString('return false;', $source);
        self::assertStringNotContainsString('DEV: still ready', $source);
    }

    public function testRouterKeepsSilentFallbackWithoutDebugProbeHooks(): void
    {
        $path = \dirname(__DIR__, 3) . '/Service/CaptchaProviderRouter.php';
        $source = (string) \file_get_contents($path);
        self::assertStringContainsString('return $this->firstReady([', $source);
        self::assertStringNotContainsString('debug-e4670c.log', $source);
        self::assertStringNotContainsString('google_skipped_not_ready', $source);
    }

    public function testGoogleProviderRejectsSiteKeyShapedApiKeyBeforeRequest(): void
    {
        $path = \dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        $source = (string) \file_get_contents($path);
        self::assertStringContainsString('googleApiKeyLooksLikeSiteKey', $source);
        self::assertStringContainsString('像 Site Key（6L…）', $source);
    }

    public function testBackendFieldDescriptionWarnsAgainstSiteKeyPaste(): void
    {
        $path = \dirname(__DIR__, 3) . '/extends/module/Weline_SystemConfig/Config/backend/captcha.phtml';
        $source = (string) \file_get_contents($path);
        self::assertStringContainsString('【密钥对照（必读）】', $source);
        self::assertStringContainsString('本模块不用', $source);
        self::assertStringContainsString('通常 AIza…', $source);
        self::assertStringContainsString('不使用 Secret Key', $source);
        self::assertStringContainsString('打开凭据页（创建 API 密钥）', $source);
        self::assertStringContainsString('apis/credentials?project=weline-framework', $source);
        self::assertStringContainsString('不要选 OAuth 客户端', $source);
        self::assertStringContainsString('Enterprise Site Key（即控制台 ID）', $source);
        self::assertStringContainsString('没有「Site Key」标签', $source);
        self::assertStringContainsString('复制「ID」字段', $source);
    }
}
