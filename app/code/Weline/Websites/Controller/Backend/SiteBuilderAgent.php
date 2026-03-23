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
use Weline\Websites\Service\AiWorkbench\ProviderRegistry;
use Weline\Websites\Service\AiWorkbench\ProviderWorkbenchService;
use Weline\Websites\Service\AiWorkbench\SessionService;
use Weline\Websites\Service\WebsiteAgentService;

#[Acl('Weline_Websites::site_builder_agent', 'AI Site Workbench', 'mdi mdi-robot', 'Coordinate domain, website, and workspace site building', 'Weline_Backend::website_service')]
class SiteBuilderAgent extends BackendController
{
    #[Acl('Weline_Websites::site_builder_agent_index', 'AI Site Workbench', 'mdi mdi-robot', 'AI site workbench hub')]
    public function index(): string
    {
        $selectedProvider = \trim((string)$this->request->getGet('provider', ''));
        if ($selectedProvider === '') {
            $selectedProvider = 'websites_default';
        }

        $fakeMode = $this->isFakeModeRequested();
        $adminId = $this->getAdminId();
        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfig($selectedProvider, $adminId);
        $scope = \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [];

        $this->assign('accounts', $this->getActiveAccounts());
        $this->assign('provider_cards', $this->getProviderCards($selectedProvider));
        $this->assign('recent_sessions', $adminId > 0 ? $this->getRecentSessionCards($adminId) : []);
        $this->assign('selected_provider', $selectedProvider);
        $this->assign('selected_provider_context', $this->extractProviderContext($providerConfig));
        $this->assign('selected_stage_guides', $this->buildStageGuides($providerConfig, $scope, 'prepare'));
        $this->assign('create_session_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/create-session'));
        $this->assign('current_entry_url', $this->getHubEntryUrl($selectedProvider, $fakeMode));
        $this->assign('fake_mode', $fakeMode);
        $this->assign('page_title', __('AI Site Workbench'));
        $this->assign('breadcrumb_parent', __('Website Services'));
        $this->assign('breadcrumb_current', __('AI Site Workbench'));

        return $this->fetch();
    }

    #[Acl('Weline_Websites::site_builder_agent_workspace', 'AI Site Workspace', 'mdi mdi-view-dashboard-outline', 'View and edit resumable AI site workspaces', 'Weline_Websites::site_builder_agent')]
    public function workspace(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));

        if ($adminId <= 0 || $publicId === '') {
            $this->assign('title', __('AI Site Workspace'));
            $this->assign('error_message', __('Login required or invalid session token'));
            $this->assign('back_url', $this->getHubEntryUrl('websites_default', $this->isFakeModeRequested()));
            return $this->fetch();
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->assign('title', __('AI Site Workspace'));
            $this->assign('error_message', __('Session not found or access denied'));
            $this->assign('back_url', $this->getHubEntryUrl('websites_default', $this->isFakeModeRequested()));
            return $this->fetch();
        }

        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminId);
        $scope = \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [];
        $currentStage = $this->normalizeJourneyStage($session->getCurrentStage());

        $this->assign('title', __('AI Site Workspace'));
        $this->assign('session', $session);
        $this->assign('provider_context', $this->extractProviderContext($providerConfig));
        $this->assign('provider_tools', \is_array($providerConfig['tools'] ?? null) ? $providerConfig['tools'] : []);
        $this->assign('current_stage', $currentStage);
        $this->assign('stage_guides', $this->buildStageGuides($providerConfig, $scope, $currentStage));
        $this->assign('scope', $scope);
        $this->assign('scope_preview', $this->encodePrettyJson($scope));
        $this->assign('accounts', $this->getActiveAccounts());
        $this->assign('messages', $this->getMessageService()->listForSession($session->getId(), $adminId, 150));
        $this->assign('events', $this->getEventStreamService()->listRecentEvents($session->getId(), $adminId, 120));
        $this->assign('last_event_id', $this->getEventStreamService()->getLatestEventId($session->getId(), $adminId));
        $this->assign('stage_options', $this->getStageOptions());
        $this->assign('state_json_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/state-json', ['public_id' => $session->getPublicId()]));
        $this->assign('back_url', $this->getHubEntryUrl($session->getProviderCode(), $this->isFakeModeRequested()));
        $this->assign('merge_scope_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/merge-scope'));
        $this->assign('replace_scope_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/replace-scope'));
        $this->assign('set_stage_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/set-stage'));
        $this->assign('append_message_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/append-message'));
        $this->assign('stream_sse_path', 'websites/backend/site-builder-agent/stream-sse');
        $this->assign('preview_full_url', $session->getPreviewUrl());
        $this->assign('provider_native_url', (string)($providerConfig['native_entry_url'] ?? ''));
        $this->assign('provider_handoff_label', (string)($providerConfig['handoff_label'] ?? ''));
        $this->assign('snapshot_artifact', $this->getArtifactService()->getOne($session->getId(), $adminId, 'workspace', 'scope_snapshot'));
        $this->assign('handoff_artifact', $this->getArtifactService()->getOne($session->getId(), $adminId, 'handoff', $session->getProviderCode()));
        $this->assign('recent_sessions', $this->getRecentSessionCards($adminId, $session->getPublicId()));

        return $this->fetch();
    }

    #[Acl('Weline_Websites::site_builder_agent_state_json', 'AI Site State JSON', 'mdi mdi-code-json', 'Read workspace state JSON', 'Weline_Websites::site_builder_agent')]
    public function getStateJson(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid parameters')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('Session not found or access denied')]);
        }

        return $this->fetchJson([
            'success' => true,
            'data' => $this->buildWorkspaceState($session, $adminId),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_create_session', 'Create AI Site Workspace', 'mdi mdi-plus', 'Create a resumable AI site workspace', 'Weline_Websites::site_builder_agent')]
    public function postCreateSession(): string
    {
        $adminId = $this->getAdminId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('Login required')]);
        }

        $providerCode = \trim((string)$this->getRequestBodyValue('provider_code', 'websites_default'));
        if ($providerCode === '') {
            $providerCode = 'websites_default';
        }

        $provider = $this->getProviderRegistry()->getProvider($providerCode);
        if ($provider === null || !$provider->isEnabled()) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Unknown or disabled provider: %{code}', ['code' => $providerCode]),
            ]);
        }

        $description = \trim((string)$this->getRequestBodyValue('description', ''));
        $domain = \strtolower(\trim((string)$this->getRequestBodyValue('domain', '')));
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
            $scope['target_domain'] = $domain;
        }
        if ($accountId > 0) {
            $scope['preferred_registrar_account_id'] = $accountId;
            $scope['registrar_account_id'] = $accountId;
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
                \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [],
                \is_array($providerConfig['provider_state'] ?? null) ? $providerConfig['provider_state'] : [],
                (string)($providerConfig['initial_stage'] ?? 'prepare')
            );
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Failed to create workspace: %{message}', ['message' => $e->getMessage()]),
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
                'use_ai' => $useAi ? 1 : 0,
                'fake_mode' => $fakeMode ? 1 : 0,
                'has_description' => $description !== '',
                'has_domain' => $domain !== '',
                'account_id' => $accountId,
            ],
            AiSiteBuilderEvent::LEVEL_INFO
        );

        $welcomeMessage = \trim((string)($providerConfig['welcome_message'] ?? $this->buildWorkspaceWelcomeMessage($providerCode)));
        if ($description !== '') {
            $this->getMessageService()->appendMessage($sessionId, $adminId, 'user', $description, 'brief');
        }
        if ($welcomeMessage !== '') {
            $this->getMessageService()->appendMessage($sessionId, $adminId, 'assistant', $welcomeMessage, 'system');
        }

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
            'provider_name' => (string)($providerConfig['name'] ?? $providerCode),
            'native_entry_url' => (string)($providerConfig['native_entry_url'] ?? ''),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_merge_scope', 'Merge Workspace Scope', 'mdi mdi-database-plus-outline', 'Merge workspace scope JSON', 'Weline_Websites::site_builder_agent')]
    public function postMergeScope(): string
    {
        return $this->jsonMutateScope(true);
    }

    #[Acl('Weline_Websites::site_builder_agent_replace_scope', 'Replace Workspace Scope', 'mdi mdi-database-edit-outline', 'Replace workspace scope JSON', 'Weline_Websites::site_builder_agent')]
    public function postReplaceScope(): string
    {
        return $this->jsonMutateScope(false);
    }

    #[Acl('Weline_Websites::site_builder_agent_set_stage', 'Set Workspace Stage', 'mdi mdi-flag-checkered', 'Update the current workspace stage', 'Weline_Websites::site_builder_agent')]
    public function postSetStage(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $stage = $this->resolveJourneyStage((string)$this->getRequestBodyValue('stage', ''));

        if ($adminId <= 0 || $publicId === '' || $stage === null) {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid parameters')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('Session not found or access denied')]);
        }

        if (!$this->getSessionService()->setStage($session->getId(), $adminId, $stage)) {
            return $this->fetchJson(['success' => false, 'message' => __('Failed to update stage')]);
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

    #[Acl('Weline_Websites::site_builder_agent_append_message', 'Append Workspace Message', 'mdi mdi-message-plus-outline', 'Append a note or message to the workspace', 'Weline_Websites::site_builder_agent')]
    public function postAppendMessage(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $content = \trim((string)$this->getRequestBodyValue('content', ''));
        $role = \trim((string)$this->getRequestBodyValue('role', 'user'));
        $messageType = \trim((string)$this->getRequestBodyValue('message_type', 'note'));

        if ($adminId <= 0 || $publicId === '' || $content === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid parameters')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('Session not found or access denied')]);
        }

        if (!\in_array($role, ['user', 'assistant', 'system'], true)) {
            $role = 'user';
        }
        if ($messageType === '') {
            $messageType = 'note';
        }

        if (!$this->getMessageService()->appendMessage($session->getId(), $adminId, $role, $content, $messageType)) {
            return $this->fetchJson(['success' => false, 'message' => __('Failed to save message')]);
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

    #[Acl('Weline_Websites::site_builder_agent_stream', 'Workspace SSE Stream', 'mdi mdi-access-point', 'Stream workspace events', 'Weline_Websites::site_builder_agent')]
    public function getStreamSse(): void
    {
        $sse = new SseWriter();
        $sse->start();

        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $lastEventId = (int)$this->request->getGet('last_event_id', 0);

        if ($adminId <= 0 || $publicId === '') {
            $sse->sendError(__('Invalid parameters'));
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $sse->sendError(__('Session not found or access denied'));
            $sse->complete(['success' => false]);
            return;
        }

        $sse->sendEvent('start', ['message' => __('Connected to workspace event stream')]);
        $sse->sendEvent('snapshot', $this->buildWorkspaceState($session, $adminId, 40, 40));

        $deadline = \time() + 900;
        $sessionId = $session->getId();
        while (\time() < $deadline && $sse->isAlive()) {
            $events = $this->getEventStreamService()->listEventsAfterId($sessionId, $adminId, $lastEventId, 80);
            foreach ($events as $event) {
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
            'message' => __('Event stream finished. Reconnect any time to continue listening.'),
            'last_event_id' => $lastEventId,
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_trigger', 'Trigger Site Build', 'mdi mdi-play', 'Trigger the site building flow')]
    public function getTriggerSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => __('Starting the guided AI site build flow...')]);

        try {
            $description = \trim((string)$this->request->getGet('description', ''));
            $domain = \strtolower(\trim((string)$this->request->getGet('domain', '')));
            $accountId = (int)$this->request->getGet('account_id', 0);
            $useAi = ($this->request->getGet('use_ai', '1') === '1');
            $fakeMode = $this->isFakeModeRequested();

            if (!$useAi && $domain === '') {
                $sse->sendEvent('error', ['message' => __('Please fill in a target domain first')]);
                $sse->complete(['success' => false]);
                return;
            }
            if (!$useAi && $accountId <= 0) {
                $sse->sendEvent('error', ['message' => __('Please choose a registrar account first')]);
                $sse->complete(['success' => false]);
                return;
            }
            if ($description === '' && $domain === '') {
                $sse->sendEvent('error', ['message' => __('Please describe the site goal or provide a domain first')]);
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
                $sse->sendEvent('error', ['message' => __('Please provide both the target domain and registrar account, or enable AI mode first.')]);
                $sse->complete(['success' => false]);
                return;
            }

            $finalDescription = $description !== '' ? $description : $domain;
            $itemExtras = [];
            $clientIp = \trim((string)$this->request->getClientIp());
            if ($clientIp !== '' && \filter_var($clientIp, FILTER_VALIDATE_IP)) {
                $itemExtras['user_client_ip'] = $clientIp;
            }

            /** @var WebsiteAgentService $agentService */
            $agentService = ObjectManager::getInstance(WebsiteAgentService::class);
            $result = $agentService->buildFromDescription(
                $finalDescription,
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
                    'message' => $result['message'] ?? __('Site build completed'),
                    'domain' => $result['domain'] ?? '',
                    'website_id' => $result['website_id'] ?? 0,
                ]);
                return;
            }

            $sse->sendEvent('error', ['message' => $result['message'] ?? __('Execution failed')]);
            $sse->complete(['success' => false]);
        } catch (\Throwable $e) {
            $sse->sendEvent('error', [
                'message' => $e->getMessage(),
                'detail' => __('The guided site build flow failed unexpectedly'),
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
        $resolvedDescription = \trim($description);
        $resolvedDomain = \strtolower(\trim($domain));
        if ($resolvedDescription === '' && $resolvedDomain !== '') {
            $resolvedDescription = $resolvedDomain;
        }

        $promptLines = [
            'You are the AI Site Workbench planner for website creation.',
            'Keep the flow simple, human-friendly, and suitable for non-technical admins.',
            'Always think in three stages: prepare, generate, complete.',
            'Prepare stage: recommend 1 to 3 domains, the best registrar account, and the purchase to DNS to certificate sequence.',
            'Generate stage: recommend the default page plan, theme direction, and content sections.',
            'Complete stage: summarize preview checks and readiness before delivery.',
            'Prefer short, actionable recommendations and use tools when available.',
        ];
        if ($resolvedDescription !== '') {
            $promptLines[] = 'Site brief: ' . $resolvedDescription;
        }
        if ($resolvedDomain !== '') {
            $promptLines[] = 'Preferred domain: ' . $resolvedDomain;
            $promptLines[] = 'Use the preferred domain unless you have a clearly better option.';
        }
        if ($accountId > 0) {
            $promptLines[] = 'Preferred registrar account id: ' . $accountId;
        }

        $prompt = \implode("\n", $promptLines);
        $params = ['account_id' => $accountId];

        $mapEvent = static function (string $eventType, array $data) use ($sse): void {
            $message = $data['message'] ?? $data['content'] ?? null;
            if (\is_string($message) && $message !== '') {
                $sse->sendEvent('progress', ['message' => $message]);
            }
            if ($eventType === 'tool_call' && isset($data['name'])) {
                $sse->sendEvent('info', ['message' => __('AI is calling tool: %{name}', ['name' => (string)$data['name']])]);
            }
            if ($eventType === 'tool_result' && isset($data['name'])) {
                $sse->sendEvent('info', ['message' => __('Tool completed: %{name}', ['name' => (string)$data['name']])]);
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
                'message' => $result->success ? __('AI planning completed') : ($result->error ?? __('AI execution failed')),
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
        $registrar = $this->recommendRegistrarAccount($accountId);
        $resolvedAccountId = $accountId > 0
            ? $accountId
            : (int)($registrar['account_id'] ?? 0);
        $registrarLabel = (string)($registrar['display'] ?? __('Local registrar recommendation unavailable'));
        $brief = $description !== '' ? $description : $resolvedDomain;
        $seed = $brief . '|' . $resolvedDomain . '|' . ($useAi ? 'ai' : 'manual') . '|' . $resolvedAccountId;
        $hash = \substr(\hash('sha256', $seed), 0, 12);
        $websiteId = 800000 + (\hexdec(\substr($hash, 0, 4)) % 10000);
        $themeId = 600000 + (\hexdec(\substr($hash, 4, 4)) % 10000);
        $previewUrl = $this->buildSimulatedPreviewUrl($resolvedDomain, 'websites_default', []);

        $timeline = [
            ['progress', ['message' => (string)__('Local demo: brief understood'), 'stage' => 'prepare', 'fake_mode' => true]],
            ['info', ['message' => (string)__('Local demo: recommended registrar %{registrar}', ['registrar' => $registrarLabel]), 'stage' => 'prepare', 'account_id' => $resolvedAccountId, 'fake_mode' => true]],
            ['info', ['message' => (string)__('Local demo: suggested domain %{domain}', ['domain' => $resolvedDomain]), 'stage' => 'prepare', 'domain' => $resolvedDomain, 'fake_mode' => true]],
            ['info', ['message' => (string)__('Local demo: simulated availability check passed for %{domain}', ['domain' => $resolvedDomain]), 'stage' => 'prepare', 'domain' => $resolvedDomain, 'availability' => 'simulated_available', 'fake_mode' => true]],
            ['progress', ['message' => (string)__('Local demo: simulated domain purchase and bootstrap resources'), 'stage' => 'prepare', 'domain' => $resolvedDomain, 'account_id' => $resolvedAccountId, 'registrar' => $registrarLabel, 'fake_mode' => true]],
            ['progress', ['message' => (string)__('Local demo: simulated DNS resolution and certificate issuance'), 'stage' => 'complete', 'domain' => $resolvedDomain, 'dns_status' => 'simulated_ready', 'certificate_status' => 'simulated_issued', 'fake_mode' => true]],
            ['progress', ['message' => (string)__('Local demo: generated page structure and starter content'), 'stage' => 'generate', 'website_id' => $websiteId, 'fake_mode' => true]],
            ['progress', ['message' => (string)__('Local demo: generated theme direction and virtual theme'), 'stage' => 'generate', 'theme_id' => $themeId, 'fake_mode' => true]],
            ['progress', ['message' => (string)__('Local demo: prepared visual-edit preview'), 'stage' => 'complete', 'preview_url' => $previewUrl, 'fake_mode' => true]],
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
            'account_id' => $resolvedAccountId,
            'registrar' => $registrarLabel,
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
        /** @var DomainRegistrarAccount $accountModel */
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

        return $activeAccounts !== [] ? $activeAccounts : $this->getSimulatedAccounts();
    }

    /**
     * @return list<array{account_id:int,account_name:string,registrar_name:string,registrar_code:string}>
     */
    private function getSimulatedAccounts(): array
    {
        return [
            [
                'account_id' => 900001,
                'account_name' => (string)__('Local demo primary account'),
                'registrar_name' => (string)__('Local demo registrar'),
                'registrar_code' => 'local_demo',
            ],
            [
                'account_id' => 900002,
                'account_name' => (string)__('Local demo backup account'),
                'registrar_name' => (string)__('Sandbox domains'),
                'registrar_code' => 'sandbox_demo',
            ],
        ];
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
        $cards = [];

        try {
            $providers = $this->getProviderRegistry()->getProviders(true);
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
                'code' => (string)($providerConfig['code'] ?? $code),
                'name' => (string)($providerConfig['name'] ?? $code),
                'description' => (string)($providerConfig['description'] ?? ''),
                'badge' => (string)($providerConfig['badge'] ?? ''),
                'target_url' => (string)($providerConfig['target_url'] ?? ''),
                'target_label' => (string)($providerConfig['target_label'] ?? ''),
                'workspace_label' => (string)($providerConfig['workspace_label'] ?? ''),
                'selected' => $selectedProvider === $code,
            ];
        }

        if ($cards === []) {
            $fallback = $this->getProviderWorkbenchService()->buildWorkbenchConfig('websites_default', $this->getAdminId());
            $cards[] = [
                'code' => 'websites_default',
                'name' => (string)($fallback['name'] ?? 'AI Site Workbench'),
                'description' => (string)($fallback['description'] ?? ''),
                'badge' => (string)($fallback['badge'] ?? ''),
                'target_url' => (string)($fallback['target_url'] ?? ''),
                'target_label' => (string)($fallback['target_label'] ?? ''),
                'workspace_label' => (string)($fallback['workspace_label'] ?? ''),
                'selected' => $selectedProvider === 'websites_default',
            ];
        }

        return $cards;
    }

    private function resolveProviderEntryUrl(Url $urlHelper, string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => $urlHelper->getBackendUrl('pagebuilder/backend/aiSiteAgent/index', ['legacy' => 1]),
            'websites_default' => $this->getHubEntryUrl('websites_default'),
            default => $this->getHubEntryUrl($providerCode),
        };
    }

    private function resolveProviderActionLabel(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('Open PageBuilder flow'),
            'websites_default' => (string)__('Open Websites flow'),
            default => (string)__('Open provider flow'),
        };
    }

    private function resolveProviderBadge(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('Styles flow'),
            'websites_default' => (string)__('Shared flow'),
            default => (string)__('Provider flow'),
        };
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function getStageOptions(): array
    {
        return [
            ['value' => 'prepare', 'label' => (string)__('Prepare')],
            ['value' => 'generate', 'label' => (string)__('Generate')],
            ['value' => 'complete', 'label' => (string)__('Complete')],
        ];
    }

    private function getStageLabel(string $stage): string
    {
        $normalized = $this->normalizeJourneyStage($stage);
        foreach ($this->getStageOptions() as $option) {
            if (($option['value'] ?? '') === $normalized) {
                return (string)($option['label'] ?? $normalized);
            }
        }

        return $normalized;
    }

    private function resolveJourneyStage(string $stage): ?string
    {
        $stage = \trim($stage);
        if ($stage === '') {
            return null;
        }

        return match ($stage) {
            'prepare', 'brief', 'domain', 'domain_wait' => 'prepare',
            'generate', 'virtual_theme', 'page_types', 'content', 'visual_edit' => 'generate',
            'complete', 'publish' => 'complete',
            default => null,
        };
    }

    private function normalizeJourneyStage(string $stage): string
    {
        return $this->resolveJourneyStage($stage) ?? 'prepare';
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildStageGuides(array $providerConfig, array $scope, string $currentStage): array
    {
        $providerCode = (string)($providerConfig['code'] ?? $scope['provider_code'] ?? 'websites_default');
        $providerTools = \is_array($providerConfig['tools'] ?? null) ? $providerConfig['tools'] : [];
        $stageOrder = ['prepare', 'generate', 'complete'];
        $currentStage = $this->normalizeJourneyStage($currentStage);
        $currentIndex = \array_search($currentStage, $stageOrder, true);
        if (!\is_int($currentIndex)) {
            $currentIndex = 0;
        }

        $guideMap = [];
        $guides = \is_array($providerConfig['stage_guides'] ?? null) ? $providerConfig['stage_guides'] : [];
        foreach ($guides as $guideKey => $guide) {
            if (!\is_array($guide)) {
                continue;
            }

            $rawCode = \trim((string)($guide['code'] ?? ''));
            if ($rawCode === '' && \is_string($guideKey)) {
                $rawCode = $guideKey;
            }
            $code = $this->normalizeJourneyStage($rawCode !== '' ? $rawCode : 'prepare');
            $guide['code'] = $code;
            $guideMap[$code] = $guide;
        }

        $fallbacks = [
            'prepare' => [
                'label' => 'Stage 1',
                'title' => 'Information preparation',
                'description' => 'Collect the brief, confirm the domain direction, and choose the registrar provider.',
                'ai_recommendation_title' => 'AI recommendation',
                'ai_recommendation' => 'AI can recommend the supplier, the domain, and the next confirmation step.',
                'confirm_label' => 'Confirm and continue',
                'previous_label' => '',
                'next_stage' => 'generate',
                'previous_stage' => '',
                'key_points' => [
                    'Describe the site goal in one short brief.',
                    'Let AI recommend the domain and supplier first.',
                    'Confirm the recommendation with one click.',
                ],
            ],
            'generate' => [
                'label' => 'Stage 2',
                'title' => 'Page generation',
                'description' => 'Start from the default page plan and let AI recommend the theme and editable sections.',
                'ai_recommendation_title' => 'AI page plan',
                'ai_recommendation' => 'AI can recommend the page structure, the theme direction, and the content layout.',
                'confirm_label' => 'Use this plan',
                'previous_label' => 'Back to preparation',
                'next_stage' => 'complete',
                'previous_stage' => 'prepare',
                'key_points' => [
                    'Use the default page types.',
                    'Header and Footer stay fixed.',
                    'Generate only the editable content sections with AI.',
                ],
            ],
            'complete' => [
                'label' => 'Stage 3',
                'title' => 'Complete',
                'description' => 'Preview the result, wait for the domain to be ready, and go back only if refinement is needed.',
                'ai_recommendation_title' => 'AI final check',
                'ai_recommendation' => 'AI can highlight the last checks before delivery and preview.',
                'confirm_label' => 'Keep current result',
                'previous_label' => 'Back to generation',
                'next_stage' => '',
                'previous_stage' => 'generate',
                'key_points' => [
                    'Track purchase, DNS, and certificate readiness.',
                    'Preview before delivery.',
                    'Go back only when the preview needs refinement.',
                ],
            ],
        ];

        $prepared = [];
        foreach ($stageOrder as $index => $code) {
            $guide = $guideMap[$code] ?? [];
            $dynamic = $this->buildStageRecommendationData($code, $providerCode, $scope);
            $toolCodes = \is_array($guide['tool_codes'] ?? null) ? $guide['tool_codes'] : [];
            $guidePatch = \is_array($guide['scope_patch'] ?? null) ? $guide['scope_patch'] : [];
            $dynamicPatch = \is_array($dynamic['patch'] ?? null) ? $dynamic['patch'] : [];
            $items = \is_array($dynamic['items'] ?? null) ? $dynamic['items'] : [];

            $defaults = $fallbacks[$code];
            $baseRecommendation = \trim((string)($guide['ai_recommendation'] ?? $defaults['ai_recommendation']));
            $dynamicRecommendation = \trim((string)($dynamic['summary'] ?? ''));
            $recommendation = \trim($baseRecommendation . ($baseRecommendation !== '' && $dynamicRecommendation !== '' ? ' ' : '') . $dynamicRecommendation);

            $prepared[] = [
                'code' => $code,
                'label' => \trim((string)($guide['label'] ?? $defaults['label'])),
                'title' => \trim((string)($guide['title'] ?? $defaults['title'])),
                'description' => \trim((string)($guide['description'] ?? $defaults['description'])),
                'ai_recommendation_title' => \trim((string)($guide['ai_recommendation_title'] ?? $defaults['ai_recommendation_title'])),
                'ai_recommendation' => $recommendation,
                'confirm_label' => \trim((string)($guide['confirm_label'] ?? $defaults['confirm_label'])),
                'previous_label' => \trim((string)($guide['previous_label'] ?? $defaults['previous_label'])),
                'next_stage' => $this->resolveJourneyStage((string)($guide['next_stage'] ?? $defaults['next_stage'])) ?? '',
                'previous_stage' => $this->resolveJourneyStage((string)($guide['previous_stage'] ?? $defaults['previous_stage'])) ?? '',
                'key_points' => \is_array($guide['key_points'] ?? null) ? $guide['key_points'] : $defaults['key_points'],
                'tools' => $this->buildStageTools($code, $toolCodes, $providerTools),
                'recommendation_items' => $items,
                'recommendation_patch' => \array_replace($guidePatch, $dynamicPatch),
                'status' => $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'upcoming'),
                'is_current' => $index === $currentIndex,
                'is_done' => $index < $currentIndex,
                'is_upcoming' => $index > $currentIndex,
            ];
        }

        return $prepared;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{
     *   summary:string,
     *   items:list<string>,
     *   patch:array<string, mixed>
     * }
     */
    private function buildStageRecommendationData(string $stage, string $providerCode, array $scope): array
    {
        $stage = $this->normalizeJourneyStage($stage);
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $targetDomain = \strtolower(\trim((string)($scope['target_domain'] ?? $scope['selected_domain'] ?? '')));
        $registrarAccountId = (int)($scope['preferred_registrar_account_id'] ?? $scope['registrar_account_id'] ?? 0);
        $isFakeMode = !empty($scope['fake_mode']) || (string)($scope['build_execution_mode'] ?? '') === 'local_fake_demo';

        if ($stage === 'prepare') {
            $registrar = $this->recommendRegistrarAccount($registrarAccountId);
            $suggestions = $this->buildDomainSuggestions($brief, $targetDomain);
            $recommendedDomain = $targetDomain !== '' ? $targetDomain : ($suggestions[0] ?? $this->buildFakeDomainSuggestion($brief));
            $registrarLabel = (string)($registrar['display'] ?? __('暂无推荐服务商'));
            $patch = [
                'journey_stage' => 'prepare',
                'target_domain' => $recommendedDomain,
                'selected_domain' => $recommendedDomain,
                'domain_suggestions' => $suggestions,
                'recommended_domain_list' => $suggestions,
                'domain_availability_status' => $isFakeMode ? 'simulated_available' : 'recommended_pending_check',
                'preferred_registrar_account_id' => (int)($registrar['account_id'] ?? 0),
                'registrar_account_id' => (int)($registrar['account_id'] ?? 0),
                'recommended_registrar_label' => $registrarLabel,
                'domain_setup_steps' => ['purchase', 'dns', 'certificate'],
                'domain_setup_status' => 'awaiting_confirmation',
            ];

            return [
                'summary' => (string)__('AI 建议优先用 %{registrar} 处理 %{domain}，确认后按“购买 -> 解析 -> 证书”的顺序自动推进。', [
                    'registrar' => $registrarLabel,
                    'domain' => $recommendedDomain,
                ]),
                'items' => [
                    (string)__('推荐域名：%{domain}', ['domain' => $recommendedDomain]),
                    (string)__('推荐服务商：%{registrar}', ['registrar' => $registrarLabel]),
                    (string)__('确认后可直接进入购买、解析和证书准备'),
                ],
                'patch' => $patch,
            ];
        }

        if ($stage === 'generate') {
            $pages = $this->buildRecommendedPages($brief, $providerCode, $scope);
            $pageSummary = $pages === [] ? '' : \implode('、', \array_slice($pages, 0, 5));

            if ($providerCode === 'pagebuilder') {
                $styleTemplate = $this->resolvePageBuilderStyleTemplate($brief, $scope);
                return [
                    'summary' => (string)__('AI 建议先选 PageBuilder 的 %{template} styles/ 模板，再生成默认页面和可编辑内容区域。', [
                        'template' => $styleTemplate,
                    ]),
                    'items' => [
                        (string)__('推荐 styles 模板：%{template}', ['template' => $styleTemplate]),
                        (string)__('建议页面：%{pages}', ['pages' => $pageSummary !== '' ? $pageSummary : (string)__('首页、关于、联系页')]),
                        (string)__('Header 和 Footer 固定，AI 只生成主体内容组件'),
                    ],
                    'patch' => [
                        'journey_stage' => 'generate',
                        'preferred_editor' => 'pagebuilder',
                        'preferred_flow' => 'pagebuilder_style_template',
                        'pagebuilder_theme_source' => 'styles',
                        'pagebuilder_style_template' => $styleTemplate,
                        'style_template_code' => $styleTemplate,
                        'theme_generation_mode' => 'existing_style_template',
                        'content_generation_mode' => 'ai_sections',
                        'header_footer_locked' => 1,
                        'recommended_pages' => $pages,
                        'page_types' => $pages,
                    ],
                ];
            }

            $themeDirection = $this->resolveWebsiteThemeDirection($brief, $scope);
            return [
                'summary' => (string)__('AI 建议先用“%{theme}”方向生成默认页面，再补齐每个页面的主要内容区块。', [
                    'theme' => $themeDirection,
                ]),
                'items' => [
                    (string)__('推荐主题方向：%{theme}', ['theme' => $themeDirection]),
                    (string)__('建议页面：%{pages}', ['pages' => $pageSummary !== '' ? $pageSummary : (string)__('首页、关于、联系页')]),
                    (string)__('Header 和 Footer 固定，正文区域可继续让 AI 生成'),
                ],
                'patch' => [
                    'journey_stage' => 'generate',
                    'theme_direction' => $themeDirection,
                    'theme_generation_mode' => 'ai_new_theme',
                    'content_generation_mode' => 'ai_sections',
                    'header_footer_locked' => 1,
                    'recommended_pages' => $pages,
                    'page_types' => $pages,
                ],
            ];
        }

        $previewUrl = $this->buildSimulatedPreviewUrl($targetDomain, $providerCode, $scope);
        $domainStatus = (string)($scope['domain_purchase_status'] ?? ($isFakeMode ? 'simulated_purchased' : 'awaiting_purchase'));
        $dnsStatus = (string)($scope['dns_status'] ?? ($isFakeMode ? 'simulated_ready' : 'awaiting_dns'));
        $certificateStatus = (string)($scope['certificate_status'] ?? ($isFakeMode ? 'simulated_issued' : 'awaiting_certificate'));

        return [
            'summary' => (string)__('AI 建议最后先看预览，再确认域名购买、解析和证书都已经进入可交付状态。'),
            'items' => [
                (string)__('域名状态：%{status}', ['status' => $domainStatus]),
                (string)__('解析状态：%{status}', ['status' => $dnsStatus]),
                (string)__('证书状态：%{status}', ['status' => $certificateStatus]),
            ],
            'patch' => [
                'journey_stage' => 'complete',
                'domain_purchase_status' => $domainStatus,
                'dns_status' => $dnsStatus,
                'certificate_status' => $certificateStatus,
                'preview_url' => $previewUrl,
                'preview_full_url' => $previewUrl,
                'delivery_mode' => 'preview_then_publish',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function buildDomainSuggestions(string $description, string $preferredDomain = ''): array
    {
        $preferredDomain = \strtolower(\trim($preferredDomain));
        $suggestions = [];

        if ($preferredDomain !== '') {
            $suggestions[] = $preferredDomain;
        }

        try {
            $agentSuggestions = $this->getWebsiteAgentService()->suggestDomainsFromDescription($description);
            if (\is_array($agentSuggestions)) {
                foreach ($agentSuggestions as $suggestion) {
                    if (!\is_string($suggestion)) {
                        continue;
                    }
                    $suggestion = \strtolower(\trim($suggestion));
                    if ($suggestion !== '') {
                        $suggestions[] = $suggestion;
                    }
                }
            }
        } catch (\Throwable) {
        }

        $fallback = $this->buildFakeDomainSuggestion($description !== '' ? $description : 'smart site');
        $suggestions[] = $fallback;
        foreach (['.com', '.net', '.site'] as $suffix) {
            $base = \preg_replace('/\.[a-z0-9.-]+$/i', '', $fallback) ?? 'smartsite';
            $suggestions[] = $base . $suffix;
        }

        $normalized = [];
        foreach ($suggestions as $suggestion) {
            if (!\is_string($suggestion)) {
                continue;
            }
            $suggestion = \strtolower(\trim($suggestion));
            if ($suggestion === '' || !\preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/', $suggestion)) {
                continue;
            }
            if (\in_array($suggestion, $normalized, true)) {
                continue;
            }
            $normalized[] = $suggestion;
        }

        return \array_slice($normalized, 0, 3);
    }

    /**
     * @return array{account_id:int,account_name:string,registrar_name:string,registrar_code:string,display:string}
     */
    private function recommendRegistrarAccount(int $preferredAccountId = 0): array
    {
        $accounts = $this->getActiveAccounts();
        $chosen = null;

        foreach ($accounts as $account) {
            if ((int)($account['account_id'] ?? 0) === $preferredAccountId && $preferredAccountId > 0) {
                $chosen = $account;
                break;
            }
        }

        if ($chosen === null) {
            foreach ($accounts as $account) {
                $registrarCode = \strtolower((string)($account['registrar_code'] ?? ''));
                $registrarName = \strtolower((string)($account['registrar_name'] ?? ''));
                if ($this->containsAny($registrarCode . ' ' . $registrarName, ['sandbox', 'demo', 'simulated'])) {
                    $chosen = $account;
                    break;
                }
            }
        }

        if ($chosen === null) {
            $chosen = $accounts[0] ?? [
                'account_id' => 0,
                'account_name' => '',
                'registrar_name' => '',
                'registrar_code' => '',
            ];
        }

        $registrarName = \trim((string)($chosen['registrar_name'] ?? ''));
        $accountName = \trim((string)($chosen['account_name'] ?? ''));

        return [
            'account_id' => (int)($chosen['account_id'] ?? 0),
            'account_name' => $accountName,
            'registrar_name' => $registrarName,
            'registrar_code' => (string)($chosen['registrar_code'] ?? ''),
            'display' => \trim($registrarName . ($accountName !== '' ? ' - ' . $accountName : '')),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function buildRecommendedPages(string $description, string $providerCode, array $scope): array
    {
        $pages = ['首页', '关于我们', '产品/服务', 'FAQ', '联系我们'];
        $haystack = \strtolower($description . ' ' . (string)($scope['site_tagline'] ?? ''));

        if ($this->containsAny($haystack, ['story', 'brand', 'coffee', 'about', '品牌', '故事'])) {
            $pages[] = '品牌故事';
        }
        if ($this->containsAny($haystack, ['pricing', 'price', 'subscription', '套餐', '订阅'])) {
            $pages[] = '定价/套餐';
        }
        if ($this->containsAny($haystack, ['blog', 'news', 'article', '内容', '文章'])) {
            $pages[] = '博客/资讯';
        }
        if ($this->containsAny($haystack, ['case', 'portfolio', 'showcase', '案例', '作品'])) {
            $pages[] = '案例展示';
        }

        if ($providerCode === 'pagebuilder' && !$this->containsAny($haystack, ['landing', 'campaign', '活动'])) {
            $pages[] = '落地页';
        }

        return \array_values(\array_unique($pages));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveWebsiteThemeDirection(string $description, array $scope): string
    {
        $haystack = \strtolower($description . ' ' . (string)($scope['site_tagline'] ?? ''));

        if ($this->containsAny($haystack, ['finance', 'loan', 'bank', 'capital', 'payment', '金融', '支付'])) {
            return (string)__('可信金融展示风格');
        }
        if ($this->containsAny($haystack, ['fitness', 'gym', 'health', 'coach', '运动', '健身'])) {
            return (string)__('高转化健身课程风格');
        }
        if ($this->containsAny($haystack, ['saas', 'software', 'platform', 'tool', 'crm', 'subscription', '软件', '平台'])) {
            return (string)__('简洁清晰的 SaaS 产品风格');
        }
        if ($this->containsAny($haystack, ['brand', 'coffee', 'outdoor', 'story', '品牌', '故事', '装备'])) {
            return (string)__('品牌叙事加产品展示风格');
        }

        return (string)__('简洁可信的品牌官网风格');
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolvePageBuilderStyleTemplate(string $description, array $scope): string
    {
        foreach (['pagebuilder_style_template', 'style_template_code'] as $field) {
            $value = \trim((string)($scope[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $haystack = \strtolower($description . ' ' . (string)($scope['site_tagline'] ?? ''));
        if ($this->containsAny($haystack, ['finance', 'loan', 'bank', 'capital', 'payment', '金融', '支付'])) {
            return 'fintech-hub';
        }
        if ($this->containsAny($haystack, ['fitness', 'gym', 'health', 'coach', '运动', '健身'])) {
            return 'fitness-pro';
        }
        if ($this->containsAny($haystack, ['rummy', '棋牌', '拉米'])) {
            return 'rummy-royal';
        }
        if ($this->containsAny($haystack, ['poker', '德州', '扑克'])) {
            return 'poker-arena';
        }
        if ($this->containsAny($haystack, ['ludo', '飞行棋'])) {
            return 'ludo-empire';
        }
        if ($this->containsAny($haystack, ['casino', 'game', 'bet', 'slot', '游戏', '娱乐'])) {
            return 'sattaking';
        }
        if ($this->containsAny($haystack, ['app', 'mobile', 'download', '落地页', '下载'])) {
            return 'tpmst';
        }

        return 'saas-starter';
    }

    /**
     * @param list<string> $toolCodes
     * @param list<array<string, mixed>> $providerTools
     * @return list<array<string, mixed>>
     */
    private function buildStageTools(string $stage, array $toolCodes, array $providerTools): array
    {
        if ($providerTools === []) {
            return [];
        }

        $stage = $this->normalizeJourneyStage($stage);
        $codeMap = $toolCodes !== [] ? \array_fill_keys($toolCodes, true) : [];
        $tools = [];

        foreach ($providerTools as $tool) {
            if (!\is_array($tool)) {
                continue;
            }

            $toolCode = \trim((string)($tool['code'] ?? ''));
            if ($toolCode === '') {
                continue;
            }

            if ($codeMap !== [] && !isset($codeMap[$toolCode])) {
                continue;
            }

            $toolStage = $this->resolveJourneyStage((string)($tool['stage'] ?? ''));
            if ($codeMap === [] && $toolStage !== null && $toolStage !== $stage) {
                continue;
            }

            $tools[] = $tool;
        }

        return $tools;
    }

    /**
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        $haystack = \strtolower($haystack);
        foreach ($needles as $needle) {
            if ($needle !== '' && \str_contains($haystack, \strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{
     *   public_id:string,
     *   provider_name:string,
     *   stage_label:string,
     *   summary:string,
     *   update_time:string,
     *   workspace_url:string,
     *   native_entry_url:string
     * }>
     */
    private function getRecentSessionCards(int $adminUserId, string $excludePublicId = ''): array
    {
        $cards = [];
        $sessions = $this->getSessionService()->listRecentSessionsForAdmin($adminUserId, 8);

        foreach ($sessions as $sessionRow) {
            $publicId = (string)($sessionRow['public_id'] ?? '');
            if ($publicId === '' || $publicId === $excludePublicId) {
                continue;
            }

            $session = $this->getSessionService()->loadById((int)($sessionRow['session_id'] ?? 0), $adminUserId);
            if ($session === null) {
                continue;
            }

            $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminUserId);
            $scope = \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [];
            $summary = (string)($scope['site_title'] ?? '');
            if ($summary === '') {
                $summary = (string)($scope['brief_description'] ?? $scope['user_description'] ?? $scope['target_domain'] ?? $session->getSelectedDomain());
            }

            $cards[] = [
                'public_id' => $publicId,
                'provider_name' => (string)($providerConfig['name'] ?? $session->getProviderCode()),
                'stage_label' => $this->getStageLabel($session->getCurrentStage()),
                'summary' => $this->buildContentPreview($summary),
                'update_time' => (string)($sessionRow['update_time'] ?? ''),
                'workspace_url' => $this->getWorkspaceUrl($publicId),
                'native_entry_url' => (string)($providerConfig['native_entry_url'] ?? ''),
            ];
        }

        return $cards;
    }

    /**
     * @return array{
     *   session:array<string, mixed>,
     *   stage_guides:list<array<string, mixed>>,
     *   messages:list<array<string, mixed>>,
     *   events:list<array<string, mixed>>,
     *   artifacts:array<string, mixed>,
     *   provider_tools:list<array<string, mixed>>
     * }
     */
    private function buildWorkspaceState(
        AiSiteBuilderSession $session,
        int $adminUserId,
        int $messageLimit = 120,
        int $eventLimit = 120
    ): array {
        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminUserId);
        $scope = \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [];
        $providerState = \is_array($providerConfig['provider_state'] ?? null) ? $providerConfig['provider_state'] : [];
        $currentStage = $this->normalizeJourneyStage($session->getCurrentStage());

        return [
            'session' => [
                'session_id' => $session->getId(),
                'public_id' => $session->getPublicId(),
                'provider_code' => $session->getProviderCode(),
                'provider_name' => (string)($providerConfig['name'] ?? $session->getProviderCode()),
                'provider_context' => $this->extractProviderContext($providerConfig),
                'current_stage' => $currentStage,
                'stage_label' => $this->getStageLabel($currentStage),
                'website_id' => $session->getWebsiteId(),
                'selected_domain' => $session->getSelectedDomain(),
                'registrar_account_id' => $session->getRegistrarAccountId(),
                'preview_url' => $session->getPreviewUrl(),
                'workspace_url' => $this->getWorkspaceUrl($session->getPublicId()),
                'native_entry_url' => (string)($providerConfig['native_entry_url'] ?? ''),
                'scope' => $scope,
                'provider_state' => $providerState,
            ],
            'stage_guides' => $this->buildStageGuides($providerConfig, $scope, $currentStage),
            'messages' => $this->getMessageService()->listForSession($session->getId(), $adminUserId, $messageLimit),
            'events' => $this->getEventStreamService()->listRecentEvents($session->getId(), $adminUserId, $eventLimit),
            'artifacts' => [
                'snapshot' => $this->getArtifactService()->getOne($session->getId(), $adminUserId, 'workspace', 'scope_snapshot'),
                'handoff' => $this->getArtifactService()->getOne($session->getId(), $adminUserId, 'handoff', $session->getProviderCode()),
            ],
            'provider_tools' => \is_array($providerConfig['tools'] ?? null) ? $providerConfig['tools'] : [],
        ];
    }

    private function jsonMutateScope(bool $merge): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));

        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Invalid parameters')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('Session not found or access denied')]);
        }

        $jsonKey = $merge ? 'scope_patch' : 'scope';
        $jsonLabel = $merge ? 'Scope Patch' : 'Scope JSON';
        $error = '';
        $payload = $this->getRequestJsonObject($jsonKey, $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }

        $saved = $merge
            ? $this->getSessionService()->mergeScope($session->getId(), $adminId, $payload)
            : $this->getSessionService()->replaceScope($session->getId(), $adminId, $payload);

        if (!$saved) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('Failed to save %{label}', ['label' => $jsonLabel]),
            ]);
        }

        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId);
        if ($fresh === null) {
            return $this->fetchJson(['success' => false, 'message' => __('Failed to reload workspace session')]);
        }

        $this->syncSessionStructuredFields($fresh, $adminId);
        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $fresh;
        $this->syncSessionArtifacts($fresh, $adminId);

        $this->getEventStreamService()->appendEvent(
            $fresh->getId(),
            $adminId,
            $this->normalizeJourneyStage($fresh->getCurrentStage()),
            $merge ? 'scope_merged' : 'scope_replaced',
            [
                'keys' => \array_values(\array_map('strval', \array_keys($payload))),
                'mode' => $merge ? 'merge' : 'replace',
            ],
            AiSiteBuilderEvent::LEVEL_INFO
        );

        return $this->fetchJson([
            'success' => true,
            'scope' => $fresh->getScopeArray(),
            'state' => $this->buildWorkspaceState($fresh, $adminId, 40, 40),
        ]);
    }

    private function syncSessionStructuredFields(AiSiteBuilderSession $session, int $adminUserId): void
    {
        $scope = $session->getScopeArray();
        $domain = \strtolower(\trim((string)($scope['target_domain'] ?? $scope['selected_domain'] ?? $session->getSelectedDomain())));
        $registrarAccountId = (int)($scope['registrar_account_id'] ?? $scope['preferred_registrar_account_id'] ?? $session->getRegistrarAccountId());
        $websiteId = (int)($scope['website_id'] ?? $scope['selected_website_id'] ?? $session->getWebsiteId());
        $previewUrl = \trim((string)($scope['preview_full_url'] ?? $scope['preview_url'] ?? $session->getPreviewUrl()));

        if ($previewUrl === '' && $domain !== '') {
            $previewUrl = $this->buildSimulatedPreviewUrl($domain, $session->getProviderCode(), $scope);
        }

        if ($domain !== '' || $registrarAccountId > 0) {
            $this->getSessionService()->bindDomain($session->getId(), $adminUserId, $domain, $registrarAccountId);
        }
        if ($websiteId > 0) {
            $this->getSessionService()->bindWebsite($session->getId(), $adminUserId, $websiteId);
        }
        if ($previewUrl !== '') {
            $this->getSessionService()->setPreviewUrl($session->getId(), $adminUserId, $previewUrl);
        }
    }

    private function syncSessionArtifacts(AiSiteBuilderSession $session, int $adminUserId): void
    {
        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminUserId);
        $scope = \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [];
        $providerContext = $this->extractProviderContext($providerConfig);

        $this->getArtifactService()->upsertArtifact(
            $session->getId(),
            $adminUserId,
            'workspace',
            'scope_snapshot',
            [
                'public_id' => $session->getPublicId(),
                'current_stage' => $this->normalizeJourneyStage($session->getCurrentStage()),
                'stage_label' => $this->getStageLabel($session->getCurrentStage()),
                'provider_context' => $providerContext,
                'scope' => $scope,
            ],
            (string)__('Workspace scope snapshot')
        );

        $this->getArtifactService()->upsertArtifact(
            $session->getId(),
            $adminUserId,
            'handoff',
            $session->getProviderCode(),
            [
                'provider_context' => $providerContext,
                'workspace_url' => $this->getWorkspaceUrl($session->getPublicId()),
                'native_entry_url' => (string)($providerConfig['native_entry_url'] ?? ''),
                'handoff_label' => (string)($providerConfig['handoff_label'] ?? $this->resolveProviderHandoffLabel($session->getProviderCode())),
            ],
            (string)__('Provider handoff')
        );
    }

    /**
     * @param array<string, mixed> $providerConfig
     * @return array{code:string,name:string,description:string,badge:string}
     */
    private function extractProviderContext(array $providerConfig): array
    {
        return [
            'code' => (string)($providerConfig['code'] ?? 'websites_default'),
            'name' => (string)($providerConfig['name'] ?? $this->resolveProviderWorkspaceLabel((string)($providerConfig['code'] ?? 'websites_default'))),
            'description' => (string)($providerConfig['description'] ?? ''),
            'badge' => (string)($providerConfig['badge'] ?? $this->resolveProviderBadge((string)($providerConfig['code'] ?? 'websites_default'))),
        ];
    }

    /**
     * @param-out string $error
     * @return array<string, mixed>
     */
    private function getRequestJsonObject(string $key, string &$error = ''): array
    {
        $error = '';
        $raw = $this->getRequestBodyValue($key, '');

        if (\is_array($raw)) {
            return $raw;
        }

        $raw = \is_string($raw) ? \trim($raw) : '';
        if ($raw === '') {
            return [];
        }

        try {
            return $this->decodeJsonObject($raw, $key);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(string $json, string $label = 'json'): array
    {
        $json = \trim($json);
        if ($json === '') {
            return [];
        }

        try {
            $decoded = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException((string)__('Invalid %{label}: %{message}', [
                'label' => $label,
                'message' => $e->getMessage(),
            ]), 0, $e);
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException((string)__('Invalid %{label}: expected a JSON object', ['label' => $label]));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePrettyJson(array $payload): string
    {
        try {
            return (string)\json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{}';
        }
    }

    private function buildContentPreview(string $content): string
    {
        $content = \trim(\preg_replace('/\s+/u', ' ', \strip_tags($content)) ?? '');
        if ($content === '') {
            return '';
        }

        return \mb_strlen($content) > 80
            ? \mb_substr($content, 0, 80) . '...'
            : $content;
    }

    private function buildWorkspaceWelcomeMessage(string $providerCode): string
    {
        return (string)__('已创建 %{name}，你可以先确认域名和服务商，再继续页面生成。', [
            'name' => $this->resolveProviderWorkspaceLabel($providerCode),
        ]);
    }

    private function resolveProviderWorkspaceLabel(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('AI 建站工作台 · PageBuilder 扩展'),
            'websites_default' => (string)__('AI 建站工作台'),
            default => (string)__('AI 建站工作区'),
        };
    }

    private function resolveProviderHandoffLabel(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('继续到 PageBuilder 工作台'),
            'websites_default' => (string)__('返回 AI 建站工作台'),
            default => (string)__('打开 provider 原生入口'),
        };
    }

    private function getRequestBodyValue(string $key, mixed $default = null): mixed
    {
        $value = $this->request->getPost($key, null);
        if ($value !== null) {
            return $value;
        }

        $bodyParams = $this->request->getBodyParams(true);
        if (\is_array($bodyParams) && \array_key_exists($key, $bodyParams)) {
            return $bodyParams[$key];
        }

        return $default;
    }

    private function isFakeModeRequested(): bool
    {
        $queryValue = $this->request->getGet('fake_mode', null);
        if ($queryValue !== null) {
            return $this->isTruthyFlag($queryValue);
        }

        return $this->isTruthyFlag($this->getRequestBodyValue('fake_mode', false));
    }

    private function isTruthyFlag(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (\is_string($value)) {
            return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function getHubEntryUrl(string $providerCode = 'websites_default', ?bool $fakeMode = null): string
    {
        $params = ['provider' => $providerCode !== '' ? $providerCode : 'websites_default'];
        if ($fakeMode ?? false) {
            $params['fake_mode'] = 1;
        }

        return $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/index', $params);
    }

    private function buildFakeDomainSuggestion(string $brief): string
    {
        $slug = \strtolower((string)\preg_replace('/[^a-z0-9]+/i', '-', $brief));
        $slug = \trim($slug, '-');
        if ($slug === '') {
            $slug = 'smartsite';
        }

        $parts = \array_values(\array_filter(\explode('-', $slug), static fn (string $part): bool => $part !== ''));
        $parts = \array_slice($parts, 0, 2);
        $base = \implode('', $parts);
        if ($base === '') {
            $base = 'smartsite';
        }

        return $base . '.com';
    }

    private function buildFakePreviewUrl(string $domain): string
    {
        $domain = \trim($domain);
        if ($domain === '') {
            return 'https://preview.local.test/site';
        }

        return 'https://preview.local.test/site?domain=' . \rawurlencode($domain);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildSimulatedPreviewUrl(string $domain, string $providerCode, array $scope): string
    {
        $scopePreviewUrl = \trim((string)($scope['preview_full_url'] ?? $scope['preview_url'] ?? ''));
        if ($scopePreviewUrl !== '') {
            return $scopePreviewUrl;
        }

        $baseUrl = $this->buildFakePreviewUrl($domain);
        $separator = \str_contains($baseUrl, '?') ? '&' : '?';
        return $baseUrl . $separator . 'provider=' . \rawurlencode($providerCode !== '' ? $providerCode : 'websites_default');
    }

    private function getAdminId(): int
    {
        return (int)($this->getLoginUserId() ?? 0);
    }

    private function getWorkspaceUrl(string $publicId): string
    {
        return $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/workspace', ['public_id' => $publicId]);
    }

    private function getUrlHelper(): Url
    {
        /** @var Url $url */
        $url = ObjectManager::getInstance(Url::class);
        return $url;
    }

    private function getArtifactService(): ArtifactService
    {
        /** @var ArtifactService $service */
        $service = ObjectManager::getInstance(ArtifactService::class);
        return $service;
    }

    private function getEventStreamService(): EventStreamService
    {
        /** @var EventStreamService $service */
        $service = ObjectManager::getInstance(EventStreamService::class);
        return $service;
    }

    private function getMessageService(): MessageService
    {
        /** @var MessageService $service */
        $service = ObjectManager::getInstance(MessageService::class);
        return $service;
    }

    private function getProviderRegistry(): ProviderRegistry
    {
        /** @var ProviderRegistry $registry */
        $registry = ObjectManager::getInstance(ProviderRegistry::class);
        return $registry;
    }

    private function getProviderWorkbenchService(): ProviderWorkbenchService
    {
        /** @var ProviderWorkbenchService $service */
        $service = ObjectManager::getInstance(ProviderWorkbenchService::class);
        return $service;
    }

    private function getSessionService(): SessionService
    {
        /** @var SessionService $service */
        $service = ObjectManager::getInstance(SessionService::class);
        return $service;
    }

    private function getWebsiteAgentService(): WebsiteAgentService
    {
        /** @var WebsiteAgentService $service */
        $service = ObjectManager::getInstance(WebsiteAgentService::class);
        return $service;
    }
}
