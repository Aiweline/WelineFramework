<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CacheManager\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Cache\Console\Cache\Flush;

/**
 * 缓存清理定时任务
 * 自动清理过期的缓存文件，防止缓存目录过大
 */
class CacheCleanup implements CronTaskInterface
{
    /**
     * 任务名称
     */
    public function name(): string
    {
        return __('缓存清理任务');
    }
        
    /**
     * 执行名称
     */
    public function execute_name(): string
    {
        return 'cache_cleanup';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return __('自动清理过期的缓存文件，防止缓存目录过大');
    }

    /**
     * 执行时间 - 每天凌晨2点执行
     */
    public function cron_time(): string
    {
        return '0 2 * * *';
    }

    /**
     * 执行任务
     */
    public function execute(): string
    {
        try {
            $flush = ObjectManager::getInstance(Flush::class);
            $flush->execute();
            return __('缓存清理完成');  
        } catch (\Exception $e) {
            w_log_error(__("缓存清理任务执行失败: %{1}", $e->getMessage()));
            return __("缓存清理失败: %{1}", $e->getMessage());
        }
    }

    /**
     * 解锁超时时间（分钟）
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 60; // 1小时超时
    }
}
