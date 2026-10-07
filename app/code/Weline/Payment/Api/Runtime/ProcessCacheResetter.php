<?php

declare(strict_types=1);

namespace Weline\Payment\Api\Runtime;

use Weline\Framework\Runtime\ProcessCacheResetContext;
use Weline\Framework\Runtime\ProcessCacheResetterInterface;
use Weline\Payment\Service\PaymentMethodManager;

final class ProcessCacheResetter implements ProcessCacheResetterInterface
{
    public function resetProcessCaches(ProcessCacheResetContext $context): int
    {
        PaymentMethodManager::clearProcessCache();

        return 1;
    }
}
