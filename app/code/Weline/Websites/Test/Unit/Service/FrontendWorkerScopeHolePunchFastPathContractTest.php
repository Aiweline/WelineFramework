<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Long Scope Cookie must short-circuit punch (verify only) — no DetectWebsite re-install.
 */
final class FrontendWorkerScopeHolePunchFastPathContractTest extends TestCase
{
    public function testPunchVerifiesExistingCookieBeforeResolveChannelScope(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/FrontendWorkerScopeHolePunchService.php'
        );
        self::assertStringContainsString('readExistingScopeCookie', $src);
        self::assertStringContainsString('SCOPE_COOKIE_NAME', $src);
        self::assertStringContainsString('verifyToken', $src);
        self::assertStringContainsString('Fast path: long HttpOnly Cookie already verifies', $src);

        $fastPos = \strpos($src, 'readExistingScopeCookie');
        $resolvePos = \strpos($src, 'resolveChannelScope(');
        $issuePos = \strpos($src, 'issueToken(');
        self::assertNotFalse($fastPos);
        self::assertNotFalse($resolvePos);
        self::assertNotFalse($issuePos);
        self::assertLessThan($resolvePos, $fastPos);
        self::assertLessThan($issuePos, $resolvePos);
    }
}
