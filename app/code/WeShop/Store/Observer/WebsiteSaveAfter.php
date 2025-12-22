<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：网站保存后观察者 - 可在此处理店铺与网站的关联逻辑
 */

namespace WeShop\Store\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Cache\CacheFactory;

class WebsiteSaveAfter
{
    /**
     * 网站保存后执行 - 可用于清理店铺相关缓存
     * @param Event $event
     */
    public function execute(Event $event): void
    {
        $data = $event->getData();
        $websiteId = $data['website_id'] ?? 0;

        if (!$websiteId) {
            return;
        }

        // 可在此处清理店铺相关的缓存
        // 例如：清理店铺列表缓存、店铺网站关联缓存等

        // 如果有缓存需要清理，可以这样做：
        // $cacheFactory = ObjectManager::getInstance(CacheFactory::class);
        // $cache = $cacheFactory->create();
        // $cache->delete('store_list_by_website_' . $websiteId);
    }
}

