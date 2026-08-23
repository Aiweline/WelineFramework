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
use Weline\Shipping\Service\ShippingConfigurationAdminService;

#[Acl('Weline_Shipping::zone', '配送区域管理', 'circle', '配送区域管理', 'Weline_Backend::shipping_group')]
class Zone extends BackendController
{
    private ZoneModel $zone;
    private ShippingConfigurationAdminService $adminService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->zone = $objectManager->getInstance(ZoneModel::class);
        $this->adminService = $objectManager->getInstance(ShippingConfigurationAdminService::class);
    }

    /**
     * 配送区域列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::zone_index', '查看配送区域', 'list', '查看配送区域列表')]
    public function index()
    {
        $zones = $this->zone->reset()
            ->order(ZoneModel::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('zones', $zones);
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::zone_save', '保存配送区域', 'save', '创建配送区域')]
    public function save()
    {
        try {
            if (!$this->request->isPost()) throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            $this->adminService->createZone((array)$this->request->getPost());
            $this->getMessageManager()->addSuccess(__('配送区域创建成功。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }
        return $this->redirect('shipping/backend/zone/index');
    }
}

