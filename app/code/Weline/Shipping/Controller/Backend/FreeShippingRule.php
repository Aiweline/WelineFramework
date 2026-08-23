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
use Weline\Shipping\Model\FreeShippingRule as FreeShippingRuleModel;
use Weline\Shipping\Service\ShippingConfigurationAdminService;

#[Acl('Weline_Shipping::free_shipping_rule', '免邮规则管理', 'circle', '免邮规则管理', 'Weline_Backend::shipping_group')]
class FreeShippingRule extends BackendController
{
    private FreeShippingRuleModel $rule;
    private ShippingConfigurationAdminService $adminService;

    public function __construct(ObjectManager $objectManager)
    {
        $this->rule = $objectManager->getInstance(FreeShippingRuleModel::class);
        $this->adminService = $objectManager->getInstance(ShippingConfigurationAdminService::class);
    }

    /**
     * 免邮规则列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::free_shipping_rule_index', '查看免邮规则', 'list', '查看免邮规则列表')]
    public function index()
    {
        $rules = $this->rule->reset()
            ->order(FreeShippingRuleModel::schema_fields_PRIORITY, 'DESC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('rules', $rules);
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));

        return $this->fetch();
    }

    #[Acl('Weline_Shipping::free_shipping_rule_save', '保存免邮规则', 'save', '创建免邮规则')]
    public function save()
    {
        try {
            if (!$this->request->isPost()) throw new \InvalidArgumentException((string)__('仅允许 POST 请求。'));
            $this->adminService->createFreeShippingRule((array)$this->request->getPost());
            $this->getMessageManager()->addSuccess(__('免邮规则创建成功。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }
        return $this->redirect('shipping/backend/freeshippingrule/index');
    }
}

