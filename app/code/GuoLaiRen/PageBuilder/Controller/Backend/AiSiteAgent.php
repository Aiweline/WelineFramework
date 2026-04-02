<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteDraftWebsiteService;
use GuoLaiRen\PageBuilder\Service\AiSitePublishService;
use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Sse\LastEventIdResolver;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\SchedulerSystem;

#[Acl('GuoLaiRen_PageBuilder::ai_site_agent', 'AI 建站工作台', 'mdi-robot-outline', 'PageBuilder AI 建站会话与流水线', 'Weline_Backend::page_builder_group')]
class AiSiteAgent extends BaseController
{
    private const PARAMS_PUBLIC_ID = ['public_id'];
    private const PARAMS_OPERATION_SSE = ['public_id', 'execution_token'];
    private const PARAMS_REGENERATE = ['public_id', 'page_type'];

    private readonly AiSiteAgentSessionService $sessionService;
    private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService;
    private readonly AiSiteProfileGenerationService $profileGenerationService;
    private readonly AiSiteDraftWebsiteService $draftWebsiteService;
    private readonly AiSiteVirtualThemeService $virtualThemeService;
    private readonly AiSitePublishService $publishService;
    private readonly AiSiteVisualUrlService $visualUrlService;
    private readonly Url $url;

    public function __construct(
        AiSiteAgentSessionService $sessionService,
        ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        ?AiSiteProfileGenerationService $profileGenerationService = null,
        ?AiSiteDraftWebsiteService $draftWebsiteService = null,
        ?AiSiteVirtualThemeService $virtualThemeService = null,
        ?AiSitePublishService $publishService = null,
        ?AiSiteVisualUrlService $visualUrlService = null,
        ?Url $url = null,
    ) {
        $this->sessionService = $sessionService;
        $this->scopeCompatibilityService = $scopeCompatibilityService
            ?? ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        $this->profileGenerationService = $profileGenerationService
            ?? ObjectManager::getInstance(AiSiteProfileGenerationService::class);
        $this->draftWebsiteService = $draftWebsiteService
            ?? ObjectManager::getInstance(AiSiteDraftWebsiteService::class);
        $this->virtualThemeService = $virtualThemeService
            ?? ObjectManager::getInstance(AiSiteVirtualThemeService::class);
        $this->publishService = $publishService
            ?? ObjectManager::getInstance(AiSitePublishService::class);
        $this->visualUrlService = $visualUrlService
            ?? ObjectManager::getInstance(AiSiteVisualUrlService::class);
        $this->url = $url ?? ObjectManager::getInstance(Url::class);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_index', 'AI 建站工作台', 'mdi-robot-outline', '进入 AI 建站工作台', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function index(): string
    {
        $legacy = ((int)$this->request->getGet('legacy', 0) === 1);
        $workbenchHomeUrl = $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']);
        if (!$legacy) {
            $this->redirect($workbenchHomeUrl);
            return '';
        }

        $adminId = (int)$this->getLoginUserId();
        $recent = $adminId > 0 ? $this->sessionService->listRecentSessionsForAdmin($adminId, 30) : [];

        $this->assign('title', __('PageBuilder AI 建站工作台'));
        $this->assign('recent_sessions', $recent);
        $this->assign('workbench_home_url', $workbenchHomeUrl);

        return $this->fetch();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'AI 建站会话', 'mdi-clipboard-text-outline', '查看与编辑 AI 建站会话', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function workspace(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            $this->assign('title', __('PageBuilder AI 建站工作台'));
            $this->assign('error_message', __('未登录或会话令牌无效'));
            return $this->fetch('workspace-error');
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->assign('title', __('PageBuilder AI 建站工作台'));
            $this->assign('error_message', __('会话不存在或无权访问'));
            return $this->fetch('workspace-error');
        }

        $state = $this->buildWorkspaceState($session, $adminId, 200, true);

        $this->assign('title', __('PageBuilder AI 建站工作台'));
        $this->assign('session', $session);
        $this->assign('workspace_state', $state);
        $this->assign('scope', $state['scope']);
        $this->assign('scope_preview', $this->encodePrettyJson($state['scope']));
        $this->assign('state_json_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/get-state-json', ['public_id' => $publicId]));
        $this->assign('merge_scope_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-merge-scope'));
        $this->assign('replace_scope_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-replace-scope'));
        $this->assign('set_stage_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-set-stage'));
        $this->assign('run_virtual_theme_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_build_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_regenerate_page_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-regenerate-page'));
        $this->assign('start_publish_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-publish'));
        $this->assign('operation_sse_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/operation-sse', ['public_id' => $publicId]));
        $this->assign('stream_sse_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/stream-sse', ['public_id' => $publicId]));
        $this->assign('switch_preview_page_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-switch-preview-page'));
        $this->assign('publish_check_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-publish-checklist'));
        $this->assign('back_url', $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']));
        $this->assign('stage_options', $this->getStageOptions());

        $expertUi = $this->isAiSiteAgentExpertUiRequested();
        $this->assign('guided_ui', !$expertUi);
        $this->assign(
            'workspace_expert_url',
            $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $publicId, 'expert' => '1'])
        );
        $this->assign(
            'workspace_guided_url',
            $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $publicId])
        );

        return $this->fetch();
    }

    private function isAiSiteAgentExpertUiRequested(): bool
    {
        $value = $this->request->getGet('expert', null);
        if ($value === null) {
            return false;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (int)$value === 1;
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '读取 AI 建站会话状态', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getStateJson(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        return $this->fetchJson([
            'success' => true,
            'data' => $this->buildWorkspaceState($session, $adminId, 80, true),
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '合并 scope', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postMergeScope(): string
    {
        return $this->mutateScope(true);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '替换 scope', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postReplaceScope(): string
    {
        return $this->mutateScope(false);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '更新阶段', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSetStage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $stage = $this->scopeCompatibilityService->normalizeStage((string)$this->getRequestBodyValue('stage', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        $allowed = \array_column($this->getStageOptions(), 'value');
        if (!\in_array($stage, $allowed, true)) {
            return $this->fetchJson(['success' => false, 'message' => __('无效的阶段')]);
        }

        $this->sessionService->setStage($session->getId(), $adminId, $stage);
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stage, 'stage_changed', (string)__('工作区阶段已切换'));
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson([
            'success' => true,
            'stage' => $stage,
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '启动主题构建', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartBuild(): string { return $this->handleStartBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '执行虚拟主题编排（兼容）', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRunVirtualTheme(): string { return $this->handleStartBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '启动单页重建', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartRegeneratePage(): string { return $this->handleStartRegeneratePage(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '启动发布流程', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPublish(): string { return $this->handleStartPublish(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '切换当前预览页', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSwitchPreviewPage(): string { return $this->handleSwitchPreviewPage(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '发布前检查', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postPublishChecklist(): string { return $this->handlePublishChecklist(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_stream', 'AI 建站事件流', 'mdi-access-point', '订阅 AI 建站会话事件流', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getStreamSse(): void { $this->handleStreamSse(); }

    /**
     * 测试 SSE 短轮询（无需认证）
     *
     * 用于验证 SSE 短轮询机制是否正常工作
     * 警告：仅用于测试，生产环境应删除此方法
     */
    public function getTestSse(): void
    {
        $sse = new SseWriter();
        $sse->start();

        $sse->sendEvent('start', ['message' => 'Test SSE connection started']);
        $sse->sendEvent('test', ['timestamp' => time(), 'message' => 'This is a test event']);

        // 短轮询：只轮询 3 次（3 秒）
        $maxPolls = 3;
        $pollInterval = 1000;  // 1 秒

        for ($i = 0; $i < $maxPolls; $i++) {
            if (!$sse->isAlive()) {
                break;
            }

            $sse->sendEvent('poll', ['count' => $i + 1, 'timestamp' => time()]);

            if ($i < $maxPolls - 1) {
                SchedulerSystem::yieldDelay($pollInterval);
            }
        }

        $sse->complete(['success' => true, 'message' => 'Test complete, please reconnect']);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_stream', 'AI 建站操作 SSE', 'mdi-access-point-network', '执行构建/重建/发布操作流', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getOperationSse(): void { $this->handleOperationSse(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_create', '创建 AI 建站会话', 'mdi-plus', '创建新的 AI 建站会话', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postCreateSession(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('未登录')]);
        }

        try {
            $session = $this->sessionService->createSession($adminId, [
                'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING,
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }

        return $this->fetchJson([
            'success' => true,
            'public_id' => $session->getPublicId(),
            'workspace_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $session->getPublicId()]),
        ]);
    }

    private function handleStartBuild(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_PUBLIC_ID);
        }
        $error = '';
        $scopePatch = $this->getRequestJsonObject('scope_patch', $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            'build',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            $scopePatch,
            '',
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
        ));
    }

    private function handleStartRegeneratePage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        if ($adminId <= 0 || $publicId === '' || $pageType === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_REGENERATE);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_PUBLIC_ID);
        }
        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            'regenerate_page',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            [],
            $pageType,
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
        ));
    }

    private function handleStartPublish(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_PUBLIC_ID);
        }
        $state = $this->buildWorkspaceState($session, $adminId, 40, true);
        if (empty($state['can_publish'])) {
            return $this->fetchJson(['success' => false, 'message' => __('当前工作区尚未准备好发布')]);
        }
        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            'publish',
            AiSiteAgentSession::STAGE_PUBLISH,
            [],
            '',
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING
        ));
    }

    private function handleSwitchPreviewPage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $requestedPageId = (int)$this->getRequestBodyValue('preview_page_id', 0);
        $requestedPageType = \trim((string)$this->getRequestBodyValue('preview_page_type', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_PUBLIC_ID);
        }

        $state = $this->buildWorkspaceState($session, $adminId, 40, true);
        $pagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType(
            $state['scope']['pagebuilder_pages_by_type'] ?? []
        );
        if ($requestedPageType === '' && $requestedPageId > 0) {
            foreach ($pagesByType as $pageType => $pageData) {
                if ((int)($pageData['page_id'] ?? 0) === $requestedPageId) {
                    $requestedPageType = $pageType;
                    break;
                }
            }
        }

        $virtualPages = $this->scopeCompatibilityService->normalizeVirtualPagesByType(
            $state['scope']['virtual_pages_by_type'] ?? [],
            $this->scopeCompatibilityService->normalizePageTypes($state['scope']['page_types'] ?? [])
        );
        $previewPageType = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, $requestedPageType);
        if ($previewPageType === '') {
            return $this->fetchJson(['success' => false, 'message' => __('当前还没有可切换的预览页')]);
        }

        $this->sessionService->mergeScope($session->getId(), $adminId, [
            'preview_page_type' => $previewPageType,
            'preview_page_id' => (int)($pagesByType[$previewPageType]['page_id'] ?? 0),
        ]);
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $state['stage'],
            'preview_page_switched',
            (string)__('预览页面已切换'),
            ['page_type' => $previewPageType]
        );
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson(['success' => true, 'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true)]);
    }

    private function handlePublishChecklist(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_PUBLIC_ID);
        }

        $state = $this->buildWorkspaceState($session, $adminId, 40, true);
        $scope = $state['scope'];
        $virtualPages = \is_array($state['virtual_pages_by_type']) ? $state['virtual_pages_by_type'] : [];
        $pageTypes = $this->scopeCompatibilityService->normalizePageTypes($scope['page_types'] ?? []);
        $checkItems = [
            ['key' => 'draft_website', 'label' => __('草稿站点已创建'), 'ok' => (int)$state['draft_website_id'] > 0, 'value' => (int)$state['draft_website_id']],
            ['key' => 'virtual_theme', 'label' => __('虚拟主题已生成'), 'ok' => (int)$state['virtual_theme_id'] > 0, 'value' => (int)$state['virtual_theme_id']],
            ['key' => 'website_profile', 'label' => __('网站级资料已齐备'), 'ok' => \trim((string)($state['website_profile']['site_title'] ?? '')) !== '', 'value' => $state['website_profile']],
            ['key' => 'virtual_pages', 'label' => __('虚拟页面已生成'), 'ok' => \count($virtualPages) >= \count($pageTypes), 'value' => \array_keys($virtualPages)],
            ['key' => 'visual_editor', 'label' => __('虚拟可视化编辑地址已就绪'), 'ok' => \trim((string)$state['visual_edit_url']) !== '' && \trim((string)$state['visual_preview_url']) !== '', 'value' => ['visual_preview_url' => $state['visual_preview_url'], 'visual_edit_url' => $state['visual_edit_url']]],
        ];
        $passed = true;
        foreach ($checkItems as $item) {
            if (empty($item['ok'])) {
                $passed = false;
                break;
            }
        }
        $payload = [
            'passed' => $passed,
            'items' => $checkItems,
            'stage' => $state['stage'],
            'workspace_status' => $state['workspace_status'],
            'publish_status' => $state['publish_status'],
        ];
        $this->appendWorkspaceEvent($session->getId(), $adminId, $state['stage'], 'publish_check', $passed ? (string)__('发布检查已通过') : (string)__('发布检查尚未通过'), ['details' => $payload]);

        return $this->fetchJson(['success' => true, 'data' => $payload]);
    }

    private function handleStreamSse(): void
    {
        // 先验证认证和参数，避免启动 SSE 后再发现认证失败
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $lastEventId = LastEventIdResolver::resolve($this->request, 'last_event_id');

        // 认证失败：返回 HTTP 401，不启动 SSE
        if ($adminId <= 0) {
            $response = $this->request->getResponse();
            $response->setHttpResponseCode(401);
            $response->setHeader('Content-Type', 'application/json');
            $response->setBody(\json_encode([
                'error' => 'UNAUTHORIZED',
                'message' => (string)__('未登录或登录已过期'),
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // 参数无效：返回 HTTP 400，不启动 SSE
        if ($publicId === '') {
            $response = $this->request->getResponse();
            $response->setHttpResponseCode(400);
            $response->setHeader('Content-Type', 'application/json');
            $response->setBody(\json_encode([
                'error' => 'INVALID_PARAMS',
                'message' => (string)__('参数无效'),
                'param' => self::PARAMS_PUBLIC_ID,
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // 会话不存在：返回 HTTP 404，不启动 SSE
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $response = $this->request->getResponse();
            $response->setHttpResponseCode(404);
            $response->setHeader('Content-Type', 'application/json');
            $response->setBody(\json_encode([
                'error' => 'SESSION_NOT_FOUND',
                'message' => (string)__('会话不存在或无权访问'),
                'param' => self::PARAMS_PUBLIC_ID,
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // 验证通过，启动 SSE
        $sse = new SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => __('已连接 PageBuilder 工作区事件流')]);
        $sse->sendEvent('snapshot', $this->buildWorkspaceState($session, $adminId, 40, true));

        // 关键优化：短轮询模式，立即返回，不占用 Worker
        // 客户端应该使用短轮询（每 2-3 秒重连一次）而不是长连接
        // 这样可以避免 SSE 连接长时间占用 Worker

        // 只轮询 3 次（约 3 秒），然后立即断开，让客户端重连
        $maxPolls = 3;
        $pollInterval = 1000;  // 1 秒

        for ($i = 0; $i < $maxPolls; $i++) {
            // 检查连接是否还活着
            if (!$sse->isAlive()) {
                break;
            }

            $newEvents = $this->sessionService->listEventsAfterId($session->getId(), $adminId, $lastEventId, 80);

            if (!empty($newEvents)) {
                foreach ($newEvents as $event) {
                    $eventId = (int)($event['event_id'] ?? 0);
                    if ($eventId > $lastEventId) {
                        $lastEventId = $eventId;
                    }
                    $sse->sendEvent('log', $event);
                }
            }

            // 最后一次轮询后不需要等待
            if ($i < $maxPolls - 1) {
                SchedulerSystem::yieldDelay($pollInterval);
            }
        }

        // 立即断开连接，让客户端重连
        // 这样 Worker 最多只被占用 3 秒，而不是 60 秒
        $sse->complete(['success' => true, 'message' => __('请重新连接继续监听'), 'last_event_id' => $lastEventId]);
    }

    private function handleOperationSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);
        $sse = new SseWriter();
        $sse->start();

        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $executionToken = \trim((string)$this->request->getGet('execution_token', ''));
        if ($adminId <= 0 || $publicId === '' || $executionToken === '') {
            $this->sendSseContractError($sse, 'INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_OPERATION_SSE);
            $sse->complete(['success' => false]);
            return;
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->sendSseContractError($sse, 'SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_OPERATION_SSE, 404);
            $sse->complete(['success' => false]);
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        if ($operation === '' || (string)($activeOperation['execution_token'] ?? '') !== $executionToken) {
            $this->sendSseContractError($sse, 'OPERATION_NOT_FOUND', (string)__('未找到待执行的工作区操作'), self::PARAMS_OPERATION_SSE, 404);
            $sse->complete(['success' => false]);
            return;
        }

        $stageCode = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $this->updateActiveOperation($session, $adminId, ['status' => 'running', 'message' => (string)__('操作开始执行')]);
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stageCode, 'operation_started', (string)__('已开始执行操作'), ['operation' => $operation, 'page_type' => (string)($activeOperation['page_type'] ?? '')]);
        $sse->sendEvent('start', ['message' => __('已开始执行：%{operation}', ['operation' => $operation]), 'operation' => $operation]);

        try {
            $result = match ($operation) {
                'build' => $this->runBuildOperation($sse, $session, $adminId),
                'regenerate_page' => $this->runRegeneratePageOperation($sse, $session, $adminId, (string)($activeOperation['page_type'] ?? '')),
                'publish' => $this->runPublishOperation($sse, $session, $adminId),
                default => throw new \RuntimeException((string)__('未知操作')),
            };
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $sse->complete(['success' => true, 'message' => (string)($result['message'] ?? __('操作执行完成')), 'operation' => $operation, 'data' => $result, 'state' => $this->buildWorkspaceState($fresh, $adminId, 80, true)]);
        } catch (\Throwable $throwable) {
            $failedStatus = $operation === 'publish' ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $this->updateActiveOperation($session, $adminId, ['status' => 'error', 'message' => $throwable->getMessage()], $failedStatus, $operation === 'publish' ? AiSiteAgentSession::PUBLISH_STATUS_FAILED : null);
            $this->appendWorkspaceEvent($session->getId(), $adminId, $stageCode, 'operation_failed', (string)__('操作执行失败：%{message}', ['message' => $throwable->getMessage()]), ['operation' => $operation, 'page_type' => (string)($activeOperation['page_type'] ?? ''), 'details' => ['exception' => $throwable->getMessage()]], AiSiteAgentSessionEvent::LEVEL_ERROR);
            $sse->sendError($throwable->getMessage());
            $sse->complete(['success' => false, 'message' => $throwable->getMessage(), 'operation' => $operation]);
        }
    }

    /**
     * @return array{
     *   public_id:string,
     *   stage:string,
     *   stage_label:string,
     *   workspace_status:string,
     *   publish_status:string,
     *   can_publish:bool,
     *   website_id:int,
     *   virtual_theme_id:int,
     *   website_profile:array<string, mixed>,
     *   draft_website_id:int,
     *   pagebuilder_pages_by_type:array<string, array<string, mixed>>,
     *   virtual_pages_by_type:array<string, array<string, mixed>>,
     *   preview_page_options:list<array<string, mixed>>,
     *   preview_page_id:int,
     *   preview_page_type:string,
     *   preview_full_url:string,
     *   visual_preview_url:string,
     *   visual_edit_url:string,
     *   pre_publish_visual_urls:array<string, string>,
     *   active_operation:array<string, mixed>,
     *   build_summary:array<string, mixed>,
     *   top_logs:list<array<string, mixed>>,
     *   scope:array<string, mixed>,
     *   events:list<array<string, mixed>>
     * }
     */
    private function buildWorkspaceState(
        AiSiteAgentSession $session,
        int $adminId,
        int $eventLimit = 120,
        bool $persist = false
    ): array {
        $normalized = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $normalized['website_profile'] = $this->profileGenerationService->generate($normalized);
        $normalized['page_types'] = $this->scopeCompatibilityService->normalizePageTypes($normalized['page_types'] ?? []);
        $normalized['page_type_layouts'] = $this->scopeCompatibilityService->normalizePageTypeLayouts(
            $normalized['page_type_layouts'] ?? [],
            $normalized['page_types']
        );

        $draftWebsiteId = \max(
            (int)($normalized['draft_website_id'] ?? 0),
            (int)($normalized['website_id'] ?? 0),
            (int)$session->getWebsiteId()
        );
        if ($draftWebsiteId > 0) {
            $normalized['draft_website_id'] = $draftWebsiteId;
            $normalized['website_id'] = $draftWebsiteId;
            $normalized['selected_website_id'] = $draftWebsiteId;
        }

        $virtualThemeId = \max(
            (int)($normalized['virtual_theme_id'] ?? 0),
            (int)$session->getVirtualThemeId()
        );
        if ($virtualThemeId > 0) {
            $normalized['virtual_theme_id'] = $virtualThemeId;
        }

        $pagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType(
            $normalized['pagebuilder_pages_by_type'] ?? []
        );
        $normalized['pagebuilder_pages_by_type'] = $pagesByType;
        $normalized['preview_page_options'] = $this->scopeCompatibilityService->buildPreviewPageOptions($pagesByType);

        $virtualPagesByType = $this->scopeCompatibilityService->buildVirtualPagesByType($normalized['page_types'], $normalized);
        $virtualPagesByType = $this->decorateVirtualPagesWithUrls($session->getPublicId(), $virtualThemeId, $virtualPagesByType);
        $normalized['virtual_pages_by_type'] = $virtualPagesByType;

        $previewPageType = $this->scopeCompatibilityService->resolvePreviewPageType(
            $virtualPagesByType,
            (string)($normalized['preview_page_type'] ?? '')
        );
        $normalized['preview_page_type'] = $previewPageType;
        $normalized['preview_page_id'] = (int)($pagesByType[$previewPageType]['page_id'] ?? 0);

        $prePublishVisualUrls = $previewPageType !== ''
            ? $this->visualUrlService->resolveVirtualUrls($session->getPublicId(), $previewPageType, $virtualThemeId)
            : ['preview_full_url' => '', 'visual_preview_url' => '', 'visual_edit_url' => ''];
        $normalized['pre_publish_visual_urls'] = $prePublishVisualUrls;

        if ((int)$normalized['preview_page_id'] > 0 && $session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED) {
            $normalized = \array_replace(
                $normalized,
                $this->visualUrlService->resolveUrls((int)$normalized['preview_page_id'], $virtualThemeId)
            );
        } else {
            $normalized = \array_replace($normalized, $prePublishVisualUrls);
        }

        $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $activeOperation = \is_array($normalized['active_operation'] ?? null) ? $normalized['active_operation'] : [];
        $canPublish = $virtualThemeId > 0
            && \count($virtualPagesByType) >= \count($normalized['page_types'])
            && \trim((string)($normalized['website_profile']['site_title'] ?? '')) !== '';
        $activeOperationStatus = \trim((string)($activeOperation['status'] ?? ''));
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        if (
            \in_array($activeOperationStatus, ['queued', 'running'], true)
            && (int)($activeOperation['progress_percent'] ?? 0) >= 100
            && (
                $session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED
                || (
                    $canPublish
                    && \in_array($activeOperationName, ['build', 'regenerate_page'], true)
                )
            )
        ) {
            $activeOperation['status'] = 'done';
            $normalized['active_operation'] = $activeOperation;
        }
        $workspaceStatus = $this->resolveWorkspaceStatus($session, $normalized, $canPublish);
        $normalized['workspace_status'] = $workspaceStatus;
        $normalized['can_publish'] = $canPublish ? 1 : 0;
        $normalized['build_summary'] = $this->buildSummary($virtualPagesByType, $activeOperation, $canPublish);

        $events = $this->sessionService->listRecentEvents($session->getId(), $adminId, $eventLimit);
        $topLogs = \array_slice($events, -12);

        if ($persist) {
            $this->persistNormalizedState($session, $adminId, $normalized, $stage, $draftWebsiteId, $virtualThemeId);
        }

        return [
            'public_id' => $session->getPublicId(),
            'stage' => $stage,
            'stage_label' => $this->getStageLabel($stage),
            'workspace_status' => $workspaceStatus,
            'publish_status' => $session->getPublishStatus(),
            'can_publish' => $canPublish,
            'website_id' => $draftWebsiteId,
            'virtual_theme_id' => $virtualThemeId,
            'website_profile' => \is_array($normalized['website_profile']) ? $normalized['website_profile'] : [],
            'draft_website_id' => $draftWebsiteId,
            'pagebuilder_pages_by_type' => $pagesByType,
            'virtual_pages_by_type' => $virtualPagesByType,
            'preview_page_options' => \is_array($normalized['preview_page_options']) ? $normalized['preview_page_options'] : [],
            'preview_page_id' => (int)$normalized['preview_page_id'],
            'preview_page_type' => (string)$normalized['preview_page_type'],
            'preview_full_url' => (string)($normalized['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($normalized['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($normalized['visual_edit_url'] ?? ''),
            'pre_publish_visual_urls' => \is_array($normalized['pre_publish_visual_urls'] ?? null) ? $normalized['pre_publish_visual_urls'] : $prePublishVisualUrls,
            'active_operation' => $activeOperation,
            'build_summary' => \is_array($normalized['build_summary'] ?? null) ? $normalized['build_summary'] : [],
            'top_logs' => $topLogs,
            'scope' => $normalized,
            'events' => $events,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @return array<string, array<string, mixed>>
     */
    private function decorateVirtualPagesWithUrls(string $publicId, int $virtualThemeId, array $virtualPagesByType): array
    {
        foreach ($virtualPagesByType as $pageType => $pageData) {
            if (!\is_string($pageType)) {
                continue;
            }
            $virtualPagesByType[$pageType] = \array_replace(
                $pageData,
                $this->visualUrlService->resolveVirtualUrls($publicId, $pageType, $virtualThemeId)
            );
            $virtualPagesByType[$pageType]['virtual_preview_url'] = (string)($virtualPagesByType[$pageType]['visual_preview_url'] ?? '');
            $virtualPagesByType[$pageType]['virtual_edit_url'] = (string)($virtualPagesByType[$pageType]['visual_edit_url'] ?? '');
        }

        return $virtualPagesByType;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveWorkspaceStatus(AiSiteAgentSession $session, array $scope, bool $canPublish): string
    {
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $workspaceStatus = $this->scopeCompatibilityService->normalizeWorkspaceStatus((string)($scope['workspace_status'] ?? ''));

        if ($session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        }
        if ($session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHING || ($operation === 'publish' && \in_array($activeStatus, ['queued', 'running'], true))) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING;
        }
        if ($activeStatus === 'error' || $workspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        }
        if (\in_array($activeStatus, ['queued', 'running'], true) && \in_array($operation, ['build', 'regenerate_page'], true)) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        }
        if ($canPublish) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        }
        if ((int)($scope['virtual_theme_id'] ?? 0) > 0) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_EDITING;
        }

        return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @param array<string, mixed> $activeOperation
     * @return array<string, mixed>
     */
    private function buildSummary(array $virtualPagesByType, array $activeOperation, bool $canPublish): array
    {
        $generatedAt = '';
        foreach ($virtualPagesByType as $pageData) {
            $lastGeneratedAt = \trim((string)($pageData['last_generated_at'] ?? ''));
            if ($lastGeneratedAt !== '' && ($generatedAt === '' || $lastGeneratedAt > $generatedAt)) {
                $generatedAt = $lastGeneratedAt;
            }
        }

        return [
            'page_count' => \count($virtualPagesByType),
            'last_generated_at' => $generatedAt,
            'active_operation' => \trim((string)($activeOperation['operation'] ?? '')),
            'can_publish' => $canPublish,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function persistNormalizedState(
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        string $stage,
        int $draftWebsiteId,
        int $virtualThemeId
    ): void {
        if ($this->encodePrettyJson($session->getScopeArray()) !== $this->encodePrettyJson($scope)) {
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        }
        if ($draftWebsiteId > 0 && $session->getWebsiteId() !== $draftWebsiteId) {
            $this->sessionService->bindWebsite($session->getId(), $adminId, $draftWebsiteId);
        }
        if ($virtualThemeId > 0 && $session->getVirtualThemeId() !== $virtualThemeId) {
            $this->sessionService->bindVirtualTheme($session->getId(), $adminId, $virtualThemeId);
        }
        if ($session->getStage() !== $stage) {
            $this->sessionService->setStage($session->getId(), $adminId, $stage);
        }
    }

    /**
     * @param array<string, mixed> $scopePatch
     * @return array<string, mixed>
     */
    private function startOperation(
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $stage,
        array $scopePatch = [],
        string $pageType = '',
        string $workspaceStatus = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
    ): array {
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        if (\in_array($activeStatus, ['queued', 'running'], true)) {
            return ['success' => false, 'message' => __('当前已有正在执行的操作，请先等待完成')];
        }

        $scope = \array_replace($scope, $scopePatch);
        $scope['page_types'] = $this->scopeCompatibilityService->normalizePageTypes($scope['page_types'] ?? []);
        if ($pageType !== '' && !\in_array($pageType, $scope['page_types'], true)) {
            return ['success' => false, 'message' => __('所选页面类型不在当前工作区中')];
        }

        $executionToken = \bin2hex(\random_bytes(16));
        $scope['active_operation'] = [
            'operation' => $operation,
            'execution_token' => $executionToken,
            'status' => 'queued',
            'page_type' => $pageType,
            'started_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
            'message' => (string)__('等待开始'),
        ];
        $scope['workspace_status'] = $workspaceStatus;

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->setStage($session->getId(), $adminId, $stage);
        if ($operation === 'publish') {
            $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHING);
        }
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stage, 'operation_queued', (string)__('已加入操作队列'), ['operation' => $operation, 'page_type' => $pageType]);

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        return [
            'success' => true,
            'message' => __('操作已启动'),
            'execution_token' => $executionToken,
            'operation' => $operation,
            'stream_url' => $this->buildOperationStreamUrl($fresh->getPublicId(), $executionToken),
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runBuildOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): array
    {
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->normalizePageTypes($scope['page_types'] ?? []);
        $pageTypeLabels = Page::getPageTypes();
        $totalSteps = \count($pageTypes) + 2; // 网站资料 + 虚拟主题 + N个页面
        $currentStep = 0;

        // 步骤 1: 准备网站资料
        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('正在准备网站资料'), $progressPercent);
        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];

        // 步骤 2: 生成虚拟主题骨架
        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('正在生成虚拟主题骨架'), $progressPercent);

        // 初始化空的 page_type_layouts，稍后逐个生成
        $pageTypeLayouts = [];
        $virtualPages = [];
        $now = \date('Y-m-d H:i:s');

        // 步骤 3-N: 逐个生成每个页面类型
        foreach ($pageTypes as $pageType) {
            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);
            $pageLabel = (string)($pageTypeLabels[$pageType] ?? $pageType);

            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'build',
                __('正在生成页面：%{page}', ['page' => $pageLabel]),
                $progressPercent,
                $pageType
            );

            // 为当前页面类型生成布局
            $pageTypeLayouts[$pageType] = $this->scopeCompatibilityService->normalizeLayoutConfig([], $pageType);

            // 更新虚拟主题（包含当前页面类型）
            $theme = $this->virtualThemeService->ensureVirtualTheme(
                $scope,
                $scope['website_profile'],
                [$pageType], // 只传入当前页面类型
                [$pageType => $pageTypeLayouts[$pageType]],
                $session->getId()
            );
            $scope['virtual_theme_id'] = (int)$theme['virtual_theme_id'];
            $scope['page_type_layouts'] = \array_replace($scope['page_type_layouts'] ?? [], $theme['page_type_layouts']);

            // 构建当前页面的虚拟页面数据
            $currentVirtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType([$pageType], $scope);
            if (isset($currentVirtualPages[$pageType])) {
                $currentVirtualPages[$pageType]['last_generated_at'] = $now;
                if (\trim((string)($currentVirtualPages[$pageType]['ai_description'] ?? '')) === '') {
                    $currentVirtualPages[$pageType]['ai_description'] = (string)($scope['website_profile']['brief_description'] ?? '');
                }
                $virtualPages[$pageType] = $currentVirtualPages[$pageType];
            }

            // 实时更新 scope，让前端可以立即看到新生成的页面
            $scope['virtual_pages_by_type'] = $virtualPages;
            $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? ''));
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)$theme['virtual_theme_id']);

            // 发送页面生成完成事件
            $sse->sendEvent('page_generated', [
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'virtual_theme_id' => (int)$theme['virtual_theme_id'],
                'progress_percent' => $progressPercent,
            ]);
        }

        // 最终步骤：完成构建
        $scope['build_summary'] = ['page_count' => \count($virtualPages), 'last_generated_at' => $now, 'active_operation' => 'build', 'can_publish' => true];
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => $now, 'message' => (string)__('主题构建完成')]);

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)($scope['virtual_theme_id'] ?? 0));
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('虚拟主题已生成，可继续进入页面编辑'), 100);
        return ['message' => (string)__('主题构建完成'), 'draft_website_id' => (int)$draftWebsite['website_id'], 'virtual_theme_id' => (int)($scope['virtual_theme_id'] ?? 0), 'page_types' => $pageTypes];
    }

    /**
     * @return array<string, mixed>
     */
    private function runRegeneratePageOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId, string $pageType): array
    {
        if ($pageType === '') {
            throw new \RuntimeException((string)__('缺少要重建的页面类型'));
        }
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $pageTypes = $this->scopeCompatibilityService->normalizePageTypes($scope['page_types'] ?? []);
        if (!\in_array($pageType, $pageTypes, true)) {
            throw new \RuntimeException((string)__('页面类型不在当前工作区中'));
        }
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('正在重建页面：%{page}', ['page' => (string)(Page::getPageTypes()[$pageType] ?? $pageType)]), 20, $pageType);
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $pageTypeLayouts[$pageType] = $this->scopeCompatibilityService->normalizeLayoutConfig([], $pageType);

        $theme = $this->virtualThemeService->ensureVirtualTheme($scope, $scope['website_profile'], $pageTypes, $pageTypeLayouts, $session->getId());
        $scope['page_type_layouts'] = $theme['page_type_layouts'];
        $scope['virtual_theme_id'] = (int)$theme['virtual_theme_id'];

        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $virtualPages[$pageType]['last_generated_at'] = \date('Y-m-d H:i:s');
        $scope['virtual_pages_by_type'] = $virtualPages;
        $scope['preview_page_type'] = $pageType;
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => \date('Y-m-d H:i:s'), 'message' => (string)__('页面重建完成')]);

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)$theme['virtual_theme_id']);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('页面重建完成，可继续调整组件'), 100, $pageType);
        return ['message' => (string)__('页面重建完成'), 'page_type' => $pageType, 'virtual_theme_id' => (int)$theme['virtual_theme_id']];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPublishOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): array
    {
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $pageTypes = $this->scopeCompatibilityService->normalizePageTypes($scope['page_types'] ?? []);
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $websiteProfile = $this->profileGenerationService->generate($scope);
        $websiteId = \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId());
        $virtualThemeId = \max((int)($scope['virtual_theme_id'] ?? 0), (int)$session->getVirtualThemeId());
        if ($websiteId <= 0 || $virtualThemeId <= 0) {
            throw new \RuntimeException((string)__('发布前请先完成主题构建'));
        }

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', __('正在创建正式页面并发布上线'), 25);
        $published = $this->publishService->publish($websiteId, $virtualThemeId, $websiteProfile, $pageTypes, $pageTypeLayouts);

        $scope['pagebuilder_pages_by_type'] = $published['pagebuilder_pages_by_type'] ?? [];
        $scope['materialized_pages_by_type'] = $published['materialized_pages_by_type'] ?? [];
        $scope['preview_page_id'] = (int)($published['preview_page_id'] ?? 0);
        $scope['preview_page_type'] = (string)($published['preview_page_type'] ?? ($scope['preview_page_type'] ?? ''));
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => \date('Y-m-d H:i:s'), 'message' => (string)__('发布完成')]);

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_PUBLISH);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', __('正式页面已创建并上线'), 100);
        return ['message' => (string)__('发布完成'), 'redirect_url' => $this->url->getBackendUrl('pagebuilder/backend/page/index'), 'published' => $published];
    }

    private function sendOperationProgress(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $stageCode,
        string $operation,
        string $message,
        int $progressPercent,
        string $pageType = '',
        string $operationStatus = 'running',
        ?string $workspaceStatus = null,
        ?string $publishStatus = null
    ): void {
        if ($operationStatus === 'running' && $progressPercent >= 100) {
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
            $resolvedWorkspaceStatus = $this->scopeCompatibilityService->normalizeWorkspaceStatus((string)($scope['workspace_status'] ?? ''));

            if (
                \in_array($operation, ['build', 'regenerate_page'], true)
                && $resolvedWorkspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            ) {
                $operationStatus = 'done';
                $workspaceStatus ??= AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            }

            if (
                $operation === 'publish'
                && (
                    $fresh->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED
                    || $resolvedWorkspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED
                )
            ) {
                $operationStatus = 'done';
                $workspaceStatus ??= AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
                $publishStatus ??= AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED;
            }
        }

        $payload = [
            'message' => $message,
            'operation' => $operation,
            'page_type' => $pageType,
            'progress_percent' => $progressPercent,
            'details' => [],
        ];
        $sse->sendEvent('progress', $payload);
        $this->updateActiveOperation(
            $session,
            $adminId,
            ['status' => $operationStatus, 'message' => $message, 'progress_percent' => $progressPercent, 'page_type' => $pageType],
            $workspaceStatus,
            $publishStatus
        );
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stageCode, 'operation_progress', $message, $payload);
    }

    /**
     * @param array<string, mixed> $patch
     */
    private function updateActiveOperation(
        AiSiteAgentSession $session,
        int $adminId,
        array $patch,
        ?string $workspaceStatus = null,
        ?string $publishStatus = null
    ): void {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $scope['active_operation'] = \array_replace($activeOperation, $patch, ['updated_at' => \date('Y-m-d H:i:s')]);
        if ($workspaceStatus !== null) {
            $scope['workspace_status'] = $workspaceStatus;
        }
        $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);
        if ($publishStatus !== null) {
            $this->sessionService->setPublishStatus($fresh->getId(), $adminId, $publishStatus);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendWorkspaceEvent(
        int $sessionId,
        int $adminId,
        string $stageCode,
        string $eventType,
        string $message,
        array $payload = [],
        string $level = AiSiteAgentSessionEvent::LEVEL_INFO
    ): void {
        $details = \is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $eventPayload = \array_replace([
            'message' => $message,
            'operation' => (string)($payload['operation'] ?? ''),
            'page_type' => (string)($payload['page_type'] ?? ''),
            'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : null,
            'details' => $details,
        ], $payload);
        $this->sessionService->appendEvent($sessionId, $adminId, $eventType, $eventPayload, $stageCode, $level);
    }

    private function mutateScope(bool $merge): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_PUBLIC_ID);
        }
        $jsonKey = $merge ? 'scope_patch' : 'scope';
        $error = '';
        $payload = $this->getRequestJsonObject($jsonKey, $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }

        $saved = $merge ? $this->sessionService->mergeScope($session->getId(), $adminId, $payload) : $this->sessionService->replaceScope($session->getId(), $adminId, $payload);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => __('保存失败')]);
        }
        $this->appendWorkspaceEvent($session->getId(), $adminId, $this->scopeCompatibilityService->normalizeStage($session->getStage()), $merge ? 'scope_merged' : 'scope_replaced', $merge ? (string)__('工作区信息已合并保存') : (string)__('工作区信息已整体替换'), ['details' => ['keys' => \array_values(\array_map('strval', \array_keys($payload)))]]);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson(['success' => true, 'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true)]);
    }

    private function buildOperationStreamUrl(string $publicId, string $executionToken): string
    {
        return $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/operation-sse', ['public_id' => $publicId, 'execution_token' => $executionToken]);
    }

    /**
     * @param list<string> $requiredParams
     * @param array<string, mixed> $extra
     */
    private function jsonError(string $code, string $message, array $requiredParams = [], array $extra = []): string
    {
        return $this->fetchJson(\array_replace([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'required_params' => $requiredParams,
        ], $extra));
    }

    /**
     * @param list<string> $requiredParams
     */
    private function sendSseContractError(
        SseWriter $sse,
        string $code,
        string $message,
        array $requiredParams = [],
        int $httpCode = 400
    ): void {
        $sse->sendEvent('error', [
            'success' => false,
            'code' => $code,
            'message' => $message,
            'required_params' => $requiredParams,
            'http_code' => $httpCode,
        ]);
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
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            $error = (string)__('JSON 无效：%{1}', [$jsonException->getMessage()]);
            return [];
        }
        if (!\is_array($decoded)) {
            $error = (string)__('请求体必须是 JSON 对象');
            return [];
        }
        return $decoded;
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function getStageOptions(): array
    {
        return [
            ['value' => AiSiteAgentSession::STAGE_VIRTUAL_THEME, 'label' => (string)__('准备信息')],
            ['value' => AiSiteAgentSession::STAGE_VISUAL_EDIT, 'label' => (string)__('虚拟编辑')],
            ['value' => AiSiteAgentSession::STAGE_PUBLISH, 'label' => (string)__('确认发布')],
        ];
    }

    private function getStageLabel(string $stage): string
    {
        foreach ($this->getStageOptions() as $option) {
            if (($option['value'] ?? '') === $stage) {
                return (string)($option['label'] ?? $stage);
            }
        }
        return $stage;
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

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePrettyJson(array $payload): string
    {
        try {
            return (string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '{}';
        }
    }
}
