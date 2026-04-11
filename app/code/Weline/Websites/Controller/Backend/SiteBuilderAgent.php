<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\LastEventIdResolver;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Websites\Model\AiSiteBuilderEvent;
use Weline\Websites\Model\AiSiteBuilderSession;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\AiWorkbench\ArtifactService;
use Weline\Websites\Service\AiWorkbench\DomainPurchaseWorkbenchService;
use Weline\Websites\Service\AiWorkbench\EventStreamService;
use Weline\Websites\Service\AiWorkbench\MessageService;
use Weline\Websites\Service\AiWorkbench\ProviderRegistry;
use Weline\Websites\Service\AiWorkbench\ProviderWorkbenchService;
use Weline\Websites\Service\AiWorkbench\SessionService;
use Weline\Websites\Service\AiWorkbench\VirtualThemeWorkbenchService;
use Weline\Websites\Service\WebsiteAgentService;

#[Acl('Weline_Websites::site_builder_agent', 'AI Site Workbench', 'mdi mdi-robot', 'Coordinate domain, website, and workspace site building', 'Weline_Backend::website_service')]
class SiteBuilderAgent extends BackendController
{
    private const PAGEBUILDER_HANDOFF_MODE_NATIVE_WORKSPACE = 'pagebuilder_native_workspace';
    private const DEV_SIM_DOMAIN = 'weline-dev.local';

    #[Acl('Weline_Websites::site_builder_agent_index', 'AI Site Workbench', 'mdi mdi-robot', 'AI site workbench hub')]
    public function index(): string
    {
        $selectedProvider = \trim((string)$this->request->getGet('provider', ''));
        if ($selectedProvider === '') {
            $selectedProvider = 'pagebuilder';
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
        $this->assign('plan_generate_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-plan/generate'));
        $this->assign('plan_revise_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-plan/revise'));
        $this->assign('plan_stream_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-plan/stream'));
        $this->assign('plan_confirm_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-plan/confirm'));
        $this->assign('plan_local_pool_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-plan/local-pool'));
        $this->assign('plan_reserve_local_pool_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-plan/reserve-local-pool'));
        $this->assign('plan_select_domain_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-plan/select-domain'));
        $this->assign('plan_create_session_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-plan/create-session'));
        $this->assign('delete_session_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/delete-session'));
        $this->assign('recommend_domain_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/recommend-domain'));
        $this->assign('recommend_domain_sse_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/recommend-domain-sse'));
        $this->assign('check_domain_url', $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/check-domain'));
        $this->assign('current_entry_url', $this->getHubEntryUrl($selectedProvider, $fakeMode));
        $this->assign('fake_mode', $fakeMode);
        $this->assign('page_title', __('AI 建站工作台'));
        $this->assign('breadcrumb_parent', __('网站服务'));
        $this->assign('breadcrumb_current', __('AI 建站工作台'));

        return $this->fetch('index-v1');
    }

    #[Acl('Weline_Websites::site_builder_agent_workspace', 'AI Site Workspace', 'mdi mdi-view-dashboard-outline', 'View and edit resumable AI site workspaces', 'Weline_Websites::site_builder_agent')]
    public function workspace(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));

        if ($adminId <= 0 || $publicId === '') {
            $this->assign('title', __('AI 建站工作区'));
            $this->assign('error_message', __('需要登录或会话令牌无效'));
            $this->assign('back_url', $this->getHubEntryUrl('websites_default', $this->isFakeModeRequested()));
            return $this->fetch();
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->assign('title', __('AI 建站工作区'));
            $this->assign('error_message', __('会话不存在或无访问权限'));
            $this->assign('back_url', $this->getHubEntryUrl('websites_default', $this->isFakeModeRequested()));
            return $this->fetch();
        }

        $session = $this->refreshPageBuilderMirrorSession($session, $adminId);
        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminId);
        $scope = \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [];
        $currentStage = $this->normalizeJourneyStage($session->getCurrentStage());

        // 检测 next_step 参数，用于触发可视化主题编辑器
        $nextStep = \trim((string)$this->request->getGet('next_step', ''));
        $this->assign('next_step', $nextStep);
        $this->assign('auto_show_page_type_selector', $nextStep === 'virtual_theme_edit');

        $this->assign('title', __('AI 建站工作区'));
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
        $this->assign('domain_purchase_state', $this->getDomainPurchaseWorkbenchService()->buildViewState($session));
        $this->assign('stage_options', $this->getStageOptions());
        $this->assign('state_json_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/state-json', ['public_id' => $session->getPublicId()]));
        $this->assign('back_url', $this->getHubEntryUrl($session->getProviderCode(), $this->isFakeModeRequested()));
        // 工作区 AJAX/SSE 一律 path-only，避免 E2E 代理下绝对 URL 指向上游导致 Cookie 丢失（adminId=0 → 参数无效）
        $this->assign('merge_scope_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/merge-scope'));
        $this->assign('replace_scope_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/replace-scope'));
        $this->assign('set_stage_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/set-stage'));
        $this->assign('append_message_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/append-message'));
        $this->assign('delete_session_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/delete-session'));
        $this->assign('start_domain_purchase_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/start-domain-purchase'));
        $this->assign('domain_purchase_sse_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/domain-purchase-sse'));
        $this->assign('generate_virtual_theme_sse_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/generate-virtual-theme-sse'));
        $this->assign('save_virtual_theme_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/save-virtual-theme'));
        $this->assign('save_page_type_layout_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/save-page-type-layout'));
        $this->assign('save_virtual_component_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/save-virtual-component'));
        $this->assign('stream_sse_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/stream-sse'));
        $this->assign('preview_full_url', (string)($scope['preview_full_url'] ?? $session->getPreviewUrl()));
        $this->assign('visual_preview_url', (string)($scope['visual_preview_url'] ?? ''));
        $this->assign('visual_edit_url', (string)($scope['visual_edit_url'] ?? ''));
        $this->assign('pagebuilder_handoff_url', $this->getUrlHelper()->getBackendUrlPath('*/backend/site-builder-agent/pagebuilder-handoff', [
            'public_id' => $session->getPublicId(),
        ]));
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
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无访问权限')]);
        }

        $session = $this->refreshPageBuilderMirrorSession($session, $adminId);

        return $this->fetchJson([
            'success' => true,
            'data' => $this->buildWorkspaceState($session, $adminId),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_domain_lifecycle_status', 'AI Site Domain Lifecycle Status', 'mdi mdi-sync', 'Read domain lifecycle status for workspace top-bar', 'Weline_Websites::site_builder_agent')]
    public function getDomainLifecycleStatus(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无访问权限')]);
        }

        $status = $this->getDomainLifecycleBridgeService()->buildLifecycleStatus($session);

        return $this->fetchJson([
            'success' => true,
            'data' => $status,
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_stage_info', 'AI Site Stage Info', 'mdi mdi-information-outline', 'Read current stage and available stages', 'Weline_Websites::site_builder_agent')]
    public function getStageInfo(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无访问权限')]);
        }

        $providerConfig = $this->getProviderWorkbenchService()->buildWorkbenchConfigForSession($session, $adminId);
        $scope = \is_array($providerConfig['scope'] ?? null) ? $providerConfig['scope'] : [];
        $currentStage = $this->normalizeJourneyStage($session->getCurrentStage());

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'current_stage' => $currentStage,
                'stage_label' => $this->getStageLabel($currentStage),
                'stage_options' => $this->getStageOptions(),
                'stage_guides' => $this->buildStageGuides($providerConfig, $scope, $currentStage),
            ],
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_create_session', 'Create AI Site Workspace', 'mdi mdi-plus', 'Create a resumable AI site workspace', 'Weline_Websites::site_builder_agent')]
    public function postCreateSession(): string
    {
        $adminId = $this->getAdminId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('需要登录')]);
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
        $scope = $this->prefillWorkspaceBriefByAi($scope, $description, $domain, $providerCode, $accountId, $useAi);

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

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function prefillWorkspaceBriefByAi(
        array $scope,
        string $description,
        string $domain,
        string $providerCode,
        int $accountId,
        bool $useAi
    ): array {
        if (!$useAi) {
            return $scope;
        }

        $description = \trim($description);
        if ($description === '') {
            return $scope;
        }

        $currentBrief = \trim((string)($scope['brief_description'] ?? ''));
        $currentUserDescription = \trim((string)($scope['user_description'] ?? ''));
        $needsTitle = \trim((string)($scope['site_title'] ?? '')) === '';
        $needsTagline = \trim((string)($scope['site_tagline'] ?? '')) === '';
        $needsBrief = $currentBrief === '' || ($currentUserDescription !== '' && $currentBrief === $currentUserDescription);
        $needsDomainSuggestion = \trim((string)($scope['target_domain'] ?? '')) === '' && \trim($domain) === '';

        if (!$needsTitle && !$needsTagline && !$needsBrief && !$needsDomainSuggestion) {
            return $scope;
        }

        $aiPatch = $this->generateAiWorkspaceBriefPatch($description, $domain, $providerCode, $accountId);
        if ($aiPatch === []) {
            return $scope;
        }

        if ($needsTitle && isset($aiPatch['site_title'])) {
            $scope['site_title'] = $aiPatch['site_title'];
        }
        if ($needsTagline && isset($aiPatch['site_tagline'])) {
            $scope['site_tagline'] = $aiPatch['site_tagline'];
        }
        if ($needsBrief && isset($aiPatch['brief_description'])) {
            $scope['brief_description'] = $aiPatch['brief_description'];
        }
        if ($needsDomainSuggestion && isset($aiPatch['target_domain'])) {
            $scope['target_domain'] = $aiPatch['target_domain'];
        }

        return $scope;
    }

    /**
     * @return array{site_title?:string,site_tagline?:string,brief_description?:string,target_domain?:string}
     */
    private function generateAiWorkspaceBriefPatch(
        string $description,
        string $domain,
        string $providerCode,
        int $accountId
    ): array {
        if (!\class_exists(\Weline\Ai\Service\AiService::class)) {
            return [];
        }

        $prompt = \implode("\n", [
            'You are a website planning assistant.',
            'Task: convert a short user sentence into a ready-to-use workspace brief.',
            'Return STRICT JSON only (no markdown, no explanation).',
            'JSON schema:',
            '{',
            '  "site_title": "string, concise brand/site title",',
            '  "site_tagline": "string, one-sentence positioning",',
            '  "brief_description": "string, 4-8 bullet-like sentences merged as plain text",',
            '  "target_domain": "string, optional, lowercase domain like example.com if obvious"',
            '}',
            'Rules:',
            '- Keep content practical for immediate website generation.',
            '- Keep original intent and market from user input.',
            '- If domain is unknown, set target_domain to empty string.',
            'Provider code: ' . $providerCode,
            'Preferred account id: ' . ($accountId > 0 ? (string)$accountId : '0'),
            'Preferred domain: ' . (\trim($domain) !== '' ? \strtolower(\trim($domain)) : ''),
            'User input: ' . $description,
        ]);

        try {
            /** @var \Weline\Ai\Service\AiService $aiService */
            $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            $result = $aiService->executeAgent('website_builder', $prompt, null, ['account_id' => $accountId], null);
            if (!$result->success || !\is_string($result->content) || \trim($result->content) === '') {
                return [];
            }

            $decoded = $this->extractFirstJsonObject((string)$result->content);
            if ($decoded === []) {
                return [];
            }

            $patch = [];
            $siteTitle = \trim((string)($decoded['site_title'] ?? ''));
            if ($siteTitle !== '') {
                $patch['site_title'] = $siteTitle;
            }
            $siteTagline = \trim((string)($decoded['site_tagline'] ?? ''));
            if ($siteTagline !== '') {
                $patch['site_tagline'] = $siteTagline;
            }
            $brief = \trim((string)($decoded['brief_description'] ?? ''));
            if ($brief !== '') {
                $patch['brief_description'] = $brief;
            }
            $targetDomain = \strtolower(\trim((string)($decoded['target_domain'] ?? '')));
            if ($targetDomain !== '' && \preg_match('/^[a-z0-9][a-z0-9.-]+\.[a-z]{2,}$/', $targetDomain) === 1) {
                $patch['target_domain'] = $targetDomain;
            }

            return $patch;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFirstJsonObject(string $content): array
    {
        $content = \trim($content);
        if ($content === '') {
            return [];
        }

        try {
            $decoded = \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
        }

        $start = \strpos($content, '{');
        $end = \strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $json = \substr($content, $start, $end - $start + 1);
        if (!\is_string($json) || \trim($json) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    #[Acl('Weline_Websites::site_builder_agent_recommend_domain', 'Recommend Available Domain', 'mdi mdi-auto-fix', 'Recommend an available domain for the quick-start flow', 'Weline_Websites::site_builder_agent')]
    public function postRecommendDomain(): string
    {
        $adminId = $this->getAdminId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('需要登录')]);
        }

        $description = \trim((string)$this->getRequestBodyValue('description', ''));
        $preferredDomain = \strtolower(\trim((string)$this->getRequestBodyValue('domain', '')));
        $accountId = (int)$this->getRequestBodyValue('account_id', 0);
        $deferAvailability = \in_array(\strtolower(\trim((string)$this->getRequestBodyValue('defer_availability_check', ''))), ['1', 'true', 'yes', 'on'], true);

        if ($description === '' && $preferredDomain === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先描述建站目标，或先输入偏好域名。'),
            ]);
        }

        if ($this->isFakeModeRequested()) {
            $seed = $description !== '' ? $description : $preferredDomain;
            $suggestions = $this->buildFakeWelineLocalDomains($seed);

            return $this->fetchJson([
                'success' => true,
                'message' => __('本地演示模式下，AI 找到可用域名：%{domain}', ['domain' => $suggestions[0]]),
                'domain' => $suggestions[0],
                'candidate_domains' => $suggestions,
                'checked_results' => [
                    [
                        'domain' => $suggestions[0],
                        'available' => true,
                    ],
                ],
                'fake_mode' => true,
            ]);
        }

        if ($deferAvailability) {
            return $this->fetchJson(
                $this->getWebsiteAgentService()->recommendAvailableDomain($description, $accountId, $preferredDomain, true)
            );
        }

        if ($accountId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('检测实时可用性前，请先选择服务商账号。'),
            ]);
        }

        return $this->fetchJson(
            $this->getWebsiteAgentService()->recommendAvailableDomain($description, $accountId, $preferredDomain)
        );
    }

    #[Acl('Weline_Websites::site_builder_agent_check_domain', 'Check Domain Availability', 'mdi mdi-shield-search', 'Check a specific domain availability for quick-start flow', 'Weline_Websites::site_builder_agent')]
    public function postCheckDomain(): string
    {
        $adminId = $this->getAdminId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'available' => false, 'message' => __('需要登录')]);
        }

        $domain = \strtolower(\trim((string)$this->getRequestBodyValue('domain', '')));
        $accountId = (int)$this->getRequestBodyValue('account_id', 0);
        if ($domain === '') {
            return $this->fetchJson([
                'success' => false,
                'available' => false,
                'message' => __('请先输入目标域名。'),
            ]);
        }
        if ($this->isLocalWelineSubdomain($domain)) {
            return $this->fetchJson([
                'success' => true,
                'available' => true,
                'domain' => $domain,
                'message' => __('本地测试域名 %{domain} 已强制通过可用性检查。', ['domain' => $domain]),
                'checked_results' => [
                    [
                        'domain' => $domain,
                        'available' => true,
                        'simulated' => true,
                    ],
                ],
                'simulated' => true,
            ]);
        }
        if ($accountId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'available' => false,
                'message' => __('检测实时可用性前，请先选择服务商账号。'),
            ]);
        }
        if ($this->isDevSimulationDomain($domain)) {
            return $this->fetchJson([
                'success' => true,
                'available' => true,
                'domain' => $domain,
                'message' => __('本地测试域名 %{domain} 已强制通过可用性检查。', ['domain' => $domain]),
                'checked_results' => [
                    [
                        'domain' => $domain,
                        'available' => true,
                        'simulated' => true,
                    ],
                ],
                'simulated' => true,
            ]);
        }

        if ($this->isFakeModeRequested()) {
            return $this->fetchJson([
                'success' => true,
                'available' => true,
                'domain' => $domain,
                'message' => __('本地演示模式下，域名 %{domain} 视为可用。', ['domain' => $domain]),
                'checked_results' => [
                    [
                        'domain' => $domain,
                        'available' => true,
                    ],
                ],
                'fake_mode' => true,
            ]);
        }

        $resultsByDomain = $this->getWebsiteAgentService()->checkCandidateAvailability($accountId, [$domain]);
        $result = null;
        foreach ($resultsByDomain as $itemDomain => $itemResult) {
            if (\strtolower((string)$itemDomain) === $domain || \strtolower((string)($itemResult['domain'] ?? '')) === $domain) {
                $result = \is_array($itemResult) ? $itemResult : null;
                break;
            }
        }

        if ($result === null) {
            return $this->fetchJson([
                'success' => false,
                'available' => false,
                'domain' => $domain,
                'message' => __('暂未获取到域名 %{domain} 的可用性结果，请重试。', ['domain' => $domain]),
                'checked_results' => [],
            ]);
        }

        $available = !empty($result['available']);
        $checkedResult = [
            'domain' => (string)($result['domain'] ?? $domain),
            'available' => $available,
        ];
        if (!empty($result['error']) && \is_string($result['error'])) {
            $checkedResult['error'] = $result['error'];
        }

        if ($available) {
            return $this->fetchJson([
                'success' => true,
                'available' => true,
                'domain' => $checkedResult['domain'],
                'message' => __('域名 %{domain} 当前可用。', ['domain' => $checkedResult['domain']]),
                'checked_results' => [$checkedResult],
            ]);
        }

        return $this->fetchJson([
            'success' => false,
            'available' => false,
            'domain' => $checkedResult['domain'],
            'message' => (string)($checkedResult['error'] ?? __('域名 %{domain} 当前不可用。', ['domain' => $checkedResult['domain']])),
            'checked_results' => [$checkedResult],
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_recommend_domain_stream', 'Recommend Available Domain SSE', 'mdi mdi-access-point', 'Stream domain recommendation checks', 'Weline_Websites::site_builder_agent')]
    public function getRecommendDomainSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->start();

        $adminId = $this->getAdminId();
        if ($adminId <= 0) {
            $sse->sendError((string)__('需要登录'));
            $sse->complete(['success' => false]);
            return;
        }

        $description = \trim((string)$this->request->getGet('description', ''));
        $preferredDomain = \strtolower(\trim((string)$this->request->getGet('domain', '')));
        $accountId = (int)$this->request->getGet('account_id', 0);

        if ($description === '' && $preferredDomain === '') {
            $sse->sendError((string)__('请先描述建站目标，或先输入偏好域名。'));
            $sse->complete(['success' => false]);
            return;
        }

        if ($this->isFakeModeRequested()) {
            $seed = $description !== '' ? $description : $preferredDomain;
            $candidates = $this->buildFakeWelineLocalDomains($seed);
            $domain = $candidates[0];
            $sse->sendEvent('success', [
                'message' => (string)__('本地演示模式下，AI 找到可用域名：%{domain}', ['domain' => $domain]),
                'domain' => $domain,
                'candidate_domains' => $candidates,
                'checked_results' => [['domain' => $domain, 'available' => true]],
                'fake_mode' => true,
            ]);
            $sse->complete(['success' => true, 'domain' => $domain]);
            return;
        }

        if ($accountId <= 0) {
            $sse->sendError((string)__('检测实时可用性前，请先选择服务商账号。'));
            $sse->complete(['success' => false]);
            return;
        }

        if (!\class_exists(\Weline\Ai\Service\AiService::class)) {
            $sse->sendError((string)__('AI 服务未启用，无法执行智能域名推荐。'));
            $sse->complete(['success' => false]);
            return;
        }

        $agentService = $this->getWebsiteAgentService();
        $sse->sendEvent('start', [
            'message' => (string)__('AI 正在分轮生成更长域名并实时检测可用性...'),
        ]);

        $startTs = \microtime(true);
        $timeoutSeconds = 35;
        $batchSize = 6;
        $checkedResults = [];
        $checkedDomains = [];
        $recommended = null;
        $allCandidates = [];
        $roundMinLengths = [8, 12, 16, 20, 24];
        foreach ($roundMinLengths as $roundIndex => $minLabelLength) {
            if (!$sse->isAlive()) {
                break;
            }
            if ((\microtime(true) - $startTs) >= $timeoutSeconds) {
                break;
            }

            $roundNo = $roundIndex + 1;
            $sse->sendEvent('progress', [
                'message' => (string)__('第 %{round} 轮：AI 正在生成更长域名候选（最小长度 %{len}）', [
                    'round' => (string)$roundNo,
                    'len' => (string)$minLabelLength,
                ]),
            ]);

            $roundCandidates = $this->generateAiDomainCandidates(
                $description,
                $preferredDomain,
                $roundNo,
                $minLabelLength,
                20
            );
            if ($this->isDevDomainSimulationEnabled() && $roundNo === 2) {
                $roundCandidates[] = self::DEV_SIM_DOMAIN;
                $sse->sendEvent('progress', [
                    'message' => (string)__('开发环境模拟：已注入保底域名 %{domain}', ['domain' => self::DEV_SIM_DOMAIN]),
                ]);
            }
            if ($roundCandidates === []) {
                $sse->sendEvent('progress', [
                    'message' => (string)__('第 %{round} 轮 AI 未产出有效候选，继续下一轮。', ['round' => (string)$roundNo]),
                ]);
                continue;
            }

            $newCandidates = [];
            foreach ($roundCandidates as $candidate) {
                if (!isset($checkedDomains[$candidate])) {
                    $newCandidates[] = $candidate;
                    $allCandidates[] = $candidate;
                }
            }
            if ($newCandidates === []) {
                continue;
            }

            for ($offset = 0; $offset < \count($newCandidates) && $sse->isAlive(); $offset += $batchSize) {
                if ((\microtime(true) - $startTs) >= $timeoutSeconds) {
                    break 2;
                }
                $batch = \array_slice($newCandidates, $offset, $batchSize);
                if ($batch === []) {
                    continue;
                }

                $resultsByDomain = $agentService->checkCandidateAvailability($accountId, $batch);
                $batchResults = [];
                foreach ($batch as $candidate) {
                    $checkedDomains[$candidate] = true;
                    $result = $resultsByDomain[$candidate] ?? ['domain' => $candidate, 'available' => false];
                    if ($this->isDevDomainSimulationEnabled() && $this->isDevSimulationDomain($candidate)) {
                        $result = [
                            'domain' => $candidate,
                            'available' => true,
                            'simulated' => true,
                        ];
                    }
                    $checkedResults[] = $result;
                    $batchResults[] = $result;
                    if ($recommended === null && !empty($result['available'])) {
                        $recommended = $result;
                    }
                }

                $sse->sendEvent('batch', [
                    'message' => (string)__('已检测 %{checked} 个候选域名', [
                        'checked' => (string)\count($checkedResults),
                    ]),
                    'batch_results' => $batchResults,
                    'checked_results' => $checkedResults,
                    'candidate_domains' => $allCandidates,
                ]);

                if ($recommended !== null) {
                    $sse->sendEvent('success', [
                        'message' => (string)__('AI 找到可用域名：%{domain}', ['domain' => (string)$recommended['domain']]),
                        'domain' => (string)$recommended['domain'],
                        'checked_results' => $checkedResults,
                        'candidate_domains' => $allCandidates,
                    ]);
                    $sse->complete(['success' => true, 'domain' => (string)$recommended['domain']]);
                    return;
                }

                $sse->maybeHeartbeat();
                SchedulerSystem::yieldDelay(200);
            }
        }

        $sse->sendEvent('done', [
            'success' => false,
            'message' => (string)__('在超时时间内未找到可用域名，请调整简报后重试。'),
            'checked_results' => $checkedResults,
            'candidate_domains' => $allCandidates,
        ]);
        $sse->complete([
            'success' => false,
            'message' => (string)__('在超时时间内未找到可用域名，请调整简报后重试。'),
            'checked_results' => $checkedResults,
            'candidate_domains' => $allCandidates,
        ]);
    }

    /**
     * @return list<string>
     */
    private function generateAiDomainCandidates(
        string $description,
        string $preferredDomain,
        int $round,
        int $minLabelLength,
        int $targetCount = 20
    ): array {
        if (!\class_exists(\Weline\Ai\Service\AiService::class)) {
            return [];
        }

        $prompt = \implode("\n", [
            'You are an expert naming assistant for domain availability checks.',
            'Task: propose domain candidates for one retry round.',
            'Requirements:',
            '- Derive market intent from the brief first; if a country/region is explicit (e.g. India), include local trust TLDs (e.g. .in, .co.in) together with global options.',
            '- Keep .com in the list, but do not output mostly .com variants of the same root.',
            '- Avoid short/generic one-word names and overused generic roots like apk, app, seo, web, ai as standalone labels.',
            '- Domain label (before TLD) must be at least ' . $minLabelLength . ' chars.',
            '- Use 2-4 combined semantic words and strongly align with the user brief (industry, market, language, intent).',
            '- Prioritize brandable combinations over dictionary-single-word domains.',
            '- Ensure diversity: avoid repeating the same label with many different TLDs.',
            '- Output only domain names, one per line, no numbering, no explanation.',
            '- Provide up to ' . $targetCount . ' domains.',
            'Round: ' . $round,
            'Brief: ' . ($description !== '' ? $description : 'N/A'),
            'Preferred domain: ' . ($preferredDomain !== '' ? $preferredDomain : 'N/A'),
        ]);

        try {
            /** @var \Weline\Ai\Service\AiService $aiService */
            $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            $result = $aiService->executeAgent('website_builder', $prompt, null, [], null);
            if (!$result->success || $result->content === '') {
                return [];
            }
            return $this->extractDomainCandidatesFromText($result->content, $minLabelLength, $targetCount);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function extractDomainCandidatesFromText(string $text, int $minLabelLength, int $limit = 20): array
    {
        $found = [];
        if (\preg_match_all('/\b([a-z0-9][a-z0-9-]{1,62}\.(?:com|io|ai|co|net|site|cn|in|org|app|online|xyz|co\.in))\b/i', $text, $matches) !== 1) {
            return [];
        }

        foreach (($matches[1] ?? []) as $rawDomain) {
            if (!\is_string($rawDomain)) {
                continue;
            }
            $domain = \strtolower(\trim($rawDomain));
            if ($domain === '' || \in_array($domain, $found, true)) {
                continue;
            }
            $label = (string)\preg_replace('/\.(?:co\.in|[a-z]{2,})$/i', '', $domain);
            $label = \str_replace('-', '', $label);
            if (\strlen($label) < $minLabelLength) {
                continue;
            }
            // 避免极度泛化短词（如 apk.com）。
            if (\preg_match('/^[a-z]{2,5}\.(?:com|io|ai|co|net|site|cn|in|org|app|online|xyz|co\.in)$/', $domain) === 1) {
                continue;
            }
            $found[] = $domain;
            if (\count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    private function isDevDomainSimulationEnabled(): bool
    {
        return true;
    }

    private function isDevSimulationDomain(string $domain): bool
    {
        return \strtolower(\trim($domain)) === self::DEV_SIM_DOMAIN;
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
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无访问权限')]);
        }

        if (!$this->getSessionService()->setStage($session->getId(), $adminId, $stage)) {
            return $this->fetchJson(['success' => false, 'message' => __('更新阶段失败')]);
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

    #[Acl('Weline_Websites::site_builder_agent_delete_session', 'Delete Workspace Session', 'mdi mdi-delete-outline', 'Delete a resumable AI site workspace', 'Weline_Websites::site_builder_agent')]
    public function postDeleteSession(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无访问权限')]);
        }

        if (!$this->getSessionService()->deleteSessionById($session->getId(), $adminId)) {
            return $this->fetchJson(['success' => false, 'message' => __('删除会话失败')]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('最近会话已删除'),
            'public_id' => $publicId,
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_save_virtual_theme', 'Save Virtual Theme', 'mdi mdi-palette', 'Save current virtual theme into database', 'Weline_Websites::site_builder_agent')]
    public function postSaveVirtualTheme(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $payload = [
            'weline_theme_id' => (int)$this->getRequestBodyValue('weline_theme_id', 0),
            'virtual_theme_name' => \trim((string)$this->getRequestBodyValue('virtual_theme_name', '')),
            'theme_style_direction' => \trim((string)$this->getRequestBodyValue('theme_style_direction', '')),
            'theme_color_scheme' => \trim((string)$this->getRequestBodyValue('theme_color_scheme', '')),
        ];
        $pageTypesError = '';
        $layoutError = '';
        $pageTypes = $this->getRequestJsonObject('page_types', $pageTypesError);
        $pageLayouts = $this->getRequestJsonObject('page_type_layouts', $layoutError);
        if ($pageTypesError !== '' || $layoutError !== '') {
            return $this->fetchJson(['success' => false, 'message' => $pageTypesError !== '' ? $pageTypesError : $layoutError]);
        }
        $payload['page_types'] = $pageTypes;
        $payload['page_type_layouts'] = $pageLayouts;

        $result = $this->getVirtualThemeWorkbenchService()->saveVirtualThemeByPublicId($publicId, $adminId, $payload);
        if (empty($result['success'])) {
            return $this->fetchJson($result);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session !== null) {
            $this->syncSessionStructuredFields($session, $adminId);
            $session = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $session;
            $this->syncSessionArtifacts($session, $adminId);
        }

        return $this->fetchJson($result);
    }

    #[Acl('Weline_Websites::site_builder_agent_save_page_type_layout', 'Save Page Type Layout', 'mdi mdi-file-document-edit-outline', 'Save one page-type layout JSON into virtual theme', 'Weline_Websites::site_builder_agent')]
    public function postSavePageTypeLayout(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        if ($adminId <= 0 || $publicId === '' || $pageType === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $layoutError = '';
        $layoutPayload = $this->getRequestJsonObject('layout', $layoutError);
        if ($layoutError !== '') {
            return $this->fetchJson(['success' => false, 'message' => $layoutError]);
        }

        $result = $this->getVirtualThemeWorkbenchService()->savePageTypeLayoutByPublicId($publicId, $adminId, $pageType, $layoutPayload);
        if (empty($result['success'])) {
            return $this->fetchJson($result);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session !== null) {
            $this->syncSessionStructuredFields($session, $adminId);
            $session = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $session;
            $this->syncSessionArtifacts($session, $adminId);
        }

        return $this->fetchJson($result);
    }

    #[Acl('Weline_Websites::site_builder_agent_save_virtual_component', 'Save Virtual Component', 'mdi mdi-view-quilt-plus', 'Save AI component into virtual theme', 'Weline_Websites::site_builder_agent')]
    public function postSaveVirtualComponent(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $metaError = '';
        $meta = $this->getRequestJsonObject('meta', $metaError);
        if ($metaError !== '') {
            return $this->fetchJson(['success' => false, 'message' => $metaError]);
        }

        $result = $this->getVirtualThemeWorkbenchService()->saveVirtualComponentByPublicId($publicId, $adminId, [
            'weline_theme_id' => (int)$this->getRequestBodyValue('weline_theme_id', 0),
            'component_code' => \trim((string)$this->getRequestBodyValue('component_code', '')),
            'name' => \trim((string)$this->getRequestBodyValue('name', '')),
            'category' => \trim((string)$this->getRequestBodyValue('category', 'content')),
            'description' => \trim((string)$this->getRequestBodyValue('description', '')),
            'template_content' => (string)$this->getRequestBodyValue('template_content', ''),
            'meta' => $meta,
        ]);

        return $this->fetchJson($result);
    }

    #[Acl('Weline_Websites::site_builder_agent_generate_virtual_theme_stream', 'Generate Virtual Theme SSE', 'mdi mdi-robot', 'Auto-generate virtual theme from brief', 'Weline_Websites::site_builder_agent')]
    public function getGenerateVirtualThemeSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->start();

        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $streamMode = \trim((string)$this->request->getGet('stream_mode', 'batch')); // 'batch' 或 'progressive'

        if ($adminId <= 0 || $publicId === '') {
            $sse->sendError((string)__('参数无效'));
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $sse->sendError((string)__('会话不存在或无访问权限'));
            $sse->complete(['success' => false]);
            return;
        }

        $scope = $session->getScopeArray();
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $selectedPageTypes = $scope['page_types'] ?? [];
        if (!\is_array($selectedPageTypes) || $selectedPageTypes === []) {
            $selectedPageTypes = [
                'home_page', 'about_page', 'contact_page',
                'privacy_policy', 'terms_of_service', 'refund_policy',
                'cookie_policy', 'blog_list', 'blog_category', 'blog_post', 'custom_page',
            ];
        }

        // 渐进式流式生成模式
        if ($streamMode === 'progressive') {
            $this->handleProgressiveGeneration($sse, $session, $adminId, $publicId, $selectedPageTypes, $scope);
            return;
        }

        // 原有批量生成模式
        $sse->sendEvent('start', ['message' => (string)__('正在根据简报自动生成虚拟主题...')]);
        $sse->sendEvent('progress', ['message' => (string)__('解析简报与页面类型')]);

        $draft = $this->buildVirtualThemeDraftFallback($scope, $selectedPageTypes);
        if (\class_exists(\Weline\Ai\Service\AiService::class)) {
            try {
                /** @var \Weline\Ai\Service\AiService $ai */
                $ai = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
                $prompt = \implode("\n", [
                    'You are a website theme planner.',
                    'Return only one JSON object.',
                    'Fields: virtual_theme_name(string), theme_style_direction(string), theme_color_scheme(string), page_type_layouts(object), component_template(string).',
                    'page_type_layouts key is page_type, value has: page_type, regions(header/content/footer), components(array with code,title,description).',
                    'Header/Footer must be fixed and non-editable by business rule.',
                    'Brief: ' . $brief,
                    'Page types: ' . \implode(',', \array_map('strval', $selectedPageTypes)),
                ]);
                $aiRaw = (string)$ai->generate($prompt, null, 'pagebuilder_component_generation', null, [], $adminId, true);
                $decoded = $this->extractFirstJsonObject($aiRaw);
                if ($decoded !== []) {
                    $draft = \array_replace($draft, \array_intersect_key($decoded, $draft));
                    if (isset($decoded['page_type_layouts']) && \is_array($decoded['page_type_layouts'])) {
                        $draft['page_type_layouts'] = $decoded['page_type_layouts'];
                    }
                }
                $sse->sendEvent('progress', ['message' => (string)__('AI 已返回主题草案')]);
            } catch (\Throwable $throwable) {
                $sse->sendEvent('warning', ['message' => (string)__('AI 生成失败，使用默认草案：%{message}', ['message' => $throwable->getMessage()])]);
            }
        } else {
            $sse->sendEvent('warning', ['message' => (string)__('未启用 AI 模块，使用默认草案')]);
        }

        $draftLayouts = \is_array($draft['page_type_layouts'] ?? null) ? $draft['page_type_layouts'] : [];
        $draftLayouts = $this->normalizeThemePageLayouts($draftLayouts, $selectedPageTypes);

        $saveThemeResult = $this->getVirtualThemeWorkbenchService()->saveVirtualThemeByPublicId($publicId, $adminId, [
            'weline_theme_id' => (int)($scope['weline_theme_id'] ?? 0),
            'virtual_theme_name' => (string)($draft['virtual_theme_name'] ?? ''),
            'theme_style_direction' => (string)($draft['theme_style_direction'] ?? ''),
            'theme_color_scheme' => (string)($draft['theme_color_scheme'] ?? ''),
            'page_types' => $selectedPageTypes,
            'page_type_layouts' => $draftLayouts,
        ]);
        if (empty($saveThemeResult['success'])) {
            $sse->sendError((string)($saveThemeResult['message'] ?? __('保存虚拟主题失败')));
            $sse->complete(['success' => false]);
            return;
        }

        foreach ($draftLayouts as $pageType => $layoutPayload) {
            $this->getVirtualThemeWorkbenchService()->savePageTypeLayoutByPublicId($publicId, $adminId, (string)$pageType, \is_array($layoutPayload) ? $layoutPayload : []);
        }
        $sse->sendEvent('progress', ['message' => (string)__('页面布局已入库')]);

        $componentTemplate = \trim((string)($draft['component_template'] ?? ''));
        if ($componentTemplate !== '') {
            $this->getVirtualThemeWorkbenchService()->saveVirtualComponentByPublicId($publicId, $adminId, [
                'weline_theme_id' => (int)($saveThemeResult['data']['weline_theme_id'] ?? 0),
                'component_code' => 'content/ai-generated-section',
                'name' => 'AI Generated Section',
                'category' => 'content',
                'template_content' => $componentTemplate,
                'meta' => [
                    'position' => ['content'],
                    'page_layouts' => ['*'],
                    'editor_lock' => ['header', 'footer'],
                ],
            ]);
            $sse->sendEvent('progress', ['message' => (string)__('AI 组件已保存到虚拟主题')]);
        }

        $this->getSessionService()->mergeScope($session->getId(), $adminId, [
            'virtual_theme_auto_generated' => 1,
            'virtual_theme_generated_at' => $this->now(),
        ]);
        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $session;
        $fresh = $this->refreshPageBuilderMirrorSession($fresh, $adminId);
        $this->syncSessionStructuredFields($fresh, $adminId);
        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $fresh;
        $this->syncSessionArtifacts($fresh, $adminId);

        $sse->complete([
            'success' => true,
            'message' => (string)__('虚拟主题已自动生成并入库'),
            'theme' => $saveThemeResult['data'] ?? [],
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
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无访问权限')]);
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

    #[Acl('Weline_Websites::site_builder_agent_domain_purchase_start', 'Start Domain Purchase', 'mdi mdi-cart-arrow-down', 'Queue a non-blocking workbench domain purchase', 'Weline_Websites::site_builder_agent')]
    public function postStartDomainPurchase(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无访问权限')]);
        }

        $scopeError = '';
        $scopePatch = $this->getRequestJsonObject('scope_patch', $scopeError);
        if ($scopeError !== '') {
            return $this->fetchJson(['success' => false, 'message' => $scopeError]);
        }

        $result = $this->getDomainPurchaseWorkbenchService()->queuePurchase($session->getId(), $adminId, $scopePatch);
        if (empty($result['success'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => (string)($result['message'] ?? __('加入域名购买队列失败')),
            ]);
        }

        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $session;
        $this->syncSessionArtifacts($fresh, $adminId);

        $streamToken = (string)($result['stream_token'] ?? '');
        $streamUrl = $this->getUrlHelper()->getBackendUrl('*/backend/site-builder-agent/domain-purchase-sse', [
            'public_id' => $fresh->getPublicId(),
            'execution_token' => $streamToken,
        ]);

        return $this->fetchJson([
            'success' => true,
            'message' => (string)($result['message'] ?? __('已加入域名购买队列')),
            'state' => $result['state'] ?? $this->getDomainPurchaseWorkbenchService()->buildViewState($fresh),
            'startable' => !empty($result['startable']),
            'stream_token' => $streamToken,
            'stream_url' => $streamUrl,
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_pagebuilder_handoff', 'Open PageBuilder Handoff', 'mdi mdi-arrow-right-bold-circle-outline', 'Create or resume the PageBuilder extension workspace for this AI site workbench session', 'Weline_Websites::site_builder_agent')]
    public function getPagebuilderHandoff(): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));

        if ($adminId <= 0 || $publicId === '') {
            $this->redirect($this->getHubEntryUrl('pagebuilder', $this->isFakeModeRequested()));
            return '';
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->redirect($this->getHubEntryUrl('pagebuilder', $this->isFakeModeRequested()));
            return '';
        }

        if ($session->getProviderCode() !== 'pagebuilder') {
            $this->redirect($this->getWorkspaceUrl($session->getPublicId()));
            return '';
        }

        $handoff = $this->createOrResumePageBuilderHandoff($session, $adminId);
        if ($handoff === null) {
            $this->redirect($this->resolveProviderNativeEntryUrl($session->getProviderCode()));
            return '';
        }

        if ($this->normalizeJourneyStage($session->getCurrentStage()) === 'prepare') {
            $this->getSessionService()->setStage($session->getId(), $adminId, 'generate');
        }

        $this->getSessionService()->mergeScope(
            $session->getId(),
            $adminId,
            [
                'provider_handoff_mode' => self::PAGEBUILDER_HANDOFF_MODE_NATIVE_WORKSPACE,
                'provider_handoff_ready' => 1,
                'pagebuilder_workspace_public_id' => $handoff['public_id'],
                'pagebuilder_workspace_url' => $handoff['workspace_url'],
                'pagebuilder_handoff_stage' => $handoff['stage'],
                'pagebuilder_handoff_synced_at' => $this->now(),
            ]
        );

        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $session;
        $this->syncSessionStructuredFields($fresh, $adminId);
        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $fresh;
        $this->syncSessionArtifacts($fresh, $adminId);
        $this->getEventStreamService()->appendEvent(
            $fresh->getId(),
            $adminId,
            $this->normalizeJourneyStage($fresh->getCurrentStage()),
            'provider_handoff_opened',
            [
                'provider_code' => 'pagebuilder',
                'native_workspace_public_id' => $handoff['public_id'],
                'native_workspace_url' => $handoff['workspace_url'],
                'native_stage' => $handoff['stage'],
            ],
            AiSiteBuilderEvent::LEVEL_INFO
        );

        $this->redirect($handoff['workspace_url']);
        return '';
    }

    #[Acl('Weline_Websites::site_builder_agent_stream', 'Workspace SSE Stream', 'mdi mdi-access-point', 'Stream workspace events', 'Weline_Websites::site_builder_agent')]
    public function getStreamSse(): void
    {
        $sse = new SseWriter();
        $sse->start();

        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $lastEventId = LastEventIdResolver::resolve($this->request, 'last_event_id');

        if ($adminId <= 0 || $publicId === '') {
            $sse->sendError(__('参数无效'));
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $sse->sendError(__('会话不存在或无访问权限'));
            $sse->complete(['success' => false]);
            return;
        }

        $sse->sendEvent('start', ['message' => __('已连接工作区事件流')]);
        $sse->sendEvent('snapshot', $this->buildWorkspaceState($session, $adminId, 40, 40));

        $deadline = \time() + 900;
        $sessionId = $session->getId();
        // 缓存会话验证，避免每次轮询都查数据库
        $sessionValidated = true;
        while (\time() < $deadline && $sse->isAlive()) {
            $events = $this->getEventStreamService()->listEventsAfterId($sessionId, $adminId, $lastEventId, 80, $sessionValidated);
            // 首次查询后，后续轮询跳过会话验证（连接已建立，会话不会突然失效）
            $sessionValidated = false;

            foreach ($events as $event) {
                $eventId = (int)($event['event_id'] ?? 0);
                if ($eventId > $lastEventId) {
                    $lastEventId = $eventId;
                }
                $sse->sendEvent('log', $event);
            }

            $sse->maybeHeartbeat();
            // 降低轮询频率：2秒 → 5秒，减少数据库压力
            SchedulerSystem::yieldDelay(5000);
        }

        $sse->complete([
            'success' => true,
            'message' => __('事件流已结束，可随时重连继续监听。'),
            'last_event_id' => $lastEventId,
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_domain_purchase_stream', 'Workbench Domain Purchase SSE', 'mdi mdi-access-point-network', 'Run a non-blocking workbench domain purchase stream', 'Weline_Websites::site_builder_agent')]
    public function getDomainPurchaseSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->start();

        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $executionToken = \trim((string)$this->request->getGet('execution_token', ''));

        if ($adminId <= 0 || $publicId === '' || $executionToken === '') {
            $sse->sendError((string)__('参数无效'));
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $sse->sendError((string)__('会话不存在或无访问权限'));
            $sse->complete(['success' => false]);
            return;
        }

        $result = $this->getDomainPurchaseWorkbenchService()->executeQueuedPurchase(
            $session->getId(),
            $adminId,
            $executionToken,
            static function (string $event, array $data) use ($sse): void {
                if ($sse->isAlive()) {
                    $sse->sendEvent($event, $data);
                }
            }
        );

        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $session;
        $this->syncSessionArtifacts($fresh, $adminId);

        if (!empty($result['success'])) {
            $this->ensureWebsiteDomainPersisted($fresh);
            $sse->complete([
                'success' => true,
                'completed' => !empty($result['completed']),
                'message' => (string)($result['message'] ?? __('域名购买事件流已结束')),
                'state' => $result['state'] ?? $this->getDomainPurchaseWorkbenchService()->buildViewState($fresh),
            ]);
            return;
        }

        $sse->sendEvent('error', [
            'message' => (string)($result['message'] ?? __('域名购买事件流失败')),
        ]);
        $sse->complete([
            'success' => false,
            'completed' => !empty($result['completed']),
            'message' => (string)($result['message'] ?? __('域名购买事件流失败')),
            'state' => $result['state'] ?? $this->getDomainPurchaseWorkbenchService()->buildViewState($fresh),
        ]);
    }

    #[Acl('Weline_Websites::site_builder_agent_trigger', 'Trigger Site Build', 'mdi mdi-play', 'Trigger the site building flow')]
    public function getTriggerSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => __('正在启动 AI 引导建站流程...')]);

        try {
            $description = \trim((string)$this->request->getGet('description', ''));
            $domain = \strtolower(\trim((string)$this->request->getGet('domain', '')));
            $accountId = (int)$this->request->getGet('account_id', 0);
            $useAi = ($this->request->getGet('use_ai', '1') === '1');
            $fakeMode = $this->isFakeModeRequested();

            if (!$useAi && $domain === '') {
                $sse->sendEvent('error', ['message' => __('请先填写目标域名')]);
                $sse->complete(['success' => false]);
                return;
            }
            if (!$useAi && $accountId <= 0) {
                $sse->sendEvent('error', ['message' => __('请先选择服务商账号')]);
                $sse->complete(['success' => false]);
                return;
            }
            if ($description === '' && $domain === '') {
                $sse->sendEvent('error', ['message' => __('请先描述建站目标，或先提供一个域名')]);
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
                $sse->sendEvent('error', ['message' => __('请同时提供目标域名和服务商账号，或先启用 AI 模式。')]);
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
                    'message' => $result['message'] ?? __('建站流程已完成'),
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
                'detail' => __('引导建站流程发生意外失败'),
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
        $demoMode = (\defined('DEV') && DEV) || $accountId >= 900000;
        if ($demoMode) {
            $promptLines[] = 'Current environment is demo/sandbox mode.';
            $promptLines[] = 'Do not retry domain checks repeatedly when results are empty or uncertain.';
            $promptLines[] = 'Prefer single-pass decisions and continue with sandbox registrar account if available.';
        }

        $prompt = \implode("\n", $promptLines);
        $params = [
            'account_id' => $accountId,
            'demo_mode' => $demoMode ? 1 : 0,
        ];
        $hasAiChunkStream = false;

        $mapEvent = static function (string $eventType, array $data) use ($sse, &$hasAiChunkStream): void {
            if (($eventType === 'ai_response' || $eventType === 'chunk')
                && isset($data['content'])
                && \is_string($data['content'])
                && $data['content'] !== '') {
                if ($eventType === 'ai_response') {
                    $hasAiChunkStream = true;
                    $sse->sendEvent('chunk', ['content' => (string)$data['content']]);
                } elseif (!$hasAiChunkStream) {
                    $sse->sendEvent('chunk', ['content' => (string)$data['content']]);
                }
                return;
            }

            $message = $data['message'] ?? null;
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

            if ($result->success && $result->content !== '' && !$hasAiChunkStream) {
                $sse->sendEvent('chunk', ['content' => $result->content]);
            }

            $sse->complete([
                'success' => $result->success,
                'message' => $result->success ? __('AI 规划完成') : ($result->error ?? __('AI 执行失败')),
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
        $registrarLabel = (string)($registrar['display'] ?? __('本地演示服务商推荐不可用'));
        $brief = $description !== '' ? $description : $resolvedDomain;
        $seed = $brief . '|' . $resolvedDomain . '|' . ($useAi ? 'ai' : 'manual') . '|' . $resolvedAccountId;
        $hash = \substr(\hash('sha256', $seed), 0, 12);
        $websiteId = 800000 + (\hexdec(\substr($hash, 0, 4)) % 10000);
        $themeId = 600000 + (\hexdec(\substr($hash, 4, 4)) % 10000);
        $previewUrl = $this->buildSimulatedPreviewUrl($resolvedDomain, 'websites_default', []);

        $timeline = [
            ['progress', ['message' => (string)__('本地演示：已理解需求简报'), 'stage' => 'prepare', 'fake_mode' => true]],
            ['info', ['message' => (string)__('Local demo: recommended registrar %{registrar}', ['registrar' => $registrarLabel]), 'stage' => 'prepare', 'account_id' => $resolvedAccountId, 'fake_mode' => true]],
            ['info', ['message' => (string)__('Local demo: suggested domain %{domain}', ['domain' => $resolvedDomain]), 'stage' => 'prepare', 'domain' => $resolvedDomain, 'fake_mode' => true]],
            ['info', ['message' => (string)__('本地演示：模拟可用性检测通过 %{domain}', ['domain' => $resolvedDomain]), 'stage' => 'prepare', 'domain' => $resolvedDomain, 'availability' => 'simulated_available', 'fake_mode' => true]],
            ['progress', ['message' => (string)__('本地演示：已模拟域名购买并初始化基础资源'), 'stage' => 'prepare', 'domain' => $resolvedDomain, 'account_id' => $resolvedAccountId, 'registrar' => $registrarLabel, 'fake_mode' => true]],
            ['progress', ['message' => (string)__('本地演示：已模拟 DNS 解析与证书签发'), 'stage' => 'complete', 'domain' => $resolvedDomain, 'dns_status' => 'simulated_ready', 'certificate_status' => 'simulated_issued', 'fake_mode' => true]],
            ['progress', ['message' => (string)__('本地演示：已生成页面结构与初始内容'), 'stage' => 'generate', 'website_id' => $websiteId, 'fake_mode' => true]],
            ['progress', ['message' => (string)__('本地演示：已生成主题方向与虚拟主题'), 'stage' => 'generate', 'theme_id' => $themeId, 'fake_mode' => true]],
            ['progress', ['message' => (string)__('本地演示：已准备可视化编辑预览'), 'stage' => 'complete', 'preview_url' => $previewUrl, 'fake_mode' => true]],
        ];

        foreach ($timeline as [$eventName, $payload]) {
            $sse->sendEvent((string)$eventName, $payload);
        }

        $result = [
            'success' => true,
            'message' => (string)__('本地演示流程已完成'),
            'domain' => $resolvedDomain,
            'website_id' => $websiteId,
            'theme_id' => $themeId,
            'account_id' => $resolvedAccountId,
            'registrar' => $registrarLabel,
            'preview_url' => $previewUrl,
            'fake_mode' => true,
        ];

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
                'account_name' => (string)__('本地演示主账号'),
                'registrar_name' => (string)__('本地演示服务商'),
                'registrar_code' => 'local_demo',
            ],
            [
                'account_id' => 900002,
                'account_name' => (string)__('本地演示备用账号'),
                'registrar_name' => (string)__('沙盒域名'),
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
            'pagebuilder' => $urlHelper->getBackendUrl('pagebuilder/backend/ai-site-agent/index'),
            'websites_default' => $this->getHubEntryUrl('websites_default'),
            default => $this->getHubEntryUrl($providerCode),
        };
    }

    private function resolveProviderActionLabel(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('打开 PageBuilder 流程'),
            'websites_default' => (string)__('打开 Websites 流程'),
            default => (string)__('打开服务商流程'),
        };
    }

    private function resolveProviderBadge(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => (string)__('样式流程'),
            'websites_default' => (string)__('共享流程'),
            default => (string)__('服务商流程'),
        };
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function getStageOptions(): array
    {
        return [
            ['value' => 'prepare', 'label' => (string)__('准备')],
            ['value' => 'generate', 'label' => (string)__('生成')],
            ['value' => 'complete', 'label' => (string)__('完成')],
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
                'label' => (string)__('第一阶段'),
                'title' => (string)__('信息准备'),
                'description' => (string)__('把建站描述、服务商选择、域名推荐与可用性确认一次整理完成。'),
                'ai_recommendation_title' => (string)__('AI 推荐'),
                'ai_recommendation' => (string)__('AI 会先推荐可购买域名与服务商，人工确认后进入自动购买/解析/证书流程。'),
                'confirm_label' => (string)__('确认并进入页面生成'),
                'previous_label' => '',
                'next_stage' => 'generate',
                'previous_stage' => '',
                'key_points' => [
                    (string)__('先描述你要建设的网站目标'),
                    (string)__('AI 推荐域名并检测可用性'),
                    (string)__('人工确认后自动执行购买、解析与证书'),
                ],
            ],
            'generate' => [
                'label' => (string)__('第二阶段'),
                'title' => (string)__('页面生成'),
                'description' => (string)__('在虚拟主题阶段直接选页面类型并编辑每类页面内容，Header/Footer 固定。'),
                'ai_recommendation_title' => (string)__('AI 页面方案'),
                'ai_recommendation' => (string)__('可选现有模板替换区域，或让 AI 直接生成数据库虚拟主题与内容组件。'),
                'confirm_label' => (string)__('确认页面方案'),
                'previous_label' => (string)__('返回信息准备'),
                'next_stage' => 'complete',
                'previous_stage' => 'prepare',
                'key_points' => [
                    (string)__('默认全页面类型，可在此阶段按需勾选'),
                    (string)__('Header/Footer 固定不可编辑'),
                    (string)__('正文区域支持 AI 组件生成并可视化保存'),
                ],
            ],
            'complete' => [
                'label' => (string)__('第三阶段'),
                'title' => (string)__('完成'),
                'description' => (string)__('等待域名准备为可建站状态后预览，必要时可回退上一阶段修改。'),
                'ai_recommendation_title' => (string)__('AI 最终检查'),
                'ai_recommendation' => (string)__('发布前检查域名入库、解析与证书状态，以及主题与页面配置是否完整。'),
                'confirm_label' => (string)__('保留当前方案'),
                'previous_label' => (string)__('返回页面生成'),
                'next_stage' => '',
                'previous_stage' => 'generate',
                'key_points' => [
                    (string)__('等待购买、解析、证书全部就绪'),
                    (string)__('预览站点并确认关键页面'),
                    (string)__('不满足时回退继续编辑'),
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
                $hasDraftWebsite = (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0) > 0;
                $hasVisualPreview = \trim((string)($scope['visual_preview_url'] ?? '')) !== '';
                $styleTemplate = (string)($scope['style_template_code'] ?? $scope['pagebuilder_style_template'] ?? '');
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
                        'provider_handoff_mode' => self::PAGEBUILDER_HANDOFF_MODE_NATIVE_WORKSPACE,
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
     * @return array{public_id:string,workspace_url:string,stage:string}|null
     */
    private function createOrResumePageBuilderHandoff(AiSiteBuilderSession $session, int $adminUserId): ?array
    {
        $pageBuilderSessionService = $this->getPageBuilderSessionService();
        if ($pageBuilderSessionService === null) {
            return null;
        }

        $scope = $session->getScopeArray();
        $handoffScope = $this->buildPageBuilderHandoffScope($session, $scope);
        $handoffStage = $this->resolvePageBuilderHandoffStage($scope);
        $existingPublicId = \trim((string)($scope['pagebuilder_workspace_public_id'] ?? ''));
        $nativeSession = null;

        if ($existingPublicId !== '') {
            try {
                $nativeSession = $pageBuilderSessionService->loadByPublicId($existingPublicId, $adminUserId);
            } catch (\Throwable) {
                $nativeSession = null;
            }
        }

        try {
            if ($nativeSession === null) {
                $nativeSession = $pageBuilderSessionService->createSession($adminUserId, $handoffScope);
                $pageBuilderSessionService->setStage($nativeSession->getId(), $adminUserId, $handoffStage);
                $pageBuilderSessionService->appendEvent(
                    $nativeSession->getId(),
                    $adminUserId,
                    'handoff_from_websites',
                    [
                        'source_public_id' => $session->getPublicId(),
                        'source_provider_code' => $session->getProviderCode(),
                        'stage' => $handoffStage,
                    ]
                );
                $nativeSession = $pageBuilderSessionService->loadById($nativeSession->getId(), $adminUserId) ?? $nativeSession;
            } else {
                $pageBuilderSessionService->mergeScope($nativeSession->getId(), $adminUserId, $handoffScope);
                if ($this->getPageBuilderStageRank((string)$nativeSession->getStage()) < $this->getPageBuilderStageRank($handoffStage)) {
                    $pageBuilderSessionService->setStage($nativeSession->getId(), $adminUserId, $handoffStage);
                }
                $pageBuilderSessionService->appendEvent(
                    $nativeSession->getId(),
                    $adminUserId,
                    'handoff_sync_from_websites',
                    [
                        'source_public_id' => $session->getPublicId(),
                        'source_provider_code' => $session->getProviderCode(),
                        'stage' => $handoffStage,
                    ]
                );
                $nativeSession = $pageBuilderSessionService->loadById($nativeSession->getId(), $adminUserId) ?? $nativeSession;
            }
        } catch (\Throwable) {
            return null;
        }

        if (!\is_object($nativeSession) || !\method_exists($nativeSession, 'getPublicId')) {
            return null;
        }

        $nativePublicId = \trim((string)$nativeSession->getPublicId());
        if ($nativePublicId === '') {
            return null;
        }

        return [
            'public_id' => $nativePublicId,
            'workspace_url' => $this->getUrlHelper()->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $nativePublicId]),
            'stage' => \method_exists($nativeSession, 'getStage') ? (string)$nativeSession->getStage() : $handoffStage,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildPageBuilderHandoffScope(AiSiteBuilderSession $session, array $scope): array
    {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $siteTitle = \trim((string)($scope['site_title'] ?? ''));
        $siteTagline = \trim((string)($scope['site_tagline'] ?? ''));
        $targetDomain = \strtolower(\trim((string)($scope['target_domain'] ?? $scope['selected_domain'] ?? $session->getSelectedDomain())));
        $recommendedPages = $this->normalizeScopeStringList($scope['page_types'] ?? $scope['recommended_pages'] ?? []);
        if ($recommendedPages === []) {
            $recommendedPages = $this->buildRecommendedPages($brief, 'pagebuilder', $scope);
        }
        $styleTemplate = '';
        $defaultLocale = \trim((string)($scope['default_locale'] ?? $scope['default_language'] ?? ''));
        $locales = $this->normalizeScopeStringList($scope['locales'] ?? $scope['language_codes'] ?? []);
        if ($defaultLocale !== '' && !\in_array($defaultLocale, $locales, true)) {
            $locales[] = $defaultLocale;
        }

        $virtualThemeNotes = \trim((string)($scope['virtual_theme_notes'] ?? ''));
        if ($virtualThemeNotes === '') {
            $virtualThemeNotes = (string)__('从 Websites 工作区接管，推荐 styles 模板：%{template}', ['template' => $styleTemplate]);
        }

        $contentNotes = \trim((string)($scope['content_notes'] ?? $scope['workbench_notes'] ?? ''));
        if ($contentNotes === '' && $brief !== '') {
            $contentNotes = $brief;
        }

        $handoffScope = [
            'handoff_source' => 'weline_websites_workbench',
            'handoff_workspace_public_id' => $session->getPublicId(),
            'handoff_provider_code' => $session->getProviderCode(),
            'site_title' => $siteTitle,
            'site_tagline' => $siteTagline,
            'brief_description' => $brief,
            'user_description' => $brief !== '' ? $brief : \trim((string)($scope['user_description'] ?? '')),
            'target_domain' => $targetDomain,
            'default_locale' => $defaultLocale,
            'locales' => $locales,
            'preferred_editor' => 'pagebuilder',
            'provider_handoff_mode' => self::PAGEBUILDER_HANDOFF_MODE_NATIVE_WORKSPACE,
            'page_types' => $recommendedPages,
            'recommended_pages' => $recommendedPages,
        ];

        foreach ([
            'website_profile',
            'draft_website_id',
            'weline_theme_id',
            'page_type_layouts',
            'pagebuilder_pages_by_type',
            'preview_page_options',
            'preview_page_id',
            'preview_page_type',
            'preview_full_url',
            'visual_preview_url',
            'visual_edit_url',
            'home_page_id',
        ] as $field) {
            if (\array_key_exists($field, $scope)) {
                $handoffScope[$field] = $scope[$field];
            }
        }

        foreach (['pagebuilder_workspace_public_id', 'pagebuilder_workspace_url'] as $field) {
            $value = \trim((string)($scope[$field] ?? ''));
            if ($value !== '') {
                $handoffScope[$field] = $value;
            }
        }

        $websiteId = (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? $scope['selected_website_id'] ?? $session->getWebsiteId());
        if ($websiteId > 0) {
            $handoffScope['draft_website_id'] = $websiteId;
            $handoffScope['website_id'] = $websiteId;
            $handoffScope['selected_website_id'] = $websiteId;
        }

        return $handoffScope;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolvePageBuilderHandoffStage(array $scope): string
    {
        if ((int)($scope['preview_page_id'] ?? 0) > 0) {
            return 'visual_edit';
        }

        $pagesByType = $scope['pagebuilder_pages_by_type'] ?? [];
        if (\is_array($pagesByType) && $pagesByType !== []) {
            return 'visual_edit';
        }

        return 'virtual_theme';
    }

    private function getPageBuilderStageRank(string $stage): int
    {
        return match (\trim($stage)) {
            'brief' => 10,
            'domain' => 20,
            'domain_wait' => 30,
            'virtual_theme' => 40,
            'page_types', 'content' => 40,
            'visual_edit' => 70,
            'publish' => 80,
            default => 0,
        };
    }

    /**
     * @return list<string>
     */
    private function normalizeScopeStringList(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded) ? $decoded : (\preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        } else {
            $items = [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $item = \trim((string)$item);
            if ($item === '' || \in_array($item, $result, true)) {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }

    private function getPageBuilderSessionService(): ?object
    {
        $serviceClass = \GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService::class;
        if (!\class_exists($serviceClass)) {
            return null;
        }

        try {
            return ObjectManager::getInstance($serviceClass);
        } catch (\Throwable) {
            return null;
        }
    }

    private function refreshPageBuilderMirrorSession(AiSiteBuilderSession $session, int $adminUserId): AiSiteBuilderSession
    {
        if ($session->getProviderCode() !== 'pagebuilder') {
            return $session;
        }

        $nativeState = $this->loadPageBuilderNativeWorkspaceState($session, $adminUserId);
        if ($nativeState === null) {
            return $session;
        }

        $changedPatch = $this->diffScopePatch($session->getScopeArray(), $this->buildPageBuilderMirrorScope($session, $nativeState));
        if ($changedPatch !== []) {
            $changedPatch['pagebuilder_handoff_synced_at'] = $this->now();
            $this->getSessionService()->mergeScope($session->getId(), $adminUserId, $changedPatch);
        }

        $journeyStage = $this->resolveJourneyStage((string)($nativeState['stage'] ?? ''));
        if ($journeyStage !== null && $this->normalizeJourneyStage($session->getCurrentStage()) !== $journeyStage) {
            $this->getSessionService()->setStage($session->getId(), $adminUserId, $journeyStage);
        }

        $fresh = $this->getSessionService()->loadById($session->getId(), $adminUserId) ?? $session;
        $this->syncSessionStructuredFields($fresh, $adminUserId);
        return $this->getSessionService()->loadById($session->getId(), $adminUserId) ?? $fresh;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPageBuilderNativeWorkspaceState(AiSiteBuilderSession $session, int $adminUserId): ?array
    {
        $scope = $session->getScopeArray();
        $nativePublicId = \trim((string)($scope['pagebuilder_workspace_public_id'] ?? ''));
        if ($nativePublicId === '') {
            return null;
        }

        $pageBuilderSessionService = $this->getPageBuilderSessionService();
        if ($pageBuilderSessionService === null || !\method_exists($pageBuilderSessionService, 'loadByPublicId')) {
            return null;
        }

        try {
            $nativeSession = $pageBuilderSessionService->loadByPublicId($nativePublicId, $adminUserId);
        } catch (\Throwable) {
            return null;
        }

        if (!\is_object($nativeSession) || !\method_exists($nativeSession, 'getScopeArray')) {
            return null;
        }

        $nativeScope = $nativeSession->getScopeArray();
        if (!\is_array($nativeScope)) {
            $nativeScope = [];
        }

        $compatibilityService = $this->getPageBuilderScopeCompatibilityService();
        if ($compatibilityService !== null && \method_exists($compatibilityService, 'normalizeScope')) {
            try {
                $nativeScope = $compatibilityService->normalizeScope($nativeScope);
            } catch (\Throwable) {
            }
        }

        $websiteProfile = \is_array($nativeScope['website_profile'] ?? null) ? $nativeScope['website_profile'] : [];
        $profileGenerationService = $this->getPageBuilderProfileGenerationService();
        if ($profileGenerationService !== null && \method_exists($profileGenerationService, 'generate')) {
            try {
                $websiteProfile = $profileGenerationService->generate($nativeScope);
            } catch (\Throwable) {
            }
        }
        $nativeScope['website_profile'] = $websiteProfile;

        $draftWebsiteId = \max(
            (int)($nativeScope['draft_website_id'] ?? 0),
            (int)($nativeScope['website_id'] ?? 0),
            \method_exists($nativeSession, 'getWebsiteId') ? (int)$nativeSession->getWebsiteId() : 0
        );
        if ($draftWebsiteId > 0) {
            $nativeScope['draft_website_id'] = $draftWebsiteId;
            $nativeScope['website_id'] = $draftWebsiteId;
            $nativeScope['selected_website_id'] = $draftWebsiteId;
        }

        $welineThemeId = \max(
            (int)($nativeScope['weline_theme_id'] ?? 0),
            \method_exists($nativeSession, 'getWelineThemeId') ? (int)$nativeSession->getWelineThemeId() : 0
        );
        if ($welineThemeId > 0) {
            $nativeScope['weline_theme_id'] = $welineThemeId;
        }

        $pagesByType = \is_array($nativeScope['pagebuilder_pages_by_type'] ?? null) ? $nativeScope['pagebuilder_pages_by_type'] : [];
        if ($compatibilityService !== null && \method_exists($compatibilityService, 'normalizePagebuilderPagesByType')) {
            try {
                $pagesByType = $compatibilityService->normalizePagebuilderPagesByType($pagesByType);
            } catch (\Throwable) {
            }
        }
        $nativeScope['pagebuilder_pages_by_type'] = $pagesByType;

        $previewPageId = (int)($nativeScope['preview_page_id'] ?? 0);
        $previewPageType = (string)($nativeScope['preview_page_type'] ?? '');
        if ($compatibilityService !== null && \method_exists($compatibilityService, 'resolvePreviewSelection')) {
            try {
                $selection = $compatibilityService->resolvePreviewSelection($pagesByType, $previewPageId, $previewPageType);
                $previewPageId = (int)($selection['preview_page_id'] ?? 0);
                $previewPageType = (string)($selection['preview_page_type'] ?? '');
            } catch (\Throwable) {
            }
        }
        $nativeScope['preview_page_id'] = $previewPageId;
        $nativeScope['preview_page_type'] = $previewPageType;

        $previewPageOptions = \is_array($nativeScope['preview_page_options'] ?? null) ? $nativeScope['preview_page_options'] : [];
        if ($compatibilityService !== null && \method_exists($compatibilityService, 'buildPreviewPageOptions')) {
            try {
                $previewPageOptions = $compatibilityService->buildPreviewPageOptions($pagesByType);
            } catch (\Throwable) {
            }
        }
        $nativeScope['preview_page_options'] = $previewPageOptions;

        $urls = [
            'preview_full_url' => (string)($nativeScope['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($nativeScope['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($nativeScope['visual_edit_url'] ?? ''),
        ];
        $visualUrlService = $this->getPageBuilderVisualUrlService();
        if ($visualUrlService !== null && \method_exists($visualUrlService, 'resolveUrls')) {
            try {
                $urls = $visualUrlService->resolveUrls($previewPageId, $welineThemeId);
            } catch (\Throwable) {
            }
        }
        $nativeScope = \array_replace($nativeScope, $urls);

        return [
            'public_id' => $nativePublicId,
            'stage' => \method_exists($nativeSession, 'getStage') ? (string)$nativeSession->getStage() : 'virtual_theme',
            'scope' => $nativeScope,
        ];
    }

    /**
     * @param array<string, mixed> $nativeState
     * @return array<string, mixed>
     */
    private function buildPageBuilderMirrorScope(AiSiteBuilderSession $session, array $nativeState): array
    {
        $nativeScope = \is_array($nativeState['scope'] ?? null) ? $nativeState['scope'] : [];
        $publicId = (string)($nativeState['public_id'] ?? '');
        $mirror = [
            'pagebuilder_workspace_public_id' => $publicId,
            'pagebuilder_workspace_url' => $publicId !== ''
                ? $this->getUrlHelper()->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $publicId])
                : '',
            'pagebuilder_handoff_stage' => (string)($nativeState['stage'] ?? ''),
            'provider_handoff_mode' => self::PAGEBUILDER_HANDOFF_MODE_NATIVE_WORKSPACE,
            'provider_handoff_ready' => 1,
            'website_profile' => \is_array($nativeScope['website_profile'] ?? null) ? $nativeScope['website_profile'] : [],
            'draft_website_id' => (int)($nativeScope['draft_website_id'] ?? $nativeScope['website_id'] ?? 0),
            'weline_theme_id' => (int)($nativeScope['weline_theme_id'] ?? 0),
            'page_types' => $nativeScope['page_types'] ?? [],
            'recommended_pages' => $nativeScope['recommended_pages'] ?? ($nativeScope['page_types'] ?? []),
            'page_type_layouts' => $nativeScope['page_type_layouts'] ?? [],
            'pagebuilder_pages_by_type' => $nativeScope['pagebuilder_pages_by_type'] ?? [],
            'preview_page_options' => $nativeScope['preview_page_options'] ?? [],
            'preview_page_id' => (int)($nativeScope['preview_page_id'] ?? 0),
            'preview_page_type' => (string)($nativeScope['preview_page_type'] ?? ''),
            'preview_full_url' => (string)($nativeScope['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($nativeScope['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($nativeScope['visual_edit_url'] ?? ''),
        ];

        if ($mirror['draft_website_id'] > 0) {
            $mirror['website_id'] = $mirror['draft_website_id'];
            $mirror['selected_website_id'] = $mirror['draft_website_id'];
        }

        foreach ([
            'site_title',
            'site_tagline',
            'brief_description',
            'user_description',
            'target_domain',
            'default_locale',
            'locales',
            'home_page_id',
        ] as $field) {
            if (\array_key_exists($field, $nativeScope)) {
                $mirror[$field] = $nativeScope[$field];
            }
        }

        return $mirror;
    }

    /**
     * @param array<string, mixed> $currentScope
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function diffScopePatch(array $currentScope, array $patch): array
    {
        $changed = [];
        foreach ($patch as $key => $value) {
            if (!$this->scopeValuesEqual($currentScope[$key] ?? null, $value)) {
                $changed[$key] = $value;
            }
        }

        return $changed;
    }

    private function scopeValuesEqual(mixed $left, mixed $right): bool
    {
        if (\is_array($left) || \is_array($right)) {
            return \json_encode($left, \JSON_UNESCAPED_UNICODE) === \json_encode($right, \JSON_UNESCAPED_UNICODE);
        }

        return $left === $right;
    }

    private function getPageBuilderScopeCompatibilityService(): ?object
    {
        $serviceClass = \GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService::class;
        if (!\class_exists($serviceClass)) {
            return null;
        }

        try {
            return ObjectManager::getInstance($serviceClass);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getPageBuilderProfileGenerationService(): ?object
    {
        $serviceClass = \GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService::class;
        if (!\class_exists($serviceClass)) {
            return null;
        }

        try {
            return ObjectManager::getInstance($serviceClass);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getPageBuilderVisualUrlService(): ?object
    {
        $serviceClass = \GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService::class;
        if (!\class_exists($serviceClass)) {
            return null;
        }

        try {
            return ObjectManager::getInstance($serviceClass);
        } catch (\Throwable) {
            return null;
        }
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
     *   domain_purchase:array<string, mixed>,
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
        $session = $this->refreshPageBuilderMirrorSession($session, $adminUserId);
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
            'domain_purchase' => $this->getDomainPurchaseWorkbenchService()->buildViewState($session),
            'stage_guides' => $this->buildStageGuides($providerConfig, $scope, $currentStage),
            'messages' => $this->getMessageService()->listForSession($session->getId(), $adminUserId, $messageLimit),
            'events' => $this->getEventStreamService()->listRecentEvents($session->getId(), $adminUserId, $eventLimit),
            'artifacts' => [
                'snapshot' => $this->getArtifactService()->getOne($session->getId(), $adminUserId, 'workspace', 'scope_snapshot'),
                'handoff' => $this->getArtifactService()->getOne($session->getId(), $adminUserId, 'handoff', $session->getProviderCode()),
            ],
            'provider_tools' => \is_array($providerConfig['tools'] ?? null) ? $providerConfig['tools'] : [],
            'draft_website_id' => (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0),
            'pagebuilder_pages_by_type' => \is_array($scope['pagebuilder_pages_by_type'] ?? null) ? $scope['pagebuilder_pages_by_type'] : [],
            'preview_page_options' => \is_array($scope['preview_page_options'] ?? null) ? $scope['preview_page_options'] : [],
            'preview_page_id' => (int)($scope['preview_page_id'] ?? 0),
            'preview_page_type' => (string)($scope['preview_page_type'] ?? ''),
            'preview_full_url' => (string)($scope['preview_full_url'] ?? $session->getPreviewUrl()),
            'visual_preview_url' => (string)($scope['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($scope['visual_edit_url'] ?? ''),
        ];
    }

    private function jsonMutateScope(bool $merge): string
    {
        $adminId = $this->getAdminId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));

        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->getSessionService()->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无访问权限')]);
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
            return $this->fetchJson(['success' => false, 'message' => __('重新加载工作区会话失败')]);
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
        $websiteId = (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? $scope['selected_website_id'] ?? $session->getWebsiteId());
        $previewUrl = \trim((string)(
            ($session->getProviderCode() === 'pagebuilder'
                ? ($scope['visual_preview_url'] ?? $scope['preview_full_url'] ?? $scope['preview_url'] ?? '')
                : ($scope['preview_full_url'] ?? $scope['preview_url'] ?? '')
            ) ?: $session->getPreviewUrl()
        ));

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
            (string)__('工作区范围快照')
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
            (string)__('服务商交接信息')
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

    private function resolveProviderNativeEntryUrl(string $providerCode): string
    {
        return match ($providerCode) {
            'pagebuilder' => $this->getUrlHelper()->getBackendUrl('pagebuilder/backend/ai-site-agent/index'),
            'websites_default' => $this->getHubEntryUrl('websites_default', $this->isFakeModeRequested()),
            default => $this->getHubEntryUrl($providerCode, $this->isFakeModeRequested()),
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

    private function isLocalWelineSubdomain(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        return $domain !== '' && \str_ends_with($domain, '.weline.local');
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

    /**
     * 本地 fake / 演示模式：生成若干 *.weline.local 候选，便于联调与 E2E（无需真实注册商可用性）。
     *
     * @return list<string>
     */
    private function buildFakeWelineLocalDomains(string $brief, int $count = 5): array
    {
        $slug = \strtolower((string)\preg_replace('/[^a-z0-9]+/i', '-', $brief));
        $slug = \trim($slug, '-');
        if ($slug === '') {
            $slug = 'demo';
        }
        $parts = \array_values(\array_filter(\explode('-', $slug), static fn (string $p): bool => $p !== ''));
        $parts = \array_slice($parts, 0, 2);
        $base = \implode('-', $parts);
        if ($base === '') {
            $base = 'demo';
        }
        $digits = \preg_replace('/\D/', '', (string)\microtime(true)) ?? '';
        $ts = \substr($digits !== '' ? $digits : (string)\random_int(10000000, 99999999), -8);
        $out = [];
        for ($i = 0; $i < \max(1, $count); $i += 1) {
            $suffix = $i === 0 ? $ts : $ts . '-' . $i;
            $out[] = $base . '-' . $suffix . '.weline.local';
        }

        return $out;
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
        // 使用当前请求的 storefront 根路径，避免硬编码 *.local.test 在未配置 DNS/hosts 时 iframe 无法解析
        $base = $this->getUrlHelper()->getOriginUrl('/');
        if ($domain === '') {
            return $base;
        }
        $sep = \str_contains($base, '?') ? '&' : '?';

        return $base . $sep . 'ai_sim_preview_domain=' . \rawurlencode($domain);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildSimulatedPreviewUrl(string $domain, string $providerCode, array $scope): string
    {
        $scopePreviewUrl = $providerCode === 'pagebuilder'
            ? \trim((string)($scope['visual_preview_url'] ?? $scope['preview_full_url'] ?? $scope['preview_url'] ?? ''))
            : \trim((string)($scope['preview_full_url'] ?? $scope['preview_url'] ?? ''));
        if ($scopePreviewUrl !== '') {
            return $scopePreviewUrl;
        }

        // PageBuilder 使用后台预览地址，不使用前台地址
        if ($providerCode === 'pagebuilder') {
            // 返回 PageBuilder 后台的页面列表地址作为兜底
            return $this->getUrlHelper()->getBackendUrl('pagebuilder/backend/page/index');
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

    private function now(): string
    {
        return \date('Y-m-d H:i:s');
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

    private function getDomainPurchaseWorkbenchService(): DomainPurchaseWorkbenchService
    {
        /** @var DomainPurchaseWorkbenchService $service */
        $service = ObjectManager::getInstance(DomainPurchaseWorkbenchService::class);
        return $service;
    }

    private function getDomainLifecycleBridgeService(): \Weline\Websites\Service\AiWorkbench\DomainLifecycleBridgeService
    {
        /** @var \Weline\Websites\Service\AiWorkbench\DomainLifecycleBridgeService $service */
        $service = ObjectManager::getInstance(\Weline\Websites\Service\AiWorkbench\DomainLifecycleBridgeService::class);
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

    private function getVirtualThemeWorkbenchService(): VirtualThemeWorkbenchService
    {
        /** @var VirtualThemeWorkbenchService $service */
        $service = ObjectManager::getInstance(VirtualThemeWorkbenchService::class);
        return $service;
    }

    private function ensureWebsiteDomainPersisted(AiSiteBuilderSession $session): void
    {
        $websiteId = $session->getWebsiteId();
        if ($websiteId <= 0) {
            return;
        }
        $domain = \strtolower(\trim($session->getSelectedDomain()));
        if ($domain === '') {
            return;
        }
        if (!\class_exists(\Weline\Websites\Model\WebsiteDomain::class)) {
            return;
        }

        /** @var \Weline\Websites\Model\WebsiteDomain $domainModel */
        $domainModel = ObjectManager::getInstance(\Weline\Websites\Model\WebsiteDomain::class);
        $exists = clone $domainModel;
        $exists->clearData()->clearQuery()->loadByDomain($domain);
        if ((int)$exists->getDomainId() > 0) {
            return;
        }

        $record = clone $domainModel;
        $record->clearData()->clearQuery();
        $record->setWebsiteId($websiteId)
            ->setDomain($domain)
            ->setSubPath('')
            ->setIsPrimary(false)
            ->setStatus(\Weline\Websites\Model\WebsiteDomain::STATUS_ACTIVE)
            ->save();
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildVirtualThemeDraftFallback(array $scope, array $pageTypes): array
    {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $title = \trim((string)($scope['site_title'] ?? ''));
        $seed = $title !== '' ? $title : ($brief !== '' ? $brief : 'ai-site');
        $slug = \strtolower((string)\preg_replace('/[^a-z0-9]+/', '-', $seed));
        $slug = \trim($slug, '-');
        if ($slug === '') {
            $slug = 'ai-site';
        }
        $slug = \substr($slug, 0, 36);

        $layouts = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '') {
                continue;
            }
            $layouts[$pageType] = [
                'page_type' => $pageType,
                'regions' => [
                    'header' => ['locked' => true],
                    'content' => ['locked' => false],
                    'footer' => ['locked' => true],
                ],
                'components' => [
                    [
                        'code' => 'content/ai-generated-section',
                        'title' => (string)__('AI 生成内容区块'),
                        'description' => (string)__('根据简报生成的页面主体内容'),
                    ],
                ],
            ];
        }

        return [
            'virtual_theme_name' => (string)($scope['virtual_theme_name'] ?? ('ai-' . $slug)),
            'theme_style_direction' => (string)($scope['theme_style_direction'] ?? (string)__('简约科技 / 品牌信任感 / 高对比行动按钮')),
            'theme_color_scheme' => (string)($scope['theme_color_scheme'] ?? '#0ea5e9,#22c55e,#0f172a'),
            'page_type_layouts' => $layouts,
            'component_template' => (string)($scope['virtual_component_template'] ?? "<section class=\"ai-generated-section\">\n    <h2>{{ title|default('AI Generated Title') }}</h2>\n    <p>{{ description|default('Generated content from brief.') }}</p>\n</section>"),
        ];
    }

    /**
     * @param array<string, mixed> $layouts
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function normalizeThemePageLayouts(array $layouts, array $pageTypes): array
    {
        $normalized = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '') {
                continue;
            }
            $layout = $layouts[$pageType] ?? [];
            if (!\is_array($layout)) {
                $layout = [];
            }
            $layout['page_type'] = $pageType;
            $layout['regions'] = \is_array($layout['regions'] ?? null) ? $layout['regions'] : [];
            if (!isset($layout['regions']['header']) || !\is_array($layout['regions']['header'])) {
                $layout['regions']['header'] = [];
            }
            if (!isset($layout['regions']['content']) || !\is_array($layout['regions']['content'])) {
                $layout['regions']['content'] = [];
            }
            if (!isset($layout['regions']['footer']) || !\is_array($layout['regions']['footer'])) {
                $layout['regions']['footer'] = [];
            }
            $layout['regions']['header']['locked'] = true;
            $layout['regions']['footer']['locked'] = true;
            if (!\array_key_exists('locked', $layout['regions']['content'])) {
                $layout['regions']['content']['locked'] = false;
            }
            if (!\is_array($layout['components'] ?? null)) {
                $layout['components'] = [];
            }
            $normalized[$pageType] = $layout;
        }

        return $normalized;
    }

    /**
     * 渐进式流式生成虚拟主题（首页先生成，其他页面依次生成）
     *
     * @param array<int, string> $selectedPageTypes
     * @param array<string, mixed> $scope
     */
    private function handleProgressiveGeneration(
        SseWriter $sse,
        AiSiteBuilderSession $session,
        int $adminId,
        string $publicId,
        array $selectedPageTypes,
        array $scope
    ): void {
        $sse->sendEvent('start', ['message' => (string)__('开始渐进式生成虚拟主题...')]);

        // 确保首页在第一位
        $homePageIndex = \array_search('home_page', $selectedPageTypes, true);
        if ($homePageIndex !== false && $homePageIndex !== 0) {
            unset($selectedPageTypes[$homePageIndex]);
            \array_unshift($selectedPageTypes, 'home_page');
        }

        $totalPages = \count($selectedPageTypes);
        $currentPage = 0;
        $sharedHeaderFooter = null;
        $welineThemeId = 0;

        foreach ($selectedPageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '') {
                continue;
            }

            $currentPage++;
            $isHomePage = $pageType === 'home_page';

            $sse->sendEvent('progress', [
                'page_type' => $pageType,
                'status' => 'generating',
                'message' => (string)__('正在生成 %{page_type}...', ['page_type' => $pageType]),
                'progress' => ($currentPage / $totalPages) * 100,
            ]);

            // 生成页面布局
            $layoutResult = $this->generateSinglePageLayout(
                $session,
                $adminId,
                $pageType,
                $scope,
                $isHomePage ? null : $sharedHeaderFooter
            );

            if (!$layoutResult['success']) {
                $sse->sendEvent('error', [
                    'page_type' => $pageType,
                    'message' => $layoutResult['message'] ?? (string)__('生成失败'),
                ]);
                continue;
            }

            $layout = $layoutResult['layout'];

            // 首页生成后，保存 Header/Footer 供其他页面复用
            if ($isHomePage) {
                $sharedHeaderFooter = [
                    'header' => $layout['regions']['header'] ?? [],
                    'footer' => $layout['regions']['footer'] ?? [],
                ];

                // 创建虚拟主题记录
                $saveThemeResult = $this->getVirtualThemeWorkbenchService()->saveVirtualThemeByPublicId($publicId, $adminId, [
                    'weline_theme_id' => (int)($scope['weline_theme_id'] ?? 0),
                    'virtual_theme_name' => (string)($layoutResult['theme_name'] ?? 'ai-site-' . $session->getId()),
                    'theme_style_direction' => (string)($scope['theme_style_direction'] ?? 'modern'),
                    'theme_color_scheme' => (string)($scope['theme_color_scheme'] ?? 'default'),
                    'page_types' => $selectedPageTypes,
                    'page_type_layouts' => [$pageType => $layout],
                ]);

                if (!empty($saveThemeResult['success'])) {
                    $welineThemeId = (int)($saveThemeResult['data']['weline_theme_id'] ?? 0);
                }
            }

            // 保存页面布局
            $this->getVirtualThemeWorkbenchService()->savePageTypeLayoutByPublicId($publicId, $adminId, $pageType, $layout);

            // 标记页面已生成
            $this->markPageTypeGenerated($session->getId(), $adminId, $pageType);

            // 生成可视化编辑 URL
            $visualEditUrl = $this->getUrlHelper()->getBackendUrlPath('pagebuilder/backend/page/virtual-edit', [
                'public_id' => $publicId,
                'page_type' => $pageType,
            ]);

            $sse->sendEvent('page_generated', [
                'page_type' => $pageType,
                'layout' => $layout,
                'visual_edit_url' => $visualEditUrl,
                'weline_theme_id' => $welineThemeId,
            ]);

            $sse->maybeHeartbeat();
            SchedulerSystem::yieldDelay(500);
        }

        $this->getSessionService()->mergeScope($session->getId(), $adminId, [
            'virtual_theme_auto_generated' => 1,
            'virtual_theme_generated_at' => $this->now(),
        ]);

        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $session;
        $fresh = $this->refreshPageBuilderMirrorSession($fresh, $adminId);
        $this->syncSessionStructuredFields($fresh, $adminId);
        $fresh = $this->getSessionService()->loadById($session->getId(), $adminId) ?? $fresh;
        $this->syncSessionArtifacts($fresh, $adminId);

        $sse->complete([
            'success' => true,
            'message' => (string)__('所有页面生成完成'),
            'total_pages' => $totalPages,
        ]);
    }

    /**
     * 生成单个页面布局
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed>|null $sharedHeaderFooter
     * @return array{success:bool,message?:string,layout?:array<string,mixed>,theme_name?:string}
     */
    private function generateSinglePageLayout(
        AiSiteBuilderSession $session,
        int $adminId,
        string $pageType,
        array $scope,
        ?array $sharedHeaderFooter
    ): array {
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $title = \trim((string)($scope['site_title'] ?? ''));

        $layout = [
            'page_type' => $pageType,
            'regions' => [
                'header' => $sharedHeaderFooter['header'] ?? ['locked' => true, 'components' => []],
                'content' => ['locked' => false, 'components' => []],
                'footer' => $sharedHeaderFooter['footer'] ?? ['locked' => true, 'components' => []],
            ],
            'components' => [],
        ];

        // 如果是首页且没有共享 Header/Footer，生成它们
        if ($sharedHeaderFooter === null) {
            $layout['regions']['header'] = [
                'locked' => true,
                'components' => [
                    [
                        'code' => 'header/default',
                        'title' => (string)__('网站头部'),
                        'config' => ['site_title' => $title],
                    ],
                ],
            ];
            $layout['regions']['footer'] = [
                'locked' => true,
                'components' => [
                    [
                        'code' => 'footer/default',
                        'title' => (string)__('网站底部'),
                        'config' => ['site_title' => $title],
                    ],
                ],
            ];
        }

        // 生成内容区组件
        $layout['regions']['content']['components'] = [
            [
                'code' => "content/{$pageType}",
                'title' => $this->getPageTypeTitle($pageType),
                'description' => $brief,
                'config' => [],
            ],
        ];

        $themeName = 'ai-site-' . $session->getId();

        return [
            'success' => true,
            'layout' => $layout,
            'theme_name' => $themeName,
        ];
    }

    /**
     * 标记页面类型已生成
     */
    private function markPageTypeGenerated(int $sessionId, int $adminId, string $pageType): void
    {
        $session = $this->getSessionService()->loadById($sessionId, $adminId);
        if ($session === null) {
            return;
        }

        $scope = $session->getScopeArray();
        $generatedPageTypes = $scope['generated_page_types'] ?? [];
        if (!\is_array($generatedPageTypes)) {
            $generatedPageTypes = [];
        }

        $generatedPageTypes[$pageType] = [
            'generated_at' => $this->now(),
            'status' => 'completed',
        ];

        $this->getSessionService()->mergeScope($sessionId, $adminId, [
            'generated_page_types' => $generatedPageTypes,
        ]);
    }

    /**
     * 获取页面类型标题
     */
    private function getPageTypeTitle(string $pageType): string
    {
        $titles = [
            'home_page' => (string)__('首页'),
            'about_page' => (string)__('关于我们'),
            'contact_page' => (string)__('联系我们'),
            'privacy_policy' => (string)__('隐私政策'),
            'terms_of_service' => (string)__('服务条款'),
            'refund_policy' => (string)__('退款政策'),
            'shipping_policy' => (string)__('配送政策'),
            'cookie_policy' => (string)__('Cookie政策'),
            'blog_list' => (string)__('博客列表'),
            'blog_category' => (string)__('博客分类'),
            'blog_post' => (string)__('博客文章'),
            'custom_page' => (string)__('自定义页面'),
        ];

        return $titles[$pageType] ?? $pageType;
    }
}
