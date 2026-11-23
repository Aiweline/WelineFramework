<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Frontend;

use Weline\Agent\Model\AgentProject;
use Weline\Agent\Model\AgentRole;
use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;

/**
 * 通用智能体聊天页（前台）
 *
 * 独立全屏应用文档（Claude/ChatGPT 式）：会话列表 + 角色/模型/项目切换 +
 * agent.chat_turn 可恢复任务流式输出。不走 theme layout，无店面 chrome。
 * @Extra type=fpc enabled=false
 */
class Chat extends FrontendController
{
    public function __construct(
        private readonly AgentRole $roleModel,
        private readonly AiModel $aiModel,
        private readonly AgentProject $projectModel,
    ) {}

    public function index(): Response
    {
        $roles = $this->roleModel->reset()
            ->where(AgentRole::schema_fields_STATUS, AgentRole::STATUS_ENABLED)
            ->order(AgentRole::schema_fields_IS_DEFAULT, 'DESC')
            ->order(AgentRole::schema_fields_ROLE_ID, 'ASC')
            ->limit(100)
            ->select()
            ->fetchArray();

        $models = $this->aiModel->reset()
            ->where(AiModel::schema_fields_IS_ACTIVE, 1)
            ->where(AiModel::schema_fields_PRIMARY_MODALITY, AiModel::PRIMARY_MODALITY_TEXT_TO_TEXT)
            ->order(AiModel::schema_fields_IS_DEFAULT, 'DESC')
            ->order(AiModel::schema_fields_ID, 'ASC')
            ->limit(100)
            ->select()
            ->fetchArray();

        $projects = $this->projectModel->reset()
            ->where(AgentProject::schema_fields_STATUS, AgentProject::STATUS_ACTIVE)
            ->order(AgentProject::schema_fields_PROJECT_ID, 'ASC')
            ->limit(100)
            ->select()
            ->fetchArray();

        // 模板经 raw include 取用本作用域变量（同 App\\Frontend\\Download 契约）
        $is_logged_in = $this->isLoggedIn();

        \ob_start();
        include BP . 'app/code/Weline/Agent/view/templates/frontend/chat/index.phtml';

        $response = Response::text((string)\ob_get_clean(), 200, 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store, max-age=0');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
