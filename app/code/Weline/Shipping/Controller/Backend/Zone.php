<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Zone as ZoneModel;

#[Acl('Weline_Shipping::zone', '配送区域管理', 'mdi-map', '配送区域管理', 'Weline_Backend::business_module')]
class Zone extends BackendController
{
    private ZoneModel $zone;

    public function __construct(ObjectManager $objectManager)
    {
        $this->zone = $objectManager->getInstance(ZoneModel::class);
    }

    /**
     * 配送区域列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::zone_index', '查看配送区域', 'mdi-format-list-bulleted', '查看配送区域列表')]
    public function index()
    {
        $zones = $this->zone->reset()
            ->order(ZoneModel::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('zones', $zones);

        return $this->fetch();
    }
}


