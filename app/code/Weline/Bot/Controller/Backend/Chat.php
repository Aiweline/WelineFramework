<?php
declare(strict_types=1);

namespace Weline\Bot\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Bot\Model\BotRole;
use Weline\Bot\Service\ChatSessionManager;

/**
 * 聊天控制台控制器
 */
#[Acl('Weline_Bot::console', '聊天控制台', '与 AI 智能体对话', '')]
class Chat extends BackendController
{
    public function __construct(
        private readonly BotRole $roleModel,
        private readonly ChatSessionManager $sessionManager,
    ) {}

    #[Acl('Weline_Bot::console_index', '聊天界面', '', '访问聊天控制台')]
    public function getIndex()
    {
        // 获取可用角色列表
        $roles = $this->roleModel->reset()
            ->where(BotRole::schema_fields_STATUS, BotRole::STATUS_ENABLED)
            ->order(BotRole::schema_fields_IS_DEFAULT, 'DESC')
            ->select()
            ->fetch();

        $this->assign('roles', $roles->getItems());
        return $this->fetch();
    }
}
