<?php

declare(strict_types=1);

namespace Weline\Tax\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class TaxProcessCacheContractTest extends TestCase
{
    public function testTaxEngineAndScopeConfigOwnProcessBags(): void
    {
        $engine = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/TaxEngine.php');
        self::assertStringContainsString('$processClassesByWebsite', $engine);
        self::assertStringContainsString('$processRulesByWebsite', $engine);
        self::assertStringContainsString('function clearProcessCache', $engine);
        self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $engine);
        self::assertStringContainsString('ScopeIdentity::websiteById', $engine);
        self::assertStringContainsString("'tax.classes'", $engine);

        $scope = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/TaxScopeConfig.php');
        self::assertStringContainsString('$processResolvedByScope', $scope);
        self::assertStringContainsString('function clearProcessCache', $scope);
        self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $scope);
        self::assertStringContainsString('ScopeIdentity::fromLayerIds', $scope);
        self::assertStringContainsString("'tax.flags'", $scope);
    }

    public function testProcessCacheResetterIsRegistered(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            \Weline\Tax\Api\Runtime\ProcessCacheResetter::class,
            $module['provides']['process_cache_resetter.Weline_Tax'] ?? null,
        );
    }
}
