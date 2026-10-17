<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Extends\Module\Weline_Framework\Security\Csp\CloudflareWebAnalyticsCsp;
use Weline\Framework\Http\Security\SecurityHeaderDefaults;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnSecurityContractsPass().
 */
final class CdnSecurityContractsTest extends TestCase
{
    public function testAllCdnSecurityContractsPass(): void
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
            'CloudflareWebAnalyticsCsp::testContributionAllowsCloudflareInsightsBeaconHosts' => function () {
            $this->assert_CloudflareWebAnalyticsCsp_testContributionAllowsCloudflareInsightsBeaconHosts();
        },
            'CloudflareWebAnalyticsCsp::testDefaultsDoNotHardcodeCloudflareInsights' => function () {
            $this->assert_CloudflareWebAnalyticsCsp_testDefaultsDoNotHardcodeCloudflareInsights();
        },
            'CloudflareWebAnalyticsCsp::testProviderLivesUnderCdnExtendsSecurityCsp' => function () {
            $this->assert_CloudflareWebAnalyticsCsp_testProviderLivesUnderCdnExtendsSecurityCsp();
        },
        ];
    }

private function assert_CloudflareWebAnalyticsCsp_testContributionAllowsCloudflareInsightsBeaconHosts(): void
{
    $directives = (new CloudflareWebAnalyticsCsp())->contribution()->directives;
    self::assertContains('https://static.cloudflareinsights.com', $directives['script-src'] ?? []);
    self::assertContains('https://cloudflareinsights.com', $directives['connect-src'] ?? []);
}

private function assert_CloudflareWebAnalyticsCsp_testDefaultsDoNotHardcodeCloudflareInsights(): void
{
    $csp = SecurityHeaderDefaults::CSP;
    self::assertStringNotContainsString('cloudflareinsights.com', $csp);
    self::assertStringNotContainsString('static.cloudflareinsights.com', $csp);
}

private function assert_CloudflareWebAnalyticsCsp_testProviderLivesUnderCdnExtendsSecurityCsp(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/extends/module/Weline_Framework/Security/Csp/CloudflareWebAnalyticsCsp.php';
    self::assertFileExists($path);
}
}
