<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueDispatchGuardService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentMutateTaskPlanTaskOperationPorts;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentMutateTaskPlanTaskOperationService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverStreamService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentRegeneratePageOperationPorts;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentRegeneratePageOperationService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentTaskPlanQueueRecoveryPorts;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentTaskPlanQueueRecoveryService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceEntryNoticeService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentStreamCodecService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceBridgeService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspacePreviewService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceStateHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWebsitesMirrorService;
use GuoLaiRen\PageBuilder\Service\AiSiteDraftWebsiteService;
use GuoLaiRen\PageBuilder\Service\AiSiteMaterializationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePublishService;
use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteQualityGateService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueSnapshotService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceDebugDefaults;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlocksBuildService;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSiteTaskPlanSseService;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemePlanService;
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

#[Acl('GuoLaiRen_PageBuilder::ai_site_agent', 'AI 建站工作台', 'mdi-robot-outline', 'PageBuilder AI 建站会话与流水线', 'Weline_Backend::page_builder_group')]
class AiSiteAgent extends BaseController
{
    private const REQUEST_CTX_AI_CHUNK_FORWARDER = 'pagebuilder.ai.chunk.forwarder';
    private const REQUEST_CTX_QUEUE_DISPATCHER = 'pagebuilder.ai.queue.dispatcher';
    private const REQUEST_CTX_QUEUE_CREATED_HOOK = 'pagebuilder.ai.queue.created';
    private const REQUEST_CTX_OBSERVER_QUEUE_SETTLE_DELAY_MS = 'pagebuilder.ai.observer.queue_settle_delay_ms';
    private const PARAMS_PUBLIC_ID = ['public_id'];
    private const PARAMS_OPERATION_SSE = ['public_id', 'execution_token'];
    private const PARAMS_REGENERATE = ['public_id', 'page_type'];
    private const PARAMS_REFINE_PLAN_PAGE = ['public_id', 'page_type', 'instruction'];
    private const PARAMS_REFINE_COMPONENT = ['public_id', 'page_type', 'component_code', 'instruction'];
    private const PARAMS_UPDATE_BLOCK = ['public_id', 'page_type', 'block_id', 'block_config'];
    private const PARAMS_MUTATE_PLAN_BLOCK = ['public_id', 'page_type', 'action', 'block_key', 'block_config'];
    private const PARAMS_MUTATE_TASK_PLAN_TASK = ['public_id', 'bucket', 'page_type', 'action', 'task_key', 'task_config'];
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
    private const OBSERVER_QUEUE_SETTLE_DELAY_MS = 3000;
    private const BUILD_TASK_MAX_GENERATION_ATTEMPTS = 3;

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
    private readonly AiSiteAgentStreamCodecService $streamCodecService;
    private readonly AiSiteAgentWorkspaceBridgeService $workspaceBridgeService;
    private readonly AiSiteAgentWorkspacePreviewService $workspacePreviewService;
    private readonly AiSiteQueueSnapshotService $queueSnapshotService;
    private readonly AiSiteAgentQueueObserverHelperService $queueObserverHelperService;
    private readonly AiSiteAgentWebsitesMirrorService $websitesMirrorService;
    private readonly AiSiteAgentWorkspaceStateHelperService $workspaceStateHelperService;
    private readonly AiSiteAgentQueueObserverStreamService $queueObserverStreamService;
    private readonly AiSiteAgentQueueDispatchGuardService $queueDispatchGuardService;
    private readonly AiSiteAgentRegeneratePageOperationService $regeneratePageOperationService;
    private readonly AiSiteAgentMutateTaskPlanTaskOperationService $mutateTaskPlanTaskOperationService;
    private readonly AiSiteAgentTaskPlanQueueRecoveryService $taskPlanQueueRecoveryService;
    private readonly AiSiteAgentWorkspaceEntryNoticeService $workspaceEntryNoticeService;
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
        ?AiSiteAgentStreamCodecService $streamCodecService = null,
        ?AiSiteAgentWorkspaceBridgeService $workspaceBridgeService = null,
        ?AiSiteAgentWorkspacePreviewService $workspacePreviewService = null,
        ?AiSiteQueueSnapshotService $queueSnapshotService = null,
        ?AiSiteAgentQueueObserverHelperService $queueObserverHelperService = null,
        ?Url $url = null,
        ?AiSiteAgentWebsitesMirrorService $websitesMirrorService = null,
        ?AiSiteAgentWorkspaceStateHelperService $workspaceStateHelperService = null,
        ?AiSiteAgentQueueObserverStreamService $queueObserverStreamService = null,
        ?AiSiteAgentQueueDispatchGuardService $queueDispatchGuardService = null,
        ?AiSiteAgentRegeneratePageOperationService $regeneratePageOperationService = null,
        ?AiSiteAgentMutateTaskPlanTaskOperationService $mutateTaskPlanTaskOperationService = null,
        ?AiSiteAgentTaskPlanQueueRecoveryService $taskPlanQueueRecoveryService = null,
        ?AiSiteAgentWorkspaceEntryNoticeService $workspaceEntryNoticeService = null,
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
        $this->streamCodecService = $streamCodecService
            ?? ObjectManager::getInstance(AiSiteAgentStreamCodecService::class);
        $this->workspaceBridgeService = $workspaceBridgeService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspaceBridgeService::class);
        $this->workspacePreviewService = $workspacePreviewService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspacePreviewService::class);
        $this->queueSnapshotService = $queueSnapshotService
            ?? ObjectManager::getInstance(AiSiteQueueSnapshotService::class);
        $this->queueObserverHelperService = $queueObserverHelperService
            ?? ObjectManager::getInstance(AiSiteAgentQueueObserverHelperService::class);
        $this->url = $url ?? ObjectManager::getInstance(Url::class);
        $this->websitesMirrorService = $websitesMirrorService
            ?? ObjectManager::getInstance(AiSiteAgentWebsitesMirrorService::class);
        $this->workspaceStateHelperService = $workspaceStateHelperService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspaceStateHelperService::class);
        $this->queueObserverStreamService = $queueObserverStreamService
            ?? ObjectManager::getInstance(AiSiteAgentQueueObserverStreamService::class);
        $this->queueDispatchGuardService = $queueDispatchGuardService
            ?? ObjectManager::getInstance(AiSiteAgentQueueDispatchGuardService::class);
        $this->regeneratePageOperationService = $regeneratePageOperationService
            ?? ObjectManager::getInstance(AiSiteAgentRegeneratePageOperationService::class);
        $this->mutateTaskPlanTaskOperationService = $mutateTaskPlanTaskOperationService
            ?? ObjectManager::getInstance(AiSiteAgentMutateTaskPlanTaskOperationService::class);
        $this->taskPlanQueueRecoveryService = $taskPlanQueueRecoveryService
            ?? ObjectManager::getInstance(AiSiteAgentTaskPlanQueueRecoveryService::class);
        $this->workspaceEntryNoticeService = $workspaceEntryNoticeService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspaceEntryNoticeService::class);
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

        $linkedWebsitesSession = $this->workspaceBridgeService->ensureLinkedWebsitesMirrorSession($session, $adminId);
        if ($linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession) {
            $this->workspaceBridgeService->syncPageBuilderScopeFromLinkedWebsitesSession($session, $linkedWebsitesSession, $adminId);
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
            ? $this->workspaceBridgeService->buildDomainPurchaseState($linkedWebsitesSession)
            : [];
        $registrarAccounts = $this->workspaceBridgeService->buildRegistrarAccountOptions();
        $registrarSelection = $this->workspaceBridgeService->buildWorkspaceRegistrarSelection(
            $linkedWebsitesScope,
            \is_array($viewState['scope'] ?? null) ? $viewState['scope'] : [],
            $registrarAccounts,
            \defined('DEV') && DEV
        );
        $recommendedDomainList = $registrarSelection['recommended_domain_list'];
        $recommendedRegistrarLabel = $registrarSelection['recommended_registrar_label'];
        $preferredRegistrarAccountId = $registrarSelection['preferred_registrar_account_id'];
        if ($preferredRegistrarAccountId <= 0) { $preferredRegistrarAccountId = 0; }
            // 线上默认不预选账号，由用户主动选择。
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
        $this->assign('mutate_task_plan_task_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-mutate-task-plan-task'));
        $this->assign('task_plan_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-task-plan-sse'));
        $this->assign('resume_build_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-resume-build'));
        $this->assign('run_virtual_theme_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_build_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_regenerate_page_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-regenerate-page'));
        $this->assign('start_refine_component_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-refine-component'));
        $this->assign('start_block_refine_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/block-refine-sse'));
        $this->assign('start_block_regenerate_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/block-regenerate-sse'));
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
                $scope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
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

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', 'Mutate stage-2 tasks', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postMutateTaskPlanTask(): string
    {
        return $this->handleMutateTaskPlanTask();
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
            'data' => $this->buildWorkspaceOperationPayload(
                $this->buildWorkspaceState($fresh, $adminId, 24, true),
                'stage'
            ),
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
    public function getBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }
    public function getPostBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }

    public function postBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }
    public function getBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }
    public function getPostBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }

    public function postUpdateBlockConfig(): string { return $this->handleUpdateBlockConfig(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '启动发布流程', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPublish(): string { return $this->handleStartPublish(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '切换当前预览页', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSwitchPreviewPage(): string { return $this->handleSwitchPreviewPageCompact(); }

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
        if (isset($scopePatch['page_types'])) {
            $scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        $scope = $this->scopeCompatibilityService->normalizeScope(\array_replace(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN),
            $scopePatch
        ));
        if (\trim((string)($scope['target_domain'] ?? '')) === '') {
            return $this->jsonError(
                'TARGET_DOMAIN_REQUIRED',
                (string)__('请先填写目标域名，再确认&更新方案。'),
                ['target_domain']
            );
        }
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
        $this->sessionService->mergeScope($session->getId(), $adminId, $persistPatch);
        $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $currentScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
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
                : (string)__('检测到建站需求已变更。是否立即重建阶段一方案？');
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
                'plan_source_changed' => !empty($planStartDecision['source_signature_changed']),
                'data' => $this->buildWorkspaceOperationPayload(
                    $this->buildWorkspaceState($session, $adminId, 24, true),
                    'plan'
                ),
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
            && $this->shouldRestartPlanOperationForScopeChange(
                $activeOperation,
                (string)$scope['plan_locale'],
                $requestedPageTypes,
                $this->buildPlanSourceSignature($currentScope)
            )
        ) {
            $this->cancelActivePlanOperationForScopeChange($session, $adminId);
            $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $currentScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
            );
            $planStartDecision = $this->resolvePlanStartDecision($currentScope);
        }
        if ((string)($planStartDecision['action'] ?? '') === 'reuse' && $hasPlanDraft && $requestedPromptMode !== 'rebuild') {
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
                'plan_source_changed' => false,
                'data' => $this->buildWorkspaceOperationPayload(
                    $this->buildWorkspaceState($session, $adminId, 24, true),
                    'plan'
                ),
            ]);
        }
        $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $effectivePlanPromptMode = \in_array($requestedPromptMode, ['refine', 'rebuild'], true)
            ? $requestedPromptMode
            : (string)($planStartDecision['action'] ?? 'rebuild');
        $planRebuildResetPatch = $effectivePlanPromptMode === 'rebuild'
            ? $this->buildStageOnePlanRegenerationResetScopePatch()
            : [];
        $result = $this->startOperation($session, $adminId, 'plan', $stage, \array_replace($planRebuildResetPatch, [
            'plan_locale' => (string)$scope['plan_locale'],
            'plan_confirmed' => 0,
            '_plan_sse_request' => [
                'prompt_mode' => $effectivePlanPromptMode,
                'instruction' => $requestedInstruction,
                'target_scope' => $requestedTargetScope,
                'round' => $requestedRound,
                'plan_locale' => (string)$scope['plan_locale'],
            ],
        ]));
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
                    'plan_source_changed' => !empty($planStartDecision['source_signature_changed']),
                    'data' => $this->buildWorkspaceOperationPayload(
                        $this->buildWorkspaceState($session, $adminId, 24, true),
                        'plan'
                    ),
                ]);
            }
            return $this->fetchJson([
                'success' => false,
                'message' => (string)($result['message'] ?? __('当前无法启动阶段一方案生成')),
                'operation' => (string)($result['operation'] ?? ''),
            ]);
        }

        $responseState = \is_array($result['data'] ?? null)
            ? $result['data']
            : $this->buildWorkspaceOperationPayload(
                $this->buildWorkspaceState($session, $adminId, 24, true),
                'plan'
            );
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
            'plan_source_changed' => !empty($planStartDecision['source_signature_changed']),
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
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
            'data' => $this->buildWorkspaceConfirmPayload(
                $this->buildWorkspaceState($fresh, $adminId, 80, true),
                'plan'
            ),
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
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

        if ($effectivePromptMode === 'rebuild') {
            $planRebuildResetPatch = \array_replace(
                $this->buildStageOnePlanRegenerationResetScopePatch(),
                ['plan_locale' => $requestedPlanLocale]
            );
            $scope = \array_replace($scope, $planRebuildResetPatch);
            $this->sessionService->mergeScope($session->getId(), $adminId, $planRebuildResetPatch);
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
            $rawChunkInfoBuffer = '';
            $emitAiChunkProgress = $this->createPlanSseAiChunkEmitter(
                $sse,
                $effectivePromptMode,
                $rawChunkInfoBuffer
            );
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
            $this->flushPlanSseRawChunkBuffer($sse, $effectivePromptMode, $rawChunkInfoBuffer);
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
                'plan_workbench' => \is_array($artifacts['plan_workbench'] ?? null) ? $artifacts['plan_workbench'] : [],
                'plan_locale' => $this->resolvePlanLocale($scope),
                'plan_ai_generated' => (int)($artifacts['ai_generated'] ?? 0),
                'plan_generated_at' => \date('Y-m-d H:i:s'),
                // 微调在已有确认状态下默认保持已确认，避免仅做文案/区块增补后打断后续阶段。
                'plan_confirmed' => $effectivePromptMode === 'rebuild' ? 0 : (int)($scope['plan_confirmed'] ?? 0),
                'plan_last_prompt_mode' => $effectivePromptMode,
                'plan_last_target_scope' => $targetScope,
                'plan_last_round' => $round,
                'plan_generated_locale' => $requestedPlanLocale,
                'plan_generated_page_types' => \array_values(\array_map('strval', $requestedPageTypes)),
                'plan_generated_source_signature' => $this->buildPlanSourceSignature($scope),
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
            foreach ($this->streamCodecService->chunkStringForSse($markdown, 220) as $chunk) {
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

    /**
     * @param-out string $rawChunkInfoBuffer
     */
    private function createPlanSseAiChunkEmitter(SseWriter $sse, string $effectivePromptMode, string &$rawChunkInfoBuffer): \Closure
    {
        $aiChunkCount = 0;
        return function (string $chunk) use (&$aiChunkCount, &$rawChunkInfoBuffer, $sse, $effectivePromptMode): void {
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
    }

    /**
     * @param-out string $rawChunkInfoBuffer
     */
    private function flushPlanSseRawChunkBuffer(SseWriter $sse, string $effectivePromptMode, string &$rawChunkInfoBuffer): void
    {
        if ($rawChunkInfoBuffer === '' || !$sse->isAlive()) {
            return;
        }
        $sse->sendEvent('info', [
            'message' => $rawChunkInfoBuffer,
            'prompt_mode' => $effectivePromptMode,
            'stream_stage' => 'ai_raw_chunk',
            'chunk' => $rawChunkInfoBuffer,
        ]);
        $rawChunkInfoBuffer = '';
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $scope = $this->normalizePlanConfirmationForTaskPlan($session, $adminId, $scope);
        if (!$this->isPlanConfirmedForTaskPlan($scope)) {
            return $this->jsonError('PLAN_REQUIRED_BEFORE_TASK_PLAN', (string)__('请先确认第一阶段方案，再生成第二阶段任务方案'), ['public_id', 'plan_confirmed']);
        }
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        if ($executionBlueprint === []) {
            return $this->jsonError('EXECUTION_BLUEPRINT_REQUIRED', (string)__('缺少已确认执行蓝图，请重新生成并确认第一阶段方案'), ['public_id', 'execution_blueprint']);
        }

        $requestedPromptMode = \trim((string)$this->getRequestBodyValue('prompt_mode', ''));
        $effectivePromptMode = \in_array($requestedPromptMode, ['detect_bootstrap_task_plan', 'refine_task_plan', 'rebuild_task_plan'], true)
            ? $requestedPromptMode
            : 'detect_bootstrap_task_plan';
        $requestedInstruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        $requestedTargetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        $requestedRound = \max(1, (int)$this->getRequestBodyValue('round', 1));
        $regenerationResetPatch = $effectivePromptMode === 'rebuild_task_plan'
            ? $this->buildTaskPlanRegenerationResetScopePatch()
            : [];
        if ($regenerationResetPatch !== []) {
            $scope = \array_replace($scope, $regenerationResetPatch);
        }

        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
            (string)($scope['workspace_track'] ?? '')
        );
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $scopePatch = \array_replace($regenerationResetPatch, [
            'build_blueprint' => $buildBlueprint,
            'build_tasks' => \is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [],
            '_task_plan_sse_request' => [
                'prompt_mode' => $effectivePromptMode,
                'instruction' => $requestedInstruction,
                'target_scope' => $requestedTargetScope,
                'round' => $requestedRound,
            ],
        ]);
        $result = $this->startOperation(
            $session,
            $adminId,
            'task_plan',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            $scopePatch,
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
                    'data' => $this->buildWorkspaceOperationPayload(
                        $this->buildWorkspaceState($session, $adminId, 24, true),
                        'task_plan'
                    ),
                ]);
            }

            return $this->fetchJson([
                'success' => false,
                'message' => (string)($result['message'] ?? __('当前无法启动第二阶段任务方案生成')),
                'operation' => (string)($result['operation'] ?? ''),
            ]);
        }

        $responseState = \is_array($result['data'] ?? null)
            ? $result['data']
            : $this->buildWorkspaceOperationPayload(
                $this->buildWorkspaceState($session, $adminId, 24, true),
                'task_plan'
            );
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

    /**
     * @return array<string, mixed>
     */
    private function buildStageOnePlanRegenerationResetScopePatch(): array
    {
        $taskPlanResetPatch = $this->buildTaskPlanRegenerationResetScopePatch();
        $taskPlanResetPatch['_task_plan_rebuild_in_progress'] = 0;

        return \array_replace($taskPlanResetPatch, [
            'execution_blueprint' => [],
            'execution_blueprint_confirmed_at' => '',
            'execution_blueprint_confirmed_signature' => '',
            'execution_blueprint_draft' => [],
            'plan_confirmed' => 0,
            'plan_confirmed_at' => '',
            'plan_generated_at' => '',
            'plan_generated_locale' => '',
            'plan_generated_page_types' => [],
            'plan_generated_source_signature' => '',
            'plan_ai_generated' => 0,
            'plan_last_prompt_mode' => 'rebuild',
            'plan_last_target_scope' => '',
            'plan_last_round' => 1,
            'plan_json' => [],
            'plan_markdown' => '',
            'plan_structured' => [],
            'plan_workbench' => [],
            'plan_rebuild_summary' => [],
            'plan_change_scope_report' => [],
            'build_summary' => [],
            'build_task_summary' => [],
            '_plan_sse_request' => [],
            '_task_plan_sse_request' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTaskPlanRegenerationResetScopePatch(): array
    {
        return [
            'virtual_theme_plan' => [
                'draft' => [],
                'draft_markdown' => '',
                'draft_generated_at' => '',
                'confirmed' => [],
                'confirmed_markdown' => '',
                'confirmed_at' => '',
                'confirmed_signature' => '',
                'plan_signature' => '',
                'last_prompt_mode' => 'rebuild_task_plan',
                'last_target_scope' => '',
                'last_round' => 1,
            ],
            'task_plan_markdown' => '',
            'task_plan_generated_at' => '',
            'task_plan_structured' => [],
            'task_plan_directory_tree' => [],
            'task_plan_summary' => [],
            'task_plan_confirmed' => 0,
            'task_plan_confirmed_at' => '',
            'task_plan_rebuild_summary' => [],
            'task_plan_change_scope_report' => [],
            'task_plan_generation_progress' => [],
            'task_plan_generation_summary' => [],
            'task_plan_generation_last_error' => '',
            'build_blueprint' => [],
            'build_tasks' => [],
            '_task_plan_rebuild_in_progress' => 1,
        ];
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $scope = $this->normalizePlanConfirmationForTaskPlan($session, $adminId, $scope);
        $taskPlanForConfirm = $this->resolveStageTwoTaskPlanForConfirmation($scope);
        $draftTaskPlan = \is_array($taskPlanForConfirm['structured'] ?? null) ? $taskPlanForConfirm['structured'] : [];
        $draftMarkdown = (string)($taskPlanForConfirm['markdown'] ?? '');
        if ($draftTaskPlan === []) {
            return $this->jsonError('TASK_PLAN_NOT_READY', (string)__('尚未生成第二阶段任务方案，请先生成后再确认'), ['public_id']);
        }
        if (!$this->isPlanConfirmedForTaskPlan($scope)) {
            return $this->jsonError('PLAN_REQUIRED_BEFORE_TASK_PLAN_CONFIRM', (string)__('请先确认阶段一方案，再确认第二阶段任务方案'), ['public_id', 'plan_confirmed']);
        }

        $signature = (string)($taskPlanForConfirm['signature'] ?? '');
        $generatedAt = (string)($taskPlanForConfirm['generated_at'] ?? \date('Y-m-d H:i:s'));
        $confirmedAt = (string)($taskPlanForConfirm['confirmed_at'] ?? \date('Y-m-d H:i:s'));
        $scopePatch = [
            'task_plan_structured' => $draftTaskPlan,
            'task_plan_markdown' => $draftMarkdown,
            'task_plan_generated_at' => $generatedAt,
            'virtual_theme_plan' => [
                'draft' => $draftTaskPlan,
                'draft_markdown' => $draftMarkdown,
                'draft_generated_at' => $generatedAt,
                'confirmed' => $draftTaskPlan,
                'confirmed_markdown' => $draftMarkdown,
                'confirmed_at' => $confirmedAt,
                'confirmed_signature' => $signature,
                'plan_signature' => $signature,
            ],
            'task_plan_confirmed' => 0,
            'build_summary' => \array_replace(
                \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
                ['task_plan_signature' => $signature]
            ),
        ];
        $scopePatch['task_plan_confirmed'] = 1;
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
        $scopePatch['build_summary'] = \array_replace(
            \is_array($scopePatch['build_summary'] ?? null) ? $scopePatch['build_summary'] : [],
            ['task_summary' => $this->buildTaskService->summarize($confirmedScope)]
        );
        $scopePatch = $this->compactConfirmedTaskPlanScope($scopePatch);
        try {
            $saved = $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
            if (!$saved) {
                throw new \RuntimeException('Second-stage task plan confirm persist failed: mergeScope returned false.');
            }
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $freshScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
            $freshVirtualThemePlan = \is_array($freshScope['virtual_theme_plan'] ?? null) ? $freshScope['virtual_theme_plan'] : [];
            $confirmed = \is_array($freshVirtualThemePlan['confirmed'] ?? null) ? $freshVirtualThemePlan['confirmed'] : [];
            $confirmedMarkdown = \trim((string)($freshVirtualThemePlan['confirmed_markdown'] ?? ''));
            if ($confirmed === [] || $confirmedMarkdown === '') {
                throw new \RuntimeException(
                    'Second-stage task plan confirm persist verification failed'
                    . ' confirmed=' . ($confirmed === [] ? 'empty' : 'ok')
                    . ' confirmed_markdown=' . ($confirmedMarkdown === '' ? 'empty' : 'ok')
                );
            }
        } catch (\Throwable $throwable) {
            return $this->jsonError('TASK_PLAN_CONFIRM_PERSIST_FAILED', $throwable->getMessage(), ['public_id']);
        }
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
            'task_plan_confirmed',
            (string)__('第二阶段任务方案已确认，可以开始执行构建'),
            [
                'operation' => 'task_plan_confirm',
                'details' => ['task_plan_signature' => $signature],
            ]
        );

        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('第二阶段任务方案已确认'),
            'data' => $this->buildWorkspaceConfirmPayload(
                $this->buildWorkspaceState($fresh, $adminId, 80, true),
                'task_plan'
            ),
        ]);
    }

    /**
     * @param array<string, mixed> $scopePatch
     */
    private function persistTaskPlanDraftOrThrow(
        AiSiteAgentSession $session,
        int $adminId,
        array $scopePatch,
        string $source
    ): AiSiteAgentSession {
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
        if (!$saved) {
            throw new \RuntimeException('Second-stage task plan draft persist failed: mergeScope returned false.');
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $draft = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
        $draftMarkdown = \trim((string)($virtualThemePlan['draft_markdown'] ?? ''));
        $taskPlanStructured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
        $taskPlanMarkdown = \trim((string)($scope['task_plan_markdown'] ?? ''));

        if ($draft !== [] && $draftMarkdown !== '') {
            return $fresh;
        }

        if ($draft === [] && $taskPlanStructured !== []) {
            $draft = $taskPlanStructured;
        }
        if ($draftMarkdown === '' && $taskPlanMarkdown !== '') {
            $draftMarkdown = $taskPlanMarkdown;
        }
        if ($taskPlanMarkdown === '' && $draftMarkdown !== '') {
            $taskPlanMarkdown = $draftMarkdown;
        }

        if ($draft !== [] && $draftMarkdown !== '') {
            $virtualThemePlan['draft'] = $draft;
            $virtualThemePlan['draft_markdown'] = $draftMarkdown;
            if (\trim((string)($virtualThemePlan['draft_generated_at'] ?? '')) === '') {
                $virtualThemePlan['draft_generated_at'] = \date('Y-m-d H:i:s');
            }
            $repairPatch = [
                'virtual_theme_plan' => $virtualThemePlan,
                'task_plan_markdown' => $taskPlanMarkdown,
            ];
            if ($taskPlanStructured !== []) {
                $repairPatch['task_plan_structured'] = $taskPlanStructured;
            }
            $repairSaved = $this->sessionService->mergeScope($fresh->getId(), $adminId, $repairPatch);
            if ($repairSaved) {
                $fresh = $this->sessionService->loadById($fresh->getId(), $adminId) ?? $fresh;
                $scope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
                $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
                $draft = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
                $draftMarkdown = \trim((string)($virtualThemePlan['draft_markdown'] ?? ''));
                $taskPlanStructured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
                $taskPlanMarkdown = \trim((string)($scope['task_plan_markdown'] ?? ''));
                if ($draft !== [] && $draftMarkdown !== '') {
                    return $fresh;
                }
            }
        }

        throw new \RuntimeException(
            'Second-stage task plan draft persist verification failed'
            . ' source=' . $source
            . ' draft=' . ($draft === [] ? 'empty' : 'ok')
            . ' draft_markdown=' . ($draftMarkdown === '' ? 'empty' : 'ok')
            . ' task_plan_structured=' . ($taskPlanStructured === [] ? 'empty' : 'ok')
            . ' task_plan_markdown=' . ($taskPlanMarkdown === '' ? 'empty' : 'ok')
        );
    }

    private function persistExistingTaskPlanDraftOrThrow(
        AiSiteAgentSession $session,
        int $adminId,
        string $source
    ): AiSiteAgentSession {
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $draft = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
        $taskPlanStructured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
        if ($draft === [] && $taskPlanStructured !== []) {
            $draft = $taskPlanStructured;
        }

        $draftMarkdown = \trim((string)($virtualThemePlan['draft_markdown'] ?? ''));
        $taskPlanMarkdown = \trim((string)($scope['task_plan_markdown'] ?? ''));
        if ($draftMarkdown === '' && $taskPlanMarkdown !== '') {
            $draftMarkdown = $taskPlanMarkdown;
        }
        if ($taskPlanMarkdown === '' && $draftMarkdown !== '') {
            $taskPlanMarkdown = $draftMarkdown;
        }

        if ($draft === [] || $draftMarkdown === '') {
            throw new \RuntimeException(
                'Second-stage task plan draft is not ready to save'
                . ' source=' . $source
                . ' draft=' . ($draft === [] ? 'empty' : 'ok')
                . ' draft_markdown=' . ($draftMarkdown === '' ? 'empty' : 'ok')
                . ' task_plan_structured=' . ($taskPlanStructured === [] ? 'empty' : 'ok')
                . ' task_plan_markdown=' . ($taskPlanMarkdown === '' ? 'empty' : 'ok')
            );
        }

        $virtualThemePlan['draft'] = $draft;
        $virtualThemePlan['draft_markdown'] = $draftMarkdown;
        if (\trim((string)($virtualThemePlan['draft_generated_at'] ?? '')) === '') {
            $virtualThemePlan['draft_generated_at'] = \date('Y-m-d H:i:s');
        }

        return $this->persistTaskPlanDraftOrThrow($session, $adminId, [
            'virtual_theme_plan' => $virtualThemePlan,
            'task_plan_markdown' => $taskPlanMarkdown,
        ], $source);
    }

    private function persistExistingPlanDraftOrThrow(
        AiSiteAgentSession $session,
        int $adminId,
        string $source
    ): AiSiteAgentSession {
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planStructured = \is_array($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        if ($planStructured === [] && $planJson !== []) {
            $planStructured = $planJson;
        }
        if ($planJson === [] && $planStructured !== []) {
            $planJson = $planStructured;
        }
        $planMarkdown = \trim((string)($scope['plan_markdown'] ?? ''));
        $executionBlueprint = \is_array($scope['execution_blueprint_draft'] ?? null)
            ? $scope['execution_blueprint_draft']
            : (\is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : []);

        if ($planMarkdown === '' && $planJson === [] && $planStructured === [] && $executionBlueprint === []) {
            throw new \RuntimeException('Stage-one plan draft is not ready to save source=' . $source);
        }

        $patch = [
            'plan_json' => $planJson,
            'plan_structured' => $planStructured,
            'plan_markdown' => $planMarkdown,
            'plan_generated_at' => (string)($scope['plan_generated_at'] ?? \date('Y-m-d H:i:s')),
        ];
        if ($executionBlueprint !== []) {
            $patch['execution_blueprint_draft'] = $executionBlueprint;
        }

        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, $patch);
        if (!$saved) {
            throw new \RuntimeException('Stage-one plan draft persist failed: mergeScope returned false.');
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $freshScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
        $freshPlanJson = \is_array($freshScope['plan_json'] ?? null) ? $freshScope['plan_json'] : [];
        $freshPlanStructured = \is_array($freshScope['plan_structured'] ?? null) ? $freshScope['plan_structured'] : [];
        $freshPlanMarkdown = \trim((string)($freshScope['plan_markdown'] ?? ''));
        $freshExecutionBlueprint = \is_array($freshScope['execution_blueprint_draft'] ?? null)
            ? $freshScope['execution_blueprint_draft']
            : (\is_array($freshScope['execution_blueprint'] ?? null) ? $freshScope['execution_blueprint'] : []);
        if ($freshPlanMarkdown !== '' || $freshPlanJson !== [] || $freshPlanStructured !== [] || $freshExecutionBlueprint !== []) {
            return $fresh;
        }

        throw new \RuntimeException('Stage-one plan draft persist verification failed source=' . $source);
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $scope = $this->normalizePlanConfirmationForTaskPlan($session, $adminId, $scope);
        if (!$this->isPlanConfirmedForTaskPlan($scope)) {
            $this->sendSseContractError($sse, 'PLAN_REQUIRED_BEFORE_TASK_PLAN', (string)__('请先确认阶段一方案，再继续调整第二阶段任务方案'), ['public_id', 'plan_confirmed'], 409);
            $sse->complete(['success' => false]);
            return;
        }

        if ($promptMode === 'rebuild_task_plan') {
            $regenerationResetPatch = $this->buildTaskPlanRegenerationResetScopePatch();
            $scope = \array_replace($scope, $regenerationResetPatch);
            $this->sessionService->mergeScope($session->getId(), $adminId, $regenerationResetPatch);
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
                $queueMode = $sse instanceof QueueDbWriter;
                if ($queueMode) {
                    // Queue mode persists structured batch progress; avoid filling queue.result with raw AI JSON tokens.
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
            $progressCallback = $this->buildTaskPlanGenerationProgressCallback($sse, $session, $adminId, $promptMode);
            $result = $promptMode === 'rebuild_task_plan'
                ? $this->virtualThemePlanService->rebuildDraftTaskPlan($scope, $buildBlueprint, [
                    'instruction' => $instruction,
                    'round' => $round,
                ], null, $taskPlanStreamHeartbeat, $progressCallback)
                : $this->virtualThemePlanService->refineDraftTaskPlan($scope, $buildBlueprint, $draftPlan, [
                    'instruction' => $instruction,
                    'target_scope' => $targetScope,
                    'round' => $round,
                ], null, $taskPlanStreamHeartbeat, $progressCallback);

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
                foreach ($this->streamCodecService->chunkStringForSse($markdown, 220) as $mdChunk) {
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
                '_task_plan_rebuild_in_progress' => 0,
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
            $fresh = $this->persistTaskPlanDraftOrThrow($session, $adminId, $scopePatch, $promptMode);

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
            if ($promptMode === 'rebuild_task_plan') {
                $this->sessionService->mergeScope($session->getId(), $adminId, [
                    '_task_plan_rebuild_in_progress' => 0,
                    '_task_plan_sse_request' => [],
                    'task_plan_generation_last_error' => $throwable->getMessage(),
                ]);
            }
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
                $taskPlanStreamHeartbeat,
                $this->buildTaskPlanGenerationProgressCallback($sse, $session, $adminId, $promptMode)
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
                foreach ($this->streamCodecService->chunkStringForSse($markdown, 220) as $chunk) {
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
                '_task_plan_rebuild_in_progress' => 0,
            ];
            $sse->sendEvent('progress', [
                'message' => (string)__('正在持久化任务方案草案'),
                'prompt_mode' => $promptMode,
                'progress_percent' => 95,
            ]);
            $fresh = $this->persistTaskPlanDraftOrThrow($session, $adminId, $scopePatch, $promptMode);
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
                ['operation' => 'task_plan', 'status' => 'done', 'message' => (string)__('第二阶段任务方案已生成')],
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
            if ($promptMode === 'rebuild_task_plan') {
                $this->sessionService->mergeScope($session->getId(), $adminId, [
                    '_task_plan_rebuild_in_progress' => 0,
                    '_task_plan_sse_request' => [],
                    'task_plan_generation_last_error' => $throwable->getMessage(),
                ]);
            }
            $this->updateActiveOperation(
                $session,
                $adminId,
                ['operation' => 'task_plan', 'status' => 'error', 'message' => $throwable->getMessage()],
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
        $currentScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $scopePatch = $this->buildTaskService->stripBuildPlanMutationScopePatch($scopePatch, $currentScope);
        $mergedScope = $this->scopeCompatibilityService->normalizeScope(\array_replace($currentScope, $scopePatch));
        $mergedScope = $this->buildTaskService->restoreBuildPlanContract($mergedScope, $currentScope);
        $mergedScope = $this->normalizeTaskPlanConfirmationForBuild($mergedScope);
        if (!$this->isTaskPlanConfirmedForBuild($mergedScope)) {
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
                    'data' => $this->buildWorkspaceOperationPayload(
                        $this->buildWorkspaceState($session, $adminId, 24, true),
                        'build'
                    ),
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

    /**
     * @param array<string, mixed> $scope
     */
    private function isTaskPlanConfirmedForBuild(array $scope): bool
    {
        return (int)($scope['task_plan_confirmed'] ?? 0) === 1
            || $this->buildTaskService->hasConfirmedTaskPlanForBuild($scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isPlanConfirmedForTaskPlan(array $scope): bool
    {
        return $this->scopeCompatibilityService->hasConfirmedStageOnePlanForTaskPlan($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function normalizePlanConfirmationForTaskPlan(
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $normalizedScope = $this->scopeCompatibilityService->normalizeConfirmedPlanFlag($scope);
        $scopePatch = [];
        if ((int)($normalizedScope['plan_confirmed'] ?? 0) !== (int)($scope['plan_confirmed'] ?? 0)) {
            $scopePatch['plan_confirmed'] = (int)($normalizedScope['plan_confirmed'] ?? 0);
        }
        if ((string)($normalizedScope['plan_confirmed_at'] ?? '') !== (string)($scope['plan_confirmed_at'] ?? '')) {
            $scopePatch['plan_confirmed_at'] = (string)($normalizedScope['plan_confirmed_at'] ?? '');
        }
        if ($scopePatch !== []) {
            $this->sessionService->mergeScope((int)$session->getId(), $adminId, $scopePatch);
        }

        return $normalizedScope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function normalizeTaskPlanConfirmationForBuild(array $scope): array
    {
        return $this->buildTaskService->normalizeConfirmedTaskPlanFlag($scope);
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            return $this->jsonError('INVALID_PARAMS', (string)__('所选页面类型不在当前工作区中'), self::PARAMS_REGENERATE);
        }

        $sharedRegion = $this->resolveSharedComponentRegionForComponentCode($pageType, $componentCode);
        $scopePatch = [];
        if ($sharedRegion !== '') {
            $sharedRefinements = \is_array($scope['shared_component_refinements'] ?? null)
                ? $scope['shared_component_refinements']
                : [];
            $sharedRefinements[$sharedRegion] = $instruction;
            $scopePatch['shared_component_refinements'] = $sharedRefinements;
        } else {
            $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
            $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
            $sectionRefinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];
            $sectionRefinements[$componentCode] = $instruction;
            $virtualPage['section_refinements'] = $sectionRefinements;
            $virtualPages[$pageType] = $virtualPage;
            $scopePatch['virtual_pages_by_type'] = $virtualPages;
        }

        $label = $componentLabel !== '' ? $componentLabel : $componentCode;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'component_refine_requested',
            (string)__('已记录区块微调：%{component}', ['component' => $label]),
            [
                'operation' => 'block_regenerate',
                'page_type' => $pageType,
                'details' => [
                    'component_code' => $componentCode,
                    'instruction' => $instruction,
                    'shared_region' => $sharedRegion,
                ],
            ]
        );

        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            'block_regenerate',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            $scopePatch,
            $pageType,
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            [
                'stage_scope' => 'build',
                'action' => 'refine',
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'block_key' => $componentCode,
                'section_code' => $componentCode,
                'component_label' => $label,
                'instruction' => $instruction,
                'shared_region' => $sharedRegion,
                'target_scope' => $sharedRegion !== ''
                    ? ('shared_components.' . $sharedRegion)
                    : ('virtual_pages_by_type.' . $pageType . '.sections.' . $componentCode),
            ]
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            $this->sendSseContractError($sse, 'INVALID_PAGE_TYPE', 'Page type is not in the current workspace', self::PARAMS_REGENERATE, 400);
            $sse->complete(['success' => false]);
            return;
        }

        $stageCode = AiSiteAgentSession::STAGE_VISUAL_EDIT;
        $sharedRegion = $this->resolveSharedComponentRegionForComponentCode($pageType, $componentCode);
        $originalActiveOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $originalWorkspaceStatus = $this->scopeCompatibilityService->normalizeWorkspaceStatus((string)($scope['workspace_status'] ?? ''));
        $preserveOriginalOperation = \in_array(\trim((string)($originalActiveOperation['status'] ?? '')), ['queued', 'running'], true);

        if ($refine) {
            if ($sharedRegion !== '') {
                $sharedRefinements = \is_array($scope['shared_component_refinements'] ?? null)
                    ? $scope['shared_component_refinements']
                    : [];
                $sharedRefinements[$sharedRegion] = $instruction;
                $scope['shared_component_refinements'] = $sharedRefinements;
            } else {
                $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
                $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
                $sectionRefinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];
                $sectionRefinements[$componentCode] = $instruction;
                $virtualPage['section_refinements'] = $sectionRefinements;
                $virtualPages[$pageType] = $virtualPage;
                $scope['virtual_pages_by_type'] = $virtualPages;
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        }

        $operationLabel = $refine ? 'block_refine' : 'block_regenerate';
        $sse->sendEvent('start', [
            'operation' => $operationLabel,
            'page_type' => $pageType,
            'component_code' => $componentCode,
            'shared_region' => $sharedRegion,
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
                $freshScope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
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
                $freshScope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
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

        $sharedRegion = $this->resolveSharedComponentRegionForComponentCode($pageType, $componentCode);
        if ($sharedRegion === 'header') {
            return [
                'task_key' => 'shared:header',
                'section_code' => 'shared:header',
                'shared_region' => 'header',
            ];
        }
        if ($sharedRegion === 'footer') {
            return [
                'task_key' => 'shared:footer',
                'section_code' => 'shared:footer',
                'shared_region' => 'footer',
            ];
        }

        $normalizedBlockId = $componentCode;
        if (\str_starts_with($normalizedBlockId, 'content/')) {
            $normalizedBlockId = \substr($normalizedBlockId, \strlen('content/'));
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

        throw new \RuntimeException((string)__('未找到组件对应的构建任务：%{1}', [$componentCode]));
    }

    private function resolveSharedComponentRegionForComponentCode(string $pageType, string $componentCode): string
    {
        $componentCode = \trim($componentCode);
        if ($componentCode === '') {
            return '';
        }

        $normalizedBlockId = $componentCode;
        if (\str_starts_with($normalizedBlockId, 'content/')) {
            $normalizedBlockId = \substr($normalizedBlockId, \strlen('content/'));
        }

        $pageSlug = \str_replace('_', '-', \trim($pageType));
        if (
            $componentCode === 'header/ai-site-header'
            || $normalizedBlockId === 'header-ai-site-header'
            || ($pageSlug !== '' && $normalizedBlockId === $pageSlug . '-site-header')
            || \str_ends_with($normalizedBlockId, '-site-header')
        ) {
            return 'header';
        }
        if (
            $componentCode === 'footer/ai-site-footer'
            || $normalizedBlockId === 'footer-ai-site-footer'
            || ($pageSlug !== '' && $normalizedBlockId === $pageSlug . '-site-footer')
            || \str_ends_with($normalizedBlockId, '-site-footer')
        ) {
            return 'footer';
        }

        return '';
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
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
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
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $pageLabel = (string)(Page::getPageTypes()[$pageType] ?? $pageType);
        $affectedPageTypes = [$pageType];

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
                $affectedPageTypes = $pageTypesAll;
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
                $affectedPageTypes = $pageTypesAll;
                $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypesAll);
                $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
                $this->virtualThemeService->saveGeneratedSharedComponent($virtualThemeId, $sharedComponent);
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypesAll, $pageTypeLayouts, $sharedComponent);
                $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypesAll, $pageTypeLayouts);
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
        $doneMessage = $instruction !== '' ? (string)__('区块微调完成') : (string)__('区块重建完成');
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        if (\in_array($activeOperationName, ['block_refine', 'block_regenerate'], true)) {
            $scope = $this->writeActiveOperationStateToScope($scope, \array_replace($activeOperation, [
                'operation' => 'block_regenerate',
                'status' => 'done',
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'instruction' => $instruction,
                'message' => $doneMessage,
                'updated_at' => \date('Y-m-d H:i:s'),
            ]));
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        }
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true),
            $affectedPageTypes
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
                'details' => ['task_key' => $taskKey, 'section_code' => $sectionCode, 'shared_region' => $sharedRegion],
            ]
        );

        $this->sendOperationProgress(
            $sse,
            $fresh,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'block_regenerate',
            $doneMessage,
            100,
            $pageType,
            'done',
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
        );

        return [
            'message' => $doneMessage,
            'page_type' => $pageType,
            'section_code' => $sectionCode,
            'shared_region' => $sharedRegion,
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
        $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);
        $qualityReport = $qualityGate->inspectScope(\is_array($state['scope'] ?? null) ? $state['scope'] : []);
        if (empty($qualityReport['passed'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('发布门禁未通过，请先修复页面内容质量、破图或任务状态问题。'),
                'data' => $qualityReport,
            ]);
        }
        $confirmVisualTheme = \in_array(
            \strtolower(\trim((string)$this->getRequestBodyValue('confirm_visual_theme', '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        if (!$confirmVisualTheme && (int)($state['scope']['virtual_theme_effect_confirmed'] ?? 0) !== 1) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('请先确认虚拟主题预览效果无问题，再发布正式站点。'),
                'data' => [
                    'required_param' => 'confirm_visual_theme',
                    'publish_quality_gate' => $qualityReport,
                ],
            ]);
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
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
            'data' => $this->buildWorkspaceOperationPayload(
                $this->buildWorkspaceState($fresh, $adminId, 12, false),
                'snapshot'
            ),
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
            $scopeArr = $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT);
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

    private function handleSwitchPreviewPageCompact(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $requestedPageId = (int)$this->getRequestBodyValue('preview_page_id', 0);
        $requestedPageType = \trim((string)$this->getRequestBodyValue('preview_page_type', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('鍙傛暟鏃犳晥'), self::PARAMS_PUBLIC_ID);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session not found.', self::PARAMS_PUBLIC_ID);
        }

        $snapshot = $this->sessionService->loadPreviewSwitchScopeSnapshot($session->getId(), $adminId);
        if ($snapshot === null) {
            return $this->fetchJson(['success' => false, 'message' => 'Preview snapshot unavailable.']);
        }

        $pagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType(
            $snapshot['pagebuilder_pages_by_type'] ?? []
        );
        if ($requestedPageType === '' && $requestedPageId > 0) {
            foreach ($pagesByType as $pageType => $pageData) {
                if ((int)($pageData['page_id'] ?? 0) === $requestedPageId) {
                    $requestedPageType = $pageType;
                    break;
                }
            }
        }

        $pageTypes = $this->scopeCompatibilityService->normalizePageTypes(
            \is_array($snapshot['page_types'] ?? null) ? $snapshot['page_types'] : []
        );
        $virtualPages = $this->scopeCompatibilityService->normalizeVirtualPagesByType(
            $snapshot['virtual_pages_by_type'] ?? [],
            $pageTypes
        );
        $previewPageType = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, $requestedPageType);
        if ($previewPageType === '') {
            return $this->fetchJson(['success' => false, 'message' => 'No preview page is available.']);
        }

        $previewPageId = (int)($pagesByType[$previewPageType]['page_id'] ?? 0);
        if (!$this->sessionService->updatePreviewSelectionScope($session->getId(), $adminId, $previewPageType, $previewPageId)) {
            return $this->fetchJson(['success' => false, 'message' => 'Preview page switch failed.']);
        }
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($snapshot['workspace_track'] ?? ''));

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($session->getStage()),
            'preview_page_switched',
            'Preview page switched.',
            ['page_type' => $previewPageType]
        );

        return $this->fetchJson([
            'success' => true,
            'data' => $this->buildPreviewSwitchPayload(
                $session,
                $pagesByType,
                $virtualPages,
                $previewPageType,
                $previewPageId,
                $workspaceTrack
            ),
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $pagesByType
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @return array<string, mixed>
     */
    private function buildPreviewSwitchPayload(
        AiSiteAgentSession $session,
        array $pagesByType,
        array $virtualPagesByType,
        string $previewPageType,
        int $previewPageId,
        string $workspaceTrack
    ): array {
        $virtualThemeId = $session->getVirtualThemeId();
        $selectedVirtualPages = [];
        if ($previewPageType !== '' && \is_array($virtualPagesByType[$previewPageType] ?? null)) {
            $selectedVirtualPages[$previewPageType] = $virtualPagesByType[$previewPageType];
        }
        $selectedVirtualPages = $this->htmlBlocksBuildService->stripServerOnlyVirtualPages($selectedVirtualPages);
        $minimalPagesByType = [];
        foreach ($pagesByType as $pageType => $pageData) {
            if (!\is_string($pageType) || !\is_array($pageData)) {
                continue;
            }
            $pageId = (int)($pageData['page_id'] ?? 0);
            if ($pageId <= 0) {
                continue;
            }
            $minimalPagesByType[$pageType] = ['page_id' => $pageId];
        }

        $prePublishVisualUrls = $previewPageType !== ''
            ? $this->visualUrlService->resolveVirtualUrls($session->getPublicId(), $previewPageType, $virtualThemeId)
            : ['preview_full_url' => '', 'visual_preview_url' => '', 'visual_edit_url' => ''];
        $resolvedUrls = $this->shouldUseWorkspacePreviewUrls($session, $workspaceTrack, $previewPageType)
            ? $prePublishVisualUrls
            : (
                $previewPageId > 0
                    ? $this->visualUrlService->resolveUrls($previewPageId, $virtualThemeId)
                    : $prePublishVisualUrls
            );

        return [
            'preview_page_id' => $previewPageId,
            'preview_page_type' => $previewPageType,
            'preview_full_url' => (string)($resolvedUrls['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($resolvedUrls['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($resolvedUrls['visual_edit_url'] ?? ''),
            'pagebuilder_pages_by_type' => $minimalPagesByType,
            'virtual_pages_by_type' => $selectedVirtualPages,
        ];
    }

    private function shouldUseWorkspacePreviewUrls(
        AiSiteAgentSession $session,
        string $workspaceTrack,
        string $previewPageType
    ): bool {
        return $previewPageType !== ''
            && $workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
            && $session->getPublishStatus() !== AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED;
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
        $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);
        $qualityReport = $qualityGate->inspectScope($scope);
        foreach (\is_array($qualityReport['items'] ?? null) ? $qualityReport['items'] : [] as $qualityItem) {
            if (\is_array($qualityItem)) {
                $checkItems[] = $qualityItem;
            }
        }
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
            'quality_gate' => $qualityReport,
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
        $streamObservedActiveOperation = $this->isWorkspaceStreamOperationActive($snapshotSource);
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

        $terminalCompletePayload = null;
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

            // Close this stream after an observed operation reaches a terminal state.
            $freshForTerminalCheck = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $terminalProbeState = $this->buildWorkspaceStreamOperationState($freshForTerminalCheck);
            if ($this->isWorkspaceStreamOperationActive($terminalProbeState)) {
                $streamObservedActiveOperation = true;
            }
            if ($streamObservedActiveOperation && $this->isWorkspaceStreamOperationTerminal($terminalProbeState)) {
                $session = $freshForTerminalCheck;
                $terminalStateSource = $this->buildWorkspaceState($session, $adminId, 40, self::WORKSPACE_STREAM_SNAPSHOT_PERSIST);
                $terminalCompletePayload = $this->buildWorkspaceStreamTerminalPayload($terminalStateSource, $lastEventId);
                if ($streamStage !== '') {
                    $terminalCompletePayload = $this->filterWorkspaceSnapshotByStage($terminalCompletePayload, $streamStage);
                }
                $this->logStreamSse('terminal_operation_detected', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                    'last_event_id' => $lastEventId,
                    'terminal_status' => (string)($terminalCompletePayload['terminal_status'] ?? ''),
                ]);
                break;
            }

            // 使用协程延迟，不阻塞 Worker
            $sse->maybeHeartbeat();
            SchedulerSystem::yieldDelay($pollInterval);
        }

        if (\is_array($terminalCompletePayload)) {
            $sse->complete($terminalCompletePayload);
        } else {
            $sse->complete(['success' => true, 'last_event_id' => $lastEventId]);
        }
        $this->releaseStreamLeaseState($session, $adminId, $leaseToken);
        $this->logStreamSse('stream_completed', [
            'session_id' => $session->getId(),
            'loop_count' => $loopCount,
            'last_event_id' => $lastEventId,
            'terminal_status' => \is_array($terminalCompletePayload) ? (string)($terminalCompletePayload['terminal_status'] ?? '') : '',
            'duration_sec' => \max(0, \time() - $startTime),
        ]);
    }

    /**
     * @return array{active_operation: array<string, mixed>}
     */
    private function buildWorkspaceStreamOperationState(AiSiteAgentSession $session): array
    {
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $this->scopeCompatibilityService->normalizeStage($session->getStage()))
        );
        return [
            'active_operation' => \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isWorkspaceStreamOperationActive(array $state): bool
    {
        $activeOperation = \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [];
        $status = \strtolower(\trim((string)($activeOperation['status'] ?? '')));

        return \in_array($status, ['queued', 'running'], true);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isWorkspaceStreamOperationTerminal(array $state): bool
    {
        $activeOperation = \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [];
        $status = \strtolower(\trim((string)($activeOperation['status'] ?? '')));

        return \in_array($status, ['done', 'error', 'cancelled'], true);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildWorkspaceStreamTerminalPayload(array $state, int $lastEventId): array
    {
        $payload = $this->buildWorkspaceStreamSnapshotPayload($state);
        $activeOperation = \is_array($payload['active_operation'] ?? null) ? $payload['active_operation'] : [];
        $status = \strtolower(\trim((string)($activeOperation['status'] ?? '')));
        $message = \trim((string)($activeOperation['message'] ?? ''));
        if ($message === '') {
            $message = match ($status) {
                'done' => 'Workspace operation completed; closing the workspace event stream.',
                'cancelled' => 'Workspace operation was cancelled; closing the workspace event stream.',
                'error' => 'Workspace operation failed; closing the workspace event stream.',
                default => 'Workspace event stream closed.',
            };
        }

        $payload['success'] = $status === 'done';
        $payload['message'] = $message;
        $payload['terminal_status'] = $status;
        $payload['last_event_id'] = $lastEventId;

        return $payload;
    }

    private function runQueuedPlanOperationFromWorkspaceStream(SseWriter $sse, AiSiteAgentSession $session, int $adminId): void
    {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if (\trim((string)($activeOperation['operation'] ?? '')) !== 'plan') {
            return;
        }
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $progressPercent = (int)($activeOperation['progress_percent'] ?? 0);
        $allowResumeStuckRunning = ($activeStatus === 'running' && $progressPercent <= 0);
        // 队列消费：claim 已将状态置为 running，且可能携带上次残留的 progress_percent>0；不得静默 return，否则 queue:run 无输出且不落库。
        $queueDbMode = $sse instanceof QueueDbWriter;
        if (!$queueDbMode) {
            $queueRow = $this->findAiSiteOperationQueueRow($fresh, 'plan', (int)($activeOperation['queue_id'] ?? 0));
            if (\is_array($queueRow) && $queueRow !== []) {
                $this->syncPlanActiveOperationFromQueueRow($fresh, $adminId, $activeOperation, $queueRow);
                return;
            }
        }
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
            $planFresh = $this->sessionService->loadById($fresh->getId(), $adminId) ?? $fresh;
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($planFresh, AiSiteAgentSession::STAGE_PLAN)
            );
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
                $queueDbMode = $sse instanceof QueueDbWriter;
                if ($queueDbMode) {
                    $sse->recordRawAiStreamChunk($chunk);
                }
                $planAiStreamBuffer .= $chunk;
                $delta = $this->streamCodecService->extractPlanMarkdownJsonStreamDelta($planAiStreamBuffer, $planMarkdownStreamState);
                if ($delta !== '') {
                    if (!$queueDbMode) {
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

                    if ($requestedPromptMode === 'mutate_plan_block') {
                        $mutation = \is_array($planSseRequest['mutation'] ?? null) ? $planSseRequest['mutation'] : [];
                        $mutationAction = \strtolower(\trim((string)($mutation['action'] ?? '')));
                        $mutationPageType = \trim((string)($mutation['page_type'] ?? ''));
                        $mutationBlockKey = \trim((string)($mutation['block_key'] ?? ''));
                        $mutationBlockConfig = \is_array($mutation['block_config'] ?? null) ? $mutation['block_config'] : [];
                        if ($mutationPageType === '' || !\in_array($mutationAction, ['create', 'delete', 'refine', 'rebuild'], true)) {
                            throw new \RuntimeException('Invalid stage-1 block mutation request.');
                        }
                        $mutationMessage = (string)__('正在后台更新阶段一页面块');
                        $sse->sendEvent('progress', [
                            'message' => $mutationMessage,
                            'operation' => 'plan',
                            'progress_percent' => 45,
                            'mutation_action' => $mutationAction,
                            'page_type' => $mutationPageType,
                            'block_key' => $mutationBlockKey,
                        ]);
                        $sse->sendEvent('log', [
                            'event_type' => 'plan_block_mutation',
                            'stage_code' => 'plan',
                            'message' => $mutationMessage,
                            'payload' => [
                                'operation' => 'plan',
                                'mutation_action' => $mutationAction,
                                'page_type' => $mutationPageType,
                                'block_key' => $mutationBlockKey,
                            ],
                            'level' => 'info',
                            'event_id' => 0,
                            'created_at' => \date('Y-m-d H:i:s'),
                        ]);
                        $artifacts = $this->executionBlueprintService->mutateDraftPlanBlock(
                            $scope,
                            $mutationPageType,
                            $mutationAction,
                            $mutationBlockKey,
                            $mutationBlockConfig
                        );
                        $artifacts['ai_generated'] = 1;
                    } elseif ($requestedPromptMode === 'refine' && \is_array($scope['plan_json'] ?? null) && $scope['plan_json'] !== []) {
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
                        if ((int)($scope['fake_mode'] ?? 0) === 1) {
                            $artifacts = $this->executionBlueprintService->buildPlanArtifacts($scope, \is_array($websiteProfile) ? $websiteProfile : []);
                        } else {
                            $buildPayload = [];
                            if ($requestedInstruction !== '') {
                                $buildPayload['instruction'] = $requestedInstruction;
                            }
                            $buildPayload['round'] = $requestedRound;
                            $buildPayload['staged_generation'] = true;
                            $artifacts = $this->executionBlueprintService->buildPlanArtifactsByAiStream($scope, \is_array($websiteProfile) ? $websiteProfile : [], $buildPayload, $onChunk);
                        }
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
                'plan_workbench' => \is_array($artifacts['plan_workbench'] ?? null) ? $artifacts['plan_workbench'] : [],
                'plan_locale' => $this->resolvePlanLocale($scope),
                'plan_ai_generated' => (int)($artifacts['ai_generated'] ?? 0),
                'plan_generated_at' => \date('Y-m-d H:i:s'),
                'plan_generated_locale' => (string)$scope['plan_locale'],
                'plan_generated_page_types' => \array_values(\array_map('strval', $currentPageTypes)),
                'plan_generated_source_signature' => $this->buildPlanSourceSignature(\array_replace($scope, ['page_types' => $currentPageTypes])),
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
            if ($requestedPromptMode === 'mutate_plan_block') {
                $mutationSummary = \is_array($artifacts['mutation_summary'] ?? null) ? $artifacts['mutation_summary'] : [];
                $this->appendWorkspaceEvent(
                    $fresh->getId(),
                    $adminId,
                    'plan',
                    'plan_block_mutated',
                    'Stage-1 plan block updated.',
                    [
                        'operation' => 'plan',
                        'details' => $mutationSummary,
                    ]
                );
                $sse->sendEvent('log', [
                    'event_type' => 'plan_block_mutated',
                    'stage_code' => 'plan',
                    'message' => 'Stage-1 plan block updated.',
                    'payload' => ['operation' => 'plan', 'details' => $mutationSummary],
                    'level' => 'done',
                    'event_id' => 0,
                    'created_at' => \date('Y-m-d H:i:s'),
                ]);
            }
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

    /**
     * Workspace SSE observes queued stage-1 work. When a queue row exists, mirror
     * its terminal/in-progress state into active_operation instead of executing
     * the generator from the SSE request.
     *
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed> $queueRow
     */
    private function syncPlanActiveOperationFromQueueRow(
        AiSiteAgentSession $session,
        int $adminId,
        array $activeOperation,
        array $queueRow
    ): void {
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        if ($queueStatus === '') {
            return;
        }

        $queueId = (int)($queueRow['queue_id'] ?? 0);
        $nextOperation = \array_replace($activeOperation, [
            'operation' => 'plan',
            'queue_id' => $queueId,
        ]);
        if (\in_array($queueStatus, ['pending', 'queued', 'running'], true)) {
            $nextOperation['status'] = $queueStatus === 'running' ? 'running' : 'queued';
            $queueProcess = \trim((string)($queueRow['process'] ?? ''));
            $nextOperation['message'] = $queueProcess !== ''
                ? $queueProcess
                : 'Stage-1 plan queue is waiting for worker execution.';
            $nextOperation['updated_at'] = \date('Y-m-d H:i:s');
        } elseif (\in_array($queueStatus, ['done', 'error', 'stop', 'cancelled'], true)) {
            $nextOperation = $this->reconcileActiveOperationWithQueueInfo(
                $nextOperation,
                $this->buildQueueObserverPanelPayload($queueRow),
                'plan'
            );
        } else {
            return;
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
        $scope = $this->writeActiveOperationStateToScope($scope, $nextOperation);
        if (($nextOperation['status'] ?? '') === 'done') {
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        } elseif (\in_array((string)($nextOperation['status'] ?? ''), ['queued', 'running'], true)) {
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        }
        $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);
    }

    private function runPlanOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): void
    {
        $this->runQueuedPlanOperationFromWorkspaceStream($sse, $session, $adminId);
    }

    private function runTaskPlanOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): void
    {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $scope = $this->normalizePlanConfirmationForTaskPlan($fresh, $adminId, $scope);
        if (!$this->isPlanConfirmedForTaskPlan($scope)) {
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

        if ($promptMode === 'mutate_task_plan_task') {
            $this->runTaskPlanTaskMutationOperation($sse, $fresh, $adminId, $scope, $sseReq);
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
     * @param array<string, mixed> $sseReq
     */
    private function runTaskPlanTaskMutationOperation(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        array $sseReq
    ): void {
        $mutation = \is_array($sseReq['mutation'] ?? null) ? $sseReq['mutation'] : [];
        $action = \strtolower(\trim((string)($mutation['action'] ?? '')));
        $bucket = \strtolower(\trim((string)($mutation['bucket'] ?? 'page'))) === 'shared' ? 'shared' : 'page';
        $pageType = \trim((string)($mutation['page_type'] ?? ''));
        $taskKey = \trim((string)($mutation['task_key'] ?? ''));
        $taskConfig = \is_array($mutation['task_config'] ?? null) ? $mutation['task_config'] : [];
        $instruction = \trim((string)($sseReq['instruction'] ?? ($mutation['instruction'] ?? '')));
        $targetScope = \trim((string)($sseReq['target_scope'] ?? ($taskKey !== '' ? $taskKey : ($bucket === 'shared' ? 'shared_tasks' : 'task_plan'))));
        $round = \max(1, (int)($sseReq['round'] ?? 1));

        if (!\in_array($action, ['refine', 'rebuild', 'delete', 'create'], true)) {
            throw new \RuntimeException('Unsupported stage-2 task mutation action: ' . $action);
        }

        $startMessage = match ($action) {
            'create' => 'Adding stage-2 task to draft task plan.',
            'delete' => 'Deleting stage-2 task from draft task plan.',
            'rebuild' => 'Rebuilding stage-2 task in draft task plan.',
            default => 'Refining stage-2 task in draft task plan.',
        };
        $doneMessage = match ($action) {
            'create' => 'Stage-2 task added.',
            'delete' => 'Stage-2 task deleted.',
            'rebuild' => 'Stage-2 task rebuilt.',
            default => 'Stage-2 task refined.',
        };

        $sse->sendEvent('start', [
            'message' => $startMessage,
            'prompt_mode' => 'mutate_task_plan_task',
            'mutation_action' => $action,
            'round' => $round,
            'target_scope' => $targetScope,
            'task_key' => $taskKey,
            'page_type' => $pageType,
            'bucket' => $bucket,
        ]);
        $sse->sendEvent('progress', [
            'message' => 'Preparing stage-2 task mutation context.',
            'prompt_mode' => 'mutate_task_plan_task',
            'mutation_action' => $action,
            'progress_percent' => 25,
        ]);

        try {
            $result = $this->virtualThemePlanService->mutateDraftTaskPlanTask(
                $scope,
                $action,
                $bucket,
                $pageType,
                $taskKey,
                $taskConfig,
                $instruction
            );

            $structured = \is_array($result['structured'] ?? null) ? $result['structured'] : [];
            $virtualThemePlan = \is_array($result['virtual_theme_plan'] ?? null) ? $result['virtual_theme_plan'] : [];
            $markdown = (string)($result['markdown'] ?? '');
            $summary = \is_array($result['mutation_summary'] ?? null) ? $result['mutation_summary'] : [];
            $taskPlanDirectoryTree = \is_array($structured['task_directory_tree'] ?? null) ? $structured['task_directory_tree'] : [];

            $sse->sendEvent('progress', [
                'message' => 'Stage-2 task mutation finished. Persisting updated task plan draft.',
                'prompt_mode' => 'mutate_task_plan_task',
                'mutation_action' => $action,
                'progress_percent' => 70,
            ]);

            if ($markdown !== '' && $sse->isAlive()) {
                foreach ($this->streamCodecService->chunkStringForSse($markdown, 220) as $mdChunk) {
                    if (\trim($mdChunk) === '') {
                        continue;
                    }
                    if (!$sse->isAlive()) {
                        break;
                    }
                    $sse->sendEvent('chunk', [
                        'content' => $mdChunk,
                        'chunk' => $mdChunk,
                        'prompt_mode' => 'mutate_task_plan_task',
                        'mutation_action' => $action,
                    ]);
                    SchedulerSystem::yieldDelay(5);
                }
            }

            $scopePatch = [
                'virtual_theme_plan' => [
                    'draft' => $virtualThemePlan,
                    'draft_markdown' => $markdown,
                    'draft_generated_at' => \date('Y-m-d H:i:s'),
                    'confirmed' => \is_array($scope['virtual_theme_plan']['confirmed'] ?? null) ? $scope['virtual_theme_plan']['confirmed'] : [],
                    'confirmed_markdown' => (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''),
                    'confirmed_at' => (string)($scope['virtual_theme_plan']['confirmed_at'] ?? ''),
                    'confirmed_signature' => (string)($scope['virtual_theme_plan']['confirmed_signature'] ?? ''),
                    'plan_signature' => (string)($virtualThemePlan['signature'] ?? $scope['virtual_theme_plan']['plan_signature'] ?? ''),
                    'last_prompt_mode' => 'manual_task_mutation',
                    'last_target_scope' => (string)(($summary['task_key'] ?? $taskKey) ?: $targetScope),
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
                    'prompt_mode' => 'manual_task_mutation',
                    'generation_source' => 'manual_task_mutation',
                ],
                'task_plan_confirmed' => 0,
                '_task_plan_rebuild_in_progress' => 0,
                '_task_plan_sse_request' => [],
            ];

            $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
                'task_plan_task_mutated',
                $doneMessage,
                [
                    'operation' => 'task_plan',
                    'details' => $summary,
                ]
            );
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                ['operation' => 'task_plan', 'status' => 'done', 'message' => $doneMessage],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );

            $state = $this->buildWorkspaceEventStatePayload($this->buildWorkspaceState($fresh, $adminId, 80, true));
            $sse->complete([
                'success' => true,
                'message' => $doneMessage,
                'prompt_mode' => 'mutate_task_plan_task',
                'mutation' => $summary,
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
                ['operation' => 'task_plan', 'status' => 'error', 'message' => $throwable->getMessage()],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'operation_failed',
                'Stage-2 task mutation failed: ' . $throwable->getMessage(),
                ['operation' => 'task_plan', 'details' => ['exception' => $throwable->getMessage(), 'action' => $action]],
                AiSiteAgentSessionEvent::LEVEL_ERROR
            );
            $sse->sendError($throwable->getMessage(), $this->inferThrowableHttpCode($throwable));
            $sse->complete([
                'success' => false,
                'message' => $throwable->getMessage(),
                'prompt_mode' => 'mutate_task_plan_task',
                'mutation_action' => $action,
            ]);
            if ($sse instanceof QueueDbWriter) {
                throw $throwable;
            }
        }
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
            $progressCallback = $this->buildTaskPlanGenerationProgressCallback($sse, $session, $adminId, $promptMode);
            $result = $promptMode === 'rebuild_task_plan'
                ? $this->virtualThemePlanService->rebuildDraftTaskPlan($scope, $buildBlueprint, [
                    'instruction' => $instruction,
                    'round' => $round,
                ], $chunkCallback, $taskPlanStreamHeartbeat, $progressCallback)
                : $this->virtualThemePlanService->refineDraftTaskPlan($scope, $buildBlueprint, $draftPlan, [
                    'instruction' => $instruction,
                    'target_scope' => $targetScope,
                    'round' => $round,
                ], $chunkCallback, $taskPlanStreamHeartbeat, $progressCallback);

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
                foreach ($this->streamCodecService->chunkStringForSse($markdown, 220) as $mdChunk) {
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
                '_task_plan_rebuild_in_progress' => 0,
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
                ['operation' => 'task_plan', 'status' => 'done', 'message' => (string)__('第二阶段任务方案生成完成')],
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
                ['operation' => 'task_plan', 'status' => 'error', 'message' => $throwable->getMessage()],
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

    private function buildTaskPlanGenerationProgressCallback(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $promptMode
    ): callable {
        return function (array $progress) use ($sse, $session, $adminId, $promptMode): void {
            $status = \trim((string)($progress['status'] ?? ''));
            $batchId = \trim((string)($progress['batch_id'] ?? ''));
            if ($status === '' || $batchId === '') {
                return;
            }

            $total = \max(1, (int)($progress['total_batches'] ?? 1));
            $completed = \max(0, (int)($progress['completed_batches'] ?? 0));
            $batchIndex = \max(0, (int)($progress['batch_index'] ?? 0));
            $attemptNo = \max(1, (int)($progress['attempt_no'] ?? ($status === 'batch_failed' ? 3 : 1)));
            $errorMessage = \trim((string)($progress['error_message'] ?? ''));
            $message = match ($status) {
                'batch_begin' => (string)__('正在生成第二阶段任务：%{task}', ['task' => $batchId]),
                'batch_done' => (string)__('第二阶段任务已生成：%{task}', ['task' => $batchId]),
                'batch_failed' => (string)__('第二阶段任务生成失败：%{task}', ['task' => $batchId]),
                default => (string)__('第二阶段任务生成进度：%{task}', ['task' => $batchId]),
            };
            $progressPercent = \min(94, \max(35, (int)\floor(35 + (($completed + ($status === 'batch_begin' ? 0 : 1)) / $total) * 55)));
            $batchProgress = [
                'batch_id' => $batchId,
                'status' => $status,
                'batch_type' => (string)($progress['batch_type'] ?? ''),
                'batch_key' => (string)($progress['batch_key'] ?? ''),
                'block_key' => (string)($progress['block_key'] ?? ''),
                'task_keys' => \array_values(\array_filter(\array_map('strval', \is_array($progress['task_keys'] ?? null) ? $progress['task_keys'] : []))),
                'attempt_no' => $attemptNo,
                'error_message' => $errorMessage,
                'updated_at' => \date('Y-m-d H:i:s'),
            ];

            try {
                $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $scope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
                $generationProgress = \is_array($scope['task_plan_generation_progress'] ?? null)
                    ? $scope['task_plan_generation_progress']
                    : [];
                $generationProgress[$batchId] = \array_replace(
                    \is_array($generationProgress[$batchId] ?? null) ? $generationProgress[$batchId] : [],
                    $batchProgress
                );
                $existingSummary = \is_array($scope['task_plan_generation_summary'] ?? null)
                    ? $scope['task_plan_generation_summary']
                    : [];
                $summaryTotal = \max($total, (int)($existingSummary['total_batches'] ?? 0), \count($generationProgress));
                $summaryCompleted = \count(\array_filter($generationProgress, static fn($item): bool => \is_array($item) && (string)($item['status'] ?? '') === 'batch_done'));
                $summaryFailed = \count(\array_filter($generationProgress, static fn($item): bool => \is_array($item) && (string)($item['status'] ?? '') === 'batch_failed'));

                $patch = [
                    'task_plan_generation_progress' => $generationProgress,
                    'task_plan_generation_summary' => [
                        'total_batches' => $summaryTotal,
                        'completed_batches' => $summaryCompleted,
                        'failed_batches' => $summaryFailed,
                        'prompt_mode' => $promptMode,
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ],
                ];

                if ($status === 'batch_done' && \is_array($progress['structured'] ?? null) && \is_array($progress['virtual_theme_plan'] ?? null)) {
                    $structured = $progress['structured'];
                    $virtualThemePlan = $progress['virtual_theme_plan'];
                    $patch['task_plan_structured'] = $structured;
                    $patch['virtual_theme_plan'] = \array_replace(
                        \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [],
                        [
                            'draft' => $virtualThemePlan,
                            'draft_generated_at' => \date('Y-m-d H:i:s'),
                            'confirmed' => \is_array($scope['virtual_theme_plan']['confirmed'] ?? null) ? $scope['virtual_theme_plan']['confirmed'] : [],
                            'confirmed_markdown' => (string)($scope['virtual_theme_plan']['confirmed_markdown'] ?? ''),
                            'confirmed_at' => (string)($scope['virtual_theme_plan']['confirmed_at'] ?? ''),
                            'confirmed_signature' => (string)($scope['virtual_theme_plan']['confirmed_signature'] ?? ''),
                            'plan_signature' => (string)($virtualThemePlan['signature'] ?? ($scope['virtual_theme_plan']['plan_signature'] ?? '')),
                            'last_prompt_mode' => $promptMode,
                        ]
                    );
                    $patch['task_plan_confirmed'] = 0;
                }

                if ($status === 'batch_failed') {
                    $patch['task_plan_generation_last_error'] = [
                        'batch_id' => $batchId,
                        'attempt_no' => $attemptNo,
                        'message' => $errorMessage,
                        'failed_at' => \date('Y-m-d H:i:s'),
                    ];
                }

                $this->sessionService->mergeScope($fresh->getId(), $adminId, $patch);
                $this->appendWorkspaceEvent(
                    $fresh->getId(),
                    $adminId,
                    AiSiteAgentSession::STAGE_VISUAL_EDIT,
                    $status === 'batch_failed' ? 'task_plan_batch_failed' : ($status === 'batch_done' ? 'task_plan_batch_done' : 'task_plan_batch_started'),
                    $status === 'batch_failed' && $errorMessage !== '' ? $message . '：' . $errorMessage : $message,
                    ['operation' => 'task_plan', 'details' => $batchProgress],
                    $status === 'batch_failed' ? AiSiteAgentSessionEvent::LEVEL_ERROR : AiSiteAgentSessionEvent::LEVEL_INFO
                );
            } catch (\Throwable) {
                // Progress persistence should not interrupt the AI task-plan batch.
            }

            if ($sse->isAlive()) {
                $payload = [
                    'message' => $status === 'batch_failed' && $errorMessage !== '' ? $message . '：' . $errorMessage : $message,
                    'operation' => 'task_plan',
                    'prompt_mode' => $promptMode,
                    'progress_percent' => $progressPercent,
                    'progress_kind' => 'task_plan_batch',
                    'task_plan_batch' => $batchProgress,
                ];
                $sse->sendEvent($status === 'batch_failed' ? 'warning' : 'progress', $payload);
            }
        };
    }

    private function emitPlanMarkdownBlocks(SseWriter $sse, AiSiteAgentSession $session, int $adminId, string $markdown): void
    {
        $text = \trim($markdown);
        if ($text === '') {
            return;
        }
        foreach ($this->streamCodecService->splitMarkdownBlocks($text) as $block) {
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
        string $operation,
        string $claimSource = 'operation_sse'
    ): array {
        $stageCode = $this->resolveAiSiteQueueStage($operation);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($fresh, $stageCode)
            );
            $active = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
            $op = \trim((string)($active['operation'] ?? ''));
            $tok = \trim((string)($active['execution_token'] ?? ''));
            $status = \trim((string)($active['status'] ?? ''));

            if ($op !== $operation || !$this->executionTokenMatches($tok, $executionToken)) {
                if ($operation !== 'plan') {
                    return ['ok' => true, 'reason' => 'parallel_non_plan'];
                }
                return ['ok' => false, 'reason' => 'terminal', 'message' => (string)__('未找到待执行的工作区操作')];
            }

            if (\in_array($status, ['done', 'error', 'cancelled'], true)) {
                return ['ok' => false, 'reason' => 'terminal', 'message' => (string)__('该操作已结束，请重新发起')];
            }

            $allowUnclaimedPlanQueueRun = false;
            if ($status === 'running') {
                $claimedBy = \trim((string)($active['claimed_by'] ?? ''));
                if (
                    $operation === 'plan'
                    && $claimSource === 'queue'
                    && $claimedBy === ''
                    && !$this->scopeHasPersistedStageOnePlan($scope)
                ) {
                    // Queue observers can mirror the queue row to "running" before the worker claims it.
                    $allowUnclaimedPlanQueueRun = true;
                } else {
                    return ['ok' => false, 'reason' => 'duplicate_stream'];
                }
            }

            if ($status !== 'queued' && !$allowUnclaimedPlanQueueRun) {
                return ['ok' => false, 'reason' => 'terminal', 'message' => (string)__('操作状态异常，请刷新后重试')];
            }

            $scope = $this->writeActiveOperationStateToScope($scope, \array_replace($active, [
                'status' => 'running',
                'claimed_by' => $claimSource,
                'claimed_at' => \date('Y-m-d H:i:s'),
                'message' => (string)__('操作开始执行'),
                'updated_at' => \date('Y-m-d H:i:s'),
            ]));
            $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);

            $verify = $this->sessionService->loadById($session->getId(), $adminId) ?? $fresh;
            $vScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($verify, $stageCode)
            );
            $vActive = $this->resolveActiveOperationForExecutionToken($vScope, $executionToken);
            if ($this->executionTokenMatches((string)($vActive['execution_token'] ?? ''), $executionToken)
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

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveActiveOperationForExecutionToken(array $scope, string $executionToken): array
    {
        $executionToken = \trim($executionToken);
        if ($executionToken === '') {
            return [];
        }

        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if ($this->executionTokenMatches((string)($activeOperation['execution_token'] ?? ''), $executionToken)) {
            return $activeOperation;
        }

        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        foreach ($activeOperations as $operation => $operationState) {
            if (!\is_array($operationState)) {
                continue;
            }
            if (!$this->executionTokenMatches((string)($operationState['execution_token'] ?? ''), $executionToken)) {
                continue;
            }
            if (\trim((string)($operationState['operation'] ?? '')) === '' && \is_string($operation)) {
                $operationState['operation'] = $operation;
            }

            return $operationState;
        }

        return [];
    }

    private function executionTokenMatches(string $actualToken, string $requestedToken): bool
    {
        $actualToken = \trim($actualToken);
        $requestedToken = \trim($requestedToken);
        if ($actualToken === '' || $requestedToken === '') {
            return false;
        }
        if ($actualToken === $requestedToken) {
            return true;
        }

        $actualBase = \explode('-force-', $actualToken, 2)[0] ?? $actualToken;
        $requestedBase = \explode('-force-', $requestedToken, 2)[0] ?? $requestedToken;

        return $actualBase !== '' && $actualBase === $requestedBase;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $operationState
     * @return array<string, mixed>
     */
    private function writeActiveOperationStateToScope(array $scope, array $operationState): array
    {
        $operation = \trim((string)($operationState['operation'] ?? ''));
        $executionToken = \trim((string)($operationState['execution_token'] ?? ''));
        $current = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $currentOperation = \trim((string)($current['operation'] ?? ''));
        $currentToken = \trim((string)($current['execution_token'] ?? ''));

        if (
            $current === []
            || ($executionToken !== '' && $currentToken === $executionToken)
            || ($operation !== '' && $currentOperation === $operation)
        ) {
            $scope['active_operation'] = $operationState;
        }

        if ($operation !== '') {
            $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
            $currentOperationState = \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [];
            $activeOperations[$operation] = \array_replace(
                $currentOperationState,
                $operationState,
                ['operation' => $operation]
            );
            $scope['active_operations'] = $activeOperations;
        }

        return $scope;
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $this->scopeCompatibilityService->normalizeStage($session->getStage()))
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $status = \trim((string)($activeOperation['status'] ?? ''));
        if ($operation === '') {
            $this->sendSseContractError($sse, 'OPERATION_NOT_FOUND', (string)__('未找到待执行的工作区操作'), self::PARAMS_OPERATION_SSE, 404);
            $sse->complete(['success' => false]);
            return;
        }

        if ($this->supportsBackgroundOperation($operation) && !\in_array($status, ['queued', 'running'], true)) {
            $terminalQueueRow = $this->findAiSiteOperationQueueRow(
                $session,
                $operation,
                (int)($activeOperation['queue_id'] ?? 0)
            );
            if (\is_array($terminalQueueRow) && \trim((string)($terminalQueueRow['status'] ?? '')) === 'done') {
                $observed = $this->observeDuplicateOperationStream($sse, $session, $adminId, $operation, $executionToken);
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
                    $healScope = $this->scopeCompatibilityService->normalizeScope(
                        $this->sessionService->loadScopeForStage($session, $this->resolveAiSiteQueueStage($operation))
                    );
                    $healActive = $this->resolveActiveOperationForExecutionToken($healScope, $executionToken);
                    $healScope = $this->writeActiveOperationStateToScope($healScope, \array_replace($healActive, [
                        'operation' => $operation,
                        'execution_token' => $executionToken,
                        'queue_id' => $newQueueId,
                        'updated_at' => \date('Y-m-d H:i:s'),
                    ]));
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
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $this->resolveAiSiteQueueStage($operation))
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);

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
            'block_regenerate' => $this->runRegenerateBlockOperation(
                $sse,
                $session,
                $adminId,
                (string)($activeOperation['page_type'] ?? ''),
                $this->resolveBlockRegenerateComponentCodeFromOperation($activeOperation),
                (string)($activeOperation['instruction'] ?? ($activeOperation['details']['instruction'] ?? ''))
            ),
            'regenerate_page' => $this->runRegeneratePageOperation($sse, $session, $adminId, (string)($activeOperation['page_type'] ?? '')),
            'publish' => $this->runPublishOperation($sse, $session, $adminId),
            default => throw new \RuntimeException((string)__('未知操作：%{operation}（允许：%{allowed}）', [
                'operation' => $operation !== '' ? $operation : '(empty)',
                'allowed' => 'plan, build, block_regenerate, regenerate_page, publish',
            ])),
        };
    }

    /**
     * @param array<string, mixed> $activeOperation
     */
    private function resolveBlockRegenerateComponentCodeFromOperation(array $activeOperation): string
    {
        $details = \is_array($activeOperation['details'] ?? null) ? $activeOperation['details'] : [];
        foreach (['component_code', 'block_key', 'section_code', 'task_key'] as $key) {
            $candidate = \trim((string)($activeOperation[$key] ?? $details[$key] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
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
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
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
        $stageCode = $this->resolveAiSiteQueueStage($operation);

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, $stageCode)
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
        $startedAtRaw = \trim((string)($activeOperation['started_at'] ?? ''));
        $lastEventId = 0;
        $queueId = (int)($activeOperation['queue_id'] ?? 0);
        $lastQueueProcess = '';
        $lastQueueResultLength = 0;
        $queueDispatchAttempted = false;
        $lastTaskProgressSnapshotSignature = '';
        $initialQueueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, $queueId);
        $eventCorrelation = $this->buildObservedOperationEventCorrelation(
            $operation,
            $executionToken,
            $activeOperation,
            $initialQueueRow
        );

        $recentEvents = $this->filterObservedOperationEvents(
            $this->sessionService->listRecentEvents($session->getId(), $adminId, 160),
            $operation,
            $startedAtRaw,
            $lastEventId,
            $eventCorrelation
        );
        $lastEventId = $this->forwardObservedOperationEvents($sse, $session, $adminId, $recentEvents, $lastEventId, $initialQueueRow);
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
        $this->emitObservedBuildTaskProgressSnapshot(
            $sse,
            $scope,
            $operation,
            $initialQueueRow,
            $lastTaskProgressSnapshotSignature
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
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($fresh, $stageCode)
            );
            $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
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
            $eventCorrelation = $this->buildObservedOperationEventCorrelation(
                $operation,
                $executionToken,
                $activeOperation,
                $queueRow
            );
            $newEvents = $this->filterObservedOperationEvents(
                $this->sessionService->listEventsAfterId($session->getId(), $adminId, $lastEventId, 80),
                $operation,
                $startedAtRaw,
                $lastEventId,
                $eventCorrelation
            );
            if ($newEvents !== []) {
                $freshAfterEvents = $this->sessionService->loadById($session->getId(), $adminId) ?? $fresh;
                $queueRowAfterEvents = $this->findAiSiteOperationQueueRow($freshAfterEvents, $operation, $queueId);
                if (\is_array($queueRowAfterEvents) && $queueRowAfterEvents !== []) {
                    $fresh = $freshAfterEvents;
                    $scope = $this->scopeCompatibilityService->normalizeScope(
                        $this->sessionService->loadScopeForStage($fresh, $stageCode)
                    );
                    $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
                    $queueId = (int)($activeOperation['queue_id'] ?? $queueId);
                    $queueRow = $queueRowAfterEvents;
                }
                $lastEventId = $this->forwardObservedOperationEvents($sse, $session, $adminId, $newEvents, $lastEventId, $queueRow);
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
            $this->emitObservedBuildTaskProgressSnapshot(
                $sse,
                $scope,
                $operation,
                $queueRow,
                $lastTaskProgressSnapshotSignature
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
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
        $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, (int)($activeOperation['queue_id'] ?? $queueId));
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        [$fresh, $scope, $activeOperation, $queueRow, $queueStatus] = $this->settleObservedQueueStateIfNeeded(
            $sse,
            $session,
            $adminId,
            $operation,
            $executionToken,
            $queueId,
            $fresh,
            $scope,
            $activeOperation,
            $queueRow,
            $queueStatus
        );
        if ($queueStatus === 'done') {
            $operationToken = \trim((string)($activeOperation['execution_token'] ?? ''));
            if ($operationToken === '') {
                $operationToken = $executionToken;
            }
            $completeMessage = $this->resolveObservedQueueMessage($queueRow, true);
            if ($completeMessage === '') {
                $completeMessage = 'Queue operation completed.';
            }
            $activeOperation = \array_replace($activeOperation, [
                'operation' => $operation,
                'execution_token' => $operationToken,
                'status' => 'done',
                'queue_id' => (int)($queueRow['queue_id'] ?? $queueId),
                'message' => $completeMessage,
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
            $scope = $this->writeActiveOperationStateToScope($scope, $activeOperation);
            $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $fresh;
        }
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true)
        );
        if (\is_array($state['active_operation'] ?? null)) {
            $stateActiveOperation = $state['active_operation'];
            if (
                \trim((string)($stateActiveOperation['operation'] ?? '')) === $operation
                && $this->executionTokenMatches((string)($stateActiveOperation['execution_token'] ?? ''), $executionToken)
            ) {
                $activeOperation = $stateActiveOperation;
            }
        }
        $status = \trim((string)($activeOperation['status'] ?? ''));

        $activeInProgress = \in_array($status, ['queued', 'running'], true);
        $queueInProgress = \in_array($queueStatus, ['pending', 'queued', 'running'], true);
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
        if ($queueStatus === 'done' && $sse->isAlive()) {
            $queueSnapshot = $this->buildQueueObserverPublicSnapshot($queueRow);
            $queueInfo = $this->buildQueueObserverPanelPayload($queueRow);
            $sse->sendEvent('info', [
                'message' => $message,
                'operation' => $operation,
                'queue_id' => (int)($queueRow['queue_id'] ?? 0),
                'queue_status' => $queueStatus,
                'queue_snapshot' => $queueSnapshot,
                'queue_info' => $queueInfo,
                'state' => $state,
                'token_usage' => \is_array($queueSnapshot['token_usage'] ?? null) ? $queueSnapshot['token_usage'] : [],
                'progress_kind' => 'queue_info',
                'observer_detail' => true,
                'queue_panel_update' => true,
            ]);
        }

        if (!$timedOut && !$success && ($activeInProgress || $queueInProgress)) {
            return [
                'success' => true,
                'message' => $message !== '' ? $message : (string)__('操作仍在执行中，工作区将继续同步后台队列进度。'),
                'data' => $this->buildObservedOperationResultData($operation, $state),
                'state' => $state,
                'http_code' => 200,
                'deferred_queue_progress' => true,
            ];
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
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $queueRow
     * @return array{AiSiteAgentSession, array<string, mixed>, array<string, mixed>, array<string, mixed>|null, string}
     */
    private function settleObservedQueueStateIfNeeded(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        int $queueId,
        AiSiteAgentSession $fresh,
        array $scope,
        array $activeOperation,
        ?array $queueRow,
        string $queueStatus
    ): array {
        if (!$this->supportsBackgroundOperation($operation)) {
            return [$fresh, $scope, $activeOperation, $queueRow, $queueStatus];
        }

        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        if (
            \in_array($activeStatus, ['queued', 'running'], true)
            || !\in_array($queueStatus, ['pending', 'queued', 'running'], true)
        ) {
            return [$fresh, $scope, $activeOperation, $queueRow, $queueStatus];
        }

        $delayMs = $this->getObservedQueueSettleDelayMs();
        if ($delayMs > 0) {
            $sse->maybeHeartbeat();
            SchedulerSystem::yieldDelay($delayMs);
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $fresh;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
        $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
        $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, (int)($activeOperation['queue_id'] ?? $queueId));
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));

        return [$fresh, $scope, $activeOperation, $queueRow, $queueStatus];
    }

    private function getObservedQueueSettleDelayMs(): int
    {
        $override = RequestContext::get(self::REQUEST_CTX_OBSERVER_QUEUE_SETTLE_DELAY_MS);
        if (\is_numeric($override)) {
            return \max(0, (int)$override);
        }

        return self::OBSERVER_QUEUE_SETTLE_DELAY_MS;
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
            || !$this->executionTokenMatches((string)($activeOperation['execution_token'] ?? ''), $executionToken)
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
        return $this->queueSnapshotService()->buildObserverPublicSnapshot($queueRow);
    }

    /**
     * PageBuilder operation 对应 weline_queue 行：queue_id + 快照 + process + result 尾部，供侧栏进度区展示。
     *
     * @param array<string, mixed> $activeOperation
     *
     * @return array<string, mixed>|null
     */
    private function buildOperationStageQueueInfoPayload(
        AiSiteAgentSession $session,
        array $activeOperation,
        string $operation
    ): ?array {
        $operation = \trim($operation);
        if ($operation === '') {
            return null;
        }
        $queueId = 0;
        if (\trim((string)($activeOperation['operation'] ?? '')) === $operation) {
            $queueId = (int)($activeOperation['queue_id'] ?? 0);
        }
        $queueRow = $this->findAiSiteOperationQueueRow($session, $operation, $queueId);
        if (!\is_array($queueRow) || $queueRow === []) {
            return null;
        }
        return $this->buildQueueObserverPanelPayload($queueRow);
    }

    /**
     * @param array<string, mixed> $activeOperation
     *
     * @return array<string, mixed>|null
     */
    private function buildPlanStageQueueInfoPayload(AiSiteAgentSession $session, array $activeOperation): ?array
    {
        return $this->buildOperationStageQueueInfoPayload($session, $activeOperation, 'plan');
    }

    private function queueSnapshotService(): AiSiteQueueSnapshotService
    {
        return isset($this->queueSnapshotService)
            ? $this->queueSnapshotService
            : ObjectManager::getInstance(AiSiteQueueSnapshotService::class);
    }

    private function queueObserverHelperService(): AiSiteAgentQueueObserverHelperService
    {
        return isset($this->queueObserverHelperService)
            ? $this->queueObserverHelperService
            : ObjectManager::getInstance(AiSiteAgentQueueObserverHelperService::class);
    }

    private function queueObserverStreamService(): AiSiteAgentQueueObserverStreamService
    {
        return isset($this->queueObserverStreamService)
            ? $this->queueObserverStreamService
            : ObjectManager::getInstance(AiSiteAgentQueueObserverStreamService::class);
    }

    private function workspaceEntryNoticeService(): AiSiteAgentWorkspaceEntryNoticeService
    {
        return isset($this->workspaceEntryNoticeService)
            ? $this->workspaceEntryNoticeService
            : ObjectManager::getInstance(AiSiteAgentWorkspaceEntryNoticeService::class);
    }

    private function workspaceStateHelperService(): AiSiteAgentWorkspaceStateHelperService
    {
        return isset($this->workspaceStateHelperService)
            ? $this->workspaceStateHelperService
            : ObjectManager::getInstance(AiSiteAgentWorkspaceStateHelperService::class);
    }

    /**
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $queueInfo
     *
     * @return array<string, mixed>
     */
    private function reconcileActiveOperationWithQueueInfo(
        array $activeOperation,
        ?array $queueInfo,
        string $operation
    ): array {
        return $this->queueObserverStreamService()->reconcileActiveOperationWithQueueInfo(
            $activeOperation,
            $queueInfo,
            $operation
        );
    }

    /**
     * 页面进入时先用 task_plan 队列快照恢复 active_operation，避免前端拿到陈旧状态。
     *
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $taskPlanQueueInfo
     * @return array<string, mixed>
     */
    private function initializeTaskPlanActiveOperationFromQueueInfo(
        AiSiteAgentSession $session,
        array $activeOperation,
        ?array $taskPlanQueueInfo
    ): array {
        if (!\is_array($taskPlanQueueInfo['snapshot'] ?? null)) {
            return $activeOperation;
        }
        $snapshot = $taskPlanQueueInfo['snapshot'];
        $queueStatus = \trim((string)($snapshot['status'] ?? ''));
        if (!\in_array($queueStatus, ['pending', 'queued', 'running'], true)) {
            return $activeOperation;
        }

        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $status = \trim((string)($activeOperation['status'] ?? ''));
        if ($operation === 'task_plan' && \in_array($status, ['queued', 'running'], true)) {
            return $activeOperation;
        }

        $queueId = (int)($taskPlanQueueInfo['queue_id'] ?? $snapshot['queue_id'] ?? 0);
        $executionToken = '';
        if ($queueId > 0) {
            $queueRow = $this->findAiSiteOperationQueueRow($session, 'task_plan', $queueId);
            if (\is_array($queueRow) && $queueRow !== []) {
                $content = \json_decode((string)($queueRow['content'] ?? ''), true);
                if (\is_array($content)) {
                    $executionToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));
                }
            }
        }

        return \array_replace($activeOperation, [
            'operation' => 'task_plan',
            'status' => $queueStatus === 'running' ? 'running' : 'queued',
            'queue_id' => $queueId,
            'execution_token' => $executionToken !== '' ? $executionToken : (string)($activeOperation['execution_token'] ?? ''),
            'message' => (string)__('检测到第二阶段队列仍在执行，已初始化队列同步状态。'),
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $activeOperation
     * @param array<string, array<string, mixed>|null> $queueInfoByOperation
     * @return array<string, mixed>
     */
    private function buildWorkspaceEntryQueueNotice(array $activeOperation, array $queueInfoByOperation): array
    {
        // Thin proxy to AiSiteAgentWorkspaceEntryNoticeService (R4.F5 Step C extraction).
        // 唯一的控制器依赖 `getTaskPlanQueueRecoveryAction` 通过 Closure 端口注入。
        return $this->workspaceEntryNoticeService()->buildWorkspaceEntryQueueNotice(
            $activeOperation,
            $queueInfoByOperation,
            fn (array $operation): string => $this->getTaskPlanQueueRecoveryAction($operation),
        );
    }

    /**
     * @param array<string, mixed> $queueRow
     *
     * @return array<string, mixed>
     */
    private function buildQueueObserverPanelPayload(array $queueRow): array
    {
        return $this->queueObserverHelperService()->buildPanelPayload($queueRow, $this->buildQueueObserverPublicSnapshot($queueRow));
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $taskPlanQueueInfo
     * @return array{normalized:array<string,mixed>,active_operation:array<string,mixed>,task_plan_queue_info:array<string,mixed>|null}
     */
    private function autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
        AiSiteAgentSession $session,
        int $adminId,
        string $currentStage,
        array $normalized,
        array $activeOperation,
        ?array $taskPlanQueueInfo
    ): array {
        $ports = new AiSiteAgentTaskPlanQueueRecoveryPorts(
            isTaskPlanDraftMissing: fn (array $n): bool => $this->isTaskPlanDraftMissing($n),
            findQueueRow: fn (AiSiteAgentSession $s, string $op, int $qid = 0): ?array
                => ($row = $this->findAiSiteOperationQueueRow($s, $op, $qid)) !== null && $row !== [] ? $row : null,
            enqueueTask: fn (AiSiteAgentSession $s, int $a, string $op, string $t, array $extras): int
                => $this->enqueueOperationQueueTask($s, $a, $op, $t, $extras),
            buildOperationEnvelope: fn (AiSiteAgentSession $s, string $op, string $t, string $st): array
                => $this->buildOperationQueueEnvelope($s, $op, $t, $st),
            mergeSessionScope: function (int $sid, int $a, array $patch): void {
                $this->sessionService->mergeScope($sid, $a, $patch);
            },
            loadSession: fn (int $sid, int $a): ?AiSiteAgentSession
                => $this->sessionService->loadById($sid, $a),
            ensureWorkerDispatched: fn (AiSiteAgentSession $s, int $a, string $op, int $qid, string $t, bool $f): array
                => $this->ensureAiSiteQueueWorkerDispatched($s, $a, $op, $qid, $t, $f),
            buildQueueInfoPayload: fn (AiSiteAgentSession $s, array $active, string $op): array
                => $this->buildOperationStageQueueInfoPayload($s, $active, $op),
            logSse: function (string $event, array $data, string $level = 'info'): void {
                $this->logOperationSse($event, $data, $level);
            },
            resolveQueueStage: fn (string $op): string => $this->resolveAiSiteQueueStage($op),
            updateQueueRow: function (int $qid, array $patch): void {
                \w_query('queue', 'update', ['queue_id' => $qid, 'patch' => $patch]);
            },
        );

        return $this->taskPlanQueueRecoveryService->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $session,
            $adminId,
            $currentStage,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            $normalized,
            $activeOperation,
            $taskPlanQueueInfo,
            $ports
        );
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $buildQueueInfo
     * @param array<string, mixed> $taskSummary
     * @return array{normalized:array<string,mixed>,active_operation:array<string,mixed>,build_queue_info:array<string,mixed>|null,task_summary:array<string,mixed>}
     */
    private function autoResumeBuildQueueWhenTasksIncomplete(
        AiSiteAgentSession $session,
        int $adminId,
        string $currentStage,
        array $normalized,
        array $activeOperation,
        ?array $buildQueueInfo,
        array $taskSummary
    ): array {
        $result = [
            'normalized' => $normalized,
            'active_operation' => $activeOperation,
            'build_queue_info' => $buildQueueInfo,
            'task_summary' => $taskSummary,
        ];
        if ($currentStage !== AiSiteAgentSession::STAGE_VISUAL_EDIT) {
            return $result;
        }
        if (!$this->isTaskPlanConfirmedForBuild($normalized)) {
            return $result;
        }
        if ((int)($taskSummary['total'] ?? 0) <= 0 || $this->countIncompleteBuildTasks($taskSummary) <= 0) {
            return $result;
        }
        if ($this->hasBlockingQueuedOperationBeforeBuild($normalized, $activeOperation)) {
            return $result;
        }

        $activeOperations = \is_array($normalized['active_operations'] ?? null) ? $normalized['active_operations'] : [];
        $activeBuildOperation = \is_array($activeOperations['build'] ?? null) ? $activeOperations['build'] : [];
        if (
            (
                \trim((string)($activeOperation['operation'] ?? '')) === 'build'
                && \trim((string)($activeOperation['status'] ?? '')) === 'cancelled'
            )
            || \trim((string)($activeBuildOperation['status'] ?? '')) === 'cancelled'
        ) {
            return $result;
        }

        $queueRow = $this->resolveBuildQueueRowForAutoResume($session, $buildQueueInfo);
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        $queueId = (int)($queueRow['queue_id'] ?? 0);
        if ($queueStatus === '' || \in_array($queueStatus, ['done', 'error', 'stop'], true)) {
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $freshScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
            $freshScope = $this->normalizeTaskPlanConfirmationForBuild($freshScope);
            $freshTaskSummary = $this->buildTaskService->summarize($freshScope);
            if ((int)($freshTaskSummary['total'] ?? 0) > 0) {
                $taskSummary = $freshTaskSummary;
                $result['task_summary'] = $freshTaskSummary;
                foreach (['build_blueprint', 'build_tasks', 'task_plan_confirmed', 'virtual_theme_plan'] as $scopeKey) {
                    if (\array_key_exists($scopeKey, $freshScope)) {
                        $normalized[$scopeKey] = $freshScope[$scopeKey];
                    }
                }
                $result['normalized'] = $normalized;
            }
            if (!$this->isTaskPlanConfirmedForBuild($normalized) || $this->countIncompleteBuildTasks($taskSummary) <= 0) {
                return $result;
            }
        }

        if (\trim((string)($activeBuildOperation['operation'] ?? '')) === '') {
            $activeBuildOperation['operation'] = 'build';
        }
        $executionToken = $this->resolveQueueExecutionToken($queueRow, $activeBuildOperation);
        if ($executionToken === '') {
            $executionToken = \bin2hex(\random_bytes(16));
        }

        $reason = $queueId > 0
            ? 'queue_' . ($queueStatus !== '' ? $queueStatus : 'unknown') . '_but_tasks_incomplete'
            : 'queue_missing_but_tasks_incomplete';
        if (\in_array($queueStatus, ['pending', 'queued', 'running'], true) && $queueId > 0) {
            $activeBuildOperation = $this->buildAutoResumeBuildOperation(
                $session,
                $activeBuildOperation,
                $executionToken,
                $queueId,
                $queueStatus === 'running' ? 'running' : 'queued',
                $reason
            );
            $dispatch = $this->ensureAiSiteQueueWorkerDispatched($session, $adminId, 'build', $queueId, $executionToken);
        } else {
            try {
                $queueId = $this->enqueueOperationQueueTask($session, $adminId, 'build', $executionToken, ['_force_rebuild' => 1]);
                if ($queueId <= 0) {
                    throw new \RuntimeException('enqueue_build_queue_failed');
                }
                $activeBuildOperation = $this->buildAutoResumeBuildOperation(
                    $session,
                    $activeBuildOperation,
                    $executionToken,
                    $queueId,
                    'queued',
                    $reason
                );
                $dispatch = $this->ensureAiSiteQueueWorkerDispatched($session, $adminId, 'build', $queueId, $executionToken, true);
            } catch (\Throwable $throwable) {
                $this->logOperationSse('build_queue_auto_resume_failed', [
                    'public_id' => $session->getPublicId(),
                    'reason' => $reason,
                    'queue_id' => $queueId,
                    'task_total' => (int)($taskSummary['total'] ?? 0),
                    'task_incomplete' => $this->countIncompleteBuildTasks($taskSummary),
                    'error' => $throwable->getMessage(),
                ], 'error');
                return $result;
            }
        }

        $activeOperations['build'] = $activeBuildOperation;
        $normalized['active_operations'] = $activeOperations;
        $normalized['active_operation'] = $activeBuildOperation;
        $normalized['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        $this->sessionService->mergeScope($session->getId(), $adminId, [
            'active_operation' => $activeBuildOperation,
            'active_operations' => $activeOperations,
            'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
        ]);

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $buildQueueInfo = $this->buildOperationStageQueueInfoPayload($fresh, $activeBuildOperation, 'build');
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'operation_progress',
            (string)__('Detected unfinished build tasks; build queue has been resumed.'),
            [
                'operation' => 'build',
                'queue_id' => $queueId,
                'details' => [
                    'reason' => $reason,
                    'task_total' => (int)($taskSummary['total'] ?? 0),
                    'task_incomplete' => $this->countIncompleteBuildTasks($taskSummary),
                    'queue_dispatch' => $dispatch,
                ],
            ]
        );
        $this->logOperationSse('build_queue_auto_resume', [
            'public_id' => $session->getPublicId(),
            'reason' => $reason,
            'queue_id' => $queueId,
            'queue_status' => $queueStatus,
            'task_total' => (int)($taskSummary['total'] ?? 0),
            'task_incomplete' => $this->countIncompleteBuildTasks($taskSummary),
            'dispatch' => $dispatch,
        ]);

        return [
            'normalized' => $normalized,
            'active_operation' => $activeBuildOperation,
            'build_queue_info' => $buildQueueInfo,
            'task_summary' => $taskSummary,
        ];
    }

    /**
     * @param array<string, mixed> $taskSummary
     */
    private function countIncompleteBuildTasks(array $taskSummary): int
    {
        return \max(0, (int)($taskSummary['pending'] ?? 0))
            + \max(0, (int)($taskSummary['running'] ?? 0))
            + \max(0, (int)($taskSummary['failed'] ?? 0));
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $activeOperation
     */
    private function hasBlockingQueuedOperationBeforeBuild(array $normalized, array $activeOperation): bool
    {
        $activeOperations = \is_array($normalized['active_operations'] ?? null) ? $normalized['active_operations'] : [];
        $operationStates = [$activeOperation];
        foreach (['plan', 'task_plan', 'block_regenerate', 'regenerate_page', 'publish'] as $operation) {
            if (\is_array($activeOperations[$operation] ?? null)) {
                $operationStates[] = $activeOperations[$operation];
            }
        }

        foreach ($operationStates as $operationState) {
            $operation = \trim((string)($operationState['operation'] ?? ''));
            if (
                $operation !== ''
                && $operation !== 'build'
                && \in_array(\trim((string)($operationState['status'] ?? '')), ['queued', 'running'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $buildQueueInfo
     * @return array<string, mixed>|null
     */
    private function resolveBuildQueueRowForAutoResume(AiSiteAgentSession $session, ?array $buildQueueInfo): ?array
    {
        $queueId = 0;
        if (\is_array($buildQueueInfo['snapshot'] ?? null)) {
            $queueId = (int)($buildQueueInfo['queue_id'] ?? $buildQueueInfo['snapshot']['queue_id'] ?? 0);
        }
        if ($queueId > 0) {
            $row = $this->findAiSiteOperationQueueRow($session, 'build', $queueId);
            if (\is_array($row) && $row !== []) {
                return $row;
            }
        }

        $row = $this->findAiSiteOperationQueueRow($session, 'build');
        return \is_array($row) && $row !== [] ? $row : null;
    }

    /**
     * @param array<string, mixed>|null $queueRow
     * @param array<string, mixed> $activeOperation
     */
    private function resolveQueueExecutionToken(?array $queueRow, array $activeOperation): string
    {
        $content = $queueRow['content'] ?? null;
        if (\is_string($content)) {
            $decoded = \json_decode($content, true);
            $content = \is_array($decoded) ? $decoded : [];
        }
        if (!\is_array($content)) {
            $content = [];
        }
        foreach ([
            $activeOperation['execution_token'] ?? '',
            $content['execution_token'] ?? '',
            $content['token'] ?? '',
        ] as $candidate) {
            $token = \trim((string)$candidate);
            if ($token !== '') {
                return $token;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function buildAutoResumeBuildOperation(
        AiSiteAgentSession $session,
        array $existing,
        string $executionToken,
        int $queueId,
        string $status,
        string $reason
    ): array {
        $details = \is_array($existing['details'] ?? null) ? $existing['details'] : [];

        return \array_replace($this->buildOperationQueueEnvelope($session, 'build', $executionToken, $status), $existing, [
            'operation' => 'build',
            'execution_token' => $executionToken,
            'queue_id' => $queueId,
            'status' => $status,
            'message' => (string)__('Detected unfinished build tasks; resuming the build queue from remaining tasks.'),
            'updated_at' => \date('Y-m-d H:i:s'),
            'details' => \array_replace($details, [
                'auto_resume_reason' => $reason,
                'resume_unfinished_tasks_only' => true,
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $activeOperation
     */
    private function getTaskPlanQueueRecoveryAction(array $activeOperation): string
    {
        $action = \trim((string)($activeOperation['task_plan_recovery_action'] ?? ''));
        if ($action !== '') {
            return $action;
        }

        $details = \is_array($activeOperation['details'] ?? null) ? $activeOperation['details'] : [];
        return \trim((string)($details['task_plan_recovery_action'] ?? ''));
    }

    /**
     * 第二阶段任务方案是否已在会话 scope 中具备可展示/可构建的实质内容（单一真相源）。
     *
     * 用于：队列自愈是否误判「方案为空」、以及下发给前端的 {@see buildWorkspaceState} `has_virtual_theme_plan`，
     * 避免 JS 仅靠局部字段猜测与后台恢复逻辑不一致。
     *
     * @param array<string, mixed> $scope normalizeScope 之后的会话 scope
     */
    /**
     * @param array<string, mixed> $scope
     */
    private function scopeHasPersistedStageOnePlan(array $scope): bool
    {
        foreach (['execution_blueprint_draft', 'execution_blueprint', 'plan_json', 'plan_structured'] as $key) {
            if (\is_array($scope[$key] ?? null) && $scope[$key] !== []) {
                return true;
            }
        }

        if (\trim((string)($scope['plan_markdown'] ?? '')) !== '') {
            return true;
        }

        $workbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
        foreach (['draft', 'confirmed', 'plan_json', 'structured_plan'] as $key) {
            if (\is_array($workbench[$key] ?? null) && $workbench[$key] !== []) {
                return true;
            }
        }

        return \trim((string)($workbench['draft_markdown'] ?? $workbench['confirmed_markdown'] ?? '')) !== '';
    }

    private function scopeHasPersistedStageTwoTaskPlan(array $scope): bool
    {
        if ((int)($scope['task_plan_confirmed'] ?? 0) === 1) {
            return true;
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $draft = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
        $draftMarkdown = \trim((string)($virtualThemePlan['draft_markdown'] ?? ''));
        $taskPlanMarkdown = \trim((string)($scope['task_plan_markdown'] ?? ''));
        $taskPlanStructured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];
        $confirmed = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
        $confirmedMarkdown = \trim((string)($virtualThemePlan['confirmed_markdown'] ?? ''));
        $confirmedAt = \trim((string)($virtualThemePlan['confirmed_at'] ?? ''));
        $confirmedSignature = \trim((string)($virtualThemePlan['confirmed_signature'] ?? ''));

        $hasDraftArtifact = $draft !== []
            || $taskPlanStructured !== []
            || $draftMarkdown !== ''
            || $taskPlanMarkdown !== '';
        $hasConfirmedArtifact = $confirmed !== []
            || $confirmedMarkdown !== ''
            || $confirmedAt !== ''
            || $confirmedSignature !== '';
        if ($hasDraftArtifact || $hasConfirmedArtifact) {
            return true;
        }

        if ($this->virtualThemePlanRootHasTaskPayload($virtualThemePlan)) {
            return true;
        }

        $summary = \is_array($scope['task_plan_summary'] ?? null) ? $scope['task_plan_summary'] : [];
        if (((int)($summary['page_task_count'] ?? 0)) > 0 || ((int)($summary['shared_task_count'] ?? 0)) > 0) {
            return true;
        }
        if (\trim((string)($summary['signature'] ?? '')) !== '') {
            return true;
        }

        $directoryTree = \is_array($scope['task_plan_directory_tree'] ?? null) ? $scope['task_plan_directory_tree'] : [];
        if ($directoryTree !== []) {
            return true;
        }

        return $this->buildTaskService->hasConfirmedTaskPlanForBuild($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{
     *   structured:array<string, mixed>,
     *   markdown:string,
     *   signature:string,
     *   generated_at:string,
     *   confirmed_at:string
     * }
     */
    private function resolveStageTwoTaskPlanForConfirmation(array $scope): array
    {
        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $candidates = [
            \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [],
            \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [],
            \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [],
        ];
        if ($this->virtualThemePlanRootHasTaskPayload($virtualThemePlan)) {
            $candidates[] = $virtualThemePlan;
        }

        $structured = [];
        foreach ($candidates as $candidate) {
            if ($this->stageTwoTaskPlanPayloadHasTasks($candidate)) {
                $structured = $candidate;
                break;
            }
        }

        $markdown = '';
        foreach ([
            $virtualThemePlan['draft_markdown'] ?? '',
            $scope['task_plan_markdown'] ?? '',
            $virtualThemePlan['confirmed_markdown'] ?? '',
        ] as $markdownCandidate) {
            $markdown = \trim((string)$markdownCandidate);
            if ($markdown !== '') {
                break;
            }
        }
        if ($markdown === '' && $structured !== []) {
            $markdown = $this->buildStageTwoTaskPlanFallbackMarkdown($structured);
        }

        $summary = \is_array($scope['task_plan_summary'] ?? null) ? $scope['task_plan_summary'] : [];
        $signature = '';
        foreach ([
            $structured['signature'] ?? '',
            $structured['plan_signature'] ?? '',
            $virtualThemePlan['confirmed_signature'] ?? '',
            $virtualThemePlan['plan_signature'] ?? '',
            $summary['signature'] ?? '',
        ] as $signatureCandidate) {
            $signature = \trim((string)$signatureCandidate);
            if ($signature !== '') {
                break;
            }
        }
        if ($signature === '' && $structured !== []) {
            $signature = \sha1((string)\json_encode($structured, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        }

        $generatedAt = '';
        foreach ([
            $virtualThemePlan['draft_generated_at'] ?? '',
            $scope['task_plan_generated_at'] ?? '',
            $virtualThemePlan['confirmed_at'] ?? '',
        ] as $generatedAtCandidate) {
            $generatedAt = \trim((string)$generatedAtCandidate);
            if ($generatedAt !== '') {
                break;
            }
        }
        if ($generatedAt === '') {
            $generatedAt = \date('Y-m-d H:i:s');
        }

        $confirmedAt = \trim((string)($virtualThemePlan['confirmed_at'] ?? ''));
        if ($confirmedAt === '' || (int)($scope['task_plan_confirmed'] ?? 0) !== 1) {
            $confirmedAt = \date('Y-m-d H:i:s');
        }

        return [
            'structured' => $structured,
            'markdown' => $markdown,
            'signature' => $signature,
            'generated_at' => $generatedAt,
            'confirmed_at' => $confirmedAt,
        ];
    }

    /**
     * @param array<string, mixed> $taskPlan
     */
    private function stageTwoTaskPlanPayloadHasTasks(array $taskPlan): bool
    {
        $sharedTasks = \is_array($taskPlan['shared_tasks'] ?? null) ? $taskPlan['shared_tasks'] : [];
        foreach ($sharedTasks as $task) {
            if (\is_array($task) && $task !== []) {
                return true;
            }
        }

        $pageTasks = \is_array($taskPlan['page_tasks'] ?? null) ? $taskPlan['page_tasks'] : [];
        foreach ($pageTasks as $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            foreach ($tasks as $task) {
                if (\is_array($task) && $task !== []) {
                    return true;
                }
            }
        }

        $executionBlueprint = \is_array($taskPlan['execution_blueprint'] ?? null) ? $taskPlan['execution_blueprint'] : [];
        $executionTasks = \is_array($executionBlueprint['tasks'] ?? null) ? $executionBlueprint['tasks'] : [];
        foreach ($executionTasks as $task) {
            if (\is_array($task) && $task !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $taskPlan
     */
    private function buildStageTwoTaskPlanFallbackMarkdown(array $taskPlan): string
    {
        $sharedCount = \count(\array_values(\array_filter(
            \is_array($taskPlan['shared_tasks'] ?? null) ? $taskPlan['shared_tasks'] : [],
            static fn($task): bool => \is_array($task) && $task !== []
        )));
        $pageTaskCount = 0;
        $pageTasks = \is_array($taskPlan['page_tasks'] ?? null) ? $taskPlan['page_tasks'] : [];
        foreach ($pageTasks as $tasks) {
            if (!\is_array($tasks)) {
                continue;
            }
            $pageTaskCount += \count(\array_values(\array_filter(
                $tasks,
                static fn($task): bool => \is_array($task) && $task !== []
            )));
        }

        return '# 第二阶段任务方案' . "\n\n"
            . '- shared_tasks: ' . $sharedCount . "\n"
            . '- page_tasks: ' . $pageTaskCount . "\n";
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @return array<string, mixed>
     */
    private function buildTaskPlanStageEntryDecision(
        string $stage,
        array $scope,
        array $activeOperation,
        bool $hasStageTwoTaskPlan
    ): array {
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        $activeOperationStatus = \strtolower(\trim((string)($activeOperation['status'] ?? '')));
        $operationRunning = \in_array($activeOperationStatus, ['queued', 'running'], true);
        $planQueueRunning = $operationRunning && $activeOperationName === 'plan';
        $taskPlanQueueRunning = $operationRunning && $activeOperationName === 'task_plan';
        $buildQueueRunning = $operationRunning && $activeOperationName === 'build';
        $hasStageOneConfirmedPlan = $this->scopeCompatibilityService->hasConfirmedStageOnePlanForTaskPlan($scope);
        $isVisualEditStage = $this->scopeCompatibilityService->normalizeStage($stage) === AiSiteAgentSession::STAGE_VISUAL_EDIT;
        $shouldPromptGenerate = $isVisualEditStage
            && $hasStageOneConfirmedPlan
            && !$hasStageTwoTaskPlan
            && !$planQueueRunning
            && !$taskPlanQueueRunning
            && !$buildQueueRunning;

        return [
            'stage' => $this->scopeCompatibilityService->normalizeStage($stage),
            'is_visual_edit_stage' => $isVisualEditStage,
            'has_phase_one_confirmed_plan' => $hasStageOneConfirmedPlan,
            'has_phase_two_task_plan' => $hasStageTwoTaskPlan,
            'plan_queue_running' => $planQueueRunning,
            'task_plan_queue_running' => $taskPlanQueueRunning,
            'build_queue_running' => $buildQueueRunning,
            'operation_running' => $operationRunning,
            'active_operation' => $activeOperationName,
            'active_operation_status' => $activeOperationStatus,
            'should_prompt_generate' => $shouldPromptGenerate,
        ];
    }

    /**
     * 兼容 LLM/历史合并把 `page_tasks` / `shared_tasks` 放在 `virtual_theme_plan` 根级、未包进 draft/confirmed 的形态。
     *
     * @param array<string, mixed> $virtualThemePlan
     */
    private function virtualThemePlanRootHasTaskPayload(array $virtualThemePlan): bool
    {
        $shared = $virtualThemePlan['shared_tasks'] ?? null;
        if (\is_array($shared)) {
            foreach ($shared as $row) {
                if (\is_array($row) && $row !== []) {
                    return true;
                }
            }
        }

        $pageTasks = $virtualThemePlan['page_tasks'] ?? null;
        if (!\is_array($pageTasks) || $pageTasks === []) {
            return false;
        }
        foreach ($pageTasks as $items) {
            if (\is_array($items) && $items !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function isTaskPlanDraftMissing(array $normalized): bool
    {
        return !$this->scopeHasPersistedStageTwoTaskPlan($normalized);
    }

    /**
     * 一个会话在工作区中只应观察当前阶段对应的队列，避免提前触发后续阶段队列恢复/补建。
     *
     * @param array<string, mixed> $activeOperation
     */
    private function shouldInspectOperationQueueInWorkspace(string $currentStage, array $activeOperation, string $operation): bool
    {
        // 当前需求：一个会话同一时刻只跟踪“当前活跃操作”的唯一队列。
        // $currentStage 作为签名保留，便于后续扩展。
        $activeName = \trim((string)($activeOperation['operation'] ?? ''));
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        return $activeName === $operation && \in_array($activeStatus, ['queued', 'running', 'done', 'error', 'cancelled'], true);
    }

    /**
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed> $activeOperations
     * @return array<string, mixed>
     */
    private function resolveWorkspaceQueueOperationState(
        array $activeOperation,
        array $activeOperations,
        string $operation
    ): array {
        if (\trim((string)($activeOperation['operation'] ?? '')) === $operation) {
            return $activeOperation;
        }

        $operationState = \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [];
        if ($operationState === []) {
            return [];
        }
        if (\trim((string)($operationState['operation'] ?? '')) === '') {
            $operationState['operation'] = $operation;
        }

        return $operationState;
    }

    /**
     * 队列创建/连接观察流后，向前台打印可读的队列元数据（多行 detail_lines + 结构化快照）。
     *
     * @param array<string, mixed> $queueRow
     */
    private function emitQueueObserverQueueDetailEvents(SseWriter $sse, array $queueRow, string $operation): void
    {
        $this->queueObserverStreamService()->emitQueueDetailEvents($sse, $queueRow, $operation);
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
        return $this->queueObserverStreamService()->forwardObservedQueueSignals(
            $sse,
            $queueRow,
            $operation,
            $lastQueueProcess,
            $lastQueueResultLength,
            $lastQueueStatus,
            $lastQueuePid
        );
    }

    /**
     * Queue observers need a compact task-progress heartbeat. The queue row itself
     * is telemetry only; the authoritative task state remains in the session scope.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed>|null $queueRow
     */
    private function emitObservedBuildTaskProgressSnapshot(
        SseWriter $sse,
        array $scope,
        string $operation,
        ?array $queueRow,
        string &$lastSignature
    ): void {
        if ($operation !== 'build' || !$sse->isAlive()) {
            return;
        }

        $summary = $this->buildTaskService->summarize($scope);
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        $aiGenerating = \in_array($queueStatus, ['pending', 'queued', 'running'], true)
            || \in_array($activeStatus, ['queued', 'running'], true);

        if ((int)($summary['total'] ?? 0) <= 0 && !$aiGenerating) {
            return;
        }

        $signaturePayload = [
            'queue_id' => (int)($queueRow['queue_id'] ?? $activeOperation['queue_id'] ?? 0),
            'queue_status' => $queueStatus,
            'queue_pid' => (int)($queueRow['pid'] ?? 0),
            'active_status' => $activeStatus,
            'summary' => $summary,
            'ai_generating' => $aiGenerating,
        ];
        $signature = \sha1(\json_encode($signaturePayload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '');
        if ($signature === $lastSignature) {
            return;
        }
        $lastSignature = $signature;

        $message = $queueStatus === 'running'
            ? (string)__('AI 正在生成页面任务')
            : (string)__('AI 构建任务同步中');
        $progressPercent = isset($activeOperation['progress_percent']) ? (int)$activeOperation['progress_percent'] : $this->resolveTaskSummaryProgressPercent($summary);

        $payload = $this->buildTaskProgressSnapshotPayload(
            $summary,
            'build',
            $message,
            $progressPercent,
            $aiGenerating,
            $queueRow,
            $activeStatus
        );
        $sse->sendEvent('progress', $payload);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $queueRow
     * @return array<string, mixed>
     */
    private function buildTaskProgressSnapshotPayload(
        array $summary,
        string $operation,
        string $message,
        int $progressPercent,
        bool $aiGenerating,
        ?array $queueRow = null,
        string $activeStatus = ''
    ): array {
        $payload = [
            'message' => $message,
            'operation' => $operation,
            'progress_percent' => \max(0, \min(100, $progressPercent)),
            'progress_kind' => 'task_progress',
            'task_progress' => $summary,
            'task_summary' => $summary,
            'build_task_summary' => $summary,
            'ai_generating' => $aiGenerating,
            'active_operation_status' => $activeStatus,
        ];

        if (\is_array($queueRow) && $queueRow !== []) {
            $payload['queue_id'] = (int)($queueRow['queue_id'] ?? 0);
            $payload['queue_status'] = \trim((string)($queueRow['status'] ?? ''));
            $payload['queue_snapshot'] = $this->buildQueueObserverPublicSnapshot($queueRow);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function emitBuildTaskProgressSnapshotFromScope(
        SseWriter $sse,
        array $scope,
        string $operation,
        string $message,
        int $progressPercent = 0,
        string $activeStatus = 'running'
    ): void {
        if (!$sse->isAlive()) {
            return;
        }

        $summary = $this->buildTaskService->summarize($scope);
        if ((int)($summary['total'] ?? 0) <= 0) {
            return;
        }

        $sse->sendEvent('progress', $this->buildTaskProgressSnapshotPayload(
            $summary,
            $operation,
            $message,
            $progressPercent,
            \in_array($activeStatus, ['queued', 'running'], true) && $progressPercent < 100,
            null,
            $activeStatus
        ));
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function resolveTaskSummaryProgressPercent(array $summary): int
    {
        $total = (int)($summary['total'] ?? 0);
        if ($total <= 0) {
            return 0;
        }

        return (int)\floor(((int)($summary['done'] ?? 0) / $total) * 100);
    }

    private function shouldSuppressObservedQueueProcessMirror(string $operation): bool
    {
        return $this->queueObserverHelperService()->shouldSuppressProcessMirror($operation);
    }

    private function shouldSkipObservedQueueResultLine(string $operation, string $line): bool
    {
        return $this->queueObserverHelperService()->shouldSkipResultLine($operation, $line);
    }

    /**
     * @param array<string, mixed>|null $queueRow
     */
    private function resolveObservedQueueMessage(?array $queueRow, bool $success): string
    {
        return $this->queueObserverHelperService()->resolveMessage($queueRow, $success);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function filterObservedOperationEvents(
        array $events,
        string $operation,
        string $startedAtRaw,
        int $afterEventId,
        array $correlation = []
    ): array {
        return $this->queueObserverHelperService()->filterOperationEvents(
            $events,
            $operation,
            $startedAtRaw,
            $afterEventId,
            $correlation
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isObservedOperationEventRelevant(array $event, string $operation, int $startedAtTs): bool
    {
        return $this->queueObserverHelperService()->isOperationEventRelevant($event, $operation, $startedAtTs);
    }

    /**
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $queueRow
     * @return array<string, mixed>
     */
    private function buildObservedOperationEventCorrelation(
        string $operation,
        string $executionToken,
        array $activeOperation,
        ?array $queueRow
    ): array {
        $queueContent = $this->decodeAiSiteQueueRowContent($queueRow);
        $operationToken = \trim((string)($activeOperation['execution_token'] ?? $activeOperation['token'] ?? ''));
        if ($operationToken === '') {
            $operationToken = \trim($executionToken);
        }
        if ($operationToken === '') {
            $operationToken = \trim((string)($queueContent['execution_token'] ?? $queueContent['token'] ?? ''));
        }

        $queueId = (int)($activeOperation['queue_id'] ?? 0);
        if ($queueId <= 0 && \is_array($queueRow)) {
            $queueId = (int)($queueRow['queue_id'] ?? 0);
        }

        $jobKey = \trim((string)($activeOperation['job_key'] ?? $queueContent['job_key'] ?? ''));
        $jobType = \trim((string)($activeOperation['job_type'] ?? $queueContent['job_type'] ?? ''));
        if ($jobType === '') {
            $jobType = $this->resolveAiSiteQueueJobType($operation);
        }

        return [
            'execution_token' => $operationToken,
            'queue_id' => $queueId,
            'job_key' => $jobKey,
            'job_type' => $jobType,
            'require_error_correlation' => $this->supportsBackgroundOperation($operation)
                && ($operationToken !== '' || $queueId > 0 || $jobKey !== '' || $jobType !== ''),
        ];
    }

    /**
     * @param array<string, mixed>|null $queueRow
     * @return array<string, mixed>
     */
    private function decodeAiSiteQueueRowContent(?array $queueRow): array
    {
        if (!\is_array($queueRow) || $queueRow === []) {
            return [];
        }

        $content = $queueRow['content'] ?? null;
        if (\is_array($content)) {
            return $content;
        }
        if (!\is_string($content) || \trim($content) === '') {
            return [];
        }

        $decoded = \json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function forwardObservedOperationEvents(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $events,
        int $lastEventId,
        ?array $settledQueueRow = null
    ): int {
        foreach ($events as $event) {
            $eventType = \trim((string)($event['event_type'] ?? ''));
            $eventName = $this->mapObservedOperationEventName($eventType);
            if ($eventName === '') {
                continue;
            }
            $eventId = (int)($event['event_id'] ?? 0);
            if ($this->shouldDeferObservedErrorEventForInProgressQueue($eventName, $settledQueueRow)) {
                break;
            }
            if ($this->shouldSuppressObservedErrorEventForDoneQueue($event, $eventName, $settledQueueRow)) {
                if ($eventId > $lastEventId) {
                    $lastEventId = $eventId;
                }
                continue;
            }
            $payload = $this->buildObservedOperationEventPayload($session, $adminId, $eventType, $event);
            if ($payload === null) {
                continue;
            }
            $sse->sendEvent($eventName, $payload, $eventId > 0 ? $eventId : null);
            if ($eventId > $lastEventId) {
                $lastEventId = $eventId;
            }
        }

        return $lastEventId;
    }

    /**
     * Queue rows are the source of truth for background operations. If an error
     * event is observed while the queue row is still non-terminal, keep it
     * pending until the queue reports done/error; otherwise the browser can show
     * a false failure during the queue status write-back window.
     *
     * @param array<string, mixed>|null $queueRow
     */
    private function shouldDeferObservedErrorEventForInProgressQueue(string $eventName, ?array $queueRow): bool
    {
        if ($eventName !== 'error') {
            return false;
        }

        $queueStatus = \trim((string)($queueRow['status'] ?? ''));

        return \in_array($queueStatus, ['pending', 'queued', 'running'], true);
    }

    /**
     * A settled queue row is the source of truth for observer SSE. Replaying an
     * older operation_failed/error event after the queue has completed makes the
     * browser show a false failure before the final done payload arrives.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed>|null $settledQueueRow
     */
    private function shouldSuppressObservedErrorEventForDoneQueue(
        array $event,
        string $eventName,
        ?array $settledQueueRow
    ): bool {
        if ($eventName !== 'error') {
            return false;
        }
        $queueStatus = \trim((string)($settledQueueRow['status'] ?? ''));
        if ($queueStatus !== 'done') {
            return false;
        }
        $eventType = \trim((string)($event['event_type'] ?? ''));

        return $eventType === '' || \in_array($eventType, ['error', 'operation_failed'], true);
    }

    private function mapObservedOperationEventName(string $eventType): string
    {
        return $this->queueObserverHelperService()->mapOperationEventName($eventType);
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
            'progress' => $this->buildObservedProgressPayload($payload),
            'ai_raw_chunk' => [
                'message' => (string)__('AI 正在生成内容，正文流已从队列 SSE 中省略。'),
                'operation' => (string)($payload['operation'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
                'suppressed_content' => true,
            ],
            'plan_chunk' => [
                'message' => (string)__('阶段方案内容已生成并写入草案，正文流已从队列 SSE 中省略。'),
                'operation' => (string)($payload['operation'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
                'suppressed_content' => true,
            ],
            'chunk' => [
                'message' => (string)__('阶段内容已更新，正文流已从队列 SSE 中省略。'),
                'operation' => (string)($payload['operation'] ?? ''),
                'region' => (string)($details['region'] ?? $payload['region'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
                'suppressed_content' => true,
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
            'operation_progress' => $this->buildObservedProgressPayload($payload),
            'ai_chunk' => [
                'message' => (string)__('AI 正在生成内容，正文流已从队列 SSE 中省略。'),
                'operation' => (string)($payload['operation'] ?? ''),
                'region' => (string)($details['region'] ?? $payload['region'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
                'suppressed_content' => true,
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
     * @return array<string, mixed>
     */
    private function buildObservedProgressPayload(array $payload): array
    {
        $result = [
            'message' => (string)($payload['message'] ?? ''),
            'operation' => (string)($payload['operation'] ?? ''),
            'page_type' => (string)($payload['page_type'] ?? ''),
            'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
        ];

        foreach ([
            'progress_kind',
            'ai_generating',
            'active_operation_status',
            'queue_id',
            'queue_status',
            'queue_snapshot',
            'task_progress',
            'task_summary',
            'build_task_summary',
        ] as $key) {
            if (\array_key_exists($key, $payload)) {
                $result[$key] = $payload[$key];
            }
        }

        return $result;
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

    private function shouldRejectTaskPlanGenerationSource(
        array $scope,
        string $generationSource,
        bool $allowDeterministicFallback = false
    ): bool {
        if ($generationSource === 'ai') {
            return false;
        }

        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            return false;
        }

        return !($allowDeterministicFallback && $generationSource === 'deterministic');
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
     *   task_plan_queue_info:array<string, mixed>|null,
     *   build_queue_info:array<string, mixed>|null,
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
        $normalized = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $this->scopeCompatibilityService->normalizeStage($session->getStage()))
        );
        $normalized = $this->scopeCompatibilityService->normalizeConfirmedPlanFlag($normalized);
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
        $normalized = $this->normalizeTaskPlanConfirmationForBuild($normalized);
        $shouldPersistCompactedTaskPlan = $this->shouldCompactConfirmedTaskPlanScope($normalized);
        if ($shouldPersistCompactedTaskPlan) {
            $normalized = $this->compactConfirmedTaskPlanScope($normalized);
        }
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
        $virtualPagesByType = $this->scopeCompatibilityService->buildVirtualPagesByType(
            $normalized['page_types'],
            $normalized,
            false
        );
        $virtualPagesByType = $this->decorateVirtualPagesWithUrls($session->getPublicId(), $virtualThemeId, $virtualPagesByType);
        $this->logHotPathStage('build_workspace_state.virtual_pages', $virtualPagesStartedAt, [
            'public_id' => $session->getPublicId(),
            'page_type_count' => \count($normalized['page_types']),
        ]);
        $normalized['virtual_pages_by_type'] = $virtualPagesByType;
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

        // Draft virtual-theme workspaces render from session scope; materialized Page URLs are only authoritative
        // after publishing or for non-virtual-theme tracks.
        if ($this->shouldUseWorkspacePreviewUrls($session, $workspaceTrack, $previewPageType)) {
            $normalized = \array_replace($normalized, $prePublishVisualUrls);
        } elseif ((int)$normalized['preview_page_id'] > 0) {
            $normalized = \array_replace(
                $normalized,
                $this->visualUrlService->resolveUrls((int)$normalized['preview_page_id'], $virtualThemeId)
            );
        } else {
            $normalized = \array_replace($normalized, $prePublishVisualUrls);
        }

        $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $activeOperation = \is_array($normalized['active_operation'] ?? null) ? $normalized['active_operation'] : [];
        $activeOperations = \is_array($normalized['active_operations'] ?? null) ? $normalized['active_operations'] : [];
        $planOperation = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, 'plan');
        $taskPlanOperation = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, 'task_plan');
        $buildOperation = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, 'build');
        $planQueueInfo = $this->shouldInspectOperationQueueInWorkspace($stage, $planOperation, 'plan')
            ? $this->buildPlanStageQueueInfoPayload($session, $planOperation)
            : null;
        $taskPlanQueueInfo = $this->shouldInspectOperationQueueInWorkspace($stage, $taskPlanOperation, 'task_plan')
            ? $this->buildOperationStageQueueInfoPayload($session, $taskPlanOperation, 'task_plan')
            : null;
        $buildQueueInfo = $this->shouldInspectOperationQueueInWorkspace($stage, $buildOperation, 'build')
            ? $this->buildOperationStageQueueInfoPayload($session, $buildOperation, 'build')
            : null;
        $activeOperation = $this->initializeTaskPlanActiveOperationFromQueueInfo($session, $activeOperation, $taskPlanQueueInfo);
        foreach ([
            'plan' => [$planOperation, $planQueueInfo],
            'task_plan' => [$taskPlanOperation, $taskPlanQueueInfo],
            'build' => [$buildOperation, $buildQueueInfo],
        ] as $queueOperation => [$operationState, $queueInfo]) {
            $operationState = $this->reconcileActiveOperationWithQueueInfo($operationState, $queueInfo, $queueOperation);
            if ($operationState !== []) {
                $activeOperations[$queueOperation] = \array_replace(
                    \is_array($activeOperations[$queueOperation] ?? null) ? $activeOperations[$queueOperation] : [],
                    $operationState,
                    ['operation' => $queueOperation]
                );
                if (
                    \trim((string)($activeOperation['operation'] ?? '')) === $queueOperation
                    || $this->executionTokenMatches(
                        (string)($activeOperation['execution_token'] ?? ''),
                        (string)($operationState['execution_token'] ?? '')
                    )
                ) {
                    $activeOperation = $operationState;
                }
            }
        }
        $normalized['active_operations'] = $activeOperations;
        $normalized['active_operation'] = $activeOperation;
        [
            'normalized' => $normalized,
            'active_operation' => $activeOperation,
            'task_plan_queue_info' => $taskPlanQueueInfo,
        ] = $this->autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing(
            $session,
            $adminId,
            $stage,
            $normalized,
            $activeOperation,
            $taskPlanQueueInfo
        );
        [
            'normalized' => $normalized,
            'active_operation' => $activeOperation,
            'build_queue_info' => $buildQueueInfo,
            'task_summary' => $taskSummary,
        ] = $this->autoResumeBuildQueueWhenTasksIncomplete(
            $session,
            $adminId,
            $stage,
            $normalized,
            $activeOperation,
            $buildQueueInfo,
            $taskSummary
        );
        $workspaceEntryQueueNotice = $this->buildWorkspaceEntryQueueNotice($activeOperation, [
            'plan' => $planQueueInfo,
            'task_plan' => $taskPlanQueueInfo,
            'build' => $buildQueueInfo,
        ]);
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
        $hasStageOnePlan = $this->scopeCompatibilityService->hasPersistedStageOnePlan($normalized);
        $virtualThemePlan = \is_array($normalized['virtual_theme_plan'] ?? null) ? $normalized['virtual_theme_plan'] : [];
        $hasVirtualThemePlan = $this->scopeHasPersistedStageTwoTaskPlan($normalized);
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

        $taskPlanStageEntry = $this->buildTaskPlanStageEntryDecision(
            $stage,
            $normalized,
            $activeOperation,
            $hasVirtualThemePlan
        );

        if ($persist || $shouldPersistCompactedTaskPlan) {
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
            'has_stage_one_plan' => $hasStageOnePlan,
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
            'task_plan_generation_progress' => \is_array($normalized['task_plan_generation_progress'] ?? null) ? $normalized['task_plan_generation_progress'] : [],
            'task_plan_generation_summary' => \is_array($normalized['task_plan_generation_summary'] ?? null) ? $normalized['task_plan_generation_summary'] : [],
            'task_plan_generation_last_error' => \is_array($normalized['task_plan_generation_last_error'] ?? null) ? $normalized['task_plan_generation_last_error'] : [],
            'has_virtual_theme_plan' => $hasVirtualThemePlan,
            'task_plan_stage_entry' => $taskPlanStageEntry,
            'has_pending_build_tasks' => (int)($taskSummary['pending'] ?? 0) > 0,
            'task_plan_sse_url' => $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-task-plan-sse'),
            'mutate_task_plan_task_url' => $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-mutate-task-plan-task'),
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
            'task_plan_queue_info' => $taskPlanQueueInfo,
            'build_queue_info' => $buildQueueInfo,
            'workspace_entry_notice' => $workspaceEntryQueueNotice,
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

        return $this->decorateWorkspaceStateWithPollingPayload($result);
    }

    /**
     * Polling responses keep the full workspace state for existing UI hydration,
     * but expose the same compact status-envelope fields that SSE state payloads
     * carry. This keeps SSE and `post-workspace-snapshot` aligned to one truth
     * source without replacing the legacy polling response shape.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function decorateWorkspaceStateWithPollingPayload(array $state): array
    {
        return \array_replace($state, $this->buildWorkspaceStatusEnvelope($state, 'poller'));
    }

    /**
     * Confirmation requests only need a compact state patch for the current UI.
     * Sending the full workspace snapshot here resends large plan/scope/event
     * payloads that the browser already has in memory.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildWorkspaceConfirmPayload(array $state, string $type): array
    {
        $payload = \array_replace([
            'public_id' => (string)($state['public_id'] ?? ''),
            'workspace_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => (string)($state['public_id'] ?? '')]),
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
            'active_operation' => \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [],
            'build_task_summary' => \is_array($state['build_task_summary'] ?? null) ? $state['build_task_summary'] : [],
            'build_summary' => \is_array($state['build_summary'] ?? null) ? $state['build_summary'] : [],
            'pending_generation_page_types' => \is_array($state['pending_generation_page_types'] ?? null) ? $state['pending_generation_page_types'] : [],
            'plan_queue_info' => \is_array($state['plan_queue_info'] ?? null) ? $state['plan_queue_info'] : null,
            'task_plan_queue_info' => \is_array($state['task_plan_queue_info'] ?? null) ? $state['task_plan_queue_info'] : null,
            'build_queue_info' => \is_array($state['build_queue_info'] ?? null) ? $state['build_queue_info'] : null,
            'task_plan_generation_progress' => \is_array($state['task_plan_generation_progress'] ?? null) ? $state['task_plan_generation_progress'] : [],
            'task_plan_generation_summary' => \is_array($state['task_plan_generation_summary'] ?? null) ? $state['task_plan_generation_summary'] : [],
            'task_plan_generation_last_error' => \is_array($state['task_plan_generation_last_error'] ?? null) ? $state['task_plan_generation_last_error'] : [],
        ], $this->buildWorkspaceStatusEnvelope($state, 'confirm'));

        if ($type === 'plan') {
            $payload['plan_confirmed'] = (int)($state['plan_confirmed'] ?? 0);
            $payload['plan_confirmed_at'] = (string)($state['plan_confirmed_at'] ?? '');
            $payload['confirmed_plan_signature'] = (string)($state['confirmed_plan_signature'] ?? '');
            $payload['has_stage_one_plan'] = !empty($state['has_stage_one_plan']);
            $payload['has_execution_blueprint'] = !empty($state['has_execution_blueprint']);
            $payload['plan_sse_url'] = (string)($state['plan_sse_url'] ?? '');
            $payload['refine_plan_page_url'] = (string)($state['refine_plan_page_url'] ?? '');
            return $payload;
        }

        if ($type === 'task_plan') {
            $taskPlan = \is_array($state['task_plan'] ?? null) ? $state['task_plan'] : [];
            $virtualThemePlan = \is_array($taskPlan['virtual_theme_plan'] ?? null) ? $taskPlan['virtual_theme_plan'] : [];
            $payload['plan_confirmed'] = (int)($state['plan_confirmed'] ?? 0);
            $payload['task_plan_confirmed'] = (int)($state['task_plan_confirmed'] ?? 0);
            $payload['task_plan_confirmed_at'] = (string)($state['task_plan_confirmed_at'] ?? ($virtualThemePlan['confirmed_at'] ?? ''));
            $payload['has_virtual_theme_plan'] = !empty($state['has_virtual_theme_plan']);
            $payload['has_pending_build_tasks'] = !empty($state['has_pending_build_tasks']);
            $payload['task_plan_sse_url'] = (string)($state['task_plan_sse_url'] ?? '');
            $payload['task_plan'] = [
                'virtual_theme_plan' => [
                    'confirmed_at' => (string)($virtualThemePlan['confirmed_at'] ?? ''),
                    'confirmed_signature' => (string)($virtualThemePlan['confirmed_signature'] ?? ''),
                    'plan_signature' => (string)($virtualThemePlan['plan_signature'] ?? ''),
                ],
            ];
        }

        return $payload;
    }

    /**
     * Long-running operation start/status endpoints return only the state patch
     * the browser needs to attach to queue/SSE progress. Full plan/task content
     * is already present in the page state or arrives through the phase streams.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildWorkspaceOperationPayload(array $state, string $operation): array
    {
        $payload = \array_replace([
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
            'preview_page_id' => (int)($state['preview_page_id'] ?? 0),
            'preview_page_type' => (string)($state['preview_page_type'] ?? ''),
            'preview_full_url' => (string)($state['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($state['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($state['visual_edit_url'] ?? ''),
            'pre_publish_visual_urls' => \is_array($state['pre_publish_visual_urls'] ?? null) ? $state['pre_publish_visual_urls'] : [],
            'pagebuilder_pages_by_type' => \is_array($state['pagebuilder_pages_by_type'] ?? null) ? $state['pagebuilder_pages_by_type'] : [],
            'preview_page_options' => \is_array($state['preview_page_options'] ?? null) ? $state['preview_page_options'] : [],
            'active_operation' => \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [],
            'plan_confirmed' => (int)($state['plan_confirmed'] ?? 0),
            'plan_confirmed_at' => (string)($state['plan_confirmed_at'] ?? ''),
            'confirmed_plan_signature' => (string)($state['confirmed_plan_signature'] ?? ''),
            'task_plan_confirmed' => (int)($state['task_plan_confirmed'] ?? 0),
            'task_plan_confirmed_at' => (string)($state['task_plan_confirmed_at'] ?? ''),
            'has_stage_one_plan' => !empty($state['has_stage_one_plan']),
            'has_execution_blueprint' => !empty($state['has_execution_blueprint']),
            'has_virtual_theme_plan' => !empty($state['has_virtual_theme_plan']),
            'has_pending_build_tasks' => !empty($state['has_pending_build_tasks']),
            'plan_sse_url' => (string)($state['plan_sse_url'] ?? ''),
            'task_plan_sse_url' => (string)($state['task_plan_sse_url'] ?? ''),
            'refine_plan_page_url' => (string)($state['refine_plan_page_url'] ?? ''),
            'plan_queue_info' => \is_array($state['plan_queue_info'] ?? null) ? $state['plan_queue_info'] : null,
            'task_plan_queue_info' => \is_array($state['task_plan_queue_info'] ?? null) ? $state['task_plan_queue_info'] : null,
            'build_queue_info' => \is_array($state['build_queue_info'] ?? null) ? $state['build_queue_info'] : null,
            'build_task_summary' => \is_array($state['build_task_summary'] ?? null) ? $state['build_task_summary'] : [],
            'build_summary' => \is_array($state['build_summary'] ?? null) ? $state['build_summary'] : [],
            'pending_generation_page_types' => \is_array($state['pending_generation_page_types'] ?? null) ? $state['pending_generation_page_types'] : [],
            'task_plan_generation_progress' => \is_array($state['task_plan_generation_progress'] ?? null) ? $state['task_plan_generation_progress'] : [],
            'task_plan_generation_summary' => \is_array($state['task_plan_generation_summary'] ?? null) ? $state['task_plan_generation_summary'] : [],
            'task_plan_generation_last_error' => \is_array($state['task_plan_generation_last_error'] ?? null) ? $state['task_plan_generation_last_error'] : [],
            'workspace_entry_queue_notice' => \is_array($state['workspace_entry_queue_notice'] ?? null) ? $state['workspace_entry_queue_notice'] : [],
        ], $this->buildWorkspaceStatusEnvelope($state, 'queue'));

        $payload['response_mode'] = 'compact_operation';
        $payload['response_operation'] = $operation;

        return $payload;
    }

    /**
     * @param array<string, mixed> $scope
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
        $payload = \array_replace([
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
            'plan_confirmed' => (int)($state['plan_confirmed'] ?? 0),
            'plan_confirmed_at' => (string)($state['plan_confirmed_at'] ?? ''),
            'has_stage_one_plan' => !empty($state['has_stage_one_plan']),
            'has_execution_blueprint' => !empty($state['has_execution_blueprint']),
            'task_plan_confirmed' => (int)($state['task_plan_confirmed'] ?? 0),
            'task_plan_confirmed_at' => (string)($state['task_plan_confirmed_at'] ?? ''),
            'has_virtual_theme_plan' => !empty($state['has_virtual_theme_plan']),
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
            'task_plan_queue_info' => \is_array($state['task_plan_queue_info'] ?? null) ? $state['task_plan_queue_info'] : null,
            'build_queue_info' => \is_array($state['build_queue_info'] ?? null) ? $state['build_queue_info'] : null,
            'build_task_summary' => \is_array($state['build_task_summary'] ?? null) ? $state['build_task_summary'] : [],
            'build_summary' => \is_array($state['build_summary'] ?? null) ? $state['build_summary'] : [],
            'pending_generation_page_types' => \is_array($state['pending_generation_page_types'] ?? null) ? $state['pending_generation_page_types'] : [],
            'task_plan_generation_progress' => \is_array($state['task_plan_generation_progress'] ?? null) ? $state['task_plan_generation_progress'] : [],
            'task_plan_generation_summary' => \is_array($state['task_plan_generation_summary'] ?? null) ? $state['task_plan_generation_summary'] : [],
            'task_plan_generation_last_error' => \is_array($state['task_plan_generation_last_error'] ?? null) ? $state['task_plan_generation_last_error'] : [],
        ], $this->buildWorkspaceStatusEnvelope($state, 'queue'));

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
     * @param array<string, mixed> $state
     * @return array{
     *   job_key:string,
     *   job_type:string,
     *   status:string,
     *   event_id:int,
     *   seq_no:int,
     *   cursor:string,
     *   source:string,
     *   progress_percent:int,
     *   session_public_id:string,
     *   context_hash:string,
     *   state_fingerprint:string,
     *   token_usage:array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string,mixed>|null},
     *   progress_kind:string,
     *   updated_at:string
     * }
     */
    private function buildWorkspaceStatusEnvelope(array $state, string $source): array
    {
        return $this->workspaceStateHelperService()->buildStatusEnvelope($state, $source);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function selectWorkspaceStatusQueueInfo(array $state, string $operation): array
    {
        return $this->workspaceStateHelperService()->selectStatusQueueInfo($state, $operation);
    }

    private function normalizeWorkspaceEnvelopeStatus(string $status): string
    {
        return $this->workspaceStateHelperService()->normalizeEnvelopeStatus($status);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     */
    private function resolveWorkspaceEnvelopeProgressPercent(array $state, array $activeOperation, string $status): int
    {
        return $this->workspaceStateHelperService()->resolveEnvelopeProgressPercent($state, $activeOperation, $status);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     */
    private function resolveWorkspaceEnvelopeCursor(array $state, array $activeOperation): string
    {
        return $this->workspaceStateHelperService()->resolveEnvelopeCursor($state, $activeOperation);
    }

    /**
     * @param array<string, mixed> $queueSnapshot
     * @param array<string, mixed> $queueInfo
     * @param array<string, mixed> $activeOperation
     * @return array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string,mixed>|null}
     */
    private function resolveWorkspaceEnvelopeTokenUsage(array $queueSnapshot, array $queueInfo, array $activeOperation): array
    {
        return $this->workspaceStateHelperService()->resolveEnvelopeTokenUsage($queueSnapshot, $queueInfo, $activeOperation);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     */
    private function resolveWorkspaceProgressKind(array $state, array $activeOperation): string
    {
        return $this->workspaceStateHelperService()->resolveProgressKind($state, $activeOperation);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed> $queueSnapshot
     */
    private function resolveWorkspaceEnvelopeUpdatedAt(array $state, array $activeOperation, array $queueSnapshot): string
    {
        return $this->workspaceStateHelperService()->resolveEnvelopeUpdatedAt($state, $activeOperation, $queueSnapshot);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function buildWorkspaceStateFingerprint(array $state): string
    {
        return $this->workspaceStateHelperService()->buildStateFingerprint($state);
    }

    /**
     * @param list<string> $pageTypes
     * @return list<string>
     */
    private function normalizeWorkspaceSsePageTypes(array $pageTypes, string $fallbackPageType = ''): array
    {
        return $this->workspaceStateHelperService()->normalizeSsePageTypes($pageTypes, $fallbackPageType);
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $pageTypes
     * @return array<string, mixed>
     */
    private function selectWorkspaceVirtualPagesForSse(array $state, array $pageTypes): array
    {
        return $this->workspaceStateHelperService()->selectVirtualPagesForSse($state, $pageTypes);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function pruneWorkspaceEventsForSse(array $rows, int $limit = 6): array
    {
        return $this->workspaceStateHelperService()->pruneEventsForSse($rows, $limit);
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function filterWorkspaceEventsByStage(array $rows, string $streamStage): array
    {
        return $this->workspaceStateHelperService()->filterEventsByStage($rows, $streamStage);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function filterWorkspaceSnapshotByStage(array $snapshot, string $streamStage): array
    {
        return $this->workspaceStateHelperService()->filterSnapshotByStage($snapshot, $streamStage);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function workspaceEventMatchesStage(array $event, string $streamStage): bool
    {
        return $this->workspaceStateHelperService()->eventMatchesStage($event, $streamStage);
    }

    private function normalizeWorkspaceStreamStage(string $stage): string
    {
        return $this->workspaceStateHelperService()->normalizeStreamStage($stage);
    }

    /**
     * @param array<int, mixed> $events
     */
    private function resolveWorkspaceLastEventId(array $events): int
    {
        return $this->workspaceStateHelperService()->resolveLastEventId($events);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function shouldCompactConfirmedTaskPlanScope(array $scope): bool
    {
        if ((int)($scope['task_plan_confirmed'] ?? 0) !== 1) {
            return false;
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        $confirmed = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
        if ($confirmed === []) {
            return false;
        }

        $draft = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
        if ($draft !== [] && $draft == $confirmed) {
            return true;
        }

        if ($this->hasReusableConfirmedBuildBlueprintForCompaction($scope)) {
            foreach ([
                'execution_blueprint',
                'shared_tasks',
                'page_tasks',
                'shared_block_tasks',
                'page_block_tasks',
                'virtual_theme_build_tree',
            ] as $key) {
                if (\is_array($confirmed[$key] ?? null) && $confirmed[$key] !== []) {
                    return true;
                }
            }

            $planWorkbench = \is_array($scope['plan_workbench'] ?? null) ? $scope['plan_workbench'] : [];
            $workbenchConfirmed = \is_array($planWorkbench['confirmed'] ?? null) ? $planWorkbench['confirmed'] : [];
            if (\is_array($workbenchConfirmed['structured_plan'] ?? null) || \is_array($workbenchConfirmed['plan_json'] ?? null)) {
                return true;
            }

            foreach (\is_array($scope['build_tasks'] ?? null) ? $scope['build_tasks'] : [] as $taskState) {
                if (!\is_array($taskState)) {
                    continue;
                }
                foreach ([
                    'runtime_context',
                    'plan_context',
                    'task_script',
                    'block_task',
                    'implementation_contract',
                ] as $key) {
                    if (\array_key_exists($key, $taskState)) {
                        return true;
                    }
                }
            }
        }

        $confirmedMarkdown = \trim((string)($virtualThemePlan['confirmed_markdown'] ?? ''));
        $draftMarkdown = \trim((string)($virtualThemePlan['draft_markdown'] ?? ''));

        return $confirmedMarkdown !== ''
            && $draftMarkdown !== ''
            && $draftMarkdown === $confirmedMarkdown;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasReusableConfirmedBuildBlueprintForCompaction(array $scope): bool
    {
        $buildBlueprint = \is_array($scope['build_blueprint'] ?? null) ? $scope['build_blueprint'] : [];
        $tasks = \is_array($buildBlueprint['tasks'] ?? null) ? $buildBlueprint['tasks'] : [];

        return (string)($buildBlueprint['source'] ?? '') === 'stage2_confirmed_task_plan'
            && \trim((string)($buildBlueprint['signature'] ?? '')) !== ''
            && $tasks !== [];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactConfirmedTaskPlanScope(array $scope): array
    {
        if (!$this->shouldCompactConfirmedTaskPlanScope($scope)) {
            return $scope;
        }
        return $this->workspaceStateHelperService()->compactConfirmedTaskPlanScope($scope);
    }

    /**
     * HTML 工作台首屏只需要轻量状态，避免把大块 scope/events 重复塞进模板。
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function pruneWorkspaceStateForView(array $state): array
    {
        return $this->workspaceStateHelperService()->pruneStateForView($state);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function pruneWorkspaceScopeForView(array $scope): array
    {
        return $this->workspaceStateHelperService()->pruneScopeForView($scope);
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
        $scope = $session->compactScopeForStorage($scope);
        $nextScopeJson = $this->encodeCompactJson($scope);
        $currentScopeJson = (string)$session->getData(AiSiteAgentSession::schema_fields_SCOPE_JSON);
        if ($nextScopeJson !== '' && $currentScopeJson !== $nextScopeJson) {
            $this->sessionService->replaceScopeJson($session->getId(), $adminId, $nextScopeJson);
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
        string $workspaceStatus = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
        array $operationDetails = []
    ): array {
        $stage = $this->scopeCompatibilityService->normalizeStage($stage);
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $stage)
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $forceTaskPlanRebuild = $this->isTaskPlanSchemeRebuildRequest($operation, $scopePatch, $operationDetails);
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
            if ($forceTaskPlanRebuild && $runningOperation === 'task_plan') {
                $scope = $this->markRunningTaskPlanAsDiscardedForRebuild($scope, $activeOperation);
                $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
                $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
            } elseif ($this->shouldReuseRunningQueuedOperation($operation, $runningOperation)) {
                return [
                    'success' => false,
                    'message' => $this->buildRunningOperationReuseMessage('task_plan'),
                    'operation' => 'task_plan',
                    'execution_token' => $runningExecutionToken,
                    'stream_url' => $runningExecutionToken !== ''
                        ? $this->buildOperationStreamUrl($session->getPublicId(), $runningExecutionToken)
                        : '',
                ];
            }
            if ($operation === 'plan' || $runningOperation === 'plan') {
                return [
                    'success' => false,
                    'message' => __('当前已有正在执行的第一阶段主题规划，请先等待完成'),
                    'operation' => $runningOperation,
                    'execution_token' => $runningExecutionToken,
                    'stream_url' => ($runningOperation !== '' && $runningExecutionToken !== '')
                        ? $this->buildOperationStreamUrl($session->getPublicId(), $runningExecutionToken)
                        : '',
                ];
            }
        }

        $baseScope = $scope;
        if ($operation === 'build') {
            $scopePatch = $this->buildTaskService->stripBuildPlanMutationScopePatch($scopePatch, $baseScope);
        }
        $scope = \array_replace($scope, $scopePatch);
        $scope['page_types'] = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if ($operation === 'build') {
            $scope = $this->buildTaskService->restoreBuildPlanContract($scope, $baseScope);
            $scope = $this->normalizeTaskPlanConfirmationForBuild($scope);
        }
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
        if ($operationDetails !== []) {
            $scope['active_operation'] = $this->applyOperationDetailsToPayload($scope['active_operation'], $operationDetails);
        }
        if ($operation === 'plan') {
            $existingDetails = \is_array($scope['active_operation']['details'] ?? null) ? $scope['active_operation']['details'] : [];
            $scope['active_operation']['details'] = \array_replace($existingDetails, [
                'plan_locale' => (string)($scope['plan_locale'] ?? ''),
                'page_types' => \array_values(\array_map('strval', \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [])),
                'source_signature' => $this->buildPlanSourceSignature($scope),
            ]);
        }
        $scope = $this->writeActiveOperationStateToScope($scope, $scope['active_operation']);
        $scope['workspace_status'] = $workspaceStatus;

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->setStage($session->getId(), $adminId, $stage);
        if ($operation === 'publish') {
            $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHING);
        }
        $freshForQueue = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        if ($this->shouldEnqueueOperation($operation)) {
            try {
                $queueId = $this->enqueueOperationQueueTask($freshForQueue, $adminId, $operation, $executionToken, $scopePatch, $operationDetails);
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
            $queueScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($freshForQueue, $stage)
            );
            $queueActiveOperation = \is_array($queueScope['active_operation'] ?? null) ? $queueScope['active_operation'] : [];
            if (
                \trim((string)($queueActiveOperation['operation'] ?? '')) === $operation
                && \trim((string)($queueActiveOperation['execution_token'] ?? '')) === $executionToken
            ) {
                $queueScope = $this->writeActiveOperationStateToScope($queueScope, \array_replace($queueActiveOperation, [
                    'queue_id' => $queueId,
                    'updated_at' => \date('Y-m-d H:i:s'),
                ]));
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
        $state = $this->buildWorkspaceState($fresh, $adminId, 24, true);
        return [
            'success' => true,
            'message' => __('操作已启动'),
            'execution_token' => $executionToken,
            'queue_id' => $queueId,
            'operation' => $operation,
            'stream_url' => $this->buildOperationStreamUrl($fresh->getPublicId(), $executionToken),
            'data' => $this->buildWorkspaceOperationPayload($state, $operation),
            'queue_dispatch' => $queueDispatch,
        ];
    }

    private function shouldEnqueueOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan', 'build', 'block_regenerate'], true);
    }

    private function buildRunningOperationReuseMessage(string $operation): string
    {
        $operation = \trim($operation);
        if ($operation === 'task_plan') {
            return 'Current stage-two task-plan generation is still running; reusing the current queue progress.';
        }
        if ($operation === 'plan') {
            return 'Current stage-one plan generation is still running; wait for it to finish.';
        }

        return 'Current workspace operation is still running; wait for it to finish.';
    }

    private function shouldReuseRunningQueuedOperation(string $requestedOperation, string $runningOperation): bool
    {
        return \trim($requestedOperation) === 'task_plan'
            && \trim($runningOperation) === 'task_plan';
    }

    /**
     * @param array<string, mixed> $scopePatch
     * @param array<string, mixed> $operationDetails
     */
    private function isTaskPlanSchemeRebuildRequest(string $operation, array $scopePatch = [], array $operationDetails = []): bool
    {
        if (\trim($operation) !== 'task_plan') {
            return false;
        }

        $request = \is_array($scopePatch['_task_plan_sse_request'] ?? null) ? $scopePatch['_task_plan_sse_request'] : [];
        $promptMode = \trim((string)($operationDetails['prompt_mode'] ?? $scopePatch['prompt_mode'] ?? $request['prompt_mode'] ?? ''));
        if ($promptMode === 'rebuild_task_plan') {
            return true;
        }

        return (int)($scopePatch['_task_plan_rebuild_in_progress'] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @return array<string, mixed>
     */
    private function markRunningTaskPlanAsDiscardedForRebuild(array $scope, array $activeOperation): array
    {
        $discarded = \array_replace($activeOperation, [
            'operation' => 'task_plan',
            'status' => 'cancelled',
            'message' => (string)__('方案重建已确认，旧的第二阶段任务方案生成已废弃。'),
            'discarded_by_rebuild' => 1,
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $activeOperations['task_plan'] = $discarded;
        $scope['active_operations'] = $activeOperations;
        $scope['active_operation'] = $discarded;

        return $scope;
    }

    private function shouldSelfDispatchAiSiteQueueOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan', 'build', 'block_regenerate'], true);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $operationDetails
     * @return array<string, mixed>
     */
    private function applyOperationDetailsToPayload(array $payload, array $operationDetails): array
    {
        if ($operationDetails === []) {
            return $payload;
        }

        $existingDetails = \is_array($payload['details'] ?? null) ? $payload['details'] : [];
        $payload['details'] = \array_replace($existingDetails, $operationDetails);
        foreach ($this->getQueuedOperationDetailKeys() as $detailKey) {
            if (\array_key_exists($detailKey, $operationDetails)) {
                $payload[$detailKey] = $operationDetails[$detailKey];
            }
        }

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function getQueuedOperationDetailKeys(): array
    {
        return [
            'page_type',
            'component_code',
            'component_label',
            'instruction',
            'shared_region',
            'stage_scope',
            'action',
            'target_scope',
            'block_key',
            'section_code',
            'task_key',
            'bucket',
            'prompt_mode',
            'round',
            'mutation',
            'block_config',
            'task_config',
        ];
    }

    /**
     * @param array<string, mixed> $scopePatch
     */
    private function enqueueOperationQueueTask(
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        array $scopePatch = [],
        array $operationDetails = []
    ): int {
        $queueClass = $this->resolveAiSiteQueueClass($operation);
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
        if ((int)($scopePatch['_force_rebuild'] ?? 0) === 1) {
            $content['_force_rebuild'] = 1;
        }
        if ($this->isTaskPlanSchemeRebuildRequest($operation, $scopePatch, $operationDetails)) {
            $content['_force_rebuild'] = 1;
        }
        $content = $this->applyOperationDetailsToPayload($content, $operationDetails);
        if (\in_array($operation, ['build', 'block_regenerate'], true)) {
            $content['scope_patch'] = $scopePatch;
        }

        $queueName = $this->buildAiSiteQueueName($operation, $executionToken);
        $bizKey = $this->buildAiSiteQueueBizKey((int)$session->getId(), $operation);
        $queueTypeId = $this->resolveAiSiteQueueTypeId($queueClass);
        $existingQueueRow = $this->findReusableAiSiteQueueRow($session, $operation);

        if (\is_array($existingQueueRow) && (int)($existingQueueRow['queue_id'] ?? 0) > 0) {
            $queueId = (int)$existingQueueRow['queue_id'];
            $updated = w_query('queue', 'update', [
                'queue_id' => $queueId,
                'patch' => [
                    'type_id' => $queueTypeId,
                    'name' => $queueName,
                    'module' => 'GuoLaiRen_PageBuilder',
                    'content' => $content,
                    'status' => 'pending',
                    'auto' => true,
                    'biz_key' => $bizKey,
                    'pid' => 0,
                    'finished' => 0,
                    'process' => (string)__('复用会话阶段队列并准备重新执行。'),
                    'result' => '',
                ],
            ]);
            if ($queueId <= 0 || !(\is_array($updated) && ($updated['success'] ?? false))) {
                throw new \RuntimeException((string)__('复用队列任务失败。'));
            }
        } else {
            $created = w_query('queue', 'create', [
                'class' => $queueClass,
                'name' => $queueName,
                'module' => 'GuoLaiRen_PageBuilder',
                'content' => $content,
                'status' => 'pending',
                'auto' => true,
                'biz_key' => $bizKey,
            ]);
            $queueId = (int)(\is_array($created) ? ($created['queue_id'] ?? 0) : 0);
            if ($queueId <= 0 || !(\is_array($created) && ($created['success'] ?? false))) {
                throw new \RuntimeException((string)__('创建队列任务失败。'));
            }
        }
        $queueId = $this->stabilizeAiSiteQueueSlot(
            $bizKey,
            $queueId,
            $queueTypeId,
            $queueName,
            $content,
            'Canonical queue slot stabilized before rerun.',
        );
        $queueCreatedHook = RequestContext::get(self::REQUEST_CTX_QUEUE_CREATED_HOOK);
        if (\is_callable($queueCreatedHook)) {
            $queueCreatedHook([
                'queue_id' => $queueId,
                'operation' => $operation,
                'execution_token' => $executionToken,
                'public_id' => $session->getPublicId(),
                'session_id' => (int)$session->getId(),
                'admin_id' => $adminId,
                'biz_key' => $bizKey,
            ]);
        }

        return $queueId;
    }

    private function resolveAiSiteQueueClass(string $operation): string
    {
        return match ($operation) {
            'plan' => \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class,
            'task_plan' => \GuoLaiRen\PageBuilder\Queue\AiSiteTaskPlanQueue::class,
            'build' => \GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue::class,
            'block_regenerate' => \GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue::class,
            default => '',
        };
    }

    private function buildAiSiteQueueName(string $operation, string $executionToken): string
    {
        return 'PageBuilder ' . $operation . ' #' . \substr($executionToken, 0, 12);
    }

    private function resolveAiSiteQueueTypeId(string $queueClass): int
    {
        $typeId = (int)w_query('queue', 'getTypeIdByClass', ['class' => $queueClass]);
        if ($typeId <= 0) {
            throw new \RuntimeException((string)__('解析队列类型失败。'));
        }

        return $typeId;
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
        $normalizedScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $this->resolveAiSiteQueueStage($operation))
        );

        $dispatchPort = function (
            AiSiteAgentSession $s,
            int $a,
            string $op,
            int $qid,
            string $token,
            bool $force
        ): array {
            return $this->ensureAiSiteQueueWorkerDispatched($s, $a, $op, $qid, $token, $force);
        };

        $findQueueRowPort = function (AiSiteAgentSession $s, string $op, int $qid): ?array {
            $row = $this->findAiSiteOperationQueueRow($s, $op, $qid);
            return \is_array($row) ? $row : null;
        };

        return $this->queueDispatchGuardService->maybeAutoDispatchObservedPendingQueue(
            $sse,
            $session,
            $adminId,
            $operation,
            $executionToken,
            $queueRow,
            $alreadyAttempted,
            $normalizedScope,
            $dispatchPort,
            $findQueueRowPort
        );
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
                ? (string)__('检测到现有队列执行进程，已恢复状态同步（PID %{pid}）。', ['pid' => $pid])
                : (string)__('检测到现有队列执行进程，已恢复状态同步。');
        }

        return $pid > 0
            ? (string)__('队列已在后台启动执行进程（PID %{pid}）。', ['pid' => $pid])
            : (string)__('队列已在后台启动执行进程，正在等待 PID 同步。');
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
            . ':queue_slot:' . $this->resolveAiSiteQueueReuseSlot($operation);
        if (\strlen($raw) > 191) {
            return \substr($raw, 0, 191);
        }

        return $raw;
    }

    private function buildAiSiteLegacyQueueBizKey(int $sessionId, string $operation): string
    {
        $raw = 'glr_aisite:session:' . $sessionId
            . ':stage:' . $this->resolveAiSiteQueueStage($operation)
            . ':operation:' . $operation;
        if (\strlen($raw) > 191) {
            return \substr($raw, 0, 191);
        }

        return $raw;
    }

    private function resolveAiSiteQueueReuseSlot(string $operation): string
    {
        return match ($operation) {
            'plan' => 'plan',
            'task_plan' => 'task_plan',
            'build' => 'build',
            default => $operation !== '' ? $operation : 'workspace',
        };
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
        return $this->workspaceStateHelperService()->resolveQueueJobType($operation);
    }

    private function resolveAiSiteQueueStage(string $operation): string
    {
        return match ($operation) {
            'plan' => AiSiteAgentSession::STAGE_PLAN,
            'task_plan', 'build', 'block_regenerate' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
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
            if (\is_array($row) && $row !== [] && $this->isAiSiteQueueRowForOperation($row, $operation)) {
                return $row;
            }
        }

        $row = $this->findAiSiteQueueRowByBizKey($this->buildAiSiteQueueBizKey((int)$session->getId(), $operation));
        if (\is_array($row) && $row !== [] && $this->isAiSiteQueueRowForOperation($row, $operation)) {
            return $row;
        }

        foreach ($this->resolveAiSiteLegacyQueueBizKeys((int)$session->getId(), $operation) as $legacyBizKey) {
            $row = $this->findAiSiteQueueRowByBizKey($legacyBizKey);
            if (\is_array($row) && $row !== [] && $this->isAiSiteQueueRowForOperation($row, $operation)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isAiSiteQueueRowForOperation(array $row, string $operation): bool
    {
        $operation = \trim($operation);
        if ($operation === '') {
            return false;
        }

        $queueClass = $this->resolveAiSiteQueueClass($operation);
        if ($queueClass === '') {
            return false;
        }

        try {
            $expectedTypeId = $this->resolveAiSiteQueueTypeId($queueClass);
            $actualTypeId = (int)($row['type_id'] ?? 0);
            if ($actualTypeId > 0 && $expectedTypeId > 0 && $actualTypeId !== $expectedTypeId) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $content = $row['content'] ?? null;
        if (\is_string($content)) {
            $decoded = \json_decode($content, true);
            $content = \is_array($decoded) ? $decoded : null;
        }

        if (\is_array($content)) {
            $contentOperation = \trim((string)($content['operation'] ?? ''));
            if ($contentOperation !== '') {
                return $contentOperation === $operation;
            }

            $contentJobType = \trim((string)($content['job_type'] ?? ''));
            if ($contentJobType !== '') {
                $expectedJobType = $this->resolveAiSiteQueueJobType($operation);
                return $expectedJobType !== '' && $contentJobType === $expectedJobType;
            }
        }

        $bizKeyMatch = $this->resolveAiSiteQueueRowBizKeyOperationMatch($row, $operation);
        if ($bizKeyMatch !== null) {
            return $bizKeyMatch;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveAiSiteQueueRowBizKeyOperationMatch(array $row, string $operation): ?bool
    {
        $bizKey = \trim((string)($row['biz_key'] ?? ''));
        if ($bizKey === '') {
            return null;
        }

        if (\preg_match('/(?:^|:)queue_slot:([^:]+)/', $bizKey, $slotMatch) === 1) {
            $slot = \trim((string)($slotMatch[1] ?? ''));
            if ($slot === 'planning') {
                return $operation === 'plan';
            }

            return $slot === $this->resolveAiSiteQueueReuseSlot($operation);
        }

        if (\preg_match('/(?:^|:)operation:([^:]+)/', $bizKey, $operationMatch) === 1) {
            return \trim((string)($operationMatch[1] ?? '')) === $operation;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAiSiteQueueRowByBizKey(string $bizKey): ?array
    {
        $bizKey = \trim($bizKey);
        if ($bizKey === '') {
            return null;
        }

        $rows = $this->findAiSiteQueueRowsByBizKey($bizKey);

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findAiSiteQueueRowsByBizKey(string $bizKey): array
    {
        $bizKey = \trim($bizKey);
        if ($bizKey === '') {
            return [];
        }

        try {
            /** @var \Weline\Queue\Model\Queue $queue */
            $queue = clone ObjectManager::getInstance(\Weline\Queue\Model\Queue::class);
            $queue->clearData()
                ->reset()
                ->where(\Weline\Queue\Model\Queue::schema_fields_BIZ_KEY, $bizKey)
                ->order(\Weline\Queue\Model\Queue::schema_fields_ID, 'ASC')
                ->select()
                ->fetch();
            $items = $queue->getItems();
            if (!\is_array($items) || $items === []) {
                return [];
            }

            $rows = [];
            foreach ($items as $item) {
                if (\is_object($item) && \method_exists($item, 'getData')) {
                    $item = $item->getData();
                }
                if (!\is_array($item)) {
                    continue;
                }
                if ((int)($item['queue_id'] ?? 0) <= 0) {
                    continue;
                }
                $rows[] = $item;
            }

            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function updateAiSiteQueueRow(
        int $queueId,
        int $queueTypeId,
        string $queueName,
        array $content,
        string $bizKey,
        string $processMessage
    ): array {
        return w_query('queue', 'update', [
            'queue_id' => $queueId,
            'patch' => [
                'type_id' => $queueTypeId,
                'name' => $queueName,
                'module' => 'GuoLaiRen_PageBuilder',
                'content' => $content,
                'status' => 'pending',
                'auto' => true,
                'biz_key' => $bizKey,
                'pid' => 0,
                'finished' => 0,
                'process' => $processMessage,
                'result' => '',
            ],
        ]);
    }

    /**
     * Keep one stable queue row per session slot even when historical duplicates already exist.
     *
     * @param array<string, mixed> $content
     */
    private function stabilizeAiSiteQueueSlot(
        string $bizKey,
        int $preferredQueueId,
        int $queueTypeId,
        string $queueName,
        array $content,
        string $processMessage
    ): int {
        $rows = $this->findAiSiteQueueRowsByBizKey($bizKey);
        if ($rows === []) {
            return $preferredQueueId;
        }

        $canonicalQueueId = (int)($rows[0]['queue_id'] ?? 0);
        if ($canonicalQueueId <= 0) {
            return $preferredQueueId;
        }

        if ($canonicalQueueId !== $preferredQueueId) {
            $updated = $this->updateAiSiteQueueRow(
                $canonicalQueueId,
                $queueTypeId,
                $queueName,
                $content,
                $bizKey,
                $processMessage,
            );
            if (!(\is_array($updated) && ($updated['success'] ?? false))) {
                return $preferredQueueId;
            }
        }

        foreach ($rows as $row) {
            $queueId = (int)($row['queue_id'] ?? 0);
            if ($queueId <= 0 || $queueId === $canonicalQueueId) {
                continue;
            }
            if (\trim((string)($row['status'] ?? '')) === \Weline\Queue\Model\Queue::status_running) {
                continue;
            }
            try {
                /** @var \Weline\Queue\Model\Queue $duplicateQueue */
                $duplicateQueue = clone ObjectManager::getInstance(\Weline\Queue\Model\Queue::class);
                $duplicateQueue->clearData()->load($queueId);
                if ((int)$duplicateQueue->getId() > 0) {
                    $duplicateQueue->delete()->fetch();
                }
            } catch (\Throwable) {
            }
        }

        return $canonicalQueueId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findReusableAiSiteQueueRow(AiSiteAgentSession $session, string $operation): ?array
    {
        $queueRow = $this->findAiSiteQueueRowByBizKey($this->buildAiSiteQueueBizKey((int)$session->getId(), $operation));
        if (\is_array($queueRow) && $queueRow !== [] && $this->isAiSiteQueueRowForOperation($queueRow, $operation)) {
            return $queueRow;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveAiSiteLegacyQueueBizKeys(int $sessionId, string $operation): array
    {
        $keys = [];
        if ($operation === 'plan') {
            $keys[] = 'glr_aisite:session:' . $sessionId . ':queue_slot:planning';
        }
        $keys[] = $this->buildAiSiteLegacyQueueBizKey($sessionId, $operation);

        return \array_values(\array_unique(\array_filter($keys, static fn ($key): bool => \is_string($key) && $key !== '')));
    }


    /**
     * 默认 HTML 区块轨：不创建虚拟主题，仅填充 scope.virtual_pages_by_type.blocks
     *
     * @return array<string, mixed>
     */
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
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
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
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

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
                try {
                    $component = $pageComponentGenerationService->generateSharedComponent(
                        $region,
                        $scope['website_profile'],
                        $scope,
                        '',
                        $queueForcedAiRebuild
                    );
                } catch (\Throwable $throwable) {
                    $failure = $this->handleBuildTaskGenerationFailure(
                        $sse,
                        $session,
                        $adminId,
                        $scope,
                        $taskKey,
                        'shared_component',
                        AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                        $throwable,
                        [
                            'region' => $region,
                            'progress_percent' => $progressPercent,
                        ]
                    );
                    $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                    if (!empty($failure['fatal'])) {
                        throw $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : $throwable;
                    }
                    continue;
                }
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
            $taskSpecErrors = \is_array($batchSpecs['errors'] ?? null) ? $batchSpecs['errors'] : [];
            $retryTaskKeys = [];
            $failedTaskKeys = [];
            $fatalBatchThrowable = null;
            foreach ($taskSpecErrors as $taskKey => $error) {
                $taskKey = (string)$taskKey;
                $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);
                $failure = $this->handleBuildTaskGenerationFailure(
                    $sse,
                    $session,
                    $adminId,
                    $scope,
                    $taskKey,
                    'page_section',
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                    new \RuntimeException((string)(\is_array($error) ? ($error['message'] ?? 'Build task spec error') : 'Build task spec error')),
                    [
                        'page_type' => \is_array($error) ? (string)($error['page_type'] ?? '') : '',
                        'section_code' => \is_array($error) ? (string)($error['section_code'] ?? '') : '',
                        'progress_percent' => $currentStep > 0 ? (int)(($currentStep / $totalSteps) * 100) : 0,
                    ]
                );
                $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                if (!empty($failure['fatal'])) {
                    $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : new \RuntimeException('Build task spec error');
                    $failedTaskKeys[] = $taskKey;
                    continue;
                }
                $retryTaskKeys[] = $taskKey;
            }
            if ($componentSpecs === []) {
                if ($fatalBatchThrowable instanceof \Throwable) {
                    throw $fatalBatchThrowable;
                }
                continue;
            }

            $runningTaskKeys = \array_values(\array_map('strval', \array_keys($componentSpecs)));
            foreach ($runningTaskKeys as $runningTaskKey) {
                $scope = $this->buildTaskService->markTaskRunning($scope, $runningTaskKey);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->emitBuildTaskProgressSnapshotFromScope(
                $sse,
                $scope,
                'build',
                (string)__('AI 正在生成页面任务'),
                $currentStep > 0 ? (int)(($currentStep / $totalSteps) * 100) : 0
            );

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
                    $failure = $this->handleBuildTaskGenerationFailure(
                        $sse,
                        $session,
                        $adminId,
                        $scope,
                        (string)$taskKey,
                        'page_section',
                        AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                        $throwable,
                        [
                            'page_type' => $pageType,
                            'task_keys' => $runningTaskKeys,
                            'completed_task_keys' => $completedTaskKeys,
                            'progress_percent' => $currentStep > 0 ? (int)(($currentStep / $totalSteps) * 100) : 0,
                        ]
                    );
                    $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                    if (!empty($failure['fatal'])) {
                        $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : $throwable;
                        $failedTaskKeys[] = (string)$taskKey;
                        continue;
                    }
                    $retryTaskKeys[] = (string)$taskKey;
                    continue;
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

            if ($fatalBatchThrowable instanceof \Throwable) {
                $this->emitBuildInfoEvent(
                    $sse,
                    (string)__('Concurrent page batch failed after saved tasks were persisted.'),
                    [
                        'event_type' => 'build_parallel_batch',
                        'batch_state' => 'failed',
                        'parallel' => true,
                        'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
                        'batch_size' => \count($runningTaskKeys),
                        'page_types' => $batchPageTypes,
                        'task_keys' => $runningTaskKeys,
                        'completed_task_keys' => $completedTaskKeys,
                        'retry_task_keys' => $retryTaskKeys,
                        'failed_task_keys' => $failedTaskKeys,
                        'error_message' => $fatalBatchThrowable->getMessage(),
                    ]
                );
                throw $fatalBatchThrowable;
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
                    'retry_task_keys' => $retryTaskKeys,
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
        $dropLegacyPageBlocks = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? '')) === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
            || (string)($sectionBlock['type'] ?? '') === 'ai_generated_section';
        foreach ($existingBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            if ($this->isHtmlSharedBlock($blockId, $block)) {
                continue;
            }
            if ($dropLegacyPageBlocks && !$this->isGeneratedHtmlContentBlock($blockId, $block)) {
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
    private function isGeneratedHtmlContentBlock(string $blockId, array $block): bool
    {
        $type = \trim((string)($block['type'] ?? ''));
        if ($type === 'ai_generated_section') {
            return true;
        }
        if (\is_array($block['config'] ?? null) && \trim((string)($block['config']['_pb_server_component_code'] ?? '')) !== '') {
            return true;
        }
        if (\str_contains($blockId, '-hero-banner') || \str_contains($blockId, '-featured-games') || \str_contains($blockId, '-trust-cta')
            || \str_contains($blockId, '-brand-story') || \str_contains($blockId, '-trust-pillars') || \str_contains($blockId, '-community-cta')) {
            return true;
        }

        return false;
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

    /**
     * @param list<string> $pageTypes
     * @param array<string, array<string, mixed>> $pageTypeLayouts
     */
    private function saveGeneratedPageLayoutsForTypes(int $virtualThemeId, array $pageTypes, array $pageTypeLayouts): void
    {
        foreach ($pageTypes as $pageType) {
            if (!isset($pageTypeLayouts[$pageType]) || !\is_array($pageTypeLayouts[$pageType])) {
                continue;
            }
            $this->virtualThemeService->saveGeneratedPageLayout($virtualThemeId, $pageType, $pageTypeLayouts[$pageType]);
        }
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
     *   }>,
     *   errors:array<string, array{
     *     task_key:string,
     *     page_type:string,
     *     page_label:string,
     *     section_code:string,
     *     message:string
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
        $errors = [];

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
                    $key = \trim((string)($sectionSpec['key'] ?? ''));
                    if ($key !== '') {
                        $sectionMap[$key] = $sectionSpec;
                    }
                    $blockId = \str_replace(['content/', '/'], ['', '-'], $code);
                    if ($blockId !== '') {
                        $sectionMap[$blockId] = $sectionSpec;
                    }
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
                $taskBlockKey = \trim((string)($task['block_key'] ?? ''));
                if ($taskBlockKey !== '') {
                    $sectionSpec = \is_array($pageSpecs['sections'][$taskBlockKey] ?? null) ? $pageSpecs['sections'][$taskBlockKey] : null;
                }
            }
            if (!\is_array($sectionSpec) && $taskKey !== '') {
                $taskKeyTail = \trim((string)\preg_replace('/^page:[^:]+:/', '', $taskKey));
                if ($taskKeyTail !== '') {
                    $sectionSpec = \is_array($pageSpecs['sections'][$taskKeyTail] ?? null) ? $pageSpecs['sections'][$taskKeyTail] : null;
                }
            }
            if (!\is_array($sectionSpec)) {
                $sectionSpec = $this->buildFallbackPageTaskSectionSpec($task, $pageType, $sectionCode, $taskKey);
            }
            if (!\is_array($sectionSpec)) {
                $errors[$taskKey] = [
                    'task_key' => $taskKey,
                    'page_type' => $pageType,
                    'page_label' => (string)($pageTypeLabels[$pageType] ?? $pageType),
                    'section_code' => $sectionCode,
                    'message' => 'Build task missing stage-two section spec: ' . $taskKey,
                ];
                continue;
            }
            $resolvedSectionCode = \trim((string)($sectionSpec['code'] ?? $sectionCode));
            $resolvedSectionCode = $resolvedSectionCode !== '' ? $resolvedSectionCode : $sectionCode;

            $components[$taskKey] = [
                'componentCode' => $resolvedSectionCode,
                'name' => (string)($sectionSpec['name'] ?? $resolvedSectionCode),
                'region' => (string)($sectionSpec['region'] ?? 'content'),
                'prompt' => (string)($sectionSpec['prompt'] ?? ''),
                'defaultConfig' => \is_array($sectionSpec['default_config'] ?? null) ? $sectionSpec['default_config'] : [],
                'renderContext' => \is_array($sectionSpec['render_context'] ?? null) ? $sectionSpec['render_context'] : [],
            ];
            $meta[$taskKey] = [
                'task_key' => $taskKey,
                'page_type' => $pageType,
                'page_label' => (string)($pageTypeLabels[$pageType] ?? $pageType),
                'section_code' => $resolvedSectionCode,
                'source_section_code' => $sectionCode,
                'blueprint' => \is_array($pageSpecs['blueprint'] ?? null) ? $pageSpecs['blueprint'] : [],
            ];
        }

        return [
            'components' => $components,
            'meta' => $meta,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function buildFallbackPageTaskSectionSpec(array $task, string $pageType, string $sectionCode, string $taskKey): array
    {
        $sectionCode = \trim($sectionCode);
        if ($sectionCode === '' || \in_array(\strtolower($sectionCode), ['section', 'content', 'block'], true)) {
            $taskTail = \trim((string)\preg_replace('/^page:[^:]+:/', '', $taskKey));
            $seed = $taskTail !== '' ? $pageType . '-' . $taskTail : $taskKey;
            $slug = \strtolower(\trim((string)\preg_replace('/[^a-z0-9]+/i', '-', \str_replace('_', '-', $seed)), '-'));
            $sectionCode = $slug !== '' ? 'content/' . $slug : '';
        }
        if ($sectionCode === '') {
            return [];
        }

        $label = \trim((string)($task['label'] ?? ''));
        if ($label === '') {
            $label = \trim((string)\preg_replace('/[^a-z0-9]+/i', ' ', \str_replace(['content/', '_', '-'], ' ', $sectionCode)));
            $label = $label !== '' ? \ucwords($label) : $sectionCode;
        }

        $promptParts = [
            'Generate this confirmed stage-two page section as one production PageBuilder component.',
            'Task key: ' . $taskKey,
            'Page type: ' . $pageType,
            'Section code: ' . $sectionCode,
            'Label: ' . $label,
        ];
        foreach (['task_script', 'plan_context', 'block_task', 'implementation_contract', 'runtime_context'] as $key) {
            $value = \is_array($task[$key] ?? null) ? $task[$key] : [];
            if ($value === []) {
                continue;
            }
            $encoded = $this->encodeCompactJson($value);
            if ($encoded === '') {
                continue;
            }
            $promptParts[] = $key . ': ' . \substr($encoded, 0, 3000);
        }

        return [
            'code' => $sectionCode,
            'key' => \trim((string)($task['block_key'] ?? '')),
            'name' => $label,
            'region' => 'content',
            'prompt' => \implode("\n", $promptParts),
            'default_config' => [],
            'render_context' => [
                'page_type' => $pageType,
                'section_code' => $sectionCode,
                'task_key' => $taskKey,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $context
     * @return array{scope:array<string,mixed>,fatal:bool,throwable:\Throwable}
     */
    private function handleBuildTaskGenerationFailure(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        string $taskKey,
        string $taskType,
        string $workspaceTrack,
        \Throwable $throwable,
        array $context = []
    ): array {
        $taskKey = \trim($taskKey);
        if ($taskKey === '') {
            return [
                'scope' => $scope,
                'fatal' => true,
                'throwable' => $throwable,
            ];
        }

        $message = \trim($throwable->getMessage());
        if ($message === '') {
            $message = $throwable::class;
        }
        $attemptNo = $this->buildTaskService->getTaskAttemptNo($scope, $taskKey);
        $progressPercent = \max(0, \min(99, (int)($context['progress_percent'] ?? 0)));
        $basePayload = \array_replace($context, [
            'parallel' => true,
            'workspace_track' => $workspaceTrack,
            'task_key' => $taskKey,
            'task_type' => $taskType,
            'attempt_no' => $attemptNo,
            'max_attempts' => self::BUILD_TASK_MAX_GENERATION_ATTEMPTS,
            'error_message' => $message,
        ]);

        if ($attemptNo < self::BUILD_TASK_MAX_GENERATION_ATTEMPTS) {
            $scope = $this->buildTaskService->markTaskPendingForRetry($scope, $taskKey, $message);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('Build task failed on attempt %{attempt}/%{max}; queued retry: %{task}', [
                    'attempt' => (string)$attemptNo,
                    'max' => (string)self::BUILD_TASK_MAX_GENERATION_ATTEMPTS,
                    'task' => $taskKey,
                ]),
                \array_replace($basePayload, [
                    'event_type' => 'build_task_retry',
                    'batch_state' => 'retrying',
                    'next_attempt_no' => $attemptNo + 1,
                ])
            );
            $this->emitBuildTaskProgressSnapshotFromScope(
                $sse,
                $scope,
                'build',
                (string)__('Build task queued for retry: %{task}', ['task' => $taskKey]),
                $progressPercent,
                'queued'
            );
            $sse->sendEvent('task_retry', $this->enrichTaskEventPayload($scope, \array_replace($basePayload, [
                'message' => 'Build task queued for retry: ' . $taskKey,
            ])));

            return [
                'scope' => $scope,
                'fatal' => false,
                'throwable' => $throwable,
            ];
        }

        $scope = $this->buildTaskService->markTaskFailed($scope, $taskKey, $message);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->emitBuildInfoEvent(
            $sse,
            (string)__('Build task failed after %{max} attempts: %{task}', [
                'max' => (string)self::BUILD_TASK_MAX_GENERATION_ATTEMPTS,
                'task' => $taskKey,
            ]),
            \array_replace($basePayload, [
                'event_type' => 'build_task_failed',
                'batch_state' => 'failed',
            ])
        );
        $this->emitBuildTaskProgressSnapshotFromScope(
            $sse,
            $scope,
            'build',
            (string)__('Build task failed: %{task}', ['task' => $taskKey]),
            $progressPercent,
            'failed'
        );
        $sse->sendEvent('task_failed', $this->enrichTaskEventPayload($scope, \array_replace($basePayload, [
            'message' => 'Build task failed: ' . $taskKey,
        ])));

        return [
            'scope' => $scope,
            'fatal' => true,
            'throwable' => $throwable,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return list<string>
     */
    private function extractBuildBatchPageTypes(array $tasks): array
    {
        $pageTypes = [];
        foreach ($tasks as $task) {
            if (!\is_array($task)) {
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? $task['group_key'] ?? ''));
            if ($pageType === '' || $pageType === 'shared') {
                continue;
            }
            $pageTypes[$pageType] = $pageType;
        }

        return \array_values($pageTypes);
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
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            return $this->runHtmlBlocksBuildOperationV3($sse, $session, $adminId, $scope);
        }
        return $this->runVirtualThemeBuildOperationV3($sse, $session, $adminId, $scope);
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
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
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
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

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
                try {
                    $component = $pageComponentGenerationService->generateSharedComponent(
                        $region,
                        $scope['website_profile'],
                        $scope,
                        '',
                        $queueForcedAiRebuild
                    );
                } catch (\Throwable $throwable) {
                    $failure = $this->handleBuildTaskGenerationFailure(
                        $sse,
                        $session,
                        $adminId,
                        $scope,
                        $taskKey,
                        'shared_component',
                        AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                        $throwable,
                        [
                            'region' => $region,
                            'progress_percent' => $progressPercent,
                        ]
                    );
                    $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                    if (!empty($failure['fatal'])) {
                        throw $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : $throwable;
                    }
                    continue;
                }
                $sharedComponents[$region] = $component;
                $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
                $scope['shared_components'][$region] = $component;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['region' => $region]);
                $virtualThemeId = (int)$scope['virtual_theme_id'];
                $this->virtualThemeService->saveGeneratedSharedComponent($virtualThemeId, $component);
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
                $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
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
            $taskSpecErrors = \is_array($batchSpecs['errors'] ?? null) ? $batchSpecs['errors'] : [];
            $retryTaskKeys = [];
            $failedTaskKeys = [];
            $fatalBatchThrowable = null;
            foreach ($taskSpecErrors as $taskKey => $error) {
                $taskKey = (string)$taskKey;
                $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);
                $failure = $this->handleBuildTaskGenerationFailure(
                    $sse,
                    $session,
                    $adminId,
                    $scope,
                    $taskKey,
                    'page_section',
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                    new \RuntimeException((string)(\is_array($error) ? ($error['message'] ?? 'Build task spec error') : 'Build task spec error')),
                    [
                        'page_type' => \is_array($error) ? (string)($error['page_type'] ?? '') : '',
                        'section_code' => \is_array($error) ? (string)($error['section_code'] ?? '') : '',
                        'progress_percent' => $currentStep > 0 ? (int)(($currentStep / $totalSteps) * 100) : 0,
                    ]
                );
                $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                if (!empty($failure['fatal'])) {
                    $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : new \RuntimeException('Build task spec error');
                    $failedTaskKeys[] = $taskKey;
                    continue;
                }
                $retryTaskKeys[] = $taskKey;
            }
            if ($componentSpecs === []) {
                if ($fatalBatchThrowable instanceof \Throwable) {
                    throw $fatalBatchThrowable;
                }
                continue;
            }

            $runningTaskKeys = \array_values(\array_map('strval', \array_keys($componentSpecs)));
            foreach ($runningTaskKeys as $runningTaskKey) {
                $scope = $this->buildTaskService->markTaskRunning($scope, $runningTaskKey);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->emitBuildTaskProgressSnapshotFromScope(
                $sse,
                $scope,
                'build',
                (string)__('AI 正在生成页面任务'),
                $currentStep > 0 ? (int)(($currentStep / $totalSteps) * 100) : 0
            );

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
                    $failure = $this->handleBuildTaskGenerationFailure(
                        $sse,
                        $session,
                        $adminId,
                        $scope,
                        (string)$taskKey,
                        'page_section',
                        AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                        $throwable,
                        [
                            'page_type' => $pageType,
                            'task_keys' => $runningTaskKeys,
                            'completed_task_keys' => $completedTaskKeys,
                            'progress_percent' => $currentStep > 0 ? (int)(($currentStep / $totalSteps) * 100) : 0,
                        ]
                    );
                    $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                    if (!empty($failure['fatal'])) {
                        $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : $throwable;
                        $failedTaskKeys[] = (string)$taskKey;
                        continue;
                    }
                    $retryTaskKeys[] = (string)$taskKey;
                    continue;
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
                ]), false, false);
                if (isset($currentVirtualPages[$pageType])) {
                    $currentVirtualPages[$pageType]['last_generated_at'] = \date('Y-m-d H:i:s');
                    $currentVirtualPages[$pageType]['title'] = (string)($blueprint['page_title'] ?? ($currentVirtualPages[$pageType]['title'] ?? ''));
                    $currentVirtualPages[$pageType]['ai_description'] = (string)($blueprint['ai_description'] ?? ($currentVirtualPages[$pageType]['ai_description'] ?? ''));
                    $currentVirtualPages[$pageType]['meta_title'] = (string)($blueprint['meta_title'] ?? ($currentVirtualPages[$pageType]['meta_title'] ?? ''));
                    $currentVirtualPages[$pageType]['meta_description'] = (string)($blueprint['meta_description'] ?? ($currentVirtualPages[$pageType]['meta_description'] ?? ''));
                    $currentVirtualPages[$pageType]['meta_keywords'] = (string)($blueprint['meta_keywords'] ?? ($currentVirtualPages[$pageType]['meta_keywords'] ?? ''));
                    $currentVirtualPages[$pageType]['section_refinements'] = \is_array($blueprint['section_refinements'] ?? null) ? $blueprint['section_refinements'] : [];
                    $currentVirtualPages[$pageType]['blocks'] = $this->composeHtmlBlocksForPage(
                        $pageType,
                        \is_array($virtualPages[$pageType]['blocks'] ?? null) ? $virtualPages[$pageType]['blocks'] : [],
                        $sharedComponents,
                        $this->htmlBlocksBuildService->buildGeneratedSectionBlock($sectionComponent),
                        $scope['website_profile'],
                        $scope
                    );
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

            if ($fatalBatchThrowable instanceof \Throwable) {
                $this->emitBuildInfoEvent(
                    $sse,
                    (string)__('Concurrent page batch failed after saved tasks were persisted.'),
                    [
                        'event_type' => 'build_parallel_batch',
                        'batch_state' => 'failed',
                        'parallel' => true,
                        'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                        'batch_size' => \count($runningTaskKeys),
                        'page_types' => $batchPageTypes,
                        'task_keys' => $runningTaskKeys,
                        'completed_task_keys' => $completedTaskKeys,
                        'retry_task_keys' => $retryTaskKeys,
                        'failed_task_keys' => $failedTaskKeys,
                        'error_message' => $fatalBatchThrowable->getMessage(),
                    ]
                );
                throw $fatalBatchThrowable;
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
                    'retry_task_keys' => $retryTaskKeys,
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
        $ports = new AiSiteAgentRegeneratePageOperationPorts(
            assertActiveStreamLeaseAlive: function (AiSiteAgentSession $session, int $adminId): void {
                $this->assertActiveStreamLeaseAlive($session, $adminId);
            },
            normalizeScope: fn (array $scope): array => $this->scopeCompatibilityService->normalizeScope($scope),
            resolveScopedPageTypes: fn (array $scope): array => $this->scopeCompatibilityService->resolveScopedPageTypes($scope),
            generateProfile: fn (array $scope): array => $this->profileGenerationService->generate($scope),
            ensureTaskScope: fn (array $scope, array $websiteProfile, string $workspaceTrack): array => $this->buildTaskService->ensureTaskScope($scope, $websiteProfile, $workspaceTrack),
            resetPageTasksForRetry: fn (array $scope, string $pageType): array => $this->buildTaskService->resetPageTasksForRetry($scope, $pageType),
            normalizeWorkspaceTrack: fn (string $track): string => $this->scopeCompatibilityService->normalizeWorkspaceTrack($track),
            resolvePageTypeLabels: fn (): array => Page::getPageTypes(),
            sendOperationProgress: function (...$args): void {
                $this->sendOperationProgress(...$args);
            },
            buildVirtualPagesByType: fn (array $pageTypes, array $scope): array => $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope),
            buildPageBlueprint: fn (string $pageType, array $scope, array $websiteProfile): array => $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile),
            buildPlaceholderBlocksForPageType: fn (string $pageType, array $websiteProfile, array $scope): array => $this->htmlBlocksBuildService->buildPlaceholderBlocksForPageType($pageType, $websiteProfile, $scope),
            markTaskDone: fn (array $scope, string $taskKey, array $payload): array => $this->buildTaskService->markTaskDone($scope, $taskKey, $payload),
            materializeGeneratedPages: fn (string $track, int $websiteId, array $websiteProfile, array $pageTypes, array $layouts, array $virtualPages): array => $this->materializeGeneratedPages($track, $websiteId, $websiteProfile, $pageTypes, $layouts, $virtualPages),
            mergeMaterializedPagesIntoScope: fn (array $scope, array $materialized): array => $this->mergeMaterializedPagesIntoScope($scope, $materialized),
            summarizeBuildTasks: fn (array $scope): array => $this->buildTaskService->summarize($scope),
            replaceScope: function (int $sessionId, int $adminId, array $scope): void {
                $this->sessionService->replaceScope($sessionId, $adminId, $scope);
            },
            bindVirtualTheme: function (int $sessionId, int $adminId, int $themeId): void {
                $this->sessionService->bindVirtualTheme($sessionId, $adminId, $themeId);
            },
            appendWorkspaceEvent: function (int $sessionId, int $adminId, string $stageCode, string $eventType, string $message, array $payload): void {
                $this->appendWorkspaceEvent($sessionId, $adminId, $stageCode, $eventType, $message, $payload);
            },
            normalizePageTypeLayouts: fn ($pageTypeLayouts, array $pageTypes): array => $this->scopeCompatibilityService->normalizePageTypeLayouts($pageTypeLayouts, $pageTypes),
            normalizeLayoutConfig: fn (array $layoutConfig, string $pageType): array => $this->scopeCompatibilityService->normalizeLayoutConfig($layoutConfig, $pageType),
            ensureAiGeneratedVirtualTheme: fn (array $scope, array $websiteProfile, array $pageTypes, array $pageTypeLayouts, int $sessionId, bool $refresh): array => $this->virtualThemeService->ensureAiGeneratedVirtualTheme($scope, $websiteProfile, $pageTypes, $pageTypeLayouts, $sessionId, $refresh),
        );

        return $this->regeneratePageOperationService->runRegeneratePageOperation($sse, $session, $adminId, $pageType, $ports);
    }

    /**
     * @return array<string, mixed>
     */
    private function runPublishOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): array
    {
        $this->assertActiveStreamLeaseAlive($session, $adminId);
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PUBLISH)
        );
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
        $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);
        $qualityReport = $qualityGate->inspectScope($scope);
        if (empty($qualityReport['passed'])) {
            throw new \RuntimeException((string)__('发布门禁未通过，请先修复页面内容质量、破图或任务状态问题。'));
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
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($fresh, $this->resolveAiSiteQueueStage($operation))
            );
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
        if (\in_array($operation, ['build', 'regenerate_page', 'block_regenerate'], true)) {
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($fresh, $this->resolveAiSiteQueueStage($operation))
            );
            $summary = $this->buildTaskService->summarize($scope);
            if ((int)($summary['total'] ?? 0) > 0) {
                $payload = \array_replace(
                    $payload,
                    $this->buildTaskProgressSnapshotPayload(
                        $summary,
                        $operation,
                        $message,
                        $progressPercent,
                        \in_array($operationStatus, ['queued', 'running'], true) && $progressPercent < 100,
                        null,
                        $operationStatus
                    ),
                    ['page_type' => $pageType, 'details' => []]
                );
            }
        }
        $sse->sendEvent('progress', $payload);
        $this->updateActiveOperation(
            $session,
            $adminId,
            ['operation' => $operation, 'status' => $operationStatus, 'message' => $message, 'progress_percent' => $progressPercent, 'page_type' => $pageType],
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
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, $this->scopeCompatibilityService->normalizeStage($fresh->getStage()))
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $updatedAt = \date('Y-m-d H:i:s');
        $operation = \trim((string)($patch['operation'] ?? $activeOperation['operation'] ?? ''));
        $baseOperation = $activeOperation;
        if ($operation !== '') {
            $currentOperation = \trim((string)($activeOperation['operation'] ?? ''));
            if ($currentOperation !== $operation && \is_array($activeOperations[$operation] ?? null)) {
                $baseOperation = $activeOperations[$operation];
            }
        }
        $nextActiveOperation = \array_replace($baseOperation, $patch, ['updated_at' => $updatedAt]);
        if ($operation !== '') {
            $nextActiveOperation['operation'] = $operation;
        }
        $scope = $this->writeActiveOperationStateToScope($scope, $nextActiveOperation);
        if ($workspaceStatus !== null) {
            $scope['workspace_status'] = $workspaceStatus;
        }
        $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);
        if ($publishStatus !== null) {
            $this->sessionService->setPublishStatus($fresh->getId(), $adminId, $publishStatus);
        }
    }

    private function touchStreamLeaseState(AiSiteAgentSession $session, int $adminId, string $leaseToken, string $tabToken = ''): int
    {
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $this->scopeCompatibilityService->normalizeStage($session->getStage()))
        );
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
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, $this->scopeCompatibilityService->normalizeStage($fresh->getStage()))
        );
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
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, $this->scopeCompatibilityService->normalizeStage($fresh->getStage()))
        );
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
        $payload = $this->enrichWorkspaceEventQueueCorrelation($payload);
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function enrichWorkspaceEventQueueCorrelation(array $payload): array
    {
        $writer = RequestContext::get(RequestContext::SSE_WRITER_KEY);
        if (!$writer instanceof QueueDbWriter) {
            return $payload;
        }

        if (\trim((string)($payload['operation'] ?? '')) === '') {
            $payload['operation'] = $writer->getOperation();
        }
        if ((int)($payload['queue_id'] ?? 0) <= 0 && $writer->getQueueId() > 0) {
            $payload['queue_id'] = $writer->getQueueId();
        }
        if (\trim((string)($payload['execution_token'] ?? '')) === '' && $writer->getExecutionToken() !== '') {
            $payload['execution_token'] = $writer->getExecutionToken();
        }
        if (\trim((string)($payload['job_key'] ?? '')) === '' && $writer->getJobKey() !== '') {
            $payload['job_key'] = $writer->getJobKey();
        }
        if (\trim((string)($payload['job_type'] ?? '')) === '' && $writer->getJobType() !== '') {
            $payload['job_type'] = $writer->getJobType();
        }

        return $payload;
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
        $saveTarget = \strtolower(\trim((string)$this->getRequestBodyValue('save_target', '')));
        if ($merge && $isAutosave && $payload === [] && ($saveTarget === 'plan' || ($saveTarget === '' && $this->isTruthyRequestFlag('save_plan_draft') && !$this->isTruthyRequestFlag('save_task_plan_draft')))) {
            try {
                $this->persistExistingPlanDraftOrThrow($session, $adminId, 'save_button');
            } catch (\Throwable $throwable) {
                return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
            }
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'plan_draft_saved',
                (string)__('第一阶段建站方案草稿已保存'),
                ['details' => ['autosave' => 1, 'source' => 'save_button']]
            );

            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('第一阶段建站方案草稿已保存'),
                'autosave' => true,
            ]);
        }
        if ($merge && $isAutosave && $payload === [] && ($saveTarget === 'task_plan' || ($saveTarget === '' && $this->isTruthyRequestFlag('save_task_plan_draft')))) {
            try {
                $this->persistExistingTaskPlanDraftOrThrow($session, $adminId, 'save_button');
            } catch (\Throwable $throwable) {
                return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
            }
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'task_plan_draft_saved',
                (string)__('第二阶段任务方案草稿已保存'),
                ['details' => ['autosave' => 1, 'source' => 'save_button']]
            );

            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('第二阶段任务方案草稿已保存'),
                'autosave' => true,
            ]);
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
        $payload = $this->normalizeTaskPlanStructuredScopePayload($payload);

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
        if ($isAutosave) {
            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('工作区已自动保存'),
                'autosave' => true,
            ]);
        }
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson(['success' => true, 'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true)]);
    }

    /**
     * Keep manual stage-two field edits structured-first: the frontend sends the edited
     * task tree in task_plan_structured and mirrors it into virtual_theme_plan.draft.
     * This normalizer also covers older clients that only send one of those locations.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeTaskPlanStructuredScopePayload(array $payload): array
    {
        $virtualThemePlan = \is_array($payload['virtual_theme_plan'] ?? null) ? $payload['virtual_theme_plan'] : [];
        $structured = \is_array($payload['task_plan_structured'] ?? null) ? $payload['task_plan_structured'] : [];
        $draft = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];

        if ($structured === [] && $this->looksLikeStructuredTaskPlanRoot($draft)) {
            $structured = $draft;
        }
        if ($structured === []) {
            return $payload;
        }

        $payload['task_plan_structured'] = $structured;
        $virtualThemePlan['draft'] = $structured;
        if (!isset($virtualThemePlan['draft_generated_at']) || \trim((string)$virtualThemePlan['draft_generated_at']) === '') {
            $virtualThemePlan['draft_generated_at'] = \date('Y-m-d H:i:s');
        }
        $payload['virtual_theme_plan'] = $virtualThemePlan;

        if (!\array_key_exists('task_plan_directory_tree', $payload)) {
            $payload['task_plan_directory_tree'] = \is_array($structured['task_directory_tree'] ?? null) ? $structured['task_directory_tree'] : [];
        }

        $summary = \is_array($payload['task_plan_summary'] ?? null) ? $payload['task_plan_summary'] : [];
        $summary['signature'] = (string)($summary['signature'] ?? $virtualThemePlan['signature'] ?? $structured['signature'] ?? '');
        $summary['shared_task_count'] = \count(\is_array($structured['shared_tasks'] ?? null) ? $structured['shared_tasks'] : []);
        $summary['page_task_count'] = \array_sum(\array_map(
            static fn($items): int => \is_array($items) ? \count($items) : 0,
            \is_array($structured['page_tasks'] ?? null) ? $structured['page_tasks'] : []
        ));
        if (!isset($summary['generation_source']) || \trim((string)$summary['generation_source']) === '') {
            $summary['generation_source'] = 'manual_structured_edit';
        }
        $payload['task_plan_summary'] = $summary;

        return $payload;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function looksLikeStructuredTaskPlanRoot(array $value): bool
    {
        if (\is_array($value['shared_tasks'] ?? null) && $value['shared_tasks'] !== []) {
            return true;
        }
        if (\is_array($value['page_tasks'] ?? null) && $value['page_tasks'] !== []) {
            return true;
        }
        if (\is_array($value['task_script_brief'] ?? null) && $value['task_script_brief'] !== []) {
            return true;
        }
        return false;
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
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
        if ($adminId <= 0 || $publicId === '' || $pageType === '' || !\in_array($action, ['create', 'delete', 'refine', 'rebuild'], true)) {
            return $this->jsonError('INVALID_PARAMS', 'Missing required params.', self::PARAMS_MUTATE_PLAN_BLOCK);
        }
        if ($action !== 'create' && $blockKey === '') {
            return $this->jsonError('INVALID_PARAMS', 'block_key is required for refine/rebuild/delete.', self::PARAMS_MUTATE_PLAN_BLOCK);
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
        $round = \max(1, (int)$this->getRequestBodyValue('round', ((int)($scope['plan_last_round'] ?? 0)) + 1));
        $targetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        if ($targetScope === '') {
            $targetScope = 'pages.' . $pageType . '.blocks.' . ($blockKey !== '' ? $blockKey : 'new');
        }
        $mutation = [
            'action' => $action,
            'page_type' => $pageType,
            'block_key' => $blockKey,
            'block_config' => $blockConfig,
        ];
        $scopePatch = [
            'plan_confirmed' => 0,
            'plan_last_prompt_mode' => 'mutate_plan_block',
            'plan_last_target_scope' => $targetScope,
            'plan_last_round' => $round,
            '_plan_sse_request' => [
                'prompt_mode' => 'mutate_plan_block',
                'instruction' => $instruction,
                'target_scope' => $targetScope,
                'round' => $round,
                'mutation' => $mutation,
            ],
        ];

        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            'plan',
            AiSiteAgentSession::STAGE_PLAN,
            $scopePatch,
            $pageType,
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING,
            [
                'stage_scope' => 'plan',
                'prompt_mode' => 'mutate_plan_block',
                'action' => $action,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'target_scope' => $targetScope,
                'instruction' => $instruction,
                'round' => $round,
                'mutation' => $mutation,
                'block_config' => $blockConfig,
            ]
        ));
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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
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

    private function handleMutateTaskPlanTask(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $bucket = \trim((string)$this->getRequestBodyValue('bucket', 'page'));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $action = \strtolower(\trim((string)$this->getRequestBodyValue('action', '')));
        $taskKey = \trim((string)$this->getRequestBodyValue('task_key', ''));
        if ($adminId <= 0 || $publicId === '' || !\in_array($action, ['refine', 'rebuild', 'delete', 'create'], true)) {
            return $this->jsonError('INVALID_PARAMS', 'Missing required params.', self::PARAMS_MUTATE_TASK_PLAN_TASK);
        }
        if ($action !== 'create' && $taskKey === '') {
            return $this->jsonError('INVALID_PARAMS', 'task_key is required for refine/rebuild/delete.', self::PARAMS_MUTATE_TASK_PLAN_TASK);
        }

        $taskConfig = [];
        $rawTaskConfig = $this->getRequestBodyValue('task_config', null);
        if ($rawTaskConfig !== null && $rawTaskConfig !== '') {
            $error = '';
            $taskConfig = $this->getRequestJsonObject('task_config', $error);
            if ($error !== '') {
                return $this->fetchJson(['success' => false, 'message' => $error]);
            }
        }
        if ($taskConfig === []) {
            $rawTaskPatch = $this->getRequestBodyValue('task_patch', null);
            if ($rawTaskPatch !== null && $rawTaskPatch !== '') {
                $error = '';
                $taskConfig = $this->getRequestJsonObject('task_patch', $error);
                if ($error !== '') {
                    return $this->fetchJson(['success' => false, 'message' => $error]);
                }
            }
        }
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        if ($instruction !== '' && !isset($taskConfig['instruction'])) {
            $taskConfig['instruction'] = $instruction;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session not found.', self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $round = \max(1, (int)$this->getRequestBodyValue('round', ((int)($scope['virtual_theme_plan']['last_round'] ?? 0)) + 1));
        $targetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        $ports = new AiSiteAgentMutateTaskPlanTaskOperationPorts(
            startOperation: fn (...$args): array => $this->startOperation(...$args),
            buildWorkspaceState: fn (...$args): array => $this->buildWorkspaceState(...$args)
        );

        return $this->fetchJson(
            $this->mutateTaskPlanTaskOperationService->run(
                $session,
                $adminId,
                $scope,
                $bucket,
                $pageType,
                $action,
                $taskKey,
                $taskConfig,
                $instruction,
                $round,
                $targetScope,
                $ports
            )
        );
    }

    private function buildOperationStreamUrl(string $publicId, string $executionToken): string
    {
        return $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/operation-sse', ['public_id' => $publicId, 'execution_token' => $executionToken]);
    }

    private function supportsBackgroundOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan', 'build', 'block_regenerate', 'regenerate_page', 'publish'], true);
    }

    private function shouldKeepQueuedObserverStreamOpen(string $operation): bool
    {
        return \in_array($operation, ['plan', 'task_plan', 'build', 'block_regenerate'], true);
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

    private function getFrameworkQueryService(): FrameworkQueryService
    {
        return ObjectManager::getInstance(FrameworkQueryService::class);
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
        $rows = $this->getFrameworkQueryService()->execute('websites', 'getRegistrarAccounts', ['status' => 'active']);
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

    /**
     * 薄壳转发：定位/创建 Websites 侧镜像会话。
     * 实际实现迁移到 {@see AiSiteAgentWebsitesMirrorService::ensureMirrorSession}（R4.3）。
     */
    private function ensureLinkedWebsitesMirrorSession(AiSiteAgentSession $session, int $adminId): ?WebsitesAiSiteBuilderSession
    {
        return $this->websitesMirrorService->ensureMirrorSession($session, $adminId);
    }

    /**
     * 薄壳转发：把 PageBuilder scope 归一化成 Websites 可写 scope。
     * 实际实现迁移到 {@see AiSiteAgentWebsitesMirrorService::buildScopeFromSource}（R4.3）。
     *
     * @return array<string, mixed>
     */
    private function buildLinkedWebsitesScopeFromPageBuilderSession(AiSiteAgentSession $session): array
    {
        return $this->websitesMirrorService->buildScopeFromSource($session);
    }

    /**
     * 薄壳转发：把 Websites 侧 scope 变更 merge 回 PageBuilder 会话。
     * 实际实现迁移到 {@see AiSiteAgentWebsitesMirrorService::syncScopeBack}（R4.3）。
     */
    private function syncPageBuilderScopeFromLinkedWebsitesSession(
        AiSiteAgentSession $pageBuilderSession,
        WebsitesAiSiteBuilderSession $websitesSession,
        int $adminId
    ): void {
        $this->websitesMirrorService->syncScopeBack($pageBuilderSession, $websitesSession, $adminId);
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
     *   page_types_changed:bool,
     *   source_signature_changed:bool
     * }
     */
    private function resolvePlanStartDecision(array $scope): array
    {
        $requestedPlanLocale = (string)$this->resolvePlanLocale($scope);
        $lastGeneratedPlanLocale = \trim((string)($scope['plan_generated_locale'] ?? ''));
        $requestedPageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $lastGeneratedPageTypes = \is_array($scope['plan_generated_page_types'] ?? null) ? $scope['plan_generated_page_types'] : [];
        $hasPlanDraft = !$this->isPlanContentEmpty($scope);
        $currentPlanSourceSignature = $hasPlanDraft ? $this->buildPlanSourceSignature($scope) : '';
        $lastGeneratedPlanSourceSignature = $hasPlanDraft ? $this->resolveLastGeneratedPlanSourceSignature($scope) : '';
        $planLocaleChanged = $hasPlanDraft
            && $requestedPlanLocale !== ''
            && $lastGeneratedPlanLocale !== ''
            && $requestedPlanLocale !== $lastGeneratedPlanLocale;
        $pageTypesChanged = $hasPlanDraft && !$this->isSamePageTypeSelection($requestedPageTypes, $lastGeneratedPageTypes);
        $sourceSignatureChanged = $hasPlanDraft
            && $currentPlanSourceSignature !== ''
            && $lastGeneratedPlanSourceSignature !== ''
            && $currentPlanSourceSignature !== $lastGeneratedPlanSourceSignature
            && !$planLocaleChanged
            && !$pageTypesChanged;
        if ($pageTypesChanged) {
            return [
                'action' => 'rebuild',
                'rebuild_required' => true,
                'translation_required' => false,
                'plan_locale_changed' => $planLocaleChanged,
                'page_types_changed' => true,
                'source_signature_changed' => false,
            ];
        }
        if ($planLocaleChanged) {
            return [
                'action' => 'translate',
                'rebuild_required' => false,
                'translation_required' => true,
                'plan_locale_changed' => true,
                'page_types_changed' => false,
                'source_signature_changed' => false,
            ];
        }
        if ($sourceSignatureChanged) {
            return [
                'action' => 'rebuild',
                'rebuild_required' => true,
                'translation_required' => false,
                'plan_locale_changed' => false,
                'page_types_changed' => false,
                'source_signature_changed' => true,
            ];
        }
        return [
            'action' => $hasPlanDraft ? 'reuse' : 'generate',
            'rebuild_required' => false,
            'translation_required' => false,
            'plan_locale_changed' => false,
            'page_types_changed' => false,
            'source_signature_changed' => false,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildPlanSourceSignature(array $scope): string
    {
        $normalizedScope = \array_replace($scope, [
            'plan_locale' => $this->resolvePlanLocale($scope),
            'page_types' => $this->scopeCompatibilityService->resolveScopedPageTypes($scope),
        ]);

        return $this->executionBlueprintService->buildSourceSignature($normalizedScope);
    }

    /**
     * Older workspaces may not yet have persisted the stage-one input signature.
     * Reconstruct a best-effort signature from the last generated profile snapshot.
     *
     * @param array<string, mixed> $scope
     */
    private function resolveLastGeneratedPlanSourceSignature(array $scope): string
    {
        $storedSignature = \trim((string)($scope['plan_generated_source_signature'] ?? ''));
        if ($storedSignature !== '') {
            return $storedSignature;
        }

        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $generatedPageTypes = \is_array($scope['plan_generated_page_types'] ?? null)
            ? \array_values(\array_map('strval', $scope['plan_generated_page_types']))
            : [];
        $fallbackScope = [
            'site_title' => (string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? ''),
            'site_tagline' => (string)($websiteProfile['site_tagline'] ?? $scope['site_tagline'] ?? ''),
            'target_domain' => (string)($websiteProfile['target_domain'] ?? $scope['target_domain'] ?? ''),
            'brief_description' => (string)($websiteProfile['brief_description'] ?? $scope['brief_description'] ?? $scope['user_description'] ?? ''),
            'default_locale' => (string)($websiteProfile['default_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? ''),
            'plan_locale' => (string)($scope['plan_generated_locale'] ?? $scope['plan_locale'] ?? $scope['default_locale'] ?? $scope['default_language'] ?? ''),
            'page_types' => $generatedPageTypes !== [] ? $generatedPageTypes : $this->scopeCompatibilityService->resolveScopedPageTypes($scope),
        ];
        $hasFallbackData = \trim((string)$fallbackScope['site_title']) !== ''
            || \trim((string)$fallbackScope['site_tagline']) !== ''
            || \trim((string)$fallbackScope['target_domain']) !== ''
            || \trim((string)$fallbackScope['brief_description']) !== ''
            || \trim((string)$fallbackScope['default_locale']) !== ''
            || \trim((string)$fallbackScope['plan_locale']) !== ''
            || $fallbackScope['page_types'] !== [];
        if (!$hasFallbackData) {
            return '';
        }

        return $this->executionBlueprintService->buildSourceSignature($fallbackScope);
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
    private function shouldRestartPlanOperationForScopeChange(
        array $activeOperation,
        string $requestedPlanLocale,
        array $requestedPageTypes,
        string $requestedSourceSignature = ''
    ): bool
    {
        $details = \is_array($activeOperation['details'] ?? null) ? $activeOperation['details'] : [];
        $runningPlanLocale = \trim((string)($details['plan_locale'] ?? ''));
        $runningPageTypes = \is_array($details['page_types'] ?? null) ? $details['page_types'] : [];
        $runningSourceSignature = \trim((string)($details['source_signature'] ?? ''));
        if ($runningPlanLocale === '' && $runningPageTypes === []) {
            return true;
        }
        if ($runningSourceSignature !== '' && $requestedSourceSignature !== '' && $runningSourceSignature !== $requestedSourceSignature) {
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
                'message' => (string)__('检测到阶段一输入已变更，已取消旧任务并准备重新生成'),
            ],
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
        );
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            'plan',
            'operation_cancelled',
            (string)__('检测到阶段一输入变更，已取消旧任务并重新排队生成'),
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

    private function isTruthyRequestFlag(string $key): bool
    {
        $value = $this->getRequestBodyValue($key, '');
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (\is_array($value)) {
            return false;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeCompactJson(array $payload): string
    {
        try {
            return (string)\json_encode(
                $payload,
                \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return '';
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
