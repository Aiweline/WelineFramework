<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Observer;

use Weline\Framework\Event\EventInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\GenerativeEngineOptimization\Model\Feed;
use Weline\GenerativeEngineOptimization\Model\FeedItem;
use Weline\GenerativeEngineOptimization\Service\PushService;

/**
 * 内容更新观察者
 * 监听内容更新事件，自动生成Feed条目并推送
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class ContentUpdateObserver
{
    /**
     * 处理内容创建事件
     * 
     * @param EventInterface $event
     * @return void
     */
    public function handleContentCreated(EventInterface $event): void
    {
        $data = $event->getData();
        $this->processContentUpdate($data, 'created');
    }

    /**
     * 处理内容更新事件
     * 
     * @param EventInterface $event
     * @return void
     */
    public function handleContentUpdated(EventInterface $event): void
    {
        $data = $event->getData();
        $this->processContentUpdate($data, 'updated');
    }

    /**
     * 处理内容更新
     * 
     * @param array $data 内容数据
     * @param string $action 操作类型（created, updated）
     * @return void
     */
    protected function processContentUpdate(array $data, string $action): void
    {
        try {
            // 获取所有启用的自动推送Feed
            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feeds = $feedModel
                ->where(Feed::schema_fields_IS_ENABLED, 1)
                ->where(Feed::schema_fields_IS_AUTO_PUSH, 1)
                ->select()
                ->fetchArray();

            foreach ($feeds as $feedData) {
                $feed = $feedModel->load($feedData['id']);
                
                // 检查Feed是否匹配内容类型
                if (!$this->isFeedMatchContent($feed, $data)) {
                    continue;
                }

                // 创建或更新Feed条目
                $this->createOrUpdateFeedItem($feed, $data);

                // 如果Feed配置了实时推送，立即推送
                if ($feed->getData(Feed::schema_fields_UPDATE_FREQUENCY) === Feed::FREQUENCY_REALTIME) {
                    $this->triggerAutoPush($feed);
                }
            }
        } catch (\Exception $e) {
            w_log_error('ContentUpdateObserver error: ' . $e->getMessage());
        }
    }

    /**
     * 检查Feed是否匹配内容
     * 
     * @param Feed $feed Feed配置
     * @param array $data 内容数据
     * @return bool 是否匹配
     */
    protected function isFeedMatchContent(Feed $feed, array $data): bool
    {
        $feedType = $feed->getData(Feed::schema_fields_FEED_TYPE);
        $sourceConfig = $feed->getSourceConfigArray();
        
        // 根据Feed类型和配置判断是否匹配
        // 这里可以根据实际业务逻辑实现匹配规则
        return true;
    }

    /**
     * 创建或更新Feed条目
     * 
     * @param Feed $feed Feed配置
     * @param array $data 内容数据
     * @return void
     */
    protected function createOrUpdateFeedItem(Feed $feed, array $data): void
    {
        /** @var FeedItem $feedItemModel */
        $feedItemModel = ObjectManager::getInstance(FeedItem::class);
        
        // 查找是否已存在
        $feedItem = $feedItemModel
            ->where(FeedItem::schema_fields_FEED_ID, $feed->getId())
            ->where(FeedItem::schema_fields_ITEM_TYPE, $data['type'] ?? 'content')
            ->where(FeedItem::schema_fields_ITEM_ID, $data['id'] ?? 0)
            ->find()
            ->fetch();

        if (!$feedItem->getId()) {
            $feedItem = $feedItemModel;
        }

        $feedItem->setData([
            FeedItem::schema_fields_FEED_ID => $feed->getId(),
            FeedItem::schema_fields_ITEM_TYPE => $data['type'] ?? 'content',
            FeedItem::schema_fields_ITEM_ID => $data['id'] ?? 0,
            FeedItem::schema_fields_TITLE => $data['title'] ?? '',
            FeedItem::schema_fields_CONTENT => $data['content'] ?? '',
            FeedItem::schema_fields_URL => $data['url'] ?? '',
            FeedItem::schema_fields_METADATA => json_encode($data['metadata'] ?? []),
            FeedItem::schema_fields_IS_PUBLISHED => $data['is_published'] ?? 1,
            FeedItem::schema_fields_PUBLISHED_AT => $data['published_at'] ?? time(),
        ]);

        $feedItem->save();
    }

    /**
     * 触发自动推送
     * 
     * @param Feed $feed Feed配置
     * @return void
     */
    protected function triggerAutoPush(Feed $feed): void
    {
        try {
            /** @var PushService $pushService */
            $pushService = ObjectManager::getInstance(PushService::class);
            
            // 获取所有启用的平台
            /** @var \Weline\GenerativeEngineOptimization\Model\Platform $platformModel */
            $platformModel = ObjectManager::getInstance(\Weline\GenerativeEngineOptimization\Model\Platform::class);
            $platforms = $platformModel
                ->where(\Weline\GenerativeEngineOptimization\Model\Platform::schema_fields_IS_ENABLED, 1)
                ->select()
                ->fetchArray();

            foreach ($platforms as $platformData) {
                $platform = $platformModel->load($platformData['id']);
                $pushService->pushFeed($feed, $platform, null, \Weline\GenerativeEngineOptimization\Model\PushLog::TYPE_AUTO);
            }
        } catch (\Exception $e) {
            w_log_error('Auto push error: ' . $e->getMessage());
        }
    }
}

