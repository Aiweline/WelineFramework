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
use Weline\Shipping\Model\ShippingService as ShippingServiceModel;

#[Acl('Weline_Shipping::shipping_service', '配送服务管理', 'mdi-truck-fast', '配送服务管理', 'Weline_Backend::business_module')]
class ShippingService extends BackendController
{
    private ShippingServiceModel $service;

    public function __construct(ObjectManager $objectManager)
    {
        $this->service = $objectManager->getInstance(ShippingServiceModel::class);
    }

    /**
     * 配送服务列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::shipping_service_index', '查看配送服务', 'mdi-format-list-bulleted', '查看配送服务列表')]
    public function index()
    {
        $services = $this->service->reset()
            ->order(ShippingServiceModel::fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('services', $services);

        return $this->fetch();
    }
}


