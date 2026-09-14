<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Carrier;
use Weline\Shipping\Model\FreeShippingRule;
use Weline\Shipping\Model\RateTemplate;
use Weline\Shipping\Model\ShippingAddress;
use Weline\Shipping\Model\ShippingService as ShippingServiceModel;
use Weline\Shipping\Service\ServiceLaneAdminService;
use Weline\Shipping\Service\ShippingConfigurationAdminService;

#[Acl('Weline_Shipping::shipping_service', '配送服务管理', 'truck', '配送服务管理', 'Weline_Backend::shipping_group')]
class ShippingService extends BackendController
{
    use ShippingBackendEmbedTrait;
    use ShippingBackendScopeTrait;

    private ShippingServiceModel $service;
    private ShippingConfigurationAdminService $adminService;
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
        $this->service = $objectManager->getInstance(ShippingServiceModel::class);
        $this->adminService = $objectManager->getInstance(ShippingConfigurationAdminService::class);
    }

    #[Acl('Weline_Shipping::shipping_service_index', '查看配送服务', 'list', '查看配送服务列表')]
    public function index()
    {
        if ($redirect = $this->redirectUnlessShippingScopeExplicit('shipping/backend/shippingservice/index')) {
            return $redirect;
        }
        $target = $this->assignShippingWorkScope(false);

        $query = $this->service->reset()
            ->where(ShippingServiceModel::schema_fields_SCOPE_TYPE, $target['scope_type'])
            ->where(ShippingServiceModel::schema_fields_SCOPE_ID, $target['scope_id'])
            ->order(ShippingServiceModel::schema_fields_SORT_ORDER, 'ASC');
        $services = $query->select()->fetch()->getItems();

        /** @var ServiceLaneAdminService $laneAdmin */
        $laneAdmin = $this->objectManager->getInstance(ServiceLaneAdminService::class);
        $laneLabels = [];
        foreach ($services as $svc) {
            if (!$svc instanceof ShippingServiceModel) {
                continue;
            }
            $sid = (int)$svc->getId();
            $laneLabels[$sid] = $laneAdmin->formatRowLabels($laneAdmin->listForService($sid));
        }

        $this->assign('services', $services);
        $this->assign('lane_labels_by_service', $laneLabels);
        $this->assign(
            'carriers',
            $this->objectManager->getInstance(Carrier::class, [], false)
                ->reset()
                ->order(Carrier::schema_fields_CARRIER_NAME, 'ASC')
                ->select()
                ->fetch()
                ->getItems(),
        );
        $this->assign(
            'templates',
            $this->objectManager->getInstance(RateTemplate::class, [], false)
                ->reset()
                ->where(RateTemplate::schema_fields_SCOPE_TYPE, $target['scope_type'])
                ->where(RateTemplate::schema_fields_SCOPE_ID, $target['scope_id'])
                ->order(RateTemplate::schema_fields_TEMPLATE_NAME, 'ASC')
                ->select()
                ->fetch()
                ->getItems(),
        );
        $this->assign(
            'free_rules',
            $this->objectManager->getInstance(FreeShippingRule::class, [], false)
                ->reset()
                ->where(FreeShippingRule::schema_fields_SCOPE_TYPE, $target['scope_type'])
                ->where(FreeShippingRule::schema_fields_SCOPE_ID, $target['scope_id'])
                ->order(FreeShippingRule::schema_fields_PRIORITY, 'ASC')
                ->select()
                ->fetch()
                ->getItems(),
        );
        $this->assign(
            'origins',
            $this->objectManager->getInstance(ShippingAddress::class, [], false)
                ->reset()
                ->where(ShippingAddress::schema_fields_IS_ENABLED, 1)
                ->order(ShippingAddress::schema_fields_IS_DEFAULT, 'DESC')
                ->select()
                ->fetch()
                ->getItems(),
        );
        $this->assignShippingEmbedLayout();

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::shipping_service_save', '保存配送服务', 'save', '创建配送服务')]
    public function save()
    {
        $target = $this->assignShippingWorkScope(true);
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $post = (array)$this->request->getPost();
            $post['scope_type'] = $target['scope_type'];
            $post['scope_id'] = $target['scope_id'];
            $post['target_scope'] = $target['storage_scope'];
            $this->adminService->createShippingService($post);
            $this->getMessageManager()->addSuccess(__('配送航线创建成功。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/shippingservice/index', $this->shippingScopeQuery($target));
    }

    #[Acl('Weline_Shipping::shipping_service_save', '更新贸易术语', 'save', '更新配送航线 Incoterm')]
    public function saveIncoterm()
    {
        $target = $this->assignShippingWorkScope(true);
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $post = (array)$this->request->getPost();
            $post['scope_type'] = $target['scope_type'];
            $post['scope_id'] = $target['scope_id'];
            $post['target_scope'] = $target['storage_scope'];
            $this->adminService->updateShippingServiceIncoterm($post);
            $this->getMessageManager()->addSuccess(__('贸易术语已更新。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/shippingservice/index', $this->shippingScopeQuery($target));
    }

    #[Acl('Weline_Shipping::shipping_service_save', '绑定仓发货地址', 'save', '绑定仓库与发货地址')]
    public function bindWarehouseOrigin()
    {
        $target = $this->assignShippingWorkScope(true);
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $websiteId = (int)$this->request->getPost('website_id');
            $warehouseId = (int)$this->request->getPost('warehouse_id');
            $addressId = (int)$this->request->getPost('shipping_address_id');
            /** @var \Weline\Shipping\Api\WarehouseShippingOriginInterface $origins */
            $origins = $this->objectManager->getInstance(\Weline\Shipping\Api\WarehouseShippingOriginInterface::class);
            $origins->bind($websiteId, $warehouseId, $addressId, true);
            $this->getMessageManager()->addSuccess(__('仓与发货地址已绑定。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/shippingservice/index', $this->shippingScopeQuery($target));
    }

    #[Acl('Weline_Shipping::shipping_service_save', '复制默认站航线', 'save', '复制默认站航线到发货锚点')]
    public function copyDefaultLanes()
    {
        $target = $this->assignShippingWorkScope(true);
        try {
            if (!$this->request->isPost()) {
                throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            }
            $websiteId = (int)$this->request->getPost('website_id');
            $addressId = (int)$this->request->getPost('shipping_address_id');
            /** @var \Weline\Shipping\Service\WarehouseLaneCopyService $copier */
            $copier = $this->objectManager->getInstance(\Weline\Shipping\Service\WarehouseLaneCopyService::class);
            $n = $copier->copyDefaultLanesToOrigin($websiteId, $addressId);
            $this->getMessageManager()->addSuccess(__('已复制 %{1} 条默认站航线到目标发货地址。', [$n]));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('shipping/backend/shippingservice/index', $this->shippingScopeQuery($target));
    }
}
