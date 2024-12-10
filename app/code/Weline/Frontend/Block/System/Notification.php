<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Block\System;

use Weline\Admin\Model\System\SystemNotification;
use Weline\Frontend\Cache\FrontendCache;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;

class Notification extends \Weline\Framework\View\Block
{
    private CacheInterface $cache;

    public function __construct(FrontendCache $backendCache, array $data = [])
    {
        $this->cache = $backendCache->create();
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
        /**@var SystemNotification $notificationsModel */
        $notificationsModel = ObjectManager::getInstance(SystemNotification::class);
        //        $this->cache->set($cache_key, $notices);
        return $notificationsModel->where(SystemNotification::fields_is_read, false)->select()->fetch();
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
//        $total = $notificationsModel->where(FrontendNotification::fields_is_read, false)->total();
//        $this->cache->set($cache_key, $total);
//        return $total;
//    }
}
