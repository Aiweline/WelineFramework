<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\CloudflareAlwaysOnlineFlagResolver;

final class CloudflareAlwaysOnlineContractTest extends TestCase
{
    public function testPutRulesSyncsAlwaysOnlineFromSystemConfig(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Adapter/Cloudflare.php'
        );
        self::assertStringContainsString('function syncAlwaysOnline', $src);
        self::assertStringContainsString('/settings/always_online', $src);
        self::assertStringContainsString('alwaysOnlineFlagResolver()', $src);
        self::assertStringContainsString('CloudflareAlwaysOnlineFlagResolver', $src);
        self::assertMatchesRegularExpression(
            '/putRules\\([\\s\\S]*?syncAlwaysOnline\\(/',
            $src,
        );
        self::assertStringContainsString("'value' => \$desiredValue", $src);
        self::assertStringContainsString("\$enabled ? 'on' : 'off'", $src);
        self::assertStringNotContainsString(
            "makeRequest('PATCH', \$url, ['value' => 'on'], \$credentials)",
            $src,
        );
    }

    public function testFlagResolverDefaultsOnAndReadsConfigKey(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/CloudflareAlwaysOnlineFlagResolver.php'
        );
        self::assertStringContainsString(CloudflareAlwaysOnlineFlagResolver::CONFIG_KEY, $src);
        self::assertSame('cdn/cloudflare/always_online', CloudflareAlwaysOnlineFlagResolver::CONFIG_KEY);
        self::assertStringContainsString('return true;', $src);
    }
}
