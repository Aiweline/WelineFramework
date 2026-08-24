<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Queue\Model\Queue;
use Weline\Queue\Model\Queue\Type;

/**
 * Feed队列服务
 * 
 * 封装Feed相关的队列操作
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class FeedQueueService
{
    /**
     * 队列类型代码
     */
    public const QUEUE_TYPE_FEED_GENERATE = 'geo_feed_generate';
    public const QUEUE_TYPE_FEED_PUSH = 'geo_feed_push';

    /**
     * 将Feed生成任务入队
     * 
     * @param int $feedId Feed ID
     * @param string $format Feed格式（json_feed, xml, rss）
     * @param bool $force 是否强制重新生成
     * @return int 队列任务ID
     */
    public function enqueueFeedGenerate(int $feedId, string $format = 'json_feed', bool $force = false): int
    {
        /** @var Type $queueType */
        $queueType = ObjectManager::getInstance(Type::class);
        $type = $queueType->where(Type::schema_fields_class, 'Weline\GenerativeEngineOptimization\Queue\FeedGenerateQueue')
            ->find()
            ->fetch();

        if (!$type->getId()) {
            throw new \Exception('Feed生成队列类型未注册，请先运行模块升级');
        }

        /** @var Queue $queue */
        $queue = ObjectManager::getInstance(Queue::class);
        
        $content = json_encode([
            'feed_id' => $feedId,
            'format' => $format,
            'force' => $force,
        ]);

        $queue->setTypeId($type->getId())
            ->setName("生成Feed #{$feedId}")
            ->setContent($content)
            ->setStatus(Queue::status_pending)
            ->setAuto(true)
            ->setModule('Weline_GenerativeEngineOptimization')
            ->save();

        return (int)$queue->getId();
    }

    /**
     * 将Feed推送任务入队
     * 
     * @param int $feedId Feed ID
     * @param array $platformIds 平台ID数组（空数组表示所有平台）
     * @param string $pushType 推送类型（manual, auto, scheduled）
     * @return int 队列任务ID
     */
    public function enqueueFeedPush(int $feedId, array $platformIds = [], string $pushType = 'scheduled'): int
    {
        /** @var Type $queueType */
        $queueType = ObjectManager::getInstance(Type::class);
        $type = $queueType->where(Type::schema_fields_class, 'Weline\GenerativeEngineOptimization\Queue\FeedPushQueue')
            ->find()
            ->fetch();

        if (!$type->getId()) {
            throw new \Exception('Feed推送队列类型未注册，请先运行模块升级');
        }

        /** @var Queue $queue */
        $queue = ObjectManager::getInstance(Queue::class);
        
        $content = json_encode([
            'feed_id' => $feedId,
            'platform_ids' => $platformIds,
            'push_type' => $pushType,
        ]);

        $platformIdsStr = empty($platformIds) ? '所有平台' : implode(',', $platformIds);
        $queue->setTypeId($type->getId())
            ->setName("推送Feed #{$feedId} 到平台 [{$platformIdsStr}]")
            ->setContent($content)
            ->setStatus(Queue::status_pending)
            ->setAuto(true)
            ->setModule('Weline_GenerativeEngineOptimization')
            ->save();

        return (int)$queue->getId();
    }

    /**
     * 将Feed条目添加任务入队（事件触发时使用）
     * 
     * @param int $feedId Feed ID
     * @param string $itemType 条目类型
     * @param int $itemId 条目ID
     * @param array $itemData 条目数据
     * @return int 队列任务ID
     */
    public function enqueueFeedItemAdd(int $feedId, string $itemType, int $itemId, array $itemData = []): int
    {
        // Feed条目添加实际上会触发Feed生成，所以入队生成任务
        return $this->enqueueFeedGenerate($feedId, 'json_feed', false);
    }
}

