<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Captcha\Extends\Module\Weline_Framework\Security\Csp\CaptchaVendorsCsp;
use Weline\Captcha\Interface\VerificationProviderInterface;
use Weline\Captcha\Provider\GoogleRecaptchaEnterprise;
use Weline\Captcha\Provider\LocalImageCaptcha;
use Weline\Captcha\Provider\TencentCaptcha;

/**
 * Aggregated contract suite (merged from 5 *ContractTest files).
 * Observe one overall pass/fail via testAllCaptchaProviderContractsPass().
 */
final class CaptchaProviderContractsTest extends TestCase
{
    public function testAllCaptchaProviderContractsPass(): void
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
            'CaptchaProviderCspDirectives::testBuiltInProvidersDeclareCspDirectives' => function () {
            $this->assert_CaptchaProviderCspDirectives_testBuiltInProvidersDeclareCspDirectives();
        },
            'CaptchaProviderCspDirectives::testCaptchaVendorsCspAggregatesRegistryProviders' => function () {
            $this->assert_CaptchaProviderCspDirectives_testCaptchaVendorsCspAggregatesRegistryProviders();
        },
            'GoogleRecaptchaEnterpriseFailureDetail::testVerifyThrowsDiagnosticCodesInsteadOfSilentFalse' => function () {
            $this->assert_GoogleRecaptchaEnterpriseFailureDetail_testVerifyThrowsDiagnosticCodesInsteadOfSilentFalse();
        },
            'GoogleRecaptchaEnterpriseOutboundProxy::testRequestAssessmentAppliesOutboundProxy' => function () {
            $this->assert_GoogleRecaptchaEnterpriseOutboundProxy_testRequestAssessmentAppliesOutboundProxy();
        },
            'GoogleRecaptchaEnterpriseRenderJs::testRenderSourceClosesAllowDegradeBeforeFailEnd' => function () {
            $this->assert_GoogleRecaptchaEnterpriseRenderJs_testRenderSourceClosesAllowDegradeBeforeFailEnd();
        },
            'GoogleRecaptchaEnterpriseRenderJs::testRenderSourceIncludesGoogleTrustBadge' => function () {
            $this->assert_GoogleRecaptchaEnterpriseRenderJs_testRenderSourceIncludesGoogleTrustBadge();
        },
            'GoogleRecaptchaEnterpriseRenderJs::testPrepareSubmitIgnoresStaleListenerAfterLocalDegrade' => function () {
            $this->assert_GoogleRecaptchaEnterpriseRenderJs_testPrepareSubmitIgnoresStaleListenerAfterLocalDegrade();
        },
            'LocalImageCaptchaLayout::testRenderStylesAlignImageWithInput' => function () {
            $this->assert_LocalImageCaptchaLayout_testRenderStylesAlignImageWithInput();
        },
        ];
    }

private function assert_CaptchaProviderCspDirectives_testBuiltInProvidersDeclareCspDirectives(): void
{
    /** @var GoogleRecaptchaEnterprise $google */
    $google = (new \ReflectionClass(GoogleRecaptchaEnterprise::class))->newInstanceWithoutConstructor();
    $g = $google->cspDirectives();
    self::assertContains('https://www.google.com', $g['script-src'] ?? []);
    self::assertContains('https://www.gstatic.com', $g['script-src'] ?? []);
    self::assertContains('https://www.recaptcha.net', $g['script-src'] ?? []);
    self::assertContains('https://www.google.com', $g['frame-src'] ?? []);
    self::assertContains('https://recaptcha.google.com', $g['frame-src'] ?? []);
    self::assertContains('https://www.google.com', $g['connect-src'] ?? []);
    self::assertContains('https://recaptchaenterprise.googleapis.com', $g['connect-src'] ?? []);
    self::assertContains('https://www.gstatic.com', $g['img-src'] ?? []);
    /** @var TencentCaptcha $tencent */
    $tencent = (new \ReflectionClass(TencentCaptcha::class))->newInstanceWithoutConstructor();
    $t = $tencent->cspDirectives();
    self::assertContains('https://turing.captcha.qcloud.com', $t['script-src'] ?? []);
    self::assertContains('https://captcha.tencentcloudapi.com', $t['connect-src'] ?? []);
    /** @var LocalImageCaptcha $local */
    $local = (new \ReflectionClass(LocalImageCaptcha::class))->newInstanceWithoutConstructor();
    self::assertSame([], $local->cspDirectives());
}

private function assert_CaptchaProviderCspDirectives_testCaptchaVendorsCspAggregatesRegistryProviders(): void
{
    $providers = [new class implements VerificationProviderInterface
    {
        public function code(): string
        {
            return 'google_enterprise';
        }
        public function render(array $context): string
        {
            return '';
        }
        public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool
        {
            return false;
        }
        public function cspDirectives(): array
        {
            return ['script-src' => ['https://www.recaptcha.net']];
        }
    }, new class implements VerificationProviderInterface
    {
        public function code(): string
        {
            return 'tencent_captcha';
        }
        public function render(array $context): string
        {
            return '';
        }
        public function verify(array $submission, string $intent, string $hostname, ?string $ip = null): bool
        {
            return false;
        }
        public function cspDirectives(): array
        {
            return ['script-src' => ['https://turing.captcha.qcloud.com']];
        }
    }];
    $contribution = (new CaptchaVendorsCsp(static fn(): array => $providers))->contribution();
    $script = $contribution->directives['script-src'] ?? [];
    self::assertContains('https://www.recaptcha.net', $script);
    self::assertContains('https://turing.captcha.qcloud.com', $script);
}

private function assert_GoogleRecaptchaEnterpriseFailureDetail_testVerifyThrowsDiagnosticCodesInsteadOfSilentFalse(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Provider/GoogleRecaptchaEnterprise.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("throw new \\RuntimeException('google_empty_token')", $source);
    self::assertStringContainsString('google_hostname_mismatch:', $source);
    self::assertStringContainsString('google_token_invalid:', $source);
    self::assertStringContainsString('siteKey is invalid', $source);
    self::assertStringContainsString('Google Enterprise Site Key 无效', $source);
}

private function assert_GoogleRecaptchaEnterpriseOutboundProxy_testRequestAssessmentAppliesOutboundProxy(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Provider/GoogleRecaptchaEnterprise.php';
    self::assertFileExists($path);
    $source = (string) \file_get_contents($path);
    self::assertStringContainsString('CaptchaOutboundProxy', $source);
    self::assertStringContainsString('CaptchaOutboundProxy::apply', $source);
    self::assertStringContainsString('use Weline\Captcha\Service\CaptchaOutboundProxy', $source);
    $proxyPath = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Service/CaptchaOutboundProxy.php';
    self::assertFileExists($proxyPath);
    $proxySource = (string) \file_get_contents($proxyPath);
    self::assertStringContainsString('CURLOPT_PROXY', $proxySource);
    self::assertStringContainsString('CURLPROXY_SOCKS5_HOSTNAME', $proxySource);
    self::assertStringContainsString('CURLOPT_PROXYUSERPWD', $proxySource);
    self::assertStringContainsString('customer/social_login/http_proxy', $proxySource);
}

private function assert_GoogleRecaptchaEnterpriseRenderJs_testRenderSourceClosesAllowDegradeBeforeFailEnd(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Provider/GoogleRecaptchaEnterprise.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('reason:failReason}}));}};', $source);
    self::assertStringContainsString('var failReason=String(error&&error.message||error||"recaptcha_unavailable");', $source);
    self::assertStringNotContainsString('reason:failReason}}));};\'', $source);
}

private function assert_GoogleRecaptchaEnterpriseRenderJs_testRenderSourceIncludesGoogleTrustBadge(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Provider/GoogleRecaptchaEnterprise.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('data-testid="google-recaptcha-trust-badge"', $source);
    self::assertStringContainsString('weline-captcha-google-trust__logo', $source);
    self::assertStringContainsString('fill="#4285F4"', $source);
    self::assertStringContainsString('https://policies.google.com/privacy', $source);
    self::assertStringContainsString('https://policies.google.com/terms', $source);
    self::assertStringContainsString('受 Google 保护', $source);
    self::assertStringContainsString('hidden data-sdk-ready="0"', $source);
    self::assertStringContainsString('var reveal=function()', $source);
    self::assertStringContainsString('var conceal=function()', $source);
    self::assertStringContainsString('recaptcha_script_error', $source);
    self::assertStringContainsString('www.recaptcha.net/recaptcha/enterprise.js', $source);
    self::assertStringContainsString('www.google.com/recaptcha/enterprise.js', $source);
    self::assertStringContainsString("preg_replace('/[^A-Za-z0-9_\\/]+/', '_'", $source);
    self::assertStringContainsString('var loadNext=function()', $source);
    self::assertStringContainsString('var settled=false', $source);
    self::assertStringContainsString('var onloaded=false', $source);
    self::assertStringContainsString('if(onloaded){return;}finish(!!(window.grecaptcha&&grecaptcha.enterprise));},5000);', $source);
    self::assertStringContainsString('else if(grace>=50)', $source);
    self::assertStringContainsString('else if(tries>=80)', $source);
    self::assertStringContainsString('var runExecute=function(left)', $source);
    self::assertStringContainsString('runExecute(3)', $source);
    self::assertStringContainsString('tokenLooksWeak', $source);
    self::assertStringContainsString('recaptcha_weak_token', $source);
    self::assertStringContainsString('length<1000', $source);
    self::assertStringContainsString('ms<120', $source);
    self::assertStringNotContainsString('127.0.0.1:7277', $source);
    self::assertStringNotContainsString('debug-e4670c.log', $source);
    self::assertStringNotContainsString('client_fail', $source);
    self::assertStringContainsString('grecaptcha.enterprise.ready(function(){reveal();', $source);
    self::assertStringContainsString('document.head.appendChild(s);', $source);
    self::assertStringContainsString('var formId=', $source);
    self::assertStringContainsString('data-form-id=', $source);
    self::assertStringContainsString('data-shipping-editor', $source);
    self::assertStringContainsString('form.tagName==="FORM"', $source);
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/view/statics/css/captcha-local.css');
    self::assertStringContainsString('.weline-captcha-google-trust', $css);
    self::assertStringContainsString('--weline-theme-border', $css);
    self::assertStringContainsString('.weline-captcha-google-trust[hidden]', $css);
    self::assertStringContainsString('[data-sdk-ready="0"]', $css);
}

private function assert_GoogleRecaptchaEnterpriseRenderJs_testPrepareSubmitIgnoresStaleListenerAfterLocalDegrade(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Provider/GoogleRecaptchaEnterprise.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('data-weline-captcha-provider")!=="google_enterprise"){return;}', $source);
    self::assertMatchesRegularExpression('/data-weline-captcha-provider=\\\\*"google_enterprise\\\\*"/', $source);
}

private function assert_LocalImageCaptchaLayout_testRenderStylesAlignImageWithInput(): void
{
    $templatePath = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/view/templates/frontend/local-image-challenge.phtml';
    $cssPath = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/view/statics/css/captcha-local.css';
    $lazyJsPath = '/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/view/statics/js/captcha-lazy.js';
    self::assertFileExists($templatePath);
    self::assertFileExists($cssPath);
    self::assertFileExists($lazyJsPath);
    $template = (string) \file_get_contents($templatePath);
    $css = (string) \file_get_contents($cssPath);
    $lazyJs = (string) \file_get_contents($lazyJsPath);
    self::assertStringContainsString('weline-captcha-row', $template);
    self::assertStringContainsString('weline-captcha-visual', $template);
    self::assertStringContainsString('weline-captcha-refresh', $template);
    self::assertStringContainsString('w-field__label', $template);
    self::assertStringContainsString('for="', $template);
    self::assertStringNotContainsString('<style>', $template, 'Layout CSS must live in captcha-local.css for lazy injection');
    self::assertStringContainsString('flex-direction: row', $css);
    self::assertStringContainsString('aspect-ratio: 168 / 40', $css);
    self::assertStringContainsString('object-fit: contain', $css);
    self::assertStringContainsString('flex: 0 1 auto', $css);
    self::assertStringContainsString('width: fit-content', $css);
    self::assertStringContainsString('height: var(--weline-control-height', $css);
    self::assertStringContainsString('width: auto !important', $css);
    self::assertStringNotContainsString('flex: 1 1 auto', $css);
    self::assertStringNotContainsString('object-fit: cover', $css);
    self::assertStringContainsString('ensureStylesheet', $lazyJs);
    self::assertStringContainsString('hoistFragmentStyles', $lazyJs);
    self::assertStringContainsString('refreshWhenShown', $lazyJs);
    self::assertStringContainsString('#cs-bind-modal', $lazyJs);
    self::assertStringContainsString('scheduleDomScan', $lazyJs);
    self::assertStringContainsString('withObserverPaused', $lazyJs);
    self::assertStringContainsString('20260910-google-ready1', $lazyJs);
    $runtime = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Captcha' . '/Service/LazyCaptchaClientRuntime.php');
    self::assertStringContainsString('20260910-google-ready1', $runtime);
    self::assertStringNotContainsString('20260908-google-trust1', $runtime);
    self::assertStringNotContainsString('20260905-input-fit1', $runtime);
    self::assertStringNotContainsString('20260907-pending1', $runtime);
}
}
