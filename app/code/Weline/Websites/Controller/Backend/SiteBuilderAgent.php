<?php
declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSiteBuilderSession;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\AiWorkbench\ArtifactService;
use Weline\Websites\Service\AiWorkbench\EventStreamService;
use Weline\Websites\Service\AiWorkbench\MessageService;
use Weline\Websites\Service\AiWorkbench\ProviderWorkbenchService;
use Weline\Websites\Service\AiWorkbench\ProviderRegistry;
use Weline\Websites\Service\AiWorkbench\SessionService;
use Weline\Websites\Service\WebsiteAgentService;

#[Acl('Weline_Websites::site_builder_agent', 'AI 建站工作台', 'mdi mdi-robot', '根据需求描述完成域名、网站与工作台协同建站', 'Weline_Backend::website_service')]
class SiteBuilderAgent extends BackendController
{
    #[Acl('Weline_Websites::site_builder_agent_index', 'AI 建站工作台', 'mdi mdi-robot', 'AI 建站工作台首页')]
    public function index(): string
    {
        $selectedProvider = \trim((string)$this->request->getGet('provider', ''));
        if ($selectedProvider === '') {
            $selectedProvider = 'websites_default';
        }
        $fakeMode = $this->isFakeModeRequested();

        $adminId = $this->getAdminId();

        $this->assign('accounts', $this->getActiveAccounts());
        $this->assign('provider_cards', $this->getProviderCards($selectedProvider));
        $this->assign('recent_sessions', $adminId > 0 ? $this->getRecentSessionCards($adminId) : []);
        $this->assign('selected_provider', $selectedProvider);
        $this->assign('create_session_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/create-session'));
        $this->assign('current_entry_url', $this->getHubEntryUrl($selectedProvider, $fakeMode));
        $this->assign('fake_mode', $fakeMode);
        $this->assign('page_title', __('AI 建站工作台'));
        $this->assign('breadcrumb_parent', __('网站服务'));
        $this->assign('breadcrumb_current', __('AI 建站工作台'));

        return $this->fetch();
    }

    #[Acl('Weline_Websites::site_builder_agent_workspace', 'AI 建站工作区', 'mdi mdi-view-dashboard-outline', '查看与编辑可恢复 AI 建站工作区', 'Weline_Websites::site_builder_agent')]
    public function workspace(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));

        if ($adminId <= 0 || $publicId === '') {
            $this->assign('title', __('AI 建站工作区'));
            $this->assign('error_message', __('未登录或会话令牌无效'));
            $this->assign('back_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/index'));
            return $this->fetch();
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->assign('title', __('AI 建站工作区'));
            $this->assign('error_message', __('会话不存在或无权访问'));
            $this->assign('back_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/index'));
            return $this->fetch();
        }

        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminId);
        $scope = $providerConfig['scope'];

        $this->assign('title', __('AI 建站工作区'));
        $this->assign('session', $session);
        $this->assign('provider_context', $this->extractProviderContext($providerConfig));
        $this->assign('provider_tools', $providerConfig['tools']);
        $this->assign('scope', $scope);
        $this->assign('scope_preview', $this->encodePrettyJson($scope));
        $this->assign('messages', $this->getMessageService()->listForSession($session->getId(), $adminId, 150));
        $this->assign('events', $this->getEventStreamService()->listRecentEvents($session->getId(), $adminId, 120));
        $this->assign('last_event_id', $this->getEventStreamService()->getLatestEventId($session->getId(), $adminId));
        $this->assign('stage_options', $this->getStageOptions());
        $this->assign('state_json_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/state-json', ['public_id' => $session->getPublicId()]));
        $this->assign('back_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/index', ['provider' => $session->getProviderCode()]));
        $this->assign('merge_scope_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/merge-scope'));
        $this->assign('replace_scope_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/replace-scope'));
        $this->assign('set_stage_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/set-stage'));
        $this->assign('append_message_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/append-message'));
        $this->assign('stream_sse_path', 'websites/backend/site-builder-agent/stream-sse');
        $this->assign('preview_full_url', $session->getPreviewUrl());
        $this->assign('provider_native_url', $providerConfig['native_entry_url']);
        $this->assign('provider_handoff_label', $providerConfig['handoff_label']);
        $this->assign('snapshot_artifact', $this->getArtifactService()->getOne($session->getId(), $adminId, 'workspace', 'scope_snapshot'));
        $this->assign('handoff_artifact', $this->getArtifactService()->getOne($session->getId(), $adminId, 'handoff', $session->getProviderCode()));
        $this->assign('recent_sessions', $this->getRecentSessionCards($adminId, $session->getPublicId()));

        return $this->fetch();
    }

    #[Acl('Weline_Websites::site_builder_agent_state_json', 'AI 建站状态 JSON', 'mdi mdi-code-json', '读取工作区 JSON 状态', 'Weline_Websites::site_builder_agent')]
    public function getStateJson(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        return $this->fetchJson([
            'success' => true,
            'data' => $this->buildWorkspaceState($session, $adminId),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_create_session', '创建 AI 建站工作区', 'mdi mdi-plus', '创建一个可恢复的 AI 建站工作区', 'Weline_Websites::site_builder_agent')]
    public function postCreateSession(): string
    {
        $adminId = $this->getAdminId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('未登录')]);
        }

        $providerCode = \trim((string)$this->getRequestBodyValue('provider_code', 'websites_default'));
        if ($providerCode === '') {
            $providerCode = 'websites_default';
        }

        $provider = $this->getProviderRegistry()->getProvider($providerCode);
        if ($provider === null || !$provider->isEnabled()) {
            return $this->fetchJson(['success' => false, 'message' => __('无效的 provider：%{code}', ['code' => $providerCode])]);
        }

        $description = \trim((string)$this->getRequestBodyValue('description', ''));
        $domain = \trim((string)$this->getRequestBodyValue('domain', ''));
        $accountId = (int)$this->getRequestBodyValue('account_id', 0);
        $useAi = ((string)$this->getRequestBodyValue('use_ai', '1') === '1');
        $fakeMode = $this->isFakeModeRequested();
        $scopeError = '';
        $providerStateError = '';
        $scopeSeed = $this->getRequestJsonObject('scope', $scopeError);
        $providerStateSeed = $this->getRequestJsonObject('provider_state', $providerStateError);
        if ($scopeError !== '' || $providerStateError !== '') {
            return $this->fetchJson([
                'success' => false,
                'message' => $scopeError !== '' ? $scopeError : $providerStateError,
            ]);
        }

        $scope = [
            'created_from' => 'websites_site_builder_agent_hub',
            'provider_code' => $providerCode,
            'use_ai' => $useAi ? 1 : 0,
        ];
        if ($description !== '') {
            $scope['user_description'] = $description;
            $scope['brief_description'] = $description;
        }
        if ($domain !== '') {
            $scope['target_domain'] = \strtolower($domain);
        }
        if ($accountId > 0) {
            $scope['preferred_registrar_account_id'] = $accountId;
        }
        if ($fakeMode) {
            $scope['fake_mode'] = 1;
            $scope['build_execution_mode'] = 'local_fake_demo';
        }
        $scope = \array_replace($scope, $scopeSeed);

        $providerState = [
            'entry' => [
                'source' => 'websites_site_builder_agent_hub',
                'use_ai' => $useAi ? 1 : 0,
            ],
        ];
        if ($fakeMode) {
            $providerState['entry']['fake_mode'] = 1;
        }
        $providerState = \array_replace($providerState, $providerStateSeed);

        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfig(
            $providerCode,
            $adminId,
            null,
            $scope,
            $providerState,
            [
                'source' => 'websites_site_builder_agent_hub',
                'description' => $description,
                'domain' => $domain,
                'account_id' => $accountId,
                'use_ai' => $useAi ? 1 : 0,
                'fake_mode' => $fakeMode ? 1 : 0,
            ]
        );

        try {
            $session = $this->getSessionService()->createSession(
                $providerCode,
                $adminId,
                $providerConfig['scope'],
                $providerConfig['provider_state'],
                $providerConfig['initial_stage']
            );
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('创建工作区失败：%{message}', ['message' => $e->getMessage()]),
            ]);
        }

        $sessionId = $session->getId();

        $this->getEventStreamService()->appendEvent(
            $sessionId,
            $adminId,
            $session->getCurrentStage(),
            'session_created',
            [
                'provider_code' => $providerCode,
                'use_ai' => $useAi,
                'has_description' => $description !== '',
                'has_domain' => $domain !== '',
                'account_id' => $accountId,
                'has_scope_seed' => $scopeSeed !== [],
            ],
            AiSiteBuilderEvent::LEVEL_INFO
        );

        $initialBrief = \trim((string)($providerConfig['scope']['brief_description'] ?? $providerConfig['scope']['user_description'] ?? ''));
        if ($initialBrief !== '') {
            $this->getMessageService()->appendMessage($sessionId, $adminId, 'user', $initialBrief, 'brief');
        }
        $this->getMessageService()->appendMessage(
            $sessionId,
            $adminId,
            'assistant',
            $providerConfig['welcome_message'],
            'system'
        );

        $fresh = $this->getSessionService()->loadById($sessionId, $adminId);
        if ($fresh !== null) {
            $this->syncSessionStructuredFields($fresh, $adminId);
            $fresh = $this->getSessionService()->loadById($sessionId, $adminId) ?? $fresh;
            $this->syncSessionArtifacts($fresh, $adminId);
        }

        return $this->fetchJson([
            'success' => true,
            'public_id' => $session->getPublicId(),
            'workspace_url' => $this->getWorkspaceUrl($session->getPublicId()),
            'provider_code' => $providerCode,
            'provider_name' => $providerConfig['name'],
            'native_entry_url' => $providerConfig['native_entry_url'],
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_merge_scope', '合并工作区 Scope', 'mdi mdi-database-plus-outline', '合并工作区 Scope JSON', 'Weline_Websites::site_builder_agent')]
    public function postMergeScope(): string
    {
        return $this->jsonMutateScope(true);
    }

    #[Acl('Weline_Websites::site_builder_agent_replace_scope', '替换工作区 Scope', 'mdi mdi-database-edit-outline', '整体替换工作区 Scope JSON', 'Weline_Websites::site_builder_agent')]
    public function postReplaceScope(): string
    {
        return $this->jsonMutateScope(false);
    }

    #[Acl('Weline_Websites::site_builder_agent_set_stage', '设置工作区阶段', 'mdi mdi-flag-checkered', '更新当前工作区阶段', 'Weline_Websites::site_builder_agent')]
    public function postSetStage(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $stage = \trim((string)$this->getRequestBodyValue('stage', ''));
        if ($adminId <= 0 || $publicId === '' || $stage === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        $allowedStages = \array_flip(\array_column($this->getStageOptions(), 'value'));
        if (!isset($allowedStages[$stage])) {
            return $this->fetchJson(['success' => false, 'message' => __('无效的阶段')]);
        }

        if (!$this->getSessionService()->setStage($session->getId(), $adminId, $stage)) {
            return $this->fetchJson(['success' => false, 'message' => __('保存阶段失败')]);
        }

        $this->getEventStreamService()->appendEvent(
            $session->getId(),
            $adminId,
            $stage,
            'stage_changed',
            ['stage' => $stage, 'stage_label' => $this->getStageLabel($stage)],
            AiSiteBuilderEvent::LEVEL_INFO
        );

        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId);
        if ($fresh !== null) {
            $this->syncSessionArtifacts($fresh, $adminId);
        }

        return $this->fetchJson([
            'success' => true,
            'stage' => $stage,
            'stage_label' => $this->getStageLabel($stage),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_append_message', '追加工作区消息', 'mdi mdi-message-plus-outline', '向工作区追加一条备注或消息', 'Weline_Websites::site_builder_agent')]
    public function postAppendMessage(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $content = \trim((string)$this->getRequestBodyValue('content', ''));
        $role = \trim((string)$this->getRequestBodyValue('role', 'user'));
        $messageType = \trim((string)$this->getRequestBodyValue('message_type', 'note'));

        if ($adminId <= 0 || $publicId === '' || $content === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        if (!\in_array($role, ['user', 'assistant', 'system'], true)) {
            $role = 'user';
        }
        if ($messageType === '') {
            $messageType = 'note';
        }

        if (!$this->getMessageService()->appendMessage($session->getId(), $adminId, $role, $content, $messageType)) {
            return $this->fetchJson(['success' => false, 'message' => __('保存消息失败')]);
        }

        $this->getEventStreamService()->appendEvent(
            $session->getId(),
            $adminId,
            $session->getCurrentStage(),
            'message_appended',
            [
                'role' => $role,
                'message_type' => $messageType,
                'content_preview' => $this->buildContentPreview($content),
            ],
            AiSiteBuilderEvent::LEVEL_INFO
        );

        return $this->fetchJson([
            'success' => true,
            'messages' => $this->getMessageService()->listForSession($session->getId(), $adminId, 150),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_stream', '工作区 SSE 事件流', 'mdi mdi-access-point', '流式订阅工作区事件', 'Weline_Websites::site_builder_agent')]
    public function getStreamSse(): void
    {
        $sse = new SseWriter();
        $sse->start();

        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $lastEventId = (int)$this->request->getGet('last_event_id', 0);

        if ($adminId <= 0 || $publicId === '') {
            $sse->sendError(__('参数无效'));
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $sse->sendError(__('会话不存在或无权访问'));
            $sse->complete(['success' => false]);
            return;
        }

        $sse->sendEvent('start', ['message' => __('已连接工作区事件流')]);
        $sse->sendEvent('snapshot', $this->buildWorkspaceState($session, $adminId, 40, 40));

        $deadline = \time() + 900;
        $sessionId = $session->getId();
        while (\time() < $deadline && $sse->isAlive()) {
            $newEvents = $this->getEventStreamService()->listEventsAfterId($sessionId, $adminId, $lastEventId, 80);
            foreach ($newEvents as $event) {
                $eventId = (int)($event['event_id'] ?? 0);
                if ($eventId > $lastEventId) {
                    $lastEventId = $eventId;
                }
                $sse->sendEvent('log', $event);
            }
            $sse->maybeHeartbeat();
            \usleep(2000000);
        }

        $sse->complete([
            'success' => true,
            'message' => __('事件流已结束，可重新连接继续监听'),
            'last_event_id' => $lastEventId,
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_trigger', '触发建站', 'mdi mdi-play', '触发建站流程')]
    public function getTriggerSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => __('正在初始化...')]);

        try {
            $description = \trim((string)$this->request->getGet('description', ''));
            $domain = \trim((string)$this->request->getGet('domain', ''));
            $accountId = (int)$this->request->getGet('account_id', 0);
            $useAi = ($this->request->getGet('use_ai', '1') === '1');
            $fakeMode = $this->isFakeModeRequested();

            if ($domain === '' && !$useAi && !$fakeMode) {
                $sse->sendEvent('error', ['message' => __('请填写目标域名，或启用 AI 模式让系统先推荐域名')]);
                $sse->complete(['success' => false]);
                return;
            }
            if ($accountId <= 0 && !$useAi && !$fakeMode) {
                $sse->sendEvent('error', ['message' => __('请先选择域名商账户')]);
                $sse->complete(['success' => false]);
                return;
            }

            if ($fakeMode) {
                $this->runFakeTriggerFlow($sse, $description, $domain, $accountId, $useAi);
                return;
            }

            if ($useAi && \class_exists(\Weline\Ai\Service\AiService::class)) {
                $this->runAiAgent($sse, $description, $domain, $accountId);
                return;
            }

            if ($domain === '' || $accountId <= 0) {
                $sse->sendEvent('error', ['message' => __('关闭 AI 模式后，必须填写域名并选择账户')]);
                $sse->complete(['success' => false]);
                return;
            }
            if ($description === '') {
                $description = $domain;
            }

            $itemExtras = [];
            $clientIp = \trim((string)$this->request->getClientIp());
            if ($clientIp !== '' && \filter_var($clientIp, FILTER_VALIDATE_IP)) {
                $itemExtras['user_client_ip'] = $clientIp;
            }

            /** @var WebsiteAgentService $agentService */
            $agentService = ObjectManager::getInstance(WebsiteAgentService::class);
            $result = $agentService->buildFromDescription(
                $description,
                $domain,
                $accountId,
                static function (string $event, array $data) use ($sse): void {
                    $sse->sendEvent($event, $data);
                },
                $itemExtras
            );

            if (!empty($result['success'])) {
                $sse->complete([
                    'success' => true,
                    'message' => $result['message'] ?? __('建站任务已完成'),
                    'domain' => $result['domain'] ?? '',
                    'website_id' => $result['website_id'] ?? 0,
                ]);
                return;
            }

            $sse->sendEvent('error', ['message' => $result['message'] ?? __('执行失败')]);
            $sse->complete(['success' => false]);
        } catch (\Throwable $e) {
            $sse->sendEvent('error', [
                'message' => $e->getMessage(),
                'detail' => __('执行出错：%{message}', ['message' => $e->getMessage()]),
            ]);
            $sse->complete(['success' => false]);
        }
    }

    private function runAiAgent(
        SseWriter $sse,
        string $description,
        string $domain,
        int $accountId
    ): void {
        $prompt = $description !== ''
            ? __('请根据以下描述完成建站：%{1}', [$description])
            : __('请帮我完成一次建站规划');
        if ($domain !== '') {
            $prompt .= "\n" . __('用户期望域名：%{1}', [$domain]);
        }
        if ($accountId > 0) {
            $prompt .= "\n" . __('使用账户 ID：%{1}', [$accountId]);
        }

        $params = ['account_id' => $accountId];
        $mapEvent = static function (string $eventType, array $data) use ($sse): void {
            $message = $data['message'] ?? $data['content'] ?? null;
            if (\is_string($message) && $message !== '') {
                $sse->sendEvent('progress', ['message' => $message]);
            }
            if ($eventType === 'tool_call' && isset($data['name'])) {
                $sse->sendEvent('info', ['message' => __('执行工具：%{1}', [$data['name']])]);
            }
            if ($eventType === 'tool_result' && isset($data['name'])) {
                $sse->sendEvent('info', ['message' => __('工具 %{1} 已完成', [$data['name']])]);
            }
        };

        try {
            /** @var \Weline\Ai\Service\AiService $aiService */
            $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            $result = $aiService->executeAgent('website_builder', $prompt, null, $params, $mapEvent);

            if ($result->success && $result->content !== '') {
                $sse->sendEvent('progress', ['message' => $result->content]);
            }
            $sse->complete([
                'success' => $result->success,
                'message' => $result->success ? __('建站任务已完成') : ($result->error ?? __('执行失败')),
            ]);
        } catch (\Throwable $e) {
            $sse->sendEvent('error', ['message' => $e->getMessage()]);
            $sse->complete(['success' => false]);
        }
    }

    private function runFakeTriggerFlow(
        SseWriter $sse,
        string $description,
        string $domain,
        int $accountId,
        bool $useAi
    ): void {
        $resolvedDomain = $domain !== ''
            ? \strtolower($domain)
            : $this->buildFakeDomainSuggestion($description !== '' ? $description : 'demo site');
        $brief = $description !== '' ? $description : $resolvedDomain;
        $seed = $brief . '|' . $resolvedDomain . '|' . ($useAi ? 'ai' : 'manual') . '|' . $accountId;
        $hash = \substr(\hash('sha256', $seed), 0, 12);
        $websiteId = 800000 + (\hexdec(\substr($hash, 0, 4)) % 10000);
        $themeId = 600000 + (\hexdec(\substr($hash, 4, 4)) % 10000);
        $previewUrl = $this->buildFakePreviewUrl($resolvedDomain, $themeId);

        $timeline = [
            [
                'progress',
                [
                    'message' => (string)__('Local demo: brief understood'),
                    'stage' => 'brief',
                    'fake_mode' => true,
                ],
            ],
            [
                'info',
                [
                    'message' => (string)__('Local demo: suggested domain %{domain}', ['domain' => $resolvedDomain]),
                    'stage' => 'domain',
                    'domain' => $resolvedDomain,
                    'fake_mode' => true,
                ],
            ],
            [
                'progress',
                [
                    'message' => (string)__('Local demo: simulated domain purchase and bootstrap resources'),
                    'stage' => 'domain',
                    'domain' => $resolvedDomain,
                    'account_id' => $accountId,
                    'fake_mode' => true,
                ],
            ],
            [
                'progress',
                [
                    'message' => (string)__('Local demo: generated page structure and starter content'),
                    'stage' => 'page_types',
                    'website_id' => $websiteId,
                    'fake_mode' => true,
                ],
            ],
            [
                'progress',
                [
                    'message' => (string)__('Local demo: generated theme direction and virtual theme'),
                    'stage' => 'virtual_theme',
                    'theme_id' => $themeId,
                    'fake_mode' => true,
                ],
            ],
            [
                'progress',
                [
                    'message' => (string)__('Local demo: prepared visual-edit preview'),
                    'stage' => 'visual_edit',
                    'preview_url' => $previewUrl,
                    'fake_mode' => true,
                ],
            ],
        ];

        foreach ($timeline as [$eventName, $payload]) {
            $sse->sendEvent((string)$eventName, $payload);
            \usleep(180000);
        }

        $result = [
            'success' => true,
            'message' => (string)__('Local demo flow completed'),
            'domain' => $resolvedDomain,
            'website_id' => $websiteId,
            'theme_id' => $themeId,
            'preview_url' => $previewUrl,
            'fake_mode' => true,
        ];

        $sse->sendEvent('done', $result);
        $sse->complete($result);
    }

    /**
     * @return list<array{account_id:int,account_name:string,registrar_name:string,registrar_code:string}>
     */
    private function getActiveAccounts(): array
    {
        $accountModel = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $allAccounts = $accountModel->getAccountsWithRegistrar();
        $activeAccounts = [];

        foreach ($allAccounts as $row) {
            if (($row['status'] ?? '') !== 'active') {
                continue;
            }
            $activeAccounts[] = [
                'account_id' => (int)($row['account_id'] ?? 0),
                'account_name' => (string)($row['account_name'] ?? ''),
                'registrar_name' => (string)($row['registrar_name'] ?? ''),
                'registrar_code' => (string)($row['registrar_code'] ?? ''),
            ];
        }

        return $activeAccounts;
    }

    /**
     * @return list<array{
     *   code:string,
     *   name:string,
     *   description:string,
     *   badge:string,
     *   target_url:string,
     *   target_label:string,
     *   workspace_label:string,
     *   selected:bool
     * }>
     */
    private function getProviderCards(string $selectedProvider): array
    {
        /** @var Url $urlHelper */
        $urlHelper = ObjectManager::getInstance(Url::class);

        $cards = [];
        try {
            /** @var ProviderRegistry $providerRegistry */
            $providerRegistry = ObjectManager::getInstance(ProviderRegistry::class);
            $providers = $providerRegistry->getProviders(true);
        } catch (\Throwable) {
            $providers = [];
        }

        foreach ($providers as $provider) {
            $code = \trim($provider->getCode());
            if ($code === '') {
                continue;
            }
            $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfig($code, $this->getAdminId());
            $cards[] = [
                'code' => $providerConfig['code'],
                'name' => $providerConfig['name'],
                'description' => $providerConfig['description'],
                'badge' => $providerConfig['badge'],
                'target_url' => $providerConfig['target_url'],
                'target_label' => $providerConfig['target_label'],
                'workspace_label' => $providerConfig['workspace_label'],
                'selected' => $selectedProvider === $code,
            ];
        }

        if ($cards === []) {
            $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfig('websites_default', $this->getAdminId());
            $cards[] = [
                'code' => $providerConfig['code'],
                'name' => (string)__('Websites 默认流程'),
                'description' => (string)__('适合想快速从需求描述走到域名、网站与初始站点结果的场景。'),
                'badge' => (string)__('默认流程'),
                'target_url' => $providerConfig['target_url'],
                'target_label' => (string)__('使用极速建站'),
                'workspace_label' => (string)__('创建工作区'),
                'selected' => $selectedProvider === 'websites_default',
            ];
        }

        return $cards;
    }

    private function resolveProviderEntryUrl(Url $urlHelper, string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => $urlHelper->getBackendUrl('pagebuilder/backend/aiSiteAgent/index', ['legacy' => 1]),
            'websites_default' => $urlHelper->getBackendUrl('*/backend/site-builder-agent/index', ['provider' => 'websites_default']) . '#quick-build',
            default => $urlHelper->getBackendUrl('*/backend/site-builder-agent/index', ['provider' => $providerCode]) . '#provider-lane',
        };
    }

    private function resolveProviderActionLabel(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('进入分阶段工作台'),
            'websites_default' => (string)__('使用极速建站'),
            default => (string)__('查看此流程'),
        };
    }

    private function resolveProviderBadge(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('扩展流程'),
            'websites_default' => (string)__('默认流程'),
            default => (string)__('已接入'),
        };
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function getStageOptions(): array
    {
        $map = [
            'brief' => __('阶段：建站简报'),
            'domain' => __('阶段：域名与基础设施'),
            'domain_wait' => __('阶段：等待域名就绪'),
            'virtual_theme' => __('阶段：主题与风格方向'),
            'page_types' => __('阶段：页面类型与结构'),
            'content' => __('阶段：内容与文案'),
            'visual_edit' => __('阶段：预览与可视化微调'),
            'publish' => __('阶段：发布检查'),
        ];

        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => (string)$label];
        }

        return $options;
    }

    private function getStageLabel(string $stage): string
    {
        foreach ($this->getStageOptions() as $option) {
            if (($option['value'] ?? '') === $stage) {
                return (string)($option['label'] ?? $stage);
            }
        }

        return $stage !== '' ? $stage : (string)__('阶段：建站简报');
    }

    /**
     * @return array{code:string,name:string,description:string,badge:string}
     */
    private function getProviderMeta(string $providerCode): array
    {
        return $this->extractProviderContext(
            $this->getProviderWorkbenchService()->buildWorkbenchConfig($providerCode, $this->getAdminId())
        );

        $provider = $this->getProviderRegistry()->getProvider($providerCode);
        if ($provider !== null) {
            return [
                'code' => $provider->getCode(),
                'name' => $provider->getName(),
                'description' => $provider->getDescription(),
                'badge' => $this->resolveProviderBadge($provider->getCode()),
            ];
        }

        return [
            'code' => $providerCode,
            'name' => $providerCode !== '' ? $providerCode : 'websites_default',
            'description' => (string)__('未找到 provider 描述，当前按兼容模式展示。'),
            'badge' => (string)__('兼容模式'),
        ];
    }

    /**
     * @return list<array{
     *   session_id:int,
     *   public_id:string,
     *   provider_code:string,
     *   provider_name:string,
     *   current_stage:string,
     *   stage_label:string,
     *   selected_domain:string,
     *   website_id:int,
     *   preview_url:string,
     *   summary:string,
     *   update_time:string,
     *   workspace_url:string,
     *   native_entry_url:string
     * }>
     */
    private function getRecentSessionCards(int $adminUserId, string $currentPublicId = ''): array
    {
        $rows = $this->getSessionService()->listRecentSessionsForAdmin($adminUserId, 12);
        $sessions = [];

        foreach ($rows as $row) {
            $publicId = (string)($row['public_id'] ?? '');
            if ($publicId === '' || $publicId === $currentPublicId) {
                continue;
            }

            $providerCode = (string)($row['provider_code'] ?? 'websites_default');
            $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfig(
                $providerCode,
                $adminUserId,
                [
                    'public_id' => $publicId,
                    'current_stage' => (string)($row['current_stage'] ?? AiSiteBuilderSession::STAGE_BRIEF),
                    'website_id' => (int)($row['website_id'] ?? 0),
                    'selected_domain' => (string)($row['selected_domain'] ?? ''),
                    'preview_url' => (string)($row['preview_url'] ?? ''),
                ]
            );
            $domain = (string)($row['selected_domain'] ?? '');
            $websiteId = (int)($row['website_id'] ?? 0);
            $stage = (string)($row['current_stage'] ?? AiSiteBuilderSession::STAGE_BRIEF);
            $summaryParts = [];
            if ($domain !== '') {
                $summaryParts[] = $domain;
            }
            if ($websiteId > 0) {
                $summaryParts[] = (string)__('网站 #%{id}', ['id' => $websiteId]);
            }
            if ($summaryParts === []) {
                $summaryParts[] = (string)__('暂未绑定域名或站点');
            }

            $sessions[] = [
                'session_id' => (int)($row['session_id'] ?? 0),
                'public_id' => $publicId,
                'provider_code' => $providerCode,
                'provider_name' => $providerConfig['name'],
                'current_stage' => $stage,
                'stage_label' => $this->getStageLabel($stage),
                'selected_domain' => $domain,
                'website_id' => $websiteId,
                'preview_url' => (string)($row['preview_url'] ?? ''),
                'summary' => \implode(' / ', $summaryParts),
                'update_time' => (string)($row['update_time'] ?? ''),
                'workspace_url' => $this->getWorkspaceUrl($publicId),
                'native_entry_url' => $providerConfig['native_entry_url'],
            ];
        }

        return $sessions;
    }

    /**
     * @return array{
     *   public_id:string,
     *   provider:array{code:string,name:string,description:string,badge:string},
     *   session:array<string,mixed>,
     *   messages:list<array<string,mixed>>,
     *   events:list<array<string,mixed>>,
     *   snapshot_artifact:array<string,mixed>|null,
     *   handoff_artifact:array<string,mixed>|null
     * }
     */
    private function buildWorkspaceState(
        AiSiteBuilderSession $session,
        int $adminUserId,
        int $messageLimit = 120,
        int $eventLimit = 120
    ): array {
        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminUserId);

        return [
            'public_id' => $session->getPublicId(),
            'provider' => [
                'code' => $providerConfig['code'],
                'name' => $providerConfig['name'],
                'description' => $providerConfig['description'],
                'badge' => $providerConfig['badge'],
                'target_url' => $providerConfig['target_url'],
                'target_label' => $providerConfig['target_label'],
                'workspace_label' => $providerConfig['workspace_label'],
                'handoff_label' => $providerConfig['handoff_label'],
                'native_entry_url' => $providerConfig['native_entry_url'],
                'tools' => $providerConfig['tools'],
            ],
            'session' => [
                'session_id' => $session->getId(),
                'provider_code' => $session->getProviderCode(),
                'current_stage' => $session->getCurrentStage(),
                'stage_label' => $this->getStageLabel($session->getCurrentStage()),
                'website_id' => $session->getWebsiteId(),
                'selected_domain' => $session->getSelectedDomain(),
                'registrar_account_id' => $session->getRegistrarAccountId(),
                'preview_url' => $session->getPreviewUrl(),
                'scope' => $providerConfig['scope'],
                'provider_state' => $providerConfig['provider_state'],
            ],
            'messages' => $this->getMessageService()->listForSession($session->getId(), $adminUserId, $messageLimit),
            'events' => $this->getEventStreamService()->listRecentEvents($session->getId(), $adminUserId, $eventLimit),
            'snapshot_artifact' => $this->getArtifactService()->getOne($session->getId(), $adminUserId, 'workspace', 'scope_snapshot'),
            'handoff_artifact' => $this->getArtifactService()->getOne($session->getId(), $adminUserId, 'handoff', $session->getProviderCode()),
        ];
    }

    private function jsonMutateScope(bool $merge): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $scopeRaw = $this->getRequestBodyValue($merge ? 'scope_patch' : 'scope', '');
        if ($scopeRaw === '' || $scopeRaw === null) {
            $scopeRaw = $this->getRequestBodyValue('scope', $this->getRequestBodyValue('scope_patch', ''));
        }

        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $decoded = $this->decodeJsonObject($scopeRaw);
        if ($decoded === null) {
            return $this->fetchJson(['success' => false, 'message' => __('Scope 必须是有效的 JSON 对象')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        $sessionId = $session->getId();
        $ok = $merge
            ? $this->getSessionService()->mergeScope($sessionId, $adminId, $decoded)
            : $this->getSessionService()->replaceScope($sessionId, $adminId, $decoded);
        if (!$ok) {
            return $this->fetchJson(['success' => false, 'message' => __('保存 Scope 失败')]);
        }

        $fresh = $this->getSessionService()->loadById($sessionId, $adminId);
        if ($fresh === null) {
            return $this->fetchJson(['success' => false, 'message' => __('保存后无法重新加载会话')]);
        }

        $this->syncSessionStructuredFields($fresh, $adminId);
        $fresh = $this->getSessionService()->loadById($sessionId, $adminId) ?? $fresh;
        $this->getEventStreamService()->appendEvent(
            $sessionId,
            $adminId,
            $fresh->getCurrentStage(),
            $merge ? 'scope_merged' : 'scope_replaced',
            ['keys' => \array_keys($decoded)],
            AiSiteBuilderEvent::LEVEL_INFO
        );
        $this->syncSessionArtifacts($fresh, $adminId);

        return $this->fetchJson([
            'success' => true,
            'scope' => $fresh->getScopeArray(),
            'selected_domain' => $fresh->getSelectedDomain(),
            'preview_url' => $fresh->getPreviewUrl(),
        ]);
    }

    private function syncSessionStructuredFields(AiSiteBuilderSession $session, int $adminUserId): void
    {
        $scope = $session->getScopeArray();
        $sessionId = $session->getId();
        $targetDomain = \trim((string)($scope['target_domain'] ?? ''));
        $registrarAccountId = (int)($scope['preferred_registrar_account_id'] ?? $scope['registrar_account_id'] ?? 0);
        if ($targetDomain !== '' || $registrarAccountId > 0) {
            $this->getSessionService()->bindDomain(
                $sessionId,
                $adminUserId,
                $targetDomain !== '' ? $targetDomain : $session->getSelectedDomain(),
                $registrarAccountId > 0 ? $registrarAccountId : $session->getRegistrarAccountId()
            );
        }

        $previewUrl = \trim((string)($scope['preview_url'] ?? $scope['preview_full_url'] ?? ''));
        if ($previewUrl !== '') {
            $this->getSessionService()->setPreviewUrl($sessionId, $adminUserId, $previewUrl);
        }

        $websiteId = (int)($scope['website_id'] ?? 0);
        if ($websiteId > 0) {
            $this->getSessionService()->bindWebsite($sessionId, $adminUserId, $websiteId);
        }
    }

    private function syncSessionArtifacts(AiSiteBuilderSession $session, int $adminUserId): void
    {
        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminUserId);
        $payload = [
            'provider_code' => $session->getProviderCode(),
            'stage' => $session->getCurrentStage(),
            'stage_label' => $this->getStageLabel($session->getCurrentStage()),
            'selected_domain' => $session->getSelectedDomain(),
            'website_id' => $session->getWebsiteId(),
            'preview_url' => $session->getPreviewUrl(),
            'scope' => $providerConfig['scope'],
        ];

        $this->getArtifactService()->upsertArtifact(
            $session->getId(),
            $adminUserId,
            'workspace',
            'scope_snapshot',
            $payload,
            (string)__('工作区快照')
        );
        $this->getArtifactService()->upsertArtifact(
            $session->getId(),
            $adminUserId,
            'handoff',
            $session->getProviderCode(),
            [
                'native_entry_url' => $providerConfig['native_entry_url'],
                'handoff_label' => $providerConfig['handoff_label'],
                'provider_code' => $session->getProviderCode(),
            ],
            (string)__('Provider 兼容入口')
        );
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @return array{code:string,name:string,description:string,badge:string}
     */
    private function extractProviderContext(array $providerConfig): array
    {
        return [
            'code' => (string)($providerConfig['code'] ?? ''),
            'name' => (string)($providerConfig['name'] ?? ''),
            'description' => (string)($providerConfig['description'] ?? ''),
            'badge' => (string)($providerConfig['badge'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestJsonObject(string $key, string &$errorMessage = ''): array
    {
        $errorMessage = '';
        $raw = $this->request->getPost($key, null);
        if ($raw === null || $raw === '') {
            $raw = $this->request->getBodyParam($key, null);
        }
        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = $this->decodeJsonObject($raw);
        if ($decoded === null) {
            $errorMessage = (string)__('参数 %{key} 必须是有效的 JSON 对象', ['key' => $key]);
            return [];
        }

        return $decoded;
    }

    private function decodeJsonObject(mixed $raw): ?array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (!\is_string($raw)) {
            return null;
        }

        $raw = \trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    private function encodePrettyJson(mixed $value): string
    {
        try {
            return (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{}';
        }
    }

    private function buildContentPreview(string $content, int $maxLength = 120): string
    {
        $content = \trim($content);
        if ($content === '') {
            return '';
        }
        if (\function_exists('mb_substr')) {
            return \mb_substr($content, 0, $maxLength, 'UTF-8');
        }

        return \substr($content, 0, $maxLength);
    }

    private function buildWorkspaceWelcomeMessage(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('已为你创建一个可恢复的 AI 建站工作区。你可以先在这里整理需求、阶段和会话状态，后续再按需要切换到 PageBuilder 的精修工作台。'),
            default => (string)__('已为你创建一个可恢复的 AI 建站工作区。你可以先整理需求、域名、阶段和备注，再决定继续走极速建站还是进入更深的页面精修流程。'),
        };
    }

    private function resolveProviderWorkspaceLabel(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('创建兼容工作区'),
            default => (string)__('创建工作区'),
        };
    }

    private function resolveProviderHandoffLabel(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('继续到 PageBuilder 旧工作台'),
            'websites_default' => (string)__('返回极速建站入口'),
            default => (string)__('打开 provider 原生入口'),
        };
    }

    private function getRequestBodyValue(string $key, mixed $default = ''): mixed
    {
        $value = $this->request->getPost($key, null);
        if ($value !== null && $value !== '') {
            return $value;
        }

        $bodyValue = $this->request->getBodyParam($key, null);
        if ($bodyValue !== null && $bodyValue !== '') {
            return $bodyValue;
        }

        return $default;
    }

    private function isFakeModeRequested(): bool
    {
        $candidates = [
            $this->request->getGet('fake_mode', null),
            $this->request->getPost('fake_mode', null),
            $this->request->getBodyParam('fake_mode', null),
            \getenv('WELINE_AI_SITE_WORKBENCH_FAKE_MODE') ?: null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            return $this->isTruthyFlag($candidate);
        }

        return false;
    }

    private function isTruthyFlag(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (!\is_string($value)) {
            return false;
        }

        return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on', 'fake', 'demo'], true);
    }

    private function getHubEntryUrl(string $providerCode, bool $fakeMode = false): string
    {
        $params = ['provider' => $providerCode !== '' ? $providerCode : 'websites_default'];
        if ($fakeMode) {
            $params['fake_mode'] = 1;
        }

        return $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/index', $params);
    }

    private function buildFakeDomainSuggestion(string $description): string
    {
        $suggestions = ObjectManager::getInstance(WebsiteAgentService::class)
            ->suggestDomainsFromDescription($description);
        if (\is_array($suggestions)) {
            foreach ($suggestions as $suggestion) {
                if (\is_string($suggestion) && \trim($suggestion) !== '') {
                    return \strtolower(\trim($suggestion));
                }
            }
        }

        return 'demo-site.local.test';
    }

    private function buildFakePreviewUrl(string $domain, int $themeId): string
    {
        $slug = \preg_replace('/[^a-z0-9\-]+/', '-', \strtolower($domain));
        $slug = \trim((string)$slug, '-');
        if ($slug === '') {
            $slug = 'demo-site';
        }

        return '/ai-site-workbench/fake-preview/' . $slug . '?theme_id=' . $themeId;
    }

    private function getWorkspaceUrl(string $publicId): string
    {
        return $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/workspace', ['public_id' => $publicId]);
    }

    private function getAdminId(): int
    {
        return (int)$this->getLoginUserId();
    }

    private function getUrlHelper(): Url
    {
        return ObjectManager::getInstance(Url::class);
    }

    private function getProviderRegistry(): ProviderRegistry
    {
        return ObjectManager::getInstance(ProviderRegistry::class);
    }

    private function getProviderWorkbenchService(): ProviderWorkbenchService
    {
        return ObjectManager::getInstance(ProviderWorkbenchService::class);
    }

    private function getSessionService(): SessionService
    {
        return ObjectManager::getInstance(SessionService::class);
    }

    private function getMessageService(): MessageService
    {
        return ObjectManager::getInstance(MessageService::class);
    }

    private function getEventStreamService(): EventStreamService
    {
        return ObjectManager::getInstance(EventStreamService::class);
    }

    private function getArtifactService(): ArtifactService
    {
        return ObjectManager::getInstance(ArtifactService::class);
    }
}
