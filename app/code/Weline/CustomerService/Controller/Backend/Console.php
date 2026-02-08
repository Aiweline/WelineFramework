<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Controller\Backend;

use Weline\Backend\Model\BackendUser;
use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ChatSession;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\CustomerService\Service\ChatService;
use Weline\CustomerService\Service\StatisticsService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

/**
 * 客服聊天工作台控制器
 */
#[Acl('Weline_CustomerService::console', '客服工作台', 'mdi-message-text', '客服聊天工作台', 'Weline_CustomerService::customer_service')]
class Console extends BackendController
{
    private ChatService $chatService;
    private StatisticsService $statisticsService;

    public function __construct(
        ChatService $chatService,
        StatisticsService $statisticsService
    ) {
        $this->chatService = $chatService;
        $this->statisticsService = $statisticsService;
    }

    /**
     * 客服聊天工作台主页面
     */
    #[Acl('Weline_CustomerService::console_index', '查看工作台', 'mdi-message-text', '查看客服工作台')]
    public function index(): string
    {
        try {
            // 获取当前登录的后台用户
            $userId = $this->session->getLoginUserID();
            
            // 查找当前用户是否是客服
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->where(ServiceAgent::fields_user_id, $userId)
                ->where(ServiceAgent::fields_is_active, 1)
                ->find()
                ->fetch();
            
            if (!$agent->getId()) {
                $this->getMessageManager()->addError(__('您不是客服人员，无法使用工作台'));
                $this->redirect('*/backend/agent');
                return '';
            }

            // 获取该客服的会话列表
            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $sessions = $session->reset()
                ->where(ChatSession::fields_agent_id, $agent->getId())
                ->where(ChatSession::fields_status, ChatSession::STATUS_ACTIVE)
                ->order(ChatSession::fields_updated_at, 'DESC')
                ->select()
                ->fetch()
                ->getItems();

            // 获取等待分配的会话
            $waitingSessions = $session->reset()
                ->where(ChatSession::fields_status, ChatSession::STATUS_WAITING)
                ->order(ChatSession::fields_created_at, 'ASC')
                ->select()
                ->fetch()
                ->getItems();

            // 获取每个会话的最后一条消息
            foreach ($sessions as &$sessionData) {
                /** @var ChatMessage $message */
                $message = ObjectManager::getInstance(ChatMessage::class);
                $lastMessage = $message->reset()
                    ->where(ChatMessage::fields_session_id, $sessionData['session_id'])
                    ->order(ChatMessage::fields_created_at, 'DESC')
                    ->find()
                    ->fetch();
                
                if ($lastMessage->getId()) {
                    $sessionData['last_message'] = $lastMessage->getTranslatedContent() ?: $lastMessage->getContent();
                    $sessionData['last_message_time'] = $lastMessage->getData('created_at');
                }

                // 获取未读消息数
                $unreadCount = $message->reset()
                    ->where(ChatMessage::fields_session_id, $sessionData['session_id'])
                    ->where(ChatMessage::fields_sender_type, ChatMessage::SENDER_TYPE_CUSTOMER)
                    ->where(ChatMessage::fields_created_at, $sessionData['last_read_time'] ?? '1970-01-01 00:00:00', '>')
                    ->count();
                $sessionData['unread_count'] = (int)$unreadCount;
            }

            foreach ($waitingSessions as &$waitingSession) {
                /** @var ChatMessage $message */
                $message = ObjectManager::getInstance(ChatMessage::class);
                $lastMessage = $message->reset()
                    ->where(ChatMessage::fields_session_id, $waitingSession['session_id'])
                    ->order(ChatMessage::fields_created_at, 'DESC')
                    ->find()
                    ->fetch();
                
                if ($lastMessage->getId()) {
                    $waitingSession['last_message'] = $lastMessage->getTranslatedContent() ?: $lastMessage->getContent();
                    $waitingSession['last_message_time'] = $lastMessage->getData('created_at');
                }
            }

            // 获取统计数据（默认今日）
            $statistics = $this->statisticsService->getAgentStatistics($agent->getId(), 'today');

            $this->assign('agent', $agent->getData());
            $this->assign('sessions', $sessions);
            $this->assign('waitingSessions', $waitingSessions);
            $this->assign('statistics', $statistics);
            $this->assign('page_title', __('客服工作台'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载工作台失败：%{1}', $e->getMessage()));
            $this->assign('sessions', []);
            $this->assign('waitingSessions', []);
            return $this->fetch();
        }
    }

    /**
     * 获取会话列表（AJAX）
     * GET /customerservice/backend/console/sessions
     */
    public function getSessions(): string
    {
        try {
            $userId = $this->session->getLoginUserID();
            
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->where(ServiceAgent::fields_user_id, $userId)
                ->where(ServiceAgent::fields_is_active, 1)
                ->find()
                ->fetch();
            
            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('您不是客服人员'));
            }

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $sessions = $session->reset()
                ->where(ChatSession::fields_agent_id, $agent->getId())
                ->where(ChatSession::fields_status, ChatSession::STATUS_ACTIVE)
                ->order(ChatSession::fields_updated_at, 'DESC')
                ->select()
                ->fetch()
                ->getItems();

            // 获取等待分配的会话
            $waitingSessions = $session->reset()
                ->where(ChatSession::fields_status, ChatSession::STATUS_WAITING)
                ->order(ChatSession::fields_created_at, 'ASC')
                ->select()
                ->fetch()
                ->getItems();

            // 获取每个会话的最后一条消息和未读数
            foreach ($sessions as &$sessionData) {
                /** @var ChatMessage $message */
                $message = ObjectManager::getInstance(ChatMessage::class);
                $lastMessage = $message->reset()
                    ->where(ChatMessage::fields_session_id, $sessionData['session_id'])
                    ->order(ChatMessage::fields_created_at, 'DESC')
                    ->find()
                    ->fetch();
                
                if ($lastMessage->getId()) {
                    $sessionData['last_message'] = $lastMessage->getTranslatedContent() ?: $lastMessage->getContent();
                    $sessionData['last_message_time'] = $lastMessage->getData('created_at');
                }

                // 获取未读消息数
                $unreadCount = $message->reset()
                    ->where(ChatMessage::fields_session_id, $sessionData['session_id'])
                    ->where(ChatMessage::fields_sender_type, ChatMessage::SENDER_TYPE_CUSTOMER)
                    ->where(ChatMessage::fields_created_at, $sessionData['last_read_time'] ?? '1970-01-01 00:00:00', '>')
                    ->count();
                $sessionData['unread_count'] = (int)$unreadCount;
            }

            return $this->jsonResponse(true, __('获取成功'), [
                'sessions' => $sessions,
                'waiting_sessions' => $waitingSessions
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('获取会话列表失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 获取会话消息（AJAX）
     * GET /customerservice/backend/console/messages
     */
    public function getMessages(): string
    {
        try {
            $sessionId = (int)$this->request->getParam('session_id', 0);
            $limit = (int)$this->request->getParam('limit', 50);
            $offset = (int)$this->request->getParam('offset', 0);

            if ($sessionId <= 0) {
                return $this->jsonResponse(false, __('无效的会话ID'));
            }

            // 验证会话是否属于当前客服
            $userId = $this->session->getLoginUserID();
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->where(ServiceAgent::fields_user_id, $userId)
                ->where(ServiceAgent::fields_is_active, 1)
                ->find()
                ->fetch();
            
            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('您不是客服人员'));
            }

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $session->load($sessionId);
            
            if (!$session->getId()) {
                return $this->jsonResponse(false, __('会话不存在'));
            }

            // 检查会话是否属于当前客服或等待分配
            if ($session->getAgentId() != $agent->getId() && $session->getStatus() != ChatSession::STATUS_WAITING) {
                return $this->jsonResponse(false, __('无权访问此会话'));
            }

            $messages = $this->chatService->getMessages($sessionId, $limit, $offset);

            return $this->jsonResponse(true, __('获取成功'), $messages);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('获取消息失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 发送消息（AJAX）
     * POST /customerservice/backend/console/send-message
     */
    public function postSendMessage(): string
    {
        try {
            $sessionId = (int)$this->request->getPost('session_id', 0);
            $content = trim($this->request->getPost('content', ''));

            if (empty($content)) {
                return $this->jsonResponse(false, __('消息内容不能为空'));
            }

            if ($sessionId <= 0) {
                return $this->jsonResponse(false, __('无效的会话ID'));
            }

            // 验证会话是否属于当前客服
            $userId = $this->session->getLoginUserID();
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->where(ServiceAgent::fields_user_id, $userId)
                ->where(ServiceAgent::fields_is_active, 1)
                ->find()
                ->fetch();
            
            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('您不是客服人员'));
            }

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $session->load($sessionId);
            
            if (!$session->getId()) {
                return $this->jsonResponse(false, __('会话不存在'));
            }

            // 如果是等待分配的会话，自动分配给当前客服
            if ($session->getStatus() == ChatSession::STATUS_WAITING) {
                $session->setAgentId($agent->getId())
                    ->setAgentLocale($agent->getLocale())
                    ->setStatus(ChatSession::STATUS_ACTIVE)
                    ->setData(ChatSession::fields_updated_at, date('Y-m-d H:i:s'))
                    ->save();
            } elseif ($session->getAgentId() != $agent->getId()) {
                return $this->jsonResponse(false, __('无权访问此会话'));
            }

            $message = $this->chatService->sendMessage(
                $sessionId,
                ChatMessage::SENDER_TYPE_AGENT,
                $agent->getId(),
                $content
            );

            return $this->jsonResponse(true, __('发送成功'), [
                'message_id' => $message->getId(),
                'content' => $message->getContent(),
                'translated_content' => $message->getTranslatedContent(),
                'created_at' => $message->getData('created_at')
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('发送消息失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 分配会话给当前客服（AJAX）
     * POST /customerservice/backend/console/assign-session
     */
    public function postAssignSession(): string
    {
        try {
            $sessionId = (int)$this->request->getPost('session_id', 0);

            if ($sessionId <= 0) {
                return $this->jsonResponse(false, __('无效的会话ID'));
            }

            $userId = $this->session->getLoginUserID();
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->where(ServiceAgent::fields_user_id, $userId)
                ->where(ServiceAgent::fields_is_active, 1)
                ->find()
                ->fetch();
            
            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('您不是客服人员'));
            }

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $session->load($sessionId);
            
            if (!$session->getId()) {
                return $this->jsonResponse(false, __('会话不存在'));
            }

            if ($session->getStatus() != ChatSession::STATUS_WAITING) {
                return $this->jsonResponse(false, __('会话已被分配'));
            }

            // 检查是否超过最大会话数
            $currentSessions = $session->reset()
                ->where(ChatSession::fields_agent_id, $agent->getId())
                ->where(ChatSession::fields_status, ChatSession::STATUS_ACTIVE)
                ->count();

            if ($currentSessions >= $agent->getMaxSessions()) {
                return $this->jsonResponse(false, __('已达到最大会话数限制'));
            }

            $session->setAgentId($agent->getId())
                ->setAgentLocale($agent->getLocale())
                ->setStatus(ChatSession::STATUS_ACTIVE)
                ->setData(ChatSession::fields_updated_at, date('Y-m-d H:i:s'))
                ->save();

            return $this->jsonResponse(true, __('分配成功'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('分配会话失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 关闭会话（AJAX）
     * POST /customerservice/backend/console/close-session
     */
    public function postCloseSession(): string
    {
        try {
            $sessionId = (int)$this->request->getPost('session_id', 0);

            if ($sessionId <= 0) {
                return $this->jsonResponse(false, __('无效的会话ID'));
            }

            $userId = $this->session->getLoginUserID();
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->where(ServiceAgent::fields_user_id, $userId)
                ->where(ServiceAgent::fields_is_active, 1)
                ->find()
                ->fetch();
            
            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('您不是客服人员'));
            }

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $session->load($sessionId);
            
            if (!$session->getId()) {
                return $this->jsonResponse(false, __('会话不存在'));
            }

            if ($session->getAgentId() != $agent->getId()) {
                return $this->jsonResponse(false, __('无权关闭此会话'));
            }

            $session->setStatus(ChatSession::STATUS_CLOSED)
                ->setData(ChatSession::fields_updated_at, date('Y-m-d H:i:s'))
                ->save();

            return $this->jsonResponse(true, __('会话已关闭'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('关闭会话失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 获取统计数据（AJAX）
     * GET /customerservice/backend/console/statistics
     */
    public function getStatistics(): string
    {
        try {
            $userId = $this->session->getLoginUserID();
            
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->where(ServiceAgent::fields_user_id, $userId)
                ->where(ServiceAgent::fields_is_active, 1)
                ->find()
                ->fetch();
            
            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('您不是客服人员'));
            }

            $period = $this->request->getParam('period', 'today');
            $statistics = $this->statisticsService->getAgentStatistics($agent->getId(), $period);

            return $this->jsonResponse(true, __('获取成功'), $statistics);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('获取统计数据失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 客服心跳（标记在线状态）
     * POST /customerservice/backend/console/heartbeat
     */
    public function postHeartbeat(): string
    {
        try {
            $userId = $this->session->getLoginUserID();

            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->where(ServiceAgent::fields_user_id, $userId)
                ->where(ServiceAgent::fields_is_active, 1)
                ->find()
                ->fetch();

            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('您不是客服人员'));
            }

            $agent->updateHeartbeat()->save();

            return $this->jsonResponse(true, 'ok');
        } catch (\Exception $e) {
            return $this->jsonResponse(false, $e->getMessage());
        }
    }

    /**
     * 获取所有客服在线状态（AJAX）
     * GET /customerservice/backend/console/agent-status
     */
    public function getAgentStatus(): string
    {
        try {
            /** @var ServiceAgent $agentModel */
            $agentModel = ObjectManager::getInstance(ServiceAgent::class);
            $agents = $agentModel->reset()
                ->where(ServiceAgent::fields_is_active, 1)
                ->select()
                ->fetch()
                ->getItems();

            $result = [];
            foreach ($agents as $a) {
                $lastHb = $a[ServiceAgent::fields_last_heartbeat] ?? null;
                $online = $lastHb && (time() - strtotime($lastHb)) < ServiceAgent::HEARTBEAT_TIMEOUT;
                $result[] = [
                    'agent_id'   => $a[ServiceAgent::fields_ID],
                    'name'       => $a[ServiceAgent::fields_name],
                    'online'     => $online,
                ];
            }

            return $this->jsonResponse(true, __('获取成功'), ['agents' => $result]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('获取客服状态失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * JSON响应
     */
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

