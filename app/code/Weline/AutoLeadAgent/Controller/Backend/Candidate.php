<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\AutoLeadAgent\Model\LeadCandidate;
use Weline\Framework\Manager\ObjectManager;

/**
 * 潜在客户管理控制器
 */
#[Acl(
    'Weline_AutoLeadAgent::candidate',
    '潜在客户管理',
    'mdi-account-group',
    '管理自动寻客发现的潜在客户',
    'Weline_AutoLeadAgent::auto_lead_agent'
)]
class Candidate extends BackendController
{
    /**
     * 潜在客户列表
     */
    #[Acl(
        'Weline_AutoLeadAgent::candidate_index',
        '查看潜在客户',
        'mdi-format-list-bulleted',
        '查看潜在客户列表'
    )]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $limit = (int)($this->request->getParam('limit') ?? 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;

        /** @var LeadCandidate $candidateModel */
        $candidateModel = ObjectManager::getInstance(LeadCandidate::class);

        // 获取总数
        $total = $candidateModel->clear()->count();
        $totalPages = (int)ceil($total / $limit);

        // 分页查询
        $offset = ($page - 1) * $limit;
        $candidates = $candidateModel->clear()
            ->order(LeadCandidate::fields_SCORE, 'DESC')
            ->order(LeadCandidate::fields_CREATED_AT, 'DESC')
            ->limit($limit, $offset)
            ->fetch()
            ->getItems();

        $this->assign('candidates', $candidates);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total_pages', $totalPages);

        return $this->fetch();
    }
}

