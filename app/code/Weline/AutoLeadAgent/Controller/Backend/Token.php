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
use Weline\AutoLeadAgent\Model\AgentToken;
use Weline\Framework\Manager\ObjectManager;

/**
 * Token管理控制器
 */
#[Acl(
    'Weline_AutoLeadAgent::token',
    'Token管理',
    'mdi-key',
    '管理自动寻客Agent的Token',
    'Weline_AutoLeadAgent::auto_lead_agent'
)]
class Token extends BackendController
{
    /**
     * Token列表
     */
    #[Acl(
        'Weline_AutoLeadAgent::token_index',
        '查看Token',
        'mdi-format-list-bulleted',
        '查看Token列表'
    )]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $limit = (int)($this->request->getParam('limit') ?? 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;

        /** @var AgentToken $tokenModel */
        $tokenModel = ObjectManager::getInstance(AgentToken::class);

        // 获取总数
        $total = $tokenModel->clear()->count();
        $totalPages = (int)ceil($total / $limit);

        // 分页查询
        $offset = ($page - 1) * $limit;
        $tokens = $tokenModel->clear()
            ->order(AgentToken::schema_fields_CREATED_AT, 'DESC')
            ->limit($limit, $offset)
            ->fetch()
            ->getItems();

        $this->assign('tokens', $tokens);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total_pages', $totalPages);

        return $this->fetch();
    }
}

