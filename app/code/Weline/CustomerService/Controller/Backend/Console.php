<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\CustomerService\Controller\Backend;

use Weline\CustomerService\Model\AgentPhrase;
use Weline\CustomerService\Model\AgentPhraseCategory;
use Weline\CustomerService\Model\ChatMessage;
use Weline\CustomerService\Model\ChatSession;
use Weline\CustomerService\Model\ServiceAgent;
use Weline\CustomerService\Service\ChatAttachmentCodec;
use Weline\CustomerService\Service\ChatMediaUploader;
use Weline\CustomerService\Service\ChatService;
use Weline\CustomerService\Service\StatisticsService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Manager\ObjectManager;

/**
 * 客服聊天工作台控制器
 */
#[Acl('Weline_CustomerService::console', '客服工作台', 'message', '客服聊天工作台', 'Weline_CustomerService::customer_service')]
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
    #[Acl('Weline_CustomerService::console_index', '查看工作台', 'message', '查看客服工作台')]
    public function index(): string
    {
        try {
            // 获取当前登录的后台用户
            $userId = $this->session->getLoginUserID();
            
            // 查找当前用户是否是客服
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $userId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
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
                ->where(ChatSession::schema_fields_AGENT_ID, $agent->getId())
                ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_ACTIVE)
                ->order(ChatSession::schema_fields_UPDATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();

            // 获取等待分配的会话
            $waitingSessions = $session->reset()
                ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_WAITING)
                ->order(ChatSession::schema_fields_CREATED_AT, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            $waitingSessions = $this->chatService->normalizeSessionRows($waitingSessions);
            $waitingSessions = $this->chatService->enrichConsoleSessionRows($waitingSessions);

            $sessions = $this->chatService->enrichConsoleSessionRows($sessions);
            $sessions = $this->chatService->withTransferBadges($sessions);
            [$sessions, $transferredSessions] = $this->partitionAgentSessions($sessions);
            $sessions = $this->chatService->sortConsoleSessionsByPriority($sessions);
            $transferredSessions = $this->chatService->sortConsoleSessionsByPriority($transferredSessions);

            // 获取统计数据（默认今日）
            $statistics = $this->statisticsService->getAgentStatistics($agent->getId(), 'today');

            $this->assign('agent', $agent->getData());
            $this->assign('sessions', $sessions);
            $this->assign('transferredSessions', $transferredSessions);
            $this->assign('waitingSessions', $waitingSessions);
            $this->assign('defaultSessionId', (int)($sessions[0]['session_id'] ?? $sessions[0]['id'] ?? 0));
            $this->assign('statistics', $statistics);
            $this->assign('page_title', __('客服工作台'));
            
            return $this->fetch();
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('加载工作台失败：%{1}', $e->getMessage()));
            $this->assign('sessions', []);
            $this->assign('transferredSessions', []);
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
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $userId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
                ->find()
                ->fetch();
            
            if (!$agent->getId()) {
                return $this->jsonResponse(false, __('您不是客服人员'));
            }

            /** @var ChatSession $session */
            $session = ObjectManager::getInstance(ChatSession::class);
            $sessions = $session->reset()
                ->where(ChatSession::schema_fields_AGENT_ID, $agent->getId())
                ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_ACTIVE)
                ->order(ChatSession::schema_fields_UPDATED_AT, 'DESC')
                ->select()
                ->fetch()
                ->getItems();

            // 获取等待分配的会话
            $waitingSessions = $session->reset()
                ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_WAITING)
                ->order(ChatSession::schema_fields_CREATED_AT, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            $waitingSessions = $this->chatService->normalizeSessionRows($waitingSessions);
            $waitingSessions = $this->chatService->enrichConsoleSessionRows($waitingSessions);

            $sessions = $this->chatService->enrichConsoleSessionRows($sessions);
            $sessions = $this->chatService->withTransferBadges($sessions);
            [$sessions, $transferredSessions] = $this->partitionAgentSessions($sessions);
            $sessions = $this->chatService->sortConsoleSessionsByPriority($sessions);
            $transferredSessions = $this->chatService->sortConsoleSessionsByPriority($transferredSessions);

            return $this->jsonResponse(true, __('获取成功'), [
                'sessions' => $sessions,
                'transferred_sessions' => $transferredSessions,
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
            $sinceMessageId = (int)$this->request->getParam('since_id', 0);
            $beforeMessageId = (int)$this->request->getParam('before_id', 0);
            $markRead = (string)$this->request->getParam('mark_read', '1') !== '0';

            if ($sessionId <= 0) {
                return $this->jsonResponse(false, __('无效的会话ID'));
            }

            // 验证会话是否属于当前客服
            $userId = $this->session->getLoginUserID();
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $userId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
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

            $pageLimit = max(1, $limit);
            if ($sinceMessageId > 0) {
                $rawMessages = $this->chatService->getMessagesSince($sessionId, $sinceMessageId, $pageLimit);
            } elseif ($beforeMessageId > 0) {
                $rawMessages = $this->chatService->getMessagesBefore($sessionId, $beforeMessageId, $pageLimit);
            } else {
                $rawMessages = $this->chatService->getMessages($sessionId, $pageLimit, $offset);
            }

            $messages = array_map(
                fn(ChatMessage|array $message): array => $this->chatService->formatMessageForAgentView($message),
                $rawMessages
            );

            if ($markRead && $session->getAgentId() == $agent->getId() && $beforeMessageId <= 0) {
                $this->chatService->markSessionReadByAgent($sessionId);
            }

            return $this->jsonResponse(true, __('获取成功'), $messages, [
                'has_more' => $beforeMessageId > 0
                    ? count($messages) >= $pageLimit
                    : ($sinceMessageId > 0 ? false : count($messages) >= $pageLimit),
            ]);
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
            $content = trim((string)$this->request->getPost('content', ''));
            $attachmentType = trim((string)$this->request->getPost('attachment_type', ''));
            $attachmentUrl = trim((string)$this->request->getPost('attachment_url', ''));
            $attachmentName = trim((string)$this->request->getPost('attachment_name', ''));
            $attachmentSize = (int)$this->request->getPost('attachment_size', 0);
            $attachmentMime = trim((string)$this->request->getPost('attachment_mime', ''));

            if ($attachmentType !== '' && $attachmentUrl !== '') {
                $content = ChatAttachmentCodec::encode([
                    'type' => $attachmentType,
                    'url' => $attachmentUrl,
                    'name' => $attachmentName,
                    'size' => $attachmentSize,
                    'mime' => $attachmentMime,
                ]);
            }

            if ($content === '') {
                return $this->jsonResponse(false, __('消息内容不能为空'));
            }

            if ($sessionId <= 0) {
                return $this->jsonResponse(false, __('无效的会话ID'));
            }

            // 验证会话是否属于当前客服
            $userId = $this->session->getLoginUserID();
            /** @var ServiceAgent $agent */
            $agent = ObjectManager::getInstance(ServiceAgent::class);
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $userId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
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
                    ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
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
                'created_at' => $this->chatService->formatClientDateTime((string)$message->getData('created_at'))
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
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $userId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
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
                ->where(ChatSession::schema_fields_AGENT_ID, $agent->getId())
                ->where(ChatSession::schema_fields_STATUS, ChatSession::STATUS_ACTIVE)
                ->count();

            if ($currentSessions >= $agent->getMaxSessions()) {
                return $this->jsonResponse(false, __('已达到最大会话数限制'));
            }

            $session->setAgentId($agent->getId())
                ->setAgentLocale($agent->getLocale())
                ->setStatus(ChatSession::STATUS_ACTIVE)
                ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
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
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $userId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
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
                ->setData(ChatSession::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
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
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $userId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
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
            $agent->reset()
                ->where(ServiceAgent::schema_fields_USER_ID, $userId)
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
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
                ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
                ->select()
                ->fetch()
                ->getItems();

            $result = [];
            foreach ($agents as $a) {
                $lastHb = $a[ServiceAgent::schema_fields_LAST_HEARTBEAT] ?? null;
                $online = $lastHb && (time() - strtotime($lastHb)) < ServiceAgent::HEARTBEAT_TIMEOUT;
                $result[] = [
                    'agent_id'   => $a[ServiceAgent::schema_fields_ID],
                    'name'       => $a[ServiceAgent::schema_fields_NAME],
                    'online'     => $online,
                ];
            }

            return $this->jsonResponse(true, __('获取成功'), ['agents' => $result]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('获取客服状态失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 上传聊天附件（base64，经 bin-query）
     * POST /customerservice/backend/console/upload
     */
    public function postUpload(): string
    {
        try {
            $agent = $this->requireCurrentAgent();
            if ($agent instanceof string) {
                return $agent;
            }

            $name = trim((string)$this->request->getPost('name', 'file'));
            $mime = strtolower(trim((string)$this->request->getPost('mime', 'application/octet-stream')));
            $base64 = (string)$this->request->getPost('data', '');
            /** @var ChatMediaUploader $uploader */
            $uploader = ObjectManager::getInstance(ChatMediaUploader::class);
            $stored = $uploader->storeBase64($name, $mime, $base64, 'agent_' . (int)$agent->getId());

            return $this->jsonResponse(true, __('上传成功'), $stored);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse(false, $e->getMessage());
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('上传失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 个人话术列表（含分类 Tab）
     * GET /customerservice/backend/console/phrases
     */
    public function getPhrases(): string
    {
        try {
            $agent = $this->requireCurrentAgent();
            if ($agent instanceof string) {
                return $agent;
            }
            $agentId = (int)$agent->getId();
            /** @var AgentPhrase $model */
            $model = ObjectManager::getInstance(AgentPhrase::class);
            $rows = $model->reset()
                ->where(AgentPhrase::schema_fields_AGENT_ID, $agentId)
                ->order(AgentPhrase::schema_fields_SORT, 'ASC')
                ->order(AgentPhrase::schema_fields_ID, 'DESC')
                ->select()
                ->fetch()
                ->getItems();
            $list = [];
            $usedCategories = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $data = is_array($row) ? $row : $row->getData();
                $category = trim((string)($data[AgentPhrase::schema_fields_CATEGORY] ?? ''));
                if ($category !== '') {
                    $usedCategories[$category] = true;
                }
                $list[] = [
                    'phrase_id' => (int)($data[AgentPhrase::schema_fields_ID] ?? 0),
                    'title' => (string)($data[AgentPhrase::schema_fields_TITLE] ?? ''),
                    'content' => (string)($data[AgentPhrase::schema_fields_CONTENT] ?? ''),
                    'category' => $category,
                    'sort_order' => (int)($data[AgentPhrase::schema_fields_SORT] ?? 0),
                ];
            }
            $categories = $this->listPhraseCategoryNames($agentId);
            foreach (array_keys($usedCategories) as $name) {
                if (!in_array($name, $categories, true)) {
                    $categories[] = $name;
                }
            }
            return $this->jsonResponse(true, __('获取成功'), [
                'phrases' => $list,
                'categories' => array_values($categories),
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('获取话术失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 保存个人话术（新建/更新）
     * POST /customerservice/backend/console/phrase-save
     */
    public function postPhraseSave(): string
    {
        try {
            $agent = $this->requireCurrentAgent();
            if ($agent instanceof string) {
                return $agent;
            }
            $phraseId = (int)$this->request->getPost('phrase_id', 0);
            $title = trim((string)$this->request->getPost('title', ''));
            $content = trim((string)$this->request->getPost('content', ''));
            $category = mb_substr(trim((string)$this->request->getPost('category', '')), 0, 64);
            if ($title === '' || $content === '') {
                return $this->jsonResponse(false, __('标题和内容不能为空'));
            }
            /** @var AgentPhrase $model */
            $model = ObjectManager::getInstance(AgentPhrase::class);
            if ($phraseId > 0) {
                $model->load($phraseId);
                if (!$model->getId() || (int)$model->getAgentId() !== (int)$agent->getId()) {
                    return $this->jsonResponse(false, __('话术不存在'));
                }
            } else {
                $model->setAgentId((int)$agent->getId());
            }
            $model->setTitle(mb_substr($title, 0, 120))
                ->setContent($content)
                ->setData(AgentPhrase::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
            try {
                $model->setCategory($category)->save();
            } catch (\Throwable $e) {
                // category 列未升级完成时回退为仅保存标题/内容
                $model->unsetModelData(AgentPhrase::schema_fields_CATEGORY);
                $model->save();
            }

            if ($category !== '') {
                $this->ensurePhraseCategory((int)$agent->getId(), $category);
            }

            return $this->jsonResponse(true, __('保存成功'), [
                'phrase_id' => (int)$model->getId(),
                'title' => $model->getTitle(),
                'content' => $model->getContent(),
                'category' => $model->getCategory(),
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存话术失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 删除个人话术
     * POST /customerservice/backend/console/phrase-delete
     */
    public function postPhraseDelete(): string
    {
        try {
            $agent = $this->requireCurrentAgent();
            if ($agent instanceof string) {
                return $agent;
            }
            $phraseId = (int)$this->request->getPost('phrase_id', 0);
            if ($phraseId <= 0) {
                return $this->jsonResponse(false, __('无效的话术ID'));
            }
            /** @var AgentPhrase $model */
            $model = ObjectManager::getInstance(AgentPhrase::class);
            $model->load($phraseId);
            if (!$model->getId() || (int)$model->getAgentId() !== (int)$agent->getId()) {
                return $this->jsonResponse(false, __('话术不存在'));
            }
            $model->delete();
            return $this->jsonResponse(true, __('删除成功'));
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('删除话术失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 新建话术分类（即新 Tab）
     * POST /customerservice/backend/console/phrase-category-save
     */
    public function postPhraseCategorySave(): string
    {
        try {
            $agent = $this->requireCurrentAgent();
            if ($agent instanceof string) {
                return $agent;
            }
            $name = mb_substr(trim((string)$this->request->getPost('name', '')), 0, 64);
            if ($name === '') {
                return $this->jsonResponse(false, __('分类名不能为空'));
            }
            $this->ensurePhraseCategory((int)$agent->getId(), $name);
            return $this->jsonResponse(true, __('分类已创建'), [
                'name' => $name,
                'categories' => $this->listPhraseCategoryNames((int)$agent->getId()),
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('保存分类失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * 删除空话术分类 Tab
     * POST /customerservice/backend/console/phrase-category-delete
     */
    public function postPhraseCategoryDelete(): string
    {
        try {
            $agent = $this->requireCurrentAgent();
            if ($agent instanceof string) {
                return $agent;
            }
            $name = mb_substr(trim((string)$this->request->getPost('name', '')), 0, 64);
            if ($name === '') {
                return $this->jsonResponse(false, __('分类名不能为空'));
            }
            $agentId = (int)$agent->getId();
            /** @var AgentPhrase $phraseModel */
            $phraseModel = ObjectManager::getInstance(AgentPhrase::class);
            $used = $phraseModel->reset()
                ->where(AgentPhrase::schema_fields_AGENT_ID, $agentId)
                ->where(AgentPhrase::schema_fields_CATEGORY, $name)
                ->select()
                ->fetch()
                ->getItems();
            if (is_array($used) && count($used) > 0) {
                return $this->jsonResponse(false, __('该分类下还有话术，请先移出或删除'));
            }
            /** @var AgentPhraseCategory $catModel */
            $catModel = ObjectManager::getInstance(AgentPhraseCategory::class);
            $row = $catModel->reset()
                ->where(AgentPhraseCategory::schema_fields_AGENT_ID, $agentId)
                ->where(AgentPhraseCategory::schema_fields_NAME, $name)
                ->find()
                ->fetch();
            if ($row && $row->getId()) {
                $row->delete();
            }
            return $this->jsonResponse(true, __('分类已删除'), [
                'categories' => $this->listPhraseCategoryNames($agentId),
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse(false, __('删除分类失败：%{1}', $e->getMessage()));
        }
    }

    /**
     * @return list<string>
     */
    private function listPhraseCategoryNames(int $agentId): array
    {
        try {
            /** @var AgentPhraseCategory $catModel */
            $catModel = ObjectManager::getInstance(AgentPhraseCategory::class);
            $rows = $catModel->reset()
                ->where(AgentPhraseCategory::schema_fields_AGENT_ID, $agentId)
                ->order(AgentPhraseCategory::schema_fields_SORT, 'ASC')
                ->order(AgentPhraseCategory::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            $names = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $data = is_array($row) ? $row : $row->getData();
                $name = trim((string)($data[AgentPhraseCategory::schema_fields_NAME] ?? ''));
                if ($name !== '' && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
            return $names;
        } catch (\Throwable $e) {
            // 表未升级完成时仍可返回话术列表，分类 Tab 退化为短语上的 category 去重。
            return [];
        }
    }

    private function ensurePhraseCategory(int $agentId, string $name): void
    {
        $name = mb_substr(trim($name), 0, 64);
        if ($name === '') {
            return;
        }
        try {
            /** @var AgentPhraseCategory $catModel */
            $catModel = ObjectManager::getInstance(AgentPhraseCategory::class);
            $existing = $catModel->reset()
                ->where(AgentPhraseCategory::schema_fields_AGENT_ID, $agentId)
                ->where(AgentPhraseCategory::schema_fields_NAME, $name)
                ->find()
                ->fetch();
            if ($existing && $existing->getId()) {
                return;
            }
            $catModel->clear()
                ->setAgentId($agentId)
                ->setName($name)
                ->setSortOrder(0)
                ->setData(AgentPhraseCategory::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();
        } catch (\Throwable $e) {
            // 分类表尚未就绪时不阻断话术保存；分类名仍写在 phrase.category。
        }
    }

    /**
     * @return ServiceAgent|string JSON error response when agent missing
     */
    private function requireCurrentAgent(): ServiceAgent|string
    {
        $userId = $this->session->getLoginUserID();
        /** @var ServiceAgent $agent */
        $agent = ObjectManager::getInstance(ServiceAgent::class);
        $agent->reset()
            ->where(ServiceAgent::schema_fields_USER_ID, $userId)
            ->where(ServiceAgent::schema_fields_IS_ACTIVE, 1)
            ->find()
            ->fetch();
        if (!$agent->getId()) {
            return $this->jsonResponse(false, __('您不是客服人员'));
        }

        return $agent;
    }

    /**
     * JSON响应
     */
    private function jsonResponse(bool $success, string $message, array $data = [], array $extra = []): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode(array_merge([
            'success' => $success,
            'message' => $message,
            'data' => $data,
        ], $extra), JSON_UNESCAPED_UNICODE);
    }

    /**
     * 将客服名下活跃会话拆为「我的会话」与「转让分配」。
     *
     * @param list<array<string, mixed>> $sessions
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function partitionAgentSessions(array $sessions): array
    {
        $mine = [];
        $transferred = [];
        foreach ($sessions as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($row['is_transferred'])) {
                $transferred[] = $row;
            } else {
                $mine[] = $row;
            }
        }

        return [$mine, $transferred];
    }
}
