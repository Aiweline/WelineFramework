<?php
declare(strict_types=1);

namespace Weline\Smtp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Smtp 一键默认全部中继：ensureDefaultSmtpAccount + rebindAll 默认 true。
 */
final class MailSmtpBootstrapDefaultRelayContractTest extends TestCase
{
    public function testBootstrapUsesDefaultSmtpAccountNotContact(): void
    {
        $path = BP . '/app/code/Weline/Smtp/Service/MailSmtpBootstrapService.php';
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString("'ensureDefaultSmtpAccount'", $src);
        self::assertStringContainsString('default_smtp_account_id', $src);
        self::assertStringContainsString('$doRebind = $rebindAll ?? true', $src);
        self::assertStringNotContainsString(
            "'ensureContactAccount'",
            $this->bootstrapEnsureBody($src)
        );
    }

    public function testProvisionerPrefersSmtpLocal(): void
    {
        $path = BP . '/app/code/Weline/Smtp/Service/MailAccountTransportProvisioner.php';
        $src = (string) \file_get_contents($path);
        self::assertMatchesRegularExpression(
            "/preferredLocals\s*=\s*\[[^\]]*['\"]smtp['\"]/",
            $src
        );
        $smtpPos = strpos($src, "'smtp'");
        $contactPos = strpos($src, "'contact'");
        self::assertNotFalse($smtpPos);
        self::assertNotFalse($contactPos);
        self::assertLessThan($contactPos, $smtpPos);
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
