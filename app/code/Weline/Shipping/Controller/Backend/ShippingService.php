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
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\Zone;
use Weline\Shipping\Service\ShippingConfigurationAdminService;

#[Acl('Weline_Shipping::shipping_service', '配送服务管理', 'truck', '配送服务管理', 'Weline_Backend::shipping_group')]
class ShippingService extends BackendController
{
    private ShippingServiceModel $service;
    private ShippingConfigurationAdminService $adminService;
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
        $this->service = $objectManager->getInstance(ShippingServiceModel::class);
        $this->adminService = $objectManager->getInstance(ShippingConfigurationAdminService::class);
    }

    /**
     * 配送服务列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::shipping_service_index', '查看配送服务', 'list', '查看配送服务列表')]
    public function index()
    {
        $services = $this->service->reset()
            ->order(ShippingServiceModel::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('services', $services);
        $this->assign('carriers', $this->objectManager->getInstance(Carrier::class, [], false)->reset()->order(Carrier::schema_fields_CARRIER_NAME, 'ASC')->select()->fetch()->getItems());
        $this->assign('zones', $this->objectManager->getInstance(Zone::class, [], false)->reset()->order(Zone::schema_fields_ZONE_NAME, 'ASC')->select()->fetch()->getItems());
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::shipping_service_save', '保存配送服务', 'save', '创建配送服务')]
    public function save()
    {
        try {
            if (!$this->request->isPost()) throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            $this->adminService->createShippingService((array)$this->request->getPost());
            $this->getMessageManager()->addSuccess(__('配送服务创建成功。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }
        return $this->redirect('shipping/backend/shippingservice/index');
    }
}

