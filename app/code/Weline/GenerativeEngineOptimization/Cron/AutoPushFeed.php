<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\GenerativeEngineOptimization\Model\Feed;
use Weline\GenerativeEngineOptimization\Model\PushLog;
use Weline\GenerativeEngineOptimization\Service\FeedQueueService;

/**
 * 自动推送Feed的Cron任务
 * 
 * 定期推送所有启用了自动推送的Feed到所有启用的平台
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class AutoPushFeed implements CronTaskInterface
{
    /**
     * 调度任务名
     * 
     * @return string
     */
    public function name(): string
    {
        return 'Weline_GenerativeEngineOptimization::auto_push_feed';
    }

    /**
     * 执行名
     * 
     * @return string
     */
    public function execute_name(): string
    {
        return 'Weline\GenerativeEngineOptimization\Cron\AutoPushFeed::execute';
    }

    /**
     * 任务描述
     * 
     * @return string
     */
    public function tip(): string
    {
        return '自动推送Feed到AI搜索引擎平台';
    }

    /**
     * 调度时间频率
     * 每小时执行一次
     * 
     * @return string
     */
    public function cron_time(): string
    {
        return '0 * * * *'; // 每小时执行一次
    }

    /**
     * 超时解锁时间（分钟）
     * 
     * @param int $minute
     * @return int
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return 60; // 60分钟超时
    }

    /**
     * 执行自动推送任务（入队方式）
     * 
     * @return string
     */
    public function execute(): string
    {
        try {
            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            
            // 获取所有启用且启用了自动推送的Feed
            $feeds = $feedModel
                ->where(Feed::schema_fields_IS_ENABLED, 1)
                ->where(Feed::schema_fields_IS_AUTO_PUSH, 1)
                ->select()
                ->fetchArray();

            if (empty($feeds)) {
                return "没有需要自动推送的Feed";
            }

            /** @var FeedQueueService $queueService */
            $queueService = ObjectManager::getInstance(FeedQueueService::class);

            $totalEnqueued = 0;

            foreach ($feeds as $feedData) {
                $feed = $feedModel->load($feedData['id']);
                
                // 再次检查（防止数据变更）
                if (!$feed->isEnabled() || !$feed->isAutoPush()) {
                    continue;
                }

                // 入队推送任务（空数组表示所有平台）
                try {
                    $queueService->enqueueFeedPush($feed->getId(), [], PushLog::TYPE_SCHEDULED);
                    $totalEnqueued++;
                } catch (\Exception $e) {
                    w_log_error("Enqueue push failed - Feed ID: {$feed->getId()}, Error: {$e->getMessage()}");
                }
            }

            // 记录执行结果
            $message = "自动推送任务入队完成 - 已入队: {$totalEnqueued} 个Feed";
            if ($totalEnqueued > 0) {
                w_log_info($message);
            }
            
            return $message;
        } catch (\Exception $e) {
            $errorMessage = "自动推送任务执行失败: " . $e->getMessage();
            w_log_error($errorMessage);
            return $errorMessage;
        }
    }
}

