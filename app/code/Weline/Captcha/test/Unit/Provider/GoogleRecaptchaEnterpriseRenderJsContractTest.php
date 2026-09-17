<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Google Enterprise inline bind script must close if(allowDegrade) before fail().
 * Trust badge markup must expose Google mark + privacy/terms attribution.
 */
final class GoogleRecaptchaEnterpriseRenderJsContractTest extends TestCase
{
    public function testRenderSourceClosesAllowDegradeBeforeFailEnd(): void
    {
        $path = \dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString(
            'reason:failReason}}));}};',
            $source
        );
        self::assertStringContainsString(
            'var failReason=String(error&&error.message||error||"recaptcha_unavailable");',
            $source
        );
        self::assertStringNotContainsString(
            'reason:failReason}}));};\'',
            $source
        );
    }

    public function testRenderSourceIncludesGoogleTrustBadge(): void
    {
        $path = \dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        $source = (string)file_get_contents($path);
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

        $css = (string)file_get_contents(\dirname(__DIR__, 3) . '/view/statics/css/captcha-local.css');
        self::assertStringContainsString('.weline-captcha-google-trust', $css);
        self::assertStringContainsString('--weline-theme-border', $css);
        self::assertStringContainsString('.weline-captcha-google-trust[hidden]', $css);
        self::assertStringContainsString('[data-sdk-ready="0"]', $css);
    }

    public function testPrepareSubmitIgnoresStaleListenerAfterLocalDegrade(): void
    {
        $path = \dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        $source = (string) file_get_contents($path);
        self::assertStringContainsString(
            'data-weline-captcha-provider")!=="google_enterprise"){return;}',
            $source
        );
        self::assertMatchesRegularExpression(
            '/data-weline-captcha-provider=\\\\*"google_enterprise\\\\*"/',
            $source
        );
    }
}
