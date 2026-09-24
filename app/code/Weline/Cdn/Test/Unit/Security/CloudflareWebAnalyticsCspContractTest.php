<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Extends\Module\Weline_Framework\Security\Csp\CloudflareWebAnalyticsCsp;
use Weline\Framework\Http\Security\SecurityHeaderDefaults;

final class CloudflareWebAnalyticsCspContractTest extends TestCase
{
    public function testContributionAllowsCloudflareInsightsBeaconHosts(): void
    {
        $directives = (new CloudflareWebAnalyticsCsp())->contribution()->directives;

        self::assertContains('https://static.cloudflareinsights.com', $directives['script-src'] ?? []);
        self::assertContains('https://cloudflareinsights.com', $directives['connect-src'] ?? []);
    }

    public function testDefaultsDoNotHardcodeCloudflareInsights(): void
    {
        $csp = SecurityHeaderDefaults::CSP;
        self::assertStringNotContainsString('cloudflareinsights.com', $csp);
        self::assertStringNotContainsString('static.cloudflareinsights.com', $csp);
    }

    public function testProviderLivesUnderCdnExtendsSecurityCsp(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Security/Csp/CloudflareWebAnalyticsCsp.php';
        self::assertFileExists($path);
    }
}
