<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Acl\Acl;

/**
 * 客户支持管理控制器
 * 
 * 功能：
 * - 工单管理
 * - 客服助手配置
 * - 支持统计
 */
#[Acl('Weline_Ai::ai_customer_support', '客户支持', 'mdi-headset', '客户支持', 'Weline_Ai::ai')]
class CustomerSupport extends BackendController
{
    /**
     * 客户支持列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_customer_support_list', '查看客户支持', 'mdi-view-list', '查看客户支持')]
    public function index(): string
    {
        try {
            // TODO: 获取工单列表
            $tickets = [];
            
            $this->assign('tickets', $tickets);
            
            // 统计
            $stats = [
                'total_tickets' => count($tickets),
                'open_tickets' => 0,
                'resolved_tickets' => 0,
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载客户支持失败：%{1}', $e->getMessage()));
            $this->assign('tickets', []);
            $this->assign('stats', ['total_tickets' => 0, 'open_tickets' => 0, 'resolved_tickets' => 0]);
            return $this->fetch();
        }
    }
}
