<?php

declare(strict_types=1);

namespace Weline\Framework\Extends\Module\Weline_Framework\Fpc\Store;

use Weline\Framework\Http\Fpc\FpcStoreAdapterInterface;
use Weline\Framework\Router\FullPageCacheCoordinator;

/**
 * Framework 进程 L1 适配器：无 WLS 时保底；有 WLS 时由配置 prefer `wls`。
 */
final class ProcessFpcStoreAdapter implements FpcStoreAdapterInterface
{
    public function code(): string
    {
        return 'process';
    }

    public function purgeUrls(array $urls): void
    {
        unset($urls);
        $this->clearProcessCache();
    }

    public function purgeAll(string $reason = ''): void
    {
        unset($reason);
        $this->clearProcessCache();
    }

    public function clearProcessCache(): void
    {
        try {
            FullPageCacheCoordinator::clearProcessCache();
        } catch (\Throwable) {
        }
    }
}
