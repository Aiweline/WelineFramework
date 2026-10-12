<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;

/**
 * Default-allow FPC: no Extra → enabled + TTL one year; Search Extra must declare ttl=3600.
 */
final class FpcDefaultAllowPolicyContractTest extends TestCase
{
    private static function repoRoot(): string
    {
        // .../Framework/Test/Unit/Router → repo root
        return \dirname(__DIR__, 7);
    }

    public function testCoordinatorFallsBackToOneYearWhenNoExtraMatches(): void
    {
        $src = (string)\file_get_contents(
            self::repoRoot() . '/app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );
        self::assertMatchesRegularExpression(
            "/\\?\\?\\s*\\[\\s*'enabled'\\s*=>\\s*true\\s*,\\s*'ttl'\\s*=>\\s*31536000\\b/",
            $src
        );
        self::assertStringNotContainsString(
            "'ttl' => 3600, 'policy_fingerprint' => 'not-applicable'",
            $src
        );
    }

    public function testSearchHardExcludeRemovedAndShortTtlExtraPresent(): void
    {
        $coordinator = (string)\file_get_contents(
            self::repoRoot() . '/app/code/Weline/Framework/Router/FullPageCacheCoordinator.php'
        );
        self::assertStringNotContainsString(
            "// Search results vary by q/type/category_id; never share one FPC body.\n            'search',",
            $coordinator
        );

        $search = (string)\file_get_contents(
            self::repoRoot() . '/app/code/Weline/Search/Controller/Frontend/Index.php'
        );
        self::assertMatchesRegularExpression('/@Extra\\s+type=fpc\\b/', $search);
        self::assertMatchesRegularExpression('/ttl\\s*=\\s*3600\\b/', $search);
        self::assertDoesNotMatchRegularExpression('/enabled\\s*=\\s*false/', $search);
    }

    public function testSensitiveSurfacesDeclareEnabledFalse(): void
    {
        $root = self::repoRoot();
        $files = [
            $root . '/app/code/Weline/Compare/Controller/Frontend/Index.php',
            $root . '/app/code/Weline/Newsletter/Controller/Frontend/Subscribe.php',
            $root . '/app/code/Weline/HelpPay/Controller/Frontend/Payer.php',
            $root . '/app/code/Weline/CustomerService/Controller/Frontend/Bind.php',
            $root . '/app/code/Weline/Multipass/Controller/Frontend/Identity.php',
        ];
        foreach ($files as $file) {
            self::assertFileExists($file, $file);
            $src = (string)\file_get_contents($file);
            self::assertMatchesRegularExpression(
                '/@Extra\\s+type=fpc\\b[^\\n]*enabled\\s*=\\s*false/',
                $src,
                \basename($file)
            );
        }
    }
}
