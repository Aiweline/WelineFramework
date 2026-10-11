<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;

final class CloudflareAlwaysOnlineContractTest extends TestCase
{
    public function testPutRulesWiresEnsureAlwaysOnline(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Adapter/Cloudflare.php'
        );
        self::assertStringContainsString('function ensureAlwaysOnline', $src);
        self::assertStringContainsString('/settings/always_online', $src);
        self::assertMatchesRegularExpression(
            '/putRules\\([\\s\\S]*?ensureAlwaysOnline\\(/',
            $src,
        );
    }
}
