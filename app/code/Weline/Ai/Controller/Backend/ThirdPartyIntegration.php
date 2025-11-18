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
 * 第三方集成管理控制器
 * 
 * 功能：
 * - 第三方服务集成配置
 * - 集成状态监控
 * - API连接管理
 */
#[Acl('Weline_Ai::ai_third_party_integration', '第三方集成', 'mdi-connection', '第三方集成', 'Weline_Ai::ai')]
class ThirdPartyIntegration extends BackendController
{
    /**
     * 第三方集成列表页面
     * 
     * @return string
     */
    #[Acl('Weline_Ai::ai_third_party_integration_list', '查看第三方集成', 'mdi-view-list', '查看第三方集成')]
    public function index(): string
    {
        try {
            // TODO: 获取集成列表
            $integrations = [];
            
            $this->assign('integrations', $integrations);
            
            // 统计
            $stats = [
                'total_integrations' => count($integrations),
                'active_integrations' => 0,
                'inactive_integrations' => 0,
            ];
            $this->assign('stats', $stats);

            return $this->fetch();

        } catch (\Exception $e) {
            Message::error(__('加载第三方集成失败：%{1}', $e->getMessage()));
            $this->assign('integrations', []);
            $this->assign('stats', ['total_integrations' => 0, 'active_integrations' => 0, 'inactive_integrations' => 0]);
            return $this->fetch();
        }
    }
}
