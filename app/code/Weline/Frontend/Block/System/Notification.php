<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Block\System;

use Weline\Admin\Api\Notification\SystemNotificationDirectoryInterface;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;

class Notification extends \Weline\Framework\View\Block
{
    private CachePoolInterface $cache;

    public function __construct(array $data = [])
    {
        $this->cache = w_cache('default');
        parent::__construct($data);
    }

    public string $_template = 'Weline_Frontend::system/notification.phtml';

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/2/16 22:24
     * 参数区：
     * @return Notification []
     */
    public function getNotices(): array
    {
//        $cache_key = $this->cache->buildKey('backend_system_notice');
//        if ($notices = $this->cache->get($cache_key)) {
//            return $notices;
//        }
        /** @var SystemNotificationDirectoryInterface $directory */
        $directory = ObjectManager::getInstance(SystemNotificationDirectoryInterface::class);
        return $directory->listUnread();
    }
//
//    function getTotals()
//    {
//        $cache_key = $this->cache->buildKey('backend_system_notice_total');
//        if ($total = $this->cache->get($cache_key)) {
//            return $total;
//        }
//        /**@var FrontendNotification $notificationsModel */
//        $notificationsModel = ObjectManager::getInstance(FrontendNotification::class);
//        $total = $notificationsModel->where(FrontendNotification::schema_fields_is_read, false)->total();
//        $this->cache->set($cache_key, $total);
//        return $total;
//    }
}
