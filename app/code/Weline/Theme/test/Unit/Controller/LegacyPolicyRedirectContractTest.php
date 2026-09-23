<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * QA-13: short policy paths 301 to canonical /policy/* via Theme Router
 * (redirect contract — not a revived defaultPublicRouteMap).
 */
final class LegacyPolicyRedirectContractTest extends TestCase
{
    public function testRouterDeclaresLegacyPolicyPermanentRedirects(): void
    {
        $path = \dirname(__DIR__, 3) . '/Controller/Router.php';
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);

        self::assertStringContainsString('LEGACY_POLICY_REDIRECTS', $source);
        self::assertStringContainsString("'privacy' => 'policy/privacy'", $source);
        self::assertStringContainsString("'cookie' => 'policy/cookie'", $source);
        self::assertStringContainsString("'cookies' => 'policy/cookie'", $source);
        self::assertStringContainsString("'refund' => 'policy/refund'", $source);
        self::assertStringContainsString('ResponseTerminateException(301', $source);
        // Redirect map only — must not restore path→controller alias table.
        self::assertStringContainsString('these aliases are redirects, not a revived', $source);
    }

    public function testCreateAliasOwnedByCustomerRouter(): void
    {
        $path = \dirname(__DIR__, 3) . '/Controller/Router.php';
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString("'customer/account/create'", $source);
    }
}
