<?php

declare(strict_types=1);

namespace Weline\Captcha\Test\Unit\Provider;

use PHPUnit\Framework\TestCase;

/**
 * Contract: Google Enterprise assessment must apply CaptchaOutboundProxy (auto punch).
 */
final class GoogleRecaptchaEnterpriseOutboundProxyContractTest extends TestCase
{
    public function testRequestAssessmentAppliesOutboundProxy(): void
    {
        $path = \dirname(__DIR__, 3) . '/Provider/GoogleRecaptchaEnterprise.php';
        self::assertFileExists($path);
        $source = (string) \file_get_contents($path);

        self::assertStringContainsString('CaptchaOutboundProxy', $source);
        self::assertStringContainsString('CaptchaOutboundProxy::apply', $source);
        self::assertStringContainsString('use Weline\\Captcha\\Service\\CaptchaOutboundProxy', $source);

        $proxyPath = \dirname(__DIR__, 3) . '/Service/CaptchaOutboundProxy.php';
        self::assertFileExists($proxyPath);
        $proxySource = (string) \file_get_contents($proxyPath);
        self::assertStringContainsString('CURLOPT_PROXY', $proxySource);
        self::assertStringContainsString('CURLPROXY_SOCKS5_HOSTNAME', $proxySource);
        self::assertStringContainsString('CURLOPT_PROXYUSERPWD', $proxySource);
        self::assertStringContainsString('customer/social_login/http_proxy', $proxySource);
    }
}
