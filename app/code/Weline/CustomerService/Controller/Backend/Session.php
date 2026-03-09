<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Controller\Backend;

use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ChatSession;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

/**
 * 会话管理控制器
 */
#[Acl('Weline_CustomerService::session', '会话管理', 'mdi-message', '会话管理', 'Weline_CustomerService::customer_service')]
class Session extends BackendController
{
    /**
     * 会话列表
     */
    #[Acl('Weline_CustomerService::session_index', '查看会话', 'mdi-message', '查看会话')]
    public function index(): string
    {
        try {
            $status = $this->request->getParam('status', '');
            $agentId = (int)$this->request->getParam('agent_id', 0);

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            
            $query = $session->reset();
            
            if (!empty($status)) {
                $query->where(ChatSession::schema_fields_STATUS, $status);
            }
            
            if ($agentId > 0) {
                $query->where(ChatSession::schema_fields_AGENT_ID, $agentId);
            }

            $sessions = $query->order(ChatSession::schema_fields_CREATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();

            // 获取关联信息
            foreach ($sessions as &$sessionData) {
                if ($sessionData['agent_id']) {
                    /** @var ServiceAgent $agent */
                    $agent = ObjectManager::getInstance(ServiceAgent::class);
                    $agent->load($sessionData['agent_id']);
                    if ($agent->getId()) {
                        $sessionData['agent_name'] = $agent->getName();
                    }
                }

                // 获取消息数量
                /** @var ChatMessage $message */
                $message = ObjectManager::getInstance(ChatMessage::class);
                $sessionData['message_count'] = $message->reset()
                    ->where(ChatMessage::schema_fields_session_id, $sessionData['session_id'])
                    ->count();
            }

            $this->assign('sessions', $sessions);
            $this->assign('page_title', __('会话管理'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载会话失败：%{1}', $e->getMessage()));
            $this->assign('sessions', []);
            return $this->fetch();
        }
    }

    /**
     * 查看会话详情
     */
    #[Acl('Weline_CustomerService::session_view', '查看会话详情', 'mdi-eye', '查看会话详情')]
    public function view(): string
    {
        try {
            $sessionId = (int)$this->request->getParam('session_id', 0);

            if ($sessionId <= 0) {
                $this->getMessageManager()->addError(__('无效的会话ID'));
                $this->redirect('*/backend/session');
                return '';
            }

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $session->load($sessionId);

            if (!$session->getId()) {
                $this->getMessageManager()->addError(__('会话不存在'));
                $this->redirect('*/backend/session');
                return '';
            }

            // 获取消息列表
            /** @var ChatMessage $message */
            $message = ObjectManager::getInstance(ChatMessage::class);
            $messages = $message->reset()
                ->where(ChatMessage::schema_fields_session_id, $sessionId)
                ->order(ChatMessage::schema_fields_created_at, 'ASC')
                ->select()
                ->fetch()
                ->getItems();

            $this->assign('session', $session->getData());
            $this->assign('messages', $messages);
            $this->assign('page_title', __('会话详情'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载会话详情失败：%{1}', $e->getMessage()));
            $this->redirect('*/backend/session');
            return '';
        }
    }

    /**
     * 关闭会话
     * POST /customerservice/backend/session/close
     */
    #[Acl('Weline_CustomerService::session_close', '关闭会话', 'mdi-close', '关闭会话')]
    public function postClose(): string
    {
        try {
            $sessionId = (int)$this->request->getPost('session_id', 0);

            if ($sessionId <= 0) {
                return $this->jsonResponse(false, __('无效的会话ID'));
            }

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $session->load($sessionId);

            if (!$session->getId()) {
                return $this->jsonResponse(false, __('会话不存在'));
            }

            $session->setStatus(ChatSession::STATUS_CLOSED)
                ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();

            return $this->jsonResponse(true, __('会话已关闭'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('关闭会话失败：%{1}', $e->getMessage()));
        }
    }

    private function jsonResponse(bool $success, string $message, array $data = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    }
}

