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
use Weline\Shipping\Model\RateTemplate as RateTemplateModel;
use Weline\Shipping\Service\ShippingConfigurationAdminService;

#[Acl('Weline_Shipping::rate_template', '费用模板管理', 'mdi-calculator', '费用模板管理', 'Weline_Backend::shipping_group')]
class RateTemplate extends BackendController
{
    private RateTemplateModel $rateTemplate;
    private ShippingConfigurationAdminService $adminService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->rateTemplate = $objectManager->getInstance(RateTemplateModel::class);
        $this->adminService = $objectManager->getInstance(ShippingConfigurationAdminService::class);
    }

    /**
     * 费用模板列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::rate_template_index', '查看费用模板', 'mdi-format-list-bulleted', '查看费用模板列表')]
    public function index()
    {
        $templates = $this->rateTemplate->reset()
            ->order(RateTemplateModel::schema_fields_ID, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('templates', $templates);
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::rate_template_save', '保存费用模板', 'mdi-content-save', '创建费用模板')]
    public function save()
    {
        try {
            if (!$this->request->isPost()) throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            $this->adminService->createRateTemplate((array)$this->request->getPost());
            $this->getMessageManager()->addSuccess(__('费用模板创建成功。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }
        return $this->redirect('shipping/backend/ratetemplate/index');
    }
}

