<?php

declare(strict_types=1);

namespace Weline\Shipping\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ShippingProcessCacheContractTest extends TestCase
{
    public function testHotPathServicesOwnProcessBags(): void
    {
        $base = dirname(__DIR__, 3) . '/Service/';
        foreach ([
            'EmbargoService.php' => '$processRulesByScope',
            'DestinationService.php' => '$processRulesByScope',
            'CarrierCoverageMatchService.php' => '$processActiveCarriers',
            'WarehouseShippingOriginService.php' => '$processOriginByKey',
            'RegionLocalNameResolver.php' => '$processRegionCache',
        ] as $file => $needle) {
            $src = (string)file_get_contents($base . $file);
            self::assertStringContainsString($needle, $src, $file);
            self::assertStringContainsString('function clearProcessCache', $src, $file);
        }
    }

    public function testProcessCacheResetterIsRegistered(): void
    {
        $module = require dirname(__DIR__, 3) . '/etc/module.php';
        self::assertSame(
            \Weline\Shipping\Api\Runtime\ProcessCacheResetter::class,
            $module['provides']['process_cache_resetter.Weline_Shipping'] ?? null,
        );
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/Runtime/ProcessCacheResetter.php');
        self::assertStringContainsString('EmbargoService::clearProcessCache()', $src);
        self::assertStringContainsString('RegionLocalNameResolver::clearProcessCache()', $src);
        $embargo = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/EmbargoService.php');
        self::assertStringContainsString('ScopeSharedMemo::rememberScoped', $embargo);
        self::assertStringContainsString('ScopeIdentity::fromLayerIds', $embargo);
        self::assertStringContainsString("'shipping.embargo'", $embargo);
    }
}
