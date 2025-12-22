<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Controller\Frontend;

use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ChatSession;
use Weline\CustomerService\Service\ChatService;
use Weline\Customer\Session\CustomerSession;
use Weline\Framework\App\Controller\FrontendController;

/**
 * 前端聊天控制器
 */
class Chat extends FrontendController
{
    private ChatService $chatService;
    private CustomerSession $session;

    public function __construct(
        ChatService $chatService,
        CustomerSession $session
    ) {
        $this->chatService = $chatService;
        $this->session = $session;
    }

    /**
     * 聊天界面
     */
    public function index(): string
    {
        $this->assign('page_title', __('客服聊天'));
        return $this->fetch();
    }

    /**
     * 获取或创建会话（AJAX）
     */
    public function getSession(): string
    {
        try {
            $customerId = $this->session->isLogin() ? $this->session->getLoginID() : null;
            $sessionToken = $this->request->getParam('session_token');
            $customerLocale = $this->request->getParam('locale', 'zh_Hans_CN');

            $session = $this->chatService->getOrCreateSession(
                $customerId,
                $sessionToken,
                $customerLocale
            );

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'session_id' => $session->getId(),
                    'session_token' => $session->getSessionToken(),
                    'customer_locale' => $session->getCustomerLocale(),
                    'agent_locale' => $session->getAgentLocale(),
                    'status' => $session->getStatus(),
                    'agent_id' => $session->getAgentId()
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取会话失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 发送消息（AJAX）
     */
    public function sendMessage(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        try {
            $sessionId = (int)$this->request->getPost('session_id', 0);
            $content = trim($this->request->getPost('content', ''));

            if (empty($content)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('消息内容不能为空')
                ]);
            }

            if ($sessionId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('无效的会话ID')
                ]);
            }

            $customerId = $this->session->isLogin() ? $this->session->getLoginID() : 0;
            $senderId = $customerId ?: $sessionId; // 未登录用户使用会话ID作为发送者ID

            $message = $this->chatService->sendMessage(
                $sessionId,
                ChatMessage::SENDER_TYPE_CUSTOMER,
                $senderId,
                $content
            );

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'message_id' => $message->getId(),
                    'content' => $message->getContent(),
                    'translated_content' => $message->getTranslatedContent(),
                    'created_at' => $message->getData('created_at')
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('发送消息失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 获取消息列表（AJAX）
     */
    public function getMessages(): string
    {
        try {
            $sessionId = (int)$this->request->getParam('session_id', 0);
            $limit = (int)$this->request->getParam('limit', 50);
            $offset = (int)$this->request->getParam('offset', 0);

            if ($sessionId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('无效的会话ID')
                ]);
            }

            $messages = $this->chatService->getMessages($sessionId, $limit, $offset);

            return $this->fetchJson([
                'success' => true,
                'data' => $messages
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('获取消息失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 设置客户语言（AJAX）
     */
    public function setLanguage(): string
    {
        if (!$this->request->isPost()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        try {
            $locale = trim($this->request->getPost('locale', ''));
            $sessionToken = $this->request->getPost('session_token');

            if (empty($locale)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('语言代码不能为空')
                ]);
            }

            $customerId = $this->session->isLogin() ? $this->session->getLoginID() : null;

            $this->chatService->setCustomerLocale(
                $locale,
                $customerId,
                $sessionToken,
                null
            );

            return $this->fetchJson([
                'success' => true,
                'message' => __('语言设置成功')
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('设置语言失败：%{1}', $e->getMessage())
            ]);
        }
    }
}

