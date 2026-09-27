<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Cloudflare protocol scopes are grant-driven; authorize must not require offline_access.
 */
final class CloudflareOAuthScopesContractTest extends TestCase
{
    public function testRequiredScopesOmitProtocolOfflineAccess(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/CloudflareOAuthService.php'
        );

        self::assertMatchesRegularExpression(
            "/REQUIRED_SCOPES\s*=\s*\[[^\]]*zone\.read[^\]]*dns\.write[^\]]*cache\.purge[^\]]*cache-settings\.write[^\]]*\]/s",
            $source
        );
        self::assertStringContainsString('PROTOCOL_SCOPES', $source);
        self::assertStringContainsString("'offline_access'", $source);
        // offline_access must live in PROTOCOL_SCOPES strip list, not REQUIRED_SCOPES.
        if (preg_match('/private const REQUIRED_SCOPES = \[(.*?)\];/s', $source, $m) !== 1) {
            self::fail('REQUIRED_SCOPES block missing');
        }
        self::assertStringNotContainsString('offline_access', $m[1]);
        self::assertStringContainsString('in_array($scope, self::PROTOCOL_SCOPES', $source);
    }
}
