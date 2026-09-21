<?php
declare(strict_types=1);

namespace Weline\Agent\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Agent\Model\AgentChatSession;
use Weline\Agent\Service\AgentAdminService;
use Weline\Agent\Service\ChatSessionManager;

/**
 * 会话管理控制器
 */
#[Acl('Weline_Agent::session', '会话管理', '管理 AI 对话会话', '')]
class Session extends BackendController
{
    use AgentHtmlSafeMutationTrait;

    public function __construct(
        private readonly AgentChatSession $sessionModel,
        private readonly ChatSessionManager $sessionManager,
        private readonly AgentAdminService $adminService,
    ) {}

    #[Acl('Weline_Agent::session_list', '会话列表', '', '查看会话列表')]
    public function getList()
    {
        $status = $this->request->getParam('status', '');
        $channel = $this->request->getParam('channel', '');

        $sessions = $this->sessionModel->reset();

        if ($status) {
            $sessions->where(AgentChatSession::schema_fields_STATUS, $status);
        }
        if ($channel) {
            $sessions->where(AgentChatSession::schema_fields_CHANNEL, $channel);
        }

        $sessions->order(AgentChatSession::schema_fields_UPDATED_AT, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('sessions', $sessions->getItems());
        $this->assign('pagination', $sessions->getPagination());
        $this->assign('current_status', $status);
        $this->assign('current_channel', $channel);
        return $this->fetch();
    }

    #[Acl('Weline_Agent::session_listing', '会话列表', '', '查看会话列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Agent::session_view', '查看会话', '', '查看会话详情')]
    public function getView()
    {
        $id = (int) $this->request->getParam('id', 0);
        $session = $this->sessionModel->load($id);

        if (!$session->getId()) {
            $this->getMessageManager()->addError(__('会话不存在'));
            return $this->redirect('*/backend/session/listing');
        }

        $messages = $this->sessionManager->getMessageHistory($session, 100);

        $this->assign('session', $session);
        $this->assign('messages', $messages);
        return $this->fetch('view');
    }

    #[Acl('Weline_Agent::session_archive', '归档会话', '', '归档会话')]
    public function postArchive()
    {
        $result = $this->adminService->archiveSession((int) $this->request->getParam('id', 0));
        return $this->respondMutation($result, '*/backend/session/listing');
    }

    #[Acl('Weline_Agent::session_delete', '删除会话', '', '删除会话')]
    public function postDelete()
    {
        $result = $this->adminService->deleteSession((int) $this->request->getParam('id', 0));
        return $this->respondMutation($result, '*/backend/session/listing');
    }
}
