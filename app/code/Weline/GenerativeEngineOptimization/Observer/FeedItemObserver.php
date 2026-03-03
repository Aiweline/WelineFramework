<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\GenerativeEngineOptimization\Model\Feed;
use Weline\GenerativeEngineOptimization\Model\FeedItem;
use Weline\GenerativeEngineOptimization\Service\FeedQueueService;

/**
 * Feed条目事件观察者
 * 
 * 监听Feed条目相关事件，自动创建或更新Feed条目
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class FeedItemObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $eventName = $event->getName();
        $data = $event->getData();

        try {
            switch ($eventName) {
                case 'Weline_GenerativeEngineOptimization::feed_item_add':
                    $this->handleFeedItemAdd($data);
                    break;
                case 'Weline_GenerativeEngineOptimization::feed_item_update':
                    $this->handleFeedItemUpdate($data);
                    break;
                case 'Weline_GenerativeEngineOptimization::feed_item_delete':
                    $this->handleFeedItemDelete($data);
                    break;
            }
        } catch (\Exception $e) {
            w_log_error("FeedItemObserver error [{$eventName}]: " . $e->getMessage());
        }
    }

    /**
     * 处理添加Feed条目事件
     * 
     * @param array $data 事件数据
     * @return void
     */
    protected function handleFeedItemAdd(array $data): void
    {
        // 验证必需字段
        if (empty($data['feed_id']) || empty($data['item_type']) || empty($data['item_id'])) {
            w_log_warning('FeedItemObserver: Missing required fields for feed_item_add');
            return;
        }

        /** @var Feed $feedModel */
        $feedModel = ObjectManager::getInstance(Feed::class);
        $feed = $feedModel->load($data['feed_id']);

        if (!$feed->getId() || !$feed->isEnabled()) {
            return;
        }

        /** @var FeedItem $feedItemModel */
        $feedItemModel = ObjectManager::getInstance(FeedItem::class);

        // 检查是否已存在
        $existingItem = $feedItemModel
            ->where(FeedItem::fields_FEED_ID, $feed->getId())
            ->where(FeedItem::fields_ITEM_TYPE, $data['item_type'])
            ->where(FeedItem::fields_ITEM_ID, $data['item_id'])
            ->find()
            ->fetch();

        if ($existingItem->getId()) {
            // 已存在，执行更新
            $this->updateFeedItem($existingItem, $data);
        } else {
            // 不存在，创建新条目
            $this->createFeedItem($feedItemModel, $feed, $data);
        }
    }

    /**
     * 处理更新Feed条目事件
     * 
     * @param array $data 事件数据
     * @return void
     */
    protected function handleFeedItemUpdate(array $data): void
    {
        // 验证必需字段
        if (empty($data['feed_id']) || empty($data['item_type']) || empty($data['item_id'])) {
            w_log_warning('FeedItemObserver: Missing required fields for feed_item_update');
            return;
        }

        /** @var FeedItem $feedItemModel */
        $feedItemModel = ObjectManager::getInstance(FeedItem::class);

        $feedItem = $feedItemModel
            ->where(FeedItem::fields_FEED_ID, $data['feed_id'])
            ->where(FeedItem::fields_ITEM_TYPE, $data['item_type'])
            ->where(FeedItem::fields_ITEM_ID, $data['item_id'])
            ->find()
            ->fetch();

        if (!$feedItem->getId()) {
            // 不存在，执行添加
            $this->handleFeedItemAdd($data);
            return;
        }

        $this->updateFeedItem($feedItem, $data);
    }

    /**
     * 处理删除Feed条目事件
     * 
     * @param array $data 事件数据
     * @return void
     */
    protected function handleFeedItemDelete(array $data): void
    {
        // 验证必需字段
        if (empty($data['feed_id']) || empty($data['item_type']) || empty($data['item_id'])) {
            w_log_warning('FeedItemObserver: Missing required fields for feed_item_delete');
            return;
        }

        /** @var FeedItem $feedItemModel */
        $feedItemModel = ObjectManager::getInstance(FeedItem::class);

        $feedItem = $feedItemModel
            ->where(FeedItem::fields_FEED_ID, $data['feed_id'])
            ->where(FeedItem::fields_ITEM_TYPE, $data['item_type'])
            ->where(FeedItem::fields_ITEM_ID, $data['item_id'])
            ->find()
            ->fetch();

        if ($feedItem->getId()) {
            $feedItem->delete();
        }
    }

    /**
     * 创建Feed条目
     * 
     * @param FeedItem $feedItemModel
     * @param Feed $feed
     * @param array $data
     * @return void
     */
    protected function createFeedItem(FeedItem $feedItemModel, Feed $feed, array $data): void
    {
        $feedItem = clone $feedItemModel;
        
        $feedItem->setData([
            FeedItem::fields_FEED_ID => $feed->getId(),
            FeedItem::fields_ITEM_TYPE => $data['item_type'],
            FeedItem::fields_ITEM_ID => $data['item_id'],
            FeedItem::fields_TITLE => $data['title'] ?? '',
            FeedItem::fields_CONTENT => $data['content'] ?? '',
            FeedItem::fields_URL => $data['url'] ?? '',
            FeedItem::fields_METADATA => json_encode($data['metadata'] ?? []),
            FeedItem::fields_IS_PUBLISHED => $data['is_published'] ?? 1,
            FeedItem::fields_PUBLISHED_AT => $data['published_at'] ?? time(),
        ]);

        $feedItem->save();

        // 如果Feed配置了实时推送，入队推送任务
        if ($feed->isAutoPush() && $feed->getData(Feed::fields_UPDATE_FREQUENCY) === Feed::FREQUENCY_REALTIME) {
            $this->enqueueAutoPush($feed);
        }
    }

    /**
     * 更新Feed条目
     * 
     * @param FeedItem $feedItem
     * @param array $data
     * @return void
     */
    protected function updateFeedItem(FeedItem $feedItem, array $data): void
    {
        // 更新字段（只更新提供的字段）
        if (isset($data['title'])) {
            $feedItem->setData(FeedItem::fields_TITLE, $data['title']);
        }
        if (isset($data['content'])) {
            $feedItem->setData(FeedItem::fields_CONTENT, $data['content']);
        }
        if (isset($data['url'])) {
            $feedItem->setData(FeedItem::fields_URL, $data['url']);
        }
        if (isset($data['metadata'])) {
            $feedItem->setData(FeedItem::fields_METADATA, json_encode($data['metadata']));
        }
        if (isset($data['is_published'])) {
            $feedItem->setData(FeedItem::fields_IS_PUBLISHED, $data['is_published']);
        }
        if (isset($data['published_at'])) {
            $feedItem->setData(FeedItem::fields_PUBLISHED_AT, $data['published_at']);
        }

        $feedItem->save();

        // 如果Feed配置了实时推送，入队推送任务
        /** @var Feed $feedModel */
        $feedModel = ObjectManager::getInstance(Feed::class);
        $feed = $feedModel->load($feedItem->getData(FeedItem::fields_FEED_ID));
        
        if ($feed->isAutoPush() && $feed->getData(Feed::fields_UPDATE_FREQUENCY) === Feed::FREQUENCY_REALTIME) {
            $this->enqueueAutoPush($feed);
        }
    }

    /**
     * 入队自动推送任务
     * 
     * @param Feed $feed
     * @return void
     */
    protected function enqueueAutoPush(Feed $feed): void
    {
        try {
            /** @var FeedQueueService $queueService */
            $queueService = ObjectManager::getInstance(FeedQueueService::class);
            
            // 入队推送任务（空数组表示所有平台）
            $queueService->enqueueFeedPush($feed->getId(), [], \Weline\GenerativeEngineOptimization\Model\PushLog::TYPE_AUTO);
        } catch (\Exception $e) {
            w_log_error('Enqueue auto push error: ' . $e->getMessage());
        }
    }
}

