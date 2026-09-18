<?php

declare(strict_types=1);

namespace Weline\Currency\Api\Runtime;

use Weline\Currency\Service\CurrencyRateService;
use Weline\Currency\Service\Repository\CurrencyCatalog;
use Weline\Currency\Taglib\CurrencySelect;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        CurrencyCatalog::clearProcessCache();
        CurrencySelect::clearProcessCaches();
        $cleared = 2;
        try {
            ObjectManager::getInstance(CurrencyRateService::class)->invalidateCachedDefinitions();
            $cleared++;
        } catch (\Throwable) {
        }

        return $cleared;
    }
}
