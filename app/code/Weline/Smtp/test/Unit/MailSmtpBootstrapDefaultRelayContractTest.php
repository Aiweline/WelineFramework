<?php
declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 邮局⊥Smtp：一键 ensure 不再调用 mail ensureDefaultSmtpAccount。
 */
final class MailSmtpBootstrapDefaultRelayContractTest extends TestCase
{
    public function testBootstrapNoLongerEnsuresMailDefaultSmtp(): void
    {
        $path = BP . '/app/code/Weline/Smtp/Service/MailSmtpBootstrapService.php';
        $src = (string) \file_get_contents($path);
        $body = $this->bootstrapEnsureBody($src);
        self::assertStringNotContainsString("'ensureDefaultSmtpAccount'", $body);
        self::assertStringContainsString('邮局与 Smtp 已拆分', $body);
    }

    public function testEnableServiceDnsBeforePoolContract(): void
    {
        $path = BP . '/app/code/Weline/Smtp/Service/SmtpServiceEnableService.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('assertDnsResolved', $src);
        self::assertStringContainsString('ensurePublicDnsHostnamesInPool', $src);
        self::assertStringContainsString('request_certificate', $src);
        self::assertStringContainsString('normalizeDomainInput', $src);
    }

    private function bootstrapEnsureBody(string $src): string
    {
        $start = strpos($src, 'function ensureDefaultContactPath');
        self::assertNotFalse($start);
        $end = strpos($src, 'function ', $start + 10);
        if ($end === false) {
            $end = strlen($src);
        }

        return substr($src, $start, $end - $start);
    }
}
