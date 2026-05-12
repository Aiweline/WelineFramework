<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Feed事件分发服务
 * 
 * 提供便捷方法触发Feed相关事件
 * 
 * @package Weline_Geo
 */
class FeedEventDispatcher
{
    /**
     * 事件管理器
     */
    protected EventsManager $eventsManager;

    public function __construct()
    {
        $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
    }

    /**
     * 触发添加Feed条目事件
     * 
     * @param int $feedId Feed ID
     * @param string $itemType 条目类型（如：article, product, page等）
     * @param int $itemId 条目ID（源数据ID）
     * @param array $itemData 条目数据
     * @return void
     */
    public function dispatchFeedItemAdd(int $feedId, string $itemType, int $itemId, array $itemData = []): void
    {
        $data = array_merge([
            'feed_id' => $feedId,
            'item_type' => $itemType,
            'item_id' => $itemId,
        ], $itemData);

        $this->eventsManager->dispatch('Weline_Geo::feed_item_add', $data);
    }

    /**
     * 触发更新Feed条目事件
     * 
     * @param int $feedId Feed ID
     * @param string $itemType 条目类型
     * @param int $itemId 条目ID
     * @param array $itemData 条目数据
     * @return void
     */
    public function dispatchFeedItemUpdate(int $feedId, string $itemType, int $itemId, array $itemData = []): void
    {
        $data = array_merge([
            'feed_id' => $feedId,
            'item_type' => $itemType,
            'item_id' => $itemId,
        ], $itemData);

        $this->eventsManager->dispatch('Weline_Geo::feed_item_update', $data);
    }

    /**
     * 触发删除Feed条目事件
     * 
     * @param int $feedId Feed ID
     * @param string $itemType 条目类型
     * @param int $itemId 条目ID
     * @return void
     */
    public function dispatchFeedItemDelete(int $feedId, string $itemType, int $itemId): void
    {
        $data = [
            'feed_id' => $feedId,
            'item_type' => $itemType,
            'item_id' => $itemId,
        ];

        $this->eventsManager->dispatch('Weline_Geo::feed_item_delete', $data);
    }

    /**
     * 批量触发添加Feed条目事件（多个Feed）
     * 
     * @param array $feedIds Feed ID数组
     * @param string $itemType 条目类型
     * @param int $itemId 条目ID
     * @param array $itemData 条目数据
     * @return void
     */
    public function dispatchFeedItemAddToFeeds(array $feedIds, string $itemType, int $itemId, array $itemData = []): void
    {
        foreach ($feedIds as $feedId) {
            $this->dispatchFeedItemAdd((int)$feedId, $itemType, $itemId, $itemData);
        }
    }

    /**
     * 批量触发更新Feed条目事件（多个Feed）
     * 
     * @param array $feedIds Feed ID数组
     * @param string $itemType 条目类型
     * @param int $itemId 条目ID
     * @param array $itemData 条目数据
     * @return void
     */
    public function dispatchFeedItemUpdateToFeeds(array $feedIds, string $itemType, int $itemId, array $itemData = []): void
    {
        foreach ($feedIds as $feedId) {
            $this->dispatchFeedItemUpdate((int)$feedId, $itemType, $itemId, $itemData);
        }
    }

    /**
     * 批量触发删除Feed条目事件（多个Feed）
     * 
     * @param array $feedIds Feed ID数组
     * @param string $itemType 条目类型
     * @param int $itemId 条目ID
     * @return void
     */
    public function dispatchFeedItemDeleteFromFeeds(array $feedIds, string $itemType, int $itemId): void
    {
        foreach ($feedIds as $feedId) {
            $this->dispatchFeedItemDelete((int)$feedId, $itemType, $itemId);
        }
    }
}

