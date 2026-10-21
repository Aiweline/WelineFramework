<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Integration\Framework;

use PHPUnit\Framework\TestCase;

/**
 * Scope Token 被拒时必须留下结构化原因（status / reason / authority / fingerprint），
 * 否则前端只能看到稳定的 409 码，无法区分 host_mismatch 与 audience_mismatch。
 */
final class FrontendWorkerScopeProviderDiagnosticsTest extends TestCase
{
    public function testVerificationFailureLogsStructuredReason(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Integration/Framework/FrontendWorkerScopeProvider.php',
        );

        $body = '';
        if (\preg_match('/private function verificationFailure\([\s\S]*?\n    \}/', $src, $matches) === 1) {
            $body = (string)$matches[0];
        }
        self::assertNotSame('', $body, 'verificationFailure() must exist');
        self::assertStringContainsString('w_log_warning', $body);
        self::assertStringContainsString("'worker_scope'", $body);
        self::assertStringContainsString('$verification->reason', $body);
        self::assertStringContainsString('$verification->status', $body);
        self::assertStringContainsString('$verification->tokenFingerprint', $body);
        self::assertStringContainsString('RequestAuthority::current()', $body);
        // 必须同时记录 Token 声明的 Host，否则 host_mismatch 无法对照。
        self::assertStringContainsString("'claimed_host'", $body);
        self::assertStringContainsString('$verification->host', $body);
    }
}
