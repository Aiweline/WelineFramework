<?php

declare(strict_types=1);

namespace Weline\Meta\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Meta\Service\MetaLocalStaleGcService;

/**
 * MetaLocal 老旧/孤儿轻量流式 GC（与翻译 cron 分离）。
 */
class MetaLocalStaleGc implements CronTaskInterface
{
    public function __construct(
        private readonly MetaLocalStaleGcService $staleGcService,
    ) {
    }

    public function name(): string
    {
        return 'MetaLocal 老旧译文清理';
    }

    public function execute_name(): string
    {
        return 'meta_local_stale_gc';
    }

    public function tip(): string
    {
        return '轻量流式清理 MetaLocal 孤儿 version/预览/无 Meta 行；单轮有上限，不打满机器';
    }

    public function cron_time(): string
    {
        return '30 3 * * *';
    }

    public function execute(): string
    {
        $result = $this->staleGcService->run();

        return (string)__(
            'MetaLocal stale GC: scanned=%{1}, orphan_version=%{2}, stale_preview=%{3}, orphan_meta=%{4}, stopped_by_limit=%{5}',
            [
                (string)($result['scanned'] ?? 0),
                (string)($result['deleted_orphan_version'] ?? 0),
                (string)($result['deleted_stale_preview'] ?? 0),
                (string)($result['deleted_orphan_meta'] ?? 0),
                !empty($result['stopped_by_limit']) ? '1' : '0',
            ],
        );
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 15;
    }
}
