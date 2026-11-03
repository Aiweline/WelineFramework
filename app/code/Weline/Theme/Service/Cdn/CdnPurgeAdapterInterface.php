<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 真正去清 CDN 的出口。实现由站点侧提供（不同 CDN/账户各一套），
 * 规划与重试逻辑留在 {@see PurgeBatchPlanner}，这样离线可测。
 */
interface CdnPurgeAdapterInterface
{
    /**
     * @param string $zone  站点 zone 标识（如域名）
     * @param list<string> $targets 前缀或精确 URL
     *
     * @return bool 是否成功；失败时由规划器决定是否中断与续跑
     */
    public function purge(string $zone, array $targets): bool;
}
