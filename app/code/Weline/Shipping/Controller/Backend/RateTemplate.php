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

#[Acl('Weline_Shipping::rate_template', '费用模板管理', 'mdi-calculator', '费用模板管理', 'Weline_Backend::business_module')]
class RateTemplate extends BackendController
{
    private RateTemplateModel $rateTemplate;

    public function __construct(ObjectManager $objectManager)
    {
        $this->rateTemplate = $objectManager->getInstance(RateTemplateModel::class);
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
}


