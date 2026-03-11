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

#[Acl('Weline_Shipping::free_shipping_rule', '免邮规则管理', 'mdi-gift', '免邮规则管理', 'Weline_Backend::shipping_group')]
class FreeShippingRule extends BackendController
{
    private FreeShippingRuleModel $rule;

    public function __construct(ObjectManager $objectManager)
    {
        $this->rule = $objectManager->getInstance(FreeShippingRuleModel::class);
    }

    /**
     * 免邮规则列表页（占位实现，保证页面可用）
     */
    #[Acl('Weline_Shipping::free_shipping_rule_index', '查看免邮规则', 'mdi-format-list-bulleted', '查看免邮规则列表')]
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
}


