<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Service\CaptchaConfig;
use Weline\Captcha\Service\ClientGeoResolver;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllCaptchaServiceContractsPass().
 */
final class CaptchaServiceContractsTest extends TestCase
{
    public function testAllCaptchaServiceContractsPass(): void
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
            'CaptchaConfigConstructor::testConfigReaderDependencyRemainsResolvableByObjectManager' => function () {
            $this->assert_CaptchaConfigConstructor_testConfigReaderDependencyRemainsResolvableByObjectManager();
        },
            'CaptchaConfigGoogleApiKeyShape::testConfigDeclaresSiteKeyShapedApiKeyDetector' => function () {
            $this->assert_CaptchaConfigGoogleApiKeyShape_testConfigDeclaresSiteKeyShapedApiKeyDetector();
        },
            'CaptchaConfigGoogleApiKeyShape::testRouterKeepsSilentFallbackWithoutDebugProbeHooks' => function () {
            $this->assert_CaptchaConfigGoogleApiKeyShape_testRouterKeepsSilentFallbackWithoutDebugProbeHooks();
        },
            'CaptchaConfigGoogleApiKeyShape::testGoogleProviderRejectsSiteKeyShapedApiKeyBeforeRequest' => function () {
            $this->assert_CaptchaConfigGoogleApiKeyShape_testGoogleProviderRejectsSiteKeyShapedApiKeyBeforeRequest();
        },
            'CaptchaConfigGoogleApiKeyShape::testBackendFieldDescriptionWarnsAgainstSiteKeyPaste' => function () {
            $this->assert_CaptchaConfigGoogleApiKeyShape_testBackendFieldDescriptionWarnsAgainstSiteKeyPaste();
        },
            'CaptchaProviderRouter::testGeoNormalizeCountryCode' => function () {
            $this->assert_CaptchaProviderRouter_testGeoNormalizeCountryCode();
        },
            'CaptchaProviderRouter::testRouterAndConfigSourcesExist' => function () {
            $this->assert_CaptchaProviderRouter_testRouterAndConfigSourcesExist();
        },
            'LazyCaptchaClientRuntimeHostMarkup::testLazyRuntimeExposesHostMarkupHelper' => function () {
            $this->assert_LazyCaptchaClientRuntimeHostMarkup_testLazyRuntimeExposesHostMarkupHelper();
        },
            'LazyCaptchaClientRuntimeHostMarkup::testInjectCaptchaIntoFormUsesHostMarkup' => function () {
            $this->assert_LazyCaptchaClientRuntimeHostMarkup_testInjectCaptchaIntoFormUsesHostMarkup();
        },
            'LazyCaptchaClientRuntimeHostMarkup::testGoogleRenderGuardsEmptySiteKey' => function () {
            $this->assert_LazyCaptchaClientRuntimeHostMarkup_testGoogleRenderGuardsEmptySiteKey();
        },
            'LazyCaptchaClientRuntimeHostMarkup::testLazyRuntimeResolvesViaFetchTagSourceWithoutDevFallback' => function () {
            $this->assert_LazyCaptchaClientRuntimeHostMarkup_testLazyRuntimeResolvesViaFetchTagSourceWithoutDevFallback();
        },
        ];
    }

private function assert_CaptchaConfigConstructor_testConfigReaderDependencyRemainsResolvableByObjectManager(): void
{
    $constructor = new \ReflectionMethod(CaptchaConfig::class, '__construct');
    $parameters = $constructor->getParameters();
    self::assertCount(1, $parameters);
    $type = $parameters[0]->getType();
    self::assertInstanceOf(\ReflectionNamedType::class, $type);
    self::assertSame(ConfigReader::class, $type->getName());
}

private function assert_CaptchaConfigGoogleApiKeyShape_testConfigDeclaresSiteKeyShapedApiKeyDetector(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Service/CaptchaConfig.php';
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

private function assert_CaptchaConfigGoogleApiKeyShape_testRouterKeepsSilentFallbackWithoutDebugProbeHooks(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Service/CaptchaProviderRouter.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('return $this->firstReady([', $source);
    self::assertStringNotContainsString('debug-e4670c.log', $source);
    self::assertStringNotContainsString('google_skipped_not_ready', $source);
}

private function assert_CaptchaConfigGoogleApiKeyShape_testGoogleProviderRejectsSiteKeyShapedApiKeyBeforeRequest(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Provider/GoogleRecaptchaEnterprise.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('googleApiKeyLooksLikeSiteKey', $source);
    self::assertStringContainsString('像 Site Key（6L…）', $source);
}

private function assert_CaptchaConfigGoogleApiKeyShape_testBackendFieldDescriptionWarnsAgainstSiteKeyPaste(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/extends/module/Weline_SystemConfig/Config/backend/captcha.phtml';
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

private function assert_CaptchaProviderRouter_testGeoNormalizeCountryCode(): void
{
    $resolver = (new \ReflectionClass(ClientGeoResolver::class))->newInstanceWithoutConstructor();
    self::assertSame('CN', $resolver->normalizeCountryCode('cn'));
    self::assertSame('US', $resolver->normalizeCountryCode(' US '));
    self::assertSame(ClientGeoResolver::UNKNOWN, $resolver->normalizeCountryCode('T1'));
    self::assertSame(ClientGeoResolver::UNKNOWN, $resolver->normalizeCountryCode(''));
    self::assertSame(ClientGeoResolver::UNKNOWN, $resolver->normalizeCountryCode('USA'));
}

private function assert_CaptchaProviderRouter_testRouterAndConfigSourcesExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha';
    self::assertFileExists($root . '/Service/CaptchaProviderRouter.php');
    self::assertFileExists($root . '/Service/ClientGeoResolver.php');
    self::assertFileExists($root . '/Provider/TencentCaptcha.php');
    self::assertFileExists($root . '/extends/module/Weline_SystemConfig/Config/backend/captcha.phtml');
    $router = (string) \file_get_contents($root . '/Service/CaptchaProviderRouter.php');
    $configUi = (string) \file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/backend/captcha.phtml');
    $captchaConfig = (string) \file_get_contents($root . '/Service/CaptchaConfig.php');
    $manager = (string) \file_get_contents($root . '/Service/CaptchaManager.php');
    $lazy = (string) \file_get_contents($root . '/view/statics/js/captcha-lazy.js');
    self::assertStringContainsString('tencent_captcha', $router);
    self::assertStringContainsString('countryOverrides', $captchaConfig);
    self::assertStringContainsString('ConfigReader', $captchaConfig);
    self::assertStringContainsString('private readonly ConfigReader $config', $captchaConfig);
    self::assertStringContainsString('captcha/routing/country_overrides', $configUi);
    self::assertStringContainsString('captcha/tencent/app_id', $configUi);
    self::assertStringContainsString('allowLocalDegrade', $manager);
    self::assertStringContainsString('weline:captcha:degrade', $lazy);
    self::assertStringContainsString('prefer', $lazy);
    self::assertStringContainsString('isInsideClosedDetails', $lazy);
    self::assertStringContainsString('FETCH_TIMEOUT_MS', $lazy);
    self::assertStringContainsString('inflightByUrl', $lazy);
}

private function assert_LazyCaptchaClientRuntimeHostMarkup_testLazyRuntimeExposesHostMarkupHelper(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Service/LazyCaptchaClientRuntime.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('function hostMarkup(', $source);
    self::assertStringContainsString('data-weline-captcha-lazy="1"', $source);
    self::assertStringContainsString('data-challenge-route="weline_captcha/frontend/challenge"', $source);
    self::assertStringContainsString('onceScriptHtml()', $source);
}

private function assert_LazyCaptchaClientRuntimeHostMarkup_testInjectCaptchaIntoFormUsesHostMarkup(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Observer/InjectCaptchaIntoForm.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('LazyCaptchaClientRuntime::hostMarkup', $source);
    self::assertStringNotContainsString('weline-captcha-lazy-host', $source);
}

private function assert_LazyCaptchaClientRuntimeHostMarkup_testGoogleRenderGuardsEmptySiteKey(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Provider/GoogleRecaptchaEnterprise.php';
    $source = (string) \file_get_contents($path);
    self::assertMatchesRegularExpression('/\$siteKey\s*=\s*.*googleSiteKey\(\).*?\n.*?if\s*\(\s*\$siteKey\s*===\s*[\'"][\'"]\s*\)/s', $source);
    self::assertStringContainsString("enterprise.js?render=' . \\rawurlencode(\$siteKey)", $source);
}

private function assert_LazyCaptchaClientRuntimeHostMarkup_testLazyRuntimeResolvesViaFetchTagSourceWithoutDevFallback(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Service/LazyCaptchaClientRuntime.php';
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('Weline\Framework\View\Data\DataInterface', $source);
    self::assertStringContainsString('fetchTagSource(DataInterface::dir_type_STATICS', $source);
    self::assertStringContainsString("Weline_Captcha::js/captcha-lazy.js", $source);
    self::assertStringContainsString("Weline_Captcha::css/captcha-local.css", $source);
    self::assertStringNotContainsString('/Weline/Captcha/view/statics/', $source);
    self::assertStringNotContainsString('Weline\Framework\DataObject\DataInterface', $source);
    // Failure path must return empty string (onceScriptHtml skips empty).
    self::assertMatchesRegularExpression('/function resolveScriptUrl\(\)[\s\S]*?return \'\'/', $source);
    self::assertMatchesRegularExpression('/function resolveStylesheetUrl\(\)[\s\S]*?return \'\'/', $source);
}
}
