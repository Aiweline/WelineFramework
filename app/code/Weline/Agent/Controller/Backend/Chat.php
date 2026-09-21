<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Agent\Model\AgentRole;
use Weline\Agent\Model\AgentChatSession;

/**
 * 聊天控制台控制器
 */
#[Acl('Weline_Agent::console', '聊天控制台', '与 AI 智能体对话', '')]
class Chat extends BackendController
{
    public function __construct(
        private readonly AgentRole $roleModel,
        private readonly AgentChatSession $sessionModel,
    ) {}

    #[Acl('Weline_Agent::console_index', '聊天界面', '', '访问聊天控制台')]
    public function getIndex()
    {
        $roles = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
            ->order(AgentRole::schema_fields_IS_DEFAULT, 'DESC')
            ->select()
            ->fetch();

        $recentSessions = $this->sessionModel->reset()
            ->where(AgentChatSession::schema_fields_STATUS, AgentChatSession::STATUS_ACTIVE)
            ->where(AgentChatSession::schema_fields_CHANNEL, AgentChatSession::CHANNEL_WEB)
            ->order(AgentChatSession::schema_fields_UPDATED_AT, 'DESC')
            ->limit(12)
            ->select()
            ->fetch();

        $this->assign('roles', $roles->getItems());
        $this->assign('recent_sessions', $recentSessions->getItems());
        return $this->fetch();
    }
}
