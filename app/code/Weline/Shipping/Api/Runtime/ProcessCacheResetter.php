<?php

declare(strict_types=1);

namespace Weline\Shipping\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Shipping\Service\CarrierCoverageMatchService;
use Weline\Shipping\Service\DestinationService;
use Weline\Shipping\Service\EmbargoService;
use Weline\Shipping\Service\RegionLocalNameResolver;
use Weline\Shipping\Service\WarehouseShippingOriginService;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        EmbargoService::clearProcessCache();
        DestinationService::clearProcessCache();
        CarrierCoverageMatchService::clearProcessCache();
        WarehouseShippingOriginService::clearProcessCache();
        RegionLocalNameResolver::clearProcessCache();

        return 5;
    }
    public function diagCounts(): array
    {
        return [];
    }

}
