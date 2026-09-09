<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Model\Feed;
use Weline\Geo\Model\PushLog;
use Weline\Geo\Service\FeedQueueService;
use Weline\Geo\Service\GeoPushSettings;

/**
 * 定时推送 Feed 到 AI 搜索平台（每天一次；受统一配置开关控制）。
 *
 * @package Weline_Geo
 */
class AutoPushFeed implements CronTaskInterface
{
    public function name(): string
    {
        return 'Weline_Geo::auto_push_feed';
    }

    public function execute_name(): string
    {
        return 'Weline\Geo\Cron\AutoPushFeed::execute';
    }

    public function tip(): string
    {
        return '每天定时推送 GEO Feed 到 AI 搜索引擎平台（需开启统一配置「启用定时推送」）';
    }

    /**
     * 每天 02:00 执行一次。
     */
    public function cron_time(): string
    {
        return '0 2 * * *';
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return 120;
    }

    public function execute(): string
    {
        try {
            /** @var GeoPushSettings $settings */
            $settings = ObjectManager::getInstance(GeoPushSettings::class);
            if (!$settings->isScheduledPushEnabled()) {
                return '定时推送已关闭（统一配置 geo/push/scheduled_enabled）';
            }

            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);

            $feeds = $feedModel
                ->where(Feed::schema_fields_IS_ENABLED, 1)
                ->where(Feed::schema_fields_IS_AUTO_PUSH, 1)
                ->select()
                ->fetchArray();

            if (empty($feeds)) {
                return '没有需要自动推送的 Feed（需内容源启用且允许自动推送）';
            }

            /** @var FeedQueueService $queueService */
            $queueService = ObjectManager::getInstance(FeedQueueService::class);

            $totalEnqueued = 0;

            foreach ($feeds as $feedData) {
                $feed = $feedModel->load($feedData['id']);

                if (!$feed->isEnabled() || !$feed->isAutoPush()) {
                    continue;
                }

                try {
                    $queueService->enqueueFeedPush($feed->getId(), [], PushLog::TYPE_SCHEDULED);
                    $totalEnqueued++;
                } catch (\Exception $e) {
                    w_log_error("Enqueue push failed - Feed ID: {$feed->getId()}, Error: {$e->getMessage()}");
                }
            }

            $message = "自动推送任务入队完成 - 已入队: {$totalEnqueued} 个Feed";
            if ($totalEnqueued > 0) {
                w_log_info($message);
            }

            return $message;
        } catch (\Exception $e) {
            $errorMessage = '自动推送任务执行失败: ' . $e->getMessage();
            w_log_error($errorMessage);

            return $errorMessage;
        }
    }
}
