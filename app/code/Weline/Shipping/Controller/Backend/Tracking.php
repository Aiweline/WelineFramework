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
use Weline\Shipping\Model\Tracking as TrackingModel;

#[Acl('Weline_Shipping::tracking', '物流跟踪管理', 'mdi-map-search', '物流跟踪管理', 'Weline_Backend::business_module')]
class Tracking extends BackendController
{
    private TrackingModel $tracking;

    public function __construct(ObjectManager $objectManager)
    {
        $this->tracking = $objectManager->getInstance(TrackingModel::class);
    }

    /**
     * 物流跟踪记录列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::tracking_index', '查看物流跟踪', 'mdi-format-list-bulleted', '查看物流跟踪记录')]
    public function index()
    {
        $records = $this->tracking->reset()
            ->order(TrackingModel::fields_CREATED_AT, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('records', $records);

        return $this->fetch();
    }
}


