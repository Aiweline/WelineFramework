<?php
declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

/**
 * AI 管理聚合页
 *
 * 模型 | 适配器 | 供应商账户 三个 Tab，URL 持久化 ?tab=model/adapter/account
 *
 * @package Weline_Ai
 */
#[Acl('Weline_Ai::ai_manager', 'AI管理', 'mdi-robot-outline', 'AI管理中心', 'Weline_Backend::ai_group')]
class Manager extends BackendController
{
    /**
     * 聚合入口：按 tab 重定向到模型/适配器/供应商页（无 iframe，统一 Tab 布局）
     */
    #[Acl('Weline_Ai::ai_manager_index', '查看AI管理', 'mdi-view-dashboard', '查看AI管理聚合页')]
    public function index()
    {
        $tab = $this->request->getGet('tab', 'model');
        $map = [
            'model' => 'ai/backend/model',
            'adapter' => 'ai/backend/adapter',
            'skill' => 'ai/backend/skill',
            'account' => 'ai/backend/provider',
        ];
        $path = $map[$tab] ?? $map['model'];
        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl($path));
    }
}
