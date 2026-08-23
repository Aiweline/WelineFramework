<?php

declare(strict_types=1);

namespace Weline\StorageOss\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\StorageOss\Service\OssMultipartCleanupProcessor;

final class MultipartCleanup implements CronTaskInterface
{
    public function __construct(private readonly OssMultipartCleanupProcessor $processor)
    {
    }

    public function name(): string { return (string)__('OSS multipart 清理'); }
    public function execute_name(): string { return 'storage_oss_multipart_cleanup'; }
    public function tip(): string { return (string)__('重试中止未完成的 OSS multipart 上传'); }
    public function cron_time(): string { return '* * * * *'; }
    public function unlock_timeout(int $minute = 5): int { return $minute; }

    public function execute(): string
    {
        $result = $this->processor->process(20);
        return (string)__('OSS multipart 清理完成：成功 %{1}，重试 %{2}，终止 %{3}', [
            $result['resolved'],
            $result['failed'],
            $result['dead'],
        ]);
    }
}
