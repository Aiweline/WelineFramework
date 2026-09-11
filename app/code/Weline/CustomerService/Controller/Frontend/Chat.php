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
use Weline\CustomerService\Model\ServiceAgent;
use Weline\CustomerService\Service\ChatAttachmentCodec;
use Weline\CustomerService\Service\ChatMediaUploader;
use Weline\CustomerService\Service\ChatService;
use Weline\CustomerService\Service\CustomerServiceSettings;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 前端聊天控制器
 */
class Chat extends FrontendController
{
    private ChatService $chatService;

    public function __construct(
        ChatService $chatService
    ) {
        $this->chatService = $chatService;
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
     * GET /customerservice/frontend/chat/session
     */
    public function getSession(): string
    {
        try {
            $customerId = $this->isLoggedIn() ? $this->getLoginUserId() : null;
            $sessionToken = $this->request->getParam('session_token');
            /** @var CustomerServiceSettings $settings */
            $settings = ObjectManager::getInstance(CustomerServiceSettings::class);
            $customerLocale = trim((string)$this->request->getParam('locale', ''));
            if ($customerLocale === '') {
                $customerLocale = $settings->defaultCustomerLocale();
            }

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
                    'agent_id' => $session->getAgentId(),
                    'identity' => $this->chatService->buildSessionIdentity(
                        $session,
                        $customerId !== null && $customerId > 0,
                        $customerId
                    ),
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
     * POST /customerservice/frontend/chat/send-message
     */
    public function postSendMessage(): string
    {
        try {
            $sessionId = (int)$this->request->getPost('session_id', 0);
            $content = trim($this->request->getPost('content', ''));
            $attachmentType = trim((string)$this->request->getPost('attachment_type', ''));
            $attachmentUrl = trim((string)$this->request->getPost('attachment_url', ''));
            if ($attachmentType !== '' && $attachmentUrl !== '') {
                $content = ChatAttachmentCodec::encode([
                    'type' => $attachmentType,
                    'url' => $attachmentUrl,
                    'name' => trim((string)$this->request->getPost('attachment_name', '')),
                    'size' => (int)$this->request->getPost('attachment_size', 0),
                    'mime' => trim((string)$this->request->getPost('attachment_mime', '')),
                ]);
            }

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

            $customerId = $this->session->isLoggedIn() ? $this->session->getUserId() : 0;
            $senderId = $customerId ?: $sessionId; // 未登录用户使用会话ID作为发送者ID

            try {
                $this->chatService->assertCustomerMaySend($sessionId, (int)$customerId > 0);
            } catch (\RuntimeException $e) {
                $gate = $this->chatService->resolveGuestSendGate($sessionId, (int)$customerId > 0);
                return $this->fetchJson([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'guest_send' => $gate,
                    'guest_send_locked' => true,
                ]);
            }

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
                ],
                'guest_send' => $this->chatService->resolveGuestSendGate($sessionId, (int)$customerId > 0),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('发送消息失败：%{1}', $e->getMessage())
            ]);
        }
    }

    /**
     * 上传聊天附件（base64）
     * POST /customerservice/frontend/chat/upload
     */
    public function postUpload(): string
    {
        try {
            $sessionId = (int)$this->request->getPost('session_id', 0);
            if ($sessionId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('无效的会话ID'),
                ]);
            }
            $customerId = $this->session->isLoggedIn() ? (int)$this->session->getUserId() : 0;
            try {
                $this->chatService->assertCustomerMaySend($sessionId, $customerId > 0);
            } catch (\RuntimeException $e) {
                $gate = $this->chatService->resolveGuestSendGate($sessionId, $customerId > 0);
                return $this->fetchJson([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'guest_send' => $gate,
                    'guest_send_locked' => true,
                ]);
            }
            $owner = $customerId > 0 ? 'c_' . $customerId : 's_' . $sessionId;
            /** @var ChatMediaUploader $uploader */
            $uploader = ObjectManager::getInstance(ChatMediaUploader::class);
            $stored = $uploader->storeBase64(
                trim((string)$this->request->getPost('name', 'file')),
                strtolower(trim((string)$this->request->getPost('mime', 'application/octet-stream'))),
                (string)$this->request->getPost('data', ''),
                $owner
            );
            return $this->fetchJson([
                'success' => true,
                'message' => __('上传成功'),
                'data' => $stored,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('上传失败：%{1}', $e->getMessage()),
            ]);
        }
    }

    /**
     * 获取消息列表（AJAX）
     * GET /customerservice/frontend/chat/messages
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
     * POST /customerservice/frontend/chat/set-language
     */
    public function postSetLanguage(): string
    {
        try {
            $locale = trim($this->request->getPost('locale', ''));
            $sessionToken = $this->request->getPost('session_token');

            if (empty($locale)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('语言代码不能为空')
                ]);
            }

            $customerId = $this->isLoggedIn() ? $this->getLoginUserId() : null;

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

    /**
     * 获取客服在线状态（前端使用）
     * GET /customerservice/frontend/chat/service-status
     * 返回: online(绿)、ai(蓝)、offline(灰)
     */
    public function getServiceStatus(): string
    {
        try {
            /** @var ServiceAgent $agentModel */
            $agentModel = ObjectManager::getInstance(ServiceAgent::class);
            $agents = $agentModel->reset()
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();

            $hasOnlineAgent = false;
            foreach ($agents as $a) {
                $lastHb = $a[ServiceAgent::schema_fields_LAST_HEARTBEAT] ?? null;
                if ($lastHb && (time() - strtotime($lastHb)) < ServiceAgent::HEARTBEAT_TIMEOUT) {
                    $hasOnlineAgent = true;
                    break;
                }
            }

            // 检查 AI 是否配置（统一配置 / 当前范围）
            $aiEnabled = false;
            try {
                /** @var CustomerServiceSettings $settings */
                $settings = ObjectManager::getInstance(CustomerServiceSettings::class);
                $aiEnabled = $settings->isAiEnabled();
            } catch (\Exception $e) {
                // 配置不可用时忽略
            }

            if ($hasOnlineAgent) {
                $status = 'online';  // 绿色
            } elseif ($aiEnabled) {
                $status = 'ai';      // 蓝色
            } else {
                $status = 'offline'; // 灰色
            }

            return $this->fetchJson([
                'success' => true,
                'data' => [
                    'status' => $status,
                    'has_online_agent' => $hasOnlineAgent,
                    'ai_enabled' => $aiEnabled,
                ]
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'data' => ['status' => 'offline']
            ]);
        }
    }
}

