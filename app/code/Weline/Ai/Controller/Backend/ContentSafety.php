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
 * 内容安全管理控制器
 * 
 * 功能：
 * - 内容审核规则配置
 * - 违规内容记录
 * - 审核日志
 */
#[Acl('Weline_Ai::ai_content_safety', '内容安全', 'mdi-shield-alert', '内容安全', 'Weline_Ai::ai')]
class ContentSafety extends BackendController
{
    /**
     * 内容安全列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_content_safety_list', '查看内容安全', 'mdi-view-list', '查看内容安全')]
    public function index(): string
    {
        try {
            // TODO: 获取内容审核记录
            $records = [];
            
            $this->assign('records', $records);
            
            // 统计
            $stats = [
                'total_checked' => 0,
                'violations' => 0,
                'safe' => 0,
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载内容安全失败：%{1}', $e->getMessage()));
            $this->assign('records', []);
            $this->assign('stats', ['total_checked' => 0, 'violations' => 0, 'safe' => 0]);
            return $this->fetch();
        }
    }
}
