<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteDraftWebsiteService;
use GuoLaiRen\PageBuilder\Service\AiSiteMaterializationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePublishService;
use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceDebugDefaults;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlocksBuildService;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteTaskPlanSseService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
use GuoLaiRen\PageBuilder\Service\QuickBuildAggregator;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use Weline\Framework\App\State;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\Sse\LastEventIdResolver;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\WlsLogger;
use Weline\Websites\Model\AiSiteBuilderSession as WebsitesAiSiteBuilderSession;
use Weline\Websites\Service\AiWorkbench\DomainPurchaseWorkbenchService as WebsitesDomainPurchaseWorkbenchService;
use Weline\Websites\Service\AiWorkbench\SessionService as WebsitesSessionService;

#[Acl('GuoLaiRen_PageBuilder::ai_site_agent', 'AI 建站工作台', 'mdi-robot-outline', 'PageBuilder AI 建站会话与流水线', 'Weline_Backend::page_builder_group')]
class AiSiteAgent extends BaseController
{
    private const REQUEST_CTX_AI_CHUNK_FORWARDER = 'pagebuilder.ai.chunk.forwarder';
    private const REQUEST_CTX_QUEUE_DISPATCHER = 'pagebuilder.ai.queue.dispatcher';
    private const REQUEST_CTX_QUEUE_CREATED_HOOK = 'pagebuilder.ai.queue.created';
    private const PARAMS_PUBLIC_ID = ['public_id'];
    private const PARAMS_OPERATION_SSE = ['public_id', 'execution_token'];
    private const PARAMS_REGENERATE = ['public_id', 'page_type'];
    private const PARAMS_REFINE_PLAN_PAGE = ['public_id', 'page_type', 'instruction'];
    private const PARAMS_REFINE_COMPONENT = ['public_id', 'page_type', 'component_code', 'instruction'];
    private const PARAMS_UPDATE_BLOCK = ['public_id', 'page_type', 'block_id', 'block_config'];
    private const PARAMS_MUTATE_PLAN_BLOCK = ['public_id', 'page_type', 'action', 'block_key', 'block_config'];
    private const WORKSPACE_STREAM_SNAPSHOT_PERSIST = false;
    private const STREAM_LEASE_SCOPE_KEY = '_workspace_stream_lease';
    private const STREAM_LEASE_TTL_SEC = 60;
    /**
     * 工作区 stream-sse 续约窗口：超过此时间未收到续约请求（POST post-touch-stream-lease）则视为断连，
     * 服务端将结束事件流并清理与本 lease 关联的排队/运行中操作。
     * 前端心跳间隔应显著小于本值（当前页面约 20s 一次 POST 续约）。
     * 
     * 注意：此值需要与 observeDuplicateOperationStream 中的 $maxIdleLoops 协调，
     * 确保长时间运行的 AI 生成任务（如大型页面构建）不会被误判为过时。
     * 当前设置为 600 秒（10分钟），以支持复杂的 AI 内容生成场景。
     */
    private const STALE_ACTIVE_OPERATION_TTL_SEC = 600;

    private readonly AiSiteAgentSessionService $sessionService;
    private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService;
    private readonly AiSiteProfileGenerationService $profileGenerationService;
    private readonly AiSiteBuildTaskService $buildTaskService;
    private readonly AiSiteDraftWebsiteService $draftWebsiteService;
    private readonly AiSiteMaterializationService $materializationService;
    private readonly AiSiteVirtualThemeService $virtualThemeService;
    private readonly AiSitePublishService $publishService;
    private readonly AiSiteVisualUrlService $visualUrlService;
    private readonly AiSiteHtmlBlocksBuildService $htmlBlocksBuildService;
    private readonly AiSitePageBlueprintService $pageBlueprintService;
    private readonly AiSiteExecutionBlueprintService $executionBlueprintService;
    private readonly AiSiteVirtualThemePlanService $virtualThemePlanService;
    private readonly AiSiteTaskPlanSseService $taskPlanSseService;
    private readonly Url $url;

    public function __construct(
        AiSiteAgentSessionService $sessionService,
        ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        ?AiSiteProfileGenerationService $profileGenerationService = null,
        ?AiSiteBuildTaskService $buildTaskService = null,
        ?AiSiteDraftWebsiteService $draftWebsiteService = null,
        ?AiSiteMaterializationService $materializationService = null,
        ?AiSiteVirtualThemeService $virtualThemeService = null,
        ?AiSitePublishService $publishService = null,
        ?AiSiteVisualUrlService $visualUrlService = null,
        ?AiSiteHtmlBlocksBuildService $htmlBlocksBuildService = null,
        ?AiSitePageBlueprintService $pageBlueprintService = null,
        ?AiSiteExecutionBlueprintService $executionBlueprintService = null,
        ?AiSiteVirtualThemePlanService $virtualThemePlanService = null,
        ?AiSiteTaskPlanSseService $taskPlanSseService = null,
        ?Url $url = null,
    ) {
        $this->sessionService = $sessionService;
        $this->scopeCompatibilityService = $scopeCompatibilityService
            ?? ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        $this->profileGenerationService = $profileGenerationService
            ?? ObjectManager::getInstance(AiSiteProfileGenerationService::class);
        $this->buildTaskService = $buildTaskService
            ?? ObjectManager::getInstance(AiSiteBuildTaskService::class);
        $this->draftWebsiteService = $draftWebsiteService
            ?? ObjectManager::getInstance(AiSiteDraftWebsiteService::class);
        $this->materializationService = $materializationService
            ?? ObjectManager::getInstance(AiSiteMaterializationService::class);
        $this->virtualThemeService = $virtualThemeService
            ?? ObjectManager::getInstance(AiSiteVirtualThemeService::class);
        $this->publishService = $publishService
            ?? ObjectManager::getInstance(AiSitePublishService::class);
        $this->visualUrlService = $visualUrlService
            ?? ObjectManager::getInstance(AiSiteVisualUrlService::class);
        $this->htmlBlocksBuildService = $htmlBlocksBuildService
            ?? ObjectManager::getInstance(AiSiteHtmlBlocksBuildService::class);
        $this->pageBlueprintService = $pageBlueprintService
            ?? ObjectManager::getInstance(AiSitePageBlueprintService::class);
        $this->executionBlueprintService = $executionBlueprintService
            ?? ObjectManager::getInstance(AiSiteExecutionBlueprintService::class);
        $this->virtualThemePlanService = $virtualThemePlanService
            ?? ObjectManager::getInstance(AiSiteVirtualThemePlanService::class);
        $this->taskPlanSseService = $taskPlanSseService
            ?? ObjectManager::getInstance(AiSiteTaskPlanSseService::class);
        $this->url = $url ?? ObjectManager::getInstance(Url::class);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_index', 'AI 建站工作台', 'mdi-robot-outline', '进入 AI 建站工作台', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function index(): string
    {
        $startedAt = \microtime(true);
        $workbenchHomeUrl = $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']);

        $adminId = (int)$this->getLoginUserId();
        $recent = $adminId > 0 ? $this->sessionService->listRecentSessionsForAdmin($adminId, 30) : [];

        $this->assign('title', __('PageBuilder AI 建站工作台'));
        $this->assign('recent_sessions', $recent);
        $this->assign('workbench_home_url', $workbenchHomeUrl);

        $html = $this->fetch();
        $this->logHotPathStage('index.total', $startedAt, [
            'recent_count' => \count($recent),
            'response_bytes' => \strlen($html),
        ]);

        return $html;
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'AI 建站会话', 'mdi-clipboard-text-outline', '查看与编辑 AI 建站会话', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function workspace(): string
    {
        $startedAt = \microtime(true);
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

        $linkedWebsitesSession = $this->ensureLinkedWebsitesMirrorSession($session, $adminId);
        if ($linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession) {
            $this->syncPageBuilderScopeFromLinkedWebsitesSession($session, $linkedWebsitesSession, $adminId);
        }
        $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $buildStateStartedAt = \microtime(true);
        // Read-only page rendering should not persist normalized state back to DB.
        // Otherwise a simple workspace refresh can race with SSE/autosave/build writes
        // and occasionally turn a GET into an empty response when persistence fails.
        $state = $this->buildWorkspaceState($session, $adminId, 12, false);
        $this->logHotPathStage('workspace.build_state', $buildStateStartedAt, [
            'public_id' => $publicId,
            'stage' => $session->getStage(),
        ]);
        $expertUi = false;
        $viewState = $this->pruneWorkspaceStateForView($state);
        $linkedWebsitesScope = $linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession
            ? $linkedWebsitesSession->getScopeArray()
            : [];
        $domainPurchaseState = $linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession
            ? $this->getWebsitesDomainPurchaseWorkbenchService()->buildViewState($linkedWebsitesSession)
            : [];
        $registrarAccounts = $this->buildRegistrarAccountOptions();
        $recommendedDomainList = \is_array($linkedWebsitesScope['recommended_domain_list'] ?? null)
            ? $linkedWebsitesScope['recommended_domain_list']
            : (\is_array($viewState['scope']['recommended_domain_list'] ?? null) ? $viewState['scope']['recommended_domain_list'] : []);
        $recommendedRegistrarLabel = \trim((string)($linkedWebsitesScope['recommended_registrar_label'] ?? $viewState['scope']['recommended_registrar_label'] ?? ''));
        $preferredRegistrarAccountId = (int)($linkedWebsitesScope['preferred_registrar_account_id'] ?? $linkedWebsitesScope['registrar_account_id'] ?? $viewState['scope']['preferred_registrar_account_id'] ?? $viewState['scope']['registrar_account_id'] ?? 0);
        $isDevMode = \defined('DEV') && DEV;
        if ($isDevMode) {
            $localDemoAccountId = 900001;
            foreach ($registrarAccounts as $account) {
                if ((int)($account['account_id'] ?? 0) !== $localDemoAccountId) {
                    continue;
                }
                $preferredRegistrarAccountId = $localDemoAccountId;
                break;
            }
        } elseif ($preferredRegistrarAccountId <= 0) {
            // 线上默认不预选账号，由用户主动选择。
            $preferredRegistrarAccountId = 0;
        }
        if ($recommendedRegistrarLabel === '' && $preferredRegistrarAccountId > 0) {
            foreach ($registrarAccounts as $account) {
                if ((int)($account['account_id'] ?? 0) !== $preferredRegistrarAccountId) {
                    continue;
                }
                $recommendedRegistrarLabel = \trim((string)($account['label'] ?? ''));
                break;
            }
        }

        $this->assign('title', __('PageBuilder AI 建站工作台'));
        $this->assign('session', $session);
        $this->assign('workspace_state', $viewState);
        $this->assign('scope', $viewState['scope']);
        $this->assign('scope_preview', '{}');
        $this->assign('merge_scope_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-merge-scope'));
        $this->assign('replace_scope_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-replace-scope'));
        $this->assign('set_stage_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-set-stage'));
        $this->assign('start_plan_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-plan'));
        $this->assign('confirm_plan_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-confirm-plan'));
        $this->assign('sort_plan_blocks_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-sort-plan-blocks'));
        $this->assign('mutate_plan_block_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-mutate-plan-block'));
        $this->assign('refine_plan_page_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-refine-plan-page'));
        $this->assign('plan_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/plan-sse'));
        $this->assign('start_task_plan_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-task-plan'));
        $this->assign('confirm_task_plan_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-confirm-task-plan'));
        $this->assign('sort_task_plan_tasks_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-sort-task-plan-tasks'));
        $this->assign('task_plan_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-task-plan-sse'));
        $this->assign('resume_build_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-resume-build'));
        $this->assign('run_virtual_theme_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_build_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_regenerate_page_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-regenerate-page'));
        $this->assign('start_refine_component_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-refine-component'));
        $this->assign('start_block_refine_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-block-refine-sse'));
        $this->assign('start_block_regenerate_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-block-regenerate-sse'));
        $this->assign('update_block_config_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-update-block-config'));
        $this->assign('start_publish_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-publish'));
        $this->assign('operation_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/operation-sse', ['public_id' => $publicId]));
        $this->assign('switch_preview_page_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-switch-preview-page'));
        $this->assign('workspace_snapshot_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-workspace-snapshot'));
        $this->assign('publish_check_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-publish-checklist'));
        $this->assign('delete_workspace_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-delete-workspace'));
        $this->assign('recommend_domain_url', $this->url->getBackendUrlPath('websites/backend/site-builder-agent/recommend-domain'));
        $this->assign('check_domain_url', $this->url->getBackendUrlPath('websites/backend/site-builder-agent/check-domain'));
        $this->assign('start_domain_purchase_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-domain-purchase'));
        $this->assign('domain_purchase_sse_url', $this->url->getBackendUrlPath('websites/backend/site-builder-agent/domain-purchase-sse'));
        $this->assign('domain_purchase_state', $domainPurchaseState);
        $this->assign('recommended_domain_list', $recommendedDomainList);
        $this->assign('recommended_registrar_label', $recommendedRegistrarLabel);
        $this->assign('preferred_registrar_account_id', $preferredRegistrarAccountId);
        $this->assign('registrar_accounts', $registrarAccounts);
        $this->assign('linked_workbench_public_id', $linkedWebsitesSession?->getPublicId() ?? '');
        $this->assign('back_url', $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']));
        $this->assign('stage_options', $this->getStageOptions());

        $this->assign('guided_ui', true);
        $this->assign('workspace_expert_url', '');
        $this->assign(
            'workspace_guided_url',
            $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $publicId])
        );

        /** @var SystemConfig $systemConfig */
        $systemConfig = ObjectManager::getInstance(SystemConfig::class);
        $rawTitle = $systemConfig->getConfig('ai_site_agent_debug_site_title', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND);
        $effectiveDebugTitle = $rawTitle === null
            ? AiSiteAgentWorkspaceDebugDefaults::SITE_TITLE
            : \trim((string)$rawTitle);
        $rawBrief = $systemConfig->getConfig('ai_site_agent_debug_brief_description', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND);
        $effectiveDebugBrief = $rawBrief === null
            ? AiSiteAgentWorkspaceDebugDefaults::BRIEF_DESCRIPTION
            : \trim((string)$rawBrief);
        $rawLocale = $systemConfig->getConfig('ai_site_agent_debug_default_locale', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND);
        $effectiveDebugLocale = $rawLocale === null
            ? AiSiteAgentWorkspaceDebugDefaults::DEFAULT_LOCALE
            : AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(\trim((string)$rawLocale));
        $this->assign('ai_site_debug_default_site_title', $effectiveDebugTitle);
        $this->assign('ai_site_debug_default_brief', $effectiveDebugBrief);
        $this->assign('ai_site_debug_default_locale', $effectiveDebugLocale);

        $html = $this->fetch();
        $this->logHotPathStage('workspace.total', $startedAt, [
            'public_id' => $publicId,
            'stage' => $session->getStage(),
            'response_bytes' => \strlen($html),
        ]);

        return $html;
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'AI 建站工作台预览', 'mdi-eye-outline', '在 AI 建站工作台中渲染可视化预览', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function workspacePreview(): void
    {
        $startedAt = \microtime(true);
        $resolveContextStartedAt = \microtime(true);
        $context = $this->resolveWorkspacePreviewContext();
        $this->logHotPathStage('workspace_preview.resolve_context', $resolveContextStartedAt, [
            'public_id' => (string)$this->request->getGet('public_id', ''),
            'page_type' => (string)$this->request->getGet('page_type', ''),
            'resolved' => $context !== null ? 1 : 0,
        ]);
        if ($context === null) {
            $adminId = (int)$this->getLoginUserId();
            $publicId = \trim((string)$this->request->getGet('public_id', ''));
            $requestedPageType = \trim((string)$this->request->getGet('page_type', ''));
            $html = $this->renderWorkspacePreviewUnavailablePage($adminId, $publicId, $requestedPageType);
            $this->logHotPathStage('workspace_preview.total', $startedAt, [
                'status' => 404,
                'public_id' => (string)$this->request->getGet('public_id', ''),
                'page_type' => (string)$this->request->getGet('page_type', ''),
            ]);
            throw new ResponseTerminateException(404, $html, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        /** @var \GuoLaiRen\PageBuilder\Service\PageRenderService $pageRenderService */
        $pageRenderService = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\PageRenderService::class);
        $isVisualEditor = $this->request->getGet('visual_editor') === '1';
        $renderMode = $isVisualEditor
            ? \GuoLaiRen\PageBuilder\Service\PageRenderService::MODE_VISUAL
            : \GuoLaiRen\PageBuilder\Service\PageRenderService::MODE_PREVIEW;

        $renderStartedAt = \microtime(true);
        $html = $pageRenderService->render(
            $context['page'],
            $renderMode,
            $context['locale'],
            $context['style_code'] !== '' ? $context['style_code'] : null,
            $context['virtual_theme_id'] > 0 ? $context['virtual_theme_id'] : null
        );
        $this->logHotPathStage('workspace_preview.render', $renderStartedAt, [
            'page_type' => (string)$context['page']->getData(Page::schema_fields_TYPE),
            'virtual_theme_id' => (int)$context['virtual_theme_id'],
            'render_mode' => $renderMode,
            'response_bytes' => \strlen($html),
        ]);
        if ($isVisualEditor) {
            $html = $this->injectWorkspacePreviewNavLinks($html, $context['virtual_pages']);
        }
        $this->logHotPathStage('workspace_preview.total', $startedAt, [
            'status' => 200,
            'page_type' => (string)$context['page']->getData(Page::schema_fields_TYPE),
            'virtual_theme_id' => (int)$context['virtual_theme_id'],
            'response_bytes' => \strlen($html),
        ]);

        throw new ResponseTerminateException(200, $html, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Accel-Expires' => '0',
        ]);
    }

    /**
     * @return array{
     *   page:Page,
     *   style_code:string,
     *   locale:string,
     *   virtual_theme_id:int,
     *   virtual_pages:array<string, array<string, mixed>>
     * }|null
     */
    private function resolveWorkspacePreviewContext(): ?array
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $requestedPageType = \trim((string)$this->request->getGet('page_type', ''));
        if ($adminId <= 0 || $publicId === '' || $requestedPageType === '') {
            return null;
        }

        /** @var \GuoLaiRen\PageBuilder\Service\AiSiteVirtualLayoutService $virtualLayoutService */
        $virtualLayoutService = ObjectManager::getInstance(\GuoLaiRen\PageBuilder\Service\AiSiteVirtualLayoutService::class);
        $context = $virtualLayoutService->loadContext($publicId, $adminId, $requestedPageType);
        if ($context === null) {
            return null;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($context['scope']);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType(
            $this->scopeCompatibilityService->resolveScopedPageTypes($scope),
            $scope
        );
        $pageType = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, $requestedPageType);
        if ($pageType === '' || !\is_array($virtualPages[$pageType] ?? null)) {
            return null;
        }

        // 仅信任该 public_id 会话下的虚拟主题；忽略 GET 中可能被拦截器注入的其它 virtual_theme_id。
        $virtualThemeId = (int)($context['virtual_theme_id'] ?? 0);
        $virtualPage = $virtualPages[$pageType];
        $styleCode = \trim((string)($this->request->getGet('style_code') ?: ($virtualPage['style_code'] ?? 'default')));
        $styleCode = $styleCode !== '' ? $styleCode : 'default';
        $locale = \trim((string)($this->request->getGet('locale') ?: ($virtualPage['locale'] ?? State::getLang())));
        $locale = $locale !== '' ? $locale : State::getLang();
        $layout = $virtualLayoutService->getResolvedLayout($virtualThemeId, $pageType);
        $virtualBlocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        $renderMode = $virtualBlocks === [] ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;

        $page = ObjectManager::make(Page::class);
        $page->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_WEBSITE_ID => (int)($scope['draft_website_id'] ?? 0),
            Page::schema_fields_PARENT_ID => $pageType === Page::TYPE_HOME ? 0 : 1,
            Page::schema_fields_LAYOUT_PAGE_ID => 0,
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
            Page::schema_fields_TITLE => (string)($virtualPage['title'] ?? ''),
            Page::schema_fields_NAME => (string)($virtualPage['title'] ?? ''),
            Page::schema_fields_HANDLE => (string)($virtualPage['handle'] ?? ''),
            Page::schema_fields_STYLE => $styleCode,
            Page::schema_fields_TYPE => $pageType,
            Page::schema_fields_CONTENT => '',
            Page::schema_fields_META_TITLE => (string)($virtualPage['meta_title'] ?? ''),
            Page::schema_fields_META_DESCRIPTION => (string)($virtualPage['meta_description'] ?? ''),
            Page::schema_fields_META_KEYWORDS => (string)($virtualPage['meta_keywords'] ?? ''),
            Page::schema_fields_AI_DESCRIPTION => (string)($virtualPage['ai_description'] ?? ''),
            Page::schema_fields_LOCALES => \json_encode([$locale], \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_DEFAULT_LOCALE => $locale,
            Page::schema_fields_STYLE_SETTING => \json_encode([
                $styleCode => \is_array($virtualPage['style_settings'] ?? null) ? $virtualPage['style_settings'] : [],
            ], \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_LAYOUT_CONFIG => \json_encode($layout, \JSON_UNESCAPED_UNICODE),
            Page::schema_fields_RENDER_MODE => $renderMode,
            Page::schema_fields_AI_LAYOUT => \json_encode(['blocks' => $virtualBlocks], \JSON_UNESCAPED_UNICODE),
        ]);
        $page->setData('virtual_public_id', $publicId);
        $page->setData('virtual_page_type', $pageType);
        $page->setData('virtual_theme_id', $virtualThemeId);
        $page->setData('virtual_layout_config', $layout);
        $page->setData('virtual_pages_by_type', $virtualPages);

        return [
            'page' => $page,
            'style_code' => $styleCode,
            'locale' => $locale,
            'virtual_theme_id' => $virtualThemeId,
            'virtual_pages' => $virtualPages,
        ];
    }

    /**
     * 预览 iframe 无法解析上下文时返回占位 HTML（会话有效时可引导 AI 生成，并携带细节任务规划是否已确认）。
     */
    private function renderWorkspacePreviewUnavailablePage(int $adminId, string $publicId, string $requestedPageType): string
    {
        $sessionAccessible = false;
        $taskPlanConfirmed = false;
        if ($adminId > 0 && $publicId !== '' && $requestedPageType !== '') {
            $session = $this->sessionService->loadByPublicId($publicId, $adminId);
            if ($session !== null) {
                $sessionAccessible = true;
                $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
                $taskPlanConfirmed = (int)($scope['task_plan_confirmed'] ?? 0) === 1;
            }
        }
        // 必须用 template() 直出 HTML：fetch() 会走 fetch_file_after，Theme 会把内容包进后台整页布局，iframe 内会出现后台顶栏/主题壳。
        return $this->template('GuoLaiRen_PageBuilder::templates/Backend/AiSiteAgent/workspace-preview-unavailable.phtml', [
            'pb_preview_unavailable_session_ok' => $sessionAccessible,
            'pb_preview_unavailable_task_plan_confirmed' => $taskPlanConfirmed,
            'pb_preview_unavailable_page_type' => $requestedPageType,
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $virtualPages
     */
    private function injectWorkspacePreviewNavLinks(string $html, array $virtualPages): string
    {
        if ($virtualPages === []) {
            return $html;
        }

        $pages = [];
        foreach ($virtualPages as $pageType => $pageData) {
            if (!\is_string($pageType) || !\is_array($pageData)) {
                continue;
            }
            $handle = \trim((string)($pageData['handle'] ?? ''));
            $url = ($pageType === Page::TYPE_HOME || $handle === '') ? '/' : '/' . $handle;
            $pages[] = [
                'page_type' => $pageType,
                'url' => $url,
            ];
        }
        if ($pages === []) {
            return $html;
        }

        $pagesJson = \json_encode($pages, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $script = <<<SCRIPT
<script>
(function(){
  var pages = {$pagesJson};
  function findPageByHref(href) {
    if (!href || href.charAt(0) === '#') return null;
    var path = href.replace(/^https?:\\/\\/[^/]*/, '').replace(/\\?.*$/, '').split('#')[0] || '/';
    if (path === '') path = '/';
    for (var i = 0; i < pages.length; i++) {
      if (pages[i].url === path) return pages[i];
    }
    return null;
  }
  var selector = 'header a[href^="/"], footer a[href^="/"], [data-region="header"] a[href^="/"], [data-region="footer"] a[href^="/"], nav a[href^="/"]';
  var links = document.querySelectorAll(selector);
  for (var j = 0; j < links.length; j++) {
    var link = links[j];
    var page = findPageByHref(link.getAttribute('href'));
    if (!page || !page.page_type) continue;
    link.setAttribute('href', '#');
    link.setAttribute('data-ve-page-type', String(page.page_type));
    link.addEventListener('click', function(e) {
      e.preventDefault();
      var pageType = this.getAttribute('data-ve-page-type');
      if (pageType && window.parent !== window) {
        window.parent.postMessage({ type: 'PageBuilderVisualEditor', action: 'navigate', page_type: pageType }, '*');
      }
    });
  }
})();
</script>
SCRIPT;

        $position = \strripos($html, '</body>');
        if ($position !== false) {
            return \substr($html, 0, $position) . $script . "\n" . \substr($html, $position);
        }

        return $html . $script;
    }

    private function isAiSiteAgentExpertUiRequested(): bool
    {
        return false;
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

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '排序阶段一方案块', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSortPlanBlocks(): string
    {
        return $this->handleSortPlanBlocks();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '新增/删除/重建阶段一方案块', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postMutatePlanBlock(): string
    {
        return $this->handleMutatePlanBlock();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '寰皟褰撳墠椤甸潰闃舵涓€鍧楁爲', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRefinePlanPage(): string
    {
        return $this->handleRefinePlanPage();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '排序阶段二任务块', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSortTaskPlanTasks(): string
    {
        return $this->handleSortTaskPlanTasks();
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

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '生成阶段一方案书', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPlan(): string { return $this->handleStartPlan(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '确认阶段一方案书', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postConfirmPlan(): string { return $this->handleConfirmPlan(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '流式微调/重建阶段一方案书', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postPlanSse(): void { $this->handlePlanSse(); }
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '流式微调/重建阶段一方案书(GET兼容)', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPlanSse(): void { $this->handlePlanSse(); }
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '流式微调/重建阶段一方案书(POST路由GET兼容)', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPostPlanSse(): void { $this->handlePlanSse(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '生成第二阶段任务方案', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartTaskPlan(): string { return $this->handleStartTaskPlan(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '确认第二阶段任务方案', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postConfirmTaskPlan(): string { return $this->handleConfirmTaskPlan(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '流式微调/重建第二阶段任务方案', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postTaskPlanSse(): void { $this->handleTaskPlanSse(); }
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '流式微调/重建第二阶段任务方案(POST路由GET兼容)', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPostTaskPlanSse(): void { $this->handleTaskPlanSse(); }

    public function postStartTaskPlanSse(): void { $this->handleTaskPlanSse(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '显式继续未完成构建', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postResumeBuild(): string { return $this->handleResumeBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '启动主题构建', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartBuild(): string { return $this->handleStartBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '执行虚拟主题编排（兼容）', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRunVirtualTheme(): string { return $this->handleStartBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '启动单页重建', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartRegeneratePage(): string { return $this->handleStartRegeneratePage(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '启动区块 AI 微调', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartRefineComponent(): string { return $this->handleStartRefineComponent(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '区块 AI 微调（SSE）', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }

    public function postBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }

    public function postUpdateBlockConfig(): string { return $this->handleUpdateBlockConfig(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '启动发布流程', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPublish(): string { return $this->handleStartPublish(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '切换当前预览页', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSwitchPreviewPage(): string { return $this->handleSwitchPreviewPage(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '拉取工作区快照（含阶段一队列信息）', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postWorkspaceSnapshot(): string
    {
        return $this->handleWorkspaceSnapshot();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '发布前检查', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postPublishChecklist(): string { return $this->handlePublishChecklist(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'AI 建站工作台', 'mdi-robot-outline', '删除 AI 建站工作台会话', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDeleteWorkspace(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        if (!$this->sessionService->deleteSession($session->getId(), $adminId)) {
            return $this->fetchJson(['success' => false, 'message' => __('删除工作区失败，请稍后重试')]);
        }

        return $this->fetchJson(['success' => true, 'message' => __('工作区已删除')]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'AI 建站事件流', 'mdi-access-point', '订阅 AI 建站会话事件流', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getStreamSse(): void
    {
        if (\defined('DEV') && DEV && !\headers_sent()) {
            \header('X-AiSite-Sse-Debug: getStreamSse-entered');
        }
        $this->handleStreamSse();
    }
    public function postTouchStreamLease(): string
    {
        return $this->fetchJson([
            'success' => true,
            'message' => __('原生 SSE 模式无需额外续约'),
            'native_sse' => true,
        ]);
    }

    /**
     * 测试 SSE 短轮询（无需认证）
     *
     * 用于验证 SSE 短轮询机制是否正常工作
     * 警告：仅用于测试，生产环境应删除此方法
     */
    public function getTestSse(): void
    {
        // 释放 PHP session 锁，避免阻塞 SSE
        $this->releasePhpSessionLockForSse();

        $sse = new SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => 'Test SSE connection started']);
        $sse->sendEvent('test', ['timestamp' => time(), 'message' => 'This is a test event']);

        // 非阻塞示例：只发送一组立即可用事件，不做轮询等待。
        $sse->sendEvent('poll', ['count' => 1, 'timestamp' => time()]);
        $sse->sendEvent('poll', ['count' => 2, 'timestamp' => time()]);
        $sse->sendEvent('poll', ['count' => 3, 'timestamp' => time()]);

        $sse->complete(['success' => true, 'message' => 'Test complete, please reconnect']);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'AI 建站操作 SSE', 'mdi-access-point-network', '执行构建/重建/发布操作流', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getOperationSse(): void { $this->handleOperationSse(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_create', '创建 AI 建站会话', 'mdi-plus', '创建新的 AI 建站会话', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postCreateSession(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('未登录')]);
        }

        $fakeMode = $this->getRequestBodyValue('fake_mode', '0');
        $fakeModeEnabled = $fakeMode === true
            || $fakeMode === 1
            || $fakeMode === '1'
            || $fakeMode === 'true';

        try {
            $session = $this->sessionService->createSession($adminId, [
                'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING,
                'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                'fake_mode' => $fakeModeEnabled ? 1 : 0,
                'site_title' => '',
                'site_tagline' => '',
                'target_domain' => '',
                'selected_domain' => '',
                'brief_description' => '',
                'user_description' => '',
                'default_locale' => '',
                'plan_locale' => '',
                'recommended_domain_list' => [],
                'recommended_registrar_label' => '',
                'preferred_registrar_account_id' => 0,
                'registrar_account_id' => 0,
                'page_types' => [],
                'recommended_pages' => [],
                'pagebuilder_pages_by_type' => [],
                'virtual_pages_by_type' => [],
                'page_type_layouts' => [],
                'site_profile_manual' => [],
                'active_operation' => [],
                'build_summary' => [],
                'build_task_summary' => [],
                'top_logs' => [],
                'events' => [],
                'preview_page_id' => 0,
                'preview_page_type' => '',
                'site_ready' => 1,
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

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_domain_purchase', 'PageBuilder 域名购买', 'mdi-cart-arrow-down', '在 PageBuilder 工作台中代理 Websites 域名购买工作流', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartDomainPurchase(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        $linkedWebsitesSession = $this->ensureLinkedWebsitesMirrorSession($session, $adminId);
        if (!$linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession) {
            return $this->fetchJson(['success' => false, 'message' => __('暂时无法创建域名购买工作区')]);
        }

        $scopeError = '';
        $scopePatch = $this->getRequestJsonObject('scope_patch', $scopeError);
        if ($scopeError !== '') {
            return $this->fetchJson(['success' => false, 'message' => $scopeError]);
        }

        $scopePatch = \array_replace($this->buildLinkedWebsitesScopeFromPageBuilderSession($session), $scopePatch);
        $result = $this->getWebsitesDomainPurchaseWorkbenchService()->queuePurchase(
            $linkedWebsitesSession->getId(),
            $adminId,
            $scopePatch
        );

        if (empty($result['success'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => (string)($result['message'] ?? __('加入域名购买队列失败')),
            ]);
        }

        $this->syncPageBuilderScopeFromLinkedWebsitesSession($session, $linkedWebsitesSession, $adminId);
        $freshPageBuilderSession = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $freshWebsitesSession = $this->getWebsitesSessionService()->loadById($linkedWebsitesSession->getId(), $adminId) ?? $linkedWebsitesSession;
        $state = $this->getWebsitesDomainPurchaseWorkbenchService()->buildViewState($freshWebsitesSession);

        return $this->fetchJson([
            'success' => true,
            'message' => (string)($result['message'] ?? __('已加入域名购买队列')),
            'state' => $state,
            'pagebuilder_state' => $this->buildWorkspaceState($freshPageBuilderSession, $adminId, 80, true),
            'linked_public_id' => $freshWebsitesSession->getPublicId(),
        ]);
    }

    private function handleStartPlan(): string
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
        $scope = $this->scopeCompatibilityService->normalizeScope(\array_replace($session->getScopeArray(), $scopePatch));
        $confirmRegenerate = \in_array(\strtolower(\trim((string)$this->getRequestBodyValue('confirm_regenerate', '0'))), ['1', 'true', 'yes', 'on'], true);
        $requestedPlanLocale = \trim((string)$this->getRequestBodyValue('plan_locale', ''));
        if ($requestedPlanLocale !== '') {
            $scope['plan_locale'] = $requestedPlanLocale;
        }
        $scope['plan_locale'] = $this->resolvePlanLocale($scope);
        // 第一阶段开始时先持久化用户本轮输入（域名/一句话描述/语言/页面类型等），再入队生成任务。
        $persistPatch = \array_replace($scopePatch, [
            'plan_locale' => (string)$scope['plan_locale'],
            'plan_confirmed' => 0,
        ]);
        if (isset($persistPatch['page_types']) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $persistPatch)) {
            $persistPatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = !empty($persistPatch['page_types']) ? 1 : 0;
        }
        $this->sessionService->mergeScope($session->getId(), $adminId, $persistPatch);
        $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $currentScope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $planStartDecision = $this->resolvePlanStartDecision($currentScope);
        $planIsEmpty = $this->isPlanContentEmpty($currentScope);
        $hasPlanDraft = !$planIsEmpty;
        if (
            $hasPlanDraft
            && \in_array((string)($planStartDecision['action'] ?? ''), ['rebuild', 'translate'], true)
            && !$confirmRegenerate
        ) {
            $confirmMessage = (string)($planStartDecision['action'] ?? '') === 'translate'
                ? (string)__('方案语言已变更。是否按新语言重新生成（翻译）阶段一方案？')
                : (string)__('页面类型或方案语言已变更。是否立即重建阶段一方案？');
            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('检测到方案配置变更，当前先保留旧方案，等待你确认后再重新生成。'),
                'operation' => 'plan',
                'start_sse' => false,
                'requires_confirmation' => true,
                'plan_is_empty' => $planIsEmpty,
                'allow_generate_when_plan_empty' => true,
                'confirm_message' => $confirmMessage,
                'plan_action' => (string)($planStartDecision['action'] ?? 'reuse'),
                'plan_rebuild_required' => !empty($planStartDecision['rebuild_required']),
                'plan_translation_required' => !empty($planStartDecision['translation_required']),
                'plan_locale_changed' => !empty($planStartDecision['plan_locale_changed']),
                'plan_page_types_changed' => !empty($planStartDecision['page_types_changed']),
                'data' => $this->buildWorkspaceState($session, $adminId, 80, true),
            ]);
        }
        $requestedPageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($currentScope);
        $requestedPromptMode = \trim((string)$this->getRequestBodyValue('prompt_mode', ''));
        $requestedInstruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        $requestedTargetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        $requestedRound = \max(1, (int)$this->getRequestBodyValue('round', 1));
        $activeOperation = \is_array($currentScope['active_operation'] ?? null) ? $currentScope['active_operation'] : [];
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        $activeOperationStatus = \trim((string)($activeOperation['status'] ?? ''));
        if (
            $activeOperationName === 'plan'
            && \in_array($activeOperationStatus, ['queued', 'running'], true)
            && $this->shouldRestartPlanOperationForScopeChange($activeOperation, (string)$scope['plan_locale'], $requestedPageTypes)
        ) {
            $this->cancelActivePlanOperationForScopeChange($session, $adminId);
            $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $currentScope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
            $planStartDecision = $this->resolvePlanStartDecision($currentScope);
        }
        if ((string)($planStartDecision['action'] ?? '') === 'reuse' && $hasPlanDraft) {
            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('当前方案无需重建，已保留并回显已有方案内容。'),
                'operation' => 'plan',
                'start_sse' => false,
                'requires_confirmation' => false,
                'plan_is_empty' => $planIsEmpty,
                'allow_generate_when_plan_empty' => true,
                'plan_action' => 'reuse',
                'plan_rebuild_required' => false,
                'plan_translation_required' => false,
                'plan_locale_changed' => false,
                'plan_page_types_changed' => false,
                'data' => $this->buildWorkspaceState($session, $adminId, 80, true),
            ]);
        }
        $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $effectivePlanPromptMode = \in_array($requestedPromptMode, ['refine', 'rebuild'], true)
            ? $requestedPromptMode
            : (string)($planStartDecision['action'] ?? 'rebuild');
        $result = $this->startOperation($session, $adminId, 'plan', $stage, [
            'plan_locale' => (string)$scope['plan_locale'],
            'plan_confirmed' => 0,
            '_plan_sse_request' => [
                'prompt_mode' => $effectivePlanPromptMode,
                'instruction' => $requestedInstruction,
                'target_scope' => $requestedTargetScope,
                'round' => $requestedRound,
                'plan_locale' => (string)$scope['plan_locale'],
            ],
        ]);
        if (empty($result['success'])) {
            if ((string)($result['operation'] ?? '') === 'plan') {
                return $this->fetchJson([
                    'success' => true,
                    'message' => (string)__('检测到阶段一方案任务已在执行中，已继续复用当前任务进度。'),
                    'operation' => 'plan',
                    'start_sse' => true,
                    'requires_confirmation' => false,
                    'plan_is_empty' => $planIsEmpty,
                    'allow_generate_when_plan_empty' => true,
                    'plan_action' => (string)($planStartDecision['action'] ?? 'reuse'),
                    'plan_rebuild_required' => !empty($planStartDecision['rebuild_required']),
                    'plan_translation_required' => !empty($planStartDecision['translation_required']),
                    'plan_locale_changed' => !empty($planStartDecision['plan_locale_changed']),
                    'plan_page_types_changed' => !empty($planStartDecision['page_types_changed']),
                    'data' => $this->buildWorkspaceState($session, $adminId, 80, true),
                ]);
            }
            return $this->fetchJson([
                'success' => false,
                'message' => (string)($result['message'] ?? __('当前无法启动阶段一方案生成')),
                'operation' => (string)($result['operation'] ?? ''),
            ]);
        }

        $responseState = \is_array($result['data'] ?? null) ? $result['data'] : $this->buildWorkspaceState($session, $adminId, 80, true);
        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('阶段一方案生成任务已创建，请查看工作区事件流进度'),
            'operation' => (string)($result['operation'] ?? 'plan'),
            'start_sse' => true,
            'queue_id' => (int)($result['queue_id'] ?? 0),
            'queue_dispatch' => \is_array($result['queue_dispatch'] ?? null) ? $result['queue_dispatch'] : null,
            'requires_confirmation' => false,
            'plan_is_empty' => $planIsEmpty,
            'allow_generate_when_plan_empty' => true,
            'plan_action' => (string)($planStartDecision['action'] ?? 'generate'),
            'plan_rebuild_required' => !empty($planStartDecision['rebuild_required']),
            'plan_translation_required' => !empty($planStartDecision['translation_required']),
            'plan_locale_changed' => !empty($planStartDecision['plan_locale_changed']),
            'plan_page_types_changed' => !empty($planStartDecision['page_types_changed']),
            'data' => $responseState,
        ]);
    }

    private function handleConfirmPlan(): string
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

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $executionBlueprintDraft = \is_array($scope['execution_blueprint_draft'] ?? null) ? $scope['execution_blueprint_draft'] : [];
        if ($executionBlueprintDraft === []) {
            return $this->jsonError('PLAN_NOT_READY', (string)__('尚未生成方案，请先完成方案生成'), ['public_id']);
        }

        $scopePatch = [
            'execution_blueprint' => $executionBlueprintDraft,
            'execution_blueprint_confirmed_at' => \date('Y-m-d H:i:s'),
            'execution_blueprint_confirmed_signature' => (string)($executionBlueprintDraft['signature'] ?? ''),
            'plan_confirmed' => 1,
        ];
        if (isset($executionBlueprintDraft['workspace_track'])) {
            $scopePatch['workspace_track'] = (string)$executionBlueprintDraft['workspace_track'];
        }
        if (\is_array($executionBlueprintDraft['page_types'] ?? null) && $executionBlueprintDraft['page_types'] !== []) {
            $scopePatch['page_types'] = $executionBlueprintDraft['page_types'];
            $scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }

        $confirmedScope = $this->scopeCompatibilityService->normalizeScope(\array_replace($scope, $scopePatch));
        $confirmedScope = $this->buildTaskService->ensureTaskScope(
            $confirmedScope,
            \is_array($confirmedScope['website_profile'] ?? null) ? $confirmedScope['website_profile'] : [],
            (string)($confirmedScope['workspace_track'] ?? '')
        );
        if (\is_array($confirmedScope['build_blueprint'] ?? null) && $confirmedScope['build_blueprint'] !== []) {
            $scopePatch['build_blueprint'] = $confirmedScope['build_blueprint'];
        }
        if (\is_array($confirmedScope['build_tasks'] ?? null) && $confirmedScope['build_tasks'] !== []) {
            $scopePatch['build_tasks'] = $confirmedScope['build_tasks'];
        }
        $scopePatch['build_summary'] = [
            'task_summary' => $this->buildTaskService->summarize($confirmedScope),
            'confirmed_execution_blueprint_signature' => (string)($executionBlueprintDraft['signature'] ?? ''),
        ];

        $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
            'plan_confirmed',
            (string)__('方案已确认，已进入第二阶段，可继续生成任务方案并开始构建'),
            [
                'operation' => 'plan_confirm',
                'details' => ['execution_blueprint_signature' => (string)($executionBlueprintDraft['signature'] ?? '')],
            ]
        );

        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('方案已确认，已进入第二阶段，可继续生成任务方案并开始构建'),
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    private function handlePlanSse(): void
    {
        @\ignore_user_abort(true);

        $adminId = (int)$this->getLoginUserId();
        $this->releasePhpSessionLockForSse();
        $sse = new SseWriter();
        $sse->start();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $promptMode = \trim((string)$this->getRequestBodyValue('prompt_mode', ''));
        if ($adminId <= 0 || $publicId === '' || !\in_array($promptMode, ['refine', 'rebuild'], true)) {
            $this->sendSseContractError($sse, 'INVALID_PARAMS', (string)__('阶段一方案流参数无效'), ['public_id', 'prompt_mode']);
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->sendSseContractError($sse, 'SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), ['public_id'], 404);
            $sse->complete(['success' => false]);
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $requestedPlanLocaleInput = \trim((string)$this->getRequestBodyValue('plan_locale', ''));
        if ($requestedPlanLocaleInput !== '') {
            $scope['plan_locale'] = $requestedPlanLocaleInput;
        }
        $scope['plan_locale'] = $this->resolvePlanLocale($scope);
        $requestedPlanLocale = (string)$scope['plan_locale'];
        $lastGeneratedPlanLocale = \trim((string)($scope['plan_generated_locale'] ?? ''));
        $requestedPageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];
        $lastGeneratedPageTypes = \is_array($scope['plan_generated_page_types'] ?? null) ? $scope['plan_generated_page_types'] : [];
        $planLocaleChanged = $requestedPlanLocale !== '' && $lastGeneratedPlanLocale !== '' && $requestedPlanLocale !== $lastGeneratedPlanLocale;
        $pageTypesChanged = !$this->isSamePageTypeSelection($requestedPageTypes, $lastGeneratedPageTypes);
        $effectivePromptMode = ($promptMode === 'refine' && $planLocaleChanged && $pageTypesChanged) ? 'rebuild' : $promptMode;
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        $targetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        $round = \max(1, (int)$this->getRequestBodyValue('round', 1));
        if ($planLocaleChanged && !$pageTypesChanged) {
            $targetScope = $targetScope !== '' ? $targetScope : 'locale_translation';
            $instruction = $instruction !== ''
                ? ((string)__('请按目标 plan_locale 翻译当前方案，并保留原有结构与页面类型。') . ' ' . $instruction)
                : (string)__('请按目标 plan_locale 翻译当前方案，并保留原有结构与页面类型。');
        }
        if ($effectivePromptMode === 'rebuild') {
            $instruction = $instruction !== ''
                ? ((string)__('本轮属于重建：请基于用户最新要求，完整重新生成阶段一方案。按全新方案输出，不沿用旧方案结构，不输出局部片段，必须覆盖 markdown 全文与完整 plan_json。') . ' ' . $instruction)
                : (string)__('本轮属于重建：请基于用户最新要求，完整重新生成阶段一方案。按全新方案输出，不沿用旧方案结构，不输出局部片段，必须覆盖 markdown 全文与完整 plan_json。');
        } else {
            $instruction = $instruction !== ''
                ? ((string)__('本轮属于对话式微调：请把用户最新指令当作新的对话上下文，重新生成完整阶段一方案。允许保留有效历史内容，但必须严格落实本轮新增/删除/改写要求。输出必须是完整方案（非局部片段），覆盖 markdown 全文与完整 plan_json。') . ' ' . $instruction)
                : (string)__('本轮属于对话式微调：请把用户最新指令当作新的对话上下文，重新生成完整阶段一方案。允许保留有效历史内容，但必须严格落实本轮新增/删除/改写要求。输出必须是完整方案（非局部片段），覆盖 markdown 全文与完整 plan_json。');
        }

        $sse->sendEvent('start', [
            'message' => $effectivePromptMode === 'rebuild'
                ? (string)__('正在重建阶段一方案')
                : (string)__('正在微调阶段一方案'),
            'prompt_mode' => $effectivePromptMode,
            'round' => $round,
            'target_scope' => $targetScope,
            'plan_locale' => $requestedPlanLocale,
            'locale_changed_force_rebuild' => ($effectivePromptMode !== $promptMode) ? 1 : 0,
        ]);
        $sse->sendEvent('progress', [
            'message' => (string)__('正在整理当前站点方案上下文'),
            'prompt_mode' => $effectivePromptMode,
            'progress_percent' => 20,
        ]);

        try {
            $websiteProfile = $this->profileGenerationService->generate($scope, false);
            $aiChunkCount = 0;
            $rawChunkInfoBuffer = '';
            $emitAiChunkProgress = function (string $chunk) use (&$aiChunkCount, &$rawChunkInfoBuffer, $sse, $effectivePromptMode): void {
                if (\trim($chunk) === '') {
                    return;
                }
                $rawChunkInfoBuffer .= $chunk;
                while (\mb_strlen($rawChunkInfoBuffer, 'UTF-8') >= 360) {
                    if (!$sse->isAlive()) {
                        $rawChunkInfoBuffer = '';
                        return;
                    }
                    $part = (string)\mb_substr($rawChunkInfoBuffer, 0, 360, 'UTF-8');
                    $rawChunkInfoBuffer = (string)\mb_substr($rawChunkInfoBuffer, 360, null, 'UTF-8');
                    $sse->sendEvent('info', [
                        'message' => $part,
                        'prompt_mode' => $effectivePromptMode,
                        'stream_stage' => 'ai_raw_chunk',
                        'chunk' => $part,
                    ]);
                }
                $aiChunkCount++;
                if ($aiChunkCount === 1 || $aiChunkCount % 12 === 0) {
                    $sse->sendEvent('progress', [
                        'message' => (string)__('AI 正在生成阶段一方案内容…'),
                        'prompt_mode' => $effectivePromptMode,
                        'progress_percent' => 55,
                        'ai_chunk_count' => $aiChunkCount,
                    ]);
                }
            };
            $artifacts = $effectivePromptMode === 'rebuild'
                ? $this->executionBlueprintService->rebuildDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                    'instruction' => $instruction,
                    'round' => $round,
                ], $emitAiChunkProgress)
                : $this->executionBlueprintService->refineDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                    'instruction' => $instruction,
                    'target_scope' => $targetScope,
                    'round' => $round,
                ], $emitAiChunkProgress);
            if ($rawChunkInfoBuffer !== '' && $sse->isAlive()) {
                $sse->sendEvent('info', [
                    'message' => $rawChunkInfoBuffer,
                    'prompt_mode' => $effectivePromptMode,
                    'stream_stage' => 'ai_raw_chunk',
                    'chunk' => $rawChunkInfoBuffer,
                ]);
                $rawChunkInfoBuffer = '';
            }
            if ((int)($scope['fake_mode'] ?? 0) !== 1 && (int)($artifacts['ai_generated'] ?? 0) !== 1) {
                throw new \RuntimeException((string)__('阶段一方案必须由 AI 生成，本次未成功调用 AI，请检查模型配置后重试。'));
            }

            $derivedPatch = \is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [];
            $executionBlueprint = \is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [];
            $markdown = (string)($artifacts['markdown'] ?? '');
            $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
            $planJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
            $scopePatch = \array_replace($derivedPatch, [
                'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
                'execution_blueprint_draft' => $executionBlueprint,
                'plan_json' => $planJson,
                'plan_markdown' => $markdown,
                'plan_structured' => $structured !== [] ? $structured : $planJson,
                'plan_locale' => $this->resolvePlanLocale($scope),
                'plan_ai_generated' => (int)($artifacts['ai_generated'] ?? 0),
                'plan_ai_fallback' => (int)($artifacts['ai_fallback'] ?? 0),
                'plan_generated_at' => \date('Y-m-d H:i:s'),
                // 微调在已有确认状态下默认保持已确认，避免仅做文案/区块增补后打断后续阶段。
                'plan_confirmed' => $effectivePromptMode === 'rebuild' ? 0 : (int)($scope['plan_confirmed'] ?? 0),
                'plan_last_prompt_mode' => $effectivePromptMode,
                'plan_last_target_scope' => $targetScope,
                'plan_last_round' => $round,
                'plan_generated_locale' => $requestedPlanLocale,
                'plan_generated_page_types' => \array_values(\array_map('strval', $requestedPageTypes)),
            ]);
            if ($effectivePromptMode === 'rebuild') {
                $scopePatch['plan_rebuild_summary'] = \is_array($artifacts['rebuild_summary'] ?? null) ? $artifacts['rebuild_summary'] : [];
            } else {
                $scopePatch['plan_change_scope_report'] = \is_array($artifacts['change_scope_report'] ?? null) ? $artifacts['change_scope_report'] : [];
            }
            $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
                'plan_saved',
                (string)__('阶段一方案已保存'),
                [
                    'operation' => 'plan',
                    'details' => [
                        'prompt_mode' => $effectivePromptMode,
                        'target_scope' => $targetScope,
                        'round' => $round,
                        'plan_locale' => $requestedPlanLocale,
                    ],
                ]
            );

            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
                $effectivePromptMode === 'rebuild' ? 'plan_rebuilt' : 'plan_refined',
                $effectivePromptMode === 'rebuild'
                    ? (string)__('阶段一方案已重建，请确认后再进入第二阶段')
                    : (string)__('阶段一方案已微调，请确认后再进入第二阶段'),
                [
                    'operation' => $effectivePromptMode === 'rebuild' ? 'rebuild_plan' : 'refine_plan',
                    'details' => [
                        'target_scope' => $targetScope,
                        'round' => $round,
                        'plan_locale' => $requestedPlanLocale,
                        'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? ''),
                    ],
                ]
            );

            $sse->sendEvent('progress', [
                'message' => (string)__('正在输出更新后的阶段一方案'),
                'prompt_mode' => $effectivePromptMode,
                'progress_percent' => 80,
            ]);
            foreach ($this->chunkStringForSse($markdown, 220) as $chunk) {
                $sse->sendEvent('chunk', [
                    'content' => $chunk,
                    'chunk' => $chunk,
                    'prompt_mode' => $effectivePromptMode,
                ]);
                if (!$sse->isAlive()) {
                    break;
                }
                SchedulerSystem::yieldDelay(5);
            }

            $state = $this->buildWorkspaceEventStatePayload($this->buildWorkspaceState($fresh, $adminId, 80, true));
            $sse->complete([
                'success' => true,
                'message' => $effectivePromptMode === 'rebuild'
                    ? (string)__('阶段一方案重建完成')
                    : (string)__('阶段一方案微调完成'),
                'prompt_mode' => $effectivePromptMode,
                'requested_prompt_mode' => $promptMode,
                'plan_locale' => $requestedPlanLocale,
                'plan' => [
                    'json' => $planJson,
                    'markdown' => $markdown,
                    'structured' => $structured !== [] ? $structured : $planJson,
                    'execution_blueprint' => $executionBlueprint,
                ],
                'state' => $state,
            ]);
        } catch (\Throwable $throwable) {
            $sse->sendError($throwable->getMessage(), $this->inferThrowableHttpCode($throwable));
            $sse->complete([
                'success' => false,
                'message' => $throwable->getMessage(),
                'prompt_mode' => $effectivePromptMode,
            ]);
        }
    }

    private function handleStartTaskPlan(): string
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

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        if ((int)($scope['plan_confirmed'] ?? 0) !== 1) {
            return $this->jsonError('PLAN_REQUIRED_BEFORE_TASK_PLAN', (string)__('请先确认第一阶段方案，再生成第二阶段任务方案'), ['public_id', 'plan_confirmed']);
        }
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        if ($executionBlueprint === []) {
            return $this->jsonError('EXECUTION_BLUEPRINT_REQUIRED', (string)__('缺少已确认执行蓝图，请重新生成并确认第一阶段方案'), ['public_id', 'execution_blueprint']);
        }

        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            (string)($scope['workspace_track'] ?? '')
        );
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $requestedPromptMode = \trim((string)$this->getRequestBodyValue('prompt_mode', ''));
        $requestedInstruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        $requestedTargetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        $requestedRound = \max(1, (int)$this->getRequestBodyValue('round', 1));
        $result = $this->startOperation(
            $session,
            $adminId,
            'task_plan',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            [
                'build_blueprint' => $buildBlueprint,
                'build_tasks' => \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [],
                '_task_plan_sse_request' => [
                    'prompt_mode' => \in_array($requestedPromptMode, ['detect_bootstrap_task_plan', 'refine_task_plan', 'rebuild_task_plan'], true)
                        ? $requestedPromptMode
                        : 'detect_bootstrap_task_plan',
                    'instruction' => $requestedInstruction,
                    'target_scope' => $requestedTargetScope,
                    'round' => $requestedRound,
                ],
            ],
            '',
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
        );

        if (empty($result['success'])) {
            if ((string)($result['operation'] ?? '') === 'task_plan') {
                return $this->fetchJson([
                    'success' => true,
                    'message' => (string)__('检测到第二阶段任务方案任务已在执行中，继续复用当前进度。'),
                    'operation' => 'task_plan',
                    'start_sse' => true,
                    'data' => $this->buildWorkspaceState($session, $adminId, 80, true),
                ]);
            }

            return $this->fetchJson([
                'success' => false,
                'message' => (string)($result['message'] ?? __('当前无法启动第二阶段任务方案生成')),
                'operation' => (string)($result['operation'] ?? ''),
            ]);
        }

        $responseState = \is_array($result['data'] ?? null) ? $result['data'] : $this->buildWorkspaceState($session, $adminId, 80, true);
        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('第二阶段任务方案生成任务已创建，请查看阶段进度。'),
            'operation' => (string)($result['operation'] ?? 'task_plan'),
            'start_sse' => true,
            'queue_id' => (int)($result['queue_id'] ?? 0),
            'queue_dispatch' => \is_array($result['queue_dispatch'] ?? null) ? $result['queue_dispatch'] : null,
            'data' => $responseState,
        ]);
    }

    private function handleConfirmTaskPlan(): string
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

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $draftTaskPlan = \is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : [];
        $draftMarkdown = (string)($scope['virtual_theme_plan']['draft_markdown'] ?? '');
        if ($draftTaskPlan === []) {
            return $this->jsonError('TASK_PLAN_NOT_READY', (string)__('尚未生成第二阶段任务方案，请先生成后再确认'), ['public_id']);
        }
        if ((int)($scope['plan_confirmed'] ?? 0) !== 1) {
            return $this->jsonError('PLAN_REQUIRED_BEFORE_TASK_PLAN_CONFIRM', (string)__('请先确认阶段一方案，再确认第二阶段任务方案'), ['public_id', 'plan_confirmed']);
        }

        $scopePatch = [
            'virtual_theme_plan' => [
                'draft' => $draftTaskPlan,
                'draft_markdown' => $draftMarkdown,
                'draft_generated_at' => (string)($scope['virtual_theme_plan']['draft_generated_at'] ?? ''),
                'confirmed' => $draftTaskPlan,
                'confirmed_markdown' => $draftMarkdown,
                'confirmed_at' => \date('Y-m-d H:i:s'),
                'confirmed_signature' => (string)($draftTaskPlan['signature'] ?? ''),
                'plan_signature' => (string)($draftTaskPlan['signature'] ?? ''),
            ],
            'task_plan_confirmed' => 1,
            'build_summary' => \array_replace(
                \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
                ['task_plan_signature' => (string)($draftTaskPlan['signature'] ?? '')]
            ),
        ];
        $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
            'task_plan_confirmed',
            (string)__('第二阶段任务方案已确认，可以开始执行构建'),
            [
                'operation' => 'task_plan_confirm',
                'details' => ['task_plan_signature' => (string)($draftTaskPlan['signature'] ?? '')],
            ]
        );

        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('第二阶段任务方案已确认'),
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    private function handleTaskPlanSse(): void
    {
        @\ignore_user_abort(true);

        $adminId = (int)$this->getLoginUserId();
        $this->releasePhpSessionLockForSse();
        $sse = new SseWriter();
        $sse->start();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $promptMode = \trim((string)$this->getRequestBodyValue('prompt_mode', ''));
        if ($adminId <= 0 || $publicId === '' || !\in_array($promptMode, ['refine_task_plan', 'rebuild_task_plan', 'detect_bootstrap_task_plan'], true)) {
            $this->sendSseContractError($sse, 'INVALID_PARAMS', (string)__('任务方案流参数无效'), ['public_id', 'prompt_mode']);
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->sendSseContractError($sse, 'SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), ['public_id'], 404);
            $sse->complete(['success' => false]);
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        if ((int)($scope['plan_confirmed'] ?? 0) !== 1) {
            $this->sendSseContractError($sse, 'PLAN_REQUIRED_BEFORE_TASK_PLAN', (string)__('请先确认阶段一方案，再继续调整第二阶段任务方案'), ['public_id', 'plan_confirmed'], 409);
            $sse->complete(['success' => false]);
            return;
        }

        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        if ($buildBlueprint === []) {
            $scope = $this->buildTaskService->ensureTaskScope(
                $scope,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                (string)($scope['workspace_track'] ?? '')
            );
            $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        }

        if ($promptMode === 'detect_bootstrap_task_plan') {
            $this->handleTaskPlanDetectBootstrapSse($sse, $session, $adminId, $scope, $buildBlueprint);
            return;
        }

        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        $targetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        $round = \max(1, (int)$this->getRequestBodyValue('round', 1));
        /** @var array<string, mixed> $sseReq */
        $sseReq = \is_array($scope['_task_plan_sse_request'] ?? null) ? $scope['_task_plan_sse_request'] : [];
        if ($instruction === '') {
            $instruction = \trim((string)($sseReq['instruction'] ?? ''));
        }
        if ($targetScope === '') {
            $targetScope = \trim((string)($sseReq['target_scope'] ?? ''));
        }
        if ($round === 1 && isset($sseReq['round'])) {
            $rr = (int)$sseReq['round'];
            if ($rr > 0) {
                $round = $rr;
            }
        }

        $sse->sendEvent('start', [
            'message' => $promptMode === 'rebuild_task_plan'
                ? (string)__('正在重建第二阶段任务方案')
                : (string)__('正在微调第二阶段任务方案'),
            'prompt_mode' => $promptMode,
            'round' => $round,
            'target_scope' => $targetScope,
        ]);
        $sse->sendEvent('progress', [
            'message' => (string)__('正在整理当前任务方案上下文'),
            'prompt_mode' => $promptMode,
            'progress_percent' => 20,
        ]);

        try {
            $draftPlan = \is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : [];
            $streamBuffer = '';
            $rawChunkInfoBuffer = '';
            // 与阶段一方案流对齐：等 AI 整包 JSON 期间只发 progress + 注释保活，不把原始 token 当 chunk 推给前端；完成后再分块输出 markdown。
            $taskPlanStreamHeartbeat = function () use ($sse): void {
                $sse->sendComment(\str_repeat(' ', 512) . 'pb-task-plan-ai');
            };
            $aiChunkCount = 0;
            $chunkCallback = static function (string $chunk) use (&$streamBuffer, &$rawChunkInfoBuffer, $sse, $promptMode, &$aiChunkCount): void {
                $streamBuffer .= $chunk;
                if (\trim($chunk) === '') {
                    return;
                }
                if ($sse instanceof QueueDbWriter) {
                    $sse->recordRawAiStreamChunk($chunk);
                }
                $rawChunkInfoBuffer .= $chunk;
                while (\mb_strlen($rawChunkInfoBuffer, 'UTF-8') >= 360) {
                    if (!$sse->isAlive()) {
                        $rawChunkInfoBuffer = '';
                        return;
                    }
                    $part = (string)\mb_substr($rawChunkInfoBuffer, 0, 360, 'UTF-8');
                    $rawChunkInfoBuffer = (string)\mb_substr($rawChunkInfoBuffer, 360, null, 'UTF-8');
                    $sse->sendEvent('info', [
                        'message' => $part,
                        'prompt_mode' => $promptMode,
                        'stream_stage' => 'ai_raw_chunk',
                        'chunk' => $part,
                    ]);
                }
                $aiChunkCount++;
                if ($aiChunkCount === 1 || $aiChunkCount % 12 === 0) {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $sse->sendEvent('progress', [
                        'message' => (string)__('AI 正在生成第二阶段任务方案…'),
                        'prompt_mode' => $promptMode,
                        'progress_percent' => 55,
                        'ai_chunk_count' => $aiChunkCount,
                    ]);
                }
            };
            $result = $promptMode === 'rebuild_task_plan'
                ? $this->virtualThemePlanService->rebuildDraftTaskPlan($scope, $buildBlueprint, [
                    'instruction' => $instruction,
                    'round' => $round,
                ], null, $taskPlanStreamHeartbeat)
                : $this->virtualThemePlanService->refineDraftTaskPlan($scope, $buildBlueprint, $draftPlan, [
                    'instruction' => $instruction,
                    'target_scope' => $targetScope,
                    'round' => $round,
                ], null, $taskPlanStreamHeartbeat);

            if ($rawChunkInfoBuffer !== '' && $sse->isAlive()) {
                $sse->sendEvent('info', [
                    'message' => $rawChunkInfoBuffer,
                    'prompt_mode' => $promptMode,
                    'stream_stage' => 'ai_raw_chunk',
                    'chunk' => $rawChunkInfoBuffer,
                ]);
                $rawChunkInfoBuffer = '';
            }

            $markdown = (string)($result['markdown'] ?? '');
            if ($markdown !== '' && $sse->isAlive()) {
                $sse->sendEvent('progress', [
                    'message' => (string)__('AI 已生成完整任务方案，正在输出正文'),
                    'prompt_mode' => $promptMode,
                    'progress_percent' => 72,
                    'stream_stage' => 'markdown_stream',
                ]);
                foreach ($this->chunkStringForSse($markdown, 220) as $mdChunk) {
                    if (\trim($mdChunk) === '') {
                        continue;
                    }
                    if (!$sse->isAlive()) {
                        break;
                    }
                    $sse->sendEvent('chunk', [
                        'content' => $mdChunk,
                        'chunk' => $mdChunk,
                        'prompt_mode' => $promptMode,
                    ]);
                    SchedulerSystem::yieldDelay(5);
                }
            }
            $sse->sendEvent('progress', [
                'message' => (string)__('任务方案正文输出完成，正在保存并生成最终状态'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 92,
            ]);
            $structured = \is_array($result['structured'] ?? null) ? $result['structured'] : [];
            $virtualThemePlan = \is_array($result['virtual_theme_plan'] ?? null) ? $result['virtual_theme_plan'] : [];
            $taskPlanGenerationSource = (string)($result['generation_source'] ?? '');
            if ($this->shouldRejectTaskPlanGenerationSource($scope, $taskPlanGenerationSource)) {
                throw new \RuntimeException((string)__('第二阶段任务方案生成失败：当前结果不是 AI 生成，请检查 AI 服务后重试'));
            }
            $taskPlanDirectoryTree = \is_array($structured['task_directory_tree'] ?? null) ? $structured['task_directory_tree'] : [];
            $scopePatch = [
                'virtual_theme_plan' => [
                    'draft' => $virtualThemePlan,
                    'draft_markdown' => $markdown,
                    'draft_generated_at' => \date('Y-m-d H:i:s'),
                    'confirmed' => \is_array($scope['virtual_theme_plan']['confirmed'] ?? null) ? $scope['virtual_theme_plan']['confirmed'] : [],
                    'confirmed_markdown' => (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''),
                    'confirmed_at' => (string)($scope['virtual_theme_plan']['confirmed_at'] ?? ''),
                    'confirmed_signature' => (string)($scope['virtual_theme_plan']['confirmed_signature'] ?? ''),
                    'plan_signature' => (string)($virtualThemePlan['signature'] ?? ''),
                    'last_prompt_mode' => $promptMode,
                    'last_target_scope' => $targetScope,
                    'last_round' => $round,
                ],
                'task_plan_structured' => $structured,
                'task_plan_directory_tree' => $taskPlanDirectoryTree,
                'task_plan_summary' => [
                    'signature' => (string)($virtualThemePlan['signature'] ?? ''),
                    'shared_task_count' => \count(\is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : []),
                    'page_task_count' => \array_sum(\array_map(static fn($items): int => \is_array($items) ? \count($items) : 0, \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [])),
                    'prompt_mode' => $promptMode,
                ],
                'task_plan_confirmed' => 0,
            ];
            if ($promptMode === 'rebuild_task_plan') {
                $scopePatch['task_plan_rebuild_summary'] = \is_array($result['rebuild_summary'] ?? null) ? $result['rebuild_summary'] : [];
            } else {
                $scopePatch['task_plan_change_scope_report'] = \is_array($result['change_scope_report'] ?? null) ? $result['change_scope_report'] : [];
            }
            $scopePatch['_task_plan_sse_request'] = [];
            $sse->sendEvent('progress', [
                'message' => (string)__('正在持久化任务方案草案'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 95,
            ]);
            $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

            $sse->sendEvent('progress', [
                'message' => (string)__('正在写入工作区事件并整理最终状态'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 97,
            ]);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
                $promptMode === 'rebuild_task_plan' ? 'task_plan_rebuilt' : 'task_plan_refined',
                $promptMode === 'rebuild_task_plan'
                    ? (string)__('第二阶段任务方案已重建，请确认后再进入构建')
                    : (string)__('第二阶段任务方案已微调，请确认后再进入构建'),
                [
                    'operation' => $promptMode,
                    'details' => [
                        'target_scope' => $targetScope,
                        'round' => $round,
                        'task_plan_signature' => (string)($virtualThemePlan['signature'] ?? ''),
                    ],
                ]
            );

            $sse->sendEvent('progress', [
                'message' => (string)__('第二阶段任务方案已生成，正在输出完成标记'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 99,
            ]);

            $state = $this->buildWorkspaceEventStatePayload($this->buildWorkspaceState($fresh, $adminId, 80, true));
            $sse->complete([
                'success' => true,
                'message' => $promptMode === 'rebuild_task_plan'
                    ? (string)__('第二阶段任务方案重建完成')
                    : (string)__('第二阶段任务方案微调完成'),
                'prompt_mode' => $promptMode,
                'task_plan' => [
                    'markdown' => $markdown,
                    'structured' => $structured,
                    'virtual_theme_plan' => $virtualThemePlan,
                ],
                'state' => $state,
            ]);
        } catch (\Throwable $throwable) {
            $sse->sendError($throwable->getMessage(), $this->inferThrowableHttpCode($throwable));
            $sse->complete([
                'success' => false,
                'message' => $throwable->getMessage(),
                'prompt_mode' => $promptMode,
            ]);
        }
    }

    /**
     * 阶段二手风琴展开：SSE 检测是否已有虚拟主题任务方案；已有则流式回填，无则按阶段一确认方案生成并落库后再输出（对齐《AI建站中台-计划》§1A 第二阶段即时行为）。
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     */
    private function handleTaskPlanDetectBootstrapSse(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        array $buildBlueprint
    ): void {
        $promptMode = 'detect_bootstrap_task_plan';
        $streamBuffer = '';
        $rawChunkInfoBuffer = '';
        try {
            $sse->sendEvent('start', [
                'message' => (string)__('正在检测第二阶段任务方案'),
                'prompt_mode' => $promptMode,
            ]);
            $sse->sendEvent('progress', [
                'message' => (string)__('正在连接任务方案流并检查会话草案'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 12,
            ]);

            // detect_bootstrap：不再使用会话内已存草案/确认稿作为“缓存快路径”，始终走 AI 生成（与前端期望一致）。
            $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
            if ($executionBlueprint === []) {
                $this->sendSseContractError($sse, 'EXECUTION_BLUEPRINT_REQUIRED', (string)__('缺少已确认执行蓝图，请重新生成并确认方案'), ['public_id', 'execution_blueprint'], 409);
                $sse->complete(['success' => false, 'prompt_mode' => $promptMode]);
                return;
            }

            $sse->sendEvent('progress', [
                'message' => (string)__('正在通过 AI 根据阶段一确认方案生成第二阶段任务方案'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 28,
            ]);
            $sse->sendEvent('progress', [
                'message' => (string)__('正在调用 AI 生成任务方案，生成期间请保持连接'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 34,
            ]);

            $scope = $this->buildTaskService->ensureTaskScope(
                $scope,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                (string)($scope['workspace_track'] ?? '')
            );
            $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
            // 保活必须用 SSE 注释行：上游 AI 可能数十秒无 token，Nginx proxy_read_timeout 等会断客端连接；
            // 禁止在等完整 JSON 阶段 sendEvent(progress/info) 透传模型原始流：既拉长可读字节间隔、又可能把半截 JSON 推给浏览器；完成后再统一 chunk markdown。
            $taskPlanStreamHeartbeat = function () use ($sse): void {
                $sse->sendComment(\str_repeat(' ', 512) . 'pb-task-plan-ai');
            };
            $aiChunkCount = 0;
            $chunkCallback = static function (string $chunk) use (&$rawChunkInfoBuffer, $sse, $promptMode, &$aiChunkCount): void {
                if (\trim($chunk) === '') {
                    return;
                }
                $queueMode = $sse instanceof QueueDbWriter;
                if ($sse instanceof QueueDbWriter) {
                    $sse->recordRawAiStreamChunk($chunk);
                }
                if (!$queueMode) {
                    $rawChunkInfoBuffer .= $chunk;
                    while (\mb_strlen($rawChunkInfoBuffer, 'UTF-8') >= 360) {
                        if (!$sse->isAlive()) {
                            $rawChunkInfoBuffer = '';
                            return;
                        }
                        $part = (string)\mb_substr($rawChunkInfoBuffer, 0, 360, 'UTF-8');
                        $rawChunkInfoBuffer = (string)\mb_substr($rawChunkInfoBuffer, 360, null, 'UTF-8');
                        $sse->sendEvent('info', [
                            'message' => $part,
                            'prompt_mode' => $promptMode,
                            'stream_stage' => 'ai_raw_chunk',
                            'chunk' => $part,
                        ]);
                    }
                }
                $aiChunkCount++;
                if ($aiChunkCount === 1 || $aiChunkCount % 12 === 0) {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $sse->sendEvent('progress', [
                        'message' => (string)__('AI 正在持续生成任务方案，请保持连接'),
                        'prompt_mode' => $promptMode,
                        'progress_percent' => 55,
                        'ai_chunk_count' => $aiChunkCount,
                    ]);
                }
            };
            $artifacts = $this->virtualThemePlanService->buildTaskPlanArtifactsStream(
                $scope,
                $buildBlueprint,
                $chunkCallback,
                $taskPlanStreamHeartbeat
            );
            if ($rawChunkInfoBuffer !== '' && !($sse instanceof QueueDbWriter) && $sse->isAlive()) {
                $sse->sendEvent('info', [
                    'message' => $rawChunkInfoBuffer,
                    'prompt_mode' => $promptMode,
                    'stream_stage' => 'ai_raw_chunk',
                    'chunk' => $rawChunkInfoBuffer,
                ]);
                $rawChunkInfoBuffer = '';
            }
            $taskPlanGenerationSource = (string)($artifacts['generation_source'] ?? '');
            if ((int)($scope['fake_mode'] ?? 0) !== 1 && $taskPlanGenerationSource !== 'ai') {
                throw new \RuntimeException('Task plan generation failed: AI result unavailable.');
            }
            $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];
            $markdown = (string)($artifacts['markdown'] ?? '');
            $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
            /*
            $deterministicFallbackAllowed = false;
            try {
                $artifacts = $this->virtualThemePlanService->buildTaskPlanArtifacts(
                    $scope,
                    $buildBlueprint
                                'message' => (string)__('AI 正在持续生成任务方案，请保持连接'),
                );
            } catch (\Throwable $streamThrowable) {
                // 流式阶段失败时回退到 deterministic 方案，保证 SSE 能给出稳定可确认草案。
                $sse->sendEvent('progress', [
                    'message' => (string)__('AI 流式任务方案生成失败，正在回退为稳定草案'),
                    'prompt_mode' => $promptMode,
                    'progress_percent' => 46,
                ]);
                $fallbackScope = \array_replace($scope, ['fake_mode' => 1]);
                $artifacts = $this->virtualThemePlanService->buildTaskPlanArtifacts(
                    $fallbackScope,
                    $buildBlueprint /*
                
                $deterministicFallbackAllowed = true;
            }
            $taskPlanGenerationSource = (string)($artifacts['generation_source'] ?? '');
            if ($this->shouldRejectTaskPlanGenerationSource($scope, $taskPlanGenerationSource, $deterministicFallbackAllowed)) {
                throw new \RuntimeException((string)__('第二阶段任务方案生成失败：未获取到 AI 生成结果，请检查 AI 服务配置后重试'));
            }
            */
            $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];
            $markdown = (string)($artifacts['markdown'] ?? '');
            $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];

            if ($sse->isAlive()) {
                $sse->sendEvent('progress', [
                    'message' => (string)__('AI 已生成完整任务方案，正在输出正文'),
                    'prompt_mode' => $promptMode,
                    'progress_percent' => 72,
                    'stream_stage' => 'markdown_stream',
                ]);
            }

            if ($markdown !== '') {
                foreach ($this->chunkStringForSse($markdown, 220) as $chunk) {
                    if (\trim($chunk) === '') {
                        continue;
                    }
                    if (!$sse->isAlive()) {
                        break;
                    }
                    $sse->sendEvent('chunk', [
                        'content' => $chunk,
                        'chunk' => $chunk,
                        'prompt_mode' => $promptMode,
                    ]);
                    SchedulerSystem::yieldDelay(5);
                }
            }
            $sse->sendEvent('progress', [
                'message' => (string)__('任务方案正文输出完成，正在保存并生成最终状态'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 92,
            ]);

            $taskPlanDirectoryTree = \is_array($structured['task_directory_tree'] ?? null) ? $structured['task_directory_tree'] : [];
            $scopePatch = [
                'build_blueprint' => $buildBlueprint,
                'build_tasks' => \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [],
                'virtual_theme_plan' => [
                    'draft' => $virtualThemePlan,
                    'draft_markdown' => $markdown,
                    'draft_generated_at' => \date('Y-m-d H:i:s'),
                    'confirmed' => \is_array($scope['virtual_theme_plan']['confirmed'] ?? null) ? $scope['virtual_theme_plan']['confirmed'] : [],
                    'confirmed_markdown' => (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''),
                    'confirmed_at' => (string)($scope['virtual_theme_plan']['confirmed_at'] ?? ''),
                    'plan_signature' => (string)($virtualThemePlan['signature'] ?? ''),
                ],
                'task_plan_markdown' => $markdown,
                'task_plan_generated_at' => \date('Y-m-d H:i:s'),
                'task_plan_structured' => $structured,
                'task_plan_directory_tree' => $taskPlanDirectoryTree,
                'task_plan_summary' => [
                    'signature' => (string)($virtualThemePlan['signature'] ?? ''),
                    'shared_task_count' => \count(\is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : []),
                    'page_task_count' => \array_sum(\array_map(static fn($items): int => \is_array($items) ? \count($items) : 0, \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [])),
                    'source' => 'task_plan_sse',
                    'generation_source' => $taskPlanGenerationSource,
                ],
                'task_plan_confirmed' => 0,
            ];
            $sse->sendEvent('progress', [
                'message' => (string)__('正在持久化任务方案草案'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 95,
            ]);
            $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $sse->sendEvent('progress', [
                'message' => (string)__('正在写入工作区事件并整理最终状态'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 97,
            ]);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
                'task_plan_generated',
                (string)__('已生成第二阶段任务方案，请确认后再开始执行构建'),
                [
                    'operation' => 'task_plan',
                    'details' => [
                        'task_plan_signature' => (string)($virtualThemePlan['signature'] ?? ''),
                        'task_count' => \count(\is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : []),
                        'source' => 'detect_bootstrap_sse',
                    ],
                ]
            );
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                ['status' => 'done', 'message' => (string)__('第二阶段任务方案已生成')],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );

            $sse->sendEvent('progress', [
                'message' => (string)__('第二阶段任务方案已生成，正在输出完成标记'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 99,
            ]);

            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = $this->buildWorkspaceEventStatePayload($this->buildWorkspaceState($fresh, $adminId, 80, true));
            $sse->complete([
                'success' => true,
                'message' => (string)__('第二阶段任务方案已生成，请确认后再进入构建'),
                'prompt_mode' => $promptMode,
                'phase' => 'virtual_theme_plan_generated',
                'task_plan' => [
                    'markdown' => $markdown,
                    'structured' => $structured,
                    'virtual_theme_plan' => $virtualThemePlan,
                ],
                'state' => $state,
            ]);
        } catch (\Throwable $throwable) {
            $this->updateActiveOperation(
                $session,
                $adminId,
                ['status' => 'error', 'message' => $throwable->getMessage()],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'operation_failed',
                (string)__('第二阶段任务方案生成失败：%{message}', ['message' => $throwable->getMessage()]),
                ['operation' => 'task_plan', 'details' => ['exception' => $throwable->getMessage()]],
                AiSiteAgentSessionEvent::LEVEL_ERROR
            );
            $streamTail = \trim((string)\mb_substr($streamBuffer, -900, null, 'UTF-8'));
            $errorMessage = $throwable->getMessage();
            if ($streamTail !== '') {
                $errorMessage .= "\n\n[stream_tail]\n" . $streamTail;
            }
            $sse->sendError($errorMessage, $this->inferThrowableHttpCode($throwable));
            $sse->complete([
                'success' => false,
                'message' => $errorMessage,
                'prompt_mode' => $promptMode,
            ]);
            if ($sse instanceof QueueDbWriter) {
                throw $throwable;
            }
        }
    }

    private function handleResumeBuild(): string
    {
        return $this->handleStartBuild(true);
    }

    private function handleStartBuild(bool $isResume = false): string
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
        if (isset($scopePatch['page_types']) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $scopePatch)) {
            $scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        $siteProfileManual = \is_array($scopePatch['site_profile_manual'] ?? null) ? $scopePatch['site_profile_manual'] : [];
        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale', 'plan_locale'] as $manualField) {
            if (\array_key_exists($manualField, $scopePatch) && !\array_key_exists($manualField, $siteProfileManual)) {
                $siteProfileManual[$manualField] = true;
            }
        }
        if ($siteProfileManual !== []) {
            $scopePatch['site_profile_manual'] = $siteProfileManual;
        }
        $mergedScope = $this->scopeCompatibilityService->normalizeScope(\array_replace($session->getScopeArray(), $scopePatch));
        if ((int)($mergedScope['task_plan_confirmed'] ?? 0) !== 1) {
            return $this->jsonError(
                'TASK_PLAN_REQUIRED_BEFORE_BUILD',
                (string)__('请先确认第二阶段任务方案，再开始执行构建'),
                ['public_id', 'task_plan_confirmed']
            );
        }
        if ($isResume) {
            $pendingSummary = $this->buildTaskService->summarize($mergedScope);
            $pendingCount = (int)($pendingSummary['pending'] ?? 0);
            if ($pendingCount <= 0) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => (string)__('当前没有待继续的构建任务'),
                    'data' => $this->buildWorkspaceState($session, $adminId, 80, true),
                ]);
            }
        }
        $startStage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        if ($startStage !== AiSiteAgentSession::STAGE_VISUAL_EDIT) {
            $startStage = AiSiteAgentSession::STAGE_PLAN;
        }

        $startResult = $this->startOperation(
            $session,
            $adminId,
            'build',
            $startStage,
            $scopePatch,
            '',
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
        );
        if (!empty($startResult['success'])) {
            $startResult['start_sse'] = true;
        }
        return $this->fetchJson($startResult);
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

    private function handleStartRefineComponent(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $componentCode = \trim((string)$this->getRequestBodyValue('component_code', ''));
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        $componentLabel = \trim((string)$this->getRequestBodyValue('component_label', ''));

        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $componentCode === '' || $instruction === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_REFINE_COMPONENT);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            return $this->jsonError('INVALID_PARAMS', (string)__('所选页面类型不在当前工作区中'), self::PARAMS_REGENERATE);
        }

        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $sectionRefinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];
        $sectionRefinements[$componentCode] = $instruction;
        $virtualPage['section_refinements'] = $sectionRefinements;
        $virtualPages[$pageType] = $virtualPage;

        $label = $componentLabel !== '' ? $componentLabel : $componentCode;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'component_refine_requested',
            (string)__('已记录区块微调：%{component}', ['component' => $label]),
            [
                'operation' => 'regenerate_page',
                'page_type' => $pageType,
                'details' => [
                    'component_code' => $componentCode,
                    'instruction' => $instruction,
                ],
            ]
        );

        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            'regenerate_page',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            ['virtual_pages_by_type' => $virtualPages],
            $pageType,
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
        ));
    }

    private function handleBlockRegenerateSse(bool $refine): void
    {
        @\ignore_user_abort(true);
        $this->releasePhpSessionLockForSse();

        $sse = new SseWriter();
        $sse->start();

        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $componentCode = \trim((string)$this->getRequestBodyValue('component_code', ''));
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));

        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $componentCode === '' || ($refine && $instruction === '')) {
            $this->sendSseContractError(
                $sse,
                'INVALID_PARAMS',
                (string)__('参数无效'),
                $refine ? self::PARAMS_REFINE_COMPONENT : self::PARAMS_REGENERATE
            );
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->sendSseContractError($sse, 'SESSION_NOT_FOUND', 'Session not found or inaccessible', self::PARAMS_PUBLIC_ID, 404);
            $sse->complete(['success' => false]);
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            $this->sendSseContractError($sse, 'INVALID_PAGE_TYPE', 'Page type is not in the current workspace', self::PARAMS_REGENERATE, 400);
            $sse->complete(['success' => false]);
            return;
        }

        $stageCode = AiSiteAgentSession::STAGE_VISUAL_EDIT;
        $originalActiveOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $originalWorkspaceStatus = $this->scopeCompatibilityService->normalizeWorkspaceStatus((string)($scope['workspace_status'] ?? ''));
        $preserveOriginalOperation = \in_array(\trim((string)($originalActiveOperation['status'] ?? '')), ['queued', 'running'], true);

        if ($refine) {
            $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
            $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
            $sectionRefinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];
            $sectionRefinements[$componentCode] = $instruction;
            $virtualPage['section_refinements'] = $sectionRefinements;
            $virtualPages[$pageType] = $virtualPage;
            $scope['virtual_pages_by_type'] = $virtualPages;
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        }

        $operationLabel = $refine ? 'block_refine' : 'block_regenerate';
        $sse->sendEvent('start', [
            'operation' => $operationLabel,
            'page_type' => $pageType,
            'component_code' => $componentCode,
            'message' => $refine ? 'Starting AI block refine' : 'Starting AI block regenerate',
        ]);

        $this->registerAiChunkForwarder($sse, $session, $adminId, $stageCode, $operationLabel);

        try {
            $result = $this->runRegenerateBlockOperation(
                $sse,
                $session,
                $adminId,
                $pageType,
                $componentCode,
                $refine ? $instruction : ''
            );
            if ($preserveOriginalOperation) {
                $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $freshScope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
                $freshScope['active_operation'] = $originalActiveOperation;
                $freshScope['workspace_status'] = $originalWorkspaceStatus;
                $this->sessionService->replaceScope($fresh->getId(), $adminId, $freshScope);
            }
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $sse->complete([
                'success' => true,
                'operation' => $operationLabel,
                'message' => $refine ? 'AI block refine completed' : 'AI block regenerate completed',
                'data' => $result,
                'state' => $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true),
                    [$pageType]
                ),
            ]);
        } catch (\Throwable $throwable) {
            if ($preserveOriginalOperation) {
                $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $freshScope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
                $freshScope['active_operation'] = $originalActiveOperation;
                $freshScope['workspace_status'] = $originalWorkspaceStatus;
                $this->sessionService->replaceScope($fresh->getId(), $adminId, $freshScope);
            }
            $sse->sendError($throwable->getMessage(), $this->inferThrowableHttpCode($throwable));
            $sse->complete([
                'success' => false,
                'operation' => $operationLabel,
                'message' => $throwable->getMessage(),
            ]);
        } finally {
            $this->clearAiChunkForwarder();
        }
    }

    private function resolveSectionTaskKeyForComponent(array $scope, string $pageType, string $componentCode): array
    {
        $componentCode = \trim($componentCode);
        if ($componentCode === '') {
            return ['task_key' => '', 'section_code' => '', 'shared_region' => ''];
        }

        $normalizedBlockId = $componentCode;
        if (\str_starts_with($normalizedBlockId, 'content/')) {
            $normalizedBlockId = \substr($normalizedBlockId, \strlen('content/'));
        }

        $pageSlug = \str_replace('_', '-', \trim($pageType));
        if (
            $componentCode === 'header/ai-site-header'
            || $normalizedBlockId === 'header-ai-site-header'
            || $normalizedBlockId === $pageSlug . '-site-header'
            || \str_ends_with($normalizedBlockId, '-site-header')
        ) {
            return [
                'task_key' => 'shared:header',
                'section_code' => $normalizedBlockId !== '' ? $normalizedBlockId : 'shared:header',
                'shared_region' => 'header',
            ];
        }
        if (
            $componentCode === 'footer/ai-site-footer'
            || $normalizedBlockId === 'footer-ai-site-footer'
            || $normalizedBlockId === $pageSlug . '-site-footer'
            || \str_ends_with($normalizedBlockId, '-site-footer')
        ) {
            return [
                'task_key' => 'shared:footer',
                'section_code' => $normalizedBlockId !== '' ? $normalizedBlockId : 'shared:footer',
                'shared_region' => 'footer',
            ];
        }

        foreach ($this->buildTaskService->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $definition = $this->buildTaskService->getTaskDefinition($scope, $taskKey);
            if (!\is_array($definition)) {
                continue;
            }
            $sectionCode = \trim((string)($definition['section_code'] ?? ''));
            if ($sectionCode === '') {
                continue;
            }
            $blockId = \str_replace(['content/', '/'], ['', '-'], $sectionCode);
            if ($componentCode === $sectionCode || $componentCode === $blockId || $normalizedBlockId === $blockId) {
                return ['task_key' => $taskKey, 'section_code' => $sectionCode, 'shared_region' => ''];
            }
        }

        $fallbackSectionCode = \str_starts_with($componentCode, 'content/') ? $componentCode : ('content/' . $componentCode);
        return [
            'task_key' => 'page:' . $pageType . ':' . $fallbackSectionCode,
            'section_code' => $fallbackSectionCode,
            'shared_region' => '',
        ];
    }

    private function runRegenerateBlockOperation(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $pageType,
        string $componentCode,
        string $instruction = ''
    ): array {
        $this->assertActiveStreamLeaseAlive($session, $adminId);
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $scope = $this->buildTaskService->ensureTaskScope($scope, \is_array($scope['website_profile']) ? $scope['website_profile'] : [], (string)($scope['workspace_track'] ?? ''));

        $resolvedTask = $this->resolveSectionTaskKeyForComponent($scope, $pageType, $componentCode);
        $taskKey = (string)($resolvedTask['task_key'] ?? '');
        $sectionCode = (string)($resolvedTask['section_code'] ?? '');
        $sharedRegion = \trim((string)($resolvedTask['shared_region'] ?? ''));
        if ($taskKey === '' || ($sharedRegion === '' && $sectionCode === '')) {
            throw new \RuntimeException((string)__('Unable to resolve block task for the current component'));
        }

        $scope = $this->buildTaskService->resetTaskForRetry($scope, $taskKey);
        $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $pageLabel = (string)(Page::getPageTypes()[$pageType] ?? $pageType);

        if ($instruction !== '') {
            if ($sharedRegion !== '') {
                $scope['shared_component_refinements'] = \is_array($scope['shared_component_refinements'] ?? null) ? $scope['shared_component_refinements'] : [];
                $scope['shared_component_refinements'][$sharedRegion] = $instruction;
            } else {
                $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($this->scopeCompatibilityService->resolveScopedPageTypes($scope), $scope);
                $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
                $sectionRefinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];
                $sectionRefinements[$sectionCode] = $instruction;
                $virtualPage['section_refinements'] = $sectionRefinements;
                $virtualPages[$pageType] = $virtualPage;
                $scope['virtual_pages_by_type'] = $virtualPages;
            }
        }

        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'block_regenerate',
            __('正在重建区块：%{page}', ['page' => $pageLabel]),
            35,
            $pageType
        );

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);

        if ($sharedRegion !== '') {
            $sharedComponent = $pageComponentGenerationService->generateSharedComponent(
                $sharedRegion,
                $scope['website_profile'],
                $scope,
                '',
                true
            );
            $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
            $scope['shared_components'][$sharedRegion] = $sharedComponent;
            if (\is_array($scope['shared_component_refinements'] ?? null)) {
                unset($scope['shared_component_refinements'][$sharedRegion]);
            }
            $dummySection = ['block_id' => ''];

            if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
                $pageTypesAll = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
                $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypesAll, $scope);
                $now = \date('Y-m-d H:i:s');
                foreach ($pageTypesAll as $pt) {
                    $blueprint = $this->pageBlueprintService->buildPageBlueprint($pt, $scope, $scope['website_profile']);
                    $row = \is_array($virtualPages[$pt] ?? null) ? $virtualPages[$pt] : [];
                    $row['title'] = (string)($blueprint['page_title'] ?? ($row['title'] ?? ''));
                    $row['ai_description'] = (string)($blueprint['ai_description'] ?? ($row['ai_description'] ?? ''));
                    $row['meta_title'] = (string)($blueprint['meta_title'] ?? ($row['meta_title'] ?? ''));
                    $row['meta_description'] = (string)($blueprint['meta_description'] ?? ($row['meta_description'] ?? ''));
                    $row['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($row['meta_keywords'] ?? ''));
                    $row['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
                    $row['blocks'] = $this->composeHtmlBlocksForPage(
                        $pt,
                        \is_array($row['blocks'] ?? null) ? $row['blocks'] : [],
                        $scope['shared_components'],
                        $dummySection,
                        $scope['website_profile'],
                        $scope
                    );
                    $row['last_generated_at'] = $now;
                    $virtualPages[$pt] = $row;
                }
                $scope['virtual_pages_by_type'] = $virtualPages;
                $scope['preview_page_type'] = $pageType;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, [
                    'page_type' => $pageType,
                    'section_code' => $sectionCode,
                    'shared_region' => $sharedRegion,
                ]);
                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                    \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                    $scope['website_profile'],
                    $pageTypesAll,
                    [],
                    $virtualPages
                );
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            } else {
                $pageTypesAll = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
                $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypesAll);
                $this->virtualThemeService->saveGeneratedSharedComponent((int)($scope['virtual_theme_id'] ?? 0), $sharedComponent);
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypesAll, $pageTypeLayouts, $sharedComponent);
                $scope['page_type_layouts'] = $pageTypeLayouts;
                $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypesAll, \array_replace($scope, [
                    'page_type_layouts' => $pageTypeLayouts,
                    'virtual_pages_by_type' => $scope['virtual_pages_by_type'] ?? [],
                ]));
                $now = \date('Y-m-d H:i:s');
                foreach ($pageTypesAll as $pt) {
                    if (!isset($virtualPages[$pt]) || !\is_array($virtualPages[$pt])) {
                        continue;
                    }
                    $virtualPages[$pt]['last_generated_at'] = $now;
                }
                $scope['virtual_pages_by_type'] = $virtualPages;
                $scope['preview_page_type'] = $pageType;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, [
                    'page_type' => $pageType,
                    'section_code' => $sectionCode,
                    'shared_region' => $sharedRegion,
                ]);
                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                    \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                    $scope['website_profile'],
                    $pageTypesAll,
                    $pageTypeLayouts,
                    $virtualPages
                );
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            }
        } else {
            $sectionComponent = $pageComponentGenerationService->generatePageSection($pageType, $sectionCode, $scope['website_profile'], $scope);

            if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
                $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
                $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
                $blueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $scope['website_profile']);
                $row = $virtualPages[$pageType] ?? [];
                $row['title'] = (string)($blueprint['page_title'] ?? ($row['title'] ?? ''));
                $row['ai_description'] = (string)($blueprint['ai_description'] ?? ($row['ai_description'] ?? ''));
                $row['meta_title'] = (string)($blueprint['meta_title'] ?? ($row['meta_title'] ?? ''));
                $row['meta_description'] = (string)($blueprint['meta_description'] ?? ($row['meta_description'] ?? ''));
                $row['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($row['meta_keywords'] ?? ''));
                $row['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
                $row['blocks'] = $this->composeHtmlBlocksForPage(
                    $pageType,
                    \is_array($row['blocks'] ?? null) ? $row['blocks'] : [],
                    \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [],
                    $this->htmlBlocksBuildService->buildGeneratedSectionBlock($sectionComponent),
                    $scope['website_profile'],
                    $scope
                );
                $row['last_generated_at'] = \date('Y-m-d H:i:s');
                $virtualPages[$pageType] = $row;
                $scope['virtual_pages_by_type'] = $virtualPages;
                $scope['preview_page_type'] = $pageType;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);
                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                    \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                    $scope['website_profile'],
                    [$pageType],
                    [],
                    [$pageType => $row]
                );
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            } else {
                $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
                $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
                $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($pageTypeLayouts[$pageType] ?? [], $pageType);
                $this->virtualThemeService->saveGeneratedContentComponent((int)($scope['virtual_theme_id'] ?? 0), $pageType, $sectionComponent);
                $layout = $this->virtualThemeService->mergeGeneratedContentIntoLayout($layout, $sectionComponent);
                $this->virtualThemeService->saveGeneratedPageLayout((int)($scope['virtual_theme_id'] ?? 0), $pageType, $layout);
                $pageTypeLayouts[$pageType] = $layout;

                $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, \array_replace($scope, [
                    'page_type_layouts' => $pageTypeLayouts,
                    'virtual_pages_by_type' => $scope['virtual_pages_by_type'] ?? [],
                ]));
                $scope['page_type_layouts'] = $pageTypeLayouts;
                $scope['virtual_pages_by_type'] = $virtualPages;
                $scope['preview_page_type'] = $pageType;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);
                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                    \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                    $scope['website_profile'],
                    [$pageType],
                    [$pageType => $layout],
                    [$pageType => $virtualPages[$pageType] ?? []]
                );
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            }
        }

        $scope['build_summary'] = \array_replace(
            \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
            ['task_summary' => $this->buildTaskService->summarize($scope)]
        );
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true),
            [$pageType]
        );

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'task_completed',
            (string)__('区块任务已完成：%{page}', ['page' => $pageLabel]),
            [
                'operation' => $instruction !== '' ? 'block_refine' : 'block_regenerate',
                'page_type' => $pageType,
                'details' => ['task_key' => $taskKey, 'section_code' => $sectionCode],
            ]
        );

        return [
            'message' => $instruction !== '' ? (string)__('区块微调完成') : (string)__('区块重建完成'),
            'page_type' => $pageType,
            'section_code' => $sectionCode,
            'state' => $state,
        ];
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
            if ((int)($state['site_ready'] ?? 1) !== 1) {
                return $this->fetchJson(['success' => false, 'message' => __('域名尚未就绪，请先完成域名流程后再发布。')]);
            }
            if ((int)($state['plan_confirmed'] ?? 0) !== 1) {
                return $this->fetchJson(['success' => false, 'message' => __('请先确认阶段一方案，再进入发布流程。')]);
            }
            if ((int)($state['task_plan_confirmed'] ?? 0) !== 1) {
                return $this->fetchJson(['success' => false, 'message' => __('请先确认第二阶段任务方案，再进入发布流程。')]);
            }
            return $this->fetchJson(['success' => false, 'message' => __('当前工作区尚未准备好发布，请先完成页面生成与编辑。')]);
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

    private function handleUpdateBlockConfig(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $blockId = \trim((string)$this->getRequestBodyValue('block_id', ''));
        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $blockId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('参数无效'), self::PARAMS_UPDATE_BLOCK);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('会话不存在或无权访问'), self::PARAMS_PUBLIC_ID);
        }

        $error = '';
        $blockConfig = $this->getRequestJsonObject('block_config', $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            return $this->jsonError('INVALID_PARAMS', (string)__('所选页面类型不在当前工作区中'), self::PARAMS_UPDATE_BLOCK);
        }

        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $blocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        $updatedBlock = null;
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

        foreach ($blocks as $index => $block) {
            if (!\is_array($block) || \trim((string)($block['block_id'] ?? '')) !== $blockId) {
                continue;
            }

            $updatedBlock = $this->htmlBlocksBuildService->rebuildBlock(
                $block,
                $websiteProfile,
                $scope,
                $blockConfig
            );
            break;
        }

        if ($updatedBlock === null) {
            return $this->fetchJson(['success' => false, 'message' => __('未找到要更新的区块')]);
        }

        $lastGeneratedAt = \date('Y-m-d H:i:s');
        $virtualPages = $this->replaceCurrentPageBlockInVirtualPages(
            $virtualPages,
            $pageType,
            $blockId,
            $updatedBlock,
            $lastGeneratedAt
        );
        $scope['virtual_pages_by_type'] = $virtualPages;
        $scope['build_summary'] = \array_replace(
            \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
            ['task_summary' => $this->buildTaskService->summarize($scope)]
        );
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'block_config_updated',
            (string)__('区块配置已更新：%{block}', ['block' => $blockId]),
            [
                'page_type' => $pageType,
                'details' => [
                    'block_id' => $blockId,
                    'config_keys' => \array_values(\array_map('strval', \array_keys($blockConfig))),
                ],
            ]
        );

        return $this->fetchJson([
            'success' => true,
            'message' => __('区块已更新'),
            'block' => $this->htmlBlocksBuildService->stripServerOnlyBlock($updatedBlock),
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    /**
     * Block-level edits are intentionally scoped to the selected page/block.
     *
     * @param array<string,array<string,mixed>> $virtualPages
     * @param array<string,mixed> $updatedBlock
     * @return array<string,array<string,mixed>>
     */
    private function replaceCurrentPageBlockInVirtualPages(
        array $virtualPages,
        string $pageType,
        string $blockId,
        array $updatedBlock,
        string $lastGeneratedAt
    ): array {
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $blocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];

        foreach ($blocks as $index => $block) {
            if (!\is_array($block) || \trim((string)($block['block_id'] ?? '')) !== $blockId) {
                continue;
            }

            $blocks[$index] = $updatedBlock;
            break;
        }

        $virtualPage['blocks'] = \array_values($blocks);
        $virtualPage['last_generated_at'] = $lastGeneratedAt;
        $virtualPages[$pageType] = $virtualPage;

        return $virtualPages;
    }

    /**
     * 只读：返回当前 buildWorkspaceState，用于前端刷新队列信息等，不写 scope、不写事件。
     */
    private function handleWorkspaceSnapshot(): string
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
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson([
            'success' => true,
            'data' => $this->buildWorkspaceState($fresh, $adminId, 24, false),
        ]);
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

        // 前端 Tab 可能对应已勾选但尚未完成 mergeScope（防抖）的页面类型；若不写入 scope，resolvePreviewPageType 会回退到首页，预览看似「切不过去」。
        if ($requestedPageType !== '' && \array_key_exists($requestedPageType, Page::getPageTypes())) {
            $scopeArr = $session->getScopeArray();
            $existingTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scopeArr);
            if (!\in_array($requestedPageType, $existingTypes, true)) {
                $existingTypes[] = $requestedPageType;
                $this->sessionService->mergeScope($session->getId(), $adminId, ['page_types' => $existingTypes]);
                $session = $this->sessionService->loadByPublicId($publicId, $adminId) ?? $session;
            }
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
            $this->scopeCompatibilityService->resolveScopedPageTypes(\is_array($state['scope'] ?? null) ? $state['scope'] : [])
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
        if ((int)($state['plan_confirmed'] ?? 0) !== 1) {
            return $this->fetchJson(['success' => false, 'message' => __('请先确认阶段一方案，再检查发布条件。')]);
        }
        if ((int)($state['task_plan_confirmed'] ?? 0) !== 1) {
            return $this->fetchJson(['success' => false, 'message' => __('请先确认第二阶段任务方案，再检查发布条件。')]);
        }
        $virtualPages = \is_array($state['virtual_pages_by_type']) ? $state['virtual_pages_by_type'] : [];
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $track = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $checkItems = [
            ['key' => 'draft_website', 'label' => __('草稿站点已创建'), 'ok' => (int)$state['draft_website_id'] > 0, 'value' => (int)$state['draft_website_id']],
            [
                'key' => 'virtual_theme',
                'label' => $track === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
                    ? __('HTML 区块轨（无需虚拟主题）')
                    : __('虚拟主题已生成'),
                'ok' => $track === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
                    ? true
                    : ((int)$state['virtual_theme_id'] > 0),
                'value' => (int)$state['virtual_theme_id'],
            ],
            ['key' => 'website_profile', 'label' => __('网站级资料已齐备'), 'ok' => \trim((string)($state['website_profile']['site_title'] ?? '')) !== '', 'value' => $state['website_profile']],
            ['key' => 'virtual_pages', 'label' => __('虚拟页面已生成'), 'ok' => \count($virtualPages) >= \count($pageTypes), 'value' => \array_keys($virtualPages)],
            [
                'key' => 'html_blocks',
                'label' => __('各页 HTML 区块已就绪'),
                'ok' => $track !== AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
                    || $this->scopeCompatibilityService->htmlTrackHasCompleteBlocks($virtualPages, $pageTypes),
                'value' => $track,
            ],
            [
                'key' => 'site_ready',
                'label' => __('域名/站点就绪（可发布）'),
                'ok' => (int)($scope['site_ready'] ?? 1) === 1,
                'value' => (int)($scope['site_ready'] ?? 1),
            ],
            [
                'key' => 'visual_editor',
                'label' => __('可视化预览/编辑地址已就绪'),
                'ok' => $track === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
                    || (\trim((string)$state['visual_edit_url']) !== '' && \trim((string)$state['visual_preview_url']) !== ''),
                'value' => ['visual_preview_url' => $state['visual_preview_url'], 'visual_edit_url' => $state['visual_edit_url']],
            ],
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
        $this->releasePhpSessionLockForSse();
        if (\defined('DEV') && DEV && !\headers_sent()) {
            \header('X-AiSite-Sse-Debug-Stage: auth-checked');
        }
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $lastEventId = LastEventIdResolver::resolve($this->request, 'last_event_id');
        $tabToken = \trim((string)$this->request->getGet('tab_token', ''));
        $streamStage = $this->normalizeWorkspaceStreamStage((string)$this->request->getGet('stage', ''));
        if (\strlen($tabToken) > 64) {
            $tabToken = \substr($tabToken, 0, 64);
        }
        $this->logStreamSse('request_received', [
            'admin_id' => $adminId,
            'public_id' => $publicId,
            'last_event_id' => $lastEventId,
            'stream_kind' => 'workspace',
            'tab_token' => $tabToken !== '' ? $tabToken : null,
            'stream_stage' => $streamStage !== '' ? $streamStage : null,
        ]);

        // 认证失败：返回 HTTP 401，不启动 SSE
        if ($adminId <= 0) {
            $this->logStreamSse('request_rejected', [
                'reason' => 'unauthorized',
                'public_id' => $publicId,
            ], 'warning');
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
            $this->logStreamSse('request_rejected', [
                'reason' => 'missing_public_id',
            ], 'warning');
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

        // 先启动 SSE，尽快向客户端发送首字节，避免被网关判定为首包超时。
        // 后续若校验失败，也通过 SSE 协议返回 error/done，保证前端可感知结束态。
        $sse = new SseWriter();
        $sse->start();
        if (\defined('DEV') && DEV && !\headers_sent()) {
            \header('X-AiSite-Sse-Debug-Stage: sse-started');
        }
        $sse->sendEvent('start', [
            'message' => __('正在建立 PageBuilder 工作区事件流...'),
        ]);

        // 会话不存在：通过 SSE 返回错误并完成
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->logStreamSse('request_rejected', [
                'reason' => 'session_not_found',
                'admin_id' => $adminId,
                'public_id' => $publicId,
            ], 'warning');
            $sse->sendEvent('error', [
                'error' => 'SESSION_NOT_FOUND',
                'message' => (string)__('会话不存在或无权访问'),
                'param' => self::PARAMS_PUBLIC_ID,
            ]);
            $sse->complete(['success' => false]);
            return;
        }

        $leaseToken = $this->generateWorkspaceStreamLeaseToken($tabToken);
        $this->touchStreamLeaseState($session, $adminId, $leaseToken, $tabToken);

        // 验证通过，启动 SSE 长连接
        @\set_time_limit(0);
        @\ignore_user_abort(true);
        $sse->sendEvent('start', [
            'message' => __('已连接 PageBuilder 工作区事件流'),
        ]);
        $this->logStreamSse('stream_started', [
            'session_id' => $session->getId(),
            'stage' => $session->getStage(),
            'publish_status' => $session->getPublishStatus(),
            'stream_kind' => 'workspace',
            'tab_token' => $tabToken !== '' ? $tabToken : null,
        ]);
        // 注册 SSE writer 到 RequestContext，让 AI 流式 chunks 能实时转发到 SSE 客户端
        RequestContext::set(RequestContext::SSE_WRITER_KEY, $sse);
        $snapshotSource = $this->buildWorkspaceState($session, $adminId, 40, self::WORKSPACE_STREAM_SNAPSHOT_PERSIST);
        $snapshot = $this->buildWorkspaceStreamSnapshotPayload($snapshotSource);
        if ($streamStage !== '') {
            $snapshot = $this->filterWorkspaceSnapshotByStage($snapshot, $streamStage);
        }
        $this->logStreamSse('snapshot_built', [
            'session_id' => $session->getId(),
            'snapshot_bytes' => \strlen((string)(\json_encode($snapshotSource, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '')),
            'snapshot_sse_bytes' => \strlen((string)(\json_encode($snapshot, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '')),
            'event_count' => \count(\is_array($snapshotSource['events'] ?? null) ? $snapshotSource['events'] : []),
            'event_sse_count' => \count(\is_array($snapshot['events'] ?? null) ? $snapshot['events'] : []),
            'top_log_count' => \count(\is_array($snapshotSource['top_logs'] ?? null) ? $snapshotSource['top_logs'] : []),
            'top_log_sse_count' => \count(\is_array($snapshot['top_logs'] ?? null) ? $snapshot['top_logs'] : []),
        ]);
        $sse->sendEvent('snapshot', $snapshot);
        if ($this->shouldFastFailWorkspaceStream($snapshotSource)) {
            $this->logStreamSse('stream_fast_failed', [
                'session_id' => $session->getId(),
                'reason' => 'fatal_error_detected_in_snapshot',
            ], 'warning');
            $sse->complete(['success' => false, 'last_event_id' => $lastEventId, 'fatal_error' => true]);
            return;
        }

        // 使用 Fiber 协程支持长连接模式（标准 SSE）。
        $pollInterval = 1000;  // 每秒轮询一次
        $startTime = \time();
        $loopCount = 0;
        // 标准 SSE 建议保持长连接，不因“空闲无事件”主动断开。
        // 仅在连接死亡、租约失效或达到总循环兜底时退出。
        $consecutiveIdleLoops = 0;
        $maxTotalLoops = 86400;  // 最多运行 24 小时（兜底）
        $probeEveryIdleLoops = 60;  // 连续空闲时定期探测连接健康

        while (true) {
            $loopCount++;
            $this->runQueuedPlanOperationFromWorkspaceStream($sse, $session, $adminId);

            if (!$this->isStreamLeaseAlive($session, $adminId, $leaseToken)) {
                $this->logStreamSse('lease_lost', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                    'tab_token' => $tabToken !== '' ? $tabToken : null,
                ], 'warning');
                break;
            }

            if ($loopCount === 1 || $loopCount % 15 === 0) {
                $this->touchStreamLeaseState($session, $adminId, $leaseToken, $tabToken);
            }

            // 兜底：超过最大总循环次数强制退出
            if ($loopCount > $maxTotalLoops) {
                $this->logStreamSse('max_loops_reached', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                ], 'warning');
                break;
            }

            // 检查连接是否还活着
            if (!$sse->isAlive()) {
                $this->logStreamSse('connection_dead', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                ]);
                break;
            }
            $newEvents = $this->sessionService->listEventsAfterId($session->getId(), $adminId, $lastEventId, 80);
            if ($streamStage !== '') {
                $newEvents = $this->filterWorkspaceEventsByStage($newEvents, $streamStage);
            }

            if (!empty($newEvents)) {
                $consecutiveIdleLoops = 0;
                foreach ($newEvents as $event) {
                    $eventId = (int)($event['event_id'] ?? 0);
                    if ($eventId > $lastEventId) {
                        $lastEventId = $eventId;
                    }
                    $sse->sendEvent('log', $event);
                }
                $this->logStreamSse('events_forwarded', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                    'event_count' => \count($newEvents),
                    'last_event_id' => $lastEventId,
                ]);
            } else {
                $consecutiveIdleLoops++;

                // 连续多次无事件且 isAlive 仍返回 true，发送 comment 检测对端是否真的活着
                if ($consecutiveIdleLoops % $probeEveryIdleLoops === 0) {
                    $this->logStreamSse('probing_connection', [
                        'session_id' => $session->getId(),
                        'loop_count' => $loopCount,
                        'consecutive_idle_loops' => $consecutiveIdleLoops,
                    ]);
                    // 发送一个 comment探测连接，如果写入失败 isAlive 会在下一轮返回 false
                    $sse->sendComment('probe:' . \time());
                }

                if ($loopCount === 1 || $loopCount % 10 === 0) {
                    $this->logStreamSse('poll_idle', [
                        'session_id' => $session->getId(),
                        'loop_count' => $loopCount,
                        'last_event_id' => $lastEventId,
                        'consecutive_idle_loops' => $consecutiveIdleLoops,
                    ]);
                }
            }

            // 使用协程延迟，不阻塞 Worker
            $sse->maybeHeartbeat();
            SchedulerSystem::yieldDelay($pollInterval);
        }

        $sse->complete(['success' => true, 'last_event_id' => $lastEventId]);
        $this->releaseStreamLeaseState($session, $adminId, $leaseToken);
        $this->logStreamSse('stream_completed', [
            'session_id' => $session->getId(),
            'loop_count' => $loopCount,
            'last_event_id' => $lastEventId,
            'duration_sec' => \max(0, \time() - $startTime),
        ]);
    }

    private function runQueuedPlanOperationFromWorkspaceStream(SseWriter $sse, AiSiteAgentSession $session, int $adminId): void
    {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if (\trim((string)($activeOperation['operation'] ?? '')) !== 'plan') {
            return;
        }
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $progressPercent = (int)($activeOperation['progress_percent'] ?? 0);
        $allowResumeStuckRunning = ($activeStatus === 'running' && $progressPercent <= 0);
        // 队列消费：claim 已将状态置为 running，且可能携带上次残留的 progress_percent>0；不得静默 return，否则 queue:run 无输出且不落库。
        $queueDbMode = $sse instanceof QueueDbWriter;
        if ($queueDbMode) {
            if (!\in_array($activeStatus, ['queued', 'running'], true)) {
                return;
            }
        } elseif ($activeStatus !== 'queued' && !$allowResumeStuckRunning) {
            return;
        }

        // active_operation 已明确为 queued plan，说明是用户动作触发后的待执行任务。
        // 这里应直接启动执行，避免“已入队后再次被误判为需点击按钮”的回退提示。

        $this->updateActiveOperation(
            $fresh,
            $adminId,
            ['status' => 'running', 'message' => (string)__('正在生成阶段一方案')],
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
        );
        $this->appendWorkspaceEvent(
            $fresh->getId(),
            $adminId,
            'plan',
            'operation_started',
            (string)__('已开始执行阶段一方案生成'),
            ['operation' => 'plan']
        );
        $sse->sendEvent('log', [
            'event_type' => 'operation_started',
            'stage_code' => 'plan',
            'message' => (string)__('已开始执行阶段一方案生成'),
            'payload' => ['operation' => 'plan'],
            'level' => 'info',
            'event_id' => 0,
            'created_at' => \date('Y-m-d H:i:s'),
        ]);
        $sse->sendEvent('start', [
            'message' => (string)__('已开始执行阶段一方案生成'),
            'operation' => 'plan',
        ]);

        try {
            $scope = $this->scopeCompatibilityService->normalizeScope(($this->sessionService->loadById($fresh->getId(), $adminId) ?? $fresh)->getScopeArray());
            $scope['plan_locale'] = $this->resolvePlanLocale($scope);
            $websiteProfile = $this->profileGenerationService->generate($scope, false);
            $currentPageTypes = \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [];
            $lastGeneratedPlanLocale = \trim((string)($scope['plan_generated_locale'] ?? ''));
            $lastGeneratedPageTypes = \is_array($scope['plan_generated_page_types'] ?? null) ? $scope['plan_generated_page_types'] : [];
            $planLocaleChanged = $lastGeneratedPlanLocale !== '' && $scope['plan_locale'] !== '' && $scope['plan_locale'] !== $lastGeneratedPlanLocale;
            $pageTypesChanged = !$this->isSamePageTypeSelection($currentPageTypes, $lastGeneratedPageTypes);
            /** @var array<string, mixed> $planSseRequest */
            $planSseRequest = \is_array($scope['_plan_sse_request'] ?? null) ? $scope['_plan_sse_request'] : [];
            $requestedPromptMode = \trim((string)($planSseRequest['prompt_mode'] ?? ''));
            $requestedInstruction = \trim((string)($planSseRequest['instruction'] ?? ''));
            $requestedTargetScope = \trim((string)($planSseRequest['target_scope'] ?? ''));
            $requestedRound = \max(1, (int)($planSseRequest['round'] ?? 1));
            $planAiStreamBuffer = '';
            $planMarkdownStreamState = [
                'stage' => 'seek_key',
                'i' => 0,
                'decoded' => '',
                'emitted' => 0,
                'markdown_string_closed' => false,
            ];
            $onChunk = function (string $chunk) use ($sse, $fresh, $adminId, &$planAiStreamBuffer, &$planMarkdownStreamState): void {
                if ($chunk === '') {
                    return;
                }
                if ($sse instanceof QueueDbWriter) {
                    $sse->recordRawAiStreamChunk($chunk);
                }
                $planAiStreamBuffer .= $chunk;
                $delta = $this->extractPlanMarkdownJsonStreamDelta($planAiStreamBuffer, $planMarkdownStreamState);
                if ($delta !== '') {
                    $this->appendWorkspaceEvent(
                        $fresh->getId(),
                        $adminId,
                        'plan',
                        'plan_chunk',
                        $delta,
                        ['operation' => 'plan', 'format' => 'markdown_stream']
                    );
                    $sse->sendEvent('log', [
                        'event_type' => 'plan_chunk',
                        'stage_code' => 'plan',
                        'message' => $delta,
                        'payload' => ['operation' => 'plan', 'format' => 'markdown_stream'],
                        'level' => 'info',
                        'event_id' => 0,
                        'created_at' => \date('Y-m-d H:i:s'),
                    ]);
                    $sse->sendEvent('chunk', [
                        'operation' => 'plan',
                        'chunk' => $delta,
                        'content' => $delta,
                        'message' => $delta,
                    ]);
                }
                $sse->maybeHeartbeat();
            };
            $maxAttempts = 3;
            $attempt = 0;
            $artifacts = [];
            $lastAttemptThrowable = null;
            while ($attempt < $maxAttempts) {
                $attempt++;
                try {
                    if ($attempt > 1) {
                        $retryMessage = (string)__('AI 输出格式异常，正在自动重试（%{1}/%{2}）…', [$attempt, $maxAttempts]);
                        $this->appendWorkspaceEvent(
                            $fresh->getId(),
                            $adminId,
                            'plan',
                            'operation_progress',
                            $retryMessage,
                            ['operation' => 'plan', 'details' => ['attempt' => $attempt, 'max_attempts' => $maxAttempts]]
                        );
                        $sse->sendEvent('log', [
                            'event_type' => 'operation_progress',
                            'stage_code' => 'plan',
                            'message' => $retryMessage,
                            'payload' => ['operation' => 'plan', 'attempt' => $attempt, 'max_attempts' => $maxAttempts],
                            'level' => 'info',
                            'event_id' => 0,
                            'created_at' => \date('Y-m-d H:i:s'),
                        ]);
                        $sse->sendEvent('progress', [
                            'message' => $retryMessage,
                            'operation' => 'plan',
                            'progress_percent' => 45,
                        ]);
                        SchedulerSystem::yieldDelay(500);
                    }

                    if ($requestedPromptMode === 'refine' && \is_array($scope['plan_json'] ?? null) && $scope['plan_json'] !== []) {
                        $refineInstruction = $requestedInstruction;
                        $refineTargetScope = $requestedTargetScope;
                        if ($planLocaleChanged && !$pageTypesChanged) {
                            $refineTargetScope = $refineTargetScope !== '' ? $refineTargetScope : 'locale_translation';
                            $refineInstruction = $refineInstruction !== ''
                                ? ((string)__('请按目标 plan_locale 翻译当前方案，并保留原有结构与页面类型。') . ' ' . $refineInstruction)
                                : (string)__('请按目标 plan_locale 翻译当前方案，并保留原有结构与页面类型。');
                        }
                        $artifacts = $this->executionBlueprintService->refineDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                            'instruction' => $refineInstruction,
                            'target_scope' => $refineTargetScope,
                            'round' => $requestedRound,
                        ], $onChunk);
                    } elseif ($planLocaleChanged && !$pageTypesChanged && \is_array($scope['plan_json'] ?? null) && $scope['plan_json'] !== []) {
                        $translateInstruction = (string)__('请保留当前方案结构与页面类型，仅将方案内容完整翻译为目标 plan_locale，不新增或删除页面。');
                        $artifacts = $this->executionBlueprintService->refineDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                            'instruction' => $translateInstruction,
                            'target_scope' => 'locale_translation',
                            'round' => 1,
                        ], $onChunk);
                    } else {
                        $buildPayload = [];
                        if ($requestedInstruction !== '') {
                            $buildPayload['instruction'] = $requestedInstruction;
                        }
                        $buildPayload['round'] = $requestedRound;
                        $buildPayload['staged_generation'] = true;
                        $artifacts = $this->executionBlueprintService->buildPlanArtifactsByAiStream($scope, \is_array($websiteProfile) ? $websiteProfile : [], $buildPayload, $onChunk);
                    }

                    $lastAttemptThrowable = null;
                    break;
                } catch (\Throwable $attemptThrowable) {
                    $lastAttemptThrowable = $attemptThrowable;
                    if (!$this->isRetryablePlanGenerationException($attemptThrowable) || $attempt >= $maxAttempts) {
                        throw $attemptThrowable;
                    }
                }
            }
            if ($artifacts === [] && $lastAttemptThrowable instanceof \Throwable) {
                throw $lastAttemptThrowable;
            }
            if ((int)($scope['fake_mode'] ?? 0) !== 1 && (int)($artifacts['ai_generated'] ?? 0) !== 1) {
                throw new \RuntimeException((string)__('阶段一方案必须由 AI 生成，本次未成功调用 AI，请检查模型配置后重试。'));
            }

            if (!($planMarkdownStreamState['markdown_string_closed'] ?? false)) {
                $this->emitPlanMarkdownBlocks($sse, $fresh, $adminId, (string)($artifacts['markdown'] ?? ''));
            }
            $derivedPatch = \is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [];
            $executionBlueprint = \is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [];
            $planJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
            $scopePatchPersist = \array_replace($derivedPatch, [
                'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
                'execution_blueprint_draft' => $executionBlueprint,
                'plan_json' => $planJson,
                'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
                'plan_structured' => \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : $planJson,
                'plan_locale' => $this->resolvePlanLocale($scope),
                'plan_ai_generated' => (int)($artifacts['ai_generated'] ?? 0),
                'plan_ai_fallback' => (int)($artifacts['ai_fallback'] ?? 0),
                'plan_generated_at' => \date('Y-m-d H:i:s'),
                'plan_generated_locale' => (string)$scope['plan_locale'],
                'plan_generated_page_types' => \array_values(\array_map('strval', $currentPageTypes)),
                'plan_confirmed' => 0,
            ]);
            $this->sessionService->mergeScope($fresh->getId(), $adminId, $scopePatchPersist);
            $freshSaved = $this->sessionService->loadById($fresh->getId(), $adminId) ?? $fresh;
            $this->appendWorkspaceEvent(
                $freshSaved->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($freshSaved->getStage()),
                'plan_saved',
                (string)__('阶段一方案已保存'),
                [
                    'operation' => 'plan',
                    'details' => [
                        'plan_locale' => (string)$scope['plan_locale'],
                        'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? ''),
                        'task_count' => \is_array($executionBlueprint['tasks'] ?? null) ? \count($executionBlueprint['tasks']) : 0,
                    ],
                ]
            );
            $sse->sendEvent('log', [
                'event_type' => 'plan_saved',
                'stage_code' => 'plan',
                'message' => (string)__('阶段一方案已保存'),
                'payload' => ['operation' => 'plan'],
                'level' => 'info',
                'event_id' => 0,
                'created_at' => \date('Y-m-d H:i:s'),
            ]);
            $sse->sendEvent('progress', [
                'message' => (string)__('阶段一方案已保存'),
                'operation' => 'plan',
                'progress_percent' => 95,
            ]);
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                ['status' => 'done', 'message' => (string)__('阶段一方案生成完成')],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );
            $this->appendWorkspaceEvent(
                $fresh->getId(),
                $adminId,
                'plan',
                'plan_generated',
                (string)__('已生成阶段一方案，请确认后继续执行构建'),
                [
                    'operation' => 'plan',
                    'details' => [
                        'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? ''),
                        'task_count' => \is_array($executionBlueprint['tasks'] ?? null) ? \count($executionBlueprint['tasks']) : 0,
                    ],
                ]
            );
            $sse->sendEvent('log', [
                'event_type' => 'plan_generated',
                'stage_code' => 'plan',
                'message' => (string)__('阶段一方案生成完成'),
                'payload' => ['operation' => 'plan'],
                'level' => 'done',
                'event_id' => 0,
                'created_at' => \date('Y-m-d H:i:s'),
            ]);
            $sse->sendEvent('progress', [
                'message' => (string)__('阶段一方案生成完成'),
                'operation' => 'plan',
                'progress_percent' => 99,
            ]);
        } catch (\Throwable $throwable) {
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                ['status' => 'error', 'message' => $throwable->getMessage()],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );
            $this->appendWorkspaceEvent(
                $fresh->getId(),
                $adminId,
                'plan',
                'operation_failed',
                (string)__('阶段一方案生成失败：%{message}', ['message' => $throwable->getMessage()]),
                ['operation' => 'plan', 'details' => ['exception' => $throwable->getMessage()]],
                AiSiteAgentSessionEvent::LEVEL_ERROR
            );
            $sse->sendEvent('log', [
                'event_type' => 'operation_failed',
                'stage_code' => 'plan',
                'message' => (string)__('阶段一方案生成失败：%{message}', ['message' => $throwable->getMessage()]),
                'payload' => ['operation' => 'plan'],
                'level' => 'error',
                'event_id' => 0,
                'created_at' => \date('Y-m-d H:i:s'),
            ]);
            $sse->sendError($throwable->getMessage(), $this->inferThrowableHttpCode($throwable));
            if ($sse instanceof QueueDbWriter) {
                throw $throwable;
            }
        }
    }

    private function runPlanOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): void
    {
        $this->runQueuedPlanOperationFromWorkspaceStream($sse, $session, $adminId);
    }

    private function runTaskPlanOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): void
    {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        if ((int)($scope['plan_confirmed'] ?? 0) !== 1) {
            throw new \RuntimeException((string)__('请先确认第一阶段方案，再生成第二阶段任务方案。'));
        }

        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            (string)($scope['workspace_track'] ?? '')
        );
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        if ($buildBlueprint === []) {
            throw new \RuntimeException((string)__('缺少任务蓝图，无法生成第二阶段任务方案。'));
        }

        /** @var array<string, mixed> $sseReq */
        $sseReq = \is_array($scope['_task_plan_sse_request'] ?? null) ? $scope['_task_plan_sse_request'] : [];
        $promptMode = \trim((string)($sseReq['prompt_mode'] ?? 'detect_bootstrap_task_plan'));
        $instruction = \trim((string)($sseReq['instruction'] ?? ''));
        $targetScope = \trim((string)($sseReq['target_scope'] ?? ''));
        $round = \max(1, (int)($sseReq['round'] ?? 1));

        if ($promptMode === '' || $promptMode === 'detect_bootstrap_task_plan') {
            $this->handleTaskPlanDetectBootstrapSse($sse, $fresh, $adminId, $scope, $buildBlueprint);
            return;
        }

        $this->runInteractiveTaskPlanOperation(
            $sse,
            $fresh,
            $adminId,
            $scope,
            $buildBlueprint,
            $promptMode,
            $instruction,
            $targetScope,
            $round
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $buildBlueprint
     */
    private function runInteractiveTaskPlanOperation(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        array $buildBlueprint,
        string $promptMode,
        string $instruction,
        string $targetScope,
        int $round
    ): void {
        $sse->sendEvent('start', [
            'message' => $promptMode === 'rebuild_task_plan'
                ? (string)__('正在重建第二阶段任务方案')
                : (string)__('正在微调第二阶段任务方案'),
            'prompt_mode' => $promptMode,
            'round' => $round,
            'target_scope' => $targetScope,
        ]);
        $sse->sendEvent('progress', [
            'message' => (string)__('正在整理当前任务方案上下文'),
            'prompt_mode' => $promptMode,
            'progress_percent' => 20,
        ]);

        try {
            $draftPlan = \is_array($scope['virtual_theme_plan']['draft'] ?? null) ? $scope['virtual_theme_plan']['draft'] : [];
            $streamBuffer = '';
            $rawChunkInfoBuffer = '';
            $taskPlanStreamHeartbeat = function () use ($sse): void {
                $sse->sendComment(\str_repeat(' ', 512) . 'pb-task-plan-ai');
            };
            $aiChunkCount = 0;
            $chunkCallback = static function (string $chunk) use (&$streamBuffer, &$rawChunkInfoBuffer, $sse, $promptMode, &$aiChunkCount): void {
                $streamBuffer .= $chunk;
                if (\trim($chunk) === '') {
                    return;
                }
                $queueMode = $sse instanceof QueueDbWriter;
                if ($queueMode) {
                    $sse->recordRawAiStreamChunk($chunk);
                }
                if (!$queueMode) {
                    $rawChunkInfoBuffer .= $chunk;
                    while (\mb_strlen($rawChunkInfoBuffer, 'UTF-8') >= 360) {
                        if (!$sse->isAlive()) {
                            $rawChunkInfoBuffer = '';
                            return;
                        }
                        $part = (string)\mb_substr($rawChunkInfoBuffer, 0, 360, 'UTF-8');
                        $rawChunkInfoBuffer = (string)\mb_substr($rawChunkInfoBuffer, 360, null, 'UTF-8');
                        $sse->sendEvent('info', [
                            'message' => $part,
                            'prompt_mode' => $promptMode,
                            'stream_stage' => 'ai_raw_chunk',
                            'chunk' => $part,
                        ]);
                    }
                }
                $aiChunkCount++;
                if ($aiChunkCount === 1 || $aiChunkCount % 12 === 0) {
                    if (!$sse->isAlive()) {
                        return;
                    }
                    $sse->sendEvent('progress', [
                        'message' => (string)__('AI 正在生成第二阶段任务方案…'),
                        'prompt_mode' => $promptMode,
                        'progress_percent' => 55,
                        'ai_chunk_count' => $aiChunkCount,
                    ]);
                }
            };
            $result = $promptMode === 'rebuild_task_plan'
                ? $this->virtualThemePlanService->rebuildDraftTaskPlan($scope, $buildBlueprint, [
                    'instruction' => $instruction,
                    'round' => $round,
                ], $chunkCallback, $taskPlanStreamHeartbeat)
                : $this->virtualThemePlanService->refineDraftTaskPlan($scope, $buildBlueprint, $draftPlan, [
                    'instruction' => $instruction,
                    'target_scope' => $targetScope,
                    'round' => $round,
                ], $chunkCallback, $taskPlanStreamHeartbeat);

            if ($rawChunkInfoBuffer !== '' && !($sse instanceof QueueDbWriter) && $sse->isAlive()) {
                $sse->sendEvent('info', [
                    'message' => $rawChunkInfoBuffer,
                    'prompt_mode' => $promptMode,
                    'stream_stage' => 'ai_raw_chunk',
                    'chunk' => $rawChunkInfoBuffer,
                ]);
            }

            $markdown = (string)($result['markdown'] ?? '');
            if ($markdown !== '' && $sse->isAlive()) {
                $sse->sendEvent('progress', [
                    'message' => (string)__('AI 已生成完整任务方案，正在输出正文'),
                    'prompt_mode' => $promptMode,
                    'progress_percent' => 72,
                    'stream_stage' => 'markdown_stream',
                ]);
                foreach ($this->chunkStringForSse($markdown, 220) as $mdChunk) {
                    if (\trim($mdChunk) === '') {
                        continue;
                    }
                    if (!$sse->isAlive()) {
                        break;
                    }
                    $sse->sendEvent('chunk', [
                        'content' => $mdChunk,
                        'chunk' => $mdChunk,
                        'prompt_mode' => $promptMode,
                    ]);
                    SchedulerSystem::yieldDelay(5);
                }
            }

            $sse->sendEvent('progress', [
                'message' => (string)__('任务方案正文输出完成，正在保存并生成最终状态'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 92,
            ]);
            $structured = \is_array($result['structured'] ?? null) ? $result['structured'] : [];
            $virtualThemePlan = \is_array($result['virtual_theme_plan'] ?? null) ? $result['virtual_theme_plan'] : [];
            $taskPlanGenerationSource = (string)($result['generation_source'] ?? '');
            if ($this->shouldRejectTaskPlanGenerationSource($scope, $taskPlanGenerationSource)) {
                throw new \RuntimeException((string)__('第二阶段任务方案生成失败：当前结果不是 AI 生成，请检查 AI 服务后重试'));
            }
            $taskPlanDirectoryTree = \is_array($structured['task_directory_tree'] ?? null) ? $structured['task_directory_tree'] : [];
            $scopePatch = [
                'virtual_theme_plan' => [
                    'draft' => $virtualThemePlan,
                    'draft_markdown' => $markdown,
                    'draft_generated_at' => \date('Y-m-d H:i:s'),
                    'confirmed' => \is_array($scope['virtual_theme_plan']['confirmed'] ?? null) ? $scope['virtual_theme_plan']['confirmed'] : [],
                    'confirmed_markdown' => (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''),
                    'confirmed_at' => (string)($scope['virtual_theme_plan']['confirmed_at'] ?? ''),
                    'confirmed_signature' => (string)($scope['virtual_theme_plan']['confirmed_signature'] ?? ''),
                    'plan_signature' => (string)($virtualThemePlan['signature'] ?? ''),
                    'last_prompt_mode' => $promptMode,
                    'last_target_scope' => $targetScope,
                    'last_round' => $round,
                ],
                'task_plan_markdown' => $markdown,
                'task_plan_generated_at' => \date('Y-m-d H:i:s'),
                'task_plan_structured' => $structured,
                'task_plan_directory_tree' => $taskPlanDirectoryTree,
                'task_plan_summary' => [
                    'signature' => (string)($virtualThemePlan['signature'] ?? ''),
                    'shared_task_count' => \count(\is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : []),
                    'page_task_count' => \array_sum(\array_map(static fn($items): int => \is_array($items) ? \count($items) : 0, \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [])),
                    'prompt_mode' => $promptMode,
                    'generation_source' => $taskPlanGenerationSource,
                ],
                'task_plan_confirmed' => 0,
                '_task_plan_sse_request' => [],
            ];
            if ($promptMode === 'rebuild_task_plan') {
                $scopePatch['task_plan_rebuild_summary'] = \is_array($result['rebuild_summary'] ?? null) ? $result['rebuild_summary'] : [];
            } else {
                $scopePatch['task_plan_change_scope_report'] = \is_array($result['change_scope_report'] ?? null) ? $result['change_scope_report'] : [];
            }
            $sse->sendEvent('progress', [
                'message' => (string)__('正在持久化任务方案草案'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 95,
            ]);
            $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

            $sse->sendEvent('progress', [
                'message' => (string)__('正在写入工作区事件并整理最终状态'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 97,
            ]);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
                $promptMode === 'rebuild_task_plan' ? 'task_plan_rebuilt' : 'task_plan_refined',
                $promptMode === 'rebuild_task_plan'
                    ? (string)__('第二阶段任务方案已重建，请确认后再进入构建')
                    : (string)__('第二阶段任务方案已微调，请确认后再进入构建'),
                [
                    'operation' => 'task_plan',
                    'details' => [
                        'prompt_mode' => $promptMode,
                        'target_scope' => $targetScope,
                        'round' => $round,
                        'task_plan_signature' => (string)($virtualThemePlan['signature'] ?? ''),
                    ],
                ]
            );
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                ['status' => 'done', 'message' => (string)__('第二阶段任务方案生成完成')],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );
            $sse->sendEvent('progress', [
                'message' => (string)__('第二阶段任务方案已生成，正在输出完成标记'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 99,
            ]);
            $state = $this->buildWorkspaceEventStatePayload($this->buildWorkspaceState($fresh, $adminId, 80, true));
            $sse->complete([
                'success' => true,
                'message' => $promptMode === 'rebuild_task_plan'
                    ? (string)__('第二阶段任务方案重建完成')
                    : (string)__('第二阶段任务方案微调完成'),
                'prompt_mode' => $promptMode,
                'task_plan' => [
                    'markdown' => $markdown,
                    'structured' => $structured,
                    'virtual_theme_plan' => $virtualThemePlan,
                ],
                'state' => $state,
            ]);
        } catch (\Throwable $throwable) {
            $this->updateActiveOperation(
                $session,
                $adminId,
                ['status' => 'error', 'message' => $throwable->getMessage()],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'operation_failed',
                (string)__('第二阶段任务方案生成失败：%{message}', ['message' => $throwable->getMessage()]),
                ['operation' => 'task_plan', 'details' => ['exception' => $throwable->getMessage()]],
                AiSiteAgentSessionEvent::LEVEL_ERROR
            );
            $sse->sendError($throwable->getMessage(), $this->inferThrowableHttpCode($throwable));
            $sse->complete([
                'success' => false,
                'message' => $throwable->getMessage(),
                'prompt_mode' => $promptMode,
            ]);
            // 队列消费（QueueDbWriter）必须向上抛错，否则 execute 会误判成功、草案校验报“字段全空”。
            if ($sse instanceof QueueDbWriter) {
                throw $throwable;
            }
        }
    }

    private function clipIncompleteUtf8Suffix(string $text): string
    {
        if ($text === '' || \mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }
        $len = \strlen($text);
        for ($cut = 1; $cut < 5 && $cut < $len; $cut++) {
            $try = \substr($text, 0, $len - $cut);
            if ($try !== '' && \mb_check_encoding($try, 'UTF-8')) {
                return $try;
            }
        }

        return '';
    }

    private function unicodeCodePointToUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12))
                . \chr(0x80 | (($cp >> 6) & 0x3F))
                . \chr(0x80 | ($cp & 0x3F));
        }
        if ($cp <= 0x10FFFF) {
            return \chr(0xF0 | ($cp >> 18))
                . \chr(0x80 | (($cp >> 12) & 0x3F))
                . \chr(0x80 | (($cp >> 6) & 0x3F))
                . \chr(0x80 | ($cp & 0x3F));
        }

        return '';
    }

    /**
     * 从流式输出的 json_object 缓冲中增量解码顶层 "markdown" 字符串字段，返回本次可安全下发的 UTF-8 增量。
     *
     * @param array<string, mixed> $state
     */
    private function extractPlanMarkdownJsonStreamDelta(string $buffer, array &$state): string
    {
        if (($state['stage'] ?? '') === 'done') {
            return '';
        }
        if (($state['stage'] ?? '') === 'seek_key') {
            if (!\preg_match('/"markdown"\s*:\s*"/u', $buffer, $m, \PREG_OFFSET_CAPTURE)) {
                return '';
            }
            $state['stage'] = 'decode';
            $state['i'] = (int)$m[0][1] + \strlen($m[0][0]);
        }
        if (($state['stage'] ?? '') !== 'decode') {
            return '';
        }
        $i = (int)($state['i'] ?? 0);
        $decoded = (string)($state['decoded'] ?? '');
        $len = \strlen($buffer);
        while ($i < $len) {
            $ch = $buffer[$i];
            if ($ch === '"') {
                $i++;
                $state['markdown_string_closed'] = true;
                $state['stage'] = 'done';
                break;
            }
            if ($ch === '\\') {
                if ($i + 1 >= $len) {
                    break;
                }
                $esc = $buffer[$i + 1];
                if ($esc === 'u') {
                    if ($i + 6 > $len) {
                        break;
                    }
                    $hex = \substr($buffer, $i + 2, 4);
                    if (!\ctype_xdigit($hex)) {
                        $decoded .= '\\u';
                        $i += 2;
                        continue;
                    }
                    $decoded .= $this->unicodeCodePointToUtf8((int)\hexdec($hex));
                    $i += 6;
                    continue;
                }
                $i += 2;
                $decoded .= match ($esc) {
                    '"' => '"',
                    '\\' => '\\',
                    '/' => '/',
                    'b' => "\x08",
                    'f' => "\f",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $esc,
                };
                continue;
            }
            $decoded .= $ch;
            $i++;
        }
        $state['i'] = $i;
        $state['decoded'] = $decoded;
        $emitted = (int)($state['emitted'] ?? 0);
        if ($emitted >= \strlen($decoded)) {
            return '';
        }
        $candidate = \substr($decoded, $emitted);
        $safeDelta = $this->clipIncompleteUtf8Suffix($candidate);
        if ($safeDelta !== '') {
            $state['emitted'] = $emitted + \strlen($safeDelta);
        }
        $out = $safeDelta;
        if (($state['markdown_string_closed'] ?? false) === true) {
            $emittedAfter = (int)($state['emitted'] ?? 0);
            if ($emittedAfter < \strlen($decoded)) {
                $tail = \substr($decoded, $emittedAfter);
                $state['emitted'] = \strlen($decoded);
                $out .= $tail;
            }
        }

        return $out;
    }

    private function emitPlanMarkdownBlocks(SseWriter $sse, AiSiteAgentSession $session, int $adminId, string $markdown): void
    {
        $text = \trim($markdown);
        if ($text === '') {
            return;
        }
        $blocks = \preg_split('/\n\s*\n(?=##\s|###\s|####\s|#\s)/u', $text) ?: [];
        if ($blocks === []) {
            $blocks = [$text];
        }
        foreach ($blocks as $block) {
            $chunk = \trim((string)$block);
            if ($chunk === '') {
                continue;
            }
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                'plan',
                'plan_chunk',
                $chunk,
                ['operation' => 'plan', 'format' => 'markdown_block']
            );
            $sse->sendEvent('log', [
                'event_type' => 'plan_chunk',
                'stage_code' => 'plan',
                'message' => $chunk,
                'payload' => ['operation' => 'plan', 'format' => 'markdown_block'],
                'level' => 'info',
                'event_id' => 0,
                'created_at' => \date('Y-m-d H:i:s'),
            ]);
            $sse->maybeHeartbeat();
        }
    }

    private function isRetryablePlanGenerationException(\Throwable $throwable): bool
    {
        $message = \mb_strtolower(\trim($throwable->getMessage()));
        if ($message === '') {
            return false;
        }

        return \str_contains($message, 'invalid ai json')
            || (\str_contains($message, 'ai plan generation failed') && \str_contains($message, 'json'));
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function shouldFastFailWorkspaceStream(array $snapshot): bool
    {
        $messages = [];
        $collect = static function (mixed $rows) use (&$messages): void {
            if (!\is_array($rows)) {
                return;
            }
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $msg = \trim((string)($row['message'] ?? ''));
                if ($msg !== '') {
                    $messages[] = $msg;
                }
            }
        };
        $collect($snapshot['top_logs'] ?? []);
        $collect($snapshot['events'] ?? []);
        $collect($snapshot['scope']['top_logs'] ?? []);

        foreach ($messages as $message) {
            $normalized = \strtolower($message);
            if (
                \str_contains($normalized, '没有满足条件的')
                && \str_contains($normalized, '供应商账户')
            ) {
                return true;
            }
            if (\str_contains($normalized, 'insufficient balance') || \str_contains($normalized, 'http 402')) {
                return true;
            }
        }

        return false;
    }

    /**
     * P2：仅 `queued` 可认领进入执行；`running` 视为重复 operation-sse；终态拒绝，避免重复跑 build/发布。
     *
     * @return array{ok: bool, reason?: string, message?: string}
     */
    private function claimActiveOperationExecution(
        AiSiteAgentSession $session,
        int $adminId,
        string $executionToken,
        string $operation
    ): array {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
            $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $op = \trim((string)($active['operation'] ?? ''));
            $tok = \trim((string)($active['execution_token'] ?? ''));
            $status = \trim((string)($active['status'] ?? ''));

            if ($op !== $operation || $tok !== $executionToken) {
                return ['ok' => false, 'reason' => 'terminal', 'message' => (string)__('未找到待执行的工作区操作')];
            }

            // if (\in_array($status, ['done', 'error', 'cancelled'], true)) {
            //     return ['ok' => false, 'reason' => 'terminal', 'message' => (string)__('该操作已结束，请重新发起')];
            // }

            if ($status === 'running') {
                return ['ok' => false, 'reason' => 'duplicate_stream'];
            }

            // if ($status !== 'queued') {
            //     return ['ok' => false, 'reason' => 'terminal', 'message' => (string)__('操作状态异常，请刷新后重试')];
            // }

            $scope['active_operation'] = \array_replace($active, [
                'status' => 'running',
                'message' => (string)__('操作开始执行'),
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
            $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);

            $verify = $this->sessionService->loadById($session->getId(), $adminId) ?? $fresh;
            $vScope = $this->scopeCompatibilityService->normalizeScope($verify->getScopeArray());
            $vActive = \is_array($vScope['active_operation'] ?? null) ? $vScope['active_operation'] : [];
            if (\trim((string)($vActive['execution_token'] ?? '')) === $executionToken
                && \trim((string)($vActive['status'] ?? '')) === 'running') {
                return ['ok' => true];
            }
        }

        return ['ok' => false, 'reason' => 'duplicate_stream'];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logOperationSse(string $message, array $context = [], string $level = 'info'): void
    {
        if (!(\defined('DEV') && DEV)) {
            return;
        }

        $message = '[AiSiteAgent::operation-sse] ' . $message;
        match (\strtolower($level)) {
            'debug' => WlsLogger::debug_($message, $context),
            'warn', 'warning' => WlsLogger::warning_($message, $context),
            'error' => WlsLogger::error_($message, $context),
            default => WlsLogger::info_($message, $context),
        };
    }

    private function handleOperationSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        // 立即释放 PHP session 锁，避免阻塞 SSE 长连接
        $this->releasePhpSessionLockForSse();

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
        $status = \trim((string)($activeOperation['status'] ?? ''));
        if ($operation === '' || (string)($activeOperation['execution_token'] ?? '') !== $executionToken) {
            $this->sendSseContractError($sse, 'OPERATION_NOT_FOUND', (string)__('未找到待执行的工作区操作'), self::PARAMS_OPERATION_SSE, 404);
            $sse->complete(['success' => false]);
            return;
        }

        if ($this->supportsBackgroundOperation($operation) && \in_array($status, ['queued', 'running'], true)) {
            $queueRow = $this->findAiSiteOperationQueueRow(
                $session,
                $operation,
                (int)($activeOperation['queue_id'] ?? 0)
            );
            if (!\is_array($queueRow) || $queueRow === []) {
                if (!$this->shouldEnqueueOperation($operation)) {
                    $this->sendSseContractError(
                        $sse,
                        'OPERATION_QUEUE_NOT_FOUND',
                        (string)__('未找到当前阶段对应的队列记录，请刷新后重试。'),
                        self::PARAMS_OPERATION_SSE,
                        404
                    );
                    $sse->complete(['success' => false, 'message' => (string)__('未找到当前阶段对应的队列记录')]);
                    return;
                }
                try {
                    // 断链补建无原始 HTTP 侧 scope_patch；build 等消费端会以会话库 scope 为准合并
                    $newQueueId = $this->enqueueOperationQueueTask($session, $adminId, $operation, $executionToken, []);
                    if ($newQueueId <= 0) {
                        throw new \RuntimeException('enqueue_queue_id');
                    }
                    $healScope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
                    $healActive = \is_array($healScope['active_operation'] ?? null) ? $healScope['active_operation'] : [];
                    $healScope['active_operation'] = \array_replace($healActive, [
                        'queue_id' => $newQueueId,
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ]);
                    $this->sessionService->replaceScope($session->getId(), $adminId, $healScope);
                    $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                    $queueRow = $this->findAiSiteOperationQueueRow($session, $operation, $newQueueId);
                } catch (\Throwable $e) {
                    $this->logOperationSse('queue_heal_failed', [
                        'public_id' => $session->getPublicId(),
                        'operation' => $operation,
                        'error' => $e->getMessage(),
                    ], 'error');
                    $this->sendSseContractError(
                        $sse,
                        'OPERATION_QUEUE_NOT_FOUND',
                        (string)__('无法补建队列任务，请刷新后重试。'),
                        self::PARAMS_OPERATION_SSE,
                        500
                    );
                    $sse->complete(['success' => false, 'message' => (string)__('无法补建队列任务，请刷新后重试。')]);
                    return;
                }
                if (!\is_array($queueRow) || $queueRow === []) {
                    $this->sendSseContractError(
                        $sse,
                        'OPERATION_QUEUE_NOT_FOUND',
                        (string)__('补建队列后仍无法加载队列记录，请刷新后重试。'),
                        self::PARAMS_OPERATION_SSE,
                        500
                    );
                    $sse->complete(['success' => false, 'message' => (string)__('补建队列后仍无法加载队列记录，请刷新后重试。')]);
                    return;
                }
                $this->logOperationSse('queue_healed', [
                    'public_id' => $session->getPublicId(),
                    'operation' => $operation,
                    'queue_id' => (int)($queueRow['queue_id'] ?? 0),
                ]);
                $sse->sendEvent('info', [
                    'message' => (string)__('已根据当前会话自动补建队列任务，进度将异步刷新。'),
                    'operation' => $operation,
                    'queue_id' => (int)($queueRow['queue_id'] ?? 0),
                    'observer_detail' => true,
                ]);
            }

            $sse->sendEvent('warning', [
                'message' => (string)__('操作已进入系统队列，工作区进度将自动刷新。'),
                'operation' => $operation,
                'observer_mode' => true,
                'background_mode' => true,
                'queue_id' => (int)($queueRow['queue_id'] ?? 0),
                'queue_status' => (string)($queueRow['status'] ?? ''),
                'biz_key' => (string)($queueRow['biz_key'] ?? ''),
            ]);
            $observed = $this->observeDuplicateOperationStream(
                $sse,
                $session,
                $adminId,
                $operation,
                $executionToken,
                !$this->shouldKeepQueuedObserverStreamOpen($operation)
            );
            if (!(bool)($observed['success'] ?? true)) {
                $sse->sendError(
                    (string)($observed['message'] ?? 'Operation failed.'),
                    (int)($observed['http_code'] ?? 500)
                );
            }
            $sse->complete([
                'success' => (bool)($observed['success'] ?? true),
                'message' => (string)($observed['message'] ?? 'Operation completed.'),
                'operation' => $operation,
                'data' => \is_array($observed['data'] ?? null) ? $observed['data'] : [],
                'state' => \is_array($observed['state'] ?? null) ? $observed['state'] : [],
                'deferred_queue_progress' => (bool)($observed['deferred_queue_progress'] ?? false),
            ]);
            return;
        }

        $claim = $this->claimActiveOperationExecution($session, $adminId, $executionToken, $operation);
        if (!$claim['ok']) {
            $reason = (string)($claim['reason'] ?? '');
            if ($reason === 'duplicate_stream') {
                $this->logOperationSse('duplicate_connection', [
                    'public_id' => $publicId,
                    'operation' => $operation,
                ]);
                $sse->sendEvent('warning', [
                    'message' => (string)__('检测到重复的操作事件流连接，当前已切换为观察模式，将继续同步剩余进度。'),
                    'operation' => $operation,
                    'observer_mode' => true,
                ]);
                $observed = $this->observeDuplicateOperationStream($sse, $session, $adminId, $operation, $executionToken);
                if (!(bool)($observed['success'] ?? true)) {
                    $sse->sendError(
                        (string)($observed['message'] ?? __('操作执行失败')),
                        (int)($observed['http_code'] ?? 500)
                    );
                }
                $sse->complete([
                    'success' => (bool)($observed['success'] ?? true),
                    'message' => (string)($observed['message'] ?? __('操作执行完成')),
                    'operation' => $operation,
                    'data' => \is_array($observed['data'] ?? null) ? $observed['data'] : [],
                    'state' => \is_array($observed['state'] ?? null) ? $observed['state'] : [],
                ]);
                return;
            }
            $terminalMessage = (string)($claim['message'] ?? (string)__('该操作已结束或不可用'));
            $this->sendSseContractError($sse, 'OPERATION_NOT_ACTIVE', $terminalMessage, self::PARAMS_OPERATION_SSE, 409);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $sse->complete([
                'success' => false,
                'state' => $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true)
                ),
            ]);
            return;
        }

        $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];

        $stageCode = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stageCode, 'operation_started', (string)__('已开始执行操作'), ['operation' => $operation, 'page_type' => (string)($activeOperation['page_type'] ?? '')]);
        $sse->sendEvent('start', ['message' => __('已开始执行：%{operation}', ['operation' => $operation]), 'operation' => $operation]);
        RequestContext::set(RequestContext::SSE_WRITER_KEY, $sse);
        RequestContext::set(self::REQUEST_CTX_AI_CHUNK_FORWARDER, function (array $payload) use ($sse, $session, $adminId, $stageCode, $operation): void {
            $chunk = (string)($payload['chunk'] ?? '');
            if ($chunk === '') {
                return;
            }
            $region = \trim((string)($payload['region'] ?? ''));
            $message = $region !== ''
                ? (string)__('AI 片段（%{region}）：%{chunk}', ['region' => $region, 'chunk' => $chunk])
                : (string)__('AI 片段：%{chunk}', ['chunk' => $chunk]);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $stageCode,
                'ai_chunk',
                $message,
                [
                    'operation' => $operation,
                    'details' => [
                        'region' => $region,
                        'chunk' => $chunk,
                    ],
                ]
            );
            if ($sse->isAlive()) {
                $sse->sendEvent('chunk', [
                    'operation' => $operation,
                    'region' => $region,
                    'chunk' => $chunk,
                    'message' => $message,
                ]);
            }
        });

        try {
            $result = $this->runClaimedOperationSseBranch($sse, $session, $adminId, $operation, $activeOperation);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $statePayload = \is_array($result['state'] ?? null)
                ? $result['state']
                : $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true)
                );
            $sse->complete([
                'success' => true,
                'message' => (string)($result['message'] ?? __('操作执行完成')),
                'operation' => $operation,
                'data' => $result,
                'state' => $statePayload,
            ]);
        } catch (\Throwable $throwable) {
            $failedStatus = $operation === 'publish' ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $this->updateActiveOperation($session, $adminId, ['status' => 'error', 'message' => $throwable->getMessage()], $failedStatus, $operation === 'publish' ? AiSiteAgentSession::PUBLISH_STATUS_FAILED : null);
            $this->appendWorkspaceEvent($session->getId(), $adminId, $stageCode, 'operation_failed', (string)__('操作执行失败：%{message}', ['message' => $throwable->getMessage()]), ['operation' => $operation, 'page_type' => (string)($activeOperation['page_type'] ?? ''), 'details' => ['exception' => $throwable->getMessage()]], AiSiteAgentSessionEvent::LEVEL_ERROR);
            $httpCode = $this->inferThrowableHttpCode($throwable);
            $sse->sendError($throwable->getMessage(), $httpCode);
            if ($httpCode === 402) {
                $sse->close();
                return;
            }
            $sse->complete(['success' => false, 'message' => $throwable->getMessage(), 'operation' => $operation]);
        } finally {
            RequestContext::set(RequestContext::SSE_WRITER_KEY, true);
            RequestContext::remove(self::REQUEST_CTX_AI_CHUNK_FORWARDER);
        }
    }

    /**
     * @param array<string, mixed> $activeOperation
     *
     * @return array<string, mixed>
     */
    private function runClaimedOperationSseBranch(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        array $activeOperation
    ): array {
        return match ($operation) {
            'plan' => $this->runPlanOperationSseBranch($sse, $session, $adminId),
            'build' => $this->runBuildOperation($sse, $session, $adminId),
            'regenerate_page' => $this->runRegeneratePageOperation($sse, $session, $adminId, (string)($activeOperation['page_type'] ?? '')),
            'publish' => $this->runPublishOperation($sse, $session, $adminId),
            default => throw new \RuntimeException((string)__('未知操作：%{operation}（允许：%{allowed}）', [
                'operation' => $operation !== '' ? $operation : '(empty)',
                'allowed' => 'plan, build, regenerate_page, publish',
            ])),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function runPlanOperationSseBranch(SseWriter $sse, AiSiteAgentSession $session, int $adminId): array
    {
        $this->runPlanOperation($sse, $session, $adminId);

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true)
        );
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $status = \trim((string)($activeOperation['status'] ?? ''));
        $message = \trim((string)($activeOperation['message'] ?? ''));

        if ($status === 'error') {
            throw new \RuntimeException($message !== '' ? $message : (string)__('阶段一方案生成失败'));
        }

        return [
            'message' => $message !== '' ? $message : (string)__('阶段一方案生成完成'),
            'state' => $state,
            'active_operation' => $activeOperation,
            'plan_markdown' => (string)($scope['plan_markdown'] ?? ''),
            'plan_json' => \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
            'execution_blueprint' => \is_array($scope['execution_blueprint_draft'] ?? null) ? $scope['execution_blueprint_draft'] : [],
        ];
    }

    /**
     * EventSource reconnects can attach a second operation-sse connection while the
     * original executor is still running. Keep the duplicate connection in observer
     * mode so the UI continues receiving the remaining progress instead of ending early.
     *
     * @return array{success: bool, message: string, data: array<string, mixed>, state: array<string, mixed>, http_code: int, deferred_queue_progress?: bool}
     */
    private function observeDuplicateOperationStream(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        bool $queueCheckpointOnly = false
    ): array {
        // 增加超时时间以支持长时间的AI生成任务
        // 从240秒增加到600秒（10分钟），适应大型页面生成场景
        $maxIdleLoops = $this->getObserverMaxIdleLoops();
        $pollIntervalMs = 1000;
        $idleLoops = 0;

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $startedAtRaw = \trim((string)($activeOperation['started_at'] ?? ''));
        $lastEventId = 0;
        $queueId = (int)($activeOperation['queue_id'] ?? 0);
        $lastQueueProcess = '';
        $lastQueueResultLength = 0;
        $queueDispatchAttempted = false;

        $recentEvents = $this->filterObservedOperationEvents(
            $this->sessionService->listRecentEvents($session->getId(), $adminId, 160),
            $operation,
            $startedAtRaw,
            $lastEventId
        );
        $lastEventId = $this->forwardObservedOperationEvents($sse, $session, $adminId, $recentEvents, $lastEventId);
        $initialQueueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, $queueId);
        $dispatchProbe = $this->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $fresh,
            $adminId,
            $operation,
            $executionToken,
            $initialQueueRow,
            $queueDispatchAttempted
        );
        if ((bool)($dispatchProbe['attempted'] ?? false)) {
            $queueDispatchAttempted = true;
            $initialQueueRow = \is_array($dispatchProbe['queue'] ?? null) ? $dispatchProbe['queue'] : $initialQueueRow;
        }
        if (\is_array($initialQueueRow) && $initialQueueRow !== [] && $sse->isAlive()) {
            $this->emitQueueObserverQueueDetailEvents($sse, $initialQueueRow, $operation);
        }
        $lastQueueStatus = '';
        $lastQueuePid = 0;
        [$lastQueueProcess, $lastQueueResultLength, $lastQueueStatus, $lastQueuePid] = $this->forwardObservedQueueSignals(
            $sse,
            $initialQueueRow,
            $operation,
            $lastQueueProcess,
            $lastQueueResultLength,
            $lastQueueStatus,
            $lastQueuePid
        );

        if ($queueCheckpointOnly) {
            $freshCheckpoint = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $stateCheckpoint = $this->buildWorkspaceEventStatePayload(
                $this->buildWorkspaceState($freshCheckpoint, $adminId, 80, true)
            );

            return [
                'success' => true,
                'message' => (string)__('队列任务已提交，进度将异步刷新，无需保持本连接。'),
                'data' => $this->buildObservedOperationResultData($operation, $stateCheckpoint),
                'state' => $stateCheckpoint,
                'http_code' => 200,
                'deferred_queue_progress' => true,
            ];
        }

        $timedOut = false;
        // Keep observing until the connection closes or the operation settles.
        while ($sse->isAlive()) {
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $newEvents = $this->filterObservedOperationEvents(
                $this->sessionService->listEventsAfterId($session->getId(), $adminId, $lastEventId, 80),
                $operation,
                $startedAtRaw,
                $lastEventId
            );
            if ($newEvents !== []) {
                $lastEventId = $this->forwardObservedOperationEvents($sse, $session, $adminId, $newEvents, $lastEventId);
            }

            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
            $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $queueId = (int)($activeOperation['queue_id'] ?? $queueId);
            $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, $queueId);
            $dispatchProbe = $this->maybeAutoDispatchObservedPendingQueue(
                $sse,
                $fresh,
                $adminId,
                $operation,
                $executionToken,
                $queueRow,
                $queueDispatchAttempted
            );
            if ((bool)($dispatchProbe['attempted'] ?? false)) {
                $queueDispatchAttempted = true;
                $queueRow = \is_array($dispatchProbe['queue'] ?? null) ? $dispatchProbe['queue'] : $queueRow;
            }
            [$lastQueueProcess, $lastQueueResultLength, $lastQueueStatus, $lastQueuePid] = $this->forwardObservedQueueSignals(
                $sse,
                $queueRow,
                $operation,
                $lastQueueProcess,
                $lastQueueResultLength,
                $lastQueueStatus,
                $lastQueuePid
            );
            if (!$this->isObservedOperationStillRunning($activeOperation, $operation, $executionToken, $queueRow)) {
                break;
            }

            if ($maxIdleLoops > 0 && $idleLoops >= $maxIdleLoops && $this->isActiveOperationStale($activeOperation)) {
                $timedOut = true;
                // 记录超时日志，便于问题追踪和调试
                $this->logOperationSse('observation_timeout', [
                    'public_id' => $session->getPublicId(),
                    'operation' => $operation,
                    'idle_loops' => $idleLoops,
                    'timeout_seconds' => $maxIdleLoops * ($pollIntervalMs / 1000),
                    'active_operation' => $activeOperation,
                ]);
                break;
            }

            $sse->maybeHeartbeat();
            SchedulerSystem::yieldDelay($pollIntervalMs);
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true)
        );
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $status = \trim((string)($activeOperation['status'] ?? ''));
        $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, (int)($activeOperation['queue_id'] ?? $queueId));
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));

        $success = !$timedOut && !\in_array($status, ['error', 'cancelled', 'queued', 'running'], true);
        if ($queueStatus === 'done') {
            $success = true;
        } elseif (\in_array($queueStatus, ['error', 'stop'], true)) {
            $success = false;
        }
        $message = \trim((string)($activeOperation['message'] ?? ''));
        if ($timedOut) {
            // 提供更详细的超时信息和操作建议
            $message = (string)__('操作仍在执行中，但进度观察已超时（10分钟无响应）。这可能发生在大型页面生成或复杂任务中。建议：1）刷新页面查看最新状态；2）如果操作未完成，可重新触发；3）检查系统日志了解详细信息');
        } elseif ($message === '') {
            $message = $this->resolveObservedQueueMessage($queueRow, $success);
        }

        return [
            'success' => $success,
            'message' => $message,
            'data' => $this->buildObservedOperationResultData($operation, $state),
            'state' => $state,
            'http_code' => $success ? 200 : 409,
        ];
    }

    /**
     * @param array<string, mixed> $activeOperation
     */
    private function isObservedOperationStillRunning(
        array $activeOperation,
        string $operation,
        string $executionToken,
        ?array $queueRow = null
    ): bool
    {
        if (
            \trim((string)($activeOperation['operation'] ?? '')) !== $operation
            || \trim((string)($activeOperation['execution_token'] ?? '')) !== $executionToken
        ) {
            return false;
        }

        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        if (\in_array($queueStatus, ['done', 'error', 'stop'], true)) {
            return false;
        }

        return \in_array($activeStatus, ['queued', 'running'], true)
            || \in_array($queueStatus, ['pending', 'running'], true);
    }

    /**
     * 供观察模式 SSE 输出的队列行快照（不含完整 content/result，避免泄露敏感字段）。
     *
     * @param array<string, mixed> $queueRow
     *
     * @return array<string, mixed>
     */
    private function buildQueueObserverPublicSnapshot(array $queueRow): array
    {
        $queueId = (int)($queueRow['queue_id'] ?? 0);
        $name = \trim((string)($queueRow['name'] ?? ''));
        $module = \trim((string)($queueRow['module'] ?? ''));
        $bizKey = \trim((string)($queueRow['biz_key'] ?? ''));
        $status = \trim((string)($queueRow['status'] ?? ''));
        $pid = (int)($queueRow['pid'] ?? 0);
        $typeId = (int)($queueRow['type_id'] ?? 0);
        $finished = (int)($queueRow['finished'] ?? 0);
        $startAt = \trim((string)($queueRow['start_at'] ?? ''));
        $endAt = \trim((string)($queueRow['end_at'] ?? ''));

        $publicIdHint = '';
        $jobKey = '';
        $jobType = '';
        $jobStatus = '';
        $token = '';
        $tokenUsage = $this->normalizeAiSiteQueueTokenUsage($queueRow);
        $contentRaw = (string)($queueRow['content'] ?? '');
        if ($contentRaw !== '') {
            $decoded = \json_decode($contentRaw, true);
            if (\is_array($decoded)) {
                $pidStr = \trim((string)($decoded['public_id'] ?? ''));
                if ($pidStr !== '') {
                    if (\defined('DEV') && DEV) {
                        $publicIdHint = $pidStr;
                    } else {
                        $len = \strlen($pidStr);
                        $publicIdHint = $len > 12 ? \substr($pidStr, 0, 6) . '…' . \substr($pidStr, -4) : $pidStr;
                    }
                }
                $jobKey = \trim((string)($decoded['job_key'] ?? ''));
                $jobType = \trim((string)($decoded['job_type'] ?? ''));
                $jobStatus = \trim((string)($decoded['status'] ?? ''));
                $token = \trim((string)($decoded['token'] ?? ($decoded['execution_token'] ?? '')));
                $contentTokenUsage = $this->normalizeAiSiteQueueTokenUsage($decoded);
                foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $tokenKey) {
                    if ($tokenUsage[$tokenKey] === null && $contentTokenUsage[$tokenKey] !== null) {
                        $tokenUsage[$tokenKey] = $contentTokenUsage[$tokenKey];
                    }
                }
                if (!\is_array($tokenUsage['token_cost_meta'] ?? null) && \is_array($contentTokenUsage['token_cost_meta'] ?? null)) {
                    $tokenUsage['token_cost_meta'] = $contentTokenUsage['token_cost_meta'];
                }
            }
        }

        $effectiveJobStatus = $jobStatus !== '' ? $jobStatus : $status;
        if (\in_array($status, ['error', 'done', 'stop', 'cancelled'], true)) {
            $effectiveJobStatus = $status;
        }

        return [
            'queue_id' => $queueId,
            'name' => $name,
            'module' => $module,
            'biz_key' => $bizKey,
            'status' => $status,
            'pid' => $pid,
            'type_id' => $typeId,
            'finished' => $finished,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'public_id_hint' => $publicIdHint,
            'job_key' => $jobKey,
            'job_type' => $jobType,
            'job_status' => $effectiveJobStatus,
            'token' => $token,
            'token_usage' => $tokenUsage,
        ];
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string, mixed>|null}
     */
    private function normalizeAiSiteQueueTokenUsage(array $source): array
    {
        $nested = \is_array($source['token_usage'] ?? null) ? $source['token_usage'] : [];
        $input = $this->normalizeAiSiteQueueTokenCount(
            $nested['input_tokens']
            ?? $source['input_tokens']
            ?? $nested['prompt_tokens']
            ?? $source['prompt_tokens']
            ?? null
        );
        $output = $this->normalizeAiSiteQueueTokenCount(
            $nested['output_tokens']
            ?? $source['output_tokens']
            ?? $nested['completion_tokens']
            ?? $source['completion_tokens']
            ?? null
        );
        $total = $this->normalizeAiSiteQueueTokenCount(
            $nested['total_tokens']
            ?? $source['total_tokens']
            ?? null
        );
        if ($total === null && $input !== null && $output !== null) {
            $total = $input + $output;
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
            'token_cost_meta' => \is_array($nested['token_cost_meta'] ?? null)
                ? $nested['token_cost_meta']
                : (\is_array($source['token_cost_meta'] ?? null) ? $source['token_cost_meta'] : null),
        ];
    }

    private function normalizeAiSiteQueueTokenCount(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (\is_float($value)) {
            return $value >= 0 ? (int)\round($value) : null;
        }
        if (\is_string($value)) {
            $trimmed = \trim($value);
            if ($trimmed !== '' && \preg_match('/^\d+$/', $trimmed) === 1) {
                return (int)$trimmed;
            }
        }

        return null;
    }

    /**
     * 阶段一 plan 对应 weline_queue 行：queue_id + 快照 + process + result 尾部，供侧栏「任务进度」内嵌展示。
     *
     * @param array<string, mixed> $activeOperation
     *
     * @return array<string, mixed>|null
     */
    private function buildPlanStageQueueInfoPayload(AiSiteAgentSession $session, array $activeOperation): ?array
    {
        $queueId = 0;
        if (\trim((string)($activeOperation['operation'] ?? '')) === 'plan') {
            $queueId = (int)($activeOperation['queue_id'] ?? 0);
        }
        $queueRow = $this->findAiSiteOperationQueueRow($session, 'plan', $queueId);
        if (!\is_array($queueRow) || $queueRow === []) {
            return null;
        }
        return $this->buildQueueObserverPanelPayload($queueRow);
    }

    /**
     * @param array<string, mixed> $queueRow
     *
     * @return array<string, mixed>
     */
    private function buildQueueObserverPanelPayload(array $queueRow): array
    {
        $snap = $this->buildQueueObserverPublicSnapshot($queueRow);
        $process = \trim((string)($queueRow['process'] ?? ''));
        $result = (string)($queueRow['result'] ?? '');
        $max = 24000;
        if (\strlen($result) > $max) {
            $result = (string)__('…（以下仅显示末尾约 %{n} 字符）', ['n' => (string)$max]) . "\n" . \substr($result, -$max);
        }

        return [
            'queue_id' => (int)($snap['queue_id'] ?? 0),
            'snapshot' => $snap,
            'process' => $process,
            'result_log' => $result,
        ];
    }

    /**
     * 队列创建/连接观察流后，向前台打印可读的队列元数据（多行 detail_lines + 结构化快照）。
     *
     * @param array<string, mixed> $queueRow
     */
    private function emitQueueObserverQueueDetailEvents(SseWriter $sse, array $queueRow, string $operation): void
    {
        if (!$sse->isAlive()) {
            return;
        }
        $snap = $this->buildQueueObserverPublicSnapshot($queueRow);
        $lines = [
            (string)__('【队列】任务已就绪，以下为队列快照（进度请在工作区自动刷新）。'),
            (string)__('队列编号：%{id}', ['id' => (string)$snap['queue_id']]),
            (string)__('业务键 biz_key：%{k}', ['k' => ($snap['biz_key'] !== '' ? (string)$snap['biz_key'] : '-')]),
            (string)__('任务：%{name}（模块 %{module}）', [
                'name' => ($snap['name'] !== '' ? (string)$snap['name'] : '-'),
                'module' => ($snap['module'] !== '' ? (string)$snap['module'] : '-'),
            ]),
            (string)__('状态：%{status}；调度 PID：%{pid}', [
                'status' => (string)$snap['status'],
                'pid' => (string)$snap['pid'],
            ]),
            (string)__('类型 ID：%{tid}；完成标记：%{fin}', [
                'tid' => (string)$snap['type_id'],
                'fin' => (string)$snap['finished'],
            ]),
        ];
        if (($snap['start_at'] ?? '') !== '' || ($snap['end_at'] ?? '') !== '') {
            $lines[] = (string)__('开始/结束：%{s} / %{e}', [
                's' => ($snap['start_at'] !== '' ? (string)$snap['start_at'] : '-'),
                'e' => ($snap['end_at'] !== '' ? (string)$snap['end_at'] : '-'),
            ]);
        }
        if (($snap['public_id_hint'] ?? '') !== '') {
            $lines[] = (\defined('DEV') && DEV)
                ? (string)__('会话 public_id：%{h}', ['h' => (string)$snap['public_id_hint']])
                : (string)__('会话 public_id（脱敏）：%{h}', ['h' => (string)$snap['public_id_hint']]);
        }

        $sse->sendEvent('info', [
            'message' => (string)($lines[0] ?? ''),
            'detail_lines' => $lines,
            'queue_snapshot' => $snap,
            'queue_info' => $this->buildQueueObserverPanelPayload($queueRow),
            'operation' => $operation,
            'queue_id' => (int)$snap['queue_id'],
            'queue_status' => (string)$snap['status'],
            'observer_detail' => true,
        ]);
    }

    /**
     * @return array{0:string,1:int,2:string,3:int}
     */
    private function forwardObservedQueueSignals(
        SseWriter $sse,
        ?array $queueRow,
        string $operation,
        string $lastQueueProcess,
        int $lastQueueResultLength,
        string $lastQueueStatus,
        int $lastQueuePid
    ): array {
        if (!\is_array($queueRow) || $queueRow === []) {
            return [$lastQueueProcess, $lastQueueResultLength, $lastQueueStatus, $lastQueuePid];
        }

        $queueId = (int)($queueRow['queue_id'] ?? 0);
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        $queuePid = (int)($queueRow['pid'] ?? 0);
        $queueSnapshot = $this->buildQueueObserverPublicSnapshot($queueRow);
        $queuePanelInfo = $this->buildQueueObserverPanelPayload($queueRow);

        if ($queueStatus !== '' && $lastQueueStatus !== '' && $queueStatus !== $lastQueueStatus) {
            $sse->sendEvent('info', [
                'message' => (string)__('队列状态变更：%{from} → %{to}', [
                    'from' => $lastQueueStatus,
                    'to' => $queueStatus,
                ]),
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => $queueStatus,
                'queue_snapshot' => $queueSnapshot,
                'queue_info' => $queuePanelInfo,
                'observer_detail' => true,
            ]);
        }
        if ($lastQueuePid === 0 && $queuePid > 0) {
            $sse->sendEvent('info', [
                'message' => (string)__('队列已被 worker 领取执行（PID %{pid}）。', ['pid' => (string)$queuePid]),
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => $queueStatus,
                'queue_snapshot' => $queueSnapshot,
                'queue_info' => $queuePanelInfo,
                'observer_detail' => true,
            ]);
        }

        $nextQueueStatus = $queueStatus !== '' ? $queueStatus : $lastQueueStatus;

        $process = \trim((string)($queueRow['process'] ?? ''));
        if ($process !== '' && $process !== $lastQueueProcess) {
            if ($this->shouldSuppressObservedQueueProcessMirror($operation)) {
                $sse->sendEvent('info', [
                    'message' => '',
                    'operation' => $operation,
                    'queue_id' => $queueId,
                    'queue_status' => $queueStatus,
                    'queue_snapshot' => $queueSnapshot,
                    'queue_process' => $process,
                    'observer_detail' => true,
                    'queue_panel_update' => true,
                ]);
            } else {
                $sse->sendEvent('progress', [
                    'message' => $process,
                    'operation' => $operation,
                    'progress_percent' => 0,
                    'queue_id' => $queueId,
                    'queue_status' => $queueStatus,
                    'queue_snapshot' => $queueSnapshot,
                    'queue_process' => $process,
                ]);
            }
            $lastQueueProcess = $process;
        }

        $result = (string)($queueRow['result'] ?? '');
        $resultLength = \strlen($result);
        if ($resultLength < $lastQueueResultLength) {
            $lastQueueResultLength = 0;
        }
        if ($resultLength > $lastQueueResultLength) {
            $delta = \substr($result, $lastQueueResultLength);
            $lines = \preg_split("/\\r\\n|\\n|\\r/", $delta) ?: [];
            foreach ($lines as $line) {
                $line = \trim((string)$line);
                if ($line === '' || $this->shouldSkipObservedQueueResultLine($operation, $line)) {
                    continue;
                }
                $sse->sendEvent('chunk', [
                    'message' => $line,
                    'operation' => $operation,
                    'chunk' => $line . PHP_EOL,
                    'content' => $line . PHP_EOL,
                    'queue_id' => $queueId,
                    'queue_status' => $queueStatus,
                    'queue_snapshot' => $queueSnapshot,
                    'queue_process' => $process,
                    'queue_result_delta' => $line . PHP_EOL,
                ]);
            }
            $lastQueueResultLength = $resultLength;
        }

        return [$lastQueueProcess, $lastQueueResultLength, $nextQueueStatus, $queuePid];
    }

    private function shouldSuppressObservedQueueProcessMirror(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan'], true);
    }

    private function shouldSkipObservedQueueResultLine(string $operation, string $line): bool
    {
        if (!\in_array($operation, ['plan', 'task_plan'], true)) {
            return false;
        }

        return (bool)\preg_match(
            '/^\[\d{2}:\d{2}:\d{2}\]\s+(?:LOG|START|INFO|WARNING|PROGRESS|ERROR|DATA|AI_STREAM|PLAN_[A-Z0-9_]+|TASK_PLAN_[A-Z0-9_]+)\b/u',
            $line
        );
    }

    /**
     * @param array<string, mixed>|null $queueRow
     */
    private function resolveObservedQueueMessage(?array $queueRow, bool $success): string
    {
        if (\is_array($queueRow)) {
            $process = \trim((string)($queueRow['process'] ?? ''));
            if ($process !== '') {
                return $process;
            }
            $result = \trim((string)($queueRow['result'] ?? ''));
            if ($result !== '') {
                $lines = \preg_split("/\\r\\n|\\n|\\r/", $result) ?: [];
                $lines = \array_values(\array_filter(\array_map('trim', $lines), static fn(string $line): bool => $line !== ''));
                if ($lines !== []) {
                    return (string)\end($lines);
                }
            }
        }

        return $success ? (string)__('操作执行完成') : (string)__('操作执行失败');
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function filterObservedOperationEvents(
        array $events,
        string $operation,
        string $startedAtRaw,
        int $afterEventId
    ): array {
        $startedAtTs = $startedAtRaw !== '' ? (\strtotime($startedAtRaw) ?: 0) : 0;
        $filtered = [];
        foreach ($events as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $eventId = (int)($event['event_id'] ?? 0);
            if ($eventId <= $afterEventId) {
                continue;
            }
            if (!$this->isObservedOperationEventRelevant($event, $operation, $startedAtTs)) {
                continue;
            }
            $filtered[] = $event;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isObservedOperationEventRelevant(array $event, string $operation, int $startedAtTs): bool
    {
        $eventType = \trim((string)($event['event_type'] ?? ''));
        if (!\in_array($eventType, [
            'start',
            'info',
            'warning',
            'progress',
            'chunk',
            'error',
            'operation_started',
            'operation_progress',
            'ai_raw_chunk',
            'plan_chunk',
            'plan_saved',
            'plan_generated',
            'plan_refined',
            'plan_rebuilt',
            'task_plan_generated',
            'task_plan_refined',
            'task_plan_rebuilt',
            'ai_chunk',
            'shared_component_generated',
            'page_generated',
            'task_completed',
            'operation_failed',
        ], true)) {
            return false;
        }

        $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
        if (\trim((string)($payload['operation'] ?? '')) !== $operation) {
            return false;
        }

        if ($startedAtTs <= 0) {
            return true;
        }

        $eventTs = \strtotime(\trim((string)($event['create_time'] ?? '')));
        return $eventTs === false || $eventTs >= $startedAtTs;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function forwardObservedOperationEvents(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $events,
        int $lastEventId
    ): int {
        foreach ($events as $event) {
            $eventType = \trim((string)($event['event_type'] ?? ''));
            $eventName = $this->mapObservedOperationEventName($eventType);
            if ($eventName === '') {
                continue;
            }
            $payload = $this->buildObservedOperationEventPayload($session, $adminId, $eventType, $event);
            if ($payload === null) {
                continue;
            }
            $eventId = (int)($event['event_id'] ?? 0);
            $sse->sendEvent($eventName, $payload, $eventId > 0 ? $eventId : null);
            if ($eventId > $lastEventId) {
                $lastEventId = $eventId;
            }
        }

        return $lastEventId;
    }

    private function mapObservedOperationEventName(string $eventType): string
    {
        return match ($eventType) {
            'start' => 'start',
            'info' => 'info',
            'warning' => 'warning',
            'progress' => 'progress',
            'chunk' => 'chunk',
            'error' => 'error',
            'operation_started' => 'start',
            'operation_progress' => 'progress',
            'ai_raw_chunk' => 'chunk',
            'plan_chunk' => 'chunk',
            'plan_saved', 'plan_generated', 'plan_refined', 'plan_rebuilt', 'task_plan_generated', 'task_plan_refined', 'task_plan_rebuilt' => 'info',
            'ai_chunk' => 'chunk',
            'shared_component_generated' => 'shared_component_generated',
            'page_generated' => 'page_generated',
            'task_completed' => 'task_completed',
            'operation_failed' => 'error',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function buildObservedOperationEventPayload(
        AiSiteAgentSession $session,
        int $adminId,
        string $eventType,
        array $event
    ): ?array {
        $payload = \is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $details = \is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $state = $this->buildObservedOperationEventStatePayload($session, $adminId, $eventType, $payload);

        return match ($eventType) {
            'start' => [
                'message' => (string)($payload['message'] ?? __('已开始执行操作')),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
            ],
            'info', 'warning', 'plan_saved', 'plan_generated', 'plan_refined', 'plan_rebuilt', 'task_plan_generated', 'task_plan_refined', 'task_plan_rebuilt' => [
                'event_type' => $eventType,
                'message' => (string)($payload['message'] ?? ''),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
                'details' => $details,
                'state' => $state,
            ],
            'progress' => [
                'message' => (string)($payload['message'] ?? ''),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
            ],
            'ai_raw_chunk' => [
                'message' => (string)($payload['message'] ?? $payload['chunk'] ?? $payload['content'] ?? ''),
                'operation' => (string)($payload['operation'] ?? ''),
                'chunk' => (string)($payload['chunk'] ?? $payload['content'] ?? $payload['message'] ?? ''),
                'content' => (string)($payload['content'] ?? $payload['chunk'] ?? $payload['message'] ?? ''),
            ],
            'plan_chunk' => [
                'message' => (string)($payload['message'] ?? $payload['chunk'] ?? $payload['content'] ?? ''),
                'operation' => (string)($payload['operation'] ?? ''),
                'chunk' => (string)($payload['chunk'] ?? $payload['content'] ?? $payload['message'] ?? ''),
                'content' => (string)($payload['content'] ?? $payload['chunk'] ?? $payload['message'] ?? ''),
            ],
            'chunk' => [
                'message' => (string)($payload['message'] ?? $payload['chunk'] ?? ''),
                'operation' => (string)($payload['operation'] ?? ''),
                'region' => (string)($details['region'] ?? $payload['region'] ?? ''),
                'chunk' => (string)($details['chunk'] ?? $payload['chunk'] ?? $payload['content'] ?? ''),
                'content' => (string)($payload['content'] ?? $payload['chunk'] ?? ''),
            ],
            'error' => [
                'message' => (string)($payload['message'] ?? __('操作执行失败')),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
                'details' => $details,
                'http_code' => isset($payload['code']) ? (int)$payload['code'] : 500,
            ],
            'operation_started' => [
                'message' => (string)($payload['message'] ?? __('已开始执行操作')),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
            ],
            'operation_progress' => [
                'message' => (string)($payload['message'] ?? ''),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
            ],
            'ai_chunk' => [
                'message' => (string)($payload['message'] ?? ''),
                'operation' => (string)($payload['operation'] ?? ''),
                'region' => (string)($details['region'] ?? $payload['region'] ?? ''),
                'chunk' => (string)($details['chunk'] ?? $payload['chunk'] ?? ''),
            ],
            'shared_component_generated' => $this->buildObservedSharedComponentPayload($session, $adminId, $payload, $details),
            'page_generated' => $this->buildObservedPageGeneratedPayload($session, $adminId, $payload, $details),
            'task_completed' => $this->buildObservedTaskCompletedPayload($session, $adminId, $payload, $details),
            'operation_failed' => [
                'message' => (string)($payload['message'] ?? __('操作执行失败')),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
                'details' => $details,
                'http_code' => 500,
            ],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function buildObservedOperationEventStatePayload(
        AiSiteAgentSession $session,
        int $adminId,
        string $eventType,
        array $payload
    ): ?array {
        $payloadState = \is_array($payload['state'] ?? null) ? $payload['state'] : null;
        if ($payloadState !== null) {
            return $payloadState;
        }

        if (!\in_array($eventType, [
            'plan_saved',
            'plan_generated',
            'plan_refined',
            'plan_rebuilt',
            'task_plan_generated',
            'task_plan_refined',
            'task_plan_rebuilt',
        ], true)) {
            return null;
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        return $this->buildWorkspaceState($fresh, $adminId, 80, true);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function buildObservedSharedComponentPayload(
        AiSiteAgentSession $session,
        int $adminId,
        array $payload,
        array $details
    ): array {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true)
        );
        $region = (string)($details['region'] ?? $payload['region'] ?? $payload['shared_region'] ?? '');

        return [
            'message' => (string)($payload['message'] ?? ''),
            'operation' => (string)($payload['operation'] ?? ''),
            'region' => $region,
            'shared_region' => $region,
            'component_code' => (string)($details['component_code'] ?? ''),
            'virtual_theme_id' => (int)($details['virtual_theme_id'] ?? $state['virtual_theme_id'] ?? 0),
            'state' => $state,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function buildObservedPageGeneratedPayload(
        AiSiteAgentSession $session,
        int $adminId,
        array $payload,
        array $details
    ): array {
        $pageType = (string)($payload['page_type'] ?? '');
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true),
            $pageType !== '' ? [$pageType] : []
        );
        $pagesByType = \is_array($state['pagebuilder_pages_by_type'] ?? null) ? $state['pagebuilder_pages_by_type'] : [];
        $pageRow = \is_array($pagesByType[$pageType] ?? null) ? $pagesByType[$pageType] : [];

        return [
            'message' => (string)($payload['message'] ?? ''),
            'operation' => (string)($payload['operation'] ?? ''),
            'page_type' => $pageType,
            'page_label' => (string)(Page::getPageTypes()[$pageType] ?? $pageType),
            'page_id' => (int)($pageRow['page_id'] ?? 0),
            'virtual_theme_id' => (int)($details['virtual_theme_id'] ?? $state['virtual_theme_id'] ?? 0),
            'section_code' => (string)($details['section_code'] ?? ''),
            'section_count' => (int)($details['section_count'] ?? 0),
            'state' => $state,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function buildObservedTaskCompletedPayload(
        AiSiteAgentSession $session,
        int $adminId,
        array $payload,
        array $details
    ): array {
        $pageType = (string)($payload['page_type'] ?? '');
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true),
            $pageType !== '' ? [$pageType] : []
        );

        return [
            'message' => (string)($payload['message'] ?? ''),
            'operation' => (string)($payload['operation'] ?? ''),
            'task_key' => (string)($payload['task_key'] ?? ''),
            'task_type' => (string)($payload['task_type'] ?? ''),
            'page_type' => $pageType,
            'section_code' => (string)($payload['section_code'] ?? $details['section_code'] ?? ''),
            'shared_region' => (string)($payload['shared_region'] ?? $payload['region'] ?? $details['region'] ?? ''),
            'task_session_id' => (string)($payload['task_session_id'] ?? ''),
            'task_sse_channel' => (string)($payload['task_sse_channel'] ?? ''),
            'task_runtime_context' => \is_array($payload['task_runtime_context'] ?? null)
                ? $payload['task_runtime_context']
                : [],
            'task_summary' => \is_array($payload['task_summary'] ?? null)
                ? $payload['task_summary']
                : [],
            'state' => \is_array($payload['state'] ?? null) ? $payload['state'] : $state,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildObservedOperationResultData(string $operation, array $state): array
    {
        return match ($operation) {
            'build' => [
                'draft_website_id' => (int)($state['draft_website_id'] ?? 0),
                'virtual_theme_id' => (int)($state['virtual_theme_id'] ?? 0),
                'page_types' => \array_values(\array_map('strval', \array_keys(\is_array($state['pagebuilder_pages_by_type'] ?? null) ? $state['pagebuilder_pages_by_type'] : []))),
            ],
            'publish' => [
                'redirect_url' => $this->url->getBackendUrl('pagebuilder/backend/page/index'),
            ],
            default => [],
        };
    }

    private function inferThrowableHttpCode(\Throwable $throwable): int
    {
        for ($cursor = $throwable; $cursor !== null; $cursor = $cursor->getPrevious()) {
            $message = \strtolower(\trim(\strip_tags($cursor->getMessage())));
            if ($message === '') {
                continue;
            }
            if (\str_contains($message, 'http 402')
                || \str_contains($message, 'insufficient balance')
                || \str_contains($message, '余额不足')
                || \str_contains($message, '额度不足')
            ) {
                return 402;
            }
        }

        return 500;
    }

    /**
     * @return list<string>
     */
    private function chunkStringForSse(string $content, int $chunkLength = 220): array
    {
        $content = \str_replace(["\r\n", "\r"], "\n", $content);
        if ($content === '') {
            return [];
        }
        $chunkLength = \max(1, $chunkLength);
        $chunks = [];
        $length = \mb_strlen($content);
        for ($offset = 0; $offset < $length; $offset += $chunkLength) {
            $chunks[] = (string)\mb_substr($content, $offset, $chunkLength);
        }

        return $chunks;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function shouldRejectTaskPlanGenerationSource(
        array $scope,
        string $generationSource,
        bool $deterministicFallbackAllowed = false
    ): bool {
        if ((int)($scope['fake_mode'] ?? 0) === 1 || $generationSource === 'ai') {
            return false;
        }

        return !($deterministicFallbackAllowed && \in_array($generationSource, ['deterministic', 'fallback'], true));
    }

    private function registerAiChunkForwarder(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $stageCode,
        string $operation
    ): void {
        RequestContext::set(RequestContext::SSE_WRITER_KEY, $sse);
        RequestContext::set(self::REQUEST_CTX_AI_CHUNK_FORWARDER, function (array $payload) use ($sse, $session, $adminId, $stageCode, $operation): void {
            $chunk = (string)($payload['chunk'] ?? '');
            if ($chunk === '') {
                return;
            }
            $region = \trim((string)($payload['region'] ?? ''));
            $message = $region !== ''
                ? 'AI chunk [' . $region . ']: ' . $chunk
                : 'AI chunk: ' . $chunk;
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $stageCode,
                'ai_chunk',
                $message,
                [
                    'operation' => $operation,
                    'details' => [
                        'region' => $region,
                        'chunk' => $chunk,
                    ],
                ]
            );
            if ($sse->isAlive()) {
                $sse->sendEvent('chunk', [
                    'operation' => $operation,
                    'region' => $region,
                    'chunk' => $chunk,
                    'message' => $message,
                ]);
            }
        });
    }

    private function clearAiChunkForwarder(): void
    {
        RequestContext::set(RequestContext::SSE_WRITER_KEY, true);
        RequestContext::remove(self::REQUEST_CTX_AI_CHUNK_FORWARDER);
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
     *   plan_queue_info:array<string, mixed>|null,
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
        $startedAt = \microtime(true);
        $normalized = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        // 读取工作区状态时避免触发外部 AI 生成，防止首开/轮询被远程调用阻塞。
        $profileStartedAt = \microtime(true);
        $normalized['website_profile'] = $this->profileGenerationService->generate($normalized, false);
        $this->logHotPathStage('build_workspace_state.website_profile', $profileStartedAt, [
            'public_id' => $session->getPublicId(),
        ]);
        $normalized['page_types'] = $this->scopeCompatibilityService->resolveScopedPageTypes($normalized);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($normalized['workspace_track'] ?? ''));
        $normalized = $this->buildTaskService->ensureTaskScope(
            $normalized,
            \is_array($normalized['website_profile']) ? $normalized['website_profile'] : [],
            $workspaceTrack
        );
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
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts(
            $normalized['page_type_layouts'] ?? [],
            $normalized['page_types']
        );
        $normalized['page_type_layouts'] = $pageTypeLayouts;

        $virtualPagesStartedAt = \microtime(true);
        $virtualPagesByType = $this->scopeCompatibilityService->buildVirtualPagesByType($normalized['page_types'], $normalized, false);
        $virtualPagesByType = $this->decorateVirtualPagesWithUrls($session->getPublicId(), $virtualThemeId, $virtualPagesByType);
        $this->logHotPathStage('build_workspace_state.virtual_pages', $virtualPagesStartedAt, [
            'public_id' => $session->getPublicId(),
            'page_type_count' => \count($normalized['page_types']),
        ]);
        $normalized['virtual_pages_by_type'] = $virtualPagesByType;
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($normalized['workspace_track'] ?? ''));
        $taskSummaryStartedAt = \microtime(true);
        $taskSummary = $this->buildTaskService->summarize($normalized);
        $this->logHotPathStage('build_workspace_state.task_summary', $taskSummaryStartedAt, [
            'public_id' => $session->getPublicId(),
            'task_total' => (int)($taskSummary['total'] ?? 0),
            'task_pending' => (int)($taskSummary['pending'] ?? 0),
        ]);
        $pendingGenerationPageTypes = $this->resolvePendingGenerationPageTypesFromTasks($normalized, $normalized['page_types']);
        if ($pendingGenerationPageTypes === []) {
            $pendingGenerationPageTypes = $this->resolvePendingGenerationPageTypes(
                $normalized['page_types'],
                $workspaceTrack,
                $pagesByType,
                $pageTypeLayouts,
                $virtualPagesByType
            );
        }

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

        // 已物化出 Page 时始终走 preview/full（与是否已发布无关）。workspace-preview 仅依赖 scope 内虚拟 blocks，
        // 若落库内容与 scope 不同步会仍显示主题静态占位，而任务进度已显示完成。
        if ((int)$normalized['preview_page_id'] > 0) {
            $normalized = \array_replace(
                $normalized,
                $this->visualUrlService->resolveUrls((int)$normalized['preview_page_id'], $virtualThemeId)
            );
        } else {
            $normalized = \array_replace($normalized, $prePublishVisualUrls);
        }

        $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $activeOperation = \is_array($normalized['active_operation'] ?? null) ? $normalized['active_operation'] : [];
        $planQueueInfo = $this->buildPlanStageQueueInfoPayload($session, $activeOperation);
        if (
            \trim((string)($activeOperation['operation'] ?? '')) === 'plan'
            && \in_array(\trim((string)($activeOperation['status'] ?? '')), ['queued', 'running'], true)
            && \is_array($planQueueInfo['snapshot'] ?? null)
        ) {
            $queueStatus = \trim((string)($planQueueInfo['snapshot']['status'] ?? ''));
            if ($queueStatus === 'error') {
                $queueProcess = \trim((string)($planQueueInfo['process'] ?? ''));
                $queueResult = \trim((string)($planQueueInfo['result_log'] ?? ''));
                $activeOperation['status'] = 'error';
                $activeOperation['message'] = $queueProcess !== ''
                    ? $queueProcess
                    : ($queueResult !== ''
                        ? (string)__('阶段一方案队列执行失败，请查看队列日志并重试。')
                        : (string)__('阶段一方案队列执行失败，请重试。'));
                $activeOperation['updated_at'] = \date('Y-m-d H:i:s');
                $normalized['active_operation'] = $activeOperation;
            } elseif (\in_array($queueStatus, ['done', 'stop', 'cancelled'], true)) {
                $activeOperation['status'] = 'done';
                if (\trim((string)($activeOperation['message'] ?? '')) === '') {
                    $activeOperation['message'] = (string)__('阶段一方案队列已完成。');
                }
                $activeOperation['updated_at'] = \date('Y-m-d H:i:s');
                $normalized['active_operation'] = $activeOperation;
            }
        }
        $siteReady = (int)($normalized['site_ready'] ?? 1) === 1;
        $titleOk = \trim((string)($normalized['website_profile']['site_title'] ?? '')) !== '';
        $taskReady = $this->taskSummaryIndicatesCompleted($taskSummary);
        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            $canPublish = $siteReady
                && $titleOk
                && ($taskReady || $this->scopeCompatibilityService->htmlTrackHasCompleteBlocks($virtualPagesByType, $normalized['page_types']));
        } else {
            $canPublish = ($virtualThemeId > 0 || $taskReady)
                && ($pendingGenerationPageTypes === [] || $taskReady)
                && $titleOk
                && $siteReady;
        }
        $activeOperationStatus = \trim((string)($activeOperation['status'] ?? ''));
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        if (
            \in_array($activeOperationStatus, ['queued', 'running'], true)
            && $this->shouldReclaimStaleActiveOperation($activeOperation)
            && $this->isActiveOperationStale($activeOperation)
        ) {
            $activeOperation = \array_replace($activeOperation, [
                'status' => 'cancelled',
                'message' => (string)__('检测到历史构建流已中断，下一次继续生成将按任务单位重新开始'),
                'updated_at' => \date('Y-m-d H:i:s'),
                'details' => \array_replace(
                    \is_array($activeOperation['details'] ?? null) ? $activeOperation['details'] : [],
                    ['reason' => 'stale_active_operation']
                ),
            ]);
            $normalized['active_operation'] = $activeOperation;
            $activeOperationStatus = 'cancelled';
        }
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
        $workspaceStatus = $this->resolveWorkspaceStatus($session, $normalized, $canPublish, $pendingGenerationPageTypes);
        $normalized['workspace_status'] = $workspaceStatus;
        $normalized['can_publish'] = $canPublish ? 1 : 0;
        $normalized['build_summary'] = $this->buildSummary($virtualPagesByType, $activeOperation, $canPublish, $pendingGenerationPageTypes, $taskSummary);
        $virtualThemePlan = \is_array($normalized['virtual_theme_plan'] ?? null) ? $normalized['virtual_theme_plan'] : [];
        $hasVirtualThemePlan = (
            \is_array($virtualThemePlan['draft'] ?? null) && $virtualThemePlan['draft'] !== []
        ) || (
            \is_array($virtualThemePlan['confirmed'] ?? null) && $virtualThemePlan['confirmed'] !== []
        );
        $taskPlanConfirmedAt = (string)($virtualThemePlan['confirmed_at'] ?? '');
        $taskPlanStructured = \is_array($normalized['task_plan_structured'] ?? null) ? $normalized['task_plan_structured'] : [];
        $taskPlanDraftStructured = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
        $taskPlanConfirmedStructured = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
        $taskPlanDraftMarkdown = (string)($normalized['task_plan_markdown'] ?? ($virtualThemePlan['draft_markdown'] ?? ''));
        $taskPlanConfirmedMarkdown = (string)($virtualThemePlan['confirmed_markdown'] ?? '');

        $eventsStartedAt = \microtime(true);
        $events = $this->sessionService->listRecentEvents($session->getId(), $adminId, $eventLimit);
        $this->logHotPathStage('build_workspace_state.events', $eventsStartedAt, [
            'public_id' => $session->getPublicId(),
            'event_limit' => $eventLimit,
            'event_count' => \count($events),
        ]);
        $topLogs = \array_slice($events, -12);

        if ($persist) {
            $persistStartedAt = \microtime(true);
            $this->persistNormalizedState($session, $adminId, $normalized, $stage, $draftWebsiteId, $virtualThemeId);
            $this->logHotPathStage('build_workspace_state.persist', $persistStartedAt, [
                'public_id' => $session->getPublicId(),
                'draft_website_id' => $draftWebsiteId,
                'virtual_theme_id' => $virtualThemeId,
            ]);
        }

        $clientVirtualPagesByType = $this->htmlBlocksBuildService->stripServerOnlyVirtualPages($virtualPagesByType);
        $clientScope = $normalized;
        $clientScope['virtual_pages_by_type'] = $clientVirtualPagesByType;
        unset($clientScope['_ai_generated_shared_components']);
        if (\is_array($clientScope['shared_components'] ?? null)) {
            foreach ($clientScope['shared_components'] as $region => $component) {
                if (!\is_array($component)) {
                    continue;
                }
                unset($component['phtml']);
                $clientScope['shared_components'][$region] = $component;
            }
        }

        $result = [
            'public_id' => $session->getPublicId(),
            'stage' => $stage,
            'stage_label' => $this->getStageLabel($stage),
            'workspace_status' => $workspaceStatus,
            'publish_status' => $session->getPublishStatus(),
            'can_publish' => $canPublish,
            'workspace_track' => $workspaceTrack,
            'site_ready' => (int)($normalized['site_ready'] ?? 1),
            'website_id' => $draftWebsiteId,
            'virtual_theme_id' => $virtualThemeId,
            'website_profile' => \is_array($normalized['website_profile']) ? $normalized['website_profile'] : [],
            'draft_website_id' => $draftWebsiteId,
            'pagebuilder_pages_by_type' => $pagesByType,
            'page_type_layouts' => \is_array($normalized['page_type_layouts'] ?? null) ? $normalized['page_type_layouts'] : [],
            'virtual_pages_by_type' => $clientVirtualPagesByType,
            'pending_generation_page_types' => $pendingGenerationPageTypes,
            'build_task_summary' => $taskSummary,
            'plan' => [
                'json' => \is_array($normalized['plan_json'] ?? null) ? $normalized['plan_json'] : [],
                'markdown' => (string)($normalized['plan_markdown'] ?? ''),
                'structured' => \is_array($normalized['plan_structured'] ?? null) ? $normalized['plan_structured'] : (\is_array($normalized['plan_json'] ?? null) ? $normalized['plan_json'] : []),
                'execution_blueprint' => \is_array($normalized['execution_blueprint_draft'] ?? null) && $normalized['execution_blueprint_draft'] !== []
                    ? $normalized['execution_blueprint_draft']
                    : (\is_array($normalized['execution_blueprint'] ?? null) ? $normalized['execution_blueprint'] : []),
            ],
            'plan_json' => \is_array($normalized['plan_json'] ?? null) ? $normalized['plan_json'] : [],
            'plan_markdown' => (string)($normalized['plan_markdown'] ?? ''),
            'plan_structured' => \is_array($normalized['plan_structured'] ?? null) ? $normalized['plan_structured'] : (\is_array($normalized['plan_json'] ?? null) ? $normalized['plan_json'] : []),
            'plan_confirmed' => (int)($normalized['plan_confirmed'] ?? 0),
            'has_execution_blueprint' => \is_array($normalized['execution_blueprint'] ?? null) && $normalized['execution_blueprint'] !== [],
            'plan_confirmed_at' => (string)($normalized['plan_confirmed_at'] ?? ''),
            'plan_sse_url' => $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/plan-sse'),
            'refine_plan_page_url' => $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-refine-plan-page'),
            // T36: 历史确认方案数据
            'confirmed_plan_markdown' => (string)($normalized['plan_markdown'] ?? ''),
            'confirmed_plan_signature' => (string)($normalized['execution_blueprint_confirmed_signature'] ?? ''),
            'task_plan' => [
                'markdown' => $taskPlanDraftMarkdown !== '' ? $taskPlanDraftMarkdown : $taskPlanConfirmedMarkdown,
                'structured' => $taskPlanStructured !== []
                    ? $taskPlanStructured
                    : ($taskPlanDraftStructured !== [] ? $taskPlanDraftStructured : $taskPlanConfirmedStructured),
                'virtual_theme_plan' => $virtualThemePlan,
            ],
            'task_plan_markdown' => $taskPlanDraftMarkdown !== '' ? $taskPlanDraftMarkdown : $taskPlanConfirmedMarkdown,
            'task_plan_structured' => $taskPlanStructured !== []
                ? $taskPlanStructured
                : ($taskPlanDraftStructured !== [] ? $taskPlanDraftStructured : $taskPlanConfirmedStructured),
            'task_plan_confirmed' => (int)($normalized['task_plan_confirmed'] ?? 0),
            'task_plan_confirmed_at' => $taskPlanConfirmedAt,
            'has_virtual_theme_plan' => $hasVirtualThemePlan,
            'has_pending_build_tasks' => (int)($taskSummary['pending'] ?? 0) > 0,
            'task_plan_sse_url' => $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-task-plan-sse'),
            'auto_start_build_after_stream' => false,
            'preview_page_options' => \is_array($normalized['preview_page_options']) ? $normalized['preview_page_options'] : [],
            'preview_page_id' => (int)$normalized['preview_page_id'],
            'preview_page_type' => (string)$normalized['preview_page_type'],
            'preview_full_url' => (string)($normalized['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($normalized['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($normalized['visual_edit_url'] ?? ''),
            'pre_publish_visual_urls' => \is_array($normalized['pre_publish_visual_urls'] ?? null) ? $normalized['pre_publish_visual_urls'] : $prePublishVisualUrls,
            'active_operation' => $activeOperation,
            'plan_queue_info' => $planQueueInfo,
            'build_summary' => \is_array($normalized['build_summary'] ?? null) ? $normalized['build_summary'] : [],
            'top_logs' => $topLogs,
            'scope' => $clientScope,
            'events' => $events,
            'last_event_id' => $this->resolveWorkspaceLastEventId($events),
        ];

        $this->logHotPathStage('build_workspace_state.total', $startedAt, [
            'public_id' => $session->getPublicId(),
            'event_limit' => $eventLimit,
            'persist' => $persist ? 1 : 0,
            'response_event_count' => \count($events),
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasPhaseOnePlanAvailable(array $scope): bool
    {
        return (int)($scope['plan_confirmed'] ?? 0) === 1
            || \trim((string)($scope['plan_markdown'] ?? '')) !== ''
            || (\is_array($scope['plan_json'] ?? null) && $scope['plan_json'] !== [])
            || (\is_array($scope['plan_structured'] ?? null) && $scope['plan_structured'] !== [])
            || (\is_array($scope['execution_blueprint_draft'] ?? null) && $scope['execution_blueprint_draft'] !== [])
            || (\is_array($scope['execution_blueprint'] ?? null) && $scope['execution_blueprint'] !== []);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildWorkspaceStreamSnapshotPayload(array $state): array
    {
        return $this->buildWorkspaceSseStatePayload($state, [], true);
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildWorkspaceEventStatePayload(array $state, array $pageTypes = []): array
    {
        return $this->buildWorkspaceSseStatePayload($state, $pageTypes, false);
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function buildWorkspaceSseStatePayload(array $state, array $pageTypes, bool $workspaceStream): array
    {
        $resolvedPageTypes = $this->normalizeWorkspaceSsePageTypes($pageTypes, (string)($state['preview_page_type'] ?? ''));
        $payload = [
            'public_id' => (string)($state['public_id'] ?? ''),
            'stage' => (string)($state['stage'] ?? ''),
            'stage_label' => (string)($state['stage_label'] ?? ''),
            'workspace_status' => (string)($state['workspace_status'] ?? ''),
            'publish_status' => (string)($state['publish_status'] ?? ''),
            'can_publish' => !empty($state['can_publish']),
            'workspace_track' => (string)($state['workspace_track'] ?? ''),
            'site_ready' => (int)($state['site_ready'] ?? 1),
            'website_id' => (int)($state['website_id'] ?? 0),
            'virtual_theme_id' => (int)($state['virtual_theme_id'] ?? 0),
            'draft_website_id' => (int)($state['draft_website_id'] ?? 0),
            'pagebuilder_pages_by_type' => \is_array($state['pagebuilder_pages_by_type'] ?? null) ? $state['pagebuilder_pages_by_type'] : [],
            'preview_page_options' => \is_array($state['preview_page_options'] ?? null) ? $state['preview_page_options'] : [],
            'preview_page_id' => (int)($state['preview_page_id'] ?? 0),
            'preview_page_type' => (string)($state['preview_page_type'] ?? ''),
            'preview_full_url' => (string)($state['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($state['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($state['visual_edit_url'] ?? ''),
            'pre_publish_visual_urls' => \is_array($state['pre_publish_visual_urls'] ?? null) ? $state['pre_publish_visual_urls'] : [],
            'active_operation' => \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [],
            'plan_queue_info' => \is_array($state['plan_queue_info'] ?? null) ? $state['plan_queue_info'] : null,
            'build_task_summary' => \is_array($state['build_task_summary'] ?? null) ? $state['build_task_summary'] : [],
            'build_summary' => \is_array($state['build_summary'] ?? null) ? $state['build_summary'] : [],
            'pending_generation_page_types' => \is_array($state['pending_generation_page_types'] ?? null) ? $state['pending_generation_page_types'] : [],
        ];

        if ($workspaceStream) {
            $payload['auto_start_build_after_stream'] = !empty($state['auto_start_build_after_stream']);
            $payload['last_event_id'] = $this->resolveWorkspaceLastEventId(\is_array($state['events'] ?? null) ? $state['events'] : []);
            $payload['events'] = $this->pruneWorkspaceEventsForSse(\is_array($state['events'] ?? null) ? $state['events'] : [], 6);
            $payload['top_logs'] = $this->pruneWorkspaceEventsForSse(\is_array($state['top_logs'] ?? null) ? $state['top_logs'] : [], 6);
            return $payload;
        }

        if ($resolvedPageTypes !== []) {
            $payload['virtual_pages_by_type'] = $this->selectWorkspaceVirtualPagesForSse($state, $resolvedPageTypes);
        }

        return $payload;
    }

    /**
     * @param list<string> $pageTypes
     * @return list<string>
     */
    private function normalizeWorkspaceSsePageTypes(array $pageTypes, string $fallbackPageType = ''): array
    {
        $resolved = [];
        foreach ($pageTypes as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '' || \in_array($pageType, $resolved, true)) {
                continue;
            }
            $resolved[] = $pageType;
        }
        if ($resolved === [] && $fallbackPageType !== '') {
            $resolved[] = $fallbackPageType;
        }
        return $resolved;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function selectWorkspaceVirtualPagesForSse(array $state, array $pageTypes): array
    {
        $virtualPages = \is_array($state['virtual_pages_by_type'] ?? null) ? $state['virtual_pages_by_type'] : [];
        if ($virtualPages === []) {
            return [];
        }

        $selected = [];
        foreach ($pageTypes as $pageType) {
            if ($pageType === '' || !isset($virtualPages[$pageType]) || !\is_array($virtualPages[$pageType])) {
                continue;
            }
            $selected[$pageType] = $virtualPages[$pageType];
        }

        return $selected;
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function pruneWorkspaceEventsForSse(array $rows, int $limit = 6): array
    {
        $sliced = \array_slice($rows, -\max(1, $limit));
        $result = [];
        foreach ($sliced as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $payload = \is_array($row['payload'] ?? null)
                ? $row['payload']
                : (\is_array($row['payload_json'] ?? null) ? $row['payload_json'] : []);
            $message = \trim((string)($payload['message'] ?? $row['message'] ?? $row['event_type'] ?? ''));
            $payloadOut = [];
            foreach (['message', 'operation', 'page_type', 'progress_percent'] as $key) {
                if (\array_key_exists($key, $payload)) {
                    $payloadOut[$key] = $payload[$key];
                }
            }

            $details = \is_array($payload['details'] ?? null) ? $payload['details'] : [];
            if ($details !== []) {
                $detailsOut = [];
                foreach (['reason', 'region', 'section_code', 'component_code'] as $detailKey) {
                    if (\array_key_exists($detailKey, $details)) {
                        $detailsOut[$detailKey] = $details[$detailKey];
                    }
                }
                if ($detailsOut !== []) {
                    $payloadOut['details'] = $detailsOut;
                }
            }

            $event = [
                'event_id' => (int)($row['event_id'] ?? $row['ai_site_agent_event_id'] ?? 0),
                'event_type' => (string)($row['event_type'] ?? ''),
                'stage_code' => (string)($row['stage_code'] ?? ''),
                'level' => (string)($row['level'] ?? ''),
                'message' => $message,
            ];
            if ($payloadOut !== []) {
                $event['payload'] = $payloadOut;
            }
            if (!empty($row['create_time'])) {
                $event['create_time'] = (string)$row['create_time'];
            }
            $result[] = $event;
        }
        return $result;
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function filterWorkspaceEventsByStage(array $rows, string $streamStage): array
    {
        if ($streamStage === '') {
            return $rows;
        }
        $filtered = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if ($this->workspaceEventMatchesStage($row, $streamStage)) {
                $filtered[] = $row;
            }
        }
        return $filtered;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function filterWorkspaceSnapshotByStage(array $snapshot, string $streamStage): array
    {
        if ($streamStage === '') {
            return $snapshot;
        }
        $snapshot['events'] = $this->filterWorkspaceEventsByStage(
            \is_array($snapshot['events'] ?? null) ? $snapshot['events'] : [],
            $streamStage
        );
        $snapshot['top_logs'] = $this->filterWorkspaceEventsByStage(
            \is_array($snapshot['top_logs'] ?? null) ? $snapshot['top_logs'] : [],
            $streamStage
        );
        return $snapshot;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function workspaceEventMatchesStage(array $event, string $streamStage): bool
    {
        $stageCode = \trim((string)($event['stage_code'] ?? ''));
        if ($stageCode === $streamStage) {
            return true;
        }
        $payload = \is_array($event['payload'] ?? null)
            ? $event['payload']
            : (\is_array($event['payload_json'] ?? null) ? $event['payload_json'] : []);
        $operation = \trim((string)($payload['operation'] ?? ''));
        $eventType = \trim((string)($event['event_type'] ?? ''));
        if ($streamStage === 'plan') {
            return $operation === 'plan'
                || \in_array($eventType, ['plan_chunk', 'plan_generated', 'plan_saved', 'plan_refined', 'plan_rebuilt'], true);
        }
        if ($streamStage === 'task_plan') {
            return $operation === 'task_plan'
                || \in_array($eventType, ['task_plan_generated', 'task_plan_refined', 'task_plan_rebuilt'], true);
        }
        return false;
    }

    private function normalizeWorkspaceStreamStage(string $stage): string
    {
        $normalized = \trim(\strtolower($stage));
        if ($normalized === '') {
            return '';
        }
        if (!\preg_match('/^[a-z0-9_\\-]{1,32}$/', $normalized)) {
            return '';
        }
        return $normalized;
    }

    /**
     * @param array<int, mixed> $events
     */
    private function resolveWorkspaceLastEventId(array $events): int
    {
        $lastId = 0;
        foreach ($events as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $lastId = \max($lastId, (int)($event['event_id'] ?? $event['ai_site_agent_event_id'] ?? 0));
        }
        return $lastId;
    }

    /**
     * HTML 工作台首屏只需要轻量状态，避免把大块 scope/events 重复塞进模板。
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function pruneWorkspaceStateForView(array $state): array
    {
        if (\is_array($state['scope'] ?? null)) {
            $state['scope'] = $this->pruneWorkspaceScopeForView($state['scope']);
        }

        if (\is_array($state['plan'] ?? null)) {
            unset($state['plan']['execution_blueprint']);
        }
        if (\is_array($state['task_plan'] ?? null)) {
            unset($state['task_plan']['virtual_theme_plan']);
        }

        unset(
            $state['events'],
            $state['plan_json']
        );

        return $state;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function pruneWorkspaceScopeForView(array $scope): array
    {
        unset(
            $scope['pagebuilder_pages_by_type'],
            $scope['virtual_pages_by_type'],
            $scope['preview_page_options'],
            $scope['page_type_layouts'],
            $scope['events'],
            $scope['top_logs'],
            $scope['build_task_summary'],
            $scope['build_summary'],
            $scope['active_operation'],
            $scope['pre_publish_visual_urls'],
            $scope['plan_json'],
            $scope['plan_structured'],
            $scope['execution_blueprint'],
            $scope['execution_blueprint_draft'],
            $scope['execution_blueprint_page'],
            $scope['task_plan_structured'],
            $scope['virtual_theme_plan'],
            $scope['build_blueprint'],
            $scope['build_blueprint_page'],
            $scope['build_tasks'],
            $scope['virtual_theme_build_tree'],
            $scope['materialized_pages_by_type'],
            $scope['shared_components'],
            $scope['_ai_generated_shared_components']
        );

        return $scope;
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pagesByType
     * @return list<string>
     */
    private function resolvePendingGenerationPageTypes(
        array $pageTypes,
        string $workspaceTrack,
        array $pagesByType,
        array $pageTypeLayouts,
        array $virtualPagesByType
    ): array
    {
        $pending = [];
        foreach ($pageTypes as $pageType) {
            if (!\is_string($pageType) || $pageType === '') {
                continue;
            }
            if (!$this->isPageTypeGenerationComplete($workspaceTrack, $pageType, $pagesByType, $pageTypeLayouts, $virtualPagesByType)) {
                $pending[] = $pageType;
            }
        }

        return $pending;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $pageTypes
     * @return list<string>
     */
    private function resolvePendingGenerationPageTypesFromTasks(array $scope, array $pageTypes): array
    {
        $summary = $this->buildTaskService->summarize($scope);
        $groups = \is_array($summary['groups'] ?? null) ? $summary['groups'] : [];
        if ($groups === []) {
            return [];
        }

        $pending = [];
        foreach ($groups as $groupKey => $group) {
            if (!\is_array($group)) {
                continue;
            }
            $pageType = \trim((string)($group['page_type'] ?? ''));
            if ($pageType === '' || !\in_array($pageType, $pageTypes, true)) {
                continue;
            }
            if ((int)($group['pending'] ?? 0) > 0 || (int)($group['running'] ?? 0) > 0 || (int)($group['failed'] ?? 0) > 0) {
                $pending[] = $pageType;
            }
        }

        return \array_values(\array_unique($pending));
    }

    /**
     * @param array<string, mixed> $taskSummary
     */
    private function taskSummaryIndicatesCompleted(array $taskSummary): bool
    {
        if ((int)($taskSummary['total'] ?? 0) <= 0) {
            return false;
        }

        return (int)($taskSummary['pending'] ?? 0) === 0
            && (int)($taskSummary['running'] ?? 0) === 0
            && (int)($taskSummary['failed'] ?? 0) === 0;
    }

    /**
     * @param array<string, array<string, mixed>> $pagesByType
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @param array<string, array<string, mixed>> $virtualPagesByType
     */
    private function isPageTypeGenerationComplete(
        string $workspaceTrack,
        string $pageType,
        array $pagesByType,
        array $pageTypeLayouts,
        array $virtualPagesByType
    ): bool {
        $pageId = (int)($pagesByType[$pageType]['page_id'] ?? 0);
        if ($pageId <= 0) {
            return false;
        }

        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            $blocks = \is_array($virtualPagesByType[$pageType]['blocks'] ?? null) ? $virtualPagesByType[$pageType]['blocks'] : [];

            return $blocks !== [];
        }

        $layout = \is_array($pageTypeLayouts[$pageType] ?? null)
            ? $pageTypeLayouts[$pageType]
            : $this->scopeCompatibilityService->normalizeLayoutConfig([], $pageType);
        $headerCode = \trim((string)($layout['header']['component'] ?? ''));
        $footerCode = \trim((string)($layout['footer']['component'] ?? ''));
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];

        return $headerCode !== '' && $footerCode !== '' && $content !== [];
    }

    /**
     * @param array<string, mixed> $activeOperation
     * @param list<string> $pendingGenerationPageTypes
     */
    private function shouldAutoStartBuildAfterWorkspaceStream(string $workspaceStatus, array $activeOperation, array $pendingGenerationPageTypes): bool
    {
        if ($pendingGenerationPageTypes === []) {
            return false;
        }

        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        if (\in_array($activeStatus, ['queued', 'running'], true)) {
            return false;
        }

        return !\in_array(
            $workspaceStatus,
            [
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING,
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED,
            ],
            true
        );
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
    private function resolveWorkspaceStatus(
        AiSiteAgentSession $session,
        array $scope,
        bool $canPublish,
        array $pendingGenerationPageTypes = []
    ): string
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
        $track = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if ($track === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            $vps = \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [];
            $pts = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
            if ($this->scopeCompatibilityService->htmlTrackHasCompleteBlocks($vps, $pts)) {
                return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_EDITING;
            }
        }
        if ($track === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME && $pendingGenerationPageTypes !== []) {
            if ((int)($scope['virtual_theme_id'] ?? 0) > 0) {
                return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
            }
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
    private function buildSummary(
        array $virtualPagesByType,
        array $activeOperation,
        bool $canPublish,
        array $pendingGenerationPageTypes = [],
        array $taskSummary = []
    ): array
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
            'pending_page_count' => \count($pendingGenerationPageTypes),
            'pending_page_types' => \array_values($pendingGenerationPageTypes),
            'last_generated_at' => $generatedAt,
            'active_operation' => \trim((string)($activeOperation['operation'] ?? '')),
            'can_publish' => $canPublish,
            'task_summary' => $taskSummary,
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
     * @param list<string> $pageTypes
     * @param array<string, mixed> $websiteProfile
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @return array{
     *   pagebuilder_pages_by_type:array<string, array<string, mixed>>,
     *   preview_page_id:int,
     *   preview_page_type:string
     * }
     */
    private function materializeGeneratedPages(
        string $workspaceTrack,
        int $websiteId,
        array $websiteProfile,
        array $pageTypes,
        array $pageTypeLayouts,
        array $virtualPagesByType
    ): array {
        if ($websiteId <= 0 || $pageTypes === [] || $virtualPagesByType === []) {
            return [
                'pagebuilder_pages_by_type' => [],
                'preview_page_id' => 0,
                'preview_page_type' => '',
            ];
        }

        $pageTypes = $this->scopeCompatibilityService->normalizePageTypes($pageTypes);

        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            return $this->materializationService->materializeHtml(
                $websiteId,
                $websiteProfile,
                $pageTypes,
                $virtualPagesByType
            );
        }

        return $this->materializationService->materialize(
            $websiteId,
            $websiteProfile,
            $pageTypes,
            $pageTypeLayouts,
            $virtualPagesByType
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array{
     *   pagebuilder_pages_by_type?:array<string, array<string, mixed>>,
     *   preview_page_id?:int,
     *   preview_page_type?:string
     * } $materialized
     * @return array<string, mixed>
     */
    private function mergeMaterializedPagesIntoScope(array $scope, array $materialized): array
    {
        $currentPages = $this->scopeCompatibilityService->normalizePagebuilderPagesByType($scope['pagebuilder_pages_by_type'] ?? []);
        $newPages = \is_array($materialized['pagebuilder_pages_by_type'] ?? null)
            ? $this->scopeCompatibilityService->normalizePagebuilderPagesByType($materialized['pagebuilder_pages_by_type'])
            : [];
        $scope['pagebuilder_pages_by_type'] = \array_replace($currentPages, $newPages);

        $previewPageType = \trim((string)($materialized['preview_page_type'] ?? ''));
        if ($previewPageType !== '') {
            $scope['preview_page_type'] = $previewPageType;
        }

        $previewPageId = (int)($materialized['preview_page_id'] ?? 0);
        if ($previewPageId > 0) {
            $scope['preview_page_id'] = $previewPageId;
        }

        return $scope;
    }

    private function emitBuildEnvironmentReady(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $pageType,
        string $pageLabel,
        int $pageId,
        int $virtualThemeId
    ): void {
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true),
            [$pageType]
        );
        $sse->sendEvent('environment_ready', [
            'message' => (string)__('编辑环境已准备好，可先调整已生成页面'),
            'page_type' => $pageType,
            'page_label' => $pageLabel,
            'page_id' => $pageId,
            'virtual_theme_id' => $virtualThemeId,
            'state' => $state,
        ]);
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
        if (
            \in_array($activeStatus, ['queued', 'running'], true)
            && $this->shouldReclaimStaleActiveOperation($activeOperation)
            && $this->isActiveOperationStale($activeOperation)
        ) {
            $scope['active_operation'] = \array_replace($activeOperation, [
                'status' => 'cancelled',
                'message' => (string)__('检测到历史操作长时间无进展，已自动回收并允许重新开始'),
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'operation_cancelled',
                (string)__('历史操作已超时回收，可重新发起构建'),
                ['details' => ['reason' => 'stale_active_operation']]
            );
            $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        }
        if (\in_array($activeStatus, ['queued', 'running'], true)) {
            $runningOperation = \trim((string)($activeOperation['operation'] ?? ''));
            $runningExecutionToken = \trim((string)($activeOperation['execution_token'] ?? ''));
            return [
                'success' => false,
                'message' => __('当前已有正在执行的操作，请先等待完成'),
                'operation' => $runningOperation,
                'execution_token' => $runningExecutionToken,
                'stream_url' => ($runningOperation !== '' && $runningExecutionToken !== '')
                    ? $this->buildOperationStreamUrl($session->getPublicId(), $runningExecutionToken)
                    : '',
            ];
        }

        $scope = \array_replace($scope, $scopePatch);
        $scope['page_types'] = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if ($pageType !== '' && !\in_array($pageType, $scope['page_types'], true)) {
            return ['success' => false, 'message' => __('所选页面类型不在当前工作区中')];
        }

        $executionToken = \bin2hex(\random_bytes(16));
        $queueId = 0;
        $queueDispatch = [
            'attempted' => false,
            'started' => false,
            'pid' => 0,
            'queue_id' => 0,
            'reason' => 'not_queued',
            'message' => '',
            'process_name' => '',
        ];
        $operationEnvelope = $this->buildOperationQueueEnvelope($session, $operation, $executionToken, 'queued');
        $scope['active_operation'] = \array_replace([
            'operation' => $operation,
            'execution_token' => $executionToken,
            'status' => 'queued',
            'page_type' => $pageType,
            'started_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
            'message' => (string)__('等待开始'),
        ], $operationEnvelope);
        if ($operation === 'plan') {
            $scope['active_operation']['details'] = [
                'plan_locale' => (string)($scope['plan_locale'] ?? ''),
                'page_types' => \array_values(\array_map('strval', \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [])),
            ];
        }
        $scope['workspace_status'] = $workspaceStatus;

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->setStage($session->getId(), $adminId, $stage);
        if ($operation === 'publish') {
            $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHING);
        }
        $freshForQueue = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        if ($this->shouldEnqueueOperation($operation)) {
            try {
                $queueId = $this->enqueueOperationQueueTask($freshForQueue, $adminId, $operation, $executionToken, $scopePatch);
            } catch (\Throwable $throwable) {
                $failedWorkspaceStatus = $operation === 'publish'
                    ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED
                    : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
                $this->updateActiveOperation(
                    $freshForQueue,
                    $adminId,
                    ['status' => 'error', 'message' => $throwable->getMessage()],
                    $failedWorkspaceStatus,
                    $operation === 'publish' ? AiSiteAgentSession::PUBLISH_STATUS_FAILED : null
                );
                throw $throwable;
            }
            $queueDispatch['queue_id'] = $queueId;

            $freshForQueue = $this->sessionService->loadById($session->getId(), $adminId) ?? $freshForQueue;
            $queueScope = $this->scopeCompatibilityService->normalizeScope($freshForQueue->getScopeArray());
            $queueActiveOperation = \is_array($queueScope['active_operation'] ?? null) ? $queueScope['active_operation'] : [];
            if (
                \trim((string)($queueActiveOperation['operation'] ?? '')) === $operation
                && \trim((string)($queueActiveOperation['execution_token'] ?? '')) === $executionToken
            ) {
                $queueScope['active_operation'] = \array_replace($queueActiveOperation, [
                    'queue_id' => $queueId,
                    'updated_at' => \date('Y-m-d H:i:s'),
                ]);
                $this->sessionService->replaceScope($freshForQueue->getId(), $adminId, $queueScope);
                $freshForQueue = $this->sessionService->loadById($session->getId(), $adminId) ?? $freshForQueue;
            }
        }
        if ($queueId > 0) {
            $freshForDispatch = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $queueDispatch = $this->ensureAiSiteQueueWorkerDispatched(
                $freshForDispatch,
                $adminId,
                $operation,
                $queueId,
                $executionToken
            );
        }
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stage, 'operation_queued', (string)__('已加入操作队列'), ['operation' => $operation, 'page_type' => $pageType]);

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        return [
            'success' => true,
            'message' => __('操作已启动'),
            'execution_token' => $executionToken,
            'queue_id' => $queueId,
            'operation' => $operation,
            'stream_url' => $this->buildOperationStreamUrl($fresh->getPublicId(), $executionToken),
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
            'queue_dispatch' => $queueDispatch,
        ];
    }

    private function shouldEnqueueOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan', 'build'], true);
    }

    private function shouldSelfDispatchAiSiteQueueOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan'], true);
    }

    /**
     * @param array<string, mixed> $scopePatch
     */
    private function enqueueOperationQueueTask(
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        array $scopePatch = []
    ): int {
        $queueClass = match ($operation) {
            'plan' => \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class,
            'task_plan' => \GuoLaiRen\PageBuilder\Queue\AiSiteTaskPlanQueue::class,
            'build' => \GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue::class,
            default => '',
        };
        if ($queueClass === '') {
            return 0;
        }

        $content = \array_replace($this->buildOperationQueueEnvelope($session, $operation, $executionToken, 'queued'), [
            'public_id' => $session->getPublicId(),
            'admin_id' => $adminId,
            'execution_token' => $executionToken,
            'operation' => $operation,
            'stage' => $this->resolveAiSiteQueueStage($operation),
        ]);
        if ($operation === 'build') {
            $content['scope_patch'] = $scopePatch;
        }

        $created = w_query('queue', 'create', [
            'class' => $queueClass,
            'name' => 'PageBuilder ' . $operation . ' #' . \substr($executionToken, 0, 12),
            'module' => 'GuoLaiRen_PageBuilder',
            'content' => $content,
            'status' => 'pending',
            'auto' => true,
            'biz_key' => $this->buildAiSiteQueueBizKey((int)$session->getId(), $operation),
        ]);
        $queueId = (int)(\is_array($created) ? ($created['queue_id'] ?? 0) : 0);
        if ($queueId <= 0 || !(\is_array($created) && ($created['success'] ?? false))) {
            throw new \RuntimeException((string)__('创建队列任务失败。'));
        }
        $queueCreatedHook = RequestContext::get(self::REQUEST_CTX_QUEUE_CREATED_HOOK);
        if (\is_callable($queueCreatedHook)) {
            $queueCreatedHook([
                'queue_id' => $queueId,
                'operation' => $operation,
                'execution_token' => $executionToken,
                'public_id' => $session->getPublicId(),
                'session_id' => (int)$session->getId(),
                'admin_id' => $adminId,
            ]);
        }

        return $queueId;
    }

    /**
     * @return array{job_key?: string, job_type?: string, status?: string, token?: string}
     */
    private function buildOperationQueueEnvelope(
        AiSiteAgentSession $session,
        string $operation,
        string $executionToken,
        string $status
    ): array {
        $jobType = $this->resolveAiSiteQueueJobType($operation);
        if ($jobType === '') {
            return [];
        }

        return [
            'job_key' => $this->buildAiSiteQueueJobKey((int)$session->getId(), $jobType),
            'job_type' => $jobType,
            'status' => $status,
            'token' => $executionToken,
        ];
    }

    /**
     * PageBuilder AI 建站队列入库业务键：用于 weline_queue.biz_key 索引精确定位
     */
    /**
     * @param array<string, mixed>|null $queueRow
     *
     * @return array{attempted: bool, started: bool, queue: array<string, mixed>|null, message: string}
     */
    private function maybeAutoDispatchObservedPendingQueue(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        ?array $queueRow,
        bool $alreadyAttempted
    ): array {
        if ($alreadyAttempted || !\is_array($queueRow) || $queueRow === []) {
            return ['attempted' => false, 'started' => false, 'queue' => $queueRow, 'message' => ''];
        }

        $queueId = (int)($queueRow['queue_id'] ?? 0);
        $status = \trim((string)($queueRow['status'] ?? ''));
        $pid = (int)($queueRow['pid'] ?? 0);
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $activeMatchesCurrent = \trim((string)($activeOperation['operation'] ?? '')) === $operation
            && \trim((string)($activeOperation['execution_token'] ?? '')) === $executionToken
            && \in_array($activeStatus, ['queued', 'running'], true);
        $queueContent = \json_decode((string)($queueRow['content'] ?? ''), true);
        $queueExecutionToken = \is_array($queueContent) ? \trim((string)($queueContent['execution_token'] ?? '')) : '';
        $recoverableSettledQueue = \in_array($status, [\Weline\Queue\Model\Queue::status_error, \Weline\Queue\Model\Queue::status_stop], true)
            && $activeMatchesCurrent
            && ($queueExecutionToken === '' || $queueExecutionToken === $executionToken);
        $needsDispatch = $status === \Weline\Queue\Model\Queue::status_pending
            || ($status === \Weline\Queue\Model\Queue::status_running && $pid <= 0)
            || $recoverableSettledQueue;
        if (!$needsDispatch || $queueId <= 0) {
            return ['attempted' => false, 'started' => false, 'queue' => $queueRow, 'message' => ''];
        }

        if ($recoverableSettledQueue && $sse->isAlive()) {
            $sse->sendEvent('warning', [
                'message' => (string)__('检测到队列上次异常结束，正在尝试自动恢复执行。'),
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => $status,
                'observer_detail' => true,
            ]);
        }

        $dispatch = $this->ensureAiSiteQueueWorkerDispatched(
            $session,
            $adminId,
            $operation,
            $queueId,
            $executionToken,
            true
        );
        $message = (string)($dispatch['message'] ?? '');
        $updatedQueue = $this->findAiSiteOperationQueueRow($session, $operation, $queueId);

        if ($message !== '' && $sse->isAlive()) {
            $sse->sendEvent((bool)($dispatch['started'] ?? false) ? 'info' : 'warning', [
                'message' => $message,
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => (string)($updatedQueue['status'] ?? $queueRow['status'] ?? ''),
                'observer_detail' => true,
            ]);
        }

        return [
            'attempted' => (bool)($dispatch['attempted'] ?? false),
            'started' => (bool)($dispatch['started'] ?? false),
            'queue' => \is_array($updatedQueue) && $updatedQueue !== [] ? $updatedQueue : $queueRow,
            'message' => $message,
        ];
    }

    /**
     * @return array{
     *     attempted: bool,
     *     started: bool,
     *     pid: int,
     *     queue_id: int,
     *     reason: string,
     *     message: string,
     *     process_name: string
     * }
     */
    private function ensureAiSiteQueueWorkerDispatched(
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        int $queueId,
        string $executionToken,
        bool $force = false
    ): array {
        $base = [
            'attempted' => false,
            'started' => false,
            'pid' => 0,
            'queue_id' => $queueId,
            'reason' => 'noop',
            'message' => '',
            'process_name' => '',
        ];
        if ($queueId <= 0 || !$this->shouldSelfDispatchAiSiteQueueOperation($operation)) {
            return $base;
        }

        $queue = $this->loadAiSiteQueueModel($queueId);
        if (!$queue instanceof \Weline\Queue\Model\Queue || (int)$queue->getId() <= 0) {
            return \array_replace($base, [
                'attempted' => true,
                'reason' => 'queue_missing',
                'message' => (string)__('未找到待派发的队列记录。'),
            ]);
        }

        $status = \trim((string)$queue->getStatus());
        $pid = (int)$queue->getPid();
        $processName = $this->buildAiSiteQueueProcessName($queue);
        if (!$force) {
            if (\in_array($status, [\Weline\Queue\Model\Queue::status_done, \Weline\Queue\Model\Queue::status_error, \Weline\Queue\Model\Queue::status_stop], true)) {
                return \array_replace($base, [
                    'reason' => 'queue_settled',
                    'process_name' => $processName,
                ]);
            }
            if ($status === \Weline\Queue\Model\Queue::status_running && $pid > 0 && \Weline\Cron\Helper\Process::isProcessRunning($pid)) {
                return \array_replace($base, [
                    'started' => true,
                    'pid' => $pid,
                    'reason' => 'already_running',
                    'message' => (string)__('队列 worker 已在执行中。'),
                    'process_name' => $processName,
                ]);
            }
        }

        $existingPid = \Weline\Cron\Helper\Process::getPidByName($processName);
        if ($existingPid > 0) {
            $message = $this->formatAiSiteQueueDispatchMessage($existingPid, 'detected');
            $this->markAiSiteQueueAsRunning($queue, $existingPid, $message);
            $this->appendWorkspaceEvent(
                (int)$session->getId(),
                $adminId,
                $this->resolveAiSiteQueueStage($operation),
                'operation_progress',
                $message,
                [
                    'operation' => $operation,
                    'queue_id' => $queueId,
                    'details' => [
                        'dispatch_mode' => 'existing_process',
                        'queue_pid' => $existingPid,
                        'execution_token' => $executionToken,
                    ],
                ]
            );

            return \array_replace($base, [
                'attempted' => true,
                'started' => true,
                'pid' => $existingPid,
                'reason' => 'process_exists',
                'message' => $message,
                'process_name' => $processName,
            ]);
        }

        $dispatch = $this->dispatchAiSiteQueueProcess($processName, [
            'queue_id' => $queueId,
            'operation' => $operation,
            'execution_token' => $executionToken,
            'public_id' => $session->getPublicId(),
        ]);
        $dispatchPid = (int)($dispatch['pid'] ?? 0);
        if ($dispatchPid <= 0) {
            $dispatchPid = \Weline\Cron\Helper\Process::getPidByName($processName);
        }

        if (!(bool)($dispatch['started'] ?? false) && $dispatchPid <= 0) {
            $message = (string)($dispatch['message'] ?? __('未能立即启动队列 worker，任务将继续等待系统调度。'));
            $this->appendWorkspaceEvent(
                (int)$session->getId(),
                $adminId,
                $this->resolveAiSiteQueueStage($operation),
                'operation_progress',
                $message,
                [
                    'operation' => $operation,
                    'queue_id' => $queueId,
                    'details' => [
                        'dispatch_mode' => 'self_heal_failed',
                        'execution_token' => $executionToken,
                    ],
                ]
            );
            $this->logOperationSse('queue_dispatch_failed', [
                'public_id' => $session->getPublicId(),
                'operation' => $operation,
                'queue_id' => $queueId,
                'execution_token' => $executionToken,
                'process_name' => $processName,
                'error' => (string)($dispatch['message'] ?? ''),
            ], 'warning');

            return \array_replace($base, [
                'attempted' => true,
                'reason' => 'dispatch_failed',
                'message' => $message,
                'process_name' => $processName,
            ]);
        }

        $message = $this->formatAiSiteQueueDispatchMessage($dispatchPid, 'started');
        $this->markAiSiteQueueAsRunning($queue, $dispatchPid, $message);
        $this->appendWorkspaceEvent(
            (int)$session->getId(),
            $adminId,
            $this->resolveAiSiteQueueStage($operation),
            'operation_progress',
            $message,
            [
                'operation' => $operation,
                'queue_id' => $queueId,
                'details' => [
                    'dispatch_mode' => 'pagebuilder_self_dispatch',
                    'queue_pid' => $dispatchPid,
                    'execution_token' => $executionToken,
                ],
            ]
        );
        $this->logOperationSse('queue_dispatch_started', [
            'public_id' => $session->getPublicId(),
            'operation' => $operation,
            'queue_id' => $queueId,
            'execution_token' => $executionToken,
            'pid' => $dispatchPid,
            'process_name' => $processName,
        ]);

        return \array_replace($base, [
            'attempted' => true,
            'started' => true,
            'pid' => $dispatchPid,
            'reason' => 'dispatched',
            'message' => $message,
            'process_name' => $processName,
        ]);
    }

    private function loadAiSiteQueueModel(int $queueId): ?\Weline\Queue\Model\Queue
    {
        /** @var \Weline\Queue\Model\Queue $queue */
        $queue = clone ObjectManager::getInstance(\Weline\Queue\Model\Queue::class);
        $queue->clearData()->load($queueId);

        return (int)$queue->getId() > 0 ? $queue : null;
    }

    private function buildAiSiteQueueProcessName(\Weline\Queue\Model\Queue $queue): string
    {
        $queueName = \Weline\Cron\Helper\Process::initTaskName('queue-' . $queue->getName() . '-' . (int)$queue->getId());

        return PHP_BINARY . ' ' . BP . 'bin' . DS . 'w'
            . ' queue:run --id=' . (int)$queue->getId()
            . " --name '{$queueName}'";
    }

    private function formatAiSiteQueueDispatchMessage(int $pid, string $mode): string
    {
        if ($mode === 'detected') {
            return $pid > 0
                ? (string)__('检测到现有队列 worker，已恢复状态同步（PID %{pid}）。', ['pid' => $pid])
                : (string)__('检测到现有队列 worker，已恢复状态同步。');
        }

        return $pid > 0
            ? (string)__('队列已在后台启动 worker（PID %{pid}）。', ['pid' => $pid])
            : (string)__('队列已在后台启动 worker，正在等待 PID 同步。');
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array{started: bool, pid: int, message: string}
     */
    private function dispatchAiSiteQueueProcess(string $processName, array $meta = []): array
    {
        $override = RequestContext::get(self::REQUEST_CTX_QUEUE_DISPATCHER);
        if (\is_callable($override)) {
            try {
                return $this->normalizeAiSiteQueueDispatchResult($override($processName, $meta));
            } catch (\Throwable $throwable) {
                return [
                    'started' => false,
                    'pid' => 0,
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        $pid = \Weline\Cron\Helper\Process::create($processName);
        if ($pid <= 0) {
            SchedulerSystem::usleep(200000);
            $pid = \Weline\Cron\Helper\Process::getPidByName($processName);
        }

        return [
            'started' => $pid > 0,
            'pid' => \max(0, $pid),
            'message' => $pid > 0 ? '' : (string)__('派发后未检测到队列 worker 进程。'),
        ];
    }

    /**
     * @return array{started: bool, pid: int, message: string}
     */
    private function normalizeAiSiteQueueDispatchResult(mixed $raw): array
    {
        if (\is_array($raw)) {
            return [
                'started' => (bool)($raw['started'] ?? ($raw['success'] ?? false)),
                'pid' => (int)($raw['pid'] ?? 0),
                'message' => \trim((string)($raw['message'] ?? '')),
            ];
        }
        if (\is_int($raw)) {
            return [
                'started' => $raw > 0,
                'pid' => \max(0, $raw),
                'message' => '',
            ];
        }
        if (\is_bool($raw)) {
            return [
                'started' => $raw,
                'pid' => 0,
                'message' => '',
            ];
        }

        return [
            'started' => false,
            'pid' => 0,
            'message' => \is_scalar($raw) ? (string)$raw : '',
        ];
    }

    private function markAiSiteQueueAsRunning(\Weline\Queue\Model\Queue $queue, int $pid = 0, string $resultLine = ''): void
    {
        $nextPid = \max(0, $pid);
        $nextResult = $this->appendAiSiteQueueResultLine((string)$queue->getResult(), $resultLine);
        $shouldSave = false;

        if (\trim((string)$queue->getStatus()) !== \Weline\Queue\Model\Queue::status_running) {
            $queue->setStatus(\Weline\Queue\Model\Queue::status_running);
            $shouldSave = true;
        }
        if ((int)$queue->getPid() !== $nextPid) {
            $queue->setPid($nextPid);
            $shouldSave = true;
        }
        if (\trim((string)$queue->getStartAt()) === '') {
            $queue->setStartAt(\date('Y-m-d H:i:s'));
            $shouldSave = true;
        }
        if ($nextResult !== (string)$queue->getResult()) {
            $queue->setResult($nextResult);
            $shouldSave = true;
        }

        if ($shouldSave) {
            $queue->save();
        }
    }

    private function appendAiSiteQueueResultLine(string $result, string $line): string
    {
        $normalizedLine = \trim($line);
        if ($normalizedLine === '' || \str_contains($result, $normalizedLine)) {
            return $result;
        }
        $trimmed = \rtrim($result);

        return $trimmed === '' ? $normalizedLine : $trimmed . PHP_EOL . $normalizedLine;
    }

    private function buildAiSiteQueueBizKey(int $sessionId, string $operation): string
    {
        $raw = 'glr_aisite:session:' . $sessionId
            . ':stage:' . $this->resolveAiSiteQueueStage($operation)
            . ':operation:' . $operation;
        if (\strlen($raw) > 191) {
            return \substr($raw, 0, 191);
        }

        return $raw;
    }

    private function buildAiSiteQueueJobKey(int $sessionId, string $jobType): string
    {
        $raw = 'glr_aisite:session:' . $sessionId . ':job:' . $jobType;
        if (\strlen($raw) > 191) {
            return \substr($raw, 0, 191);
        }

        return $raw;
    }

    private function resolveAiSiteQueueJobType(string $operation): string
    {
        return match ($operation) {
            'plan' => 'stage1.requirement_expand',
            default => '',
        };
    }

    private function resolveAiSiteQueueStage(string $operation): string
    {
        return match ($operation) {
            'plan' => AiSiteAgentSession::STAGE_PLAN,
            'task_plan', 'build' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'publish' => AiSiteAgentSession::STAGE_PUBLISH,
            default => 'workspace',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAiSiteOperationQueueRow(
        AiSiteAgentSession $session,
        string $operation,
        int $queueId = 0
    ): ?array {
        if ($queueId > 0) {
            $row = w_query('queue', 'get', ['queue_id' => $queueId]);
            if (\is_array($row) && $row !== []) {
                return $row;
            }
        }

        $row = w_query('queue', 'getByBizKey', [
            'biz_key' => $this->buildAiSiteQueueBizKey((int)$session->getId(), $operation),
        ]);

        return \is_array($row) && $row !== [] ? $row : null;
    }


    /**
     * 默认 HTML 区块轨：不创建虚拟主题，仅填充 scope.virtual_pages_by_type.blocks
     *
     * @return array<string, mixed>
     */
    private function runHtmlBlocksBuildOperation(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
        );
        $pageTypeLabels = Page::getPageTypes();
        $totalSteps = \count($pageTypes) + 2;
        $currentStep = 0;

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('正在准备网站资料'), $progressPercent);
        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];
        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);

        // 步骤 2: 生成 header/footer
        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('AI 正在生成站点页头与页脚'), $progressPercent);

        try {
            $sharedComponents = $pageComponentGenerationService->generateSharedComponents($scope['website_profile'], $scope);
            $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
            if (\is_array($sharedComponents['header'] ?? null)) {
                $scope['shared_components']['header'] = $sharedComponents['header'];
                $scope = $this->buildTaskService->markTaskDone($scope, 'shared:header', ['region' => 'header']);
                $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => 'shared:header',
                    'task_type' => 'shared_component',
                    'message' => (string)__('共享任务已完成：Header'),
                ]));
            }
            if (\is_array($sharedComponents['footer'] ?? null)) {
                $scope['shared_components']['footer'] = $sharedComponents['footer'];
                $scope = $this->buildTaskService->markTaskDone($scope, 'shared:footer', ['region' => 'footer']);
                $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => 'shared:footer',
                    'task_type' => 'shared_component',
                    'message' => (string)__('共享任务已完成：Footer'),
                ]));
            }
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'shared_layout_generated',
                (string)__('AI 已生成站点页头与页脚'),
                ['operation' => 'build']
            );
        } catch (\Throwable $e) {
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'shared_layout_error',
                (string)__('生成页头页脚失败：%{message}', ['message' => $e->getMessage()]),
                ['operation' => 'build', 'details' => ['exception' => $e->getMessage()]],
                AiSiteAgentSessionEvent::LEVEL_ERROR
            );
            // 继续生成，不因为 header/footer 生成失败而中断整个流程
            $sharedComponents = ['header' => null, 'footer' => null];
        }

        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $now = \date('Y-m-d H:i:s');
        $environmentReadyEmitted = false;

        foreach ($pageTypes as $pageType) {
            $this->assertActiveStreamLeaseAlive($session, $adminId);
            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);
            $pageLabel = (string)($pageTypeLabels[$pageType] ?? $pageType);
            $blueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $scope['website_profile']);
            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'build',
                __('正在生成 HTML 区块：%{page}', ['page' => $pageLabel]),
                $progressPercent,
                $pageType
            );
            $buildScope = $scope;
            $buildScope['_ai_generated_shared_components'] = $sharedComponents;
            $blocks = $this->htmlBlocksBuildService->buildPlaceholderBlocksForPageType($pageType, $scope['website_profile'], $buildScope);
            $row = $virtualPages[$pageType] ?? [];
            $row['blocks'] = $blocks;
            $row['last_generated_at'] = $now;
            $row['title'] = (string)($blueprint['page_title'] ?? ($row['title'] ?? ''));
            $row['ai_description'] = (string)($blueprint['ai_description'] ?? ($row['ai_description'] ?? ''));
            $row['meta_title'] = (string)($blueprint['meta_title'] ?? ($row['meta_title'] ?? ''));
            $row['meta_description'] = (string)($blueprint['meta_description'] ?? ($row['meta_description'] ?? ''));
            $row['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($row['meta_keywords'] ?? ''));
            $row['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
            $virtualPages[$pageType] = $row;
            foreach (\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [] as $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $sectionCode = \trim((string)($section['code'] ?? ''));
                if ($sectionCode === '') {
                    continue;
                }
                $scope = $this->buildTaskService->markTaskDone(
                    $scope,
                    'page:' . $pageType . ':' . $sectionCode,
                    ['page_type' => $pageType, 'section_code' => $sectionCode]
                );
            }
            $scope['virtual_pages_by_type'] = $virtualPages;
            $scope['virtual_theme_id'] = 0;
            $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? ''));
            $materialized = $this->materializeGeneratedPages(
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                (int)$draftWebsite['website_id'],
                $scope['website_profile'],
                \array_keys($virtualPages),
                [],
                $virtualPages
            );
            $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $pageId = (int)($scope['pagebuilder_pages_by_type'][$pageType]['page_id'] ?? 0);
            if (!$environmentReadyEmitted && $pageId > 0) {
                $environmentReadyEmitted = true;
                $this->emitBuildEnvironmentReady($sse, $session, $adminId, $pageType, $pageLabel, $pageId, 0);
            }
            $sse->sendEvent('page_generated', [
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'page_id' => $pageId,
                'virtual_theme_id' => 0,
                'progress_percent' => $progressPercent,
                'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
            ]);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('页面已生成：%{page}', ['page' => $pageLabel]),
                [
                    'operation' => 'build',
                    'page_type' => $pageType,
                    'details' => [
                        'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                    ],
                ]
            );
            $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                'task_key' => 'page:' . $pageType,
                'task_type' => 'page_group',
                'message' => (string)__('页面任务已完成：%{page}', ['page' => $pageLabel]),
            ]));
        }

        $now = $lastGeneratedAt;
        $scope['build_summary'] = ['page_count' => \count($virtualPages), 'last_generated_at' => $now, 'active_operation' => 'build', 'can_publish' => true, 'task_summary' => $this->buildTaskService->summarize($scope)];
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => $now, 'message' => (string)__('HTML 区块已生成')]);

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, 0);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);

        /*
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('HTML 区块已就绪，可预览或发布'), 100);

        return ['message' => (string)__('HTML 区块构建完成'), 'draft_website_id' => (int)$draftWebsite['website_id'], 'virtual_theme_id' => 0, 'page_types' => $pageTypes];
    }

    private function runHtmlBlocksBuildOperationV2(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
        );
        $pageTypeLabels = Page::getPageTypes();
        $dispatchWindow = 3;
        $initialPendingTasks = $this->buildTaskService->listPendingTasks($scope);
        $totalSteps = \max(1, \count($initialPendingTasks) + 1);
        $currentStep = 0;

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('正在准备网站资料'), $progressPercent);
        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $environmentReadyEmitted = false;
        $parallelPageModeLogged = false;

        while (true) {
            $taskBatch = $this->buildTaskService->pickConcurrentTasks($scope, $dispatchWindow);
            if ($taskBatch === []) {
                break;
            }

            foreach ($taskBatch as $task) {
            $this->assertActiveStreamLeaseAlive($session, $adminId);
            $taskKey = (string)($task['task_key'] ?? '');
            $taskType = (string)($task['task_type'] ?? '');
            if ($taskKey === '' || $taskType === '') {
                continue;
            }

            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);
            $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);

            if ($taskType === 'shared_component') {
                $region = (string)($task['region'] ?? '');
                $this->sendOperationProgress(
                    $sse,
                    $session,
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'build',
                    $region === 'header' ? __('正在生成共享 Header') : __('正在生成共享 Footer'),
                    $progressPercent
                );
                $component = $pageComponentGenerationService->generateSharedComponent($region, $scope['website_profile'], $scope);
                $sharedComponents[$region] = $component;
                $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
                $scope['shared_components'][$region] = $component;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['region' => $region]);
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                $message = $region === 'header'
                    ? (string)__('共享任务已完成：Header')
                    : (string)__('共享任务已完成：Footer');
                $this->appendWorkspaceEvent(
                    $session->getId(),
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'shared_component_generated',
                    $message,
                    ['operation' => 'build', 'details' => ['region' => $region]]
                );
                $freshShared = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $sharedState = $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($freshShared, $adminId, 80, true)
                );
                $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => $taskKey,
                    'task_type' => $taskType,
                    'message' => $message,
                    'state' => $sharedState,
                ]));
                continue;
            }

            if ($taskType !== 'page_section') {
                continue;
            }

            $pageType = (string)($task['page_type'] ?? '');
            $sectionCode = (string)($task['section_code'] ?? '');
            if ($pageType === '' || $sectionCode === '') {
                continue;
            }

            $pageLabel = (string)($pageTypeLabels[$pageType] ?? $pageType);
            $blueprintSpecs = $pageComponentGenerationService->buildPageSectionSpecs($pageType, $scope['website_profile'], $scope);
            $blueprint = \is_array($blueprintSpecs['blueprint'] ?? null)
                ? $blueprintSpecs['blueprint']
                : $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $scope['website_profile']);
            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'build',
                __('正在生成 HTML 区块：{page}', ['page' => $pageLabel]),
                $progressPercent,
                $pageType
            );

            $sectionComponent = $pageComponentGenerationService->generatePageSection($pageType, $sectionCode, $scope['website_profile'], $scope);
            $sectionBlock = $this->htmlBlocksBuildService->buildGeneratedSectionBlock($sectionComponent);
            $row = $virtualPages[$pageType] ?? [];
            $row['title'] = (string)($blueprint['page_title'] ?? ($row['title'] ?? ''));
            $row['ai_description'] = (string)($blueprint['ai_description'] ?? ($row['ai_description'] ?? ''));
            $row['meta_title'] = (string)($blueprint['meta_title'] ?? ($row['meta_title'] ?? ''));
            $row['meta_description'] = (string)($blueprint['meta_description'] ?? ($row['meta_description'] ?? ''));
            $row['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($row['meta_keywords'] ?? ''));
            $row['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
            $row['blocks'] = $this->composeHtmlBlocksForPage(
                $pageType,
                \is_array($row['blocks'] ?? null) ? $row['blocks'] : [],
                $sharedComponents,
                $sectionBlock,
                $scope['website_profile'],
                $scope
            );
            $row['last_generated_at'] = \date('Y-m-d H:i:s');
            $virtualPages[$pageType] = $row;
            $scope['virtual_pages_by_type'] = $virtualPages;
            $scope['virtual_theme_id'] = 0;
            $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? $pageType));
            $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);
            $materialized = $this->materializeGeneratedPages(
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                (int)$draftWebsite['website_id'],
                $scope['website_profile'],
                [$pageType],
                [],
                [$pageType => $row]
            );
            $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

            $pageId = (int)($scope['pagebuilder_pages_by_type'][$pageType]['page_id'] ?? 0);
            if (!$environmentReadyEmitted && $pageId > 0) {
                $environmentReadyEmitted = true;
                $this->emitBuildEnvironmentReady($sse, $session, $adminId, $pageType, $pageLabel, $pageId, 0);
            }

            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = $this->buildWorkspaceEventStatePayload(
                $this->buildWorkspaceState($fresh, $adminId, 80, true),
                [$pageType]
            );
            $pageCompleted = $this->buildTaskService->arePageTasksComplete($scope, $pageType);
            $pageGeneratedMessage = $pageCompleted
                ? 'Page generation complete: ' . $pageLabel
                : 'Page updated and ready to edit: ' . $pageLabel;
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('页面已生成：%{page}', ['page' => $pageLabel]),
                [
                    'operation' => 'build',
                    'page_type' => $pageType,
                    'details' => [
                        'section_code' => $sectionCode,
                        'page_completed' => $pageCompleted ? 1 : 0,
                    ],
                ]
            );
            $sse->sendEvent('page_generated', $this->enrichTaskEventPayload($scope, [
                'task_key' => $taskKey,
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'page_id' => $pageId,
                'virtual_theme_id' => 0,
                'progress_percent' => $progressPercent,
                'section_code' => $sectionCode,
                'page_completed' => $pageCompleted,
                'message' => $pageGeneratedMessage,
                'state' => $state,
            ]));
            $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                'task_key' => $taskKey,
                'task_type' => $taskType,
                'message' => (string)__('区块任务已完成：%{page}', ['page' => $pageLabel]),
                'state' => $state,
            ]));
            }
        }

        $now = \date('Y-m-d H:i:s');
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'last_generated_at' => $now,
            'active_operation' => 'build',
            'can_publish' => !$this->buildTaskService->hasPendingTasks($scope),
            'task_summary' => $this->buildTaskService->summarize($scope),
        ];
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            ['status' => 'done', 'updated_at' => $now, 'message' => (string)__('HTML blocks generated')]
        );
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, 0);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('HTML blocks ready for preview or publish'), 100);

        return ['message' => (string)__('HTML block build complete'), 'draft_website_id' => (int)$draftWebsite['website_id'], 'virtual_theme_id' => 0, 'page_types' => $pageTypes];
    }

    private function runHtmlBlocksBuildOperationV3(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
        );
        $queueForcedAiRebuild = (int)($scope['_queue_force_build']['active'] ?? 0) === 1;
        $pageTypeLabels = Page::getPageTypes();
        $dispatchWindow = 3;
        $initialPendingTasks = $this->buildTaskService->listPendingTasks($scope);
        $totalSteps = \max(1, \count($initialPendingTasks) + 1);
        $currentStep = 0;

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Preparing website workspace'), $progressPercent);
        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $environmentReadyEmitted = false;
        $parallelPageModeLogged = false;

        while (true) {
            $taskBatch = $this->buildTaskService->pickConcurrentTasks($scope, $dispatchWindow);
            if ($taskBatch === []) {
                break;
            }

            $sharedTasks = [];
            $pageTasks = [];
            foreach ($taskBatch as $task) {
                $taskType = (string)($task['task_type'] ?? '');
                if ($taskType === 'shared_component') {
                    $sharedTasks[] = $task;
                    continue;
                }
                if ($taskType === 'page_section') {
                    $pageTasks[] = $task;
                }
            }

            foreach ($sharedTasks as $task) {
                $this->assertActiveStreamLeaseAlive($session, $adminId);
                $taskKey = (string)($task['task_key'] ?? '');
                if ($taskKey === '') {
                    continue;
                }

                $currentStep++;
                $progressPercent = (int)(($currentStep / $totalSteps) * 100);
                $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);

                $region = (string)($task['region'] ?? '');
                $this->sendOperationProgress(
                    $sse,
                    $session,
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'build',
                    $region === 'header' ? __('Generating shared header') : __('Generating shared footer'),
                    $progressPercent
                );
                $component = $pageComponentGenerationService->generateSharedComponent(
                    $region,
                    $scope['website_profile'],
                    $scope,
                    '',
                    $queueForcedAiRebuild
                );
                $sharedComponents[$region] = $component;
                $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
                $scope['shared_components'][$region] = $component;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['region' => $region]);
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                $message = $region === 'header' ? 'Shared header generated' : 'Shared footer generated';
                $this->appendWorkspaceEvent(
                    $session->getId(),
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'shared_component_generated',
                    $message,
                    ['operation' => 'build', 'details' => ['region' => $region]]
                );
                $freshShared = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $sharedState = $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($freshShared, $adminId, 80, true)
                );
                $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => $taskKey,
                    'task_type' => 'shared_component',
                    'message' => $message,
                    'state' => $sharedState,
                ]));
            }

            if ($pageTasks === []) {
                continue;
            }

            if (!$parallelPageModeLogged) {
                $parallelPageModeLogged = true;
                $this->emitBuildInfoEvent($sse, 'Shared theme ready; remaining pages will be generated in concurrent batches.', [
                    'event_type' => 'build_parallel_mode_enabled',
                    'parallel' => true,
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                ]);
            }

            $batchSpecs = $this->buildConcurrentPageTaskBatchSpecs(
                $pageComponentGenerationService,
                $pageTasks,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                $scope,
                $pageTypeLabels
            );
            $componentSpecs = \is_array($batchSpecs['components'] ?? null) ? $batchSpecs['components'] : [];
            $taskMeta = \is_array($batchSpecs['meta'] ?? null) ? $batchSpecs['meta'] : [];
            if ($componentSpecs === []) {
                continue;
            }

            $runningTaskKeys = \array_values(\array_map('strval', \array_keys($componentSpecs)));
            foreach ($runningTaskKeys as $runningTaskKey) {
                $scope = $this->buildTaskService->markTaskRunning($scope, $runningTaskKey);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

            $batchPageTypes = $this->extractBuildBatchPageTypes($pageTasks);
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('Starting concurrent page batch for %{count} tasks.', ['count' => (string)\count($runningTaskKeys)]),
                [
                    'event_type' => 'build_parallel_batch',
                    'batch_state' => 'started',
                    'parallel' => true,
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                    'batch_size' => \count($runningTaskKeys),
                    'page_types' => $batchPageTypes,
                    'task_keys' => $runningTaskKeys,
                ]
            );

            $completedTaskKeys = [];
            foreach ($pageComponentGenerationService->generateComponentEventsConcurrently($componentSpecs) as $taskKey => $event) {
                $this->assertActiveStreamLeaseAlive($session, $adminId);
                $meta = \is_array($taskMeta[$taskKey] ?? null) ? $taskMeta[$taskKey] : [];
                $pageType = (string)($meta['page_type'] ?? '');
                $sectionCode = (string)($meta['section_code'] ?? '');
                $pageLabel = (string)($meta['page_label'] ?? $pageType);

                if (($event['status'] ?? '') !== 'fulfilled') {
                    $throwable = $event['error'] instanceof \Throwable
                        ? $event['error']
                        : new \RuntimeException((string)__('Concurrent build task failed'));
                    $scope = $this->rollbackConcurrentBuildBatch(
                        $scope,
                        $runningTaskKeys,
                        $completedTaskKeys,
                        (string)$taskKey,
                        $throwable->getMessage()
                    );
                    $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                    $this->emitBuildInfoEvent(
                        $sse,
                        (string)__('Concurrent page batch failed: %{page}', ['page' => $pageLabel !== '' ? $pageLabel : (string)$taskKey]),
                        [
                            'event_type' => 'build_parallel_batch',
                            'batch_state' => 'failed',
                            'parallel' => true,
                            'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                            'task_key' => (string)$taskKey,
                            'page_type' => $pageType,
                            'task_keys' => $runningTaskKeys,
                            'completed_task_keys' => $completedTaskKeys,
                            'error_message' => $throwable->getMessage(),
                        ]
                    );
                    throw $throwable;
                }

                $currentStep++;
                $progressPercent = (int)(($currentStep / $totalSteps) * 100);
                $this->sendOperationProgress(
                    $sse,
                    $session,
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'build',
                    __('HTML page batch synced: %{page}', ['page' => $pageLabel]),
                    $progressPercent,
                    $pageType
                );

                $blueprint = \is_array($meta['blueprint'] ?? null) ? $meta['blueprint'] : [];
                $sectionComponent = \is_array($event['result'] ?? null) ? $event['result'] : [];
                $sectionBlock = $this->htmlBlocksBuildService->buildGeneratedSectionBlock($sectionComponent);
                $row = $virtualPages[$pageType] ?? [];
                $row['title'] = (string)($blueprint['page_title'] ?? ($row['title'] ?? ''));
                $row['ai_description'] = (string)($blueprint['ai_description'] ?? ($row['ai_description'] ?? ''));
                $row['meta_title'] = (string)($blueprint['meta_title'] ?? ($row['meta_title'] ?? ''));
                $row['meta_description'] = (string)($blueprint['meta_description'] ?? ($row['meta_description'] ?? ''));
                $row['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($row['meta_keywords'] ?? ''));
                $row['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
                $row['blocks'] = $this->composeHtmlBlocksForPage(
                    $pageType,
                    \is_array($row['blocks'] ?? null) ? $row['blocks'] : [],
                    $sharedComponents,
                    $sectionBlock,
                    $scope['website_profile'],
                    $scope
                );
                $row['last_generated_at'] = \date('Y-m-d H:i:s');
                $virtualPages[$pageType] = $row;
                $scope['virtual_pages_by_type'] = $virtualPages;
                $scope['virtual_theme_id'] = 0;
                $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? $pageType));
                $scope = $this->buildTaskService->markTaskDone($scope, (string)$taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);
                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                    (int)$draftWebsite['website_id'],
                    $scope['website_profile'],
                    [$pageType],
                    [],
                    [$pageType => $row]
                );
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                $pageId = (int)($scope['pagebuilder_pages_by_type'][$pageType]['page_id'] ?? 0);
                if (!$environmentReadyEmitted && $pageId > 0) {
                    $environmentReadyEmitted = true;
                    $this->emitBuildEnvironmentReady($sse, $session, $adminId, $pageType, $pageLabel, $pageId, 0);
                }

                $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $state = $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true),
                    [$pageType]
                );
                $pageCompleted = $this->buildTaskService->arePageTasksComplete($scope, $pageType);
                $pageGeneratedMessage = $pageCompleted
                    ? 'Page generation complete: ' . $pageLabel
                    : 'Page updated and ready to edit: ' . $pageLabel;
                $this->appendWorkspaceEvent(
                    $session->getId(),
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'page_generated',
                    'Page generated: ' . $pageLabel,
                    [
                        'operation' => 'build',
                        'page_type' => $pageType,
                        'details' => [
                            'section_code' => $sectionCode,
                            'page_completed' => $pageCompleted ? 1 : 0,
                        ],
                    ]
                );
                $sse->sendEvent('page_generated', $this->enrichTaskEventPayload($scope, [
                    'task_key' => (string)$taskKey,
                    'page_type' => $pageType,
                    'page_label' => $pageLabel,
                    'page_id' => $pageId,
                    'virtual_theme_id' => 0,
                    'progress_percent' => $progressPercent,
                    'section_code' => $sectionCode,
                    'page_completed' => $pageCompleted,
                    'message' => $pageGeneratedMessage,
                    'state' => $state,
                ]));
                $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => (string)$taskKey,
                    'task_type' => 'page_section',
                    'message' => 'HTML section task complete: ' . $pageLabel,
                    'state' => $state,
                ]));
                $completedTaskKeys[] = (string)$taskKey;
            }

            $this->emitBuildInfoEvent(
                $sse,
                (string)__('Concurrent page batch completed: %{count} tasks.', ['count' => (string)\count($completedTaskKeys)]),
                [
                    'event_type' => 'build_parallel_batch',
                    'batch_state' => 'completed',
                    'parallel' => true,
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                    'batch_size' => \count($runningTaskKeys),
                    'page_types' => $batchPageTypes,
                    'task_keys' => $runningTaskKeys,
                    'completed_task_keys' => $completedTaskKeys,
                ]
            );
        }

        $now = \date('Y-m-d H:i:s');
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'last_generated_at' => $now,
            'active_operation' => 'build',
            'can_publish' => !$this->buildTaskService->hasPendingTasks($scope),
            'task_summary' => $this->buildTaskService->summarize($scope),
        ];
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            ['status' => 'done', 'updated_at' => $now, 'message' => (string)__('HTML blocks generated')]
        );
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, 0);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('HTML blocks ready for preview or publish'), 100);

        return [
            'message' => (string)__('HTML block build complete'),
            'draft_website_id' => (int)$draftWebsite['website_id'],
            'virtual_theme_id' => 0,
            'page_types' => $pageTypes,
        ];
    }

    /**
     * @param list<array<string, mixed>> $existingBlocks
     * @param array<string, array<string, mixed>> $sharedComponents
     * @param array<string, mixed> $sectionBlock
     * @param array<string, mixed> $websiteProfile
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function composeHtmlBlocksForPage(
        string $pageType,
        array $existingBlocks,
        array $sharedComponents,
        array $sectionBlock,
        array $websiteProfile,
        array $scope
    ): array {
        $blocks = [];

        $existingHeaderBlock = $this->findExistingHtmlSharedBlock($existingBlocks, 'header');
        if (\is_array($sharedComponents['header'] ?? null)) {
            $headerBlock = $this->htmlBlocksBuildService->buildGeneratedSharedBlock('header', $pageType, $sharedComponents['header']);
            if (\is_array($existingHeaderBlock)) {
                $headerBlock = $this->htmlBlocksBuildService->mergeUserCustomizedSharedBlockConfig($headerBlock, $existingHeaderBlock);
            }
            $blocks[] = $headerBlock;
        } else {
            $blocks[] = $this->htmlBlocksBuildService->buildSharedHeaderBlock($pageType, $websiteProfile, $scope);
        }

        $sectionBlockId = \trim((string)($sectionBlock['block_id'] ?? ''));
        $sectionInserted = false;
        foreach ($existingBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            if ($this->isHtmlSharedBlock($blockId, $block)) {
                continue;
            }
            if ($sectionBlockId !== '' && $blockId === $sectionBlockId) {
                $blocks[] = $sectionBlock;
                $sectionInserted = true;
                continue;
            }
            $blocks[] = $block;
        }
        if (!$sectionInserted && $sectionBlockId !== '') {
            $blocks[] = $sectionBlock;
        }

        $existingFooterBlock = $this->findExistingHtmlSharedBlock($existingBlocks, 'footer');
        if (\is_array($sharedComponents['footer'] ?? null)) {
            $footerBlock = $this->htmlBlocksBuildService->buildGeneratedSharedBlock('footer', $pageType, $sharedComponents['footer']);
            if (\is_array($existingFooterBlock)) {
                $footerBlock = $this->htmlBlocksBuildService->mergeUserCustomizedSharedBlockConfig($footerBlock, $existingFooterBlock);
            }
            $blocks[] = $footerBlock;
        } else {
            $blocks[] = $this->htmlBlocksBuildService->buildSharedFooterBlock($pageType, $websiteProfile, $scope);
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function isHtmlSharedBlock(string $blockId, array $block): bool
    {
        if (\str_contains($blockId, 'site-header') || \str_contains($blockId, 'site-footer')) {
            return true;
        }

        $type = \trim((string)($block['type'] ?? ''));
        return \str_starts_with($type, 'ai_generated_shared_');
    }

    /**
     * @param list<array<string, mixed>> $existingBlocks
     * @return array<string, mixed>|null
     */
    private function findExistingHtmlSharedBlock(array $existingBlocks, string $region): ?array
    {
        foreach ($existingBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ($this->htmlBlocksBuildService->resolveSharedBlockRegion($block) !== $region) {
                continue;
            }

            return $block;
        }

        return null;
    }

    private function persistVirtualThemeBuildScope(
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        int $websiteId,
        array $pageTypes,
        array $pageTypeLayouts,
        array $virtualPages
    ): array {
        $scope['page_type_layouts'] = $pageTypeLayouts;
        $scope['virtual_pages_by_type'] = $virtualPages;
        $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? ''));
        $materialized = $this->materializeGeneratedPages(
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            $websiteId,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            $pageTypes,
            $pageTypeLayouts,
            $virtualPages
        );
        $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, $websiteId);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)($scope['virtual_theme_id'] ?? 0));

        return $scope;
    }

    /**
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     * @param array<string, mixed> $component
     * @return array<string, array<string, mixed>>
     */
    private function applySharedComponentToPageLayouts(array $pageTypes, array $pageTypeLayouts, array $component): array
    {
        $region = \trim((string)($component['region'] ?? ''));
        if (!\in_array($region, ['header', 'footer'], true)) {
            return $pageTypeLayouts;
        }

        $componentCode = \trim((string)($component['code'] ?? ''));
        if ($componentCode === '') {
            return $pageTypeLayouts;
        }

        $componentConfig = \is_array($component['default_config'] ?? null) ? $component['default_config'] : [];
        foreach ($pageTypes as $pageType) {
            $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($pageTypeLayouts[$pageType] ?? [], $pageType);
            $layout[$region] = [
                'component' => $componentCode,
                'config' => $componentConfig,
            ];
            $pageTypeLayouts[$pageType] = $layout;
        }

        return $pageTypeLayouts;
    }

    private function emitBuildInfoEvent(SseWriter $sse, string $message, array $payload = []): void
    {
        if (!$sse->isAlive()) {
            return;
        }

        try {
            $sse->sendEvent('info', \array_replace([
                'message' => $message,
                'operation' => 'build',
            ], $payload));
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{
     *   components:array<string, array{
     *     componentCode:string,
     *     name:string,
     *     region:string,
     *     prompt:string,
     *     defaultConfig:array<string,mixed>,
     *     renderContext:array<string,mixed>
     *   }>,
     *   meta:array<string, array{
     *     task_key:string,
     *     page_type:string,
     *     page_label:string,
     *     section_code:string,
     *     blueprint:array<string,mixed>
     *   }>
     * }
     */
    private function buildConcurrentPageTaskBatchSpecs(
        AiSitePageComponentGenerationService $pageComponentGenerationService,
        array $tasks,
        array $websiteProfile,
        array $scope,
        array $pageTypeLabels
    ): array {
        $pageSpecCache = [];
        $components = [];
        $meta = [];

        foreach ($tasks as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            $pageType = \trim((string)($task['page_type'] ?? ''));
            $sectionCode = \trim((string)($task['section_code'] ?? ''));
            if ($taskKey === '' || $pageType === '' || $sectionCode === '') {
                continue;
            }

            if (!isset($pageSpecCache[$pageType])) {
                $pageSpecs = $pageComponentGenerationService->buildPageSectionSpecs($pageType, $websiteProfile, $scope);
                $sectionMap = [];
                foreach (($pageSpecs['sections'] ?? []) as $sectionSpec) {
                    if (!\is_array($sectionSpec)) {
                        continue;
                    }

                    $code = \trim((string)($sectionSpec['code'] ?? ''));
                    if ($code === '') {
                        continue;
                    }

                    $sectionMap[$code] = $sectionSpec;
                }

                $pageSpecCache[$pageType] = [
                    'blueprint' => \is_array($pageSpecs['blueprint'] ?? null)
                        ? $pageSpecs['blueprint']
                        : $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile),
                    'sections' => $sectionMap,
                ];
            }

            $pageSpecs = \is_array($pageSpecCache[$pageType] ?? null) ? $pageSpecCache[$pageType] : [];
            $sectionSpec = \is_array($pageSpecs['sections'][$sectionCode] ?? null) ? $pageSpecs['sections'][$sectionCode] : null;
            if (!\is_array($sectionSpec)) {
                throw new \RuntimeException((string)__('Unknown section task in concurrent batch: %{page} / %{section}', [
                    'page' => $pageType,
                    'section' => $sectionCode,
                ]));
            }

            $components[$taskKey] = [
                'componentCode' => (string)($sectionSpec['code'] ?? $sectionCode),
                'name' => (string)($sectionSpec['name'] ?? $sectionCode),
                'region' => (string)($sectionSpec['region'] ?? 'content'),
                'prompt' => (string)($sectionSpec['prompt'] ?? ''),
                'defaultConfig' => \is_array($sectionSpec['default_config'] ?? null) ? $sectionSpec['default_config'] : [],
                'renderContext' => \is_array($sectionSpec['render_context'] ?? null) ? $sectionSpec['render_context'] : [],
            ];
            $meta[$taskKey] = [
                'task_key' => $taskKey,
                'page_type' => $pageType,
                'page_label' => (string)($pageTypeLabels[$pageType] ?? $pageType),
                'section_code' => $sectionCode,
                'blueprint' => \is_array($pageSpecs['blueprint'] ?? null) ? $pageSpecs['blueprint'] : [],
            ];
        }

        return [
            'components' => $components,
            'meta' => $meta,
        ];
    }

    /**
     * @return list<string>
     */
    private function extractBuildBatchPageTypes(array $tasks): array
    {
        $pageTypes = [];
        foreach ($tasks as $task) {
            $pageType = \trim((string)($task['page_type'] ?? ''));
            if ($pageType === '' || isset($pageTypes[$pageType])) {
                continue;
            }

            $pageTypes[$pageType] = $pageType;
        }

        return \array_values($pageTypes);
    }

    /**
     * @param list<string> $runningTaskKeys
     * @param list<string> $completedTaskKeys
     */
    private function rollbackConcurrentBuildBatch(
        array $scope,
        array $runningTaskKeys,
        array $completedTaskKeys,
        string $failedTaskKey,
        string $errorMessage
    ): array {
        if ($failedTaskKey !== '') {
            $scope = $this->buildTaskService->markTaskFailed($scope, $failedTaskKey, $errorMessage);
        }

        foreach ($runningTaskKeys as $taskKey) {
            if ($taskKey === $failedTaskKey || \in_array($taskKey, $completedTaskKeys, true)) {
                continue;
            }

            $scope = $this->buildTaskService->resetTaskForRetry($scope, $taskKey);
        }

        return $scope;
    }

    /**
     * @return array<string, mixed>
     */
    private function runBuildOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): array
    {
        $this->assertActiveStreamLeaseAlive($session, $adminId);
        // 队列/CLI 下首条可见进度：后续虚拟主题路径会先跑 profile 生成，可能耗时较长，避免认领后长时间无 SSE。
        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'build',
            (string)__('正在执行，请稍候...'),
            1
        );
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            return $this->runHtmlBlocksBuildOperationV3($sse, $session, $adminId, $scope);
        }
        return $this->runVirtualThemeBuildOperationV3($sse, $session, $adminId, $scope);
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $pageTypeLabels = Page::getPageTypes();
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $themeShell = $this->virtualThemeService->ensureThemeShell($scope, $scope['website_profile'], $session->getId());
        $scope['virtual_theme_id'] = (int)$themeShell['virtual_theme_id'];
        $sharedComponents = $this->virtualThemeService->loadSharedComponents((int)$scope['virtual_theme_id']);
        foreach (['header', 'footer'] as $region) {
            if (\is_array($sharedComponents[$region] ?? null)) {
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $sharedComponents[$region]);
            }
        }
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $existingPagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType($scope['pagebuilder_pages_by_type'] ?? []);
        $missingSharedRegions = [];
        foreach (['header', 'footer'] as $region) {
            if (!\is_array($sharedComponents[$region] ?? null)) {
                $missingSharedRegions[] = $region;
            }
        }
        $pendingPageTypes = $this->resolvePendingGenerationPageTypes(
            $pageTypes,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            $existingPagesByType,
            $pageTypeLayouts,
            $virtualPages
        );
        $totalSteps = \max(1, 1 + \count($missingSharedRegions) + \count($pendingPageTypes));
        $currentStep = 0;
        $environmentReadyEmitted = false;
        $lastGeneratedAt = \date('Y-m-d H:i:s');
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $themeShell = $this->virtualThemeService->ensureThemeShell($scope, $scope['website_profile'], $session->getId());
        $scope['virtual_theme_id'] = (int)$themeShell['virtual_theme_id'];
        $sharedComponents = $this->virtualThemeService->loadSharedComponents((int)$scope['virtual_theme_id']);
        foreach (['header', 'footer'] as $region) {
            if (\is_array($sharedComponents[$region] ?? null)) {
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $sharedComponents[$region]);
            }
        }
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $existingPagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType($scope['pagebuilder_pages_by_type'] ?? []);
        $missingSharedRegions = [];
        foreach (['header', 'footer'] as $region) {
            if (!\is_array($sharedComponents[$region] ?? null)) {
                $missingSharedRegions[] = $region;
            }
        }
        $pendingPageTypes = $this->resolvePendingGenerationPageTypes(
            $pageTypes,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            $existingPagesByType,
            $pageTypeLayouts,
            $virtualPages
        );
        $totalSteps = \max(1, 1 + \count($missingSharedRegions) + \count($pendingPageTypes));
        $currentStep = 0;
        $environmentReadyEmitted = false;
        $lastGeneratedAt = \date('Y-m-d H:i:s');
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
        $scope = $this->persistVirtualThemeBuildScope(
            $session,
            $adminId,
            $scope,
            (int)$draftWebsite['website_id'],
            $pageTypes,
            $pageTypeLayouts,
            $virtualPages
        );
        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $totalSteps = \max(1, 1 + \count($missingSharedRegions) + \count($pendingPageTypes));
        $currentStep = 1;
        $environmentReadyEmitted = false;
        $lastGeneratedAt = \date('Y-m-d H:i:s');

        // 步骤 2: 生成虚拟主题骨架
        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('正在生成虚拟主题骨架'), $progressPercent);

        // 初始化空的 page_type_layouts，稍后逐个生成
        foreach ($missingSharedRegions as $region) {
            $this->assertActiveStreamLeaseAlive($session, $adminId);
            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);

            // 如果该组件已存在（从 loadSharedComponents 加载），直接跳过并发送完成事件
            if (isset($sharedComponents[$region]) && \is_array($sharedComponents[$region])) {
                $component = $sharedComponents[$region];
                $this->sendOperationProgress(
                    $sse,
                    $session,
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'build',
                    $region === 'header'
                        ? __('Header 组件已存在，跳过生成')
                        : __('Footer 组件已存在，跳过生成'),
                    $progressPercent
                );
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
                $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $state = $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true)
                );
                $readyPageType = (string)($state['preview_page_type'] ?? Page::TYPE_HOME);
                $readyPageLabel = (string)($pageTypeLabels[$readyPageType] ?? $readyPageType);
                $readyPageId = (int)($state['pagebuilder_pages_by_type'][$readyPageType]['page_id'] ?? 0);
                if (!$environmentReadyEmitted && $readyPageId > 0) {
                    $environmentReadyEmitted = true;
                    $this->emitBuildEnvironmentReady(
                        $sse,
                        $session,
                        $adminId,
                        $readyPageType,
                        $readyPageLabel,
                        $readyPageId,
                        (int)$scope['virtual_theme_id']
                    );
                }
                $message = $region === 'header'
                    ? (string)__('Header 组件已生成并同步到可视化主题')
                    : (string)__('Footer 组件已生成并同步到可视化主题');
                $sse->sendEvent('shared_component_generated', [
                    'region' => $region,
                    'component_code' => (string)($component['code'] ?? ''),
                    'component_name' => (string)($component['name'] ?? ''),
                    'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                    'progress_percent' => $progressPercent,
                    'message' => $message,
                    'state' => $state,
                    'skipped' => true,
                ]);
                $this->appendWorkspaceEvent(
                    $session->getId(),
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'shared_component_generated',
                    $message,
                    [
                        'operation' => 'skip',
                        'details' => [
                            'region' => $region,
                            'component_code' => (string)($component['code'] ?? ''),
                            'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                        ],
                    ]
                );
                continue;
            }

            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'build',
                $region === 'header'
                    ? __('AI 正在生成站点 Header')
                    : __('AI 正在生成站点 Footer'),
                $progressPercent
            );

            $component = $pageComponentGenerationService->generateSharedComponent($region, $scope['website_profile'], $scope);
            $sharedComponents[$region] = $component;
            $this->virtualThemeService->saveGeneratedSharedComponent((int)$scope['virtual_theme_id'], $component);
            $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
            $scope = $this->persistVirtualThemeBuildScope(
                $session,
                $adminId,
                $scope,
                (int)$draftWebsite['website_id'],
                $pageTypes,
                $pageTypeLayouts,
                $virtualPages
            );

            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = $this->buildWorkspaceEventStatePayload(
                $this->buildWorkspaceState($fresh, $adminId, 80, true),
                [$pageType]
            );
            $readyPageType = (string)($state['preview_page_type'] ?? Page::TYPE_HOME);
            $readyPageLabel = (string)($pageTypeLabels[$readyPageType] ?? $readyPageType);
            $readyPageId = (int)($state['pagebuilder_pages_by_type'][$readyPageType]['page_id'] ?? 0);
            if (!$environmentReadyEmitted && $readyPageId > 0) {
                $environmentReadyEmitted = true;
                $this->emitBuildEnvironmentReady(
                    $sse,
                    $session,
                    $adminId,
                    $readyPageType,
                    $readyPageLabel,
                    $readyPageId,
                    (int)$scope['virtual_theme_id']
                );
            }

            $message = $region === 'header'
                ? (string)__('Header 已生成并同步到可视化主题')
                : (string)__('Footer 已生成并同步到可视化主题');
            $sse->sendEvent('shared_component_generated', [
                'region' => '',
                'shared_region' => $region,
                'component_code' => (string)($component['code'] ?? ''),
                'component_name' => (string)($component['name'] ?? ''),
                'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                'progress_percent' => $progressPercent,
                'message' => $message,
                'state' => $state,
            ]);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'shared_component_generated',
                $message,
                [
                    'operation' => 'build',
                    'details' => [
                        'region' => $region,
                        'component_code' => (string)($component['code'] ?? ''),
                        'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                    ],
                ]
            );
            $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                'task_key' => 'shared:' . $region,
                'task_type' => 'shared_component',
                'message' => $message,
                'state' => $state,
            ]));
        }

        // 步骤 3-N: 逐个生成每个页面类型
        foreach ($pageTypes as $pageType) {
            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);
            $pageLabel = (string)($pageTypeLabels[$pageType] ?? $pageType);
            $blueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $scope['website_profile']);

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
            $pageTypeLayouts = $this->applySharedComponentToPageLayouts([$pageType], $pageTypeLayouts, $sharedComponents['header'] ?? []);
            $pageTypeLayouts = $this->applySharedComponentToPageLayouts([$pageType], $pageTypeLayouts, $sharedComponents['footer'] ?? []);
            $currentPagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType($scope['pagebuilder_pages_by_type'] ?? []);
            if ($this->isPageTypeGenerationComplete(
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                $pageType,
                $currentPagesByType,
                $pageTypeLayouts,
                $virtualPages
            )) {
                continue;
            }

            // 更新虚拟主题（包含当前页面类型）
            $theme = $this->virtualThemeService->ensureAiGeneratedVirtualTheme(
                $scope,
                $scope['website_profile'],
                [$pageType], // 只传入当前页面类型
                [$pageType => $pageTypeLayouts[$pageType]],
                $session->getId(),
                false,
                $sharedComponents
            );
            $scope['virtual_theme_id'] = (int)$theme['virtual_theme_id'];
            $pageTypeLayouts = \array_replace($pageTypeLayouts, $theme['page_type_layouts']);

            // 构建当前页面的虚拟页面数据
            $currentVirtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType([$pageType], \array_replace($scope, [
                'page_type_layouts' => $pageTypeLayouts,
                'virtual_pages_by_type' => $virtualPages,
            ]));
            if (isset($currentVirtualPages[$pageType])) {
                $lastGeneratedAt = \date('Y-m-d H:i:s');
                $currentVirtualPages[$pageType]['last_generated_at'] = $lastGeneratedAt;
                $currentVirtualPages[$pageType]['title'] = (string)($blueprint['page_title'] ?? ($currentVirtualPages[$pageType]['title'] ?? ''));
                $currentVirtualPages[$pageType]['ai_description'] = (string)($blueprint['ai_description'] ?? ($currentVirtualPages[$pageType]['ai_description'] ?? ''));
                $currentVirtualPages[$pageType]['meta_title'] = (string)($blueprint['meta_title'] ?? ($currentVirtualPages[$pageType]['meta_title'] ?? ''));
                $currentVirtualPages[$pageType]['meta_description'] = (string)($blueprint['meta_description'] ?? ($currentVirtualPages[$pageType]['meta_description'] ?? ''));
                $currentVirtualPages[$pageType]['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($currentVirtualPages[$pageType]['meta_keywords'] ?? ''));
                $currentVirtualPages[$pageType]['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
                $virtualPages[$pageType] = $currentVirtualPages[$pageType];
            }

            // 实时更新 scope，让前端可以立即看到新生成的页面
            $scope = $this->persistVirtualThemeBuildScope(
                $session,
                $adminId,
                $scope,
                (int)$draftWebsite['website_id'],
                $pageTypes,
                $pageTypeLayouts,
                $virtualPages
            );
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = $this->buildWorkspaceEventStatePayload(
                $this->buildWorkspaceState($fresh, $adminId, 80, true)
            );
            $pageId = (int)($scope['pagebuilder_pages_by_type'][$pageType]['page_id'] ?? 0);
            if (!$environmentReadyEmitted && $pageId > 0) {
                $environmentReadyEmitted = true;
                $this->emitBuildEnvironmentReady(
                    $sse,
                    $session,
                    $adminId,
                    $pageType,
                    $pageLabel,
                    $pageId,
                    (int)$scope['virtual_theme_id']
                );
            }

            // 发送页面生成完成事件
            $sse->sendEvent('page_generated', $this->enrichTaskEventPayload($scope, [
                'task_key' => 'page:' . $pageType,
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'page_id' => $pageId,
                'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                'progress_percent' => $progressPercent,
                'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                'state' => $state,
            ]));
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('页面已生成：%{page}', ['page' => $pageLabel]),
                [
                    'operation' => 'build',
                    'page_type' => $pageType,
                    'details' => [
                        'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                        'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                    ],
                ]
            );
            $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                'task_key' => 'page:' . $pageType,
                'task_type' => 'page_group',
                'message' => (string)__('页面任务已完成：%{page}', ['page' => $pageLabel]),
                'state' => $state,
            ]));
        }

        // 最终步骤：完成构建
        $now = \date('Y-m-d H:i:s');
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
    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function runVirtualThemeBuildOperation(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        $pageTypeLabels = Page::getPageTypes();
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);

        $themeShell = $this->virtualThemeService->ensureThemeShell($scope, $scope['website_profile'], $session->getId());
        $scope['virtual_theme_id'] = (int)$themeShell['virtual_theme_id'];
        $sharedComponents = $this->virtualThemeService->loadSharedComponents((int)$scope['virtual_theme_id']);

        foreach (['header', 'footer'] as $region) {
            if (\is_array($sharedComponents[$region] ?? null)) {
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $sharedComponents[$region]);
            }
        }

        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $pendingPageTypes = $this->resolvePendingGenerationPageTypes(
            $pageTypes,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            $this->scopeCompatibilityService->normalizePagebuilderPagesByType($scope['pagebuilder_pages_by_type'] ?? []),
            $pageTypeLayouts,
            $virtualPages
        );
        $missingSharedRegions = [];
        foreach (['header', 'footer'] as $region) {
            if (!\is_array($sharedComponents[$region] ?? null)) {
                $missingSharedRegions[] = $region;
            }
        }

        $totalSteps = \max(1, 1 + \count($missingSharedRegions) + \count($pendingPageTypes));
        $currentStep = 0;
        $environmentReadyEmitted = false;
        $lastGeneratedAt = \date('Y-m-d H:i:s');

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('正在准备网站资料'), $progressPercent);

        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];
        $scope = $this->persistVirtualThemeBuildScope(
            $session,
            $adminId,
            $scope,
            (int)$draftWebsite['website_id'],
            $pageTypes,
            $pageTypeLayouts,
            $virtualPages
        );

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);

        foreach ($missingSharedRegions as $region) {
            $this->assertActiveStreamLeaseAlive($session, $adminId);
            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);
            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'build',
                $region === 'header' ? 'Generating shared header' : 'Generating shared footer',
                $progressPercent
            );

            $component = $pageComponentGenerationService->generateSharedComponent($region, $scope['website_profile'], $scope);
            $sharedComponents[$region] = $component;
            $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
            $scope['shared_components'][$region] = $component;
            $scope = $this->buildTaskService->markTaskDone($scope, 'shared:' . $region, ['region' => $region]);
            $this->virtualThemeService->saveGeneratedSharedComponent((int)$scope['virtual_theme_id'], $component);
            $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
            $scope = $this->persistVirtualThemeBuildScope(
                $session,
                $adminId,
                $scope,
                (int)$draftWebsite['website_id'],
                $pageTypes,
                $pageTypeLayouts,
                $virtualPages
            );

            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = $this->buildWorkspaceEventStatePayload(
                $this->buildWorkspaceState($fresh, $adminId, 80, true)
            );
            $readyPageType = (string)($state['preview_page_type'] ?? Page::TYPE_HOME);
            $readyPageLabel = (string)($pageTypeLabels[$readyPageType] ?? $readyPageType);
            $readyPageId = (int)($state['pagebuilder_pages_by_type'][$readyPageType]['page_id'] ?? 0);
            if (!$environmentReadyEmitted && $readyPageId > 0) {
                $environmentReadyEmitted = true;
                $this->emitBuildEnvironmentReady(
                    $sse,
                    $session,
                    $adminId,
                    $readyPageType,
                    $readyPageLabel,
                    $readyPageId,
                    (int)$scope['virtual_theme_id']
                );
            }

            $message = $region === 'header'
                ? 'Header generated and synced to visual theme'
                : 'Footer generated and synced to visual theme';
            $sse->sendEvent('shared_component_generated', [
                'region' => $region,
                'component_code' => (string)($component['code'] ?? ''),
                'component_name' => (string)($component['name'] ?? ''),
                'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                'progress_percent' => $progressPercent,
                'message' => $message,
                'state' => $state,
            ]);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'shared_component_generated',
                $message,
                [
                    'operation' => 'build',
                    'details' => [
                        'region' => $region,
                        'component_code' => (string)($component['code'] ?? ''),
                        'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                    ],
                ]
            );
        }

        foreach ($pageTypes as $pageType) {
            if ($this->isPageTypeGenerationComplete(
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                $pageType,
                $this->scopeCompatibilityService->normalizePagebuilderPagesByType($scope['pagebuilder_pages_by_type'] ?? []),
                $pageTypeLayouts,
                $virtualPages
            )) {
                continue;
            }

            $this->assertActiveStreamLeaseAlive($session, $adminId);
            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);
            $pageLabel = (string)($pageTypeLabels[$pageType] ?? $pageType);
            $blueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $scope['website_profile']);

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

            $baseLayout = $this->scopeCompatibilityService->normalizeLayoutConfig($pageTypeLayouts[$pageType] ?? [], $pageType);
            if (\trim((string)($baseLayout['header']['component'] ?? '')) === '' && \is_array($sharedComponents['header'] ?? null)) {
                $baseLayout['header'] = [
                    'component' => (string)($sharedComponents['header']['code'] ?? ''),
                    'config' => \is_array($sharedComponents['header']['default_config'] ?? null) ? $sharedComponents['header']['default_config'] : [],
                ];
            }
            if (\trim((string)($baseLayout['footer']['component'] ?? '')) === '' && \is_array($sharedComponents['footer'] ?? null)) {
                $baseLayout['footer'] = [
                    'component' => (string)($sharedComponents['footer']['code'] ?? ''),
                    'config' => \is_array($sharedComponents['footer']['default_config'] ?? null) ? $sharedComponents['footer']['default_config'] : [],
                ];
            }

            $theme = $this->virtualThemeService->ensureAiGeneratedVirtualTheme(
                $scope,
                $scope['website_profile'],
                [$pageType],
                [$pageType => $baseLayout],
                $session->getId(),
                false,
                $sharedComponents
            );
            $scope['virtual_theme_id'] = (int)$theme['virtual_theme_id'];
            $pageTypeLayouts = \array_replace($pageTypeLayouts, $theme['page_type_layouts']);

            $currentVirtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType([$pageType], \array_replace($scope, [
                'page_type_layouts' => $pageTypeLayouts,
                'virtual_pages_by_type' => $virtualPages,
            ]));
            if (isset($currentVirtualPages[$pageType])) {
                $lastGeneratedAt = \date('Y-m-d H:i:s');
                $currentVirtualPages[$pageType]['last_generated_at'] = $lastGeneratedAt;
                $currentVirtualPages[$pageType]['title'] = (string)($blueprint['page_title'] ?? ($currentVirtualPages[$pageType]['title'] ?? ''));
                $currentVirtualPages[$pageType]['ai_description'] = (string)($blueprint['ai_description'] ?? ($currentVirtualPages[$pageType]['ai_description'] ?? ''));
                $currentVirtualPages[$pageType]['meta_title'] = (string)($blueprint['meta_title'] ?? ($currentVirtualPages[$pageType]['meta_title'] ?? ''));
                $currentVirtualPages[$pageType]['meta_description'] = (string)($blueprint['meta_description'] ?? ($currentVirtualPages[$pageType]['meta_description'] ?? ''));
                $currentVirtualPages[$pageType]['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($currentVirtualPages[$pageType]['meta_keywords'] ?? ''));
                $currentVirtualPages[$pageType]['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
                $virtualPages[$pageType] = $currentVirtualPages[$pageType];
            }
            foreach (\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [] as $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $sectionCode = \trim((string)($section['code'] ?? ''));
                if ($sectionCode === '') {
                    continue;
                }
                $scope = $this->buildTaskService->markTaskDone(
                    $scope,
                    'page:' . $pageType . ':' . $sectionCode,
                    ['page_type' => $pageType, 'section_code' => $sectionCode]
                );
            }

            $scope = $this->persistVirtualThemeBuildScope(
                $session,
                $adminId,
                $scope,
                (int)$draftWebsite['website_id'],
                $pageTypes,
                $pageTypeLayouts,
                $virtualPages
            );
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = $this->buildWorkspaceEventStatePayload(
                $this->buildWorkspaceState($fresh, $adminId, 80, true),
                [$pageType]
            );
            $pageId = (int)($scope['pagebuilder_pages_by_type'][$pageType]['page_id'] ?? 0);
            if (!$environmentReadyEmitted && $pageId > 0) {
                $environmentReadyEmitted = true;
                $this->emitBuildEnvironmentReady(
                    $sse,
                    $session,
                    $adminId,
                    $pageType,
                    $pageLabel,
                    $pageId,
                    (int)$scope['virtual_theme_id']
                );
            }

            $sse->sendEvent('page_generated', [
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'page_id' => $pageId,
                'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                'progress_percent' => $progressPercent,
                'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                'state' => $state,
            ]);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('页面已生成：%{page}', ['page' => $pageLabel]),
                [
                    'operation' => 'build',
                    'page_type' => $pageType,
                    'details' => [
                        'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                        'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                    ],
                ]
            );
        }

        $pendingPageTypes = $this->resolvePendingGenerationPageTypes(
            $pageTypes,
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
            $this->scopeCompatibilityService->normalizePagebuilderPagesByType($scope['pagebuilder_pages_by_type'] ?? []),
            $pageTypeLayouts,
            $virtualPages
        );
        $canPublish = $pendingPageTypes === [];
        $now = \date('Y-m-d H:i:s');
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'pending_page_count' => \count($pendingPageTypes),
            'pending_page_types' => $pendingPageTypes,
            'last_generated_at' => $lastGeneratedAt,
            'active_operation' => 'build',
            'can_publish' => $canPublish,
            'task_summary' => $this->buildTaskService->summarize($scope),
        ];
        $scope['workspace_status'] = $canPublish
            ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], [
            'status' => 'done',
            'updated_at' => $now,
            'message' => (string)__('主题构建完成'),
        ]);

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)($scope['virtual_theme_id'] ?? 0));
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);

        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'build',
            $canPublish
                ? (string)__('虚拟主题已生成，可继续进入页面编辑')
                : (string)__('仍有页面待生成，构建进度已记录'),
            100
        );

        return [
            'message' => (string)__('主题构建完成'),
            'draft_website_id' => (int)$draftWebsite['website_id'],
            'virtual_theme_id' => (int)($scope['virtual_theme_id'] ?? 0),
            'page_types' => $pageTypes,
        ];
    }

    private function runVirtualThemeBuildOperationV2(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        $pageTypeLabels = Page::getPageTypes();
        $dispatchWindow = 3;
        $initialPendingTasks = $this->buildTaskService->listPendingTasks($scope);
        $totalSteps = \max(1, \count($initialPendingTasks) + 1);
        $currentStep = 0;

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Preparing virtual theme workspace'), $progressPercent);

        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];
        $themeShell = $this->virtualThemeService->ensureThemeShell($scope, $scope['website_profile'], $session->getId());
        $scope['virtual_theme_id'] = (int)$themeShell['virtual_theme_id'];

        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $environmentReadyEmitted = false;

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);

        while (true) {
            $taskBatch = $this->buildTaskService->pickConcurrentTasks($scope, $dispatchWindow);
            if ($taskBatch === []) {
                break;
            }

            foreach ($taskBatch as $task) {
            $this->assertActiveStreamLeaseAlive($session, $adminId);
            $taskKey = (string)($task['task_key'] ?? '');
            $taskType = (string)($task['task_type'] ?? '');
            if ($taskKey === '' || $taskType === '') {
                continue;
            }

            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);
            $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);

            if ($taskType === 'shared_component') {
                $region = (string)($task['region'] ?? '');
                $this->sendOperationProgress(
                    $sse,
                    $session,
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'build',
                    $region === 'header' ? __('Generating shared header') : __('Generating shared footer'),
                    $progressPercent
                );
                $component = $pageComponentGenerationService->generateSharedComponent($region, $scope['website_profile'], $scope);
                $sharedComponents[$region] = $component;
                $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
                $scope['shared_components'][$region] = $component;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['region' => $region]);
                $this->virtualThemeService->saveGeneratedSharedComponent((int)$scope['virtual_theme_id'], $component);
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
                $scope['page_type_layouts'] = $pageTypeLayouts;
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                $message = $region === 'header' ? 'Shared header generated' : 'Shared footer generated';
                $this->appendWorkspaceEvent(
                    $session->getId(),
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'shared_component_generated',
                    $message,
                    ['operation' => 'build', 'details' => ['region' => $region]]
                );
                $freshShared = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $sharedState = $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($freshShared, $adminId, 80, true)
                );
                $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => $taskKey,
                    'task_type' => $taskType,
                    'message' => $message,
                    'state' => $sharedState,
                ]));
                continue;
            }

            if ($taskType !== 'page_section') {
                continue;
            }

            $pageType = (string)($task['page_type'] ?? '');
            $sectionCode = (string)($task['section_code'] ?? '');
            if ($pageType === '' || $sectionCode === '') {
                continue;
            }

            $pageLabel = (string)($pageTypeLabels[$pageType] ?? $pageType);
            $blueprintSpecs = $pageComponentGenerationService->buildPageSectionSpecs($pageType, $scope['website_profile'], $scope);
            $blueprint = \is_array($blueprintSpecs['blueprint'] ?? null)
                ? $blueprintSpecs['blueprint']
                : $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $scope['website_profile']);
            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'build',
                __('Generating themed page section: %{page}', ['page' => $pageLabel]),
                $progressPercent,
                $pageType
            );

            $sectionComponent = $pageComponentGenerationService->generatePageSection($pageType, $sectionCode, $scope['website_profile'], $scope);
            $this->virtualThemeService->saveGeneratedContentComponent((int)$scope['virtual_theme_id'], $pageType, $sectionComponent);

            $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($pageTypeLayouts[$pageType] ?? [], $pageType);
            if (\trim((string)($layout['header']['component'] ?? '')) === '' && \is_array($sharedComponents['header'] ?? null)) {
                $layout['header'] = [
                    'component' => (string)($sharedComponents['header']['code'] ?? ''),
                    'config' => \is_array($sharedComponents['header']['default_config'] ?? null) ? $sharedComponents['header']['default_config'] : [],
                ];
            }
            if (\trim((string)($layout['footer']['component'] ?? '')) === '' && \is_array($sharedComponents['footer'] ?? null)) {
                $layout['footer'] = [
                    'component' => (string)($sharedComponents['footer']['code'] ?? ''),
                    'config' => \is_array($sharedComponents['footer']['default_config'] ?? null) ? $sharedComponents['footer']['default_config'] : [],
                ];
            }
            $layout = $this->virtualThemeService->mergeGeneratedContentIntoLayout($layout, $sectionComponent);
            $this->virtualThemeService->saveGeneratedPageLayout((int)$scope['virtual_theme_id'], $pageType, $layout);
            $pageTypeLayouts[$pageType] = $layout;

            $currentVirtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType([$pageType], \array_replace($scope, [
                'page_type_layouts' => $pageTypeLayouts,
                'virtual_pages_by_type' => $virtualPages,
            ]));
            if (isset($currentVirtualPages[$pageType])) {
                $currentVirtualPages[$pageType]['last_generated_at'] = \date('Y-m-d H:i:s');
                $currentVirtualPages[$pageType]['title'] = (string)($blueprint['page_title'] ?? ($currentVirtualPages[$pageType]['title'] ?? ''));
                $currentVirtualPages[$pageType]['ai_description'] = (string)($blueprint['ai_description'] ?? ($currentVirtualPages[$pageType]['ai_description'] ?? ''));
                $currentVirtualPages[$pageType]['meta_title'] = (string)($blueprint['meta_title'] ?? ($currentVirtualPages[$pageType]['meta_title'] ?? ''));
                $currentVirtualPages[$pageType]['meta_description'] = (string)($blueprint['meta_description'] ?? ($currentVirtualPages[$pageType]['meta_description'] ?? ''));
                $currentVirtualPages[$pageType]['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($currentVirtualPages[$pageType]['meta_keywords'] ?? ''));
                $currentVirtualPages[$pageType]['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
                $virtualPages[$pageType] = $currentVirtualPages[$pageType];
            }

            $scope['page_type_layouts'] = $pageTypeLayouts;
            $scope['virtual_pages_by_type'] = $virtualPages;
            $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? $pageType));
            $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);

            $materialized = $this->materializeGeneratedPages(
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                (int)$draftWebsite['website_id'],
                $scope['website_profile'],
                [$pageType],
                [$pageType => $layout],
                [$pageType => $virtualPages[$pageType]]
            );
            $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

            $pageId = (int)($scope['pagebuilder_pages_by_type'][$pageType]['page_id'] ?? 0);
            if (!$environmentReadyEmitted && $pageId > 0) {
                $environmentReadyEmitted = true;
                $this->emitBuildEnvironmentReady($sse, $session, $adminId, $pageType, $pageLabel, $pageId, (int)$scope['virtual_theme_id']);
            }

            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = $this->buildWorkspaceEventStatePayload(
                $this->buildWorkspaceState($fresh, $adminId, 80, true),
                [$pageType]
            );
            $pageCompleted = $this->buildTaskService->arePageTasksComplete($scope, $pageType);
            $pageGeneratedMessage = $pageCompleted
                ? 'Page generation complete: ' . $pageLabel
                : 'Page updated and ready to edit: ' . $pageLabel;
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('页面已生成：%{page}', ['page' => $pageLabel]),
                [
                    'operation' => 'build',
                    'page_type' => $pageType,
                    'details' => [
                        'section_code' => $sectionCode,
                        'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                        'page_completed' => $pageCompleted ? 1 : 0,
                    ],
                ]
            );
            $sse->sendEvent('page_generated', $this->enrichTaskEventPayload($scope, [
                'task_key' => $taskKey,
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'page_id' => $pageId,
                'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                'progress_percent' => $progressPercent,
                'section_code' => $sectionCode,
                'page_completed' => $pageCompleted,
                'message' => $pageGeneratedMessage,
                'state' => $state,
            ]));
            $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                'task_key' => $taskKey,
                'task_type' => $taskType,
                'message' => (string)__('Theme section task complete: %{page}', ['page' => $pageLabel]),
                'state' => $state,
            ]));
            }
        }

        $now = \date('Y-m-d H:i:s');
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'last_generated_at' => $now,
            'active_operation' => 'build',
            'can_publish' => !$this->buildTaskService->hasPendingTasks($scope),
            'task_summary' => $this->buildTaskService->summarize($scope),
        ];
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            ['status' => 'done', 'updated_at' => $now, 'message' => (string)__('Virtual theme generated')]
        );

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)($scope['virtual_theme_id'] ?? 0));
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Virtual theme ready for editing'), 100);
        return [
            'message' => (string)__('Virtual theme build complete'),
            'draft_website_id' => (int)$draftWebsite['website_id'],
            'virtual_theme_id' => (int)($scope['virtual_theme_id'] ?? 0),
            'page_types' => $pageTypes,
        ];
    }

    private function runVirtualThemeBuildOperationV3(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        // queue:run -f：_queue_force_build.active=1 时强制走 AI，并绕过共享 Header/Footer 的进程内静态缓存。
        $queueForcedAiRebuild = (int)($scope['_queue_force_build']['active'] ?? 0) === 1;
        $pageTypeLabels = Page::getPageTypes();
        $dispatchWindow = 3;
        $initialPendingTasks = $this->buildTaskService->listPendingTasks($scope);
        $totalSteps = \max(1, \count($initialPendingTasks) + 1);
        $currentStep = 0;

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Preparing virtual theme workspace'), $progressPercent);

        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];
        $themeShell = $this->virtualThemeService->ensureThemeShell($scope, $scope['website_profile'], $session->getId());
        $scope['virtual_theme_id'] = (int)$themeShell['virtual_theme_id'];

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $environmentReadyEmitted = false;
        $parallelPageModeLogged = false;

        while (true) {
            $taskBatch = $this->buildTaskService->pickConcurrentTasks($scope, $dispatchWindow);
            if ($taskBatch === []) {
                break;
            }

            $sharedTasks = [];
            $pageTasks = [];
            foreach ($taskBatch as $task) {
                $taskType = (string)($task['task_type'] ?? '');
                if ($taskType === 'shared_component') {
                    $sharedTasks[] = $task;
                    continue;
                }
                if ($taskType === 'page_section') {
                    $pageTasks[] = $task;
                }
            }

            foreach ($sharedTasks as $task) {
                $this->assertActiveStreamLeaseAlive($session, $adminId);
                $taskKey = (string)($task['task_key'] ?? '');
                if ($taskKey === '') {
                    continue;
                }

                $currentStep++;
                $progressPercent = (int)(($currentStep / $totalSteps) * 100);
                $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);

                $region = (string)($task['region'] ?? '');
                $this->sendOperationProgress(
                    $sse,
                    $session,
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'build',
                    $region === 'header' ? __('Generating shared header') : __('Generating shared footer'),
                    $progressPercent
                );
                $component = $pageComponentGenerationService->generateSharedComponent(
                    $region,
                    $scope['website_profile'],
                    $scope,
                    '',
                    $queueForcedAiRebuild
                );
                $sharedComponents[$region] = $component;
                $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
                $scope['shared_components'][$region] = $component;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['region' => $region]);
                $this->virtualThemeService->saveGeneratedSharedComponent((int)$scope['virtual_theme_id'], $component);
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
                $scope['page_type_layouts'] = $pageTypeLayouts;
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                $message = $region === 'header' ? 'Shared header generated' : 'Shared footer generated';
                $this->appendWorkspaceEvent(
                    $session->getId(),
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'shared_component_generated',
                    $message,
                    ['operation' => 'build', 'details' => ['region' => $region]]
                );
                $freshShared = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $sharedState = $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($freshShared, $adminId, 80, true)
                );
                $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => $taskKey,
                    'task_type' => 'shared_component',
                    'message' => $message,
                    'state' => $sharedState,
                ]));
            }

            if ($pageTasks === []) {
                continue;
            }

            if (!$parallelPageModeLogged) {
                $parallelPageModeLogged = true;
                $this->emitBuildInfoEvent($sse, 'Shared theme ready; remaining pages will be generated in concurrent batches.', [
                    'event_type' => 'build_parallel_mode_enabled',
                    'parallel' => true,
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                ]);
            }

            $batchSpecs = $this->buildConcurrentPageTaskBatchSpecs(
                $pageComponentGenerationService,
                $pageTasks,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                $scope,
                $pageTypeLabels
            );
            $componentSpecs = \is_array($batchSpecs['components'] ?? null) ? $batchSpecs['components'] : [];
            $taskMeta = \is_array($batchSpecs['meta'] ?? null) ? $batchSpecs['meta'] : [];
            if ($componentSpecs === []) {
                continue;
            }

            $runningTaskKeys = \array_values(\array_map('strval', \array_keys($componentSpecs)));
            foreach ($runningTaskKeys as $runningTaskKey) {
                $scope = $this->buildTaskService->markTaskRunning($scope, $runningTaskKey);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

            $batchPageTypes = $this->extractBuildBatchPageTypes($pageTasks);
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('Starting concurrent page batch for %{count} tasks.', ['count' => (string)\count($runningTaskKeys)]),
                [
                    'event_type' => 'build_parallel_batch',
                    'batch_state' => 'started',
                    'parallel' => true,
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                    'batch_size' => \count($runningTaskKeys),
                    'page_types' => $batchPageTypes,
                    'task_keys' => $runningTaskKeys,
                ]
            );

            $completedTaskKeys = [];
            foreach ($pageComponentGenerationService->generateComponentEventsConcurrently($componentSpecs) as $taskKey => $event) {
                $this->assertActiveStreamLeaseAlive($session, $adminId);
                $meta = \is_array($taskMeta[$taskKey] ?? null) ? $taskMeta[$taskKey] : [];
                $pageType = (string)($meta['page_type'] ?? '');
                $sectionCode = (string)($meta['section_code'] ?? '');
                $pageLabel = (string)($meta['page_label'] ?? $pageType);

                if (($event['status'] ?? '') !== 'fulfilled') {
                    $throwable = $event['error'] instanceof \Throwable
                        ? $event['error']
                        : new \RuntimeException((string)__('Concurrent build task failed'));
                    $scope = $this->rollbackConcurrentBuildBatch(
                        $scope,
                        $runningTaskKeys,
                        $completedTaskKeys,
                        (string)$taskKey,
                        $throwable->getMessage()
                    );
                    $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                    $this->emitBuildInfoEvent(
                        $sse,
                        (string)__('Concurrent page batch failed: %{page}', ['page' => $pageLabel !== '' ? $pageLabel : (string)$taskKey]),
                        [
                            'event_type' => 'build_parallel_batch',
                            'batch_state' => 'failed',
                            'parallel' => true,
                            'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                            'task_key' => (string)$taskKey,
                            'page_type' => $pageType,
                            'task_keys' => $runningTaskKeys,
                            'completed_task_keys' => $completedTaskKeys,
                            'error_message' => $throwable->getMessage(),
                        ]
                    );
                    throw $throwable;
                }

                $currentStep++;
                $progressPercent = (int)(($currentStep / $totalSteps) * 100);
                $this->sendOperationProgress(
                    $sse,
                    $session,
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'build',
                    __('Theme page batch synced: %{page}', ['page' => $pageLabel]),
                    $progressPercent,
                    $pageType
                );

                $blueprint = \is_array($meta['blueprint'] ?? null) ? $meta['blueprint'] : [];
                $sectionComponent = \is_array($event['result'] ?? null) ? $event['result'] : [];
                $this->virtualThemeService->saveGeneratedContentComponent((int)$scope['virtual_theme_id'], $pageType, $sectionComponent);

                $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($pageTypeLayouts[$pageType] ?? [], $pageType);
                if (\trim((string)($layout['header']['component'] ?? '')) === '' && \is_array($sharedComponents['header'] ?? null)) {
                    $layout['header'] = [
                        'component' => (string)($sharedComponents['header']['code'] ?? ''),
                        'config' => \is_array($sharedComponents['header']['default_config'] ?? null) ? $sharedComponents['header']['default_config'] : [],
                    ];
                }
                if (\trim((string)($layout['footer']['component'] ?? '')) === '' && \is_array($sharedComponents['footer'] ?? null)) {
                    $layout['footer'] = [
                        'component' => (string)($sharedComponents['footer']['code'] ?? ''),
                        'config' => \is_array($sharedComponents['footer']['default_config'] ?? null) ? $sharedComponents['footer']['default_config'] : [],
                    ];
                }
                $layout = $this->virtualThemeService->mergeGeneratedContentIntoLayout($layout, $sectionComponent);
                $this->virtualThemeService->saveGeneratedPageLayout((int)$scope['virtual_theme_id'], $pageType, $layout);
                $pageTypeLayouts[$pageType] = $layout;

                $currentVirtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType([$pageType], \array_replace($scope, [
                    'page_type_layouts' => $pageTypeLayouts,
                    'virtual_pages_by_type' => $virtualPages,
                ]));
                if (isset($currentVirtualPages[$pageType])) {
                    $currentVirtualPages[$pageType]['last_generated_at'] = \date('Y-m-d H:i:s');
                    $currentVirtualPages[$pageType]['title'] = (string)($blueprint['page_title'] ?? ($currentVirtualPages[$pageType]['title'] ?? ''));
                    $currentVirtualPages[$pageType]['ai_description'] = (string)($blueprint['ai_description'] ?? ($currentVirtualPages[$pageType]['ai_description'] ?? ''));
                    $currentVirtualPages[$pageType]['meta_title'] = (string)($blueprint['meta_title'] ?? ($currentVirtualPages[$pageType]['meta_title'] ?? ''));
                    $currentVirtualPages[$pageType]['meta_description'] = (string)($blueprint['meta_description'] ?? ($currentVirtualPages[$pageType]['meta_description'] ?? ''));
                    $currentVirtualPages[$pageType]['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($currentVirtualPages[$pageType]['meta_keywords'] ?? ''));
                    $currentVirtualPages[$pageType]['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
                    $virtualPages[$pageType] = $currentVirtualPages[$pageType];
                }

                $scope['page_type_layouts'] = $pageTypeLayouts;
                $scope['virtual_pages_by_type'] = $virtualPages;
                $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? $pageType));
                $scope = $this->buildTaskService->markTaskDone($scope, (string)$taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);

                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                    (int)$draftWebsite['website_id'],
                    $scope['website_profile'],
                    [$pageType],
                    [$pageType => $layout],
                    [$pageType => $virtualPages[$pageType]]
                );
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                $pageId = (int)($scope['pagebuilder_pages_by_type'][$pageType]['page_id'] ?? 0);
                if (!$environmentReadyEmitted && $pageId > 0) {
                    $environmentReadyEmitted = true;
                    $this->emitBuildEnvironmentReady($sse, $session, $adminId, $pageType, $pageLabel, $pageId, (int)$scope['virtual_theme_id']);
                }

                $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $state = $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true),
                    [$pageType]
                );
                $pageCompleted = $this->buildTaskService->arePageTasksComplete($scope, $pageType);
                $pageGeneratedMessage = $pageCompleted
                    ? 'Page generation complete: ' . $pageLabel
                    : 'Page updated and ready to edit: ' . $pageLabel;
                $this->appendWorkspaceEvent(
                    $session->getId(),
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    'page_generated',
                    'Page generated: ' . $pageLabel,
                    [
                        'operation' => 'build',
                        'page_type' => $pageType,
                        'details' => [
                            'section_code' => $sectionCode,
                            'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                            'page_completed' => $pageCompleted ? 1 : 0,
                        ],
                    ]
                );
                $sse->sendEvent('page_generated', $this->enrichTaskEventPayload($scope, [
                    'task_key' => (string)$taskKey,
                    'page_type' => $pageType,
                    'page_label' => $pageLabel,
                    'page_id' => $pageId,
                    'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                    'progress_percent' => $progressPercent,
                    'section_code' => $sectionCode,
                    'page_completed' => $pageCompleted,
                    'message' => $pageGeneratedMessage,
                    'state' => $state,
                ]));
                $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => (string)$taskKey,
                    'task_type' => 'page_section',
                    'message' => 'Theme section task complete: ' . $pageLabel,
                    'state' => $state,
                ]));
                $completedTaskKeys[] = (string)$taskKey;
            }

            $this->emitBuildInfoEvent(
                $sse,
                (string)__('Concurrent page batch completed: %{count} tasks.', ['count' => (string)\count($completedTaskKeys)]),
                [
                    'event_type' => 'build_parallel_batch',
                    'batch_state' => 'completed',
                    'parallel' => true,
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                    'batch_size' => \count($runningTaskKeys),
                    'page_types' => $batchPageTypes,
                    'task_keys' => $runningTaskKeys,
                    'completed_task_keys' => $completedTaskKeys,
                ]
            );
        }

        $now = \date('Y-m-d H:i:s');
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'last_generated_at' => $now,
            'active_operation' => 'build',
            'can_publish' => !$this->buildTaskService->hasPendingTasks($scope),
            'task_summary' => $this->buildTaskService->summarize($scope),
        ];
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            ['status' => 'done', 'updated_at' => $now, 'message' => (string)__('Virtual theme generated')]
        );

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)($scope['virtual_theme_id'] ?? 0));
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Virtual theme ready for editing'), 100);
        return [
            'message' => (string)__('Virtual theme build complete'),
            'draft_website_id' => (int)$draftWebsite['website_id'],
            'virtual_theme_id' => (int)($scope['virtual_theme_id'] ?? 0),
            'page_types' => $pageTypes,
        ];
    }

    private function runRegeneratePageOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId, string $pageType): array
    {
        $this->assertActiveStreamLeaseAlive($session, $adminId);
        if ($pageType === '') {
            throw new \RuntimeException((string)__('缺少要重建的页面类型'));
        }
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            throw new \RuntimeException((string)__('页面类型不在当前工作区中'));
        }
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $scope = $this->buildTaskService->ensureTaskScope($scope, \is_array($scope['website_profile']) ? $scope['website_profile'] : [], (string)($scope['workspace_track'] ?? ''));
        $scope = $this->buildTaskService->resetPageTasksForRetry($scope, $pageType);

        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('正在重建页面：%{page}', ['page' => (string)(Page::getPageTypes()[$pageType] ?? $pageType)]), 20, $pageType);
            $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
            $blueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $scope['website_profile']);
            $blocks = $this->htmlBlocksBuildService->buildPlaceholderBlocksForPageType($pageType, $scope['website_profile'], $scope);
            $row = $virtualPages[$pageType] ?? [];
            $row['blocks'] = $blocks;
            $row['last_generated_at'] = \date('Y-m-d H:i:s');
            $row['title'] = (string)($blueprint['page_title'] ?? ($row['title'] ?? ''));
            $row['ai_description'] = (string)($blueprint['ai_description'] ?? ($row['ai_description'] ?? ''));
            $row['meta_title'] = (string)($blueprint['meta_title'] ?? ($row['meta_title'] ?? ''));
            $row['meta_description'] = (string)($blueprint['meta_description'] ?? ($row['meta_description'] ?? ''));
            $row['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($row['meta_keywords'] ?? ''));
            $row['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
            $virtualPages[$pageType] = $row;
            foreach (\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [] as $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $sectionCode = \trim((string)($section['code'] ?? ''));
                if ($sectionCode === '') {
                    continue;
                }
                $scope = $this->buildTaskService->markTaskDone(
                    $scope,
                    'page:' . $pageType . ':' . $sectionCode,
                    ['page_type' => $pageType, 'section_code' => $sectionCode]
                );
            }
            $scope['virtual_pages_by_type'] = $virtualPages;
            $scope['preview_page_type'] = $pageType;
            $materialized = $this->materializeGeneratedPages(
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                $scope['website_profile'],
                \array_keys($virtualPages),
                [],
                $virtualPages
            );
            $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            $scope['build_summary'] = \array_replace(
                \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
                ['task_summary' => $this->buildTaskService->summarize($scope)]
            );
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => \date('Y-m-d H:i:s'), 'message' => (string)__('页面区块已重建')]);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('页面重建完成'), 100, $pageType);

            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('页面已重建：%{page}', ['page' => (string)(Page::getPageTypes()[$pageType] ?? $pageType)]),
                [
                    'operation' => 'regenerate_page',
                    'page_type' => $pageType,
                    'details' => [
                        'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                    ],
                ]
            );

            return ['message' => (string)__('页面重建完成'), 'page_type' => $pageType, 'virtual_theme_id' => 0];
        }

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('正在重建页面：%{page}', ['page' => (string)(Page::getPageTypes()[$pageType] ?? $pageType)]), 20, $pageType);
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $pageTypeLayouts[$pageType] = $this->scopeCompatibilityService->normalizeLayoutConfig([], $pageType);

        $theme = $this->virtualThemeService->ensureAiGeneratedVirtualTheme($scope, $scope['website_profile'], $pageTypes, $pageTypeLayouts, $session->getId(), false);
        $scope['page_type_layouts'] = $theme['page_type_layouts'];
        $scope['virtual_theme_id'] = (int)$theme['virtual_theme_id'];

        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $blueprint = $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $scope['website_profile']);
        $virtualPages[$pageType]['last_generated_at'] = \date('Y-m-d H:i:s');
        $virtualPages[$pageType]['title'] = (string)($blueprint['page_title'] ?? ($virtualPages[$pageType]['title'] ?? ''));
        $virtualPages[$pageType]['ai_description'] = (string)($blueprint['ai_description'] ?? ($virtualPages[$pageType]['ai_description'] ?? ''));
        $virtualPages[$pageType]['meta_title'] = (string)($blueprint['meta_title'] ?? ($virtualPages[$pageType]['meta_title'] ?? ''));
        $virtualPages[$pageType]['meta_description'] = (string)($blueprint['meta_description'] ?? ($virtualPages[$pageType]['meta_description'] ?? ''));
        $virtualPages[$pageType]['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($virtualPages[$pageType]['meta_keywords'] ?? ''));
        $virtualPages[$pageType]['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
        foreach (\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : [] as $section) {
            if (!\is_array($section)) {
                continue;
            }
            $sectionCode = \trim((string)($section['code'] ?? ''));
            if ($sectionCode === '') {
                continue;
            }
            $scope = $this->buildTaskService->markTaskDone(
                $scope,
                'page:' . $pageType . ':' . $sectionCode,
                ['page_type' => $pageType, 'section_code' => $sectionCode]
            );
        }
        $scope['virtual_pages_by_type'] = $virtualPages;
            $scope['preview_page_type'] = $pageType;
            $materialized = $this->materializeGeneratedPages(
                AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                $scope['website_profile'],
                \array_keys($virtualPages),
                $scope['page_type_layouts'] ?? [],
                $virtualPages
            );
            $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            $scope['build_summary'] = \array_replace(
                \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
                ['task_summary' => $this->buildTaskService->summarize($scope)]
            );
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => \date('Y-m-d H:i:s'), 'message' => (string)__('页面重建完成')]);

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)$theme['virtual_theme_id']);
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'page_generated',
            (string)__('页面已重建：%{page}', ['page' => (string)(Page::getPageTypes()[$pageType] ?? $pageType)]),
            [
                'operation' => 'regenerate_page',
                'page_type' => $pageType,
                'details' => [
                    'section_count' => \count(\is_array($blueprint['sections'] ?? null) ? $blueprint['sections'] : []),
                    'virtual_theme_id' => (int)$theme['virtual_theme_id'],
                ],
            ]
        );

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'regenerate_page', __('页面重建完成，可继续调整组件'), 100, $pageType);
        return ['message' => (string)__('页面重建完成'), 'page_type' => $pageType, 'virtual_theme_id' => (int)$theme['virtual_theme_id']];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPublishOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): array
    {
        $this->assertActiveStreamLeaseAlive($session, $adminId);
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $websiteProfile = $this->profileGenerationService->generate($scope);
        if ((int)($scope['site_ready'] ?? 1) !== 1) {
            throw new \RuntimeException((string)__('域名尚未就绪，当前仅可保存草稿，无法发布上线。'));
        }

        $websiteId = \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId());
        $virtualThemeId = \max((int)($scope['virtual_theme_id'] ?? 0), (int)$session->getVirtualThemeId());
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            if ($websiteId <= 0) {
                throw new \RuntimeException((string)__('发布前请先完成 HTML 区块构建'));
            }
        } elseif ($websiteId <= 0 || $virtualThemeId <= 0) {
            throw new \RuntimeException((string)__('发布前请先完成主题构建'));
        }

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', __('正在创建正式页面并发布上线'), 25);
        $published = $this->publishService->publish(
            $websiteId,
            $virtualThemeId,
            $websiteProfile,
            $pageTypes,
            $pageTypeLayouts,
            \is_array($scope['virtual_pages_by_type'] ?? null) ? $scope['virtual_pages_by_type'] : [],
            $workspaceTrack
        );

        $scope['pagebuilder_pages_by_type'] = $published['pagebuilder_pages_by_type'] ?? [];
        $scope['materialized_pages_by_type'] = $published['materialized_pages_by_type'] ?? [];
        $scope['preview_page_id'] = (int)($published['preview_page_id'] ?? 0);
        $scope['preview_page_type'] = (string)($published['preview_page_type'] ?? ($scope['preview_page_type'] ?? ''));
        $scope['build_summary'] = \array_replace(
            \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
            ['task_summary' => $this->buildTaskService->summarize($scope)]
        );
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
        $this->assertActiveStreamLeaseAlive($session, $adminId);
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
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function enrichTaskEventPayload(array $scope, array $payload): array
    {
        $taskKey = \trim((string)($payload['task_key'] ?? ''));
        if ($taskKey === '') {
            return $payload;
        }
        $runtime = $this->resolveTaskRuntimeContextForEvent($scope, $taskKey);
        if ($runtime === []) {
            return $payload;
        }
        return \array_replace($payload, [
            'task_session_id' => (string)($runtime['task_session_id'] ?? ''),
            'task_runtime_context' => $runtime,
            'task_sse_channel' => 'task-sse:' . $taskKey,
        ]);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveTaskRuntimeContextForEvent(array $scope, string $taskKey): array
    {
        $definition = $this->buildTaskService->getTaskDefinition($scope, $taskKey);
        $runtime = \is_array($definition['runtime_context'] ?? null) ? $definition['runtime_context'] : [];
        if ($runtime !== []) {
            return $runtime;
        }
        $stateMap = \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [];
        $state = \is_array($stateMap[$taskKey] ?? null) ? $stateMap[$taskKey] : [];
        $runtime = \is_array($state['runtime_context'] ?? null) ? $state['runtime_context'] : [];
        if ($runtime !== []) {
            return $runtime;
        }
        $sessionScope = \trim((string)($scope['public_id'] ?? $scope['session_id'] ?? ''));
        return [
            'session_id' => $sessionScope,
            'task_key' => $taskKey,
            'task_session_id' => $sessionScope !== '' ? \sha1($sessionScope . ':' . $taskKey) : '',
            'stream_session_key' => $sessionScope !== '' ? ($sessionScope . ':' . $taskKey) : $taskKey,
        ];
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

    private function touchStreamLease(): string
    {
        return $this->fetchJson([
            'success' => true,
            'data' => [
                'mode' => 'standard_sse',
            ],
        ]);
    }

    private function touchStreamLeaseState(AiSiteAgentSession $session, int $adminId, string $leaseToken, string $tabToken = ''): int
    {
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $lease = \is_array($scope[self::STREAM_LEASE_SCOPE_KEY] ?? null) ? $scope[self::STREAM_LEASE_SCOPE_KEY] : [];
        $expiresAt = \time() + self::STREAM_LEASE_TTL_SEC;
        $lease['token'] = $leaseToken;
        $lease['tab_token'] = $tabToken;
        $lease['expires_at'] = $expiresAt;
        $lease['updated_at'] = \date('Y-m-d H:i:s');
        $scope[self::STREAM_LEASE_SCOPE_KEY] = $lease;
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

        return $expiresAt;
    }

    private function isStreamLeaseAlive(AiSiteAgentSession $session, int $adminId, string $leaseToken = ''): bool
    {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $lease = \is_array($scope[self::STREAM_LEASE_SCOPE_KEY] ?? null) ? $scope[self::STREAM_LEASE_SCOPE_KEY] : [];
        if ($lease === []) {
            return true;
        }

        if ($leaseToken !== '' && (string)($lease['token'] ?? '') !== $leaseToken) {
            return false;
        }

        return (int)($lease['expires_at'] ?? 0) >= \time();
    }

    /**
     * 当前连接持有的 lease 是否因过期而失效（排除「scope 已被其它标签页改写 token」的情况）。
     */
    private function isThisWorkspaceStreamLeaseExpired(AiSiteAgentSession $session, int $adminId, string $leaseToken): bool
    {
        if ($leaseToken === '') {
            return false;
        }
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $lease = \is_array($scope[self::STREAM_LEASE_SCOPE_KEY] ?? null) ? $scope[self::STREAM_LEASE_SCOPE_KEY] : [];
        if ($lease === []) {
            return false;
        }
        if ((string)($lease['token'] ?? '') !== $leaseToken) {
            return false;
        }

        return (int)($lease['expires_at'] ?? 0) < \time();
    }

    /**
     * 工作区 stream-sse 续约超时：取消仍绑定本会话的排队/运行中操作，避免无人值守的后台继续占用。
     */
    private function cancelWorkspaceWorkAfterStreamLeaseExpired(AiSiteAgentSession $session, int $adminId, string $leaseToken): void
    {
        if ($leaseToken === '') {
            return;
        }
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $lease = \is_array($scope[self::STREAM_LEASE_SCOPE_KEY] ?? null) ? $scope[self::STREAM_LEASE_SCOPE_KEY] : [];
        if ((string)($lease['token'] ?? '') !== $leaseToken) {
            return;
        }

        $active = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $status = \trim((string)($active['status'] ?? ''));
        if (!\in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $operation = \trim((string)($active['operation'] ?? ''));
        $stageCode = $this->scopeCompatibilityService->normalizeStage($fresh->getStage());
        $cancelMessage = (string)__('工作区事件流已断开（未及时续约），操作已终止');

        $publishStatus = null;
        if ($operation === 'publish') {
            if ($status === 'running') {
                $publishStatus = AiSiteAgentSession::PUBLISH_STATUS_FAILED;
                $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
            } else {
                $publishStatus = AiSiteAgentSession::PUBLISH_STATUS_DRAFT;
                $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            }
        } else {
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        }

        $scope['active_operation'] = [
            'operation' => $operation,
            'execution_token' => (string)($active['execution_token'] ?? ''),
            'status' => 'cancelled',
            'page_type' => (string)($active['page_type'] ?? ''),
            'updated_at' => \date('Y-m-d H:i:s'),
            'message' => $cancelMessage,
        ];

        $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);
        if ($publishStatus !== null) {
            $this->sessionService->setPublishStatus($fresh->getId(), $adminId, $publishStatus);
        }
        $this->appendWorkspaceEvent(
            $fresh->getId(),
            $adminId,
            $stageCode,
            'operation_cancelled',
            (string)__('由于工作区连接超时未续约，已终止后台操作'),
            ['details' => ['reason' => 'stream_lease_expired', 'operation' => $operation]]
        );
    }

    private function assertActiveStreamLeaseAlive(AiSiteAgentSession $session, int $adminId, string $leaseToken = ''): void
    {
        // 标准 SSE 模式下不做 lease 校验，连接生命周期由浏览器/TCP 自然管理。
    }

    private function releaseStreamLeaseState(AiSiteAgentSession $session, int $adminId, string $leaseToken): void
    {
        if ($leaseToken === '') {
            return;
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope($fresh->getScopeArray());
        $lease = \is_array($scope[self::STREAM_LEASE_SCOPE_KEY] ?? null) ? $scope[self::STREAM_LEASE_SCOPE_KEY] : [];
        if ((string)($lease['token'] ?? '') !== $leaseToken) {
            return;
        }

        unset($scope[self::STREAM_LEASE_SCOPE_KEY]);
        $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);
    }

    private function generateWorkspaceStreamLeaseToken(string $tabToken = ''): string
    {
        $seed = $tabToken !== '' ? ($tabToken . '|') : '';
        try {
            return $seed . \bin2hex(\random_bytes(12));
        } catch (\Throwable) {
            return $seed . \str_replace('.', '', \uniqid('workspace-stream-', true));
        }
    }

    /**
     * @param array<string, mixed> $activeOperation
     */
    private function isActiveOperationStale(array $activeOperation): bool
    {
        $updatedAtRaw = \trim((string)($activeOperation['updated_at'] ?? $activeOperation['started_at'] ?? ''));
        if ($updatedAtRaw === '') {
            return true;
        }
        $updatedAtTs = \strtotime($updatedAtRaw);
        if ($updatedAtTs === false) {
            return true;
        }
        return (\time() - $updatedAtTs) > self::STALE_ACTIVE_OPERATION_TTL_SEC;
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
        $isAutosave = \in_array(\strtolower(\trim((string)$this->getRequestBodyValue('autosave', '0'))), ['1', 'true', 'yes', 'on'], true);
        $error = '';
        $payload = $this->getRequestJsonObject($jsonKey, $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        if (isset($payload['page_types']) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $payload)) {
            $payload[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        $siteProfileManual = \is_array($payload['site_profile_manual'] ?? null) ? $payload['site_profile_manual'] : [];
        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale', 'plan_locale'] as $manualField) {
            if (\array_key_exists($manualField, $payload) && !\array_key_exists($manualField, $siteProfileManual)) {
                $siteProfileManual[$manualField] = true;
            }
        }
        if ($siteProfileManual !== []) {
            $payload['site_profile_manual'] = $siteProfileManual;
        }

        $saved = $merge ? $this->sessionService->mergeScope($session->getId(), $adminId, $payload) : $this->sessionService->replaceScope($session->getId(), $adminId, $payload);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => __('保存失败')]);
        }
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($session->getStage()),
            $isAutosave ? 'autosave_saved' : ($merge ? 'scope_merged' : 'scope_replaced'),
            $isAutosave
                ? (string)__('工作区已自动保存')
                : ($merge ? (string)__('工作区信息已合并保存') : (string)__('工作区信息已整体替换')),
            ['details' => ['keys' => \array_values(\array_map('strval', \array_keys($payload))), 'autosave' => $isAutosave ? 1 : 0]]
        );
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson(['success' => true, 'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true)]);
    }

    private function handleRefinePlanPage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $instruction === '') {
            return $this->jsonError('INVALID_PARAMS', 'Missing required params.', self::PARAMS_REFINE_PLAN_PAGE);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session not found.', self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if (!\is_array($planJson['pages'][$pageType] ?? null)) {
            return $this->jsonError('PAGE_PLAN_NOT_FOUND', 'Stage-1 page plan not found.', ['public_id', 'page_type']);
        }

        $round = \max(1, (int)$this->getRequestBodyValue('round', ((int)($scope['plan_last_round'] ?? 0)) + 1));
        $targetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        $websiteProfile = $this->profileGenerationService->generate($scope, false);
        try {
            $artifacts = $this->executionBlueprintService->refineDraftPlanPage(
                $scope,
                \is_array($websiteProfile) ? $websiteProfile : [],
                $pageType,
                [
                    'instruction' => $instruction,
                    'target_scope' => $targetScope !== '' ? $targetScope : ('pages.' . $pageType),
                    'round' => $round,
                ]
            );
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }

        $executionBlueprint = \is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [];
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, [
            'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
            'execution_blueprint_draft' => $executionBlueprint,
            'plan_json' => \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
            'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
            'plan_structured' => \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
            'plan_workbench' => \is_array($artifacts['plan_workbench'] ?? null) ? $artifacts['plan_workbench'] : [],
            'plan_last_prompt_mode' => 'refine_page',
            'plan_last_target_scope' => $targetScope !== '' ? $targetScope : ('pages.' . $pageType),
            'plan_last_round' => $round,
            'plan_confirmed' => (int)($scope['plan_confirmed'] ?? 0),
            'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? $scope['execution_blueprint_signature'] ?? ''),
            'plan_change_scope_report' => \is_array($artifacts['page_refine_summary'] ?? null) ? $artifacts['page_refine_summary'] : [],
        ]);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => 'Failed to persist page refine result.']);
        }

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($session->getStage()),
            'plan_page_refined',
            'Stage-1 current page plan blocks refined.',
            [
                'operation' => 'refine_plan_page',
                'page_type' => $pageType,
                'details' => \is_array($artifacts['page_refine_summary'] ?? null) ? $artifacts['page_refine_summary'] : [],
            ]
        );
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson([
            'success' => true,
            'message' => 'Stage-1 current page plan blocks refined.',
            'page_type' => $pageType,
            'summary' => \is_array($artifacts['page_refine_summary'] ?? null) ? $artifacts['page_refine_summary'] : [],
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    private function handleSortPlanBlocks(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        if ($adminId <= 0 || $publicId === '' || $pageType === '') {
            return $this->jsonError('INVALID_PARAMS', 'Missing required params.', ['public_id', 'page_type', 'ordered_block_keys']);
        }

        $error = '';
        $orderedBlockKeys = $this->getRequestJsonStringList('ordered_block_keys', $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        if ($orderedBlockKeys === []) {
            return $this->jsonError('INVALID_PARAMS', 'ordered_block_keys must not be empty.', ['ordered_block_keys']);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session not found.', self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        try {
            $artifacts = $this->executionBlueprintService->reorderDraftPlanBlocks($scope, $pageType, $orderedBlockKeys);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }

        $executionBlueprint = \is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [];
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, [
            'execution_blueprint_draft' => $executionBlueprint,
            'plan_json' => \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
            'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
            'plan_structured' => \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
            'plan_workbench' => \is_array($artifacts['plan_workbench'] ?? null) ? $artifacts['plan_workbench'] : [],
            'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? $scope['execution_blueprint_signature'] ?? ''),
        ]);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => 'Failed to persist plan order.']);
        }

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($session->getStage()),
            'plan_blocks_reordered',
            'Stage-1 plan blocks reordered.',
            ['details' => \is_array($artifacts['reorder_summary'] ?? null) ? $artifacts['reorder_summary'] : []]
        );
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson([
            'success' => true,
            'message' => 'Stage-1 plan order saved.',
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    private function handleMutatePlanBlock(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $action = \strtolower(\trim((string)$this->getRequestBodyValue('action', '')));
        $blockKey = \trim((string)$this->getRequestBodyValue('block_key', ''));
        if ($adminId <= 0 || $publicId === '' || $pageType === '' || !\in_array($action, ['create', 'delete', 'rebuild'], true)) {
            return $this->jsonError('INVALID_PARAMS', 'Missing required params.', self::PARAMS_MUTATE_PLAN_BLOCK);
        }
        if ($action !== 'create' && $blockKey === '') {
            return $this->jsonError('INVALID_PARAMS', 'block_key is required for delete/rebuild.', self::PARAMS_MUTATE_PLAN_BLOCK);
        }

        $blockConfig = [];
        $rawBlockConfig = $this->getRequestBodyValue('block_config', null);
        if ($rawBlockConfig !== null && $rawBlockConfig !== '') {
            $error = '';
            $blockConfig = $this->getRequestJsonObject('block_config', $error);
            if ($error !== '') {
                return $this->fetchJson(['success' => false, 'message' => $error]);
            }
        }
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        if ($instruction !== '' && !isset($blockConfig['instruction'])) {
            $blockConfig['instruction'] = $instruction;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session not found.', self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        try {
            $artifacts = $this->executionBlueprintService->mutateDraftPlanBlock($scope, $pageType, $action, $blockKey, $blockConfig);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }

        $executionBlueprint = \is_array($artifacts['execution_blueprint'] ?? null) ? $artifacts['execution_blueprint'] : [];
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, [
            'execution_blueprint_draft' => $executionBlueprint,
            'plan_json' => \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
            'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
            'plan_structured' => \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
            'plan_workbench' => \is_array($artifacts['plan_workbench'] ?? null) ? $artifacts['plan_workbench'] : [],
            'execution_blueprint_signature' => (string)($executionBlueprint['signature'] ?? $scope['execution_blueprint_signature'] ?? ''),
        ]);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => 'Failed to persist plan block mutation.']);
        }

        $summary = \is_array($artifacts['mutation_summary'] ?? null) ? $artifacts['mutation_summary'] : [];
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($session->getStage()),
            'plan_block_mutated',
            'Stage-1 plan block updated.',
            ['details' => $summary]
        );
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson([
            'success' => true,
            'message' => 'Stage-1 plan block updated.',
            'mutation' => $summary,
            'block' => \is_array($artifacts['block'] ?? null) ? $artifacts['block'] : null,
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    private function handleSortTaskPlanTasks(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $bucket = \trim((string)$this->getRequestBodyValue('bucket', 'page'));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', 'Missing required params.', ['public_id', 'bucket', 'ordered_task_keys']);
        }

        $error = '';
        $orderedTaskKeys = $this->getRequestJsonStringList('ordered_task_keys', $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        if ($orderedTaskKeys === []) {
            return $this->jsonError('INVALID_PARAMS', 'ordered_task_keys must not be empty.', ['ordered_task_keys']);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session not found.', self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        try {
            $artifacts = $this->virtualThemePlanService->reorderDraftTaskPlanTasks($scope, $bucket, $orderedTaskKeys, $pageType);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }

        $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
        $virtualThemePlan = \is_array($artifacts['virtual_theme_plan'] ?? null) ? $artifacts['virtual_theme_plan'] : [];
        $scopePatch = [
            'virtual_theme_plan' => [
                'draft' => $virtualThemePlan,
                'draft_markdown' => (string)($artifacts['markdown'] ?? ''),
                'draft_generated_at' => \date('Y-m-d H:i:s'),
                'confirmed' => \is_array($scope['virtual_theme_plan']['confirmed'] ?? null) ? $scope['virtual_theme_plan']['confirmed'] : [],
                'confirmed_markdown' => (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''),
                'confirmed_at' => (string)($scope['virtual_theme_plan']['confirmed_at'] ?? ''),
                'confirmed_signature' => (string)($scope['virtual_theme_plan']['confirmed_signature'] ?? ''),
                'plan_signature' => (string)($virtualThemePlan['signature'] ?? $scope['virtual_theme_plan']['plan_signature'] ?? ''),
                'last_prompt_mode' => (string)($scope['virtual_theme_plan']['last_prompt_mode'] ?? ''),
                'last_target_scope' => (string)($scope['virtual_theme_plan']['last_target_scope'] ?? ''),
                'last_round' => (int)($scope['virtual_theme_plan']['last_round'] ?? 0),
            ],
            'task_plan_markdown' => (string)($artifacts['markdown'] ?? ''),
            'task_plan_generated_at' => \date('Y-m-d H:i:s'),
            'task_plan_structured' => $structured,
            'task_plan_directory_tree' => \is_array($structured['task_directory_tree'] ?? null) ? $structured['task_directory_tree'] : [],
            'task_plan_summary' => [
                'signature' => (string)($virtualThemePlan['signature'] ?? ''),
                'shared_task_count' => \count(\is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : []),
                'page_task_count' => \array_sum(\array_map(static fn($items): int => \is_array($items) ? \count($items) : 0, \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : [])),
                'prompt_mode' => 'manual_reorder',
                'generation_source' => 'manual_reorder',
            ],
        ];
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => 'Failed to persist task-plan order.']);
        }

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($session->getStage()),
            'task_plan_tasks_reordered',
            'Stage-2 task plan order saved.',
            ['details' => \is_array($artifacts['reorder_summary'] ?? null) ? $artifacts['reorder_summary'] : []]
        );
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson([
            'success' => true,
            'message' => 'Stage-2 task-plan order saved.',
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    private function buildOperationStreamUrl(string $publicId, string $executionToken): string
    {
        return $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/operation-sse', ['public_id' => $publicId, 'execution_token' => $executionToken]);
    }

    private function supportsBackgroundOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan', 'build', 'regenerate_page', 'publish'], true);
    }

    private function shouldKeepQueuedObserverStreamOpen(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan'], true);
    }

    /**
     * Stage-1/2 planning operations are intentionally long-running and should not be auto-reclaimed
     * just because their active_operation timestamp is old.
     *
     * @param array<string, mixed> $activeOperation
     */
    private function shouldReclaimStaleActiveOperation(array $activeOperation): bool
    {
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        return !\in_array($operation, ['plan', 'task_plan'], true);
    }

    private function getObserverMaxIdleLoops(): int
    {
        // 0 means "do not cut off observer mode because of idle time".
        return 0;
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
     * @param-out string $error
     * @return list<string>
     */
    private function getRequestJsonStringList(string $key, string &$error = ''): array
    {
        $error = '';
        $raw = $this->getRequestBodyValue($key, '');
        if (\is_array($raw)) {
            return \array_values(\array_filter(\array_map(static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '', $raw), static fn(string $value): bool => $value !== ''));
        }
        $raw = \is_string($raw) ? \trim($raw) : '';
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            $error = 'Invalid JSON: ' . $jsonException->getMessage();
            return [];
        }
        if (!\is_array($decoded)) {
            $error = 'Request body must be a JSON array.';
            return [];
        }
        return \array_values(\array_filter(\array_map(static fn($value): string => \is_scalar($value) ? \trim((string)$value) : '', $decoded), static fn(string $value): bool => $value !== ''));
    }

    private function getQuickBuildAggregator(): QuickBuildAggregator
    {
        return ObjectManager::getInstance(QuickBuildAggregator::class);
    }

    private function getWebsitesSessionService(): WebsitesSessionService
    {
        return ObjectManager::getInstance(WebsitesSessionService::class);
    }

    private function getWebsitesDomainPurchaseWorkbenchService(): WebsitesDomainPurchaseWorkbenchService
    {
        return ObjectManager::getInstance(WebsitesDomainPurchaseWorkbenchService::class);
    }

    /**
     * @return list<array{account_id:int,label:string,registrar_name:string,registrar_code:string,account_name:string}>
     */
    private function buildRegistrarAccountOptions(): array
    {
        $rows = $this->getQuickBuildAggregator()->queryRegistrarAccounts(['status' => 'active']);
        if (!\is_array($rows)) {
            $rows = [];
        }

        $options = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $accountId = (int)($row['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }
            $registrarName = \trim((string)($row['registrar_name'] ?? $row['registrar_code'] ?? ''));
            $accountName = \trim((string)($row['account_name'] ?? ''));
            $label = $registrarName !== ''
                ? $registrarName . ($accountName !== '' ? (' - ' . $accountName) : '')
                : ($accountName !== '' ? $accountName : (string)__('鏈嶅姟鍟?'));
            $options[] = [
                'account_id' => $accountId,
                'label' => $label,
                'registrar_name' => $registrarName,
                'registrar_code' => (string)($row['registrar_code'] ?? ''),
                'account_name' => $accountName,
            ];
        }

        if (\defined('DEV') && DEV) {
            $localDemo = [
                'account_id' => 900001,
                'label' => (string)__('本地供应商 - 本地默认账号'),
                'registrar_name' => (string)__('本地供应商'),
                'registrar_code' => 'local_demo',
                'account_name' => (string)__('本地默认账号'),
            ];
            $hasLocalDemo = false;
            foreach ($options as $option) {
                if ((int)($option['account_id'] ?? 0) === 900001 || (string)($option['registrar_code'] ?? '') === 'local_demo') {
                    $hasLocalDemo = true;
                    break;
                }
            }
            if (!$hasLocalDemo) {
                \array_unshift($options, $localDemo);
            }
        }

        return $options;
    }

    private function ensureLinkedWebsitesMirrorSession(AiSiteAgentSession $session, int $adminId): ?WebsitesAiSiteBuilderSession
    {
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $linkedPublicId = \trim((string)($scope['handoff_workspace_public_id'] ?? ''));
        $websitesSessionService = $this->getWebsitesSessionService();
        $linkedSession = $linkedPublicId !== ''
            ? $websitesSessionService->loadByPublicId($linkedPublicId, $adminId)
            : null;
        $linkedScope = $this->buildLinkedWebsitesScopeFromPageBuilderSession($session);

        if (!$linkedSession instanceof WebsitesAiSiteBuilderSession) {
            $linkedSession = $websitesSessionService->createSession('pagebuilder', $adminId, $linkedScope, [], 'prepare');
            $this->sessionService->mergeScope($session->getId(), $adminId, [
                'handoff_workspace_public_id' => $linkedSession->getPublicId(),
                'provider_handoff_mode' => 'pagebuilder_native_workspace',
            ]);

            return $linkedSession;
        }

        $websitesSessionService->mergeScope($linkedSession->getId(), $adminId, $linkedScope);

        return $websitesSessionService->loadById($linkedSession->getId(), $adminId) ?? $linkedSession;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLinkedWebsitesScopeFromPageBuilderSession(AiSiteAgentSession $session): array
    {
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $targetDomain = \strtolower(\trim((string)($scope['target_domain'] ?? '')));
        $brief = \trim((string)($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        $preferredRegistrarAccountId = (int)($scope['preferred_registrar_account_id'] ?? $scope['registrar_account_id'] ?? 0);
        $recommendedRegistrarLabel = \trim((string)($scope['recommended_registrar_label'] ?? ''));
        $recommendedDomainList = $this->normalizeStringList($scope['recommended_domain_list'] ?? []);
        if ($recommendedDomainList === [] && $targetDomain !== '') {
            $recommendedDomainList[] = $targetDomain;
        }

        return [
            'handoff_source' => 'pagebuilder_native_workspace',
            'provider_handoff_mode' => 'pagebuilder_native_workspace',
            'pagebuilder_workspace_public_id' => $session->getPublicId(),
            'pagebuilder_workspace_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $session->getPublicId()]),
            'site_title' => (string)($scope['site_title'] ?? ''),
            'site_tagline' => (string)($scope['site_tagline'] ?? ''),
            'default_locale' => \trim((string)($scope['default_locale'] ?? $scope['default_language'] ?? '')),
            'plan_locale' => \trim((string)($scope['plan_locale'] ?? $scope['default_language'] ?? $scope['default_locale'] ?? '')),
            'brief_description' => $brief,
            'user_description' => $brief !== '' ? $brief : (string)($scope['user_description'] ?? ''),
            'target_domain' => $targetDomain,
            'selected_domain' => $targetDomain,
            'preferred_registrar_account_id' => $preferredRegistrarAccountId,
            'registrar_account_id' => $preferredRegistrarAccountId,
            'recommended_registrar_label' => $recommendedRegistrarLabel,
            'recommended_domain_list' => $recommendedDomainList,
            'page_types' => \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [],
            'recommended_pages' => \is_array($scope['recommended_pages'] ?? null) ? $scope['recommended_pages'] : (\is_array($scope['page_types'] ?? null) ? $scope['page_types'] : []),
            'fake_mode' => !empty($scope['fake_mode']) ? 1 : 0,
            'site_ready' => (int)($scope['site_ready'] ?? 1),
        ];
    }

    private function syncPageBuilderScopeFromLinkedWebsitesSession(
        AiSiteAgentSession $pageBuilderSession,
        WebsitesAiSiteBuilderSession $websitesSession,
        int $adminId
    ): void {
        $scope = $websitesSession->getScopeArray();
        $siteProfileManual = \is_array($scope['site_profile_manual'] ?? null) ? $scope['site_profile_manual'] : [];
        $patch = [
            'handoff_workspace_public_id' => $websitesSession->getPublicId(),
            'provider_handoff_mode' => 'pagebuilder_native_workspace',
        ];

        foreach ([
            'target_domain',
            'selected_domain',
            'preferred_registrar_account_id',
            'registrar_account_id',
            'recommended_registrar_label',
            'recommended_domain_list',
            'fake_mode',
            'site_ready',
            'domain_purchase_status',
            'domain_purchase_stage',
            'domain_purchase_stage_label',
            'domain_purchase_message',
            'domain_purchase_order_id',
        ] as $field) {
            if (\array_key_exists($field, $scope)) {
                $patch[$field] = $scope[$field];
            }
        }

        foreach ([
            'site_title',
            'site_tagline',
            'brief_description',
            'user_description',
            'default_locale',
            'plan_locale',
            'locales',
            'page_types',
            'recommended_pages',
        ] as $field) {
            if (\array_key_exists($field, $scope)) {
                $patch[$field] = $scope[$field];
            }
        }

        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale', 'plan_locale'] as $manualField) {
            if (!\array_key_exists($manualField, $patch)) {
                continue;
            }
            $value = $patch[$manualField];
            $siteProfileManual[$manualField] = \is_scalar($value)
                ? \trim((string)$value) !== ''
                : !empty($value);
        }

        if (\array_key_exists('page_types', $patch) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $patch)) {
            $patch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = !empty($patch['page_types']) ? 1 : 0;
        }
        if ($siteProfileManual !== []) {
            $patch['site_profile_manual'] = $siteProfileManual;
        }

        $this->sessionService->mergeScope($pageBuilderSession->getId(), $adminId, $patch);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolvePlanLocale(array $scope): string
    {
        $planLocale = AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(
            \trim((string)($scope['plan_locale'] ?? ''))
        );
        if ($planLocale !== '') {
            return $planLocale;
        }

        return AiSiteAgentWorkspaceDebugDefaults::normalizeDefaultLocale(
            \trim((string)($scope['default_language'] ?? $scope['default_locale'] ?? ''))
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{
     *   action:string,
     *   rebuild_required:bool,
     *   translation_required:bool,
     *   plan_locale_changed:bool,
     *   page_types_changed:bool
     * }
     */
    private function resolvePlanStartDecision(array $scope): array
    {
        $requestedPlanLocale = (string)$this->resolvePlanLocale($scope);
        $lastGeneratedPlanLocale = \trim((string)($scope['plan_generated_locale'] ?? ''));
        $requestedPageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $lastGeneratedPageTypes = \is_array($scope['plan_generated_page_types'] ?? null) ? $scope['plan_generated_page_types'] : [];
        $hasPlanDraft = !$this->isPlanContentEmpty($scope);
        $planLocaleChanged = $hasPlanDraft
            && $requestedPlanLocale !== ''
            && $lastGeneratedPlanLocale !== ''
            && $requestedPlanLocale !== $lastGeneratedPlanLocale;
        $pageTypesChanged = $hasPlanDraft && !$this->isSamePageTypeSelection($requestedPageTypes, $lastGeneratedPageTypes);
        if ($pageTypesChanged) {
            return [
                'action' => 'rebuild',
                'rebuild_required' => true,
                'translation_required' => false,
                'plan_locale_changed' => $planLocaleChanged,
                'page_types_changed' => true,
            ];
        }
        if ($planLocaleChanged) {
            return [
                'action' => 'translate',
                'rebuild_required' => false,
                'translation_required' => true,
                'plan_locale_changed' => true,
                'page_types_changed' => false,
            ];
        }
        return [
            'action' => $hasPlanDraft ? 'reuse' : 'generate',
            'rebuild_required' => false,
            'translation_required' => false,
            'plan_locale_changed' => false,
            'page_types_changed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isPlanContentEmpty(array $scope): bool
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if ($planJson !== []) {
            return false;
        }
        $planMarkdown = \trim((string)($scope['plan_markdown'] ?? ''));
        if ($planMarkdown !== '') {
            return false;
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        $values = [];
        if (\is_array($raw)) {
            $values = $raw;
        } elseif (\is_string($raw) && $raw !== '') {
            $decoded = \json_decode($raw, true);
            $values = \is_array($decoded) ? $decoded : (\preg_split('/[\r\n,;]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $normalized = [];
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $item = \trim((string)$value);
            if ($item === '' || \in_array($item, $normalized, true)) {
                continue;
            }
            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param list<string>|array<int, mixed> $left
     * @param list<string>|array<int, mixed> $right
     */
    private function isSamePageTypeSelection(array $left, array $right): bool
    {
        $normalize = static function (array $value): array {
            $result = [];
            foreach ($value as $item) {
                $item = \trim((string)$item);
                if ($item === '') {
                    continue;
                }
                $result[$item] = true;
            }
            $types = \array_keys($result);
            \sort($types);
            return $types;
        };

        return $normalize($left) === $normalize($right);
    }

    /**
     * @param array<string, mixed> $activeOperation
     * @param list<string> $requestedPageTypes
     */
    private function shouldRestartPlanOperationForScopeChange(array $activeOperation, string $requestedPlanLocale, array $requestedPageTypes): bool
    {
        $details = \is_array($activeOperation['details'] ?? null) ? $activeOperation['details'] : [];
        $runningPlanLocale = \trim((string)($details['plan_locale'] ?? ''));
        $runningPageTypes = \is_array($details['page_types'] ?? null) ? $details['page_types'] : [];
        if ($runningPlanLocale === '' && $runningPageTypes === []) {
            return true;
        }
        if ($runningPlanLocale !== '' && $requestedPlanLocale !== '' && $runningPlanLocale !== $requestedPlanLocale) {
            return true;
        }
        return !$this->isSamePageTypeSelection($requestedPageTypes, $runningPageTypes);
    }

    private function cancelActivePlanOperationForScopeChange(AiSiteAgentSession $session, int $adminId): void
    {
        $this->updateActiveOperation(
            $session,
            $adminId,
            [
                'status' => 'cancelled',
                'message' => (string)__('检测到方案语言或页面类型变更，已取消旧任务并准备重新生成'),
            ],
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
        );
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            'plan',
            'operation_cancelled',
            (string)__('检测到方案配置变更（语言/页面类型），已取消旧任务并重新排队生成'),
            ['operation' => 'plan', 'details' => ['reason' => 'plan_scope_changed']]
        );
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function getStageOptions(): array
    {
        return [
            ['value' => AiSiteAgentSession::STAGE_PLAN, 'label' => (string)__('计划阶段')],
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
        $getValue = $this->request->getGet($key, null);
        if ($getValue !== null) {
            return $getValue;
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

    /**
     * @param array<string, mixed> $context
     */
    private function logStreamSse(string $message, array $context = [], string $level = 'info'): void
    {
        if (!(\defined('DEV') && DEV)) {
            return;
        }

        $message = '[AiSiteAgent::stream-sse] ' . $message;
        match (\strtolower($level)) {
            'debug' => WlsLogger::debug_($message, $context),
            'warn', 'warning' => WlsLogger::warning_($message, $context),
            'error' => WlsLogger::error_($message, $context),
            default => WlsLogger::info_($message, $context),
        };
    }

    private function logHotPathStage(string $stage, float $startedAt, array $context = []): void
    {
        if (!(bool)\Weline\Framework\App\Env::get('wls.debug.hot_path_logs', false)) {
            return;
        }

        $context['elapsed_ms'] = \round((\microtime(true) - $startedAt) * 1000, 2);
        WlsLogger::warning_('[AiSiteAgent::trace] ' . $stage, $context);
    }

    /**
     * FPM 场景下，SSE 长连接需要尽快释放 session 文件锁，
     * 避免同一用户的并发请求（含 EventSource 重连）互相阻塞。
     */
    private function releasePhpSessionLockForSse(): void
    {
        if (\function_exists('session_status') && \session_status() === \PHP_SESSION_ACTIVE) {
            @\session_write_close();
        }
    }

}
