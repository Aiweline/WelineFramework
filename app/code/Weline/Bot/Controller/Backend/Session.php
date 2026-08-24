<?php
declare(strict_types=1);

namespace Weline\Bot\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Bot\Model\BotChatSession;
use Weline\Bot\Service\ChatSessionManager;

/**
 * 会话管理控制器
 */
#[Acl('Weline_Bot::session', '会话管理', '管理 AI 对话会话', '')]
class Session extends BackendController
{
    public function __construct(
        private readonly BotChatSession $sessionModel,
        private readonly ChatSessionManager $sessionManager,
    ) {}

    #[Acl('Weline_Bot::session_list', '会话列表', '', '查看会话列表')]
    public function getList()
    {
        $status = $this->request->getParam('status', '');
        $channel = $this->request->getParam('channel', '');

        $sessions = $this->sessionModel->reset();

        if ($status) {
            $sessions->where(BotChatSession::schema_fields_STATUS, $status);
        }
        if ($channel) {
            $sessions->where(BotChatSession::schema_fields_CHANNEL, $channel);
        }

        $sessions->order(BotChatSession::schema_fields_UPDATED_AT, 'DESC')
            ->pagination()
            ->select()
            ->fetch();

        $this->assign('sessions', $sessions->getItems());
        $this->assign('pagination', $sessions->getPagination());
        $this->assign('current_status', $status);
        $this->assign('current_channel', $channel);
        return $this->fetch();
    }

    #[Acl('Weline_Bot::session_listing', '会话列表', '', '查看会话列表')]
    public function listing()
    {
        return $this->getList();
    }

    #[Acl('Weline_Bot::session_view', '查看会话', '', '查看会话详情')]
    public function getView()
    {
        $id = (int) $this->request->getParam('id', 0);
        $session = $this->sessionModel->load($id);

        if (!$session->getId()) {
            $this->getSession()->addError(__('会话不存在'));
            return $this->redirect('*/*/listing');
        }

        $messages = $this->sessionManager->getMessageHistory($session, 100);

        $this->assign('session', $session);
        $this->assign('messages', $messages);
        return $this->fetch('view');
    }

    #[Acl('Weline_Bot::session_archive', '归档会话', '', '归档会话')]
    public function postArchive()
    {
        $id = (int) $this->request->getParam('id', 0);
        $session = $this->sessionModel->load($id);

        if (!$session->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('会话不存在'),
            ]);
        }

        $this->sessionManager->archiveSession($session);

        return $this->fetchJson([
            'success' => true,
            'msg' => __('已归档'),
        ]);
    }

    #[Acl('Weline_Bot::session_delete', '删除会话', '', '删除会话')]
    public function postDelete()
    {
        $id = (int) $this->request->getParam('id', 0);
        $session = $this->sessionModel->load($id);

        if (!$session->getId()) {
            return $this->fetchJson([
                'success' => false,
                'msg' => __('会话不存在'),
            ]);
        }

        $this->sessionManager->deleteSession($session);

        return $this->fetchJson([
            'success' => true,
            'msg' => __('已删除'),
        ]);
    }
}
