<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\CloudflareAlwaysOnlineFlagResolver;

/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllCdnAdapterContractsPass().
 */
final class CdnAdapterContractsTest extends TestCase
{
    public function testAllCdnAdapterContractsPass(): void
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
            'CloudflareAlwaysOnline::testPutRulesSyncsAlwaysOnlineFromSystemConfig' => function () {
            $this->assert_CloudflareAlwaysOnline_testPutRulesSyncsAlwaysOnlineFromSystemConfig();
        },
            'CloudflareAlwaysOnline::testFlagResolverDefaultsOnAndReadsConfigKey' => function () {
            $this->assert_CloudflareAlwaysOnline_testFlagResolverDefaultsOnAndReadsConfigKey();
        },
            'CloudflareOauthCapable::testCloudflareImplementsOauthCapableProviderInterface' => function () {
            $this->assert_CloudflareOauthCapable_testCloudflareImplementsOauthCapableProviderInterface();
        },
            'CloudflareOauthCapable::testAdapterResolverExposesOauthCapableHelpers' => function () {
            $this->assert_CloudflareOauthCapable_testAdapterResolverExposesOauthCapableHelpers();
        },
        ];
    }

private function assert_CloudflareAlwaysOnline_testPutRulesSyncsAlwaysOnlineFromSystemConfig(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Adapter/Cloudflare.php');
    self::assertStringContainsString('function syncAlwaysOnline', $src);
    self::assertStringContainsString('/settings/always_online', $src);
    self::assertStringContainsString('alwaysOnlineFlagResolver()', $src);
    self::assertStringContainsString('CloudflareAlwaysOnlineFlagResolver', $src);
    self::assertMatchesRegularExpression('/putRules\([\s\S]*?syncAlwaysOnline\(/', $src);
    self::assertStringContainsString("'value' => \$desiredValue", $src);
    self::assertStringContainsString("\$enabled ? 'on' : 'off'", $src);
    self::assertStringNotContainsString("makeRequest('PATCH', \$url, ['value' => 'on'], \$credentials)", $src);
}

private function assert_CloudflareAlwaysOnline_testFlagResolverDefaultsOnAndReadsConfigKey(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/CloudflareAlwaysOnlineFlagResolver.php');
    self::assertStringContainsString(CloudflareAlwaysOnlineFlagResolver::CONFIG_KEY, $src);
    self::assertSame('cdn/cloudflare/always_online', CloudflareAlwaysOnlineFlagResolver::CONFIG_KEY);
    self::assertStringContainsString('return true;', $src);
}

private function assert_CloudflareOauthCapable_testCloudflareImplementsOauthCapableProviderInterface(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Adapter/Cloudflare.php');
    self::assertStringContainsString('use Weline\Cdn\Api\OauthCapableProviderInterface;', $source);
    self::assertStringContainsString('implements AdapterInterface, OauthCapableProviderInterface, OriginNoStoreEdgeHeaderWriterInterface', $source);
    self::assertStringContainsString('function supportsOneClickOauth', $source);
    self::assertStringContainsString('function startOauthAuthorization', $source);
    self::assertStringContainsString('function completeOauthAuthorization', $source);
    self::assertStringContainsString('function consumeOauthFailureState', $source);
    self::assertStringContainsString('authorizationUrl(', $source);
    self::assertStringContainsString('withoutStorefrontLocalizationPrefix', $source);
}

private function assert_CloudflareOauthCapable_testAdapterResolverExposesOauthCapableHelpers(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cdn' . '/Service/AdapterResolver.php');
    self::assertStringContainsString('function getOauthCapableAdapters', $source);
    self::assertStringContainsString('function getOauthCapableAdapter', $source);
    self::assertStringContainsString('OauthCapableProviderInterface', $source);
}
}
