<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionEvent;
use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Model\VirtualThemeComponent;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentQueueObserverStreamService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentRegeneratePageOperationPorts;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentRegeneratePageOperationService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceEntryNoticeService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentStreamCodecService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceBridgeService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspacePreviewService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceStateHelperService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWebsitesMirrorService;
use GuoLaiRen\PageBuilder\Service\AiSiteBlockPartialPatchService;
use GuoLaiRen\PageBuilder\Service\AiSiteAssetManifestService;
use GuoLaiRen\PageBuilder\Service\AiSiteAutoAssetGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSiteDraftWebsiteService;
use GuoLaiRen\PageBuilder\Service\AiSiteMaterializationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageComponentGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePageBlueprintService;
use GuoLaiRen\PageBuilder\Service\AiSitePublishService;
use GuoLaiRen\PageBuilder\Service\AiSiteProfileGenerationService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskScheduler;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonTaskService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonStateService;
use GuoLaiRen\PageBuilder\Service\AiSiteQualityGateService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueStateService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceDebugDefaults;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AiSitePreviewLinkRewriteService;
use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlocksBuildService;
use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonGenerationService;
use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use GuoLaiRen\PageBuilder\Service\AI\DesignDirection\DesignDirectionService;
use GuoLaiRen\PageBuilder\Service\AI\Skill\CustomSkillRepository;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use Weline\Ai\Model\AiSkill;
use Weline\Ai\Service\Skill\AdapterSkillResolver;
use Weline\Ai\Service\Skill\SkillRegistry as CoreSkillRegistry;
use Weline\Ai\Service\Skill\SkillRepository as AiSkillRepository;
use Weline\Framework\App\State;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Http\Sse\LastEventIdResolver;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Server\Log\WlsLogger;
use Weline\Server\Service\LocalDomainPolicy;
use Weline\Websites\Model\AiSiteBuilderSession as WebsitesAiSiteBuilderSession;
use Weline\Websites\Service\AiWorkbench\DomainPurchaseWorkbenchService as WebsitesDomainPurchaseWorkbenchService;
use Weline\Websites\Service\AiWorkbench\SessionService as WebsitesSessionService;
use Weline\Websites\Service\WebsiteAgentService;

#[Acl('GuoLaiRen_PageBuilder::ai_site_agent', 'Ai Site Agent', 'mdi-robot-outline', 'PageBuilder AI site agent permission: ai_site_agent', 'Weline_Backend::page_builder_group')]
class AiSiteAgent extends BaseController
{
    private const REQUEST_CTX_AI_CHUNK_FORWARDER = 'pagebuilder.ai.chunk.forwarder';
    private const REQUEST_CTX_QUEUE_CREATED_HOOK = 'pagebuilder.ai.queue.created';
    private const REQUEST_CTX_OBSERVER_QUEUE_SETTLE_DELAY_MS = 'pagebuilder.ai.observer.queue_settle_delay_ms';
    private const PARAMS_PUBLIC_ID = ['public_id'];
    private const PARAMS_OPERATION_SSE = ['public_id', 'execution_token'];
    private const PARAMS_REGENERATE = ['public_id', 'page_type'];
    private const PARAMS_REFINE_PLAN_PAGE = ['public_id', 'page_type', 'instruction'];
    private const PARAMS_REFINE_COMPONENT = ['public_id', 'page_type', 'component_code', 'instruction'];
    private const PARAMS_PATCH_BLOCK = ['public_id', 'page_type', 'block_id|component_code', 'instruction'];
    private const PARAMS_UPDATE_BLOCK = ['public_id', 'page_type', 'block_id', 'block_config'];
    private const PARAMS_MUTATE_PLAN_BLOCK = ['public_id', 'page_type', 'action=create|delete|refine|rebuild', 'block_key required except create', 'block_config'];
    private const WORKSPACE_STREAM_STATE_PERSIST = false;
    private const STREAM_LEASE_SCOPE_KEY = '_workspace_stream_lease';
    private const STREAM_LEASE_TTL_SEC = 60;
    private const WORKSPACE_STREAM_MAX_EVENT_REPLAY = 300;
    private const WORKSPACE_FAST_VIEW_ARTIFACT_KEYS_BY_STAGE = [
        AiSiteAgentSession::STAGE_PLAN => ['plan_json', 'plan_markdown'],
        AiSiteAgentSession::STAGE_VISUAL_EDIT => ['plan_json', 'plan_markdown'],
        AiSiteAgentSession::STAGE_PUBLISH => ['plan_json', 'plan_markdown'],
    ];
    private const WORKSPACE_FAST_VIEW_SCOPE_KEYS = [
        '_artifact_refs',
        'active_operation',
        'active_operations',
        'asset_image_generation_failures',
        'asset_manifest',
        'brief_description',
        'build_queue_info',
        'build_summary',
        'plan_json_task_summary',
        'can_publish',
        'default_language',
        'default_locale',
        'design_direction_code',
        'design_direction_custom_id',
        'design_direction_hash',
        'design_direction_locked',
        'design_direction_match_reason',
        'design_direction_mode',
        'design_direction_snapshot',
        'draft_website_id',
        'extra_page_types_panel_open',
        'fake_mode',
        'latest_build_failed',
        'latest_build_failure',
        'locales',
        'page_types',
        'page_types_user_customized',
        'pending_generation_page_types',
        'plan_json',
        'plan_locale',
        'plan_queue_info',
        'preferred_registrar_account_id',
        'pre_publish_visual_urls',
        'preview_full_url',
        'preview_page_id',
        'preview_page_type',
        'publish_blocked_by_latest_ai_failure',
        'publish_blocked_reason',
        'publish_status',
        'recommended_domain_list',
        'recommended_registrar_label',
        'reference_images',
        'registrar_account_id',
        'retryable_ai_failure_count',
        'retryable_ai_failures',
        'selected_domain',
        'selected_skill_codes',
        'selected_website_id',
        'site_profile_manual',
        'site_tagline',
        'site_title',
        'target_domain',
        'user_description',
        'verified_assets',
        'virtual_theme_id',
        'visual_edit_url',
        'visual_preview_url',
        'website_id',
        'website_profile',
        'workspace_status',
        'workspace_track',
    ];
    /**
     * Keys retained for the lightweight workspace view payload.
     */
    private const STALE_ACTIVE_OPERATION_TTL_SEC = 600;
    private const OBSERVER_QUEUE_SETTLE_DELAY_MS = 3000;
    private const OBSERVER_QUEUE_PROGRESS_POLL_INTERVAL_MS = 250;
    private const OBSERVER_QUEUE_PROGRESS_MAX_OBSERVE_MS = 720000;
    private const PLAN_JSON_TASK_MAX_GENERATION_ATTEMPTS = 2;
    private const DEFAULT_PAGE_SECTION_BUILD_DISPATCH_WINDOW = 5;
    private const AI_SITE_QUEUE_CONTENT_LIGHT_FIELDS = 'queue_id,type_id,pid,name,module,status,finished,start_at,end_at,biz_key,process';
    private const AI_SITE_QUEUE_CONTENT_PAYLOAD_FIELDS = 'queue_id,type_id,pid,name,module,status,finished,start_at,end_at,biz_key,content,process,result';
    private const AI_SITE_QUEUE_MAX_CONTENT_JSON_DECODE_BYTES = 262144;

    private readonly AiSiteAgentSessionService $sessionService;
    private readonly AiSiteScopeCompatibilityService $scopeCompatibilityService;
    private readonly AiSiteProfileGenerationService $profileGenerationService;
    private readonly AiSitePlanJsonTaskService $planJsonTaskService;
    private readonly AiSiteDraftWebsiteService $draftWebsiteService;
    private readonly AiSiteMaterializationService $materializationService;
    private readonly AiSiteVirtualThemeService $virtualThemeService;
    private readonly AiSitePublishService $publishService;
    private readonly AiSiteVisualUrlService $visualUrlService;
    private readonly AiSiteHtmlBlocksBuildService $htmlBlocksBuildService;
    private readonly AiSitePageBlueprintService $pageBlueprintService;
    private readonly AiSitePlanJsonGenerationService $planJsonGenerationService;
    private readonly AiSiteAgentStreamCodecService $streamCodecService;
    private readonly AiSiteAgentWorkspaceBridgeService $workspaceBridgeService;
    private readonly AiSiteAgentWorkspacePreviewService $workspacePreviewService;
    private readonly AiSiteQueueStateService $queueStateService;
    private readonly AiSiteAgentQueueObserverHelperService $queueObserverHelperService;
    private readonly AiSiteAgentWebsitesMirrorService $websitesMirrorService;
    private readonly AiSiteAgentWorkspaceStateHelperService $workspaceStateHelperService;
    private readonly AiSiteAgentQueueObserverStreamService $queueObserverStreamService;
    private readonly AiSiteAgentRegeneratePageOperationService $regeneratePageOperationService;
    private readonly AiSiteAgentWorkspaceEntryNoticeService $workspaceEntryNoticeService;
    private ?AiSiteBlockPartialPatchService $blockPartialPatchService = null;
    private ?AiSitePlanJsonStateService $planJsonStateService = null;
    private readonly Url $url;

    public function __construct(
        AiSiteAgentSessionService $sessionService,
        ?AiSiteScopeCompatibilityService $scopeCompatibilityService = null,
        ?AiSiteProfileGenerationService $profileGenerationService = null,
        ?AiSitePlanJsonTaskService $planJsonTaskService = null,
        ?AiSiteDraftWebsiteService $draftWebsiteService = null,
        ?AiSiteMaterializationService $materializationService = null,
        ?AiSiteVirtualThemeService $virtualThemeService = null,
        ?AiSitePublishService $publishService = null,
        ?AiSiteVisualUrlService $visualUrlService = null,
        ?AiSiteHtmlBlocksBuildService $htmlBlocksBuildService = null,
        ?AiSitePageBlueprintService $pageBlueprintService = null,
        ?AiSitePlanJsonGenerationService $planJsonGenerationService = null,
        ?AiSiteAgentStreamCodecService $streamCodecService = null,
        ?AiSiteAgentWorkspaceBridgeService $workspaceBridgeService = null,
        ?AiSiteAgentWorkspacePreviewService $workspacePreviewService = null,
        ?AiSiteQueueStateService $queueStateService = null,
        ?AiSiteAgentQueueObserverHelperService $queueObserverHelperService = null,
        ?Url $url = null,
        ?AiSiteAgentWebsitesMirrorService $websitesMirrorService = null,
        ?AiSiteAgentWorkspaceStateHelperService $workspaceStateHelperService = null,
        ?AiSiteAgentQueueObserverStreamService $queueObserverStreamService = null,
        ?AiSiteAgentRegeneratePageOperationService $regeneratePageOperationService = null,
        ?AiSiteAgentWorkspaceEntryNoticeService $workspaceEntryNoticeService = null,
    ) {
        $this->sessionService = $sessionService;
        $this->scopeCompatibilityService = $scopeCompatibilityService
            ?? ObjectManager::getInstance(AiSiteScopeCompatibilityService::class);
        $this->profileGenerationService = $profileGenerationService
            ?? ObjectManager::getInstance(AiSiteProfileGenerationService::class);
        $this->planJsonTaskService = $planJsonTaskService
            ?? ObjectManager::getInstance(AiSitePlanJsonTaskService::class);
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
        $this->planJsonGenerationService = $planJsonGenerationService
            ?? ObjectManager::getInstance(AiSitePlanJsonGenerationService::class);
        $this->streamCodecService = $streamCodecService
            ?? ObjectManager::getInstance(AiSiteAgentStreamCodecService::class);
        $this->workspaceBridgeService = $workspaceBridgeService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspaceBridgeService::class);
        $this->workspacePreviewService = $workspacePreviewService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspacePreviewService::class);
        $this->queueStateService = $queueStateService
            ?? ObjectManager::getInstance(AiSiteQueueStateService::class);
        $this->queueObserverHelperService = $queueObserverHelperService
            ?? ObjectManager::getInstance(AiSiteAgentQueueObserverHelperService::class);
        $this->url = $url ?? ObjectManager::getInstance(Url::class);
        $this->websitesMirrorService = $websitesMirrorService
            ?? ObjectManager::getInstance(AiSiteAgentWebsitesMirrorService::class);
        $this->workspaceStateHelperService = $workspaceStateHelperService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspaceStateHelperService::class);
        $this->queueObserverStreamService = $queueObserverStreamService
            ?? ObjectManager::getInstance(AiSiteAgentQueueObserverStreamService::class);
        $this->regeneratePageOperationService = $regeneratePageOperationService
            ?? ObjectManager::getInstance(AiSiteAgentRegeneratePageOperationService::class);
        $this->workspaceEntryNoticeService = $workspaceEntryNoticeService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspaceEntryNoticeService::class);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_index', 'Ai Site Agent Index', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_index', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function index(): string
    {
        $startedAt = \microtime(true);
        $workbenchHomeUrl = $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']);
        $showAll = (string)$this->request->getGet('show', '') === 'all';

        $adminId = (int)$this->getLoginUserId();
        $recent = $adminId > 0 ? $this->sessionService->listRecentSessionsForAdmin($adminId, $showAll ? 200 : 30) : [];
        $directionOptions = $adminId > 0 ? \array_values($this->designDirectionService()->listDirections($adminId, false)) : [];

        $this->assign('title', __('PageBuilder AI site workspace'));
        $this->assign('recent_sessions', $recent);
        $this->assign('workbench_home_url', $workbenchHomeUrl);
        $this->assign('show_all_sessions', $showAll);
        $this->assign('design_direction_options', $directionOptions);
        $this->assign('design_direction_list_url', $this->url->getBackendUrlPath('ai/backend/style/post-catalog'));
        $this->assign('design_direction_match_url', $this->url->getBackendUrlPath('ai/backend/style/post-match'));
        $this->assign('design_direction_manage_url', $this->url->getBackendUrl('ai/backend/style'));

        $html = $this->fetch();
        $this->logHotPathStage('index.total', $startedAt, [
            'recent_count' => \count($recent),
            'response_bytes' => \strlen($html),
        ]);

        return $html;
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'Ai Site Agent Workspace', 'mdi-clipboard-text-outline', 'PageBuilder AI site agent permission: ai_site_agent_workspace', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function workspace(): string
    {
        $startedAt = \microtime(true);
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid workspace parameters.'), self::PARAMS_PUBLIC_ID);
        }
        if ($adminId <= 0 || $publicId === '') {
            $this->assign('title', __('PageBuilder AI site workspace'));
            $this->assign('error_message', __('Invalid workspace parameters.'));
            return $this->fetch('workspace-error');
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('Session not found.'), self::PARAMS_PUBLIC_ID);
        }
        if ($session === null) {
            $this->assign('title', __('PageBuilder AI site workspace'));
            $this->assign('error_message', __('Invalid workspace parameters.'));
            return $this->fetch('workspace-error');
        }

        $buildStateStartedAt = \microtime(true);
        // Read-only page rendering should not persist normalized state back to DB.
        // Otherwise a simple workspace refresh can race with SSE/autosave/build writes
        // and occasionally turn a GET into an empty response when persistence fails.
        $state = $this->buildWorkspaceFastViewState($session, $adminId);
        $this->logHotPathStage('workspace.build_state', $buildStateStartedAt, [
            'public_id' => $publicId,
            'stage' => $session->getStage(),
        ]);
        $expertUi = false;
        $viewState = $this->pruneWorkspaceStateForView($state);
        if (\is_array($viewState['scope'] ?? null)) {
            $viewState['scope'] = $this->applyAutoDesignDirectionToScope($viewState['scope'], $adminId);
            $directionState = $this->designDirectionService()->buildWorkspaceDirectionState($viewState['scope']);
            $viewState['design_direction'] = $this->pruneWorkspaceDesignDirectionStateForView($directionState);
            $viewState['design_direction_snapshot'] = $this->pruneWorkspaceDesignDirectionSnapshotForView(
                \is_array($viewState['scope']['design_direction_snapshot'] ?? null) ? $viewState['scope']['design_direction_snapshot'] : []
            );
            $viewState['design_direction_match_reason'] = (string)($viewState['scope']['design_direction_match_reason'] ?? ($directionState['match_reason'] ?? ''));
        }
        $linkedWebsitesSession = $this->loadExistingLinkedWebsitesMirrorSessionFromScope(
            \is_array($viewState['scope'] ?? null) ? $viewState['scope'] : [],
            $adminId
        );
        $linkedWebsitesScope = $linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession
            ? $linkedWebsitesSession->getScopeArray()
            : [];
        $domainPurchaseState = $linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession
            ? $this->workspaceBridgeService->buildDomainPurchaseState($linkedWebsitesSession)
            : [];
        $domainChoiceEnvironment = $this->workspaceBridgeService->buildDomainChoiceEnvironment();
        $isLocalDomainEnvironment = !empty($domainChoiceEnvironment['is_local']);
        $registrarAccounts = $this->workspaceBridgeService->buildRegistrarAccountOptions();
        $registrarSelection = $this->workspaceBridgeService->buildWorkspaceRegistrarSelection(
            $linkedWebsitesScope,
            \is_array($viewState['scope'] ?? null) ? $viewState['scope'] : [],
            $registrarAccounts,
            $isLocalDomainEnvironment
        );
        $recommendedDomainList = $registrarSelection['recommended_domain_list'];
        $recommendedRegistrarLabel = $registrarSelection['recommended_registrar_label'];
        $preferredRegistrarAccountId = $registrarSelection['preferred_registrar_account_id'];
        if ($preferredRegistrarAccountId <= 0) { $preferredRegistrarAccountId = 0; }
            // Comment removed.
        if ($recommendedRegistrarLabel === '' && $preferredRegistrarAccountId > 0) {
            foreach ($registrarAccounts as $account) {
                if ((int)($account['account_id'] ?? 0) !== $preferredRegistrarAccountId) {
                    continue;
                }
                $recommendedRegistrarLabel = \trim((string)($account['label'] ?? ''));
                break;
            }
        }

        $this->assign('title', __('PageBuilder AI site workspace'));
        $this->assign('session', $session);
        $this->assign('workspace_state', $viewState);
        $this->assign('scope', $viewState['scope']);
        $this->assign('merge_scope_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-merge-scope'));
        $this->assign('set_stage_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-set-stage'));
        $this->assign('start_plan_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-plan'));
        $this->assign('skill_list_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-skill-list'));
        $this->assign('skill_save_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-skill-save'));
        $this->assign('skill_disable_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-skill-disable'));
        $this->assign('design_direction_list_url', $this->url->getBackendUrlPath('ai/backend/style/post-catalog'));
        $this->assign('design_direction_save_url', $this->url->getBackendUrlPath('ai/backend/style/post-save'));
        $this->assign('design_direction_disable_url', $this->url->getBackendUrlPath('ai/backend/style/post-disable'));
        $this->assign('design_direction_clone_builtin_url', $this->url->getBackendUrlPath('ai/backend/style/post-clone-builtin'));
        $this->assign('design_direction_match_url', $this->url->getBackendUrlPath('ai/backend/style/post-match'));
        $this->assign('design_direction_manage_url', $this->url->getBackendUrl('ai/backend/style'));
        $this->assign('confirm_plan_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-confirm-plan'));
        $this->assign('sort_plan_blocks_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-sort-plan-blocks'));
        $this->assign('mutate_plan_block_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-mutate-plan-block'));
        $this->assign('refine_plan_page_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-refine-plan-page'));
        $this->assign('plan_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/plan-sse'));
        $this->assign('resume_build_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-resume-build'));
        $this->assign('run_virtual_theme_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_build_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_regenerate_page_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-regenerate-page'));
        $this->assign('start_refine_component_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-refine-component'));
        $this->assign('start_patch_block_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-patch-block'));
        $this->assign('retry_ai_operation_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-retry-ai-operation'));
        $this->assign('update_block_config_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-update-block-config'));
        $this->assign('start_publish_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-publish'));
        $this->assign('operation_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/operation-sse', ['public_id' => $publicId]));
        $this->assign('workspace_stream_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/get-stream-sse', ['public_id' => $publicId]));
        $this->assign('switch_preview_page_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-switch-preview-page'));
        $this->assign('workspace_state_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-workspace-state'));
        $this->assign('publish_check_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-publish-checklist'));
        $this->assign('delete_workspace_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-delete-workspace'));
        $this->assign('recommend_domain_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-recommend-domain'));
        $this->assign('check_domain_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-check-domain'));
        $this->assign('start_domain_purchase_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-start-domain-purchase'));
        $this->assign('domain_purchase_sse_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/domain-purchase-sse'));
        $this->assign('domain_purchase_state', $domainPurchaseState);
        $this->assign('recommended_domain_list', $recommendedDomainList);
        $this->assign('recommended_registrar_label', $recommendedRegistrarLabel);
        $this->assign('preferred_registrar_account_id', $preferredRegistrarAccountId);
        $this->assign('registrar_accounts', $registrarAccounts);
        $this->assign('domain_choice_environment', $domainChoiceEnvironment);
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
        $debugDefaultsEnabled = (int)($scope['fake_mode'] ?? 0) === 1;
        $rawTitle = $debugDefaultsEnabled
            ? $systemConfig->getConfig('ai_site_agent_debug_site_title', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND)
            : null;
        $effectiveDebugTitle = $debugDefaultsEnabled
            ? ($rawTitle === null ? AiSiteAgentWorkspaceDebugDefaults::SITE_TITLE : \trim((string)$rawTitle))
            : '';
        $rawBrief = $debugDefaultsEnabled
            ? $systemConfig->getConfig('ai_site_agent_debug_brief_description', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND)
            : null;
        $effectiveDebugBrief = $debugDefaultsEnabled
            ? ($rawBrief === null ? AiSiteAgentWorkspaceDebugDefaults::BRIEF_DESCRIPTION : \trim((string)$rawBrief))
            : '';
        $rawLocale = $debugDefaultsEnabled
            ? $systemConfig->getConfig('ai_site_agent_debug_default_locale', 'GuoLaiRen_PageBuilder', SystemConfig::area_BACKEND)
            : null;
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

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_index', 'Ai Site Agent Index', 'mdi-compass-outline', 'PageBuilder AI site agent permission: ai_site_agent_index', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function designDirections(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $items = $adminId > 0 ? \array_values($this->designDirectionService()->listDirections($adminId, true)) : [];
        $this->assign('title', __('AI Design Directions'));
        $this->assign('design_direction_items', $items);
        $this->assign('design_direction_list_url', $this->url->getBackendUrlPath('ai/backend/style/post-catalog'));
        $this->assign('design_direction_save_url', $this->url->getBackendUrlPath('ai/backend/style/post-save'));
        $this->assign('design_direction_disable_url', $this->url->getBackendUrlPath('ai/backend/style/post-disable'));
        $this->assign('design_direction_delete_url', $this->url->getBackendUrlPath('ai/backend/style/post-delete'));
        $this->assign('design_direction_clone_builtin_url', $this->url->getBackendUrlPath('ai/backend/style/post-clone-builtin'));
        $this->assign('back_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/index'));

        return $this->fetch('design-directions');
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'Ai Site Agent Workspace', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_workspace', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function workspacePreview(): void
    {
        $startedAt = \microtime(true);
        $resolveContextStartedAt = \microtime(true);
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $requestedPageType = \trim((string)$this->request->getGet('page_type', ''));
        $context = $this->workspacePreviewService->buildPreviewContext(
            $adminId,
            $publicId,
            $requestedPageType,
            \trim((string)$this->request->getGet('style_code', '')),
            \trim((string)$this->request->getGet('locale', '')),
            (int)$this->request->getGet('virtual_theme_id', 0)
        );
        $this->logHotPathStage('workspace_preview.resolve_context', $resolveContextStartedAt, [
            'public_id' => $publicId,
            'page_type' => $requestedPageType,
            'resolved' => $context !== null ? 1 : 0,
        ]);
        if ($context === null) {
            $html = $this->renderWorkspacePreviewUnavailablePage($adminId, $publicId, $requestedPageType);
            $this->logHotPathStage('workspace_preview.total', $startedAt, [
                'status' => 404,
                'public_id' => $publicId,
                'page_type' => $requestedPageType,
            ]);
            throw new ResponseTerminateException(404, $html, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $isVisualEditor = $this->request->getGet('visual_editor') === '1';
        $renderMode = $isVisualEditor
            ? \GuoLaiRen\PageBuilder\Service\PageRenderService::MODE_VISUAL
            : \GuoLaiRen\PageBuilder\Service\PageRenderService::MODE_PREVIEW;

        $renderStartedAt = \microtime(true);
        $html = $this->workspacePreviewService->renderPreviewHtml($context, $isVisualEditor);
        $this->logHotPathStage('workspace_preview.render', $renderStartedAt, [
            'page_type' => (string)$context['page']->getData(Page::schema_fields_TYPE),
            'virtual_theme_id' => (int)$context['virtual_theme_id'],
            'render_mode' => $renderMode,
            'response_bytes' => \strlen($html),
        ]);
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
     * Return an iframe-safe placeholder when the preview context cannot be resolved.
     */
    private function renderWorkspacePreviewUnavailablePage(int $adminId, string $publicId, string $requestedPageType): string
    {
        $sessionAccessible = false;
        $planJsonConfirmed = false;
        $buildQueueRunning = false;
        if ($adminId > 0 && $publicId !== '' && $requestedPageType !== '') {
            $session = $this->sessionService->loadByPublicId($publicId, $adminId);
            if ($session !== null) {
                $sessionAccessible = true;
                $scope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
                $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
                $planJsonConfirmed = $this->planJsonStateService()->isConfirmed($planJson);
                $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
                $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
                $activeBuild = \is_array($activeOperations['build'] ?? null) ? $activeOperations['build'] : [];
                $activeOpName = \strtolower(\trim((string)($activeOperation['operation'] ?? '')));
                $activeOpStatus = \strtolower(\trim((string)($activeOperation['queue_status'] ?? '')));
                $buildStatus = \strtolower(\trim((string)($activeBuild['queue_status'] ?? '')));
                $buildQueueRunning = ($activeOpName === 'build' && \in_array($activeOpStatus, ['pending', 'queued', 'running', 'processing'], true))
                    || \in_array($buildStatus, ['pending', 'queued', 'running', 'processing'], true);
            }
        }
        // Comment removed.
        return $this->template('GuoLaiRen_PageBuilder::templates/Backend/AiSiteAgent/workspace-preview-unavailable.phtml', [
            'pb_preview_unavailable_session_ok' => $sessionAccessible,
            'pb_preview_unavailable_plan_json' => ['confirmed' => $planJsonConfirmed ? 1 : 0],
            'pb_preview_unavailable_build_queue_running' => $buildQueueRunning,
            'pb_preview_unavailable_page_type' => $requestedPageType,
        ]);
    }

    public function postMergeScope(): string
    {
        return $this->mutateScope();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSortPlanBlocks(): string
    {
        return $this->handleSortPlanBlocks();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postMutatePlanBlock(): string
    {
        return $this->handleMutatePlanBlock();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRefinePlanPage(): string
    {
        return $this->handleRefinePlanPage();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSetStage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $stage = $this->scopeCompatibilityService->normalizeStage((string)$this->getRequestBodyValue('stage', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $allowed = \array_column($this->getStageOptions(), 'value');
        if (!\in_array($stage, $allowed, true)) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        if ($stage === AiSiteAgentSession::STAGE_PUBLISH) {
            $state = $this->buildWorkspaceFastViewState($session, $adminId);
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForBuildOperation($session)
            );
            $fastScope = \is_array($state['scope'] ?? null) ? $state['scope'] : [];
            foreach (['active_operation', 'active_operations', 'build_queue_info'] as $runtimeKey) {
                if (\array_key_exists($runtimeKey, $fastScope)) {
                    $scope[$runtimeKey] = $fastScope[$runtimeKey];
                }
            }
            $completionGate = $this->planJsonTaskService->inspectBuildCompletionGate($scope);
            $publishBlock = !empty($completionGate['passed']) ? ['blocked' => false] : $this->resolveLatestPublishBlockingAiBuildFailure(
                $scope,
                \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
                \is_array($scope['build_queue_info'] ?? null) ? $scope['build_queue_info'] : null
            );
            if (!empty($publishBlock['blocked'])) {
                return $this->fetchJson([
                    'success' => false,
                    'code' => 'LATEST_AI_BUILD_FAILED',
                    'message' => $this->formatPublishBlockedByAiFailureMessage($publishBlock),
                    'data' => [
                        'code' => 'LATEST_AI_BUILD_FAILED',
                        'publish_blocked_by_latest_ai_failure' => true,
                        'latest_build_failure' => $publishBlock,
                    ],
                ]);
            }
            $stateScope = \is_array($state['scope'] ?? null) ? $state['scope'] : [];
            $stateWorkspaceStatus = \strtolower(\trim((string)($state['workspace_status'] ?? $stateScope['workspace_status'] ?? '')));
            $stateCanPublish = !empty($state['can_publish'])
                || !empty($stateScope['can_publish'])
                || $stateWorkspaceStatus === 'can_publish'
                || $stateWorkspaceStatus === 'published'
                || (string)$session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED;
            if (!$stateCanPublish && $session->getPublishStatus() !== AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED) {
                return $this->fetchJson([
                    'success' => false,
                    'code' => 'WORKSPACE_NOT_READY',
                    'message' => __('Current workspace is not ready to publish. Finish AI page generation first.'),
                ]);
            }
        }

        $this->sessionService->setStage($session->getId(), $adminId, $stage);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $viewState = $this->buildWorkspaceFastViewState($fresh, $adminId);

        return $this->fetchJson([
            'success' => true,
            'stage' => $stage,
            'data' => $this->buildWorkspaceOperationPayload($viewState, 'stage'),
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSkillList(): string { return $this->handleSkillList(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPostSkillList(): string { return $this->handleSkillList(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getSkillList(): string { return $this->handleSkillList(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSkillSave(): string { return $this->handleSkillSave(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSkillDisable(): string { return $this->handleSkillDisable(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-compass-outline', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionList(): string { return $this->handleDesignDirectionList(); }

    public function getPostDesignDirectionList(): string { return $this->handleDesignDirectionList(); }

    public function getDesignDirectionList(): string { return $this->handleDesignDirectionList(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-compass-outline', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionSave(): string { return $this->handleDesignDirectionSave(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-compass-outline', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionDisable(): string { return $this->handleDesignDirectionDisable(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-compass-outline', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionCloneBuiltin(): string { return $this->handleDesignDirectionCloneBuiltin(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-compass-outline', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionMatch(): string { return $this->handleDesignDirectionMatch(); }

    public function getPostDesignDirectionMatch(): string { return $this->handleDesignDirectionMatch(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPlan(): string { return $this->handleStartPlan(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postConfirmPlan(): string { return $this->handleConfirmPlan(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postPlanSse(): void { $this->handlePlanSse(); }
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPlanSse(): void { $this->handlePlanSse(); }
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPostPlanSse(): void { $this->handlePlanSse(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postResumeBuild(): string { return $this->safeHandleStartBuild(true); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartBuild(): string { return $this->safeHandleStartBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartAssetGeneration(): string { return $this->handleStartAssetGeneration(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postUploadReferenceImage(): string { return $this->handleUploadReferenceImage(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function startAssetGeneration(): string { return $this->handleStartAssetGeneration(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRunVirtualTheme(): string { return $this->safeHandleStartBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartRegeneratePage(): string { return $this->handleStartRegeneratePage(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartRefineComponent(): string { return $this->handleStartRefineComponent(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPatchBlock(): string { return $this->handleStartPatchBlock(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRetryAiOperation(): string { return $this->handleRetryAiOperation(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }
    public function getBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }
    public function getPostBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }

    public function postBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }
    public function getBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }
    public function getPostBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }

    public function postUpdateBlockConfig(): string { return $this->handleUpdateBlockConfig(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPublish(): string { return $this->handleStartPublish(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSwitchPreviewPage(): string { return $this->handleSwitchPreviewPageCompact(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postWorkspaceState(): string
    {
        return $this->handleWorkspaceState();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'Ai Site Agent Api', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_api', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postPublishChecklist(): string { return $this->handlePublishChecklist(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'Ai Site Agent Workspace', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_workspace', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDeleteWorkspace(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        if (!$this->sessionService->deleteSession($session->getId(), $adminId)) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'Ai Site Agent Workspace', 'mdi-api', 'PageBuilder AI site agent permission: ai_site_agent_workspace', 'GuoLaiRen_PageBuilder::ai_site_agent')]
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
            'native_sse' => true,
        ]);
    }

    /**
     * Comment removed.
     *
     * Comment removed.
     * Comment removed.
     */
    public function getTestSse(): void
    {
        // Comment removed.
        $this->releasePhpSessionLockForSse();

        $sse = new SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => 'Test SSE connection started']);
        $sse->sendEvent('test', ['timestamp' => time(), 'message' => 'This is a test event']);

        // Comment removed.
        $sse->sendEvent('poll', ['count' => 1, 'timestamp' => time()]);
        $sse->sendEvent('poll', ['count' => 2, 'timestamp' => time()]);
        $sse->sendEvent('poll', ['count' => 3, 'timestamp' => time()]);

        $sse->complete(['success' => true, 'message' => 'Test complete, please reconnect']);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'Ai Site Agent Workspace', 'mdi-access-point-network', 'PageBuilder AI site agent permission: ai_site_agent_workspace', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getOperationSse(): void { $this->handleOperationSse(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_create', 'Ai Site Agent Create', 'mdi-plus', 'PageBuilder AI site agent permission: ai_site_agent_create', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postCreateSession(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $fakeMode = $this->getRequestBodyValue('fake_mode', '0');
        $fakeModeEnabled = $fakeMode === true
            || $fakeMode === 1
            || $fakeMode === '1'
            || $fakeMode === 'true';
        $siteTitle = \trim((string)$this->getRequestBodyValue('site_title', ''));
        $briefDescription = \trim((string)$this->getRequestBodyValue('brief_description', $this->getRequestBodyValue('user_description', '')));
        $directionMode = $this->designDirectionService()->normalizeMode(
            (string)$this->getRequestBodyValue('design_direction_mode', DesignDirectionService::MODE_AUTO)
        );
        $directionCode = \trim((string)$this->getRequestBodyValue('design_direction_code', ''));

        try {
            $directionPatch = $this->designDirectionService()->resolveSelectionForScope([
                'site_title' => $siteTitle,
                'brief_description' => $briefDescription,
                'design_direction_mode' => $directionMode,
                'design_direction_code' => $directionCode,
            ], $adminId, false);
            $session = $this->sessionService->createSession($adminId, \array_replace([
                'workspace_status' => AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING,
                'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                'fake_mode' => $fakeModeEnabled ? 1 : 0,
                'site_title' => $siteTitle,
                'site_tagline' => '',
                'target_domain' => '',
                'selected_domain' => '',
                'brief_description' => $briefDescription,
                'user_description' => $briefDescription,
                'default_locale' => '',
                'plan_locale' => '',
                'recommended_domain_list' => [],
                'recommended_registrar_label' => '',
                'preferred_registrar_account_id' => 0,
                'registrar_account_id' => 0,
                'page_types' => [],
                'recommended_pages' => [],
                'materialized_pages_by_type' => [],
                'site_profile_manual' => [],
                'active_operation' => [],
                'build_summary' => [],
                'plan_json_task_summary' => [],
                'top_logs' => [],
                'events' => [],
                'preview_page_id' => 0,
                'preview_page_type' => '',
            ], $directionPatch));
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'DESIGN_DIRECTION_INVALID',
                'message' => $invalidArgumentException->getMessage(),
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }

        $workspaceParams = ['public_id' => $session->getPublicId()];
        if ($fakeModeEnabled) {
            $workspaceParams['fake_mode'] = '1';
        }

        return $this->fetchJson([
            'success' => true,
            'public_id' => $session->getPublicId(),
            'workspace_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', $workspaceParams),
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_domain_recommend', 'Ai Site Agent Domain Recommend', 'mdi-auto-fix', 'PageBuilder AI site agent permission: ai_site_agent_domain_recommend', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRecommendDomain(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => 'Login is required.']);
        }

        $description = \trim((string)$this->getRequestBodyValue('description', ''));
        $preferredDomain = \strtolower(\trim((string)$this->getRequestBodyValue('domain', '')));
        $accountId = (int)$this->getRequestBodyValue('account_id', 0);
        $deferAvailability = \in_array(
            \strtolower(\trim((string)$this->getRequestBodyValue('defer_availability_check', ''))),
            ['1', 'true', 'yes', 'on'],
            true
        );

        if ($description === '' && $preferredDomain === '') {
            return $this->fetchJson([
                'success' => false,
                'message' => 'Please describe the website goal or enter a preferred domain first.',
                'candidate_domains' => [],
                'checked_results' => [],
            ]);
        }

        $effectiveAccountId = $accountId;
        $domainChoiceEnvironment = $this->workspaceBridgeService->buildDomainChoiceEnvironment();
        $requestHost = LocalDomainPolicy::normalizeDomain(
            (string)($this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: '')
        );
        if (
            $effectiveAccountId <= 0
            && (!empty($domainChoiceEnvironment['is_local']) || LocalDomainPolicy::isManagedLocalDomain($requestHost))
        ) {
            $effectiveAccountId = (int)($domainChoiceEnvironment['local_registrar_account_id'] ?? 0);
        }
        if ($effectiveAccountId <= 0 && $this->isTruthyRequestFlag('fake_mode')) {
            $effectiveAccountId = 900001;
        }

        if (!$deferAvailability && $effectiveAccountId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'message' => 'Please choose a registrar account before checking live availability.',
                'candidate_domains' => [],
                'checked_results' => [],
            ]);
        }

        return $this->fetchJson($this->getWebsiteAgentService()->recommendAvailableDomain(
            $description,
            $effectiveAccountId,
            $preferredDomain,
            $deferAvailability
        ));
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_domain_check', 'Ai Site Agent Domain Check', 'mdi-shield-search', 'PageBuilder AI site agent permission: ai_site_agent_domain_check', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postCheckDomain(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'available' => false, 'message' => 'Login is required.']);
        }

        $domain = \strtolower(\trim((string)$this->getRequestBodyValue('domain', '')));
        $accountId = (int)$this->getRequestBodyValue('account_id', 0);
        if ($domain === '') {
            return $this->fetchJson([
                'success' => false,
                'available' => false,
                'message' => 'Please enter a target domain first.',
                'checked_results' => [],
            ]);
        }
        if ($this->isTruthyRequestFlag('fake_mode')) {
            return $this->fetchJson([
                'success' => true,
                'available' => true,
                'domain' => $domain,
                'message' => 'Domain is treated as available in local simulation mode.',
                'checked_results' => [
                    [
                        'domain' => $domain,
                        'available' => true,
                        'simulated' => true,
                    ],
                ],
                'fake_mode' => true,
            ]);
        }
        if ($accountId <= 0) {
            return $this->fetchJson([
                'success' => false,
                'available' => false,
                'domain' => $domain,
                'message' => 'Please choose a registrar account before checking live availability.',
                'checked_results' => [],
            ]);
        }

        $resultsByDomain = $this->getWebsiteAgentService()->checkCandidateAvailability($accountId, [$domain]);
        $result = null;
        foreach ($resultsByDomain as $itemDomain => $itemResult) {
            if (
                \strtolower((string)$itemDomain) === $domain
                || \strtolower((string)($itemResult['domain'] ?? '')) === $domain
            ) {
                $result = \is_array($itemResult) ? $itemResult : null;
                break;
            }
        }

        if ($result === null) {
            return $this->fetchJson([
                'success' => false,
                'available' => false,
                'domain' => $domain,
                'message' => 'No availability result was returned for this domain.',
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

        return $this->fetchJson([
            'success' => $available,
            'available' => $available,
            'domain' => $checkedResult['domain'],
            'message' => $available ? 'Domain is currently available.' : (string)($checkedResult['error'] ?? 'Domain is not currently available.'),
            'checked_results' => [$checkedResult],
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_domain_purchase', 'Ai Site Agent Domain Purchase', 'mdi-cart-arrow-down', 'PageBuilder AI site agent permission: ai_site_agent_domain_purchase', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartDomainPurchase(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $linkedWebsitesSession = $this->ensureLinkedWebsitesMirrorSession($session, $adminId);
        if (!$linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
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
            ]);
        }

        $this->syncPageBuilderScopeFromLinkedWebsitesSession($session, $linkedWebsitesSession, $adminId);
        $freshPageBuilderSession = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $freshWebsitesSession = $this->getWebsitesSessionService()->loadById($linkedWebsitesSession->getId(), $adminId) ?? $linkedWebsitesSession;
        $state = $this->getWebsitesDomainPurchaseWorkbenchService()->buildViewState($freshWebsitesSession);

        return $this->fetchJson([
            'success' => true,
            'state' => $state,
            'pagebuilder_state' => $this->buildWorkspaceState($freshPageBuilderSession, $adminId, 80, true),
            'public_id' => $freshPageBuilderSession->getPublicId(),
            'linked_public_id' => $freshWebsitesSession->getPublicId(),
            'stream_token' => (string)($result['stream_token'] ?? ($state['execution_token'] ?? '')),
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_domain_purchase_stream', 'Ai Site Agent Domain Purchase Stream', 'mdi-access-point-network', 'PageBuilder AI site agent permission: ai_site_agent_domain_purchase_stream', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getDomainPurchaseSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new SseWriter();
        $sse->start();

        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $executionToken = \trim((string)$this->request->getGet('execution_token', ''));
        if ($adminId <= 0 || $publicId === '' || $executionToken === '') {
            $sse->sendError('Invalid domain purchase stream parameters.');
            $sse->complete(['success' => false]);
            return;
        }

        $pageBuilderSession = $this->sessionService->loadByPublicId($publicId, $adminId);
        if (!$pageBuilderSession instanceof AiSiteAgentSession) {
            $sse->sendError('PageBuilder workspace does not exist or cannot be accessed.');
            $sse->complete(['success' => false]);
            return;
        }

        $linkedWebsitesSession = $this->ensureLinkedWebsitesMirrorSession($pageBuilderSession, $adminId);
        if (!$linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession) {
            $sse->sendError('Unable to prepare the linked domain purchase workspace.');
            $sse->complete(['success' => false]);
            return;
        }

        $result = $this->getWebsitesDomainPurchaseWorkbenchService()->executeQueuedPurchase(
            $linkedWebsitesSession->getId(),
            $adminId,
            $executionToken,
            static function (string $event, array $data) use ($sse): void {
                if ($sse->isAlive()) {
                    $sse->sendEvent($event, $data);
                }
            }
        );

        $freshWebsitesSession = $this->getWebsitesSessionService()->loadById($linkedWebsitesSession->getId(), $adminId) ?? $linkedWebsitesSession;
        $this->syncPageBuilderScopeFromLinkedWebsitesSession($pageBuilderSession, $freshWebsitesSession, $adminId);
        $freshPageBuilderSession = $this->sessionService->loadById($pageBuilderSession->getId(), $adminId) ?? $pageBuilderSession;
        $state = $result['state'] ?? $this->getWebsitesDomainPurchaseWorkbenchService()->buildViewState($freshWebsitesSession);

        if (!empty($result['success'])) {
            $sse->complete([
                'success' => true,
                'completed' => !empty($result['completed']),
                'message' => (string)($result['message'] ?? 'Domain purchase stream has finished.'),
                'state' => $state,
                'pagebuilder_state' => $this->buildWorkspaceState($freshPageBuilderSession, $adminId, 80, true),
            ]);
            return;
        }

        $sse->sendEvent('error', [
            'message' => (string)($result['message'] ?? 'Domain purchase stream failed.'),
        ]);
        $sse->complete([
            'success' => false,
            'completed' => !empty($result['completed']),
            'message' => (string)($result['message'] ?? 'Domain purchase stream failed.'),
            'state' => $state,
            'pagebuilder_state' => $this->buildWorkspaceState($freshPageBuilderSession, $adminId, 80, true),
        ]);
    }

    private function handleSkillList(): string
    {
        try {
            $includeBody = $this->isTruthyRequestFlag('include_body');
            $includeInactive = $this->isTruthyRequestFlag('include_inactive') || $this->isTruthyRequestFlag('include_disabled');
            $temporaryCodes = $this->normalizeSkillCodeListForApi($this->getRequestBodyValue(
                'temporary_skill_codes',
                $this->getRequestBodyValue('selected_skill_codes', [])
            ));
            try {
                $catalog = $this->adapterSkillResolver()->buildSkillCatalog(
                    'pagebuilder_component_generation',
                    $temporaryCodes,
                    $includeInactive
                );
            } catch (\Throwable $catalogThrowable) {
                $catalog = [
                    'items' => [],
                    'default_skill_codes' => $this->aiSiteSkillRegistry()->getDefaultSkillCodes(),
                    'warnings' => [$catalogThrowable->getMessage()],
                ];
            }
            $catalogByCode = [];
            foreach (($catalog['items'] ?? []) as $catalogItem) {
                if (!\is_array($catalogItem)) {
                    continue;
                }
                $code = (string)($catalogItem['code'] ?? '');
                if ($code !== '') {
                    $catalogByCode[$code] = $catalogItem;
                }
            }

            $items = [];
            $defaultSkillCodes = \is_array($catalog['default_skill_codes'] ?? null)
                ? \array_values(\array_filter(\array_map('strval', $catalog['default_skill_codes'])))
                : $this->aiSiteSkillRegistry()->getDefaultSkillCodes();
            foreach ($this->aiSiteSkillRegistry()->listAvailableSkills() as $skill) {
                $code = (string)($skill['code'] ?? '');
                $merged = isset($catalogByCode[$code]) ? \array_replace($skill, $catalogByCode[$code]) : $skill;
                if (\in_array($code, $defaultSkillCodes, true)) {
                    $merged['locked'] = true;
                    $merged['binding_source'] = 'locked';
                    $merged['selectable'] = false;
                    $merged['readonly'] = true;
                }
                if (!$includeInactive && (string)($merged['status'] ?? 'active') !== 'active') {
                    continue;
                }
                $items[] = $this->formatSkillApiItem($merged, $includeBody);
            }
            foreach ($catalogByCode as $code => $catalogItem) {
                $alreadyAdded = false;
                foreach ($items as $item) {
                    if ((string)($item['code'] ?? '') === $code) {
                        $alreadyAdded = true;
                        break;
                    }
                }
                if (!$alreadyAdded) {
                    $items[] = $this->formatSkillApiItem($catalogItem, $includeBody);
                }
            }

            return $this->fetchJson([
                'success' => true,
                'items' => $items,
                'default_skill_codes' => $defaultSkillCodes,
                'warnings' => $catalog['warnings'] ?? [],
            ]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function handleSkillSave(): string
    {
        $error = '';
        $skillPayload = $this->getRequestJsonObject('skill', $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }

        $data = [
            'code' => $this->getRequestBodyValue('code', $skillPayload['code'] ?? ''),
            'name' => $this->getRequestBodyValue('name', $skillPayload['name'] ?? ''),
            'description' => $this->getRequestBodyValue('description', $skillPayload['description'] ?? ''),
            'body' => $this->getRequestBodyValue('body', $skillPayload['body'] ?? ''),
            'status' => $this->getRequestBodyValue('status', $skillPayload['status'] ?? 'active'),
        ];

        try {
            $code = (string)($data['code'] ?? '');
            if ($this->coreSkillRegistry()->isReservedCode($code)) {
                throw new \InvalidArgumentException('Custom skill code "' . $code . '" conflicts with a system/module skill.');
            }
            $saved = $this->aiSkillRepository()->saveFromArray($data + ['source_type' => AiSkill::SOURCE_CUSTOM], AiSkill::SOURCE_CUSTOM);
            $item = $this->aiSkillRepository()->modelToArray($saved);

            return $this->fetchJson([
                'success' => true,
                'item' => $this->formatSkillApiItem($item, true),
            ]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => $invalidArgumentException->getMessage(),
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'SKILL_SAVE_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function handleSkillDisable(): string
    {
        $code = \trim((string)$this->getRequestBodyValue('code', ''));
        if ($code === '') {
            return $this->fetchJson([
                'success' => false,
                'code' => 'INVALID_SKILL_CODE',
                'message' => 'Skill code is required.',
            ]);
        }

        try {
            $existing = $this->aiSkillRepository()->findArrayByCode($code);
            if (\is_array($existing)) {
                $saved = $this->aiSkillRepository()->disable($code);
                return $this->fetchJson([
                    'success' => true,
                    'item' => $this->formatSkillApiItem($saved ? $this->aiSkillRepository()->modelToArray($saved) : $existing, true),
                ]);
            }

            $existing = $this->customSkillRepository()->findArrayByCode($code);
            if (!\is_array($existing)) {
                $builtin = $this->aiSiteSkillRegistry()->getSkill($code);
                return $this->fetchJson([
                    'success' => false,
                    'code' => !empty($builtin['exists']) ? 'BUILTIN_SKILL_READONLY' : 'SKILL_NOT_FOUND',
                    'message' => !empty($builtin['exists'])
                        ? 'Builtin skills are read-only and cannot be disabled by this endpoint.'
                        : 'Skill not found.',
                ]);
            }

            $existing['status'] = 'disabled';
            $saved = $this->customSkillRepository()->saveFromArray($existing);
            $item = $this->customSkillRepository()->findArrayByCode($saved->getCode()) ?? $existing;

            return $this->fetchJson([
                'success' => true,
                'item' => $this->formatSkillApiItem($item, true),
            ]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'SKILL_DISABLE_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $skill
     * @return array<string, mixed>
     */
    private function formatSkillApiItem(array $skill, bool $includeBody = false): array
    {
        $status = (string)($skill['status'] ?? 'active');
        $source = (string)($skill['source'] ?? '');
        $sourceType = (string)($skill['source_type'] ?? $source);
        $locked = !empty($skill['locked']);
        $manual = !empty($skill['manual']);
        $temporary = !empty($skill['temporary']);
        $readonly = \array_key_exists('readonly', $skill)
            ? !empty($skill['readonly'])
            : ($source !== 'custom_db' && $sourceType !== AiSkill::SOURCE_CUSTOM);
        $selectable = \array_key_exists('selectable', $skill)
            ? !empty($skill['selectable'])
            : (!empty($skill['exists']) && $status === 'active' && !$locked && !$manual);
        $item = [
            'id' => (int)($skill['id'] ?? 0),
            'code' => (string)($skill['code'] ?? ''),
            'name' => (string)($skill['name'] ?? $skill['code'] ?? ''),
            'description' => (string)($skill['description'] ?? ''),
            'status' => $status,
            'source' => $source,
            'source_type' => $sourceType,
            'body_hash' => (string)($skill['body_hash'] ?? ''),
            'local_path' => (string)($skill['local_path'] ?? ''),
            'tags' => \array_values(\array_filter(\array_map('strval', \is_array($skill['tags'] ?? null) ? $skill['tags'] : []))),
            'readonly' => $readonly,
            'selectable' => $selectable,
            'exists' => !empty($skill['exists']),
            'locked' => $locked,
            'manual' => $manual,
            'temporary' => $temporary,
            'binding_source' => (string)($skill['binding_source'] ?? ($locked ? 'locked' : ($manual ? 'manual' : ($temporary ? 'temporary' : '')))),
        ];
        if ($includeBody) {
            $item['body'] = (string)($skill['body'] ?? $skill['normalized_body'] ?? '');
        }

        return $item;
    }

    private function aiSiteSkillRegistry(): AiSiteSkillRegistry
    {
        return ObjectManager::getInstance(AiSiteSkillRegistry::class);
    }

    private function customSkillRepository(): CustomSkillRepository
    {
        return ObjectManager::getInstance(CustomSkillRepository::class);
    }

    private function aiSkillRepository(): AiSkillRepository
    {
        return ObjectManager::getInstance(AiSkillRepository::class);
    }

    private function coreSkillRegistry(): CoreSkillRegistry
    {
        return ObjectManager::getInstance(CoreSkillRegistry::class);
    }

    private function adapterSkillResolver(): AdapterSkillResolver
    {
        return ObjectManager::getInstance(AdapterSkillResolver::class);
    }

    /**
     * @return list<string>
     */
    private function normalizeSkillCodeListForApi(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : \preg_split('/[\s,;]+/', $raw);
        }
        if (!\is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $code = \trim((string)$item);
            if ($code !== '' && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function designDirectionService(): DesignDirectionService
    {
        return ObjectManager::getInstance(DesignDirectionService::class);
    }

    /**
     * Comment removed.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function applyAutoDesignDirectionToScope(array $scope, int $adminId): array
    {
        if ($this->isTruthyRequestFlagFromScope($scope, 'design_direction_locked')) {
            return $scope;
        }

        $mode = $this->designDirectionService()->normalizeMode(
            (string)($scope['design_direction_mode'] ?? DesignDirectionService::MODE_AUTO)
        );
        if ($mode !== DesignDirectionService::MODE_AUTO) {
            return $scope;
        }

        try {
            $directionPatch = $this->designDirectionService()->resolveSelectionForScope($scope, $adminId, false);
        } catch (\InvalidArgumentException) {
            return $scope;
        }

        return $this->scopeCompatibilityService->normalizeScope(\array_replace($scope, $directionPatch));
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isTruthyRequestFlagFromScope(array $scope, string $key): bool
    {
        $value = $scope[$key] ?? 0;
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function handleDesignDirectionList(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        try {
            $includeDisabled = $this->isTruthyRequestFlag('include_disabled');
            $items = \array_values($this->designDirectionService()->listDirections($adminId, $includeDisabled));
            return $this->fetchJson([
                'success' => true,
                'items' => $items,
                'builtin_code' => DesignDirectionService::BUILTIN_CARD_GAME_CODE,
            ]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'DESIGN_DIRECTION_LIST_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function handleDesignDirectionSave(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $error = '';
        $payload = $this->getRequestJsonObject('direction', $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        $data = \array_replace($payload, [
            'code' => $this->getRequestBodyValue('code', $payload['code'] ?? ''),
            'name' => $this->getRequestBodyValue('name', $payload['name'] ?? ''),
            'description' => $this->getRequestBodyValue('description', $payload['description'] ?? ''),
            'status' => $this->getRequestBodyValue('status', $payload['status'] ?? 'active'),
            'cta_style' => $this->getRequestBodyValue('cta_style', $payload['cta_style'] ?? ''),
            'supplemental_prompt' => $this->getRequestBodyValue('supplemental_prompt', $payload['supplemental_prompt'] ?? ''),
        ]);
        foreach ([
            'industry_tags',
            'match_keywords',
            'visual_keywords',
            'color_system',
            'layout_patterns',
            'image_strategy',
            'forbidden_patterns',
            'block_rules',
            'qa_rules',
            'example_refs',
        ] as $field) {
            $data[$field] = $this->getRequestBodyValue($field, $payload[$field] ?? []);
        }

        try {
            $item = $this->designDirectionService()->saveCustom($data, $adminId);
            return $this->fetchJson(['success' => true, 'item' => $item]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'VALIDATION_ERROR',
                'message' => $invalidArgumentException->getMessage(),
            ]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'DESIGN_DIRECTION_SAVE_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function handleDesignDirectionDisable(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $code = \trim((string)$this->getRequestBodyValue('code', ''));
        if ($adminId <= 0 || $code === '') {
            return $this->fetchJson([
                'success' => false,
                'code' => 'INVALID_PARAMS',
                'message' => 'Design direction code is required.',
            ]);
        }

        try {
            $item = $this->designDirectionService()->disableCustom($code, $adminId);
            return $this->fetchJson(['success' => true, 'item' => $item]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'DESIGN_DIRECTION_DISABLE_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function handleDesignDirectionCloneBuiltin(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $code = \trim((string)$this->getRequestBodyValue('code', ''));
        if ($adminId <= 0 || $code === '') {
            return $this->fetchJson([
                'success' => false,
                'code' => 'INVALID_PARAMS',
                'message' => 'Builtin design direction code is required.',
            ]);
        }

        try {
            $item = $this->designDirectionService()->cloneBuiltin($code, $adminId);
            return $this->fetchJson(['success' => true, 'item' => $item]);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'DESIGN_DIRECTION_CLONE_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function handleDesignDirectionMatch(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $title = \trim((string)$this->getRequestBodyValue('site_title', $this->getRequestBodyValue('title', '')));
        $brief = \trim((string)$this->getRequestBodyValue('brief_description', $this->getRequestBodyValue('brief', '')));
        try {
            $match = $this->designDirectionService()->matchDirection($title, $brief, $adminId);
            return $this->fetchJson(\array_replace(['success' => true], $match));
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'DESIGN_DIRECTION_MATCH_FAILED',
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function handleStartPlan(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $error = '';
        $scopePatch = $this->getRequestJsonObject('scope_patch', $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        if (isset($scopePatch['page_types'])) {
            $scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        foreach (['design_direction_mode', 'design_direction_code', 'design_direction_custom_id'] as $directionRequestKey) {
            $directionRequestValue = $this->getRequestBodyValue($directionRequestKey, null);
            if ($directionRequestValue !== null) {
                $scopePatch[$directionRequestKey] = $directionRequestValue;
            }
        }
        if ($this->isTruthyRequestFlag('fake_mode') || (int)($scopePatch['fake_mode'] ?? 0) === 1) {
            $scopePatch['fake_mode'] = 1;
        }
        $requestedSelectedSkillCodes = $this->getRequestBodyValue(AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY, null);
        if ($requestedSelectedSkillCodes !== null || \array_key_exists(AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY, $scopePatch)) {
            $scopePatch[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] = $this->scopeCompatibilityService->normalizeSelectedSkillCodes(
                $requestedSelectedSkillCodes ?? $scopePatch[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] ?? []
            );
        }
        $scope = $this->scopeCompatibilityService->normalizeScope(\array_replace(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN),
            $scopePatch
        ));
        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            $scopePatch['fake_mode'] = 1;
        }
        $targetDomain = \trim((string)($scope['target_domain'] ?? ''));
        if ($targetDomain === '') {
            return $this->jsonError(
                'TARGET_DOMAIN_REQUIRED',
                'Target domain is required before planning.',
                ['target_domain']
            );
        }
        $scopeBeforeBriefPageTypeExpansion = $scope;
        $scope = $this->scopeCompatibilityService->augmentDefaultPageTypesFromBrief($scope);
        if (($scope['page_types'] ?? []) !== ($scopeBeforeBriefPageTypeExpansion['page_types'] ?? [])) {
            $scopePatch['page_types'] = $scope['page_types'];
            $scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        $confirmRegenerate = \in_array(\strtolower(\trim((string)$this->getRequestBodyValue('confirm_regenerate', '0'))), ['1', 'true', 'yes', 'on'], true);
        $requestedPlanLocale = \trim((string)$this->getRequestBodyValue('plan_locale', ''));
        if ($requestedPlanLocale !== '') {
            $scope['plan_locale'] = $requestedPlanLocale;
        }
        $scope['plan_locale'] = $this->resolvePlanLocale($scope);
        $selectedSkillCodes = $this->scopeCompatibilityService->normalizeSelectedSkillCodes(
            $scope[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] ?? []
        );
        $scope[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] = $selectedSkillCodes;
        try {
            $directionPatch = $this->designDirectionService()->resolveSelectionForScope($scope, $adminId, false);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->jsonError(
                'DESIGN_DIRECTION_INVALID',
                $invalidArgumentException->getMessage(),
                ['design_direction_code']
            );
        }
        $scope = $this->scopeCompatibilityService->normalizeScope(\array_replace($scope, $directionPatch));
        $scopePatch = \array_replace($scopePatch, $directionPatch);
        // Comment removed.
        $persistPatch = \array_replace($scopePatch, [
            'plan_locale' => (string)$scope['plan_locale'],
            'plan_json' => (new AiSitePlanJsonStateService((int)$session->getId()))->setConfirmed(
                \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
                false
            ),
            AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY => $selectedSkillCodes,
        ]);
        $this->sessionService->mergeScope($session->getId(), $adminId, $persistPatch);
        $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $currentScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
        $planStartDecision = $this->resolvePlanStartDecision($currentScope);
        $planIsEmpty = $this->isPlanContentEmpty($currentScope);
        $hasPlanDraft = !$planIsEmpty;
        $hasRetryablePlanFailures = $this->planJsonTaskService->hasRetryableAiFailures($currentScope, 'plan');
        if (
            $hasPlanDraft
            && \in_array((string)($planStartDecision['action'] ?? ''), ['rebuild', 'translate'], true)
            && !$confirmRegenerate
        ) {
            $confirmMessage = (string)($planStartDecision['action'] ?? '') === 'translate'
                ? (string)__('The plan language changed. Regenerate the plan before building.')
                : (string)__('The plan input changed. Regenerate the plan before building.');
            return $this->fetchJson([
                'success' => true,
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
                    $this->buildStartPlanLightweightStatePatch($session, $currentScope, $hasPlanDraft),
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
        $activeOperationStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        if (
            $activeOperationName === 'plan'
            && \in_array($activeOperationStatus, ['queued', 'running'], true)
            && $this->shouldRestartPlanOperationForScopeChange(
                $activeOperation,
                (string)$scope['plan_locale'],
                $requestedPageTypes,
                $this->PlanJsonSourceSignature($currentScope)
            )
        ) {
            $this->cancelActivePlanOperationForScopeChange($session, $adminId);
            $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $currentScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
            );
            $planStartDecision = $this->resolvePlanStartDecision($currentScope);
        }
        if (
            $requestedPromptMode === 'refine'
            && $requestedInstruction === ''
            && $requestedTargetScope === ''
            && (string)($planStartDecision['action'] ?? '') === 'reuse'
            && $hasPlanDraft
            && !$hasRetryablePlanFailures
        ) {
            $requestedPromptMode = '';
        }
        if ((string)($planStartDecision['action'] ?? '') === 'reuse' && $hasPlanDraft && !$hasRetryablePlanFailures && !\in_array($requestedPromptMode, ['refine', 'rebuild', 'resume_plan'], true)) {
            return $this->fetchJson([
                'success' => true,
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
                    $this->buildStartPlanLightweightStatePatch($session, $currentScope, $hasPlanDraft),
                    'plan'
                ),
            ]);
        }
        $stage = AiSiteAgentSession::STAGE_PLAN;
        if ($hasRetryablePlanFailures) {
            $effectivePlanPromptMode = $requestedPromptMode === 'rebuild'
                ? 'rebuild'
                : 'resume_plan';
        } elseif (\in_array($requestedPromptMode, ['refine', 'rebuild', 'resume_plan'], true)) {
            $effectivePlanPromptMode = $requestedPromptMode;
        } elseif ((string)($planStartDecision['action'] ?? '') === 'reuse' && $this->scopeHasPersistedStageOnePlan($currentScope)) {
            $effectivePlanPromptMode = 'resume_plan';
        } else {
            $effectivePlanPromptMode = (string)($planStartDecision['action'] ?? 'rebuild');
        }
        $planRebuildResetPatch = $effectivePlanPromptMode === 'rebuild'
            ? $this->buildStageOnePlanRegenerationResetScopePatch()
            : [];
        if ($effectivePlanPromptMode === 'rebuild') {
            $planRebuildResetPatch['_force_rebuild'] = 1;
        }
        $planOperationDetails = [
            'prompt_mode' => $effectivePlanPromptMode,
            'instruction' => $requestedInstruction,
            'target_scope' => $requestedTargetScope,
            'round' => $requestedRound,
            'page_types' => $requestedPageTypes,
            AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
        ];
        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            $planOperationDetails['fake_mode'] = 1;
        }
        if ($hasRetryablePlanFailures && $effectivePlanPromptMode === 'resume_plan') {
            $planOperationDetails['resume_failed_tasks'] = 1;
        }
        $result = $this->startOperation($session, $adminId, 'plan', $stage, \array_replace($planRebuildResetPatch, [
            'plan_locale' => (string)$scope['plan_locale'],
            'plan_json' => (new AiSitePlanJsonStateService((int)$session->getId()))->setConfirmed(
                \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
                false
            ),
            '_plan_sse_request' => [
                'prompt_mode' => $effectivePlanPromptMode,
                'instruction' => $requestedInstruction,
                'target_scope' => $requestedTargetScope,
                'round' => $requestedRound,
                'plan_locale' => (string)$scope['plan_locale'],
                'page_types' => $requestedPageTypes,
                AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
                AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY => $selectedSkillCodes,
                'design_direction_mode' => (string)($scope['design_direction_mode'] ?? DesignDirectionService::MODE_AUTO),
                'design_direction_code' => (string)($scope['design_direction_code'] ?? ''),
                'design_direction_snapshot' => \is_array($scope['design_direction_snapshot'] ?? null) ? $scope['design_direction_snapshot'] : [],
                'design_direction_hash' => (string)($scope['design_direction_hash'] ?? ''),
                'fake_mode' => (int)($scope['fake_mode'] ?? 0) === 1 ? 1 : 0,
            ],
        ]), '', AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING, $planOperationDetails);
        if (empty($result['success'])) {
            if ((string)($result['operation'] ?? '') === 'plan') {
                $resumeExecutionToken = \trim((string)($result['execution_token'] ?? ''));
                $resumeStreamUrl = \trim((string)($result['stream_url'] ?? ''));
                if ($resumeExecutionToken !== '' && $resumeStreamUrl !== '') {
                    return $this->fetchJson([
                        'success' => true,
                        'operation' => 'plan',
                        'execution_token' => $resumeExecutionToken,
                        'stream_url' => $resumeStreamUrl,
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
                        'queue_id' => (int)($result['queue_id'] ?? 0),
                        'queue_wait' => \is_array($result['queue_wait'] ?? null) ? $result['queue_wait'] : null,
                        'data' => \is_array($result['data'] ?? null) ? $result['data'] : [],
                    ]);
                }
                // Comment removed.
                // Comment removed.
                $this->cancelActivePlanOperationForScopeChange($session, $adminId);
                $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
                $result = $this->startOperation($session, $adminId, 'plan', $stage, \array_replace($planRebuildResetPatch, [
                    'plan_locale' => (string)$scope['plan_locale'],
                    'plan_json' => (new AiSitePlanJsonStateService((int)$session->getId()))->setConfirmed(
                        \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
                        false
                    ),
                    '_plan_sse_request' => [
                        'prompt_mode' => $effectivePlanPromptMode,
                        'instruction' => $requestedInstruction,
                        'target_scope' => $requestedTargetScope,
                        'round' => $requestedRound,
                        'plan_locale' => (string)$scope['plan_locale'],
                        'page_types' => $requestedPageTypes,
                        AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY => 1,
                        AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY => $selectedSkillCodes,
                        'design_direction_mode' => (string)($scope['design_direction_mode'] ?? DesignDirectionService::MODE_AUTO),
                        'design_direction_code' => (string)($scope['design_direction_code'] ?? ''),
                        'design_direction_snapshot' => \is_array($scope['design_direction_snapshot'] ?? null) ? $scope['design_direction_snapshot'] : [],
                        'design_direction_hash' => (string)($scope['design_direction_hash'] ?? ''),
                        'fake_mode' => (int)($scope['fake_mode'] ?? 0) === 1 ? 1 : 0,
                    ],
                ]), '', AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING, $planOperationDetails);
                if (empty($result['success'])) {
                    return $this->fetchJson([
                        'success' => false,
                        'operation' => (string)($result['operation'] ?? 'plan'),
                    ]);
                }
                // Comment removed.
            } else {
                return $this->fetchJson([
                    'success' => false,
                    'operation' => (string)($result['operation'] ?? ''),
                ]);
            }
        }

        $responseState = \is_array($result['data'] ?? null) ? $result['data'] : [];
        return $this->fetchJson([
            'success' => true,
            'operation' => (string)($result['operation'] ?? 'plan'),
            'execution_token' => (string)($result['execution_token'] ?? ''),
            'stream_url' => (string)($result['stream_url'] ?? ''),
            'start_sse' => true,
            'queue_id' => (int)($result['queue_id'] ?? 0),
            'queue_wait' => \is_array($result['queue_wait'] ?? null) ? $result['queue_wait'] : null,
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
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $requestedStage = $this->scopeCompatibilityService->normalizeStage(
            \trim((string)$this->getRequestBodyValue('stage', ''))
        );
        $currentStage = $this->scopeCompatibilityService->normalizeStage((string)$session->getStage());
        // Pass explicit artifact keys to bypass dehydrateScopePaths which strips plan_json.
        $planArtifactKeys = [
            'plan_json',
            'content_manifest',
        ];
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN, $planArtifactKeys)
        );
        if ($requestedStage !== '' && $requestedStage !== AiSiteAgentSession::STAGE_PLAN) {
            $requestedStageScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($session, $requestedStage, $planArtifactKeys)
            );
            $scope = $this->scopeCompatibilityService->normalizeScope(\array_replace($requestedStageScope, $scope));
        }
        if ($currentStage !== '' && $currentStage !== AiSiteAgentSession::STAGE_PLAN && $currentStage !== $requestedStage) {
            $currentStageScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($session, $currentStage)
            );
            $scope = $this->scopeCompatibilityService->normalizeScope(\array_replace($currentStageScope, $scope));
        }
        $scope = $this->hydrateStageOnePlanPayloadFromPlanStageScope($session, $adminId, $scope);
        $planConfirmationPrepared = $this->planJsonGenerationService->prepareStageOnePlanScopeForConfirmation($scope);
        $scope = \is_array($planConfirmationPrepared['scope'] ?? null)
            ? $planConfirmationPrepared['scope']
            : (\is_array($planConfirmationPrepared) ? $planConfirmationPrepared : $scope);
        $planConfirmationStage1Validation = \is_array($planConfirmationPrepared['stage1_validation'] ?? null)
            ? $planConfirmationPrepared['stage1_validation']
            : [];
        $hasStageOnePayload = $this->scopeCompatibilityService->hasPersistedStageOnePlan($scope);
        if (!$hasStageOnePayload) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        if ($this->planJsonTaskService->hasRetryableAiFailures($scope, 'plan')) {
            $summary = $this->planJsonTaskService->summarizeRetryableAiFailures($scope, 'plan');
            return $this->jsonError(
                'RETRYABLE_AI_FAILURES_PENDING',
                'Retryable AI failures must be retried before continuing.',
                ['public_id'],
                ['retryable_ai_failure_count' => (int)($summary['count'] ?? 0), 'retryable_ai_failures' => $summary]
            );
        }
        $missingPlanPageTypes = $this->planJsonTaskService->collectMissingSelectedPlanPageTypes($scope);
        if ($missingPlanPageTypes !== []) {
            return $this->jsonError(
                'PLAN_PAGE_COVERAGE_INCOMPLETE',
                (string)__('Plan JSON is missing selected page types: %{page_types}. Regenerate the plan until every selected page has an explicit plan JSON page and block plan.', [
                    'page_types' => \implode(', ', \array_slice($missingPlanPageTypes, 0, 12)),
                ]),
                ['public_id'],
                ['missing_page_types' => $missingPlanPageTypes]
            );
        }
        $planStartDecision = $this->resolvePlanStartDecision($scope);
        $planInputStaleForConfirmation = !empty($planStartDecision['rebuild_required'])
            || !empty($planStartDecision['source_signature_changed']);
        $forceConfirmStalePlan = (int)$this->getRequestBodyValue('force_confirm_stale_plan', 0) === 1;
        if ($planInputStaleForConfirmation && !$forceConfirmStalePlan) {
            $stalePlanConfirmMessage = (string)__('The plan input changed. Confirm regeneration before continuing.');
            return $this->jsonError(
                'PLAN_INPUT_STALE',
                $stalePlanConfirmMessage,
                ['public_id', 'force_confirm_stale_plan'],
                [
                    'requires_confirmation' => true,
                    'confirmation_code' => 'PLAN_INPUT_STALE_CONFIRM',
                    'confirm_message' => $stalePlanConfirmMessage,
                    'plan_start_decision' => $planStartDecision,
                ]
            );
        }

        try {
            $directionLockPatch = $this->designDirectionService()->resolveSelectionForScope($scope, $adminId, true);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->jsonError(
                'DESIGN_DIRECTION_INVALID',
                $invalidArgumentException->getMessage(),
                ['design_direction_code']
            );
        }

        $planJsonEditor = new AiSitePlanJsonStateService((int)$session->getId());
        $scopePatch = \array_replace(
            $directionLockPatch,
            $planJsonEditor->setConfirmedScopePatch(
                \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
                true
            )
        );
        $PlanJsonSourceScope = \array_replace($scope, $scopePatch);
        $PlanJsonPatch = $this->PlanJsonJsonConfirmationScopePatch($PlanJsonSourceScope, (int)$session->getId());
        unset($PlanJsonSourceScope);
        $PlanJsonValidation = \is_array($PlanJsonPatch['plan_json_pages_validation'] ?? null)
            ? $PlanJsonPatch['plan_json_pages_validation']
            : [];
        if (!($PlanJsonValidation['valid'] ?? false)) {
            $PlanJsonValidation = \is_array($PlanJsonPatch['plan_json_pages_validation'] ?? null)
                ? $PlanJsonPatch['plan_json_pages_validation']
                : [];
            $PlanJsonErrors = \is_array($PlanJsonValidation['errors'] ?? null)
                ? \array_values(\array_filter(\array_map('strval', $PlanJsonValidation['errors']), static fn(string $error): bool => \trim($error) !== ''))
                : [];
            $detail = $PlanJsonErrors !== []
                ? \implode('; ', \array_slice($PlanJsonErrors, 0, 6))
                : '';
            $message = $detail !== ''
                ? (string)__('Plan JSON pages validation failed: %{detail}', ['detail' => $detail])
                : (string)__('Plan JSON pages validation failed. Please regenerate the site plan.');

            return $this->jsonError(
                'PLAN_JSON_PAGES_INVALID',
                $message,
                ['public_id'],
                [
                    'plan_json_pages_validation' => $PlanJsonValidation,
                    'stage1_validation' => $planConfirmationStage1Validation,
                ]
            );
        }
        $scopePatch = \array_replace($scopePatch, $PlanJsonPatch);

        $confirmedScope = $this->scopeCompatibilityService->normalizeScope(\array_replace($scope, $scopePatch));
        $confirmedScope = $this->planJsonTaskService->ensureTaskScope(
            $confirmedScope,
            \is_array($confirmedScope['website_profile'] ?? null) ? $confirmedScope['website_profile'] : [],
            (string)($confirmedScope['workspace_track'] ?? '')
        );
        $scopePatch = \array_replace($scopePatch, $this->planJsonTaskService->extractPlanJsonDerivedScopePatch($confirmedScope));
        $scopePatch['plan_json_task_summary'] = $this->planJsonTaskService->summarize($confirmedScope);
        $confirmedPlanSignature = $this->resolveConfirmedPlanSignature(\array_replace($scope, $scopePatch));
        $scopePatch['build_summary'] = [
            'confirmed_plan_signature' => $confirmedPlanSignature,
        ];

        $confirmOnly = (int)$this->getRequestBodyValue('confirm_only', 0) === 1
            || (int)$this->getRequestBodyValue('build_deferred', 0) === 1
            || \strtolower(\trim((string)$this->getRequestBodyValue('start_build', '1'))) === '0';

        $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
        if (!$confirmOnly) {
            $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
            $confirmedPlanJson = \is_array($scopePatch['plan_json'] ?? null) ? $scopePatch['plan_json'] : [];
            if ($confirmedPlanJson !== []) {
                $confirmedPlanJson['state'] = AiSiteAgentSession::STAGE_VISUAL_EDIT;
                $this->sessionService->mergeScope($session->getId(), $adminId, ['plan_json' => $confirmedPlanJson]);
            }
        }
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
            'plan_json_confirmed',
            (string)__('Plan JSON confirmed.'),
            [
                'operation' => 'plan_confirm',
                'details' => [
                    'plan_signature' => $confirmedPlanSignature,
                    'plan_json_task_count' => (int)($scopePatch['plan_json_task_summary']['total'] ?? 0),
                ],
            ]
        );

        if ($confirmOnly) {
            $confirmedState = [
                'public_id' => (string)$fresh->getPublicId(),
                'stage' => $this->scopeCompatibilityService->normalizeStage((string)$fresh->getStage()) ?: AiSiteAgentSession::STAGE_PLAN,
                'workspace_status' => (string)($confirmedScope['workspace_status'] ?? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING),
                'publish_status' => (string)$fresh->getPublishStatus(),
                'can_publish' => !empty($confirmedScope['can_publish']),
                'latest_build_failed' => !empty($confirmedScope['latest_build_failed']),
                'latest_build_failure' => \is_array($confirmedScope['latest_build_failure'] ?? null) ? $confirmedScope['latest_build_failure'] : [],
                'publish_blocked_by_latest_ai_failure' => !empty($confirmedScope['publish_blocked_by_latest_ai_failure']),
                'publish_blocked_reason' => (string)($confirmedScope['publish_blocked_reason'] ?? ''),
                'workspace_track' => (string)($confirmedScope['workspace_track'] ?? ''),
                'website_id' => (int)$fresh->getWebsiteId(),
                'virtual_theme_id' => (int)$fresh->getVirtualThemeId(),
                'draft_website_id' => (int)($confirmedScope['draft_website_id'] ?? 0),
                'active_operation' => \is_array($confirmedScope['active_operation'] ?? null) ? $confirmedScope['active_operation'] : [],
                'confirmed_plan_signature' => $confirmedPlanSignature,
                'has_stage_one_plan' => true,
                'plan_json_task_summary' => \is_array($scopePatch['plan_json_task_summary'] ?? null) ? $scopePatch['plan_json_task_summary'] : [],
                'build_summary' => \is_array($scopePatch['build_summary'] ?? null) ? $scopePatch['build_summary'] : [],
                'pending_generation_page_types' => \is_array($confirmedScope['pending_generation_page_types'] ?? null) ? $confirmedScope['pending_generation_page_types'] : [],
            ];
            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('Plan JSON confirmed; you can generate a selected block first.'),
                'start_sse' => false,
                'operation' => 'plan_confirm',
                'data' => $this->buildWorkspaceConfirmPayload($confirmedState, 'plan'),
            ]);
        }

        $buildStartResult = $this->startOperation(
            $fresh,
            $adminId,
            'build',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            [],
            '',
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            ['fresh_repair_failed_tasks' => 1]
        );
        if (!empty($buildStartResult['success'])) {
            $buildStartResult['start_sse'] = true;
        }

        return $this->fetchJson($buildStartResult);
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
        if ($adminId <= 0 || $publicId === '' || !\in_array($promptMode, ['refine', 'rebuild', 'resume_plan'], true)) {
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $sse->complete(['success' => false]);
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_PLAN,
                []
            )
        );
        $this->sendSseContractError(
            $sse,
            'QUEUE_START_REQUIRED',
            'Direct AI SSE execution is disabled. Use the start endpoint to create one queue row, then observe scheduler progress.',
            ['public_id', 'prompt_mode'],
            409
        );
        $sse->complete([
            'success' => false,
            'message' => 'Direct AI SSE execution is disabled. Use the start endpoint to create one queue row; system scheduling usually starts within about one minute.',
            'operation' => 'plan',
            'queue_waiting_for_scheduler' => true,
            'can_close_stream' => true,
            'continue_other_operations' => true,
        ]);
        return;

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
            $instruction = $instruction !== '' ? $instruction : 'Translate the current plan locale.';
        }
        if ($effectivePromptMode === 'rebuild') {
            $instruction = $instruction !== '' ? $instruction : 'Regenerate the plan from plan_json.pages.';
        } else {
            $instruction = $instruction !== '' ? $instruction : 'Refine the current plan_json.pages plan.';
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
            'message' => $effectivePromptMode === 'rebuild' ? 'Regenerating plan.' : 'Refining plan.',
            'prompt_mode' => $effectivePromptMode,
            'round' => $round,
            'target_scope' => $targetScope,
            'plan_locale' => $requestedPlanLocale,
            'locale_changed_force_rebuild' => ($effectivePromptMode !== $promptMode) ? 1 : 0,
        ]);
        $sse->sendEvent('progress', [
            'prompt_mode' => $effectivePromptMode,
            'progress_percent' => 20,
        ]);

        try {
            $websiteProfile = $this->profileGenerationService->generate($scope, false);
            $rawChunkInfoBuffer = '';
            $emitPlanPipelineProgress = function (array $progressPayload) use ($sse): void {
                $message = \trim((string)($progressPayload['message'] ?? ''));
                if ($message === '') {
                    return;
                }

                $payload = [
                    'message' => $message,
                    'operation' => 'plan',
                    'progress_percent' => \max(0, \min(99, (int)($progressPayload['progress_percent'] ?? 0))),
                    'progress_kind' => (string)($progressPayload['progress_kind'] ?? 'queue_info'),
                    'token_usage' => \is_array($progressPayload['token_usage'] ?? null)
                        ? $progressPayload['token_usage']
                        : [
                            'input_tokens' => null,
                            'output_tokens' => null,
                            'total_tokens' => null,
                        ],
                ];
                foreach (['stage1_step', 'stage1_phase', 'page_type', 'page_total', 'queue_process', 'stage1_page_progress'] as $key) {
                    if (\array_key_exists($key, $progressPayload)) {
                        $payload[$key] = $progressPayload[$key];
                    }
                }

                $sse->sendEvent('progress', $payload);
            };
            $emitAiChunkProgress = $this->createPlanSseAiChunkEmitter(
                $sse,
                $effectivePromptMode,
                $rawChunkInfoBuffer
            );
            $artifacts = $effectivePromptMode === 'rebuild'
                ? $this->planJsonGenerationService->rebuildDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                    'instruction' => $instruction,
                    'round' => $round,
                ], $emitAiChunkProgress, $emitPlanPipelineProgress)
                : $this->planJsonGenerationService->refineDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                    'instruction' => $instruction,
                    'target_scope' => $targetScope,
                    'round' => $round,
                ], $emitAiChunkProgress, $emitPlanPipelineProgress);
            $this->flushPlanSseRawChunkBuffer($sse, $effectivePromptMode, $rawChunkInfoBuffer);
            if ((int)($scope['fake_mode'] ?? 0) !== 1 && (int)($artifacts['ai_generated'] ?? 0) !== 1) {
            }

            $derivedPatch = \is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [];
            $planJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
            if ($planJson === [] && \is_array($artifacts['structured'] ?? null)) {
                $planJson = $artifacts['structured'];
            }
            $scopePatch = \array_replace($derivedPatch, [
                'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
                'plan_json' => (new AiSitePlanJsonStateService((int)$session->getId()))->setConfirmed($planJson, false),
                'plan_locale' => $this->resolvePlanLocale($scope),
                'plan_ai_generated' => (int)($artifacts['ai_generated'] ?? 0),
                'stage1_validation_report' => \is_array($planJson['stage1_validation_report'] ?? null) ? $planJson['stage1_validation_report'] : [],
                'stage1_first_pass' => (int)($planJson['stage1_first_pass'] ?? 0),
                'stage1_generation_attempts' => \is_array($planJson['stage1_generation_attempts'] ?? null) ? $planJson['stage1_generation_attempts'] : [],
                'partial_retry_required' => (int)($artifacts['partial_retry_required'] ?? 0),
                'plan_generated_at' => \date('Y-m-d H:i:s'),
                // Comment removed.
                'plan_last_prompt_mode' => $effectivePromptMode,
                'plan_last_target_scope' => $targetScope,
                'plan_last_round' => $round,
                'plan_generated_locale' => $requestedPlanLocale,
                'plan_generated_page_types' => \array_values(\array_map('strval', $requestedPageTypes)),
                'plan_generated_source_signature' => $this->PlanJsonSourceSignature($scope),
                '_force_rebuild' => 0,
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
                $effectivePromptMode === 'rebuild' ? 'Plan regenerated.' : 'Plan refined.',
                [
                    'operation' => $effectivePromptMode === 'rebuild' ? 'regenerate_plan' : 'refine_plan',
                    'details' => [
                        'target_scope' => $targetScope,
                        'round' => $round,
                        'plan_locale' => $requestedPlanLocale,
                        'plan_signature' => (string)($planJsonGeneration['signature'] ?? ''),
                    ],
                ]
            );
            $sse->sendEvent('progress', [
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
                'message' => $effectivePromptMode === 'rebuild' ? 'Plan regenerated.' : 'Plan refined.',
                'prompt_mode' => $effectivePromptMode,
                'requested_prompt_mode' => $promptMode,
                'plan_locale' => $requestedPlanLocale,
                'plan' => [
                    'json' => $planJson,
                    'markdown' => $markdown,
                    'structured' => $planJson,
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
                    'message' => __('Operation completed.'),
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

    /**
     * @return array<string, mixed>
     */
    private function buildStageOnePlanRegenerationResetScopePatch(): array
    {
        return [
            '_artifact_refs' => [],
            'content_manifest' => [],
            'plan_generated_at' => '',
            'plan_generated_locale' => '',
            'plan_generated_page_types' => [],
            'plan_generated_source_signature' => '',
            'plan_ai_generated' => 0,
            'plan_last_prompt_mode' => 'rebuild',
            'plan_last_target_scope' => '',
            'plan_last_round' => 1,
            'plan_json' => [],
            'stage1_validation_report' => [],
            'stage1_first_pass' => 0,
            'stage1_generation_attempts' => [],
            'stage1_visual_qa_report' => [],
            'shared_components' => [],
            'theme_context_snapshot' => [],
            'shared_prompt_context' => [],
            'plan_rebuild_summary' => [],
            'plan_change_scope_report' => [],
            'plan_generation_progress' => [],
            'retryable_ai_failures' => [],
            'retryable_ai_failure_count' => 0,
            'next_stage_blocked_by_ai_failures' => 0,
            'partial_retry_required' => 0,
            'publish_blocked_by_latest_ai_failure' => 0,
            'build_summary' => [],
            'plan_json_task_summary' => [],
            '_plan_sse_request' => [],
            'build_contracts' => [],
            'render_data_contract' => [],
            'task_results' => [],
            'qa_report_v2' => [],
            'repair_patch' => [],
        ];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function resolveConfirmedPlanSignature(array $scope): string
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];

        foreach ([
            $planJson['confirmed_signature'] ?? null,
            $planJson['signature'] ?? null,
            $planJson['plan_signature'] ?? null,
        ] as $candidate) {
            $signature = \trim((string)$candidate);
            if ($signature !== '') {
                return $signature;
            }
        }

        return '';
    }

    private function handleResumeBuild(): string
    {
        return $this->handleStartBuild(true);
    }

    private function safeHandleStartBuild(bool $isResume = false): string
    {
        try {
            return $this->handleStartBuild($isResume);
        } catch (ResponseTerminateException $terminate) {
            throw $terminate;
        } catch (\Throwable $throwable) {
            \error_log('[AiSiteStartBuild] failed: ' . $throwable->getMessage() . ' in ' . $throwable->getFile() . ':' . $throwable->getLine());
            return $this->jsonError(
                'START_BUILD_FAILED',
                (string)__('Failed to start build.'),
                self::PARAMS_PUBLIC_ID
            );
        }
    }

    private function handleStartBuild(bool $isResume = false): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $error = '';
        $scopePatch = $this->getRequestJsonObject('scope_patch', $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        if ((int)$this->getRequestBodyValue('_force_rebuild', 0) === 1) {
            $scopePatch['_force_rebuild'] = 1;
        }
        if (isset($scopePatch['page_types']) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $scopePatch)) {
            $scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        $scopePatch = $this->dropEmptyProfileIdentityPatchValues($scopePatch);
        $currentScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForBuildOperation($session)
        );
        $currentScope = $this->normalizePlanJsonConfirmationForBuild($currentScope);
        $scopePatch = $this->planJsonTaskService->stripPlanJsonMutationScopePatch($scopePatch, $currentScope);
        $mergedScope = $this->scopeCompatibilityService->normalizeScope(\array_replace($currentScope, $scopePatch));
        $mergedScope = $this->planJsonTaskService->restorePlanJsonContract($mergedScope, $currentScope);
        $mergedScope = $this->normalizePlanJsonConfirmationForBuild($mergedScope);
        $websiteProfile = $this->profileGenerationService->generate($mergedScope, false);
        if (\is_array($websiteProfile) && $websiteProfile !== []) {
            $mergedScope['website_profile'] = $websiteProfile;
            $scopePatch['website_profile'] = $websiteProfile;
        }
        if ($this->isPlanJsonReadyForBuild($mergedScope)) {
            $mergedScope = $this->planJsonTaskService->ensureTaskScope(
                $mergedScope,
                \is_array($mergedScope['website_profile'] ?? null) ? $mergedScope['website_profile'] : [],
                (string)($mergedScope['workspace_track'] ?? '')
            );
            $scopePatch = \array_replace($scopePatch, $this->planJsonTaskService->extractPlanJsonDerivedScopePatch($mergedScope));
            $scopePatch['plan_json_task_summary'] = $this->planJsonTaskService->summarize($mergedScope);
            if (\is_array($scopePatch['build_summary'] ?? null)) {
                unset($scopePatch['build_summary']['task_summary']);
            }
        }
        if ($this->planJsonTaskService->hasRetryableAiFailures($mergedScope, 'plan')) {
            $summary = $this->planJsonTaskService->summarizeRetryableAiFailures($mergedScope, 'plan');
            return $this->jsonError(
                'RETRYABLE_AI_FAILURES_PENDING',
                'Retryable AI failures must be retried before continuing.',
                ['public_id'],
                ['retryable_ai_failure_count' => (int)($summary['count'] ?? 0), 'retryable_ai_failures' => $summary]
            );
        }
        if (!$this->isPlanJsonReadyForBuild($mergedScope)) {
            return $this->jsonError(
                'PLAN_JSON_REQUIRED_BEFORE_BUILD',
                'A confirmed stage-1 plan_json is required before build.',
                ['public_id', 'plan_json']
            );
        }
        if ($isResume) {
            $resumeSummary = $this->planJsonTaskService->summarize($mergedScope);
            $resumeTaskCount = (int)($resumeSummary['pending'] ?? 0)
                + (int)($resumeSummary['failed'] ?? 0)
                + (int)($resumeSummary['running'] ?? 0);
            $retryableSummary = $this->planJsonTaskService->summarizeRetryableAiFailures($mergedScope, 'build');
            $retryableFailureCount = (int)($retryableSummary['count'] ?? 0);
            if ($resumeTaskCount <= 0 && $retryableFailureCount <= 0) {
                return $this->fetchJson([
                    'success' => true,
                    'data' => $this->buildWorkspaceOperationPayload(
                        $this->buildWorkspaceState($session, $adminId, 24, true),
                        'build'
                    ),
                ]);
            }
        }
        $startStage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        if ($startStage === AiSiteAgentSession::STAGE_PUBLISH) {
            $startStage = AiSiteAgentSession::STAGE_VISUAL_EDIT;
        }
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
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            $isResume ? ['resume_failed_tasks' => 1] : ['fresh_repair_failed_tasks' => 1]
        );
        if (!empty($startResult['success'])) {
            $startResult['start_sse'] = true;
        }
        return $this->fetchJson($startResult);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function dropEmptyProfileIdentityPatchValues(array $payload): array
    {
        $identityFields = ['site_title', 'site_tagline', 'target_domain', 'brief_description', 'user_description', 'default_locale', 'plan_locale'];
        foreach ($identityFields as $field) {
            if (\array_key_exists($field, $payload) && !$this->isMeaningfulProfileManualValue($payload[$field])) {
                unset($payload[$field]);
            }
        }
        if (\array_key_exists('site_profile_manual', $payload) && !\is_array($payload['site_profile_manual'])) {
            unset($payload['site_profile_manual']);
        }
        if (\is_array($payload['site_profile_manual'] ?? null)) {
            foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale', 'plan_locale'] as $field) {
                if (!\array_key_exists($field, $payload) || !$this->isMeaningfulProfileManualValue($payload[$field])) {
                    unset($payload['site_profile_manual'][$field]);
                }
            }
            if ($payload['site_profile_manual'] === []) {
                unset($payload['site_profile_manual']);
            }
        }

        return $payload;
    }

    private function isMeaningfulProfileManualValue(mixed $value): bool
    {
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            return \trim((string)$value) !== '';
        }

        return $value !== null && $value !== [];
    }

    private function handleStartAssetGeneration(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $slotId = \trim((string)$this->getRequestBodyValue('slot_id', ''));
        $promptBrief = \trim((string)$this->getRequestBodyValue('prompt_brief', ''));
        $slotType = \trim((string)$this->getRequestBodyValue('slot_type', 'section_image'));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $label = \trim((string)$this->getRequestBodyValue('label', ''));
        $mode = \trim((string)$this->getRequestBodyValue('mode', 'generate'));
        $executionToken = \trim((string)$this->getRequestBodyValue('execution_token', ''));
        $currentUrl = \trim((string)$this->getRequestBodyValue('current_url', ''));
        $resolvedUrl = \trim((string)$this->getRequestBodyValue('resolved_url', ''));
        $blockId = \trim((string)$this->getRequestBodyValue('block_id', ''));
        $componentCode = \trim((string)$this->getRequestBodyValue('component_code', ''));
        if ($executionToken === '') {
            $executionToken = \bin2hex(\random_bytes(16));
        }
        if (!\in_array($mode, ['generate', 'regenerate'], true)) {
            $mode = 'generate';
        }
        if ($slotId === '' && $promptBrief !== '') {
            $slotId = 'agent_tool_' . \substr(\sha1($promptBrief . ':' . \microtime(true)), 0, 12);
        }
        if (!\in_array($slotType, ['hero_image', 'trust_brand_image', 'section_image', 'logo_icon'], true)) {
            $slotType = 'section_image';
        }
        if ($adminId <= 0 || $publicId === '' || $slotId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $assetManifestService = $this->assetManifestService();
        $manifest = $assetManifestService->syncFromPlanJson($scope);
        $slot = $assetManifestService->getSlot($manifest, $slotId);
        if ($slot === [] && $promptBrief !== '') {
            $manifest = $assetManifestService->upsert($manifest, [
                'slot_id' => $slotId,
                'slot_type' => $slotType,
                'kind' => $slotType,
                'page_type' => $pageType,
                'label' => $label !== '' ? $label : 'AI generated image',
                'brief' => $promptBrief,
                'prompt_brief' => $promptBrief,
                'status' => 'pending',
                'source' => 'agent_tool',
                'final_url' => '',
                'locked_by_user' => 0,
            ]);
            $slot = $assetManifestService->getSlot($manifest, $slotId);
        }
        if ($slot === []) {
            return $this->jsonError('ASSET_SLOT_NOT_FOUND', 'Asset slot does not exist.', ['public_id', 'slot_id']);
        }
        if ((int)($slot['locked_by_user'] ?? 0) === 1) {
            return $this->jsonError('ASSET_SLOT_LOCKED', 'Asset slot is locked by user.', ['public_id', 'slot_id']);
        }
        if ($promptBrief !== '') {
            $manifest = $assetManifestService->upsert($manifest, [
                'slot_id' => (string)($slot['slot_id'] ?? $slotId),
                'slot_type' => $slotType !== '' ? $slotType : (string)($slot['slot_type'] ?? 'section_image'),
                'kind' => $slotType !== '' ? $slotType : (string)($slot['kind'] ?? 'section_image'),
                'page_type' => $pageType !== '' ? $pageType : (string)($slot['page_type'] ?? ''),
                'label' => $label !== '' ? $label : (string)($slot['label'] ?? 'AI generated image'),
                'brief' => $promptBrief,
                'prompt_brief' => $promptBrief,
                'locked_by_user' => (int)($slot['locked_by_user'] ?? 0),
            ]);
            $slot = $assetManifestService->getSlot($manifest, $slotId);
        }

        $manifest = $assetManifestService->markQueued($manifest, $slotId, $executionToken);
        $verifiedAssets = $assetManifestService->extractVerifiedAssets($manifest);
        $this->sessionService->mergeScope((int)$session->getId(), $adminId, [
            'asset_manifest' => $manifest,
            'verified_assets' => $verifiedAssets,
        ]);
        $session = $this->sessionService->loadById((int)$session->getId(), $adminId) ?? $session;

        $normalizedSlotId = (string)($slot['slot_id'] ?? $slotId);
        $jobType = $this->resolveAiSiteQueueJobType('image_asset');
        $safeSlot = $this->sanitizeAiSiteAssetPathSegment($normalizedSlotId);
        $content = \array_replace($this->buildOperationQueueEnvelope($session, 'image_asset', $executionToken, 'queued'), [
            'public_id' => $session->getPublicId(),
            'admin_id' => $adminId,
            'operation' => 'image_asset',
            'slot_id' => $normalizedSlotId,
            'mode' => $mode,
            'planning_signature' => (string)($slot['planning_signature'] ?? ''),
            'execution_token' => $executionToken,
            'target_path' => $this->buildAiSiteAssetTargetPath($scope, $session, $normalizedSlotId, $executionToken),
            'current_url' => $currentUrl,
            'resolved_url' => $resolvedUrl,
            'block_id' => $blockId,
            'component_code' => $componentCode,
            'page_type' => $pageType !== '' ? $pageType : (string)($slot['page_type'] ?? ''),
            'label' => $label !== '' ? $label : (string)($slot['label'] ?? ''),
            'job_key' => $this->buildAiSiteQueueJobKey((int)$session->getId(), $jobType . ':' . $safeSlot),
            'job_type' => $jobType,
            'queue_status' => 'queued',
        ]);
        $bizKey = $this->buildAiSiteAssetQueueBizKey((int)$session->getId(), $normalizedSlotId);
        $queueName = 'PageBuilder image asset ' . \substr($safeSlot, 0, 96);
        $queueResult = $this->createOrReuseAiSiteAssetQueue($bizKey, $queueName, $content);
        $queueId = (int)($queueResult['queue_id'] ?? 0);
        $effectiveExecutionToken = (string)($queueResult['execution_token'] ?? $executionToken);
        $queueStatus = (string)($queueResult['queue_status'] ?? 'pending');
        if ($queueId <= 0) {
            return $this->jsonError('ASSET_QUEUE_CREATE_FAILED', 'Failed to create image asset queue.', ['public_id', 'slot_id']);
        }

        $operationScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $operationScope = $this->writeActiveOperationStateToScope($operationScope, [
            'operation' => 'image_asset',
            'queue_status' => 'queued',
            'execution_token' => $effectiveExecutionToken,
            'token' => $effectiveExecutionToken,
            'queue_id' => $queueId,
            'biz_key' => $bizKey,
            'slot_id' => $normalizedSlotId,
            'page_type' => $pageType !== '' ? $pageType : (string)($slot['page_type'] ?? ''),
            'block_id' => $blockId,
            'component_code' => $componentCode,
            'job_key' => (string)($content['job_key'] ?? ''),
            'job_type' => (string)($content['job_type'] ?? ''),
            'message' => 'Image asset generation queued; waiting for the system scheduler.',
            'queue_waiting_for_scheduler' => true,
            'can_close_stream' => true,
            'continue_other_operations' => true,
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $this->sessionService->replaceScope((int)$session->getId(), $adminId, $operationScope);

        $fresh = $this->sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceState($fresh, $adminId, 24, true);
        $data = $this->buildWorkspaceOperationPayload($state, 'image_asset');
        $streamUrl = $this->buildOperationStreamUrl($fresh->getPublicId(), $effectiveExecutionToken);
        $data = \array_replace($data, [
            'queue_id' => $queueId,
            'biz_key' => $bizKey,
            'operation' => 'image_asset',
            'execution_token' => $effectiveExecutionToken,
            'stream_url' => $streamUrl,
            'slot_id' => $normalizedSlotId,
            'queue_status' => $queueStatus,
            'queue_waiting_for_scheduler' => true,
            'asset_manifest' => \is_array($state['asset_manifest'] ?? null) ? $state['asset_manifest'] : $manifest,
        ]);

        return $this->fetchJson([
            'success' => true,
            'queue_id' => $queueId,
            'biz_key' => $bizKey,
            'operation' => 'image_asset',
            'execution_token' => $effectiveExecutionToken,
            'stream_url' => $streamUrl,
            'slot_id' => $normalizedSlotId,
            'queue_status' => $queueStatus,
            'queue_waiting_for_scheduler' => true,
            'data' => $data,
        ]);
    }

    /**
     * @param array<string,mixed> $content
     */
    private function createOrReuseAiSiteAssetQueue(string $bizKey, string $queueName, array $content): array
    {
        $queueClass = \GuoLaiRen\PageBuilder\Queue\AiSiteAssetQueue::class;
        $existing = $this->findAiSiteQueueRowByBizKey($bizKey, true);
        if (\is_array($existing) && $existing !== []) {
            $queueId = (int)($existing['queue_id'] ?? $existing['id'] ?? 0);
            if ($queueId > 0) {
                $status = \trim((string)($existing['status'] ?? ''));
                $normalizedStatus = \strtolower($status);
                if (\in_array($normalizedStatus, ['pending', 'queued', 'running'], true)) {
                    $existingContent = $existing['content'] ?? [];
                    if (\is_string($existingContent)) {
                        $decoded = \json_decode($existingContent, true);
                        $existingContent = \is_array($decoded) ? $decoded : [];
                    }
                    $existingSignature = \is_array($existingContent)
                        ? \trim((string)($existingContent['planning_signature'] ?? ''))
                        : '';
                    $nextSignature = \trim((string)($content['planning_signature'] ?? ''));
                    if ($nextSignature === '' || $existingSignature === '' || \hash_equals($existingSignature, $nextSignature)) {
                        $effectiveExecutionToken = (string)(\is_array($existingContent)
                            ? ($existingContent['execution_token'] ?? $content['execution_token'] ?? '')
                            : ($content['execution_token'] ?? ''));
                        if (\in_array($normalizedStatus, ['pending', 'queued'], true)) {
                            $existingName = \trim((string)($existing['name'] ?? ''));
                            if ($existingName !== $queueName) {
                                $refreshed = w_query('queue', 'update', [
                                    'queue_id' => $queueId,
                                    'patch' => [
                                        'name' => $queueName,
                                        'module' => 'GuoLaiRen_PageBuilder',
                                        'type_id' => $this->resolveAiSiteQueueTypeId($queueClass),
                                        'content' => $content,
                                        'status' => 'pending',
                                        'auto' => true,
                                        'biz_key' => $bizKey,
                                        'result' => '',
                                        'process' => '',
                                        'pid' => 0,
                                        'finished' => 0,
                                    ],
                                    'wake_scheduler' => false,
                                ]);
                                if (\is_array($refreshed) && !empty($refreshed['success'])) {
                                    $effectiveExecutionToken = (string)($content['execution_token'] ?? $effectiveExecutionToken);
                                    $status = 'pending';
                                }
                            }
                        }
                        return [
                            'queue_id' => $queueId,
                            'execution_token' => $effectiveExecutionToken,
                            'queue_status' => $status,
                            'reused' => true,
                        ];
                    }
                    if ($normalizedStatus === 'running') {
                        return [
                            'queue_id' => $queueId,
                            'execution_token' => (string)(\is_array($existingContent)
                                ? ($existingContent['execution_token'] ?? $existingContent['token'] ?? '')
                                : ''),
                            'queue_status' => $status,
                            'reused' => true,
                        ];
                    }
                }
                $updated = w_query('queue', 'update', [
                    'queue_id' => $queueId,
                    'patch' => [
                        'name' => $queueName,
                        'module' => 'GuoLaiRen_PageBuilder',
                        'type_id' => $this->resolveAiSiteQueueTypeId($queueClass),
                        'content' => $content,
                        'status' => 'pending',
                        'auto' => true,
                        'biz_key' => $bizKey,
                        'result' => '',
                        'process' => '',
                        'pid' => 0,
                        'finished' => 0,
                    ],
                    'wake_scheduler' => false,
                ]);
                if (\is_array($updated) && !empty($updated['success'])) {
                    return [
                        'queue_id' => (int)($updated['queue_id'] ?? $queueId),
                        'execution_token' => (string)($content['execution_token'] ?? ''),
                        'queue_status' => 'pending',
                        'reused' => true,
                    ];
                }
            }
        }

        $created = w_query('queue', 'create', [
            'class' => $queueClass,
            'name' => $queueName,
            'module' => 'GuoLaiRen_PageBuilder',
            'content' => $content,
            'status' => 'pending',
            'auto' => true,
            'biz_key' => $bizKey,
            'wake_scheduler' => false,
        ]);

        return [
            'queue_id' => (int)(\is_array($created) && !empty($created['success']) ? ($created['queue_id'] ?? 0) : 0),
            'execution_token' => (string)($content['execution_token'] ?? ''),
            'queue_status' => 'pending',
            'reused' => false,
        ];
    }

    private function handleUploadReferenceImage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', 'Invalid public_id.', ['public_id']);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session does not exist or is not accessible.', ['public_id']);
        }

        $file = $this->resolveReferenceImageUploadFile();
        if ($file === []) {
            return $this->jsonError('REFERENCE_IMAGE_REQUIRED', 'Reference image is required.', ['reference_image']);
        }
        if ((int)($file['error'] ?? \UPLOAD_ERR_OK) !== \UPLOAD_ERR_OK) {
            return $this->jsonError('REFERENCE_IMAGE_UPLOAD_FAILED', 'Reference image upload failed.', ['reference_image']);
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !\is_file($tmpName)) {
            return $this->jsonError('REFERENCE_IMAGE_TMP_MISSING', 'Uploaded reference image is missing.', ['reference_image']);
        }

        $size = (int)($file['size'] ?? \filesize($tmpName) ?: 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            return $this->jsonError('REFERENCE_IMAGE_SIZE_INVALID', 'Reference image must be smaller than 10MB.', ['reference_image']);
        }

        $imageInfo = @\getimagesize($tmpName);
        $mimeType = \is_array($imageInfo) ? \strtolower((string)($imageInfo['mime'] ?? '')) : '';
        $extensionByMime = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extensionByMime[$mimeType])) {
            return $this->jsonError('REFERENCE_IMAGE_TYPE_INVALID', 'Only jpg, png, webp and gif reference images are supported.', ['reference_image']);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $this->scopeCompatibilityService->normalizeStage($session->getStage()))
        );
        $handle = $this->resolveAiSiteAssetTargetHandle($scope, $session);
        $originalName = \basename((string)($file['name'] ?? 'reference-image'));
        $safeName = $this->sanitizeAiSiteAssetPathSegment(\pathinfo($originalName, \PATHINFO_FILENAME));
        $hash = \substr(\sha1_file($tmpName) ?: \sha1($tmpName . ':' . \microtime(true)), 0, 16);
        $relativePath = 'pub/media/page-build/' . $handle . '/reference/' . $safeName . '-' . $hash . '.' . $extensionByMime[$mimeType];
        $targetPath = \rtrim((string)BP, '/\\') . DS . \str_replace(['/', '\\'], DS, $relativePath);
        $targetDir = \dirname($targetPath);
        if (!\is_dir($targetDir) && !@\mkdir($targetDir, 0775, true) && !\is_dir($targetDir)) {
            return $this->jsonError('REFERENCE_IMAGE_SAVE_DIR_FAILED', 'Failed to create reference image directory.', ['reference_image']);
        }

        $moved = \is_uploaded_file($tmpName) ? @\move_uploaded_file($tmpName, $targetPath) : @\copy($tmpName, $targetPath);
        if (!$moved) {
            return $this->jsonError('REFERENCE_IMAGE_SAVE_FAILED', 'Failed to save reference image.', ['reference_image']);
        }

        $referenceImage = [
            'url' => '/' . \str_replace('\\', '/', $relativePath),
            'path' => $relativePath,
            'mime_type' => $mimeType,
            'size' => $size,
            'name' => $originalName,
            'uploaded_at' => \date('Y-m-d H:i:s'),
        ];
        $referenceImages = $this->resolveReferenceImagesFromScope($scope);
        $referenceImages[] = $referenceImage;
        $referenceImages = \array_values(\array_slice($referenceImages, -12));

        $saved = $this->sessionService->mergeScope((int)$session->getId(), $adminId, [
            'reference_images' => $referenceImages,
        ]);
        if (!$saved) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }
        if (!$saved) {
            return $this->jsonError('REFERENCE_IMAGE_SCOPE_SAVE_FAILED', 'Failed to save reference image into workspace.', ['reference_image']);
        }

        $fresh = $this->sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        return $this->fetchJson([
            'success' => true,
            'message' => 'Reference image uploaded.',
            'reference_image' => $referenceImage,
            'reference_images' => $referenceImages,
            'data' => $this->buildWorkspaceState($fresh, $adminId, 24, true),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveReferenceImageUploadFile(): array
    {
        foreach (['reference_image', 'image', 'file'] as $key) {
            if (isset($_FILES[$key]) && \is_array($_FILES[$key])) {
                return $_FILES[$key];
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function resolveReferenceImagesFromScope(array $scope): array
    {
        $images = \is_array($scope['reference_images'] ?? null)
            ? $scope['reference_images']
            : (\is_array($scope['scope']['reference_images'] ?? null) ? $scope['scope']['reference_images'] : []);
        if ($images === []) {
            return [];
        }

        $normalized = [];
        foreach ($images as $image) {
            if (!\is_array($image)) {
                continue;
            }
            $url = \trim((string)($image['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $normalized[] = [
                'url' => $url,
                'path' => \trim((string)($image['path'] ?? '')),
                'mime_type' => \trim((string)($image['mime_type'] ?? '')),
                'size' => (int)($image['size'] ?? 0),
                'name' => \trim((string)($image['name'] ?? 'reference-image')),
                'uploaded_at' => \trim((string)($image['uploaded_at'] ?? '')),
            ];
        }

        return \array_values($normalized);
    }

    private function buildAiSiteAssetQueueBizKey(int $sessionId, string $slotId): string
    {
        $raw = 'glr_aisite:session:' . $sessionId . ':asset:' . $this->sanitizeAiSiteAssetPathSegment($slotId);
        return \strlen($raw) > 191 ? \substr($raw, 0, 191) : $raw;
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildAiSiteAssetTargetPath(array $scope, AiSiteAgentSession $session, string $slotId, string $executionToken): string
    {
        $handle = $this->resolveAiSiteAssetTargetHandle($scope, $session);
        $safeSlot = $this->sanitizeAiSiteAssetPathSegment($slotId);
        $hash = \substr(\sha1($slotId . ':' . $executionToken), 0, 12);

        return 'pub/media/page-build/ai-generated/' . $handle . '/' . $safeSlot . '-' . $hash . '.webp';
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveAiSiteAssetTargetHandle(array $scope, AiSiteAgentSession $session): string
    {
        $localPreviewHost = $this->resolveAiSiteLocalPreviewHost($scope);
        if ($localPreviewHost !== '') {
            return $this->sanitizeAiSiteAssetPathSegment($localPreviewHost);
        }

        foreach ([
            $scope['target_domain'] ?? null,
            $scope['selected_domain'] ?? null,
            $scope['website_profile']['site_title'] ?? null,
            $scope['site_title'] ?? null,
            $session->getPublicId(),
        ] as $value) {
            $handle = $this->sanitizeAiSiteAssetPathSegment((string)$value);
            if ($handle !== '') {
                return $handle;
            }
        }

        return 'site';
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function resolveAiSiteLocalPreviewHost(array $scope): string
    {
        foreach (['preview_full_url', 'visual_preview_url', 'visual_edit_url', 'preview_url'] as $key) {
            $url = \trim((string)($scope[$key] ?? ''));
            if ($url === '') {
                continue;
            }
            $host = \parse_url($url, \PHP_URL_HOST);
            $host = \is_string($host) ? \strtolower(\trim($host)) : '';
            if ($host !== '' && (\str_ends_with($host, '.weline.test') || \str_ends_with($host, '.local.test'))) {
                return $host;
            }
        }

        return '';
    }

    private function sanitizeAiSiteAssetPathSegment(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        return \trim($value, '-_.') ?: 'asset';
    }

    private function resolvePageSectionBuildDispatchWindow(): int
    {
        $configured = (int)\Weline\Framework\App\Env::get('pagebuilder.ai_site.page_section_build_dispatch_window', 0);
        if ($configured > 0) {
            return \max(1, $configured);
        }

        return \max(1, self::DEFAULT_PAGE_SECTION_BUILD_DISPATCH_WINDOW);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isPlanJsonReadyForBuild(array $scope): bool
    {
        return $this->planJsonTaskServiceForRead()->hasConfirmedPlanJsonForBuild($scope);
    }

    private function planJsonTaskServiceForRead(): AiSitePlanJsonTaskService
    {
        return isset($this->planJsonTaskService)
            ? $this->planJsonTaskService
            : ObjectManager::getInstance(AiSitePlanJsonTaskService::class);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function PlanJsonPageValidationScopePatch(array $scope): array
    {
        $coverage = $this->planJsonTaskServiceForRead()->inspectConfirmedPlanJsonPageTypeCoverage($scope);
        $missingPages = \is_array($coverage['missing_page_types'] ?? null) ? $coverage['missing_page_types'] : [];
        $validation = $missingPages === []
            ? ['valid' => true, 'errors' => []]
            : [
                'valid' => false,
                'errors' => [
                    'PLAN_JSON_PAGES_INVALID: plan_json.pages missing selected page_types: '
                    . \implode(', ', $missingPages),
                ],
            ];

        return [
            'plan_json_pages_validation' => $validation,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function PlanJsonJsonConfirmationScopePatch(array $scope, int|string|null $sessionId = null): array
    {
        try {
            /** @var AiSitePlanJsonTaskScheduler $scheduler */
            $scheduler = ObjectManager::getInstance(AiSitePlanJsonTaskScheduler::class);
            return $scheduler->buildConfirmationScopePatch(
                $scope,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                (string)($scope['workspace_track'] ?? ''),
                $sessionId
            );
        } catch (\Throwable $throwable) {
            return [
                'plan_json_pages_validation' => [
                    'valid' => false,
                    'errors' => [$throwable->getMessage()],
                ],
            ];
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function normalizePlanJsonConfirmationForBuild(array $scope): array
    {
        return $this->planJsonTaskService->normalizePlanJsonConfirmedState($scope);
    }

    private function handleStartRegeneratePage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        if ($adminId <= 0 || $publicId === '' || $pageType === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
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

        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $componentCode === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid request.'), self::PARAMS_PUBLIC_ID);
        }

        $componentCodes = $this->resolveRequestedBlockComponentCodes($componentCode);
        $label = $componentLabel !== '' ? $componentLabel : (string)($componentCodes[0] ?? $componentCode);
        $queueAction = $instruction !== '' ? 'refine' : 'regenerate';
        $queueContext = $this->buildBlockRegenerateQueueContext(
            $scope,
            $pageTypes,
            $pageType,
            $componentCodes,
            $instruction,
            $queueAction,
            $componentLabel
        );

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'component_refine_requested',
            ($queueAction === 'refine' ? 'Block refine queued: ' : 'Block regenerate queued: ') . $label,
            [
                'operation' => 'block_regenerate',
                'page_type' => $pageType,
                'details' => $queueContext['details'],
            ]
        );

        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            'block_regenerate',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            $queueContext['scope_patch'],
            $pageType,
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            $queueContext['details']
        ));

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
            $scopePatch['plan_json'] = $this->withPlanJsonPages($scope, $virtualPages)['plan_json'];
        }

        $label = $componentLabel !== '' ? $componentLabel : $componentCode;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'component_refine_requested',
            (string)__('Operation completed.'),
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
                    ? ('plan_json.shared_components.' . $sharedRegion)
                    : ('plan_json.pages.' . $pageType . '.' . $componentCode),
            ]
        ));
    }

    private function handleStartPatchBlock(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $blockId = \trim((string)$this->getRequestBodyValue('block_id', ''));
        $componentCode = \trim((string)$this->getRequestBodyValue('component_code', ''));
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        $componentLabel = \trim((string)$this->getRequestBodyValue('component_label', ''));
        if ($blockId === '') {
            $blockId = $componentCode;
        }

        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $blockId === '' || $instruction === '') {
            return $this->jsonError('INVALID_PARAMS', 'Missing required patch block parameters.', self::PARAMS_PATCH_BLOCK);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Workspace session not found.', self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            return $this->jsonError('INVALID_PARAMS', 'Selected page type is not available in the current workspace.', self::PARAMS_PATCH_BLOCK);
        }

        $read = $this->blockPartialPatchService()->readCurrentBlockFromScope($scope, $pageType, $blockId);
        if (empty($read['success'])) {
            return $this->fetchJson([
                'success' => false,
                'message' => (string)($read['message'] ?? 'Target block was not found.'),
                'code' => (string)($read['code'] ?? 'BLOCK_NOT_FOUND'),
                'details' => \is_array($read['details'] ?? null) ? $read['details'] : [],
            ]);
        }

        $actualBlockId = \trim((string)($read['block_id'] ?? $blockId));
        $actualComponentCode = \trim((string)($read['component_code'] ?? $componentCode));
        $label = $componentLabel !== ''
            ? $componentLabel
            : ($actualComponentCode !== '' ? $actualComponentCode : $actualBlockId);
        $readSource = \trim((string)($read['source'] ?? ''));
        $targetScope = \str_starts_with($readSource, 'plan_json.shared_components.')
            ? $readSource
            : ('plan_json.pages.' . $pageType . '.' . $actualBlockId);
        $details = [
            'stage_scope' => 'build',
            'action' => 'partial_patch',
            'page_type' => $pageType,
            'block_id' => $actualBlockId,
            'block_key' => $actualBlockId,
            'component_code' => $actualComponentCode !== '' ? $actualComponentCode : $actualBlockId,
            'component_label' => $label,
            'instruction' => $instruction,
            'target_scope' => $targetScope,
            'source' => $readSource,
        ];

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'block_partial_patch_requested',
            'Block partial patch queued: ' . $label,
            [
                'operation' => 'block_partial_patch',
                'page_type' => $pageType,
                'details' => $details,
            ]
        );

        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            'block_partial_patch',
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            [],
            $pageType,
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            $details
        ));
    }

    private function handleRetryAiOperation(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $requestedOperation = \strtolower(\trim((string)$this->getRequestBodyValue('operation', '')));
        $queueId = (int)$this->getRequestBodyValue('queue_id', 0);
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', 'Missing retry operation parameters.', self::PARAMS_PUBLIC_ID);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Workspace session not found.', self::PARAMS_PUBLIC_ID);
        }

        $retry = $this->resolveRetryAiOperationRequest($session, $adminId, $requestedOperation, $queueId);
        if ($retry === []) {
            return $this->jsonError('RETRY_OPERATION_NOT_FOUND', 'No retryable AI operation was found for the current workspace.', ['operation', 'queue_id']);
        }

        $operation = \trim((string)($retry['operation'] ?? ''));
        $pageType = \trim((string)($retry['page_type'] ?? ''));
        $details = \is_array($retry['details'] ?? null) ? $retry['details'] : [];
        $scopePatch = \is_array($retry['scope_patch'] ?? null) ? $retry['scope_patch'] : [];
        if ($operation === '' || !$this->isRetryableAiOperationName($operation)) {
            return $this->jsonError('RETRY_OPERATION_UNSUPPORTED', 'The failed AI operation cannot be retried.', ['operation']);
        }
        if ($operation === 'build') {
            $buildScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
            );
            if (!$this->planJsonTaskService->hasConfirmedPlanJsonForBuild($buildScope)) {
                return $this->jsonError(
                    'PLAN_JSON_REQUIRED_BEFORE_BUILD',
                    'A confirmed stage-1 plan_json is required before build.',
                    ['public_id', 'plan_json']
                );
            }
        }
        if (!\in_array($operation, ['plan', 'build'], true) && $pageType === '') {
            return $this->jsonError('RETRY_OPERATION_INCOMPLETE', 'The failed AI operation is missing page_type.', ['page_type']);
        }
        if ($operation === 'block_partial_patch') {
            $blockId = \trim((string)($details['block_id'] ?? $details['block_key'] ?? ''));
            $instruction = \trim((string)($details['instruction'] ?? ''));
            if ($blockId === '' || $instruction === '') {
                return $this->jsonError('RETRY_OPERATION_INCOMPLETE', 'The failed block patch is missing block_id or instruction.', ['block_id', 'instruction']);
            }
        }

        $details['retry_of_queue_id'] = (int)($retry['queue_id'] ?? $queueId);
        $details['retry_source'] = 'failed_ai_operation';
        if ($operation === 'build') {
            unset($details['fresh_repair_failed_tasks']);
            $details['resume_failed_tasks'] = 1;
            $scopePatch = [];
        } elseif ($operation === 'plan') {
            $details['prompt_mode'] = 'resume_plan';
            $details['resume_failed_tasks'] = 1;
        }
        $stage = $operation === 'plan'
            ? AiSiteAgentSession::STAGE_PLAN
            : AiSiteAgentSession::STAGE_VISUAL_EDIT;

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $stage,
            'ai_operation_retry_requested',
            'Retry AI operation queued: ' . $operation,
            [
                'operation' => $operation,
                'queue_id' => (int)($retry['queue_id'] ?? $queueId),
                'page_type' => $pageType,
                'details' => $details,
            ]
        );

        return $this->fetchJson($this->startOperation(
            $session,
            $adminId,
            $operation,
            $stage,
            $scopePatch,
            $pageType,
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            $details
        ));
    }

    /**
     * @return array{operation?: string, page_type?: string, queue_id?: int, details?: array<string, mixed>, scope_patch?: array<string, mixed>}
     */
    private function resolveRetryAiOperationRequest(
        AiSiteAgentSession $session,
        int $adminId,
        string $requestedOperation,
        int $queueId
    ): array {
        if ($queueId > 0) {
            $fromQueue = $this->resolveRetryAiOperationFromQueueId($session, $adminId, $requestedOperation, $queueId);
            if ($fromQueue !== []) {
                return $fromQueue;
            }
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        foreach ($this->collectRetryAiOperationStateCandidates($scope, $requestedOperation) as $candidate) {
            $candidateQueueId = (int)($candidate['queue_id'] ?? 0);
            if ($candidateQueueId > 0) {
                $fromQueue = $this->resolveRetryAiOperationFromQueueId($session, $adminId, $requestedOperation, $candidateQueueId);
                if ($fromQueue !== []) {
                    return $fromQueue;
                }
            }
            $fromState = $this->normalizeRetryAiOperationState($candidate, $requestedOperation);
            if ($fromState !== []) {
                return $fromState;
            }
        }

        if ($this->canResumeRetryableAiFailureLedger($scope, $requestedOperation)) {
            $retryableSummary = $this->planJsonTaskService->summarizeRetryableAiFailures($scope, 'build');
            return [
                'operation' => 'build',
                'page_type' => '',
                'queue_id' => 0,
                'details' => [
                    'resume_failed_tasks' => 1,
                    'retryable_ai_failure_count' => (int)($retryableSummary['count'] ?? 0),
                ],
                'scope_patch' => [],
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function canResumeRetryableAiFailureLedger(array $scope, string $requestedOperation): bool
    {
        $requestedOperation = \strtolower(\trim($requestedOperation));
        if ($requestedOperation !== '' && $requestedOperation !== 'build') {
            return false;
        }

        $retryableSummary = $this->planJsonTaskService->summarizeRetryableAiFailures($scope, 'build');
        return (int)($retryableSummary['count'] ?? 0) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectRetryAiOperationStateCandidates(array $scope, string $requestedOperation): array
    {
        $candidates = [];
        $append = static function ($state) use (&$candidates): void {
            if (\is_array($state) && $state !== []) {
                $candidates[] = $state;
            }
        };

        $append($scope['active_operation'] ?? null);
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        if ($requestedOperation !== '' && \is_array($activeOperations[$requestedOperation] ?? null)) {
            $append($activeOperations[$requestedOperation]);
        }
        foreach ($activeOperations as $operation => $operationState) {
            if (\is_array($operationState)) {
                if (\trim((string)($operationState['operation'] ?? '')) === '' && \is_string($operation)) {
                    $operationState['operation'] = $operation;
                }
                $append($operationState);
            }
        }
        $append($scope['latest_build_failure'] ?? null);

        return $candidates;
    }

    /**
     * @return array{operation?: string, page_type?: string, queue_id?: int, details?: array<string, mixed>, scope_patch?: array<string, mixed>}
     */
    private function resolveRetryAiOperationFromQueueId(
        AiSiteAgentSession $session,
        int $adminId,
        string $requestedOperation,
        int $queueId
    ): array {
        try {
            $queueRow = $this->findAiSiteQueueRowById($queueId, true);
        } catch (\Throwable) {
            return [];
        }
        if (\is_object($queueRow) && \method_exists($queueRow, 'getData')) {
            $queueRow = $queueRow->getData();
        }
        if (!\is_array($queueRow) || $queueRow === []) {
            return [];
        }
        $content = $this->decodeAiSiteQueueRowContent($queueRow);
        if ($content === []) {
            return [];
        }
        if (\trim((string)($content['public_id'] ?? '')) !== (string)$session->getPublicId()) {
            return [];
        }
        $contentAdminId = (int)($content['admin_id'] ?? 0);
        if ($contentAdminId > 0 && $contentAdminId !== $adminId) {
            return [];
        }

        $operation = \strtolower(\trim((string)($content['operation'] ?? '')));
        if ($operation === '') {
            $operation = $this->resolveAiSiteOperationFromQueueJobType(\trim((string)($content['job_type'] ?? '')));
        }
        if ($operation === '' || !$this->canRetryAiOperationForRequested($requestedOperation, $operation)) {
            return [];
        }

        $details = \is_array($content['details'] ?? null) ? $content['details'] : [];
        foreach ($this->getQueuedOperationDetailKeys() as $detailKey) {
            if (!\array_key_exists($detailKey, $details) && \array_key_exists($detailKey, $content)) {
                $details[$detailKey] = $content[$detailKey];
            }
        }
        $pageType = \trim((string)($details['page_type'] ?? $content['page_type'] ?? ''));

        return [
            'operation' => $operation,
            'page_type' => $pageType,
            'queue_id' => $queueId,
            'details' => $details,
            'scope_patch' => \is_array($content['scope_patch'] ?? null) ? $content['scope_patch'] : [],
        ];
    }

    /**
     * @return array{operation?: string, page_type?: string, queue_id?: int, details?: array<string, mixed>, scope_patch?: array<string, mixed>}
     */
    private function normalizeRetryAiOperationState(array $state, string $requestedOperation): array
    {
        $details = \is_array($state['details'] ?? null) ? $state['details'] : [];
        $operation = \strtolower(\trim((string)($state['operation'] ?? $details['operation'] ?? '')));
        if ($operation === '' || !$this->canRetryAiOperationForRequested($requestedOperation, $operation)) {
            return [];
        }
        $status = \strtolower(\trim((string)($state['status'] ?? '')));
        if ($status !== '' && !\in_array($status, ['error', 'failed', 'fail', 'stop', 'stopped', 'cancelled', 'canceled'], true)) {
            return [];
        }
        foreach ($this->getQueuedOperationDetailKeys() as $detailKey) {
            if (!\array_key_exists($detailKey, $details) && \array_key_exists($detailKey, $state)) {
                $details[$detailKey] = $state[$detailKey];
            }
        }

        return [
            'operation' => $operation,
            'page_type' => \trim((string)($details['page_type'] ?? $state['page_type'] ?? '')),
            'queue_id' => (int)($state['queue_id'] ?? 0),
            'details' => $details,
            'scope_patch' => \is_array($state['scope_patch'] ?? null) ? $state['scope_patch'] : [],
        ];
    }

    private function canRetryAiOperationForRequested(string $requestedOperation, string $actualOperation): bool
    {
        $requestedOperation = \strtolower(\trim($requestedOperation));
        $actualOperation = \strtolower(\trim($actualOperation));
        if ($actualOperation === '' || !$this->isRetryableAiOperationName($actualOperation)) {
            return false;
        }
        if ($requestedOperation === '' || $requestedOperation === $actualOperation) {
            return true;
        }
        if ($requestedOperation === 'build') {
            return \in_array($actualOperation, ['build', 'block_regenerate', 'block_partial_patch', 'regenerate_page'], true);
        }

        return false;
    }

    private function isRetryableAiOperationName(string $operation): bool
    {
        return \in_array(\strtolower(\trim($operation)), ['plan', 'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page'], true);
    }

    private function resolveAiSiteOperationFromQueueJobType(string $jobType): string
    {
        $jobType = \strtolower(\trim($jobType));
        return match ($jobType) {
            'plan' => 'plan',
            'virtual_theme.build', 'virtual_theme.tree.build', 'build' => 'build',
            'virtual_theme.block.regenerate' => 'block_regenerate',
            'virtual_theme.block.partial_patch' => 'block_partial_patch',
            'virtual_theme.page.regenerate' => 'regenerate_page',
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    private function resolveRequestedBlockComponentCodes(string $primaryCode): array
    {
        $componentCodes = [];
        $primaryCode = \trim($primaryCode);
        if ($primaryCode !== '') {
            $componentCodes[] = $primaryCode;
        }
        foreach (['component_codes', 'block_keys', 'section_codes', 'task_keys'] as $requestKey) {
            foreach ($this->normalizeStringList($this->getRequestBodyValue($requestKey, [])) as $candidate) {
                if (!\in_array($candidate, $componentCodes, true)) {
                    $componentCodes[] = $candidate;
                }
            }
        }

        return $componentCodes;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string>|array<int, string> $pageTypes
     * @param list<string>|array<int, string> $componentCodes
     *
     * @return array{scope_patch: array<string, mixed>, details: array<string, mixed>}
     */
    private function buildBlockRegenerateQueueContext(
        array $scope,
        array $pageTypes,
        string $pageType,
        array $componentCodes,
        string $instruction,
        string $action,
        string $componentLabel = ''
    ): array {
        $componentCodes = $this->normalizeStringList($componentCodes);
        $pageType = \trim($pageType);
        $scopePatch = [];
        $targets = [];
        $targetScopes = [];
        $sharedRegions = [];
        $componentLabels = [];
        $virtualPages = null;
        $sharedRefinements = \is_array($scope['shared_component_refinements'] ?? null)
            ? $scope['shared_component_refinements']
            : [];

        foreach ($componentCodes as $index => $componentCode) {
            $sharedRegion = $this->resolveSharedComponentRegionForComponentCode($pageType, $componentCode);
            $targetScope = $sharedRegion !== ''
                ? ('plan_json.shared_components.' . $sharedRegion)
                : ('plan_json.pages.' . $pageType . '.' . $componentCode);
            $label = $index === 0 && \trim($componentLabel) !== '' ? \trim($componentLabel) : $componentCode;

            if ($instruction !== '') {
                if ($sharedRegion !== '') {
                    $sharedRefinements[$sharedRegion] = $instruction;
                } else {
                    if ($virtualPages === null) {
                        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
                    }
                    $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
                    $sectionRefinements = \is_array($virtualPage['section_refinements'] ?? null) ? $virtualPage['section_refinements'] : [];
                    $sectionRefinements[$componentCode] = $instruction;
                    $virtualPage['section_refinements'] = $sectionRefinements;
                    $virtualPages[$pageType] = $virtualPage;
                }
            }

            $targetScopes[] = $targetScope;
            $sharedRegions[] = $sharedRegion;
            $componentLabels[] = $label;
            $targets[] = [
                'page_type' => $pageType,
                'page_key' => $pageType,
                'component_code' => $componentCode,
                'block_key' => $componentCode,
                'section_code' => $componentCode,
                'task_key' => $componentCode,
                'component_label' => $label,
                'shared_region' => $sharedRegion,
                'target_scope' => $targetScope,
            ];
        }

        if ($instruction !== '') {
            if ($sharedRefinements !== (\is_array($scope['shared_component_refinements'] ?? null) ? $scope['shared_component_refinements'] : [])) {
                $scopePatch['shared_component_refinements'] = $sharedRefinements;
            }
            if (\is_array($virtualPages)) {
                $scopePatch['plan_json'] = $this->withPlanJsonPages($scope, $virtualPages)['plan_json'];
            }
        }

        $firstComponent = (string)($componentCodes[0] ?? '');
        $firstTarget = \is_array($targets[0] ?? null) ? $targets[0] : [];

        return [
            'scope_patch' => $scopePatch,
            'details' => [
                'stage_scope' => 'build',
                'action' => $action,
                'page_type' => $pageType,
                'page_types' => $pageType !== '' ? [$pageType] : [],
                'page_key' => $pageType,
                'page_keys' => $pageType !== '' ? [$pageType] : [],
                'component_code' => $firstComponent,
                'component_codes' => $componentCodes,
                'component_label' => (string)($firstTarget['component_label'] ?? $firstComponent),
                'component_labels' => $componentLabels,
                'instruction' => $instruction,
                'shared_region' => (string)($firstTarget['shared_region'] ?? ''),
                'shared_regions' => $sharedRegions,
                'target_scope' => (string)($firstTarget['target_scope'] ?? ''),
                'target_scopes' => $targetScopes,
                'block_key' => $firstComponent,
                'block_keys' => $componentCodes,
                'section_code' => $firstComponent,
                'section_codes' => $componentCodes,
                'task_key' => $firstComponent,
                'task_keys' => $componentCodes,
                'selected_blocks' => $componentCodes,
                'selected_tasks' => $componentCodes,
                'targets' => $targets,
            ],
        ];
    }

    private function handleBlockRegenerateSse(bool $refine): void
    {
        @\ignore_user_abort(true);
        $this->releasePhpSessionLockForSse();

        $sse = new SseWriter();
        // Block refine/regenerate is a one-shot generation request. Native EventSource
        // reconnects would re-enter the controller and generate the same block again.
        $sse->setRetryInterval(86400000);
        $sse->start();

        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $componentCode = \trim((string)$this->getRequestBodyValue('component_code', ''));
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        $operationLabel = $refine ? 'block_refine' : 'block_regenerate';

        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $componentCode === '' || ($refine && $instruction === '')) {
            $this->sendSseContractError(
                $sse,
                'INVALID_PARAMS',
                $refine ? self::PARAMS_REFINE_COMPONENT : self::PARAMS_REGENERATE
            );
            $sse->sendEvent('done', [
                'success' => false,
                'operation' => $operationLabel,
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'realtime' => true,
            ]);
            $sse->close();
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->sendSseContractError($sse, 'SESSION_NOT_FOUND', 'Session not found or inaccessible', self::PARAMS_PUBLIC_ID, 404);
            $sse->sendEvent('done', [
                'success' => false,
                'operation' => $operationLabel,
                'message' => 'Session not found or inaccessible',
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'realtime' => true,
            ]);
            $sse->close();
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if (!\in_array($pageType, $pageTypes, true)) {
            $this->sendSseContractError($sse, 'INVALID_PAGE_TYPE', 'Page type is not in the current workspace', self::PARAMS_REGENERATE, 400);
            $sse->sendEvent('done', [
                'success' => false,
                'operation' => $operationLabel,
                'message' => 'Page type is not in the current workspace',
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'realtime' => true,
            ]);
            $sse->close();
            return;
        }

        $componentCodes = $this->resolveRequestedBlockComponentCodes($componentCode);
        if ($componentCodes === []) {
            $componentCodes = [$componentCode];
        }
        $stageCode = AiSiteAgentSession::STAGE_VISUAL_EDIT;
        $instructionText = $refine ? $instruction : '';

        $this->registerAiChunkForwarder($sse, $session, $adminId, $stageCode, $operationLabel);
        RequestContext::set(AiSitePageComponentGenerationService::REQUEST_KEY_FAST_BLOCK_ARTIFACT, true);
        try {
            $lastResult = [];
            foreach ($componentCodes as $idx => $candidateCode) {
                $candidateCode = \trim((string)$candidateCode);
                if ($candidateCode === '') {
                    continue;
                }
                if ($idx > 0) {
                    $sse->sendEvent('info', [
                        'operation' => $operationLabel,
                        'component_code' => $candidateCode,
                        'message' => 'Processing next block: ' . $candidateCode,
                    ]);
                }
                $lastResult = $this->runRegenerateBlockOperation(
                    $sse,
                    $session,
                    $adminId,
                    $pageType,
                    $candidateCode,
                    $instructionText
                );
                $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            }
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = \is_array($lastResult['state'] ?? null)
                ? $lastResult['state']
                : $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true),
                    [$pageType]
                );
            $sse->sendEvent('done', [
                'success' => true,
                'operation' => $operationLabel,
                'message' => (string)($lastResult['message'] ?? ($refine ? __('Page refined.') : __('Page regenerated.'))),
                'page_type' => (string)($lastResult['page_type'] ?? $pageType),
                'component_code' => $componentCode,
                'section_code' => (string)($lastResult['section_code'] ?? ''),
                'shared_region' => (string)($lastResult['shared_region'] ?? ''),
                'state' => $state,
                'realtime' => true,
            ]);
            $sse->close();
        } catch (\Throwable $throwable) {
            $sse->sendError($throwable->getMessage(), $this->inferThrowableHttpCode($throwable));
            $sse->sendEvent('done', [
                'success' => false,
                'operation' => $operationLabel,
                'message' => $throwable->getMessage(),
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'realtime' => true,
            ]);
            $sse->close();
        } finally {
            RequestContext::remove(AiSitePageComponentGenerationService::REQUEST_KEY_FAST_BLOCK_ARTIFACT);
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

        $ordinalTask = $this->resolveSectionTaskKeyByPageBlockOrdinal($scope, $pageType, $componentCode);
        if ($ordinalTask !== []) {
            return $ordinalTask;
        }

        $requestAliases = $this->buildBlockIdentifierAliasMap([$componentCode]);

        foreach ($this->planJsonTaskService->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $definition = $this->planJsonTaskService->getTaskDefinition($scope, $taskKey);
            if (!\is_array($definition)) {
                continue;
            }
            $sectionCode = \trim((string)($definition['section_code'] ?? ''));
            if ($sectionCode === '') {
                continue;
            }
            $definitionAliases = $this->planJsonTaskDefinitionIdentifierAliasMap($definition, $taskKey);
            if (\array_intersect_key($requestAliases, $definitionAliases) !== []) {
                return ['task_key' => $taskKey, 'section_code' => $sectionCode, 'shared_region' => ''];
            }
        }

            throw new \RuntimeException((string)__('Operation failed.'));
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, true>
     */
    /**
     * Plan preview block buttons use page-local ordinal ids such as block_3.
     * Map that contract back to the confirmed build task order for the page.
     *
     * @param array<string, mixed> $scope
     * @return array{task_key:string,section_code:string,shared_region:string}|array{}
     */
    private function resolveSectionTaskKeyByPageBlockOrdinal(array $scope, string $pageType, string $componentCode): array
    {
        $componentCode = \trim($componentCode);
        if ($componentCode === '') {
            return [];
        }

        $candidates = [$componentCode];
        foreach ([':', '.', '/', '\\'] as $separator) {
            if (\str_contains($componentCode, $separator)) {
                $parts = \array_values(\array_filter(\explode($separator, $componentCode), static fn(string $part): bool => \trim($part) !== ''));
                if ($parts !== []) {
                    $candidates[] = (string)\end($parts);
                }
            }
        }

        $ordinal = 0;
        foreach ($candidates as $candidate) {
            if (\preg_match('/^block[_-](\d+)$/i', \trim((string)$candidate), $matches) === 1) {
                $ordinal = (int)($matches[1] ?? 0);
                break;
            }
        }
        if ($ordinal <= 0) {
            return [];
        }

        $taskKeys = \array_values($this->planJsonTaskService->listTaskKeysByPageType($scope, $pageType));
        $taskKey = \trim((string)($taskKeys[$ordinal - 1] ?? ''));
        if ($taskKey === '') {
            return [];
        }

        $definition = $this->planJsonTaskService->getTaskDefinition($scope, $taskKey);
        if (!\is_array($definition)) {
            return [];
        }
        $sectionCode = \trim((string)($definition['section_code'] ?? ''));
        if ($sectionCode === '') {
            return [];
        }

        return ['task_key' => $taskKey, 'section_code' => $sectionCode, 'shared_region' => ''];
    }

    private function planJsonTaskDefinitionIdentifierAliasMap(array $definition, string $taskKey): array
    {
        $runtimeContext = \is_array($definition['runtime_context'] ?? null) ? $definition['runtime_context'] : [];
        $candidates = [
            $taskKey,
            $definition['task_key'] ?? null,
            $definition['section_code'] ?? null,
            $definition['section_key'] ?? null,
            $definition['block_key'] ?? null,
            $definition['source_block_key'] ?? null,
        ];

        foreach ([
            $definition['prompt_variables'] ?? null,
            $definition['result_ref'] ?? null,
            $definition['plan_context'] ?? null,
            $definition['block_task'] ?? null,
            $definition['implementation_contract'] ?? null,
            $runtimeContext['context_refs'] ?? null,
        ] as $bucket) {
            if (!\is_array($bucket)) {
                continue;
            }
            foreach ([
                'task_id',
                'task_key',
                'section_code',
                'section_key',
                'block_key',
                'source_block_key',
                'block_id',
                'source_block_id',
                'block_context_ref',
            ] as $key) {
                $candidates[] = $bucket[$key] ?? null;
            }
        }

        return $this->buildBlockIdentifierAliasMap($candidates);
    }

    /**
     * @param array<int, mixed> $values
     * @return array<string, true>
     */
    private function buildBlockIdentifierAliasMap(array $values): array
    {
        $aliases = [];
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $raw = \trim((string)$value);
            if ($raw === '') {
                continue;
            }
            $this->appendBlockIdentifierAliases($aliases, $raw);
        }

        return $aliases;
    }

    /**
     * @param array<string, true> $aliases
     */
    private function appendBlockIdentifierAliases(array &$aliases, string $value): void
    {
        $value = \trim($value);
        if ($value === '') {
            return;
        }

        $variants = [$value];
        if (\str_starts_with($value, 'content/')) {
            $variants[] = \substr($value, \strlen('content/'));
        }
        foreach ([':', '.', '/', '\\'] as $separator) {
            if (\str_contains($value, $separator)) {
                $parts = \array_values(\array_filter(\explode($separator, $value), static fn(string $part): bool => \trim($part) !== ''));
                if ($parts !== []) {
                    $variants[] = (string)\end($parts);
                }
            }
        }

        foreach ($variants as $variant) {
            $variant = \trim((string)$variant);
            if ($variant === '') {
                continue;
            }
            $lower = \strtolower($variant);
            foreach ([$variant, $lower] as $candidate) {
                $candidate = \trim($candidate);
                if ($candidate !== '') {
                    $aliases[$candidate] = true;
                }
            }
            foreach ([
                \preg_replace('/[^a-z0-9]+/i', '-', $lower),
                \preg_replace('/[^a-z0-9]+/i', '_', $lower),
                \str_replace(['/', '\\', '.'], '-', $lower),
                \str_replace(['/', '\\', '.', '-'], '_', $lower),
            ] as $candidate) {
                if (!\is_string($candidate)) {
                    continue;
                }
                $candidate = \trim($candidate, "-_ \t\n\r\0\x0B");
                if ($candidate !== '') {
                    $aliases[$candidate] = true;
                }
            }
        }
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

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function ensureVirtualThemeWorkspaceForScopedBuildOperation(AiSiteAgentSession $session, int $adminId, array $scope): array
    {
        $websiteProfile = \is_array($scope['website_profile'] ?? null)
            ? $scope['website_profile']
            : $this->profileGenerationService->generate($scope);
        $scope['website_profile'] = $websiteProfile;

        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $websiteProfile);
        $websiteId = (int)($draftWebsite['website_id'] ?? 0);
        if ($websiteId > 0) {
            $scope['draft_website_id'] = $websiteId;
            $scope['website_id'] = $websiteId;
            $scope['selected_website_id'] = $websiteId;
        }

        $themeShell = $this->virtualThemeService->ensureThemeShell($scope, $websiteProfile, $session->getId());
        $virtualThemeId = (int)($themeShell['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0) {
            throw new \RuntimeException((string)__('Unable to prepare virtual theme workspace for scoped build operation.'));
        }

        $scope['virtual_theme_id'] = $virtualThemeId;
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, $virtualThemeId);

        return $scope;
    }

    private function runRegenerateBlockOperation(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $pageType,
        string $componentCode,
        string $instruction = ''
    ): array {
        $no_Ai_Generate = false;
        $opStartedAt = \microtime(true);
        $this->assertActiveStreamLeaseAlive($session, $adminId);
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $scope = $this->planJsonTaskService->ensureTaskScope($scope, \is_array($scope['website_profile']) ? $scope['website_profile'] : [], (string)($scope['workspace_track'] ?? ''));

        $resolvedTask = $this->resolveSectionTaskKeyForComponent($scope, $pageType, $componentCode);
        $taskKey = (string)($resolvedTask['task_key'] ?? '');
        $sectionCode = (string)($resolvedTask['section_code'] ?? '');
        $sharedRegion = \trim((string)($resolvedTask['shared_region'] ?? ''));
        if ($taskKey === '' || ($sharedRegion === '' && $sectionCode === '')) {
            throw new \RuntimeException((string)__('Unable to resolve block task for the current component'));
        }

        $scope = $this->planJsonTaskService->resetTaskForRetry($scope, $taskKey);
        $scope = $this->planJsonTaskService->markTaskRunning($scope, $taskKey);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if ($workspaceTrack !== AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks) {
            $scope = $this->ensureVirtualThemeWorkspaceForScopedBuildOperation($session, $adminId, $scope);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        }
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
                $scope = $this->withPlanJsonPages($scope, $virtualPages);
            }
        }

        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'block_regenerate',
            __('Regenerating page block.'),
            35,
            $pageType
        );

        if ($no_Ai_Generate) {
            // Comment removed.
            $affectedPageTypes = $sharedRegion !== ''
                ? $this->scopeCompatibilityService->resolveScopedPageTypes($scope)
                : [$pageType];
            if ($affectedPageTypes === []) {
                $affectedPageTypes = [$pageType];
            }
            $scope['preview_page_type'] = $pageType;
            $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, [
                'page_type' => $pageType,
                'section_code' => $sectionCode,
                'shared_region' => $sharedRegion,
                'demo' => 1,
            ]);
            $this->emitBlockPreviewReadyEvent(
                $sse,
                $instruction !== '' ? 'block_refine' : 'block_regenerate',
                (string)__('Block regenerated in demo mode. Refreshing preview.'),
                $pageType,
                $componentCode,
                $sectionCode,
                $sharedRegion,
                $affectedPageTypes,
                $scope
            );

            $scope['plan_json_task_summary'] = $this->planJsonTaskService->summarize($scope);
            if (\is_array($scope['build_summary'] ?? null)) {
                unset($scope['build_summary']['task_summary']);
            }
            $doneMessage = $instruction !== ''
                ? (string)__('Block refinement completed. Refreshing preview.')
                : (string)__('Block regeneration completed. Refreshing preview.');
            $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
            if (\in_array($activeOperationName, ['block_refine', 'block_regenerate'], true)) {
                $scope = $this->writeActiveOperationStateToScope($scope, \array_replace($activeOperation, [
                    'operation' => 'block_regenerate',
                    'queue_status' => 'done',
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'instruction' => $instruction,
                    'message' => $doneMessage,
                    'updated_at' => \date('Y-m-d H:i:s'),
                    'demo' => 1,
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
                (string)__('Operation completed.'),
                [
                    'operation' => $instruction !== '' ? 'block_refine' : 'block_regenerate',
                    'page_type' => $pageType,
                    'details' => ['task_key' => $taskKey, 'section_code' => $sectionCode, 'shared_region' => $sharedRegion, 'demo' => 1],
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

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);

        if ($sharedRegion !== '') {
            $generateStartedAt = \microtime(true);
            $sharedComponent = $pageComponentGenerationService->generateSharedComponent(
                $sharedRegion,
                $scope['website_profile'],
                $scope,
                $instruction,
                true
            );
            $this->logHotPathStage('block_regenerate.generate_shared_component', $generateStartedAt, [
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'shared_region' => $sharedRegion,
                'operation' => $instruction !== '' ? 'block_refine' : 'block_regenerate',
            ]);
            $scope['plan_json'] = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
            $scope['plan_json']['shared_components'] = \is_array($scope['plan_json']['shared_components'] ?? null)
                ? $scope['plan_json']['shared_components']
                : [];
            $scope['plan_json']['shared_components'][$sharedRegion] = $sharedComponent;
            unset($scope['shared_components']);
            if (\is_array($scope['shared_component_refinements'] ?? null)) {
                unset($scope['shared_component_refinements'][$sharedRegion]);
            }
            $dummySection = ['block_id' => ''];
            $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null)
                ? $scope['plan_json']['shared_components']
                : [];

            if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks) {
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
                        $sharedComponents,
                        $dummySection,
                        $scope['website_profile'],
                        $scope
                    );
                    $row['last_generated_at'] = $now;
                    $virtualPages[$pt] = $row;
                }
                $scope = $this->withPlanJsonPages($scope, $virtualPages);
                $scope['preview_page_type'] = $pageType;
                $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, [
                    'page_type' => $pageType,
                    'section_code' => $sectionCode,
                    'shared_region' => $sharedRegion,
                ]);
                $this->logHotPathStage('block_regenerate.preview_ready_html_blocks', $opStartedAt, [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'shared_region' => $sharedRegion,
                    'affected_page_types' => $affectedPageTypes,
                ]);
                $this->emitBlockPreviewReadyEvent(
                    $sse,
                    $instruction !== '' ? 'block_refine' : 'block_regenerate',
                    (string)__('Block preview is ready.'),
                    $pageType,
                    $componentCode,
                    $sectionCode,
                    $sharedRegion,
                    $affectedPageTypes,
                    $scope
                );
                $materializeStartedAt = \microtime(true);
                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
                    \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                    $scope['website_profile'],
                    $pageTypesAll,
                    [],
                    $virtualPages
                );
                $this->logHotPathStage('block_regenerate.materialize_html_blocks', $materializeStartedAt, [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'shared_region' => $sharedRegion,
                    'affected_page_types' => $affectedPageTypes,
                ]);
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            } else {
                $pageTypesAll = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
                $affectedPageTypes = $pageTypesAll;
                $layoutStartedAt = \microtime(true);
                $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts([], $pageTypesAll);
                $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
                $this->virtualThemeService->saveGeneratedSharedComponent($virtualThemeId, $sharedComponent);
                $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypesAll, $pageTypeLayouts, $sharedComponent);
                $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypesAll, $pageTypeLayouts);
                $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypesAll, \array_replace($scope, [
                    'plan_json' => \array_replace(\is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [], ['pages' => $this->planJsonPages($scope)]),
                ]), false);
                $now = \date('Y-m-d H:i:s');
                foreach ($pageTypesAll as $pt) {
                    if (!isset($virtualPages[$pt]) || !\is_array($virtualPages[$pt])) {
                        continue;
                    }
                    $virtualPages[$pt]['blocks'] = $this->composeHtmlBlocksForPage(
                        $pt,
                        $this->resolveExistingAiHtmlBlocksForPage($pt, $scope, $virtualPages),
                        $sharedComponents,
                        $dummySection,
                        $scope['website_profile'],
                        $scope
                    );
                    $virtualPages[$pt]['last_generated_at'] = $now;
                }
                $scope = $this->withPlanJsonPages($scope, $virtualPages);
                $scope['preview_page_type'] = $pageType;
                $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, [
                    'page_type' => $pageType,
                    'section_code' => $sectionCode,
                    'shared_region' => $sharedRegion,
                ]);
                $this->logHotPathStage('block_regenerate.preview_ready_virtual_theme_shared', $layoutStartedAt, [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'shared_region' => $sharedRegion,
                    'affected_page_types' => $affectedPageTypes,
                ]);
                $this->emitBlockPreviewReadyEvent(
                    $sse,
                    $instruction !== '' ? 'block_refine' : 'block_regenerate',
                    (string)__('Shared block preview is ready.'),
                    $pageType,
                    $componentCode,
                    $sectionCode,
                    $sharedRegion,
                    $affectedPageTypes,
                    $scope
                );
                $this->logHotPathStage('block_regenerate.virtual_theme_shared_ready', $layoutStartedAt, [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'shared_region' => $sharedRegion,
                    'affected_page_types' => $affectedPageTypes,
                ]);
            }
        } else {
            $generateStartedAt = \microtime(true);
            $generationScope = $scope;
            $generationScope['_inline_image_asset_generator'] = $this->buildInlineImageAssetGenerator(
                $session,
                $adminId,
                $scope,
                $pageType,
                $sectionCode,
                $componentCode
            );
            $sectionComponent = $pageComponentGenerationService->generatePageSection($pageType, $sectionCode, $scope['website_profile'], $generationScope);
            $this->logHotPathStage('block_regenerate.generate_page_section', $generateStartedAt, [
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'section_code' => $sectionCode,
                'operation' => $instruction !== '' ? 'block_refine' : 'block_regenerate',
            ]);

            if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks) {
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
                    $this->resolveExistingAiHtmlBlocksForPage($pageType, $scope, $virtualPages),
                    \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [],
                    $this->htmlBlocksBuildService->buildGeneratedSectionBlock($sectionComponent),
                    $scope['website_profile'],
                    $scope
                );
                $row['last_generated_at'] = \date('Y-m-d H:i:s');
                $virtualPages[$pageType] = $row;
                $scope = $this->withPlanJsonPages($scope, $virtualPages);
                $scope['preview_page_type'] = $pageType;
                $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);
                $this->logHotPathStage('block_regenerate.preview_ready_html_section', $generateStartedAt, [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'section_code' => $sectionCode,
                ]);
                $this->emitBlockPreviewReadyEvent(
                    $sse,
                    $instruction !== '' ? 'block_refine' : 'block_regenerate',
                    (string)__('Block preview is ready.'),
                    $pageType,
                    $componentCode,
                    $sectionCode,
                    '',
                    [$pageType],
                    $scope
                );
                $materializeStartedAt = \microtime(true);
                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
                    \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId()),
                    $scope['website_profile'],
                    [$pageType],
                    [],
                    [$pageType => $row]
                );
                $this->logHotPathStage('block_regenerate.materialize_html_section', $materializeStartedAt, [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'section_code' => $sectionCode,
                ]);
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
            } else {
                $layoutStartedAt = \microtime(true);
                $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
                $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts([], $pageTypes);
                $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($pageTypeLayouts[$pageType] ?? [], $pageType);
                $layout = $this->virtualThemeService->mergePersistedContentIntoGeneratedLayout((int)$scope['virtual_theme_id'], $pageType, $layout);
                $this->virtualThemeService->saveGeneratedContentComponent((int)($scope['virtual_theme_id'] ?? 0), $pageType, $sectionComponent);
                $layout = $this->virtualThemeService->mergeGeneratedContentIntoLayout($layout, $sectionComponent);
                $layout = $this->sortVirtualThemeLayoutContentByPlanJsonTasks($layout, $scope, $pageType);
                $this->virtualThemeService->saveGeneratedPageLayout((int)($scope['virtual_theme_id'] ?? 0), $pageType, $layout);
                $pageTypeLayouts[$pageType] = $layout;

                $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, \array_replace($scope, [
                    'plan_json' => \array_replace(\is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [], ['pages' => $this->planJsonPages($scope)]),
                ]));
                if (isset($virtualPages[$pageType]) && \is_array($virtualPages[$pageType])) {
                    $virtualPages[$pageType]['blocks'] = $this->composeHtmlBlocksForPage(
                        $pageType,
                        $this->resolveExistingAiHtmlBlocksForPage($pageType, $scope, $virtualPages),
                        \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [],
                        $this->htmlBlocksBuildService->buildGeneratedSectionBlock($sectionComponent),
                        $scope['website_profile'],
                        $scope
                    );
                }
                $scope = $this->withPlanJsonPages($scope, $virtualPages);
                $scope['preview_page_type'] = $pageType;
                $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);
                $this->logHotPathStage('block_regenerate.preview_ready_virtual_theme_section', $layoutStartedAt, [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'section_code' => $sectionCode,
                ]);
                $this->emitBlockPreviewReadyEvent(
                    $sse,
                    $instruction !== '' ? 'block_refine' : 'block_regenerate',
                    (string)__('Section preview is ready.'),
                    $pageType,
                    $componentCode,
                    $sectionCode,
                    '',
                    [$pageType],
                    $scope
                );
                $this->logHotPathStage('block_regenerate.virtual_theme_section_ready', $layoutStartedAt, [
                    'page_type' => $pageType,
                    'component_code' => $componentCode,
                    'section_code' => $sectionCode,
                ]);
            }
        }

        $scope['plan_json_task_summary'] = $this->planJsonTaskService->summarize($scope);
        if (\is_array($scope['build_summary'] ?? null)) {
            unset($scope['build_summary']['task_summary']);
        }
        $doneMessage = $instruction !== '' ? (string)__('Block refinement completed.') : (string)__('Block regeneration completed.');
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        if (\in_array($activeOperationName, ['block_refine', 'block_regenerate'], true)) {
            $scope = $this->writeActiveOperationStateToScope($scope, \array_replace($activeOperation, [
                'operation' => 'block_regenerate',
                'queue_status' => 'done',
                'page_type' => $pageType,
                'component_code' => $componentCode,
                'instruction' => $instruction,
                'message' => $doneMessage,
                'updated_at' => \date('Y-m-d H:i:s'),
            ]));
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
        }
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        SchedulerSystem::yield();
        $this->logHotPathStage('block_regenerate.persist_and_reload_scope', $opStartedAt, [
            'page_type' => $pageType,
            'component_code' => $componentCode,
            'section_code' => $sectionCode,
            'shared_region' => $sharedRegion,
            'operation' => $instruction !== '' ? 'block_refine' : 'block_regenerate',
        ]);
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true),
            $affectedPageTypes
        );

        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'task_completed',
            (string)__('Operation completed.'),
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

    /**
     * Comment removed.
     * Comment removed.
     *
     * @param list<string> $affectedPageTypes
     * @param array<string, mixed> $scope
     */
    private function emitBlockPreviewReadyEvent(
        SseWriter $sse,
        string $operation,
        string $message,
        string $pageType,
        string $componentCode,
        string $sectionCode,
        string $sharedRegion,
        array $affectedPageTypes,
        array $scope
    ): void {
        $minimalState = [
            'plan_json_pages' => $this->planJsonPages($scope),
            'preview_page_type' => (string)($scope['preview_page_type'] ?? $pageType),
            'plan_json_task_summary' => $this->planJsonTaskService->summarize($scope),
            'build_summary' => \array_diff_key(
                \is_array($scope['build_summary'] ?? null) ? $scope['build_summary'] : [],
                ['task_summary' => true]
            ),
            'workspace_status' => (string)($scope['workspace_status'] ?? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH),
        ];
        $sse->sendEvent('page_generated', [
            'operation' => $operation,
            'message' => $message,
            'page_type' => $pageType,
            'component_code' => $componentCode,
            'section_code' => $sectionCode,
            'shared_region' => $sharedRegion,
            'affected_page_types' => $affectedPageTypes,
            'progress_percent' => 100,
            'state' => $minimalState,
            'realtime' => true,
        ]);
        $sse->sendEvent('progress', [
            'operation' => $operation,
            'message' => (string)__('Block generation completed.'),
            'page_type' => $pageType,
            'component_code' => $componentCode,
            'section_code' => $sectionCode,
            'shared_region' => $sharedRegion,
            'progress_percent' => 100,
            'state' => $minimalState,
            'realtime' => true,
        ]);
    }

    private function handleStartPublish(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Missing public_id.'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('Session not found.'), self::PARAMS_PUBLIC_ID);
        }
        $state = $this->buildWorkspaceFastViewState($session, $adminId);
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForBuildOperation($session)
        );
        $fastScope = \is_array($state['scope'] ?? null) ? $state['scope'] : [];
        foreach ([
            'active_operation',
            'active_operations',
            'build_queue_info',
            'latest_build_failed',
            'publish_blocked_by_latest_ai_failure',
            'workspace_status',
            'can_publish',
            'visual_edit_url',
            'visual_preview_url',
            'pre_publish_visual_urls',
        ] as $runtimeKey) {
            if (\array_key_exists($runtimeKey, $fastScope)) {
                $scope[$runtimeKey] = $fastScope[$runtimeKey];
            }
        }
        $completionGate = $this->planJsonTaskService->inspectBuildCompletionGate($scope);
        $completionGatePassed = !empty($completionGate['passed']);
        $publishBlock = $completionGatePassed ? ['blocked' => false] : $this->resolveLatestPublishBlockingAiBuildFailure(
            $scope,
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            \is_array($scope['build_queue_info'] ?? null) ? $scope['build_queue_info'] : null
        );
        if (!empty($publishBlock['blocked'])) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'LATEST_AI_BUILD_FAILED',
                'message' => $this->formatPublishBlockedByAiFailureMessage($publishBlock),
                'data' => [
                    'code' => 'LATEST_AI_BUILD_FAILED',
                    'publish_blocked_by_latest_ai_failure' => true,
                    'latest_build_failure' => $publishBlock,
                ],
            ]);
        }
        if (!$completionGatePassed) {
            $scope['can_publish'] = 0;
            $state['can_publish'] = false;
        }
        $buildAlreadyComplete = $completionGatePassed;
        if (empty($state['can_publish']) && !$buildAlreadyComplete) {
            if ($this->isPlanJsonReadyForBuild($scope)) {
                $detail = $this->planJsonTaskService->formatBuildCompletionGateFailureDetail($completionGate);
                return $this->fetchJson([
                    'success' => false,
                    'code' => 'BUILD_COMPLETION_GATE_BLOCKED',
                    'message' => $detail !== '' ? $detail : 'Build is not ready: every planned page block must be generated before publishing.',
                    'data' => [
                        'code' => 'BUILD_COMPLETION_GATE_BLOCKED',
                        'completion_gate' => $completionGate,
                    ],
                ]);
            }
            return $this->fetchJson(['success' => false, 'code' => 'PLAN_JSON_NOT_CONFIRMED', 'message' => __('Please confirm plan_json before checking publish readiness.')]);
        }
        if ($buildAlreadyComplete && empty($state['can_publish'])) {
            $state['can_publish'] = true;
        }
        $stageTwoReadiness = $this->buildStageTwoPublishReadinessReport($scope);
        if (empty($stageTwoReadiness['passed'])) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'PUBLISH_STAGE2_TASK_BLOCK_MISMATCH',
                'message' => __('plan_json build readiness failed.'),
                'data' => \array_replace(['code' => 'PUBLISH_STAGE2_TASK_BLOCK_MISMATCH'], $stageTwoReadiness),
            ]);
        }
        $refreshedScope = $this->refreshScopeQualityContractsForPublishGate($scope);
        if ($refreshedScope !== $scope) {
            $this->sessionService->replaceScope($session->getId(), $adminId, $refreshedScope);
            $state['scope'] = $refreshedScope;
            $scope = $refreshedScope;
        }
        $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);
        $qualityReport = $this->normalizePublishQualityReport(
            $qualityGate->inspectScope($scope),
            $stageTwoReadiness
        );
        if (empty($qualityReport['passed'])) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'PUBLISH_QUALITY_GATE_FAILED',
                'message' => __('Publish quality gate failed.'),
                'data' => \array_replace(['code' => 'PUBLISH_QUALITY_GATE_FAILED'], $qualityReport),
            ]);
        }
        $confirmVisualTheme = \in_array(
            \strtolower(\trim((string)$this->getRequestBodyValue('confirm_visual_theme', '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
        if (!$confirmVisualTheme && (int)($scope['virtual_theme_effect_confirmed'] ?? 0) !== 1) {
            return $this->fetchJson([
                'success' => false,
                'code' => 'VISUAL_THEME_CONFIRM_REQUIRED',
                'message' => __('Please confirm the visual theme before publishing.'),
                'data' => [
                    'code' => 'VISUAL_THEME_CONFIRM_REQUIRED',
                    'required_param' => 'confirm_visual_theme',
                    'publish_quality_gate' => $qualityReport,
                ],
            ]);
        }
        $executionToken = \bin2hex(\random_bytes(16));
        $scope = $this->writeActiveOperationStateToScope($scope, [
            'operation' => 'publish',
            'execution_token' => $executionToken,
            'status' => 'running',
            'queue_status' => 'running',
            'page_type' => '',
            'started_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
            'progress_percent' => 0,
            'message' => (string)__('Operation progress updated.'),
        ]);
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING;
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_PUBLISH);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHING);

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        try {
            $this->runPublishOperation($this->silentSseWriter(), $fresh, $adminId);
        } catch (\Throwable $throwable) {
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                [
                    'operation' => 'publish',
                    'status' => 'error',
                    'queue_status' => 'error',
                    'message' => \trim($throwable->getMessage()) !== '' ? $throwable->getMessage() : (string)__('Operation failed.'),
                ],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED,
                AiSiteAgentSession::PUBLISH_STATUS_FAILED
            );
            return $this->fetchJson([
                'success' => false,
                'code' => 'PUBLISH_FAILED',
                'message' => \trim($throwable->getMessage()) !== '' ? $throwable->getMessage() : (string)__('Operation failed.'),
            ]);
        }

        $published = $this->buildWorkspaceFastViewState($this->sessionService->loadById($session->getId(), $adminId) ?? $fresh, $adminId);
        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('Operation completed.'),
            'operation' => 'publish',
            'execution_token' => $executionToken,
            'data' => $published,
        ]);
    }

    private function handleUpdateBlockConfig(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $pageType = \trim((string)$this->getRequestBodyValue('page_type', ''));
        $blockId = \trim((string)$this->getRequestBodyValue('block_id', ''));
        $componentCode = \trim((string)$this->getRequestBodyValue('component_code', ''));
        $region = \trim((string)$this->getRequestBodyValue('region', ''));
        $componentIndex = (int)$this->getRequestBodyValue('index', 0);
        if ($adminId <= 0 || $publicId === '' || $pageType === '' || $blockId === '') {
            return $this->jsonError('INVALID_PARAMS', 'Invalid block update parameters.', self::PARAMS_UPDATE_BLOCK);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session not found.', self::PARAMS_PUBLIC_ID);
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
            return $this->jsonError('INVALID_PARAMS', 'Page type is not available in this workspace.', self::PARAMS_UPDATE_BLOCK);
        }

        $blockConfig = $this->syncVirtualThemeEditableConfigAliases(
            $blockConfig,
            $scope,
            $pageType,
            $blockId,
            $componentCode,
            $region,
            $componentIndex
        );

        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
        $virtualPage = \is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : [];
        $blocks = $this->planJsonPageBlockList($scope, $pageType);
        $updatedBlock = null;
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];

        foreach ($blocks as $block) {
            if (!\is_array($block) || !$this->blockMatchesComponentIdentity($pageType, $block, $blockId, $componentCode)) {
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
            $virtualThemeId = \max((int)($scope['virtual_theme_id'] ?? 0), (int)$session->getVirtualThemeId());
            if ($virtualThemeId <= 0) {
                return $this->fetchJson(['success' => false, 'message' => 'Virtual theme is missing.']);
            }
            $scope['virtual_theme_id'] = $virtualThemeId;
            $resolvedComponentCode = $componentCode !== '' ? $componentCode : $blockId;
            $updatedBlock = [
                'block_id' => $blockId,
                'component_code' => $resolvedComponentCode,
                '_pb_server_component_code' => $resolvedComponentCode,
                'region' => $region !== '' ? $region : 'content',
                '_pb_server_region' => $region !== '' ? $region : 'content',
                'index' => $componentIndex,
                '_pb_server_index' => $componentIndex,
                'config' => $blockConfig,
                'field_schema' => [],
            ];
        }

        $lastGeneratedAt = \date('Y-m-d H:i:s');
        $virtualPages = $this->replaceCurrentPageBlockInVirtualPages(
            $virtualPages,
            $pageType,
            $blockId,
            $updatedBlock,
            $lastGeneratedAt
        );
        $scope = $this->withPlanJsonPages($scope, $virtualPages);
        $scope = $this->syncUpdatedVirtualBlockToThemeLayout($scope, $pageTypes, $pageType, $updatedBlock);
        $scope['plan_json_task_summary'] = $this->planJsonTaskService->summarize($scope);
        if (\is_array($scope['build_summary'] ?? null)) {
            unset($scope['build_summary']['task_summary']);
        }
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'block_config_updated',
            (string)__('Operation completed.'),
            [
                'page_type' => $pageType,
                'details' => [
                    'block_id' => $blockId,
                    'config_keys' => \array_values(\array_map('strval', \array_keys($blockConfig))),
                ],
            ]
        );

        $clientVirtualPagesByType = [];
        if (\is_array($virtualPages[$pageType] ?? null)) {
            $clientVirtualPagesByType = $this->htmlBlocksBuildService->stripServerOnlyVirtualPages([
                $pageType => $virtualPages[$pageType],
            ]);
        }
        $virtualThemeId = \max((int)($scope['virtual_theme_id'] ?? 0), (int)$session->getVirtualThemeId());

        $response = [
            'success' => true,
            'message' => 'Operation completed.',
            'data' => [
                'public_id' => $session->getPublicId(),
                'stage' => $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'virtual_theme_id' => $virtualThemeId,
                'preview_page_type' => (string)($scope['preview_page_type'] ?? $pageType),
                'plan_json_pages' => $clientVirtualPagesByType,
                'plan_json_task_summary' => \is_array($scope['plan_json_task_summary'] ?? null) ? $scope['plan_json_task_summary'] : [],
            ],
        ];
        $response['block'] = $this->htmlBlocksBuildService->stripServerOnlyBlock($updatedBlock);

        return $this->fetchJson($response);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function syncVirtualThemeEditableConfigAliases(
        array $config,
        array $scope,
        string $pageType,
        string $blockId,
        string $componentCode,
        string $region,
        int $componentIndex
    ): array {
        if ($config === []) {
            return $config;
        }

        $referenceConfig = $this->resolveVirtualThemeEditableReferenceConfig(
            $scope,
            $pageType,
            $blockId,
            $componentCode,
            $region,
            $componentIndex
        );

        foreach ($this->virtualThemeEditableAliasGroups() as $group) {
            $referenceValue = $this->firstScalarAliasConfigValue($referenceConfig, $group, true);
            $referenceString = $referenceValue === null ? null : (string)$referenceValue;
            $fallbackValue = null;
            $hasFallback = false;
            $chosenValue = null;
            $hasChosen = false;

            foreach ($group as $key) {
                if (!\array_key_exists($key, $config)) {
                    continue;
                }
                $value = $config[$key];
                if (!\is_scalar($value) && !$value instanceof \Stringable) {
                    continue;
                }
                if (!$hasFallback) {
                    $fallbackValue = $value;
                    $hasFallback = true;
                }
                if ($referenceString !== null && (string)$value !== $referenceString) {
                    $chosenValue = $value;
                    $hasChosen = true;
                    break;
                }
            }

            if (!$hasChosen && $hasFallback) {
                $chosenValue = $fallbackValue;
                $hasChosen = true;
            }
            if (!$hasChosen) {
                continue;
            }

            foreach ($group as $key) {
                $config[$key] = $chosenValue;
            }
        }

        return $config;
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function resolveVirtualThemeEditableReferenceConfig(
        array $scope,
        string $pageType,
        string $blockId,
        string $componentCode,
        string $region,
        int $componentIndex
    ): array {
        $region = \strtolower(\trim($region));
        if ($region === '') {
            $region = 'content';
        }
        $pages = $this->planJsonPages($scope);
        $targetPageType = \in_array($region, ['header', 'footer'], true) ? Page::TYPE_HOME : $pageType;
        $page = \is_array($pages[$targetPageType] ?? null) ? $pages[$targetPageType] : [];
        foreach (\array_values($this->planJsonDynamicBlocks($page)) as $index => $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ($componentIndex >= 0 && $index !== $componentIndex) {
                continue;
            }
            if ($this->blockMatchesComponentIdentity($pageType, $block, $blockId, $componentCode)) {
                return \is_array($block['config'] ?? null) ? $block['config'] : [];
            }
        }

        return $this->loadVirtualThemeComponentDefaultConfig($scope, $region, $blockId, $componentCode);
    }

    /**
     * @param array<string,mixed> $item
     */
    private function layoutItemMatchesEditableBlock(string $pageType, array $item, string $blockId, string $componentCode): bool
    {
        $itemCode = \trim((string)($item['code'] ?? $item['component'] ?? ''));
        return $this->blockMatchesComponentIdentity($pageType, [
            'block_id' => (string)($item['instance_id'] ?? ''),
            'component_code' => $itemCode,
            '_pb_server_component_code' => $itemCode,
            'code' => $itemCode,
            'component' => (string)($item['component'] ?? ''),
            'instance_id' => (string)($item['instance_id'] ?? ''),
        ], $blockId, $componentCode);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function loadVirtualThemeComponentDefaultConfig(
        array $scope,
        string $region,
        string $blockId,
        string $componentCode
    ): array {
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0) {
            return [];
        }

        $resolvedCode = \trim($componentCode);
        if ($resolvedCode === '') {
            $resolvedCode = \str_replace('-', '/', \trim($blockId));
            if ($region === 'content' && $resolvedCode !== '' && !\str_starts_with($resolvedCode, 'content/')) {
                $resolvedCode = 'content/' . $resolvedCode;
            }
        }
        if ($resolvedCode === '') {
            return [];
        }

        /** @var VirtualThemeComponent $component */
        $component = clone ObjectManager::getInstance(VirtualThemeComponent::class);
        $component->clearData()->clearQuery()
            ->where(VirtualThemeComponent::schema_fields_VIRTUAL_THEME_ID, $virtualThemeId)
            ->where(VirtualThemeComponent::schema_fields_COMPONENT_CODE, $resolvedCode)
            ->where(VirtualThemeComponent::schema_fields_AREA, VirtualThemeComponent::AREA_FRONTEND)
            ->where(VirtualThemeComponent::schema_fields_IS_ACTIVE, 1)
            ->order(VirtualThemeComponent::schema_fields_ID, 'DESC')
            ->find()
            ->fetch();

        return (int)$component->getId() > 0 ? $component->getDefaultConfig() : [];
    }

    /**
     * @return list<list<string>>
     */
    private function virtualThemeEditableAliasGroups(): array
    {
        return [
            ['content.title', 'content.heading', 'content.headline', 'title', 'heading', 'headline'],
            ['content.subtitle', 'content.subheading', 'subtitle', 'subheading'],
            ['content.description', 'content.body', 'description', 'body', 'text'],
            ['cta.text', 'content.cta_text', 'cta_text', 'button_text', 'button.label'],
            ['cta.url', 'content.cta_url', 'cta_url', 'button_url', 'button.url', 'button.href'],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param list<string> $keys
     */
    private function firstScalarAliasConfigValue(array $config, array $keys, bool $preferNonEmpty = false): mixed
    {
        $first = null;
        $hasFirst = false;
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $config)) {
                continue;
            }
            $value = $config[$key];
            if (!\is_scalar($value) && !$value instanceof \Stringable) {
                continue;
            }
            if (!$hasFirst) {
                $first = $value;
                $hasFirst = true;
            }
            if (!$preferNonEmpty || \trim((string)$value) !== '') {
                return $value;
            }
        }

        return $hasFirst ? $first : null;
    }

    /**
     * @param array<string,mixed> $block
     */
    private function blockMatchesComponentIdentity(string $pageType, array $block, string $blockId, string $componentCode = ''): bool
    {
        $targetValues = [
            $blockId,
            $componentCode,
            $this->normalizeGeneratedComponentCodeToBlockId($componentCode),
        ];
        $targets = $this->buildComponentIdentityTokens($pageType, $targetValues);
        if ($targets === []) {
            return false;
        }

        $candidateValues = [
            (string)($block['block_id'] ?? ''),
            (string)($block['component_code'] ?? ''),
            (string)($block['_pb_server_component_code'] ?? ''),
            (string)($block['code'] ?? ''),
            (string)($block['component'] ?? ''),
            (string)($block['block_key'] ?? ''),
            (string)($block['section_code'] ?? ''),
            (string)($block['instance_id'] ?? ''),
        ];
        foreach ($this->buildComponentIdentityTokens($pageType, $candidateValues) as $candidate) {
            if (\in_array($candidate, $targets, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function buildComponentIdentityTokens(string $pageType, array $values): array
    {
        $tokens = [];
        foreach ($values as $value) {
            $value = \trim((string)$value);
            if ($value === '') {
                continue;
            }
            $tokens[] = $value;
            $tokens[] = \str_replace('/', '-', $this->normalizeGeneratedComponentCodeToBlockId($value));
            $tokens[] = $this->normalizeLayoutComponentIdentity($value);
            $tokens[] = $this->normalizePageScopedComponentIdentity($pageType, $value);
        }
        return \array_values(\array_unique(\array_filter($tokens, static fn(string $token): bool => $token !== '')));
    }

    private function normalizePageScopedComponentIdentity(string $pageType, string $value): string
    {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return '';
        }
        if (\str_starts_with($value, 'content/')) {
            $value = \substr($value, \strlen('content/'));
        }
        $value = (string)\preg_replace('/[\/._\-\s]+/', '_', $value);
        $value = \trim($value, '_');
        $pageToken = \strtolower(\trim($pageType));
        $pageToken = (string)\preg_replace('/[\/._\-\s]+/', '_', $pageToken);
        $pageToken = \trim($pageToken, '_');
        if ($pageToken !== '' && \str_starts_with($value, $pageToken . '_')) {
            $value = \substr($value, \strlen($pageToken) + 1);
        }
        return $value;
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
        $blocks = $this->planJsonDynamicBlocks($virtualPage);
        $componentCode = \trim((string)($updatedBlock['_pb_server_component_code'] ?? $updatedBlock['component_code'] ?? ''));

        foreach ($blocks as $index => $block) {
            if (!\is_array($block) || !$this->blockMatchesComponentIdentity($pageType, $block, $blockId, $componentCode)) {
                continue;
            }

            $blocks[$index] = $updatedBlock;
            break;
        }

        foreach ($blocks as $key => $block) {
            if (\is_string($key) && \is_array($block)) {
                $virtualPage[$key] = $block;
            }
        }
        $virtualPage['last_generated_at'] = $lastGeneratedAt;
        $virtualPages[$pageType] = $virtualPage;

        return $virtualPages;
    }

    /**
     * Keep block editor saves on the virtual theme side. Materialized Page rows
     * are intentionally updated only by publish/materialization.
     *
     * @param array<string, mixed> $scope
     * @param list<string> $pageTypes
     * @param array<string, mixed> $updatedBlock
     * @return array<string, mixed>
     */
    private function syncUpdatedVirtualBlockToThemeLayout(
        array $scope,
        array $pageTypes,
        string $pageType,
        array $updatedBlock
    ): array {
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId <= 0) {
            return $scope;
        }

        $blockConfig = \is_array($updatedBlock['config'] ?? null) ? $updatedBlock['config'] : [];
        if ($blockConfig === []) {
            return $scope;
        }

        $region = \strtolower(\trim((string)($updatedBlock['_pb_server_region'] ?? $updatedBlock['region'] ?? $blockConfig['region'] ?? '')));
        if ($region === '') {
            $region = $this->htmlBlocksBuildService->resolveSharedBlockRegion($updatedBlock);
        }
        if ($region === '') {
            $region = 'content';
        }
        $componentCode = \trim((string)($updatedBlock['_pb_server_component_code'] ?? $updatedBlock['component_code'] ?? $updatedBlock['code'] ?? ''));
        $blockId = \trim((string)($updatedBlock['block_id'] ?? ''));
        if ($componentCode === '' && $blockId !== '') {
            $componentCode = \str_replace('-', '/', $blockId);
            if ($region === 'content' && !\str_starts_with($componentCode, 'content/')) {
                $componentCode = 'content/' . $componentCode;
            }
        }

        $targetPageType = \in_array($region, ['header', 'footer'], true) ? Page::TYPE_HOME : $pageType;
        $layoutPageTypes = \array_values(\array_unique(\array_merge($pageTypes, [$targetPageType])));
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $layoutPageTypes);
        if (!isset($pageTypeLayouts[$targetPageType]) || !\is_array($pageTypeLayouts[$targetPageType])) {
            $pageTypeLayouts[$targetPageType] = $this->scopeCompatibilityService->normalizeLayoutConfig([], $targetPageType);
        }
        $layout = $pageTypeLayouts[$targetPageType];
        if ($this->layoutHasNoConfiguredComponents($layout)) {
            $persistedLayout = $this->virtualThemeService->loadGeneratedPageLayout($virtualThemeId, $targetPageType);
            if (!$this->layoutHasNoConfiguredComponents($persistedLayout)) {
                $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($persistedLayout, $targetPageType);
            }
        }
        $changed = false;

        if (\in_array($region, ['header', 'footer'], true)) {
            $slot = \is_array($layout[$region] ?? null) ? $layout[$region] : ['component' => '', 'config' => []];
            if ($componentCode !== '') {
                $slot['component'] = $componentCode;
            }
            $slot['config'] = $blockConfig;
            $layout[$region] = $slot;
            $changed = true;
        } else {
            $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
            $matchedContentIndex = null;
            $normalizedComponentCode = $this->normalizeLayoutComponentIdentity($componentCode);
            $normalizedBlockId = $this->normalizeLayoutComponentIdentity($blockId);
            $requestedContentIndex = isset($updatedBlock['index']) ? (int)$updatedBlock['index'] : -1;
            if ($requestedContentIndex >= 0 && isset($content[$requestedContentIndex]) && \is_array($content[$requestedContentIndex])) {
                $item = $content[$requestedContentIndex];
                $itemCode = \trim((string)($item['code'] ?? $item['component'] ?? ''));
                $itemBlockId = $this->normalizeGeneratedComponentCodeToBlockId($itemCode);
                $normalizedItemCode = $this->normalizeLayoutComponentIdentity($itemCode);
                if (
                    ($componentCode !== '' && $itemCode === $componentCode)
                    || ($normalizedComponentCode !== '' && $normalizedItemCode === $normalizedComponentCode)
                    || ($blockId !== '' && $itemBlockId === $blockId)
                    || ($normalizedBlockId !== '' && $normalizedItemCode === $normalizedBlockId)
                    || ($componentCode !== '' && $this->normalizePageScopedComponentIdentity($pageType, $itemCode) === $this->normalizePageScopedComponentIdentity($pageType, $componentCode))
                ) {
                    $item['config'] = $blockConfig;
                    if ($componentCode !== '') {
                        $item['code'] = $componentCode;
                    }
                    $content[$requestedContentIndex] = $item;
                    $matchedContentIndex = $requestedContentIndex;
                    $changed = true;
                }
            }
            foreach ($content as $index => $item) {
                if ($changed) {
                    break;
                }
                if (!\is_array($item)) {
                    continue;
                }
                $itemCode = \trim((string)($item['code'] ?? $item['component'] ?? ''));
                $itemBlockId = $this->normalizeGeneratedComponentCodeToBlockId($itemCode);
                $normalizedItemCode = $this->normalizeLayoutComponentIdentity($itemCode);
                if (
                    ($componentCode !== '' && $itemCode === $componentCode)
                    || ($normalizedComponentCode !== '' && $normalizedItemCode === $normalizedComponentCode)
                    || ($blockId !== '' && $itemBlockId === $blockId)
                    || ($normalizedBlockId !== '' && $normalizedItemCode === $normalizedBlockId)
                    || ($componentCode !== '' && $this->normalizePageScopedComponentIdentity($pageType, $itemCode) === $this->normalizePageScopedComponentIdentity($pageType, $componentCode))
                    || ($blockId !== '' && $this->normalizePageScopedComponentIdentity($pageType, $itemCode) === $this->normalizePageScopedComponentIdentity($pageType, $blockId))
                ) {
                    $item['config'] = $blockConfig;
                    $content[$index] = $item;
                    $matchedContentIndex = $index;
                    $changed = true;
                    break;
                }
            }
            if (!$changed && $componentCode !== '') {
                $content[] = [
                    'code' => $componentCode,
                    'enabled' => true,
                    'config' => $blockConfig,
                    'instance_id' => $blockId,
                    'sort_order' => (\count($content) + 1) * 10,
                ];
                $changed = true;
                $matchedContentIndex = \array_key_last($content);
            }
            if ($matchedContentIndex !== null) {
                $content = $this->dedupeLayoutContentAfterBlockConfigUpdate(
                    $content,
                    (int)$matchedContentIndex,
                    $normalizedComponentCode,
                    $normalizedBlockId
                );
            }
            $layout['content'] = \array_values($content);
        }

        if (!$changed) {
            return $scope;
        }

        $pageTypeLayouts[$targetPageType] = $layout;
        $this->virtualThemeService->saveGeneratedPageLayout($virtualThemeId, $targetPageType, $layout);
        $scope['page_type_layouts'] = $pageTypeLayouts;

        return $scope;
    }

    /**
     * @param array<string, mixed> $layout
     */
    private function layoutHasNoConfiguredComponents(array $layout): bool
    {
        $header = \is_array($layout['header'] ?? null) ? $layout['header'] : [];
        if (\trim((string)($header['component'] ?? '')) !== '') {
            return false;
        }

        $footer = \is_array($layout['footer'] ?? null) ? $layout['footer'] : [];
        if (\trim((string)($footer['component'] ?? '')) !== '') {
            return false;
        }

        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        foreach ($content as $item) {
            if (!\is_array($item)) {
                continue;
            }
            if (\trim((string)($item['code'] ?? $item['component'] ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function runBlockPartialPatchOperation(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $pageType,
        string $blockId,
        string $instruction = '',
        string $executionToken = ''
    ): array {
        $this->assertActiveStreamLeaseAlive($session, $adminId);
        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'block_partial_patch',
            'Reading current block for partial patch',
            15,
            $pageType
        );

        try {
            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'block_partial_patch',
                'Generating scoped block patch',
                45,
                $pageType
            );

            $result = $this->blockPartialPatchService()->generateAndApplyPatch(
                $session,
                $adminId,
                $pageType,
                $blockId,
                $instruction,
                $executionToken,
                function (string $eventType, array $payload) use ($sse, $pageType, $blockId): void {
                    if ($eventType === 'ai_response' && \trim((string)($payload['content'] ?? '')) !== '') {
                        $sse->sendEvent('ai_chunk', [
                            'operation' => 'block_partial_patch',
                            'page_type' => $pageType,
                            'block_id' => $blockId,
                            'content' => (string)$payload['content'],
                        ]);
                    }
                }
            );

            if (empty($result['success'])) {
                $detail = \is_array($result['details'] ?? null) ? $result['details'] : [];
                $message = \trim((string)($result['message'] ?? 'Block partial patch failed validation.'));
                if ($detail !== []) {
                    $message .= ' details=' . \json_encode($detail, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
                }
                throw new \RuntimeException($message);
            }

            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $state = $this->buildWorkspaceState($fresh, $adminId, 80, true);
            $payload = [
                'operation' => 'block_partial_patch',
                'page_type' => $pageType,
                'block_id' => (string)($result['block_id'] ?? $blockId),
                'component_code' => (string)($result['component_code'] ?? ''),
                'change_summary' => (string)($result['change_summary'] ?? ''),
                'changed_fields' => \is_array($result['changed_fields'] ?? null) ? $result['changed_fields'] : [],
                'state' => $this->buildWorkspaceEventStatePayload($state, [$pageType]),
            ];
            $sse->sendEvent('block_partial_patch_applied', $payload);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'block_partial_patch_applied',
                'Block partial patch applied: ' . (string)($result['block_id'] ?? $blockId),
                \array_diff_key($payload, ['state' => true])
            );
            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'block_partial_patch',
                'Block partial patch applied',
                100,
                $pageType,
                'done',
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );

            return $result;
        } catch (\Throwable $throwable) {
            $failurePayload = [
                'operation' => 'block_partial_patch',
                'page_type' => $pageType,
                'block_id' => $blockId,
                'message' => $throwable->getMessage(),
            ];
            $sse->sendEvent('block_partial_patch_failed', $failurePayload);
            $this->updateActiveOperation(
                $session,
                $adminId,
                [
                    'operation' => 'block_partial_patch',
                    'queue_status' => 'error',
                    'message' => $throwable->getMessage(),
                    'progress_percent' => 100,
                    'page_type' => $pageType,
                ],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED
            );
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'block_partial_patch_failed',
                $throwable->getMessage(),
                $failurePayload,
                AiSiteAgentSessionEvent::LEVEL_ERROR
            );

            throw $throwable;
        }
    }

    /**
     * Comment removed.
     */
    private function handleWorkspaceState(): string
    {
        $adminId = 0;
        $publicId = '';
        $stateMode = '';
        try {
            $adminId = (int)$this->getLoginUserId();
            $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
            $stateMode = \strtolower(\trim((string)$this->getRequestBodyValue('state_mode', '')));
            if ($adminId <= 0 || $publicId === '') {
                return $this->jsonError('INVALID_PARAMS', (string)__('Invalid parameters.'), self::PARAMS_PUBLIC_ID);
            }
            $session = $this->sessionService->loadByPublicId($publicId, $adminId);
            if ($session === null) {
                return $this->jsonError('SESSION_NOT_FOUND', (string)__('Session not found.'), self::PARAMS_PUBLIC_ID);
            }
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            if ($stateMode === '') {
                $stateMode = 'queue_poll';
            }
            if ($stateMode !== 'full' && $stateMode !== 'full_state') {
                return $this->fetchJson([
                    'success' => true,
                    'data' => $this->buildWorkspaceQueuePollState(
                        $fresh,
                        $adminId,
                        (string)$this->getRequestBodyValue('operation', '')
                    ),
                ]);
            }

            return $this->fetchJson([
                'success' => true,
                'data' => $this->buildWorkspaceOperationPayload(
                    $this->buildWorkspaceState($fresh, $adminId, 12, false),
                    'state'
                ),
            ]);
        } catch (\Weline\Framework\Http\ResponseTerminateException $throwable) {
            throw $throwable;
        } catch (\Throwable $throwable) {
            $this->logWorkspaceStateFailure($throwable, $publicId, $stateMode, $adminId);
            return $this->fetchJson([
                'success' => false,
                'code' => 'STATE_FAILED',
                'message' => (string)__('Operation completed.'),
                'data' => [
                    'public_id' => $publicId,
                    'response_mode' => $stateMode === '' ? 'queue_poll' : $stateMode,
                    'status' => 'failed',
                    'source' => 'poller',
                    'updated_at' => \date('Y-m-d H:i:s'),
                ],
            ]);
        }
    }

    private function logWorkspaceStateFailure(\Throwable $throwable, string $publicId, string $stateMode, int $adminId): void
    {
        static $lastLoggedAt = [];

        $signature = $throwable::class . '|' . $throwable->getFile() . ':' . $throwable->getLine();
        $now = \microtime(true);
        if (($lastLoggedAt[$signature] ?? 0.0) > $now - 30.0) {
            return;
        }
        $lastLoggedAt[$signature] = $now;

        \error_log('[AiSiteWorkspaceState] failed ' . \json_encode([
            'public_id' => $publicId,
            'state_mode' => $stateMode,
            'admin_id' => $adminId,
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Queue polling must stay cheap. It only returns the state patch required by
     * progress/status widgets and avoids virtual page rebuilding, asset manifests,
     * design direction hydration, and event list loading.
     *
     * @return array<string, mixed>
     */
    private function buildWorkspaceQueuePollState(
        AiSiteAgentSession $session,
        int $adminId,
        string $requestedOperation
    ): array {
        $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeFragment(
                $session,
                self::WORKSPACE_FAST_VIEW_SCOPE_KEYS,
                $this->workspaceFastViewArtifactKeys($stage)
            )
        );
        $scope = $this->discardForeignAiSiteQueueRuntimeState($scope, (int)$session->getId());

        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $operation = $this->normalizeWorkspaceQueuePollOperation($requestedOperation);
        if ($operation === '') {
            $operation = $this->normalizeWorkspaceQueuePollOperation((string)($activeOperation['operation'] ?? ''));
        }
        if ($operation === '' || !isset([
            'plan' => true,
            'build' => true,
            'regenerate_page' => true,
            'block_regenerate' => true,
            'block_partial_patch' => true,
            'image_asset' => true,
            'publish' => true,
        ][$operation])) {
            $operation = 'build';
        }

        $operationState = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, $operation);
        $planQueueInfo = \is_array($scope['plan_queue_info'] ?? null) ? $scope['plan_queue_info'] : null;
        $buildQueueInfo = \is_array($scope['build_queue_info'] ?? null) ? $scope['build_queue_info'] : null;
        if ($operation === 'plan') {
            $planQueueInfo = $this->PlanJsonStageQueueInfoPayload($session, $operationState) ?? $planQueueInfo;
        } else {
            $buildQueueInfo = $this->buildOperationStageQueueInfoPayload($session, $operationState, $operation) ?? $buildQueueInfo;
        }
        if (
            $operation !== 'build'
            && $this->shouldAttachBuildQueueInfoForPublishGate($stage, $scope, $activeOperation, $activeOperations, $buildQueueInfo)
        ) {
            $buildOperationState = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, 'build');
            $resolvedBuildQueueInfo = $this->buildOperationStageQueueInfoPayload($session, $buildOperationState, 'build');
            if (\is_array($resolvedBuildQueueInfo)) {
                $buildQueueInfo = $resolvedBuildQueueInfo;
                $buildOperationState = $this->reconcileActiveOperationWithQueueInfo(
                    $buildOperationState,
                    $buildQueueInfo,
                    'build'
                );
                if ($buildOperationState !== []) {
                    $activeOperations['build'] = \array_replace(
                        \is_array($activeOperations['build'] ?? null) ? $activeOperations['build'] : [],
                        $buildOperationState,
                        ['operation' => 'build']
                    );
                }
            }
        }

        $operationState = $this->reconcileActiveOperationWithQueueInfo(
            $operationState,
            $operation === 'plan' ? $planQueueInfo : $buildQueueInfo,
            $operation
        );
        $queueTerminalRecovered = !empty($operationState['queue_terminal_recovered']);
        if ($queueTerminalRecovered) {
            if ($operation === 'plan' && \is_array($planQueueInfo)) {
                $planQueueInfo = $this->markQueueInfoPayloadAsRecoveredForRetry($planQueueInfo, $operationState);
            } elseif (\is_array($buildQueueInfo)) {
                $buildQueueInfo = $this->markQueueInfoPayloadAsRecoveredForRetry($buildQueueInfo, $operationState);
            }
        }
        if ($operationState !== []) {
            $activeOperations[$operation] = \array_replace(
                \is_array($activeOperations[$operation] ?? null) ? $activeOperations[$operation] : [],
                $operationState,
                ['operation' => $operation]
            );
            if (
                \trim((string)($activeOperation['operation'] ?? '')) === $operation
                || $this->executionTokenMatches(
                    (string)($activeOperation['execution_token'] ?? ''),
                    (string)($operationState['execution_token'] ?? '')
                )
            ) {
                $activeOperation = $operationState;
            }
        }
        $websiteId = \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId());
        $virtualThemeId = \max((int)($scope['virtual_theme_id'] ?? 0), (int)$session->getVirtualThemeId());
        $planJsonTaskSummary = $this->planJsonPages($scope) !== []
            ? $this->planJsonTaskService->summarize($scope)
            : (\is_array($scope['plan_json_task_summary'] ?? null) ? $scope['plan_json_task_summary'] : []);
        $taskReady = $this->taskSummaryIndicatesCompleted($planJsonTaskSummary);
        $completionGate = $this->planJsonTaskService->inspectBuildCompletionGate($scope);
        $completionGatePassed = !empty($completionGate['passed'])
            || !empty($scope['build_summary']['completion_gate']['passed']);
        $titleOk = \trim((string)($scope['website_profile']['site_title'] ?? $scope['site_title'] ?? '')) !== '';
        $published = $session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED
            || (string)($scope['workspace_status'] ?? '') === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        $scopeForPublishGate = \array_replace($scope, [
            'active_operation' => $activeOperation,
            'active_operations' => $activeOperations,
        ]);
        if (\is_array($buildQueueInfo)) {
            $scopeForPublishGate['build_queue_info'] = $buildQueueInfo;
        }
        $publishBlockingAiRunning = $this->hasPublishBlockingAiBuildRunningState($scopeForPublishGate, $activeOperation, $buildQueueInfo);
        $publishBlockingBuildFailure = $this->resolveLatestPublishBlockingAiBuildFailure($scopeForPublishGate, $activeOperation, $buildQueueInfo);
        $workspaceStatus = (string)($scope['workspace_status'] ?? '');
        if ($publishBlockingAiRunning && $workspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED) {
            $workspaceStatus = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        }
        $state = [
            'public_id' => $session->getPublicId(),
            'stage' => $stage,
            'stage_label' => $this->getStageLabel($stage),
            'workspace_status' => $workspaceStatus,
            'publish_status' => $session->getPublishStatus(),
            'can_publish' => !$publishBlockingAiRunning && empty($publishBlockingBuildFailure['blocked']) && ($published || ($completionGatePassed && $taskReady && $titleOk)),
            'latest_build_failed' => !empty($publishBlockingBuildFailure['blocked']),
            'latest_build_failure' => !empty($publishBlockingBuildFailure['blocked']) ? $publishBlockingBuildFailure : [],
            'publish_blocked_by_latest_ai_failure' => !empty($publishBlockingBuildFailure['blocked']),
            'publish_blocked_reason' => !empty($publishBlockingBuildFailure['blocked'])
                ? $this->formatPublishBlockedByAiFailureMessage($publishBlockingBuildFailure)
                : '',
            'workspace_track' => (string)($scope['workspace_track'] ?? ''),
            'website_id' => $websiteId,
            'virtual_theme_id' => $virtualThemeId,
            'draft_website_id' => $websiteId,
            'preview_page_id' => (int)($scope['preview_page_id'] ?? 0),
            'preview_page_type' => (string)($scope['preview_page_type'] ?? ''),
            'active_operation' => $activeOperation,
            'active_operations' => $activeOperations,
            'plan_queue_info' => $planQueueInfo,
            'build_queue_info' => $buildQueueInfo,
            'plan_json_task_summary' => $planJsonTaskSummary,
            'task_summary' => $planJsonTaskSummary,
            'build_summary' => \is_array($scope['build_summary'] ?? null)
                ? \array_diff_key($scope['build_summary'], ['task_summary' => true])
                : [],
            'pending_generation_page_types' => \is_array($scope['pending_generation_page_types'] ?? null) ? $scope['pending_generation_page_types'] : [],
            'has_stage_one_plan' => $this->workspaceFastViewHasStageOnePlan($scope),
            'plan_json' => \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
            'stage1_validation_report' => \is_array($scope['stage1_validation_report'] ?? null) ? $scope['stage1_validation_report'] : [],
            'retryable_failures' => \is_array($scope['retryable_failures'] ?? null) ? $scope['retryable_failures'] : [],
            'plan_generation_last_error' => \is_array($scope['plan_generation_last_error'] ?? null) ? $scope['plan_generation_last_error'] : [],
            'scope' => [
                'workspace_status' => $workspaceStatus,
                'active_operation' => $activeOperation,
                'active_operations' => $activeOperations,
                'plan_json_task_summary' => $planJsonTaskSummary,
                'latest_build_failed' => !empty($publishBlockingBuildFailure['blocked']) ? 1 : 0,
                'latest_build_failure' => !empty($publishBlockingBuildFailure['blocked']) ? $publishBlockingBuildFailure : [],
                'publish_blocked_by_latest_ai_failure' => !empty($publishBlockingBuildFailure['blocked']) ? 1 : 0,
                'publish_blocked_reason' => !empty($publishBlockingBuildFailure['blocked'])
                    ? $this->formatPublishBlockedByAiFailureMessage($publishBlockingBuildFailure)
                    : '',
            ],
            'events' => [],
            'response_mode' => 'queue_poll',
            'response_operation' => $operation,
        ];

        return $this->decorateWorkspaceStateWithPollingPayload($state);
    }

    /**
     * @return list<string>
     */
    private function workspaceFastViewScopeKeys(string $stage): array
    {
        if ($stage !== AiSiteAgentSession::STAGE_PLAN
            && $stage !== AiSiteAgentSession::STAGE_VISUAL_EDIT
            && $stage !== AiSiteAgentSession::STAGE_PUBLISH) {
            return self::WORKSPACE_FAST_VIEW_SCOPE_KEYS;
        }

        return \array_values(\array_unique(\array_merge(self::WORKSPACE_FAST_VIEW_SCOPE_KEYS, [
            'plan_json',
            'plan_markdown',
        ])));
    }

    /**
     * @return list<string>
     */
    private function workspaceFastViewArtifactKeys(string $stage): array
    {
        return self::WORKSPACE_FAST_VIEW_ARTIFACT_KEYS_BY_STAGE[$stage] ?? [];
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function workspaceFastViewHasStageOnePlan(array $scope): bool
    {
        return $this->scopeCompatibilityService->hasPersistedStageOnePlan($scope)
            || $this->workspaceScopeHasArtifactRef($scope, AiSiteAgentSession::STAGE_PLAN, 'plan_json')
            || $this->workspaceScopeHasArtifactRef($scope, AiSiteAgentSession::STAGE_PLAN, 'plan_markdown');
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function workspaceScopeHasArtifactRef(array $scope, string $stage, string $artifactKey): bool
    {
        $refs = \is_array($scope['_artifact_refs'] ?? null) ? $scope['_artifact_refs'] : [];
        $stageRefs = \is_array($refs[$stage] ?? null) ? $refs[$stage] : [];

        return \is_array($stageRefs[$artifactKey] ?? null);
    }

    private function normalizeWorkspaceQueuePollOperation(string $operation): string
    {
        $operation = \strtolower(\trim($operation));
        return match ($operation) {
            'visual_edit' => 'build',
            'block_refine' => 'block_regenerate',
            default => $operation,
        };
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed> $activeOperations
     * @param array<string, mixed>|null $buildQueueInfo
     */
    private function shouldAttachBuildQueueInfoForPublishGate(
        string $stage,
        array $scope,
        array $activeOperation,
        array $activeOperations,
        ?array $buildQueueInfo
    ): bool {
        if (\is_array($buildQueueInfo) && $buildQueueInfo !== []) {
            return false;
        }
        if (!empty($scope['latest_build_failed']) || !empty($scope['publish_blocked_by_latest_ai_failure'])) {
            return true;
        }
        if (\is_array($activeOperations['build'] ?? null)) {
            return true;
        }
        if ($this->isPublishBlockingAiBuildOperation((string)($activeOperation['operation'] ?? ''))) {
            return true;
        }

        return \in_array($stage, [
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            AiSiteAgentSession::STAGE_PUBLISH,
        ], true);
    }

    /**
     * Workspace GET is a render hot path. It must not recompute Plan JSONs,
     * virtual pages, task summaries, or asset manifests; the queue/SSE paths own
     * those mutations and the poller patches status after first paint.
     *
     * @return array<string, mixed>
     */
    private function buildWorkspaceFastViewState(AiSiteAgentSession $session, int $adminId): array
    {
        $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeFragment(
                $session,
                $this->workspaceFastViewScopeKeys($stage),
                $this->workspaceFastViewArtifactKeys($stage)
            )
        );
        $scope = $this->discardForeignAiSiteQueueRuntimeState($scope, (int)$session->getId());
        $scope['reference_images'] = $this->resolveReferenceImagesFromScope($scope);
        $scope = $this->scopeCompatibilityService->normalizeConfirmedPlanFlag($scope);
        $scope = $this->normalizePlanJsonConfirmationForBuild($scope);

        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        $draftWebsiteId = \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId());
        $virtualThemeId = \max((int)($scope['virtual_theme_id'] ?? 0), (int)$session->getVirtualThemeId());
        if ($draftWebsiteId > 0) {
            $scope['draft_website_id'] = $draftWebsiteId;
            $scope['website_id'] = $draftWebsiteId;
            $scope['selected_website_id'] = $draftWebsiteId;
        }
        if ($virtualThemeId > 0) {
            $scope['virtual_theme_id'] = $virtualThemeId;
        }
        $scope['page_types'] = $pageTypes;

        $pagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType(
            $scope['materialized_pages_by_type'] ?? []
        );
        $planJsonPages = $this->planJsonPages($scope);
        $virtualPagesByType = $this->scopeCompatibilityService->normalizeVirtualPagesByType(
            $planJsonPages,
            $pageTypes
        );
        if ($virtualPagesByType !== []) {
            $virtualPagesByType = $this->decorateVirtualPagesWithUrls($session->getPublicId(), $virtualThemeId, $virtualPagesByType);
        }
        $previewPageType = $this->scopeCompatibilityService->resolvePreviewPageType(
            $virtualPagesByType,
            (string)($scope['preview_page_type'] ?? '')
        );
        if ($previewPageType === '' && $pageTypes !== []) {
            $previewPageType = (string)$pageTypes[0];
        }
        $scope['preview_page_type'] = $previewPageType;
        $scope['preview_page_id'] = (int)($pagesByType[$previewPageType]['page_id'] ?? 0);

        $prePublishVisualUrls = $previewPageType !== ''
            ? $this->visualUrlService->resolveVirtualUrls($session->getPublicId(), $previewPageType, $virtualThemeId)
            : ['preview_full_url' => '', 'visual_preview_url' => '', 'visual_edit_url' => ''];
        $prePublishVisualUrls = $this->normalizeAiSiteVisualUrlsToLocalBase($prePublishVisualUrls, [
            'scope' => $scope,
            'plan_json_pages' => $virtualPagesByType,
            'preview_page_type' => $previewPageType,
        ]);
        $scope = \array_replace($scope, $prePublishVisualUrls);

        $queuePatch = $this->buildWorkspaceQueuePollState(
            $session,
            $adminId,
            (string)($scope['active_operation']['operation'] ?? '')
        );
        $scopePlanJsonTaskSummary = $planJsonPages !== []
            ? $this->planJsonTaskService->summarize($scope)
            : (\is_array($scope['plan_json_task_summary'] ?? null) ? $scope['plan_json_task_summary'] : []);
        $queuePlanJsonTaskSummary = \is_array($queuePatch['plan_json_task_summary'] ?? null) ? $queuePatch['plan_json_task_summary'] : [];
        $planJsonTaskSummary = $scopePlanJsonTaskSummary !== [] ? $scopePlanJsonTaskSummary : $queuePlanJsonTaskSummary;
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        if ($websiteProfile === []) {
            $websiteProfile = [
                'site_title' => (string)($scope['site_title'] ?? ''),
                'brief_description' => (string)($scope['brief_description'] ?? $scope['user_description'] ?? ''),
                'target_domain' => (string)($scope['target_domain'] ?? $scope['selected_domain'] ?? ''),
                'default_locale' => (string)($scope['default_locale'] ?? $scope['default_language'] ?? ''),
            ];
        }
        $hasStageOnePlan = $this->workspaceFastViewHasStageOnePlan($scope);
        $taskReady = $this->taskSummaryIndicatesCompleted($planJsonTaskSummary);
        $completionGatePassed = !empty($scope['build_summary']['completion_gate']['passed'])
            || !empty($queuePatch['build_summary']['completion_gate']['passed'] ?? null)
            || !empty($queuePatch['scope']['build_summary']['completion_gate']['passed'] ?? null);
        $titleOk = \trim((string)($websiteProfile['site_title'] ?? $scope['site_title'] ?? '')) !== '';
        $published = $session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED
            || (string)($scope['workspace_status'] ?? '') === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        $canPublish = $published
            || (
                !empty($scope['can_publish'])
                && $taskReady
                && $titleOk
            )
            || (
                $completionGatePassed
                && $taskReady
                && $titleOk
            );
        $workspaceStatus = (string)($scope['workspace_status'] ?? '');
        if ($workspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH && !$canPublish) {
            $workspaceStatus = $taskReady
                ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_EDITING
                : (string)$stage;
        }
        if ($canPublish) {
            $workspaceStatus = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        }
        if ($workspaceStatus === '') {
            $workspaceStatus = $canPublish
                ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
                : (string)$stage;
        }
        $queuePatchActiveOperation = \is_array($queuePatch['active_operation'] ?? null) ? $queuePatch['active_operation'] : [];
        $queuePatchBuildInfo = \is_array($queuePatch['build_queue_info'] ?? null) ? $queuePatch['build_queue_info'] : null;
        $queuePatchScope = \array_replace($scope, \is_array($queuePatch['scope'] ?? null) ? $queuePatch['scope'] : []);
        $queuePatchScope['active_operation'] = $queuePatchActiveOperation;
        $queuePatchScope['active_operations'] = \is_array($queuePatch['active_operations'] ?? null) ? $queuePatch['active_operations'] : [];
        if (\is_array($queuePatchBuildInfo)) {
            $queuePatchScope['build_queue_info'] = $queuePatchBuildInfo;
        }
        $queuePatchBuildRunning = $this->hasPublishBlockingAiBuildRunningState(
            $queuePatchScope,
            $queuePatchActiveOperation,
            $queuePatchBuildInfo
        );
        if ($queuePatchBuildRunning) {
            $canPublish = false;
            if ($workspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED) {
                $workspaceStatus = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
            }
        } elseif (!empty($queuePatch['latest_build_failed']) || !empty($queuePatch['publish_blocked_by_latest_ai_failure'])) {
            if (!$completionGatePassed) {
                $canPublish = false;
                $workspaceStatus = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
            }
        }

        $clientScope = $scope;
        unset(
            $clientScope['plan_markdown'],
            $clientScope['plan_projection'],
            $clientScope['pagebuilder_pages_by_type'],
            $clientScope['page_type_layouts'],
            $clientScope['virtual_pages_by_type'],
            $clientScope['materialized_pages_by_type']
        );

        $state = \array_replace($queuePatch, [
            'public_id' => $session->getPublicId(),
            'stage' => $stage,
            'stage_label' => $this->getStageLabel($stage),
            'workspace_status' => $workspaceStatus,
            'publish_status' => $session->getPublishStatus(),
            'can_publish' => $canPublish,
            'workspace_track' => $workspaceTrack,
            'website_id' => $draftWebsiteId,
            'virtual_theme_id' => $virtualThemeId,
            'draft_website_id' => $draftWebsiteId,
            'website_profile' => $websiteProfile,
            'draft_website_id' => $draftWebsiteId,
            'preview_page_options' => $this->scopeCompatibilityService->buildPreviewPageOptions($pagesByType),
            'preview_page_id' => (int)$scope['preview_page_id'],
            'preview_page_type' => $previewPageType,
            'preview_full_url' => (string)($scope['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($scope['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($scope['visual_edit_url'] ?? ''),
            'pre_publish_visual_urls' => $prePublishVisualUrls,
            'plan_json_task_summary' => $planJsonTaskSummary,
            'task_summary' => $planJsonTaskSummary,
            'build_summary' => \is_array($scope['build_summary'] ?? null)
                ? \array_diff_key($scope['build_summary'], ['task_summary' => true])
                : [],
            'plan_json' => \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
            'plan' => [
                'json' => \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
            ],
            'confirmed_plan_signature' => $this->resolveConfirmedPlanSignature($scope),
            'has_stage_one_plan' => $hasStageOnePlan,
            'asset_manifest' => \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : ['version' => 1, 'slots' => []],
            'verified_assets' => \is_array($scope['verified_assets'] ?? null) ? $scope['verified_assets'] : [],
            'reference_images' => \is_array($scope['reference_images'] ?? null) ? $scope['reference_images'] : [],
            'scope' => $clientScope,
            'events' => [],
            'top_logs' => [],
            'last_event_id' => 0,
            'response_mode' => 'fast_view',
        ]);

        return $this->decorateWorkspaceStateWithPollingPayload($state);
    }

    private function handleSwitchPreviewPage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $requestedPageId = (int)$this->getRequestBodyValue('preview_page_id', 0);
        $requestedPageType = \trim((string)$this->getRequestBodyValue('preview_page_type', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid parameters.'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('Session not found.'), self::PARAMS_PUBLIC_ID);
        }

        // Comment removed.
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
            $state['scope']['materialized_pages_by_type'] ?? []
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
            $this->planJsonPages(\is_array($state['scope'] ?? null) ? $state['scope'] : []),
            $this->scopeCompatibilityService->resolveScopedPageTypes(\is_array($state['scope'] ?? null) ? $state['scope'] : [])
        );
        $previewPageType = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, $requestedPageType);
        if ($previewPageType === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Operation failed.')]);
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
            (string)__('Operation completed.'),
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
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid parameters.'), self::PARAMS_PUBLIC_ID);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', 'Session not found.', self::PARAMS_PUBLIC_ID);
        }

        $previewState = $this->sessionService->loadPreviewSwitchScopeState($session->getId(), $adminId);
        if ($previewState === null) {
            return $this->fetchJson(['success' => false, 'message' => 'Preview state unavailable.']);
        }

        $pagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType(
            $previewState['materialized_pages_by_type'] ?? []
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
            \is_array($previewState['page_types'] ?? null) ? $previewState['page_types'] : []
        );
        $virtualPages = $this->scopeCompatibilityService->normalizeVirtualPagesByType(
            \is_array($previewState['plan_json_pages'] ?? null) ? $previewState['plan_json_pages'] : [],
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
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($previewState['workspace_track'] ?? ''));

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
        $prePublishVisualUrls = $this->normalizeAiSiteVisualUrlsToLocalBase($prePublishVisualUrls, [
            'plan_json_pages' => $virtualPagesByType,
            'preview_page_type' => $previewPageType,
        ]);
        $resolvedUrls = $this->shouldUseWorkspacePreviewUrls($session, $workspaceTrack, $previewPageType, $previewPageId)
            ? $prePublishVisualUrls
            : (
                $previewPageId > 0
                    ? $this->normalizeAiSiteVisualUrlsToLocalBase(
                        $this->visualUrlService->resolveUrls($previewPageId, $virtualThemeId),
                        [
                            'plan_json_pages' => $virtualPagesByType,
                            'preview_page_type' => $previewPageType,
                        ]
                    )
                    : $prePublishVisualUrls
            );

        return [
            'preview_page_id' => $previewPageId,
            'preview_page_type' => $previewPageType,
            'preview_full_url' => (string)($resolvedUrls['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($resolvedUrls['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($resolvedUrls['visual_edit_url'] ?? ''),
            'materialized_pages_by_type' => $minimalPagesByType,
            'plan_json_pages' => $selectedVirtualPages,
        ];
    }

    private function shouldUseWorkspacePreviewUrls(
        AiSiteAgentSession $session,
        string $workspaceTrack,
        string $previewPageType,
        int $previewPageId = 0
    ): bool {
        if ($previewPageType === '') {
            return false;
        }

        return $workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
            && $session->getPublishStatus() !== AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED;
    }

    /**
     * Queue/CLI execution may not have the browser Host header. When URL
     * generation returns a pseudo host, preserve its path/query and restore the
     * known local preview origin from the current workspace state.
     *
     * @param array<string,mixed> $urls
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function normalizeAiSiteVisualUrlsToLocalBase(array $urls, array $context): array
    {
        $currentOrigin = $this->resolveAiSiteCurrentRequestOrigin();
        if ($currentOrigin !== '') {
            $context = ['current_request_origin' => $currentOrigin] + $context;
        }

        return $this->visualUrlService->normalizeUrlsToLocalBase($urls, $context);
    }

    private function resolveAiSiteCurrentRequestOrigin(): string
    {
        if (!isset($this->request)) {
            return '';
        }

        $host = \trim((string)($this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: ''));
        if ($host === '') {
            return '';
        }
        $host = \preg_replace('/[\x00-\x20\/\\\\?#]+/', '', $host) ?? '';
        if ($host === '') {
            return '';
        }

        return ($this->request->isSecure() ? 'https' : 'http') . '://' . $host;
    }

    private function resolveAiSiteLocalPreviewOriginFromMixed(mixed $value, int $depth = 0): string
    {
        if ($depth > 8) {
            return '';
        }
        if (\is_string($value)) {
            return $this->isAiSiteLocalPreviewUrl($value) ? $this->extractAiSiteUrlOrigin($value) : '';
        }
        if (!\is_array($value)) {
            return '';
        }

        foreach (['preview_full_url', 'visual_preview_url', 'visual_edit_url', 'virtual_preview_url', 'virtual_edit_url', 'preview_url'] as $key) {
            if (!\array_key_exists($key, $value)) {
                continue;
            }
            $origin = $this->resolveAiSiteLocalPreviewOriginFromMixed($value[$key], $depth + 1);
            if ($origin !== '') {
                return $origin;
            }
        }
        foreach ($value as $item) {
            $origin = $this->resolveAiSiteLocalPreviewOriginFromMixed($item, $depth + 1);
            if ($origin !== '') {
                return $origin;
            }
        }

        return '';
    }

    private function isAiSiteLocalPreviewUrl(string $url): bool
    {
        $host = \parse_url($url, \PHP_URL_HOST);
        $host = \is_string($host) ? \strtolower(\trim($host)) : '';
        return $host !== ''
            && (\str_ends_with($host, '.weline.test') || \str_ends_with($host, '.local.test'));
    }

    private function extractAiSiteUrlOrigin(string $url): string
    {
        $scheme = \parse_url($url, \PHP_URL_SCHEME);
        $host = \parse_url($url, \PHP_URL_HOST);
        if (!\is_string($scheme) || !\is_string($host) || \trim($host) === '') {
            return '';
        }
        $port = \parse_url($url, \PHP_URL_PORT);
        return \strtolower($scheme) . '://' . \strtolower($host) . (\is_int($port) && $port > 0 ? ':' . $port : '');
    }

    private function rewriteAiSiteUrlToOrigin(string $url, string $origin): string
    {
        $origin = \rtrim($origin, '/');
        if ($origin === '') {
            return '';
        }

        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && \trim($path) !== '' ? $path : '/' . \ltrim($url, '/');
        $query = \parse_url($url, \PHP_URL_QUERY);
        return $origin . '/' . \ltrim($path, '/') . (\is_string($query) && $query !== '' ? '?' . $query : '');
    }

    /**
     * Comment removed.
     * Comment removed.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function refreshScopeQualityContractsForPublishGate(array $scope): array
    {
        unset($scope['quality_gate_preflight_error']);
        $summary = $this->planJsonTaskService->summarize($scope);
        $total = (int)($summary['total'] ?? 0);
        if ($total <= 0) {
            return $scope;
        }
        if (
            (int)($summary['pending'] ?? 0) > 0
            || (int)($summary['running'] ?? 0) > 0
            || (int)($summary['failed'] ?? 0) > 0
            || (int)($summary['done'] ?? 0) < $total
        ) {
            return $scope;
        }

        try {
            return $this->planJsonTaskService->attachBuildRenderDataContract($scope);
        } catch (\Throwable $throwable) {
            $scope['quality_gate_preflight_error'] = \trim($throwable->getMessage()) !== ''
                ? $throwable->getMessage()
            : (string)__('Operation failed.');

            return $scope;
        }
    }

    private function handlePublishChecklist(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid parameters.'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('Session not found.'), self::PARAMS_PUBLIC_ID);
        }

        $state = $this->buildWorkspaceFastViewState($session, $adminId);
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForBuildOperation($session)
        );
        $fastScope = \is_array($state['scope'] ?? null) ? $state['scope'] : [];
        foreach ([
            'active_operation',
            'active_operations',
            'build_queue_info',
            'latest_build_failed',
            'publish_blocked_by_latest_ai_failure',
            'workspace_status',
            'can_publish',
            'visual_edit_url',
            'visual_preview_url',
            'pre_publish_visual_urls',
        ] as $runtimeKey) {
            if (\array_key_exists($runtimeKey, $fastScope)) {
                $scope[$runtimeKey] = $fastScope[$runtimeKey];
            }
        }
        $planJsonScope = $scope;
        $completionGate = $this->planJsonTaskService->inspectBuildCompletionGate($scope);
        $completionGatePassed = !empty($completionGate['passed']);
        $publishBlock = $completionGatePassed ? ['blocked' => false] : $this->resolveLatestPublishBlockingAiBuildFailure(
            $scope,
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            \is_array($scope['build_queue_info'] ?? null) ? $scope['build_queue_info'] : null
        );
        $publishBlockedItem = null;
        if (!empty($publishBlock['blocked'])) {
            $publishBlockedItem = [
                'key' => 'latest_ai_build',
                'code' => 'LATEST_AI_BUILD_FAILED',
                'label' => 'Latest AI build completed successfully',
                'ok' => false,
                'value' => (string)($publishBlock['status'] ?? 'failed'),
                'detail' => $this->formatPublishBlockedByAiFailureMessage($publishBlock),
            ];
        }
        if (!$this->isPlanJsonReadyForBuild($planJsonScope)) {
            return $this->fetchJson(['success' => false, 'code' => 'PLAN_JSON_NOT_CONFIRMED', 'message' => __('Operation failed.')]);
        }
        if (!$completionGatePassed) {
            $scope['can_publish'] = 0;
            $state['can_publish'] = false;
        }
        $buildAlreadyComplete = $completionGatePassed;
        $virtualPages = \is_array($state['plan_json_pages'] ?? null) ? $state['plan_json_pages'] : [];
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $checkItems = [
            [
                'key' => 'virtual_theme',
                'code' => 'VIRTUAL_THEME_READY',
                'label' => __('Virtual theme is ready'),
                'ok' => (int)$state['virtual_theme_id'] > 0,
                'value' => (int)$state['virtual_theme_id'],
            ],
            [
                'key' => 'website_profile',
                'code' => 'WEBSITE_PROFILE_READY',
                'label' => __('Website profile is ready'),
                'ok' => !empty($state['website_profile']),
                'value' => [
                    'site_title' => (string)($state['website_profile']['site_title'] ?? ''),
                    'site_tagline' => (string)($state['website_profile']['site_tagline'] ?? ''),
                    'brief_description' => (string)($state['website_profile']['brief_description'] ?? ''),
                    'default_locale' => (string)($state['website_profile']['default_locale'] ?? ''),
                ],
            ],
            [
                'key' => 'visual_editor',
                'code' => 'VISUAL_EDITOR_READY',
                'label' => __('Visual editor is ready'),
                'ok' => \trim((string)$state['visual_edit_url']) !== '' && \trim((string)$state['visual_preview_url']) !== '',
                'value' => ['visual_preview_url' => $state['visual_preview_url'], 'visual_edit_url' => $state['visual_edit_url']],
            ],
        ];
        $checkItems[] = [
            'key' => 'build_completion_gate',
            'code' => 'BUILD_COMPLETION_GATE',
                'label' => __('Ready'),
            'ok' => $completionGatePassed,
            'value' => [
                'passed' => $completionGatePassed,
                'reason' => (string)($completionGate['reason'] ?? ''),
                'done' => (int)($completionGate['done'] ?? 0),
                'total' => (int)($completionGate['total'] ?? 0),
                'page_block_shortfalls' => \is_array($completionGate['page_block_shortfalls'] ?? null)
                    ? $completionGate['page_block_shortfalls']
                    : [],
            ],
            'detail' => $completionGatePassed ? '' : $this->planJsonTaskService->formatBuildCompletionGateFailureDetail($completionGate),
        ];
        if ($publishBlockedItem !== null) {
            \array_unshift($checkItems, $publishBlockedItem);
        }
        $stageTwoReadiness = $this->buildStageTwoPublishReadinessReport($scope);
        $checkItems[] = [
            'key' => 'stage2_task_block_integrity',
            'code' => 'STAGE2_TASK_BLOCK_INTEGRITY',
                'label' => __('Ready'),
            'ok' => !empty($stageTwoReadiness['passed']),
            'value' => [
                'passed' => !empty($stageTwoReadiness['passed']),
                'expected_total' => (int)($stageTwoReadiness['expected_total'] ?? 0),
                'actual_total' => (int)($stageTwoReadiness['actual_total'] ?? 0),
                'failures' => \is_array($stageTwoReadiness['failures'] ?? null) ? $stageTwoReadiness['failures'] : [],
            ],
            'detail' => $this->formatStageTwoPublishReadinessDetail($stageTwoReadiness),
        ];
        $refreshedScope = $this->refreshScopeQualityContractsForPublishGate($scope);
        if ($refreshedScope !== $scope) {
            $this->sessionService->replaceScope($session->getId(), $adminId, $refreshedScope);
            $state['scope'] = $refreshedScope;
            $scope = $refreshedScope;
        }
        $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);
        $qualityReport = $this->normalizePublishQualityReport(
            $qualityGate->inspectScope($scope),
            $stageTwoReadiness
        );
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
            'code' => $passed ? 'PUBLISH_CHECKLIST_PASSED' : 'PUBLISH_CHECKLIST_BLOCKED',
            'items' => $checkItems,
            'stage' => $state['stage'],
            'workspace_status' => $state['workspace_status'],
            'publish_status' => $state['publish_status'],
            'quality_gate' => $qualityReport,
        ];
        if (\is_array($payload['quality_gate'] ?? null)) {
            unset($payload['quality_gate']['page_reports']);
        }
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            (string)$state['stage'],
            'publish_check',
            $passed ? (string)__('Publish checklist passed.') : (string)__('Publish checklist blocked.'),
            [
                'details' => [
                    'passed' => $passed,
                    'code' => $payload['code'],
                    'workspace_status' => $payload['workspace_status'],
                    'publish_status' => $payload['publish_status'],
                    'item_count' => \count($checkItems),
                ],
            ]
        );

        return $this->fetchJson(['success' => true, 'data' => $payload]);
    }

    private function handleStreamSse(): void
    {
        // Comment removed.
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

        // Comment removed.
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
            'message' => (string)__('Operation completed.'),
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // Comment removed.
        if ($publicId === '') {
            $this->logStreamSse('request_rejected', [
                'reason' => 'missing_public_id',
            ], 'warning');
            $response = $this->request->getResponse();
            $response->setHttpResponseCode(400);
            $response->setHeader('Content-Type', 'application/json');
            $response->setBody(\json_encode([
                'error' => 'INVALID_PARAMS',
                'message' => (string)__('Operation completed.'),
                'param' => self::PARAMS_PUBLIC_ID,
            ], JSON_UNESCAPED_UNICODE));
            return;
        }

        // Comment removed.
        // Comment removed.
        $sse = new SseWriter();
        $sse->start();
        if (\defined('DEV') && DEV && !\headers_sent()) {
            \header('X-AiSite-Sse-Debug-Stage: sse-started');
        }
        $sse->sendEvent('start', [
            'message' => (string)__('Operation completed.'),
        ]);

        // Comment removed.
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->logStreamSse('request_rejected', [
                'reason' => 'session_not_found',
                'admin_id' => $adminId,
                'public_id' => $publicId,
            ], 'warning');
            $sse->sendEvent('error', [
                'error' => 'SESSION_NOT_FOUND',
                'message' => (string)__('Operation completed.'),
                'param' => self::PARAMS_PUBLIC_ID,
            ]);
            $sse->complete(['success' => false]);
            return;
        }

        $leaseToken = $this->generateWorkspaceStreamLeaseToken($tabToken);
        $this->touchStreamLeaseState($session, $adminId, $leaseToken, $tabToken);

        // Comment removed.
        @\set_time_limit(0);
        @\ignore_user_abort(true);
        $sse->sendEvent('start', [
            'message' => (string)__('Operation completed.'),
        ]);
        $this->logStreamSse('stream_started', [
            'session_id' => $session->getId(),
            'stage' => $session->getStage(),
            'publish_status' => $session->getPublishStatus(),
            'stream_kind' => 'workspace',
            'tab_token' => $tabToken !== '' ? $tabToken : null,
        ]);
        // Comment removed.
        RequestContext::set(RequestContext::SSE_WRITER_KEY, $sse);
        $stateSource = $this->buildWorkspaceState($session, $adminId, 40, self::WORKSPACE_STREAM_STATE_PERSIST);
        $statePayload = $this->buildWorkspaceStreamStatePayload($stateSource);
        if ($streamStage !== '') {
            $statePayload = $this->filterWorkspaceStateByStage($statePayload, $streamStage);
        }
        $stateLastEventId = (int)($statePayload['last_event_id'] ?? $this->resolveWorkspaceLastEventId(\is_array($stateSource['events'] ?? null) ? $stateSource['events'] : []));
        if ($stateLastEventId > 0 && ($lastEventId <= 0 || ($stateLastEventId - $lastEventId) > self::WORKSPACE_STREAM_MAX_EVENT_REPLAY)) {
            $lastEventId = $stateLastEventId;
            $statePayload['event_replay_skipped'] = true;
            $statePayload['event_replay_skipped_until'] = $lastEventId;
            $statePayload['event_replay_message'] = 'Older events were skipped; current workspace state was sent.';
        }
        $streamObservedActiveOperation = $this->isWorkspaceStreamOperationActive($stateSource);
        $this->logStreamSse('state_built', [
            'session_id' => $session->getId(),
            'state_bytes' => \strlen((string)(\json_encode($stateSource, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '')),
            'state_sse_bytes' => \strlen((string)(\json_encode($statePayload, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '')),
            'event_count' => \count(\is_array($stateSource['events'] ?? null) ? $stateSource['events'] : []),
            'event_sse_count' => \count(\is_array($statePayload['events'] ?? null) ? $statePayload['events'] : []),
            'top_log_count' => \count(\is_array($stateSource['top_logs'] ?? null) ? $stateSource['top_logs'] : []),
            'top_log_sse_count' => \count(\is_array($statePayload['top_logs'] ?? null) ? $statePayload['top_logs'] : []),
        ]);
        $sse->sendEvent('state', $statePayload);
        $lastPlanStatePlanJson = $this->extractPlanJsonForPlanState($stateSource);
        $lastPlanStateFingerprint = $this->planJsonStateService()->fingerprint($lastPlanStatePlanJson);
        $sse->sendEvent('plan_state', $this->planJsonStateService()->PlanJsonStatePayload([], $lastPlanStatePlanJson));
        if ($this->shouldFastFailWorkspaceStream($stateSource)) {
            $this->logStreamSse('stream_fast_failed', [
                'session_id' => $session->getId(),
                'reason' => 'fatal_error_detected_in_state',
            ], 'warning');
            $sse->complete(['success' => false, 'last_event_id' => $lastEventId, 'fatal_error' => true]);
            return;
        }

        // Comment removed.
        $pollInterval = 1000;
        $startTime = \time();
        $loopCount = 0;
        // Comment removed.
        // Comment removed.
        $consecutiveIdleLoops = 0;
        $maxTotalLoops = 86400;
        $probeEveryIdleLoops = 60;

        $terminalCompletePayload = null;
        while (true) {
            $loopCount++;

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

            // Comment removed.
            if ($loopCount > $maxTotalLoops) {
                $this->logStreamSse('max_loops_reached', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                ], 'warning');
                break;
            }

            // Comment removed.
            if (!$sse->isAlive()) {
                $this->logStreamSse('connection_dead', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                ]);
                break;
            }
            $planStatePayload = $this->pollWorkspaceStreamPlanState(
                $session,
                $adminId,
                $lastPlanStatePlanJson,
                $lastPlanStateFingerprint
            );
            if ($planStatePayload !== null) {
                $sse->sendEvent('plan_state', $planStatePayload);
                $consecutiveIdleLoops = 0;
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
                    $logEvents = $this->pruneWorkspaceEventsForSse([$event], 1);
                    $sse->sendEvent('log', $logEvents[0] ?? [
                        'event_id' => $eventId,
                        'event_type' => (string)($event['event_type'] ?? 'log'),
                        'stage_code' => (string)($event['stage_code'] ?? ''),
                        'level' => (string)($event['level'] ?? 'info'),
                    ]);
                }
                $this->logStreamSse('events_forwarded', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                    'event_count' => \count($newEvents),
                    'last_event_id' => $lastEventId,
                ]);
            } else {
                $consecutiveIdleLoops++;

                // Comment removed.
                if ($consecutiveIdleLoops % $probeEveryIdleLoops === 0) {
                    $this->logStreamSse('probing_connection', [
                        'session_id' => $session->getId(),
                        'loop_count' => $loopCount,
                        'consecutive_idle_loops' => $consecutiveIdleLoops,
                    ]);
                    // Comment removed.
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
                $terminalStateSource = $this->buildWorkspaceState($session, $adminId, 40, self::WORKSPACE_STREAM_STATE_PERSIST);
                $terminalCompletePayload = $this->buildWorkspaceStreamTerminalPayload($terminalStateSource, $lastEventId);
                if ($streamStage !== '') {
                    $terminalCompletePayload = $this->filterWorkspaceStateByStage($terminalCompletePayload, $streamStage);
                }
                $this->logStreamSse('terminal_operation_detected', [
                    'session_id' => $session->getId(),
                    'loop_count' => $loopCount,
                    'last_event_id' => $lastEventId,
                    'terminal_status' => (string)($terminalCompletePayload['terminal_status'] ?? ''),
                ]);
                break;
            }

            // Comment removed.
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
     * Workspace SSE progress is derived from the persisted plan_json only.
     *
     * @param array<string, mixed> $lastPlanJson
     */
    private function pollWorkspaceStreamPlanState(
        AiSiteAgentSession $session,
        int $adminId,
        array &$lastPlanJson,
        string &$lastFingerprint
    ): ?array {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN, [])
        );
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planJson = $this->planJsonStateService()->normalizePlanJson($planJson);
        $fingerprint = $this->planJsonStateService()->fingerprint($planJson);
        if ($fingerprint === $lastFingerprint) {
            return null;
        }

        $payload = $this->planJsonStateService()->PlanJsonStatePayload($lastPlanJson, $planJson);
        $lastPlanJson = $planJson;
        $lastFingerprint = $fingerprint;

        return $payload;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function extractPlanJsonForPlanState(array $state): array
    {
        $plan = \is_array($state['plan'] ?? null) ? $state['plan'] : [];
        $planJson = \is_array($state['plan_json'] ?? null)
            ? $state['plan_json']
            : (\is_array($plan['json'] ?? null) ? $plan['json'] : []);

        return $this->planJsonStateService()->normalizePlanJson($planJson);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isWorkspaceStreamOperationActive(array $state): bool
    {
        $activeOperation = \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [];
        $status = \strtolower(\trim((string)($activeOperation['queue_status'] ?? '')));

        return \in_array($status, ['queued', 'running'], true);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function isWorkspaceStreamOperationTerminal(array $state): bool
    {
        $activeOperation = \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [];
        $status = \strtolower(\trim((string)($activeOperation['queue_status'] ?? '')));

        if (\in_array($status, ['stop', 'stopped'], true)) {
            return true;
        }

        return \in_array($status, ['done', 'error', 'cancelled'], true);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildWorkspaceStreamTerminalPayload(array $state, int $lastEventId): array
    {
        $payload = $this->buildWorkspaceStreamStatePayload($state);
        $activeOperation = \is_array($payload['active_operation'] ?? null) ? $payload['active_operation'] : [];
        $status = \strtolower(\trim((string)($activeOperation['queue_status'] ?? '')));
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
        if (!$sse instanceof QueueDbWriter) {
            $this->observeQueuedPlanOperationFromWorkspaceStream($sse, $session, $adminId);
            return;
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if (\trim((string)($activeOperation['operation'] ?? '')) !== 'plan') {
            return;
        }
        $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        $progressPercent = (int)($activeOperation['progress_percent'] ?? 0);
        $allowResumeStuckRunning = ($activeStatus === 'running' && $progressPercent <= 0);
        // Comment removed.
        $queueDbMode = $sse instanceof QueueDbWriter;
        if (!$queueDbMode) {
            $queueRow = $this->findAiSiteOperationQueueRow($fresh, 'plan', (int)($activeOperation['queue_id'] ?? 0));
            if (\is_array($queueRow) && $queueRow !== []) {
                $this->syncPlanActiveOperationFromQueueRow($fresh, $adminId, $activeOperation, $queueRow);
                return;
            }
            $schedulerWaitMessage = 'Plan queue is waiting for the system scheduler.';
            if ($activeStatus !== 'queued' || \trim((string)($activeOperation['message'] ?? '')) !== $schedulerWaitMessage) {
                $this->updateActiveOperation(
                    $fresh,
                    $adminId,
                    [
                        'queue_status' => 'queued',
                        'message' => $schedulerWaitMessage,
                        'progress_percent' => 0,
                    ],
                    AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
                );
                $sse->sendEvent('progress', [
                    'message' => $schedulerWaitMessage,
                    'operation' => 'plan',
                    'deferred_queue_progress' => true,
                    'progress_percent' => 0,
                ]);
            }
            return;
        }
        if ($queueDbMode) {
            if (!\in_array($activeStatus, ['queued', 'running'], true)) {
                return;
            }
        } elseif ($activeStatus !== 'queued' && !$allowResumeStuckRunning) {
            return;
        }

        // Comment removed.
        // Comment removed.

        // Execution reaches this point only inside the system scheduler queue worker.
        $this->updateActiveOperation(
            $fresh,
            $adminId,
            ['status' => 'running', 'message' => (string)__('Plan operation started.')],
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
        );
        $this->appendWorkspaceEvent(
            $fresh->getId(),
            $adminId,
            'plan',
            'operation_started',
            (string)__('Operation completed.'),
            ['operation' => 'plan']
        );
        $sse->sendEvent('log', [
            'event_type' => 'operation_started',
            'stage_code' => 'plan',
            'message' => (string)__('Operation completed.'),
            'payload' => ['operation' => 'plan'],
            'level' => 'info',
            'event_id' => 0,
            'created_at' => \date('Y-m-d H:i:s'),
        ]);
        $sse->sendEvent('start', [
            'message' => (string)__('Operation completed.'),
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
            $onProgress = function (array $progressPayload) use ($sse): void {
                $message = \trim((string)($progressPayload['message'] ?? ''));
                if ($message === '') {
                    return;
                }

                $payload = [
                    'message' => $message,
                    'operation' => 'plan',
                    'progress_percent' => \max(0, \min(99, (int)($progressPayload['progress_percent'] ?? 0))),
                    'progress_kind' => (string)($progressPayload['progress_kind'] ?? 'queue_info'),
                    'token_usage' => \is_array($progressPayload['token_usage'] ?? null)
                        ? $progressPayload['token_usage']
                        : [
                            'input_tokens' => null,
                            'output_tokens' => null,
                            'total_tokens' => null,
                        ],
                ];
                foreach (['stage1_step', 'stage1_phase', 'page_type', 'page_total', 'queue_process', 'stage1_page_progress'] as $key) {
                    if (\array_key_exists($key, $progressPayload)) {
                        $payload[$key] = $progressPayload[$key];
                    }
                }

                $sse->sendEvent('progress', $payload);
            };
            $onChunk = function (string $chunk) use ($sse, $fresh, $adminId, &$planAiStreamBuffer, &$planMarkdownStreamState): void {
                if ($chunk === '') {
                    return;
                }
                $queueDbMode = $sse instanceof QueueDbWriter;
                if ($queueDbMode) {
                    $sse->recordRawAiStreamChunk($chunk);
                    $sse->maybeHeartbeat();
                    return;
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
            $maxAttempts = 1;
            $attempt = 0;
            $artifacts = [];
            $lastAttemptThrowable = null;
            while ($attempt < $maxAttempts) {
                $attempt++;
                try {
                    if ($attempt > 1) {
                    $retryMessage = (string)__('Operation completed.');
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
                        $mutationBlockConfigs = \is_array($mutation['block_configs'] ?? null) ? $mutation['block_configs'] : [];
                        $mutationBlockKeys = $this->resolvePlanMutationBlockKeys($mutation, $mutationBlockKey);
                        if ($mutationPageType === '' || !\in_array($mutationAction, ['create', 'delete', 'refine', 'rebuild'], true)) {
                            throw new \RuntimeException('Invalid stage-1 block mutation request.');
                        }
            $mutationMessage = (string)__('Operation completed.');
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
                        $mutationTargets = $this->PlanJsonMutationTargets(
                            $mutationAction,
                            $mutationBlockKeys,
                            $mutationBlockKey,
                            $mutationBlockConfig,
                            $mutationBlockConfigs
                        );
                        $workingScope = $scope;
                        $mutationSummaries = [];
                        $lastArtifacts = [];
                        foreach ($mutationTargets as $mutationTarget) {
                            $targetBlockKey = \trim((string)($mutationTarget['block_key'] ?? ''));
                            $targetBlockConfig = \is_array($mutationTarget['block_config'] ?? null) ? $mutationTarget['block_config'] : [];
                            $lastArtifacts = $this->planJsonGenerationService->mutateDraftPlanBlock(
                                $workingScope,
                                $mutationPageType,
                                $mutationAction,
                                $targetBlockKey,
                                $targetBlockConfig
                            );
                            $mutationSummary = \is_array($lastArtifacts['mutation_summary'] ?? null)
                                ? $lastArtifacts['mutation_summary']
                                : [];
                            if ($mutationSummary !== []) {
                                $mutationSummaries[] = $mutationSummary;
                            }
                            $workingScope = $this->buildScopeFromPlanArtifactsForMutation($workingScope, $lastArtifacts);
                        }
                        $artifacts = $lastArtifacts;
                        if ($mutationSummaries !== []) {
                            $artifacts['mutation_summary'] = $this->buildCombinedPlanMutationSummary(
                                $mutationAction,
                                $mutationPageType,
                                $mutationSummaries
                            );
                            $artifacts['mutation_summaries'] = $mutationSummaries;
                        }
                        $artifacts['ai_generated'] = 1;
                    } elseif ($requestedPromptMode === 'refine' && \is_array($scope['plan_json'] ?? null) && $scope['plan_json'] !== []) {
                        $resumeInstruction = $requestedPromptMode === 'resume_plan'
                    ? (string)__('Operation completed.')
                            : '';
                        $refineInstruction = $requestedInstruction;
                        $refineTargetScope = $requestedTargetScope;
                        if ($planLocaleChanged && !$pageTypesChanged) {
                            $refineTargetScope = $refineTargetScope !== '' ? $refineTargetScope : 'locale_translation';
                            $refineInstruction = $refineInstruction !== ''
                    ? (string)__('Operation completed.')
                    : (string)__('Operation failed.');
                        }
                        $artifacts = $this->planJsonGenerationService->refineDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                            'instruction' => $refineInstruction,
                            'target_scope' => $refineTargetScope,
                            'round' => $requestedRound,
                            'prompt_mode' => $requestedPromptMode,
                        ], $onChunk, $onProgress);
                    } elseif ($planLocaleChanged && !$pageTypesChanged && \is_array($scope['plan_json'] ?? null) && $scope['plan_json'] !== []) {
                $translateInstruction = (string)__('Operation completed.');
                        $artifacts = $this->planJsonGenerationService->refineDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                            'instruction' => $translateInstruction,
                            'target_scope' => 'locale_translation',
                            'round' => 1,
                        ], $onChunk, $onProgress);
                    } else {
                        if ((int)($scope['fake_mode'] ?? 0) === 1) {
                            $artifacts = $this->planJsonGenerationService->PlanJsonArtifacts($scope, \is_array($websiteProfile) ? $websiteProfile : []);
                        } else {
                            $buildPayload = [];
                            if ($requestedPromptMode === 'resume_plan') {
                $buildPayload['instruction'] = (string)__('Generate the requested page block from plan_json.');
                                $buildPayload['target_scope'] = 'resume_generation';
                                $buildPayload['prompt_mode'] = 'resume_plan';
                                $buildPayload['resume_failed_tasks'] = 1;
                            }
                            if ($requestedInstruction !== '') {
                                $buildPayload['instruction'] = isset($buildPayload['instruction'])
                                    ? ((string)$buildPayload['instruction'] . ' ' . $requestedInstruction)
                                    : $requestedInstruction;
                            }
                            $buildPayload['round'] = $requestedRound;
                            $buildPayload['staged_generation'] = true;
                            $buildPayload['on_stage1_scope_patch'] = function (array $scopePatch) use ($fresh, $adminId, &$scope): void {
                                if ($scopePatch === []) {
                                    return;
                                }
                                $scope = \array_replace($scope, $scopePatch);
                                $this->sessionService->mergeScope($fresh->getId(), $adminId, $scopePatch);
                            };
                            $buildPayload['on_stage1_progress_state'] = function (array $progressState) use ($sse, $fresh, $adminId, &$scope): void {
                                if ($progressState === [] || !\is_array($progressState['plan_json'] ?? null)) {
                                    return;
                                }
                                $progressPlanJson = \is_array($progressState['plan_json'] ?? null) ? $progressState['plan_json'] : [];
                                $scope['plan_json'] = $this->mergeStageOnePersistedPlanJson(
                                    \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
                                    $progressPlanJson
                                );
                                $retryableFailures = \is_array($progressState['retryable_ai_failures'] ?? null)
                                    ? \array_values(\array_filter($progressState['retryable_ai_failures'], static fn($failure): bool => \is_array($failure)))
                                    : [];
                                $scope = $this->planJsonTaskService->replaceRetryableAiFailures($scope, 'plan', $retryableFailures);
                                $progressMessage = \trim((string)($progressState['message'] ?? ''));
                                $planGenerationProgress = [
                                    'step' => (string)($progressState['step'] ?? ''),
                                    'page_types' => \is_array($progressState['page_types'] ?? null) ? $progressState['page_types'] : [],
                                    'failed_count' => \count($retryableFailures),
                                    'updated_at' => (string)($progressState['updated_at'] ?? \date('Y-m-d H:i:s')),
                                ];
                                $progressScopePatch = [
                                    'plan_json' => $this->planJsonStateService()->normalizePlanJson(\is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : []),
                                    'retryable_ai_failures' => \is_array($scope['retryable_ai_failures'] ?? null) ? $scope['retryable_ai_failures'] : [],
                                    'retryable_ai_failure_count' => (int)($scope['retryable_ai_failure_count'] ?? 0),
                                    'next_stage_blocked_by_ai_failures' => (int)($scope['next_stage_blocked_by_ai_failures'] ?? 0),
                                    'plan_generation_progress' => $planGenerationProgress,
                                ];
                                $this->sessionService->mergeScope($fresh->getId(), $adminId, $progressScopePatch);
                                if ($progressMessage !== '') {
                                    $this->appendWorkspaceEvent(
                                        $fresh->getId(),
                                        $adminId,
                                        'plan',
                                        'operation_progress',
                                        $progressMessage,
                                        [
                                            'operation' => 'plan',
                                            'details' => [
                                                'step' => (string)($progressState['step'] ?? ''),
                                                'page_types' => \is_array($progressState['page_types'] ?? null) ? $progressState['page_types'] : [],
                                            ],
                                        ]
                                    );
                                }
                            };
                            $artifacts = $this->planJsonGenerationService->PlanJsonArtifactsByAiStream(
                                $scope,
                                \is_array($websiteProfile) ? $websiteProfile : [],
                                $buildPayload,
                                $onChunk,
                                $onProgress
                            );
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
                throw new \RuntimeException((string)__('Operation failed.'));
            }

            if (!($planMarkdownStreamState['markdown_string_closed'] ?? false)) {
                $this->emitPlanMarkdownBlocks($sse, $fresh, $adminId, (string)($artifacts['markdown'] ?? ''));
            }
            $derivedPatch = \is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [];
            $planJson = $this->mergeStageOnePersistedPlanJson(
                \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
                \is_array($artifacts['plan_json'] ?? null)
                    ? $artifacts['plan_json']
                    : (\is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [])
            );
            $planJson = (new AiSitePlanJsonStateService((int)$fresh->getId()))->setConfirmed($planJson, false);
            $retryablePlanFailures = \is_array($artifacts['retryable_ai_failures'] ?? null)
                ? \array_values(\array_filter($artifacts['retryable_ai_failures'], static fn($failure): bool => \is_array($failure)))
                : [];
            $scopePatchPersist = \array_replace($derivedPatch, [
                'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
                'plan_json' => $planJson,
                'stage1_validation_report' => \is_array($planJson['stage1_validation_report'] ?? null) ? $planJson['stage1_validation_report'] : [],
                'stage1_first_pass' => (int)($planJson['stage1_first_pass'] ?? 0),
                'stage1_generation_attempts' => \is_array($planJson['stage1_generation_attempts'] ?? null) ? $planJson['stage1_generation_attempts'] : [],
                'partial_retry_required' => (int)($artifacts['partial_retry_required'] ?? 0),
                'theme_context_snapshot' => \is_array($planJson['theme_context_snapshot'] ?? null) ? $planJson['theme_context_snapshot'] : [],
                'shared_components' => \is_array($planJson['shared_components'] ?? null) ? $planJson['shared_components'] : [],
                'shared_prompt_context' => \is_array($planJson['shared_prompt_context'] ?? null) ? $planJson['shared_prompt_context'] : [],
                'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
                'plan_locale' => $this->resolvePlanLocale($scope),
                'plan_ai_generated' => (int)($artifacts['ai_generated'] ?? 0),
                'plan_generated_at' => \date('Y-m-d H:i:s'),
                'plan_generated_locale' => (string)$scope['plan_locale'],
                'plan_generated_page_types' => \array_values(\array_map('strval', $currentPageTypes)),
                'plan_generated_source_signature' => $this->PlanJsonSourceSignature(\array_replace($scope, ['page_types' => $currentPageTypes])),
                '_force_rebuild' => 0,
                'plan_generation_progress' => [],
            ]);
            $missingPlanPageTypes = $this->planJsonTaskService->collectMissingSelectedPlanPageTypes(\array_replace($scope, $scopePatchPersist));
            if ($missingPlanPageTypes !== []) {
                $scopePatchPersist['plan_missing_page_types'] = $missingPlanPageTypes;
                $scopePatchPersist['partial_retry_required'] = 1;
                foreach ($missingPlanPageTypes as $missingPageType) {
                    $retryablePlanFailures[$missingPageType] = [
                        'operation' => 'plan',
                        'item_key' => $missingPageType,
                        'item_type' => 'page_fanout',
                        'retry_scope' => 'stage1_page',
                        'page_type' => $missingPageType,
                        'failure_source' => 'gate_coverage',
                        'failure_class' => 'stage1_page_coverage_incomplete',
                        'message' => 'Stage-one plan did not include this selected page type.',
                        'validation_summary' => 'pages.' . $missingPageType . '=missing_page',
                        'failed_at' => \date('Y-m-d H:i:s'),
                    ];
                }
            } else {
                $scopePatchPersist['plan_missing_page_types'] = [];
            }
            $planJsonValidationPatch = $this->PlanJsonPageValidationScopePatch(\array_replace($scope, $scopePatchPersist));
            if ($planJsonValidationPatch !== []) {
                $scopePatchPersist = \array_replace($scopePatchPersist, $planJsonValidationPatch);
            }
            $scopeWithFailures = $this->planJsonTaskService->replaceRetryableAiFailures(
                \array_replace($scope, $scopePatchPersist),
                'plan',
                $retryablePlanFailures
            );
            $scopePatchPersist['retryable_ai_failures'] = \is_array($scopeWithFailures['retryable_ai_failures'] ?? null) ? $scopeWithFailures['retryable_ai_failures'] : [];
            $scopePatchPersist['retryable_ai_failure_count'] = (int)($scopeWithFailures['retryable_ai_failure_count'] ?? 0);
            $scopePatchPersist['next_stage_blocked_by_ai_failures'] = (int)($scopeWithFailures['next_stage_blocked_by_ai_failures'] ?? 0);
            $this->sessionService->mergeScope($fresh->getId(), $adminId, $scopePatchPersist);
            $freshSaved = $this->sessionService->loadById($fresh->getId(), $adminId) ?? $fresh;
            $this->appendWorkspaceEvent(
                $freshSaved->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($freshSaved->getStage()),
                'plan_saved',
                (string)__('Operation completed.'),
                [
                    'operation' => 'plan',
                    'details' => [
                        'plan_locale' => (string)$scope['plan_locale'],
                        'plan_signature' => (string)($planJsonGeneration['signature'] ?? ''),
                        'task_count' => \is_array($planJsonGeneration['tasks'] ?? null) ? \count($planJsonGeneration['tasks']) : 0,
                    ],
                ]
            );
            $sse->sendEvent('log', [
                'event_type' => 'plan_saved',
                'stage_code' => 'plan',
                'message' => (string)__('Operation completed.'),
                'payload' => ['operation' => 'plan'],
                'level' => 'info',
                'event_id' => 0,
                'created_at' => \date('Y-m-d H:i:s'),
            ]);
            $sse->sendEvent('progress', [
                'message' => (string)__('Operation completed.'),
                'operation' => 'plan',
                'progress_percent' => 95,
            ]);
            if ($retryablePlanFailures !== []) {
                $this->updateActiveOperation(
                    $fresh,
                    $adminId,
                    [
                        'queue_status' => 'error',
                        'message' => (string)__('Operation completed.'),
                        'retry_allowed' => 1,
                        'failure_mode' => 'plan_failed',
                        'retryable_ai_failure_count' => \count($retryablePlanFailures),
                    ],
                    AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING
                );
                return;
            }
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                ['queue_status' => 'done', 'message' => (string)__('Plan generated.')],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );
            $this->appendWorkspaceEvent(
                $fresh->getId(),
                $adminId,
                'plan',
                'plan_generated',
                (string)__('Operation completed.'),
                [
                    'operation' => 'plan',
                    'details' => [
                        'plan_signature' => (string)($planJsonGeneration['signature'] ?? ''),
                        'task_count' => \is_array($planJsonGeneration['tasks'] ?? null) ? \count($planJsonGeneration['tasks']) : 0,
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
                'message' => (string)__('Operation completed.'),
                'payload' => ['operation' => 'plan'],
                'level' => 'done',
                'event_id' => 0,
                'created_at' => \date('Y-m-d H:i:s'),
            ]);
            $sse->sendEvent('progress', [
                'message' => (string)__('Operation completed.'),
                'operation' => 'plan',
                'progress_percent' => 99,
            ]);
        } catch (\Throwable $throwable) {
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                ['queue_status' => 'error', 'message' => $throwable->getMessage()],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            );
            $this->appendWorkspaceEvent(
                $fresh->getId(),
                $adminId,
                'plan',
                'operation_failed',
                'Plan operation failed.',
                ['operation' => 'plan', 'details' => ['exception' => $throwable->getMessage()]],
                AiSiteAgentSessionEvent::LEVEL_ERROR
            );
            $sse->sendEvent('log', [
                'event_type' => 'operation_failed',
                'stage_code' => 'plan',
                'message' => 'Operation completed.',
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

    private function observeQueuedPlanOperationFromWorkspaceStream(SseWriter $sse, AiSiteAgentSession $session, int $adminId): void
    {
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, AiSiteAgentSession::STAGE_PLAN)
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if (\trim((string)($activeOperation['operation'] ?? '')) !== 'plan') {
            return;
        }

        $queueRow = $this->findAiSiteOperationQueueRow($fresh, 'plan', (int)($activeOperation['queue_id'] ?? 0));
        if (\is_array($queueRow) && $queueRow !== []) {
            $this->syncPlanActiveOperationFromQueueRow($fresh, $adminId, $activeOperation, $queueRow);
            return;
        }

        $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        $schedulerWaitMessage = (string)__('Waiting for plan_json block work to finish.');
        if ($activeStatus !== 'queued' || \trim((string)($activeOperation['message'] ?? '')) !== $schedulerWaitMessage) {
            $this->updateActiveOperation(
                $fresh,
                $adminId,
                [
                    'queue_status' => 'queued',
                    'message' => $schedulerWaitMessage,
                    'progress_percent' => 0,
                ],
                AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING
            );
            $sse->sendEvent('progress', [
                'message' => $schedulerWaitMessage,
                'operation' => 'plan',
                'deferred_queue_progress' => true,
                'queue_waiting_for_scheduler' => true,
                'can_close_stream' => true,
                'continue_other_operations' => true,
                'progress_percent' => 0,
            ]);
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
            if ($queueProcess !== '') {
                $nextOperation['message'] = $queueProcess;
            } else {
                $nextOperation['message'] = $queueStatus === 'running'
                    ? (string)__('Plan operation is running.')
                    : (string)__('Plan operation is queued.');
            }
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
        $nextOperationStatus = \trim((string)($nextOperation['queue_status'] ?? ($nextOperation['status'] ?? '')));
        if ($nextOperationStatus === 'done') {
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
        } elseif (\in_array($nextOperationStatus, ['queued', 'running'], true)) {
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        }
        $this->sessionService->replaceScope($fresh->getId(), $adminId, $scope);
    }

    private function runPlanOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): void
    {
        $this->runQueuedPlanOperationFromWorkspaceStream($sse, $session, $adminId);
    }

    /**
     * @param array<string, mixed> $currentPlanJson
     * @param array<string, mixed> $incomingPlanJson
     * @return array<string, mixed>
     */
    private function mergeStageOnePersistedPlanJson(array $currentPlanJson, array $incomingPlanJson): array
    {
        if ($incomingPlanJson === []) {
            return $this->planJsonStateService()->normalizePlanJson($currentPlanJson);
        }
        if ($currentPlanJson === []) {
            return $this->planJsonStateService()->normalizePlanJson([
                ...$incomingPlanJson,
                'pages' => $this->normalizeStageOnePlanPageMap(\is_array($incomingPlanJson['pages'] ?? null) ? $incomingPlanJson['pages'] : []),
            ]);
        }

        $merged = \array_replace($currentPlanJson, $incomingPlanJson);
        foreach (['pages'] as $pageBucketKey) {
            $currentPages = \is_array($currentPlanJson[$pageBucketKey] ?? null) ? $currentPlanJson[$pageBucketKey] : [];
            $incomingPages = \is_array($incomingPlanJson[$pageBucketKey] ?? null) ? $incomingPlanJson[$pageBucketKey] : [];
            if ($currentPages !== [] || $incomingPages !== []) {
                $merged[$pageBucketKey] = \array_replace(
                    $this->normalizeStageOnePlanPageMap($currentPages),
                    $this->normalizeStageOnePlanPageMap($incomingPages)
                );
            }
        }

        return $this->planJsonStateService()->normalizePlanJson($merged);
    }

    /**
     * @param array<int|string, mixed> $pages
     * @return array<int|string, mixed>
     */
    private function normalizeStageOnePlanPageMap(array $pages): array
    {
        $normalized = [];
        foreach ($pages as $key => $page) {
            if (!\is_array($page)) {
                $normalized[$key] = $page;
                continue;
            }

            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? ''));
            if ($pageType === '' && \is_string($key) && !\ctype_digit($key)) {
                $pageType = \trim($key);
            }
            if ($pageType !== '') {
                $page['page_type'] = $pageType;
                $normalized[$pageType] = $page;
                continue;
            }

            $normalized[$key] = $page;
        }

        return $normalized;
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
     * @param array<string, mixed> $state
     */
    private function shouldFastFailWorkspaceStream(array $state): bool
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
        $collect($state['top_logs'] ?? []);
        $collect($state['events'] ?? []);
        $collect($state['scope']['top_logs'] ?? []);

        foreach ($messages as $message) {
            $normalized = \strtolower($message);
            if (\str_contains($normalized, 'insufficient balance') || \str_contains($normalized, 'http 402')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comment removed.
     *
     * @return array{ok: bool, reason?: string, message?: string}
     */
    private function claimActiveOperationExecution(
        AiSiteAgentSession $session,
        int $adminId,
        string $executionToken,
        string $operation,
        string $claimSource = 'direct_operation'
    ): array {
        $claimSource = \trim($claimSource);
        if ($claimSource === '') {
            $claimSource = 'direct_operation';
        }
        if ($this->isAiSiteQueueBackedOperation($operation) && $claimSource !== 'queue') {
            return [
                'ok' => false,
                'reason' => 'queue_observer_only',
                'message' => (string)__('Operation completed.'),
            ];
        }
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
            if ($status === '') {
                $status = \trim((string)($active['queue_status'] ?? ''));
            }

            if ($op !== $operation || !$this->executionTokenMatches($tok, $executionToken)) {
                if ($operation !== 'plan') {
                    return ['ok' => true, 'reason' => 'parallel_non_plan'];
                }
                return ['ok' => false, 'reason' => 'operation_mismatch', 'message' => (string)__('Operation token does not match.')];
            }

            if (\in_array($status, ['done', 'error', 'cancelled'], true)) {
                return ['ok' => false, 'reason' => 'operation_terminal', 'message' => (string)__('Operation is already complete.')];
            }

            $allowUnclaimedPlanQueueRun = false;
            if ($status === 'running') {
                $claimedBy = \trim((string)($active['claimed_by'] ?? ''));
                if (
                    $claimSource === 'queue'
                    && \in_array($claimedBy, ['', 'queue'], true)
                ) {
                    // Queue observers may mirror the row to running before the scheduler worker claims it;
                    // the scheduler may also resume a same-token queue after the previous worker died.
                    $allowUnclaimedPlanQueueRun = true;
                } elseif (
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
                return ['ok' => false, 'reason' => 'operation_not_queued', 'message' => (string)__('Operation is not queued.')];
            }

            $scope = $this->writeActiveOperationStateToScope($scope, \array_replace($active, [
                'status' => 'running',
                'queue_status' => 'running',
                'claimed_by' => $claimSource,
                'claimed_at' => \date('Y-m-d H:i:s'),
                'message' => (string)__('Operation completed.'),
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

    /**
     * Runtime queue state is session-owned. Cloned smoke/workbench sessions must not
     * inherit queue ids or job keys from their source session.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function discardForeignAiSiteQueueRuntimeState(array $scope, int $sessionId): array
    {
        if ($sessionId <= 0) {
            return $scope;
        }

        $removed = false;
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if ($activeOperation !== [] && $this->aiSiteRuntimeStateReferencesForeignSession($activeOperation, $sessionId)) {
            $scope['active_operation'] = [];
            $activeOperation = [];
            $removed = true;
        }

        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        foreach ($activeOperations as $operation => $operationState) {
            if (!\is_array($operationState)) {
                unset($activeOperations[$operation]);
                $removed = true;
                continue;
            }
            if ($this->aiSiteRuntimeStateReferencesForeignSession($operationState, $sessionId)) {
                unset($activeOperations[$operation]);
                $removed = true;
            }
        }
        $scope['active_operations'] = $activeOperations;

        foreach (['plan_queue_info', 'build_queue_info'] as $queueInfoKey) {
            $queueInfo = \is_array($scope[$queueInfoKey] ?? null) ? $scope[$queueInfoKey] : [];
            if ($queueInfo !== [] && $this->aiSiteRuntimeStateReferencesForeignSession($queueInfo, $sessionId)) {
                unset($scope[$queueInfoKey]);
                $removed = true;
            }
        }

        if ($removed && $activeOperation === [] && \in_array((string)($scope['workspace_status'] ?? ''), [
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING,
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING,
        ], true)) {
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function aiSiteRuntimeStateReferencesForeignSession(array $state, int $sessionId): bool
    {
        foreach ($this->collectAiSiteRuntimeSessionIds($state) as $referencedSessionId) {
            if ($referencedSessionId > 0 && $referencedSessionId !== $sessionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function collectAiSiteRuntimeSessionIds(mixed $value): array
    {
        if (\is_string($value)) {
            if (\preg_match_all('/glr_aisite:session:(\d+)(?:\:|$)/', $value, $matches) !== false && !empty($matches[1])) {
                return \array_values(\array_unique(\array_map('intval', $matches[1])));
            }

            return [];
        }

        if (!\is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $item) {
            foreach ($this->collectAiSiteRuntimeSessionIds($item) as $id) {
                $ids[] = $id;
            }
        }

        return \array_values(\array_unique($ids));
    }

    private function handleOperationSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        // Comment removed.
        $this->releasePhpSessionLockForSse();

        $sse = new SseWriter();
        $sse->start();

        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        $executionToken = \trim((string)$this->request->getGet('execution_token', ''));
        if ($adminId <= 0 || $publicId === '' || $executionToken === '') {
            $this->sendSseContractError($sse, 'OPERATION_FAILED', (string)__('Operation failed.'));
            $sse->complete(['success' => false]);
            return;
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->sendSseContractError($sse, 'OPERATION_FAILED', (string)__('Operation failed.'));
            $sse->complete(['success' => false]);
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, $this->scopeCompatibilityService->normalizeStage($session->getStage()))
        );
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $status = \trim((string)($activeOperation['queue_status'] ?? ''));
        if ($operation === '') {
            $this->sendSseContractError($sse, 'OPERATION_FAILED', (string)__('Operation failed.'));
            $sse->complete(['success' => false]);
            return;
        }

        if ($operation === 'plan') {
            $queueRow = $this->findAiSiteOperationQueueRow(
                $session,
                $operation,
                (int)($activeOperation['queue_id'] ?? 0)
            );
            if ($this->isObservedQueueInProgress($queueRow)) {
                $this->streamDeferredQueueProgressUntilTerminal(
                    $sse,
                    $session,
                    $adminId,
                    $operation,
                    $executionToken,
                    (int)($queueRow['queue_id'] ?? 0)
                );
                return;
            }
        }

        if ($this->supportsBackgroundOperation($operation) && !\in_array($status, ['pending', 'queued', 'running', 'processing'], true)) {
            $terminalQueueRow = $this->findAiSiteOperationQueueRow(
                $session,
                $operation,
                (int)($activeOperation['queue_id'] ?? 0)
            );
            if (\is_array($terminalQueueRow) && \trim((string)($terminalQueueRow['status'] ?? '')) === 'done') {
                $observed = $this->observeDuplicateOperationStream($sse, $session, $adminId, $operation, $executionToken);
                if ((bool)($observed['deferred_queue_progress'] ?? false)) {
                    return;
                }
                if (!(bool)($observed['success'] ?? true)) {
                    $sse->sendError(
                        (string)($observed['message'] ?? 'Operation failed.'),
                        (int)($observed['http_code'] ?? 500)
                    );
                }
                if (\trim($operation) === 'plan') {
                    $this->streamDeferredQueueProgressUntilTerminal(
                        $sse,
                        $session,
                        $adminId,
                        $operation,
                        $executionToken,
                        (int)($terminalQueueRow['queue_id'] ?? 0)
                    );
                    return;
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

        if ($this->supportsBackgroundOperation($operation) && \in_array($status, ['pending', 'queued', 'running', 'processing'], true)) {
            $queueRow = $this->findAiSiteOperationQueueRow(
                $session,
                $operation,
                (int)($activeOperation['queue_id'] ?? 0)
            );
            if (!\is_array($queueRow) || $queueRow === []) {
                $this->logOperationSse('operation_sse_missing_queue_record', [
                    'public_id' => $session->getPublicId(),
                    'operation' => $operation,
                ]);
                $missingQueueMessage = (string)__('Operation completed.');
                $this->sendSseContractError(
                    $sse,
                    'OPERATION_QUEUE_NOT_FOUND',
                    $missingQueueMessage,
                    self::PARAMS_OPERATION_SSE,
                    404
                );
                $sse->complete([
                    'success' => false,
                    'message' => $missingQueueMessage,
                    'operation' => $operation,
                    'observer_mode' => true,
                    'background_mode' => true,
                    'queue_waiting_for_scheduler' => true,
                    'can_close_stream' => true,
                    'continue_other_operations' => true,
                ]);
                return;
            }

            $queueStatusForObserver = \trim((string)($queueRow['status'] ?? ''));
            $queueWaitingForScheduler = \in_array($queueStatusForObserver, ['pending', 'queued'], true);
            if ($queueWaitingForScheduler) {
                $schedulerHintMessage = (string)__('Operation completed.');
                $sse->sendEvent('info', [
                    'message' => $schedulerHintMessage,
                    'operation' => $operation,
                    'observer_mode' => true,
                    'background_mode' => true,
                    'queue_waiting_for_scheduler' => true,
                    'can_close_stream' => true,
                    'continue_other_operations' => true,
                    'queue_id' => (int)($queueRow['queue_id'] ?? 0),
                    'queue_status' => (string)($queueRow['status'] ?? ''),
                    'biz_key' => (string)($queueRow['biz_key'] ?? ''),
                ]);

                $sse->sendEvent('warning', [
                    'message' => $schedulerHintMessage,
                    'operation' => $operation,
                    'observer_mode' => true,
                    'background_mode' => true,
                    'queue_id' => (int)($queueRow['queue_id'] ?? 0),
                    'queue_status' => (string)($queueRow['status'] ?? ''),
                    'biz_key' => (string)($queueRow['biz_key'] ?? ''),
                ]);
            }
            $observeInitialStateOnly = !$this->shouldKeepQueuedObserverStreamOpen($operation);
            $observed = $this->observeDuplicateOperationStream(
                $sse,
                $session,
                $adminId,
                $operation,
                $executionToken,
                $observeInitialStateOnly
            );
            if (
                !$observeInitialStateOnly
                && $this->shouldKeepQueuedObserverStreamOpen($operation)
                && $sse->isAlive()
            ) {
                $maxObserveResumeCycles = 720;
                $observeResumeCycles = 0;
                while ($sse->isAlive() && $observeResumeCycles < $maxObserveResumeCycles) {
                    $freshObserveSession = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                    $queueRowForResume = $this->findAiSiteOperationQueueRow(
                        $freshObserveSession,
                        $operation,
                        (int)($queueRow['queue_id'] ?? 0)
                    );
                    if (!$this->isObservedQueueInProgress($queueRowForResume)) {
                        break;
                    }
                    if (!(bool)($observed['deferred_queue_progress'] ?? false)) {
                        break;
                    }
                    $observeResumeCycles++;
                    $observed = $this->observeDuplicateOperationStream(
                        $sse,
                        $session,
                        $adminId,
                        $operation,
                        $executionToken,
                        false
                    );
                }
                $freshObserveSession = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $queueRow = $this->findAiSiteOperationQueueRow(
                    $freshObserveSession,
                    $operation,
                    (int)($queueRow['queue_id'] ?? 0)
                );
                $queueStatusForObserver = \trim((string)($queueRow['status'] ?? ''));
                $queueWaitingForScheduler = \in_array($queueStatusForObserver, ['pending', 'queued'], true);
            }
            if (!(bool)($observed['success'] ?? true)) {
                $sse->sendError(
                    (string)($observed['message'] ?? 'Operation failed.'),
                    (int)($observed['http_code'] ?? 500)
                );
            }
            $queueStillInProgress = $this->isObservedQueueInProgress($queueRow);
            $emitDeferredQueueHandoff = (bool)($observed['deferred_queue_progress'] ?? false) && $queueStillInProgress;
            $queueStatusStillInProgress = \in_array($queueStatusForObserver, ['pending', 'queued', 'running', 'processing'], true);
            if (\trim($operation) === 'plan') {
                $this->streamDeferredQueueProgressUntilTerminal(
                    $sse,
                    $session,
                    $adminId,
                    $operation,
                    $executionToken,
                    (int)($queueRow['queue_id'] ?? 0)
                );
                return;
            }
            $sse->complete([
                'success' => (bool)($observed['success'] ?? true),
                'message' => (string)($observed['message'] ?? 'Operation completed.'),
                'operation' => $operation,
                'data' => \is_array($observed['data'] ?? null) ? $observed['data'] : [],
                'state' => \is_array($observed['state'] ?? null) ? $observed['state'] : [],
                'deferred_queue_progress' => $emitDeferredQueueHandoff,
                'queue_waiting_for_scheduler' => $emitDeferredQueueHandoff && $queueWaitingForScheduler,
                'can_close_stream' => $emitDeferredQueueHandoff,
                'continue_other_operations' => $emitDeferredQueueHandoff,
                'queue_status' => $queueStatusForObserver,
            ]);
            return;
        }

        if ($this->isAiSiteQueueBackedOperation($operation)) {
            $queueRow = $this->findAiSiteOperationQueueRow(
                $session,
                $operation,
                (int)($activeOperation['queue_id'] ?? 0)
            );
            if (\is_array($queueRow) && $queueRow !== []) {
                if (\trim($operation) === 'plan') {
                    $this->streamDeferredQueueProgressUntilTerminal(
                        $sse,
                        $session,
                        $adminId,
                        $operation,
                        $executionToken,
                        (int)($queueRow['queue_id'] ?? 0)
                    );
                    return;
                }
                $sse->sendEvent('info', [
                    'message' => (string)__('Operation completed.'),
                    'operation' => $operation,
                    'observer_mode' => true,
                    'queue_id' => (int)($queueRow['queue_id'] ?? 0),
                    'queue_status' => (string)($queueRow['status'] ?? ''),
                ]);
                $observed = $this->observeDuplicateOperationStream($sse, $session, $adminId, $operation, $executionToken);
                if (!(bool)($observed['success'] ?? true)) {
                    $sse->sendError(
                        (string)($observed['message'] ?? __('Operation completed.')),
                        (int)($observed['http_code'] ?? 500)
                    );
                }
                $sse->complete([
                    'success' => (bool)($observed['success'] ?? true),
                    'message' => (string)__('Operation completed.'),
                    'operation' => $operation,
                    'data' => \is_array($observed['data'] ?? null) ? $observed['data'] : [],
                    'state' => \is_array($observed['state'] ?? null) ? $observed['state'] : [],
                    'deferred_queue_progress' => (bool)($observed['deferred_queue_progress'] ?? false),
                ]);
                return;
            }
            $this->sendSseContractError(
                $sse,
                'OPERATION_QUEUE_REQUIRED',
                (string)__('Queued operation requires the system scheduler.'),
                self::PARAMS_OPERATION_SSE,
                409
            );
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $sse->complete([
                'success' => false,
                'message' => (string)__('Operation completed.'),
                'operation' => $operation,
                'state' => $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, false)
                ),
            ]);
            return;
        }

        $claim = $this->claimActiveOperationExecution($session, $adminId, $executionToken, $operation, 'direct_operation');
        if (!$claim['ok']) {
            $reason = (string)($claim['reason'] ?? '');
            if ($reason === 'duplicate_stream') {
                $this->logOperationSse('duplicate_connection', [
                    'public_id' => $publicId,
                    'operation' => $operation,
                ]);
                $sse->sendEvent('warning', [
                'message' => (string)__('Operation completed.'),
                    'operation' => $operation,
                    'observer_mode' => true,
                ]);
                $observed = $this->observeDuplicateOperationStream($sse, $session, $adminId, $operation, $executionToken);
                if (!(bool)($observed['success'] ?? true)) {
                    $sse->sendError(
                        (string)($observed['message'] ?? __('Operation completed.')),
                        (int)($observed['http_code'] ?? 500)
                    );
                }
                $sse->complete([
                    'success' => (bool)($observed['success'] ?? true),
                    'message' => (string)__('Operation completed.'),
                    'operation' => $operation,
                    'data' => \is_array($observed['data'] ?? null) ? $observed['data'] : [],
                    'state' => \is_array($observed['state'] ?? null) ? $observed['state'] : [],
                ]);
                return;
            }
            $terminalMessage = (string)__('Operation completed.');
            $this->sendSseContractError($sse, 'OPERATION_NOT_ACTIVE', $terminalMessage, self::PARAMS_OPERATION_SSE, 409);
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $sse->complete([
                'success' => false,
                'state' => $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, false)
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
            $this->appendWorkspaceEvent($session->getId(), $adminId, $stageCode, 'operation_started', (string)__('Operation started.'));
        RequestContext::set(RequestContext::SSE_WRITER_KEY, $sse);
        RequestContext::set(self::REQUEST_CTX_AI_CHUNK_FORWARDER, function (array $payload) use ($sse, $session, $adminId, $stageCode, $operation): void {
            $chunk = (string)($payload['chunk'] ?? '');
            if ($chunk === '') {
                return;
            }
            $region = \trim((string)($payload['region'] ?? ''));
            $message = $region !== ''
                            ? (string)__('Operation completed.')
                            : (string)__('Operation failed.');
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
                'message' => (string)__('Operation completed.'),
                'operation' => $operation,
                'data' => $result,
                'state' => $statePayload,
            ]);
        } catch (\Throwable $throwable) {
            $failedStatus = $operation === 'publish' ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $this->updateActiveOperation($session, $adminId, ['queue_status' => 'error', 'message' => $throwable->getMessage()], $failedStatus, $operation === 'publish' ? AiSiteAgentSession::PUBLISH_STATUS_FAILED : null);
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
        if ($this->isAiSiteQueueBackedOperation($operation)) {
            throw new \RuntimeException((string)__('AI queue backed operation must resume through queue state.'));
        }

        throw new \RuntimeException((string)__('Unsupported operation.'));
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
        $status = \trim((string)($activeOperation['queue_status'] ?? ''));
        $message = \trim((string)($activeOperation['message'] ?? ''));

        if ($status === 'error') {
            throw new \RuntimeException((string)__('Operation failed.'));
        }

        return [
            'message' => (string)__('Operation completed.'),
            'state' => $state,
            'active_operation' => $activeOperation,
            'plan_json' => \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [],
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
        bool $queueInitialStateOnly = false
    ): array {
        // Comment removed.
        // Comment removed.
        $maxIdleLoops = $this->getObserverMaxIdleLoops();
        $pollIntervalMs = self::OBSERVER_QUEUE_PROGRESS_POLL_INTERVAL_MS;
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
        $lastTaskProgressStateSignature = '';
        $lastStageOnePageProgressSignature = '';
        $includeQueuePayload = $operation === 'plan';
        $initialQueueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, $queueId, $includeQueuePayload);
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
        $this->emitObservedPlanJsonTaskProgressState(
            $sse,
            $scope,
            $operation,
            $initialQueueRow,
            $lastTaskProgressStateSignature
        );
        $this->emitObservedPlanStageOnePageProgressState(
            $sse,
            $initialQueueRow,
            $operation,
            $lastStageOnePageProgressSignature
        );

        if ($queueInitialStateOnly) {
            $queuedState = $this->buildQueuedOperationState(
                $session,
                $stageCode,
                $operation,
                $activeOperation,
                $initialQueueRow
            );

            return [
                'success' => true,
                'message' => (string)__('Operation completed.'),
                'data' => $this->buildObservedOperationResultData($operation, $queuedState),
                'state' => $queuedState,
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
            $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, $queueId, $includeQueuePayload);
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
            $queueProgressBefore = [
                $lastQueueProcess,
                $lastQueueResultLength,
                $lastQueueStatus,
                $lastQueuePid,
            ];
            $stageOneProgressBefore = $lastStageOnePageProgressSignature;
            if ($newEvents !== []) {
                $freshAfterEvents = $this->sessionService->loadById($session->getId(), $adminId) ?? $fresh;
                $queueRowAfterEvents = $this->findAiSiteOperationQueueRow($freshAfterEvents, $operation, $queueId, $includeQueuePayload);
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
            $queueProgressChanged = $queueProgressBefore !== [
                $lastQueueProcess,
                $lastQueueResultLength,
                $lastQueueStatus,
                $lastQueuePid,
            ];
            $this->emitObservedPlanStageOnePageProgressState(
                $sse,
                $queueRow,
                $operation,
                $lastStageOnePageProgressSignature
            );
            $queueProgressChanged = $queueProgressChanged || $stageOneProgressBefore !== $lastStageOnePageProgressSignature;
            $this->emitObservedPlanJsonTaskProgressState(
                $sse,
                $scope,
                $operation,
                $queueRow,
                $lastTaskProgressStateSignature
            );
            if (!$this->isObservedOperationStillRunning($activeOperation, $operation, $executionToken, $queueRow)) {
                $loopQueueStatus = \trim((string)($queueRow['status'] ?? ''));
                // Comment removed.
                if (!$this->isObservedQueueInProgress($queueRow)) {
                    break;
                }
            }

            if ($newEvents !== [] || $queueProgressChanged) {
                $idleLoops = 0;
            } else {
                $idleLoops++;
            }
            if ($maxIdleLoops > 0 && $idleLoops >= $maxIdleLoops) {
                $this->logOperationSse('observation_short_handoff', [
                    'public_id' => $session->getPublicId(),
                    'operation' => $operation,
                    'idle_loops' => $idleLoops,
                    'handoff_seconds' => $maxIdleLoops * ($pollIntervalMs / 1000),
                    'active_operation' => $activeOperation,
                ]);
                break;
            }

            $sse->maybeHeartbeat();
            SchedulerSystem::yieldDelay($pollIntervalMs);
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($fresh, $stageCode)
        );
        $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
        $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, (int)($activeOperation['queue_id'] ?? $queueId), $includeQueuePayload);
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        [$fresh, $scope, $activeOperation, $queueRow, $queueStatus] = $this->settleObservedQueueStateIfNeeded(
            $sse,
            $session,
            $adminId,
            $stageCode,
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
                'queue_status' => 'done',
                'queue_id' => (int)($queueRow['queue_id'] ?? $queueId),
                'message' => $completeMessage,
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
        }
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, false)
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
        $status = \trim((string)($activeOperation['queue_status'] ?? ''));

        $activeInProgress = \in_array($status, ['pending', 'queued', 'running', 'processing'], true);
        $queueInProgress = $this->isObservedQueueInProgress($queueRow);
        $queueTerminal = $this->isObservedQueueTerminal($queueRow);
        $success = !$timedOut
            && !\in_array($status, ['error', 'cancelled', 'pending', 'queued', 'running', 'processing'], true)
            && !$queueInProgress;
        if ($queueStatus === 'done' && $queueTerminal) {
            $success = true;
        } elseif (\in_array($queueStatus, ['error', 'stop'], true) && $queueTerminal) {
            $success = false;
        }
        $message = \trim((string)($activeOperation['message'] ?? ''));
        if ($timedOut) {
            // Comment removed.
                $message = (string)__('Operation completed.');
        } elseif ($message === '') {
            $message = $this->resolveObservedQueueMessage($queueRow, $success);
        }
        if ($queueStatus === 'done' && $sse->isAlive()) {
            $queueState = $this->buildQueueObserverPublicState($queueRow);
            $queueInfo = $this->buildQueueObserverPanelPayload($queueRow);
            $sse->sendEvent('info', [
                'message' => $message,
                'operation' => $operation,
                'queue_id' => (int)($queueRow['queue_id'] ?? 0),
                'queue_status' => $queueStatus,
                'queue_state' => $queueState,
                'queue_info' => $queueInfo,
                'state' => $state,
                'token_usage' => \is_array($queueState['token_usage'] ?? null) ? $queueState['token_usage'] : [],
                'progress_kind' => 'queue_info',
                'observer_detail' => true,
                'queue_panel_update' => true,
            ]);
        }

        if (!$timedOut && !$success && ($activeInProgress || $queueInProgress)) {
            return [
                'success' => true,
                    'message' => (string)__('Operation completed.'),
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

    private function streamDeferredQueueProgressUntilTerminal(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        int $queueId
    ): void {
        $stageCode = $this->resolveAiSiteQueueStage($operation);
        $lastQueueProcess = '';
        $lastQueueResultLength = 0;
        $lastQueueStatus = '';
        $lastQueuePid = 0;
        $lastTaskProgressStateSignature = '';
        $lastStageOnePageProgressSignature = '';
        $pollIntervalMs = self::OBSERVER_QUEUE_PROGRESS_POLL_INTERVAL_MS;
        $maxObserveCycles = (int)\ceil(self::OBSERVER_QUEUE_PROGRESS_MAX_OBSERVE_MS / \max(1, $pollIntervalMs));

        for ($observeCycle = 0; $observeCycle < $maxObserveCycles && $sse->isAlive(); $observeCycle++) {
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($fresh, $stageCode)
            );
            $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
            $effectiveQueueId = (int)($activeOperation['queue_id'] ?? 0);
            if ($effectiveQueueId <= 0) {
                $effectiveQueueId = $queueId;
            }
            $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, $effectiveQueueId, $operation === 'plan');
            if (\is_array($queueRow) && $queueRow !== []) {
                $queueId = (int)($queueRow['queue_id'] ?? $queueId);
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
            $this->emitObservedPlanStageOnePageProgressState(
                $sse,
                $queueRow,
                $operation,
                $lastStageOnePageProgressSignature
            );
            $this->emitObservedPlanJsonTaskProgressState(
                $sse,
                $scope,
                $operation,
                $queueRow,
                $lastTaskProgressStateSignature
            );

            if (!$this->isObservedQueueInProgress($queueRow)) {
                break;
            }

            $sse->maybeHeartbeat();
            SchedulerSystem::yieldDelay($pollIntervalMs);
        }

        if (!$sse->isAlive()) {
            return;
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, $queueId, $operation === 'plan');
        if ($this->isObservedQueueInProgress($queueRow)) {
            $sse->sendEvent('info', [
            'message' => (string)__('Operation completed.'),
                'operation' => $operation,
                'queue_id' => $queueId,
                'queue_status' => (string)($queueRow['status'] ?? 'running'),
                'queue_process' => (string)($queueRow['process'] ?? ''),
                'queue_state' => \is_array($queueRow) ? $this->buildQueueObserverPublicState($queueRow) : [],
                'queue_info' => \is_array($queueRow) ? $this->buildQueueObserverPanelPayload($queueRow) : [],
                'progress_kind' => 'queue_info',
                'observer_detail' => true,
                'deferred_queue_progress' => true,
                'queue_waiting_for_scheduler' => false,
                'can_close_stream' => false,
                'continue_other_operations' => true,
            ]);
            return;
        }

        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, false)
        );
        $queueStatus = \trim((string)($queueRow['status'] ?? ''));
        $success = $queueStatus === 'done';
        $message = $this->resolveObservedQueueMessage($queueRow, $success);
        if ($message === '') {
            $message = $success ? 'Queue operation completed.' : 'Queue operation stopped.';
        }
        $sse->complete([
            'success' => $success,
            'message' => $message,
            'operation' => $operation,
            'data' => $this->buildObservedOperationResultData($operation, $state),
            'state' => $state,
            'queue_status' => $queueStatus,
        ]);
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
        string $stageCode,
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

        $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        if (
            \in_array($activeStatus, ['pending', 'queued', 'running', 'processing'], true)
            || !\in_array($queueStatus, ['pending', 'queued', 'running', 'processing'], true)
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
            $this->sessionService->loadScopeForStage($fresh, $stageCode)
        );
        $activeOperation = $this->resolveActiveOperationForExecutionToken($scope, $executionToken);
        $queueRow = $this->findAiSiteOperationQueueRow($fresh, $operation, (int)($activeOperation['queue_id'] ?? $queueId), $operation === 'plan');
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

        $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        if ($this->isObservedQueueTerminal($queueRow)) {
            return false;
        }

        return \in_array($activeStatus, ['pending', 'queued', 'running', 'processing'], true)
            || $this->isObservedQueueInProgress($queueRow);
    }

    /**
     * Comment removed.
     * Comment removed.
     *
     * @param array<string, mixed>|null $queueRow
     */
    private function isObservedQueueTerminal(?array $queueRow): bool
    {
        if (!\is_array($queueRow) || $queueRow === []) {
            return false;
        }
        $status = \trim((string)($queueRow['status'] ?? ''));
        return \in_array($status, ['done', 'error', 'stop', 'cancelled', 'canceled'], true);
    }

    /**
     * @param array<string, mixed>|null $queueRow
     */
    private function isObservedQueueInProgress(?array $queueRow): bool
    {
        if (!\is_array($queueRow) || $queueRow === []) {
            return false;
        }
        if ($this->isObservedQueueTerminal($queueRow)) {
            return false;
        }
        $status = \trim((string)($queueRow['status'] ?? ''));
        if (\in_array($status, ['running', 'processing'], true)) {
            // Comment removed.
            // Comment removed.
            // Comment removed.
            return true;
        }

        return \in_array($status, ['pending', 'queued'], true);
    }

    /**
     * Comment removed.
     * @param array<string, mixed> $queueRow
     *
     * @return array<string, mixed>
     */
    private function buildQueueObserverPublicState(array $queueRow): array
    {
        return $this->queueStateService()->buildObserverPublicState($queueRow);
    }

    /**
     * Comment removed.
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
        $queueRow = $this->findAiSiteOperationQueueRow($session, $operation, $queueId, $operation === 'plan');
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
    private function PlanJsonStageQueueInfoPayload(AiSiteAgentSession $session, array $activeOperation): ?array
    {
        return $this->buildOperationStageQueueInfoPayload($session, $activeOperation, 'plan');
    }

    private function queueStateService(): AiSiteQueueStateService
    {
        return isset($this->queueStateService)
            ? $this->queueStateService
            : ObjectManager::getInstance(AiSiteQueueStateService::class);
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

    private function blockPartialPatchService(): AiSiteBlockPartialPatchService
    {
        if ($this->blockPartialPatchService === null) {
            $this->blockPartialPatchService = ObjectManager::getInstance(AiSiteBlockPartialPatchService::class);
        }

        return $this->blockPartialPatchService;
    }

    private function planJsonStateService(): AiSitePlanJsonStateService
    {
        if ($this->planJsonStateService === null) {
            $this->planJsonStateService = ObjectManager::getInstance(AiSitePlanJsonStateService::class);
        }

        return $this->planJsonStateService;
    }

    private function assetManifestService(): AiSiteAssetManifestService
    {
        return ObjectManager::getInstance(AiSiteAssetManifestService::class);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array{
     *   scope:array<string,mixed>,
     *   generated_slots:list<string>,
     *   failed_slots:list<array{slot_id:string,message:string}>
     * }
     */
    private function prepareBuildImageAssets(AiSiteAgentSession $session, int $adminId, array $scope): array
    {
        $scope['plan_json_task_summary'] = $this->planJsonTaskService->summarize($scope);
        if (\is_array($scope['build_summary'] ?? null)) {
            unset($scope['build_summary']['task_summary']);
        }
        if (!\array_key_exists('auto_generate_identity_assets_first', $scope)) {
            $scope['auto_generate_identity_assets_first'] = 0;
        }
        unset($scope['auto_asset_prebuild_identity_only']);

        /** @var AiSiteAutoAssetGenerationService $assetGenerationService */
        $assetGenerationService = ObjectManager::getInstance(AiSiteAutoAssetGenerationService::class);
        // Image generation is a design preference, not a build gate. Prepare the
        // manifest and prompts here, but leave actual image rendering to the
        // component/asset pipeline so the main build queue is not blocked by a
        // provider timeout.
        $assetGenerationLimit = 0;
        $result = $assetGenerationService->prepareBuildAssets($session, $adminId, $scope, $assetGenerationLimit);
        $resultScope = \is_array($result['scope'] ?? null) ? $result['scope'] : $scope;
        $resultScope['plan_json_task_summary'] = $this->planJsonTaskService->summarize($resultScope);
        if (\is_array($resultScope['build_summary'] ?? null)) {
            unset($resultScope['build_summary']['task_summary']);
        }

        return [
            'scope' => $resultScope,
            'generated_slots' => \array_values(\array_map('strval', \is_array($result['generated_slots'] ?? null) ? $result['generated_slots'] : [])),
            'failed_slots' => \array_values(\array_filter(
                \is_array($result['failed_slots'] ?? null) ? $result['failed_slots'] : [],
                static fn($row): bool => \is_array($row)
            )),
        ];
    }

    /**
     * @param list<array<string,mixed>> $failedSlots
     */
    private function assertBuildImageAssetsReady(array $failedSlots): void
    {
        if ($failedSlots === []) {
            return;
        }

        $messages = [];
        foreach ($failedSlots as $failedSlot) {
            $slotId = \trim((string)($failedSlot['slot_id'] ?? ''));
            $message = \trim((string)($failedSlot['message'] ?? ''));
            $messages[] = ($slotId !== '' ? $slotId : 'unknown_slot') . ($message !== '' ? ': ' . $message : '');
        }

        // Pre-build asset generation is opportunistic. A failed image slot must be
        // visible and retryable, but it must not prevent all block tasks from running.
        \w_log_warning('[AI Site Asset Prebuild] non-fatal failures before page build: ' . \implode('; ', \array_slice($messages, 0, 5)));
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
     * @param array<string, mixed> $activeOperation
     * @param array<string, array<string, mixed>|null> $queueInfoByOperation
     * @return array<string, mixed>
     */
    private function buildWorkspaceEntryQueueNotice(array $activeOperation, array $queueInfoByOperation): array
    {
        // Thin proxy to AiSiteAgentWorkspaceEntryNoticeService (R4.F5 Step C extraction).
        return $this->workspaceEntryNoticeService()->buildWorkspaceEntryQueueNotice(
            $activeOperation,
            $queueInfoByOperation,
            static fn (array $operation): string => '',
        );
    }

    /**
     * @param array<string, mixed> $queueRow
     *
     * @return array<string, mixed>
     */
    private function buildQueueObserverPanelPayload(array $queueRow): array
    {
        return $this->queueObserverHelperService()->buildPanelPayload($queueRow, $this->buildQueueObserverPublicState($queueRow));
    }

    /**
     * @param array<string, mixed> $queueInfo
     * @param array<string, mixed> $operationState
     * @return array<string, mixed>
     */
    private function markQueueInfoPayloadAsRecoveredForRetry(array $queueInfo, array $operationState): array
    {
        $message = \trim((string)($operationState['message'] ?? ''));
        if ($message === '') {
            $message = 'Linked queue process ended without a terminal queue status; retry is allowed.';
        }
        $queueInfo['queue_status'] = 'cancelled';
        $queueInfo['semantic_status'] = 'cancelled';
        $queueInfo['queue_terminal_recovered'] = 1;
        $queueInfo['retry_allowed'] = 1;
        $queueInfo['message'] = $message;
        if (\trim((string)($queueInfo['process'] ?? '')) === '') {
            $queueInfo['process'] = $message;
        }
        $queueInfo['finished'] = 1;
        if ((int)($operationState['queue_id'] ?? 0) > 0) {
            $queueInfo['queue_id'] = (int)$operationState['queue_id'];
        }
        unset($queueInfo['snapshot']);

        return $queueInfo;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function scopeHasPersistedStageOnePlan(array $scope): bool
    {
        return $this->scopeCompatibilityService->hasPersistedStageOnePlan($scope);
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
        if (!$this->isPlanJsonReadyForBuild($normalized)) {
            return $result;
        }
        if ((int)($taskSummary['total'] ?? 0) <= 0 || $this->countIncompletePlanJsonTasks($taskSummary) <= 0) {
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
                && \trim((string)($activeOperation['queue_status'] ?? '')) === 'cancelled'
            )
            || \trim((string)($activeBuildOperation['queue_status'] ?? '')) === 'cancelled'
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
            $freshScope = $this->normalizePlanJsonConfirmationForBuild($freshScope);
            $freshScope = $this->planJsonTaskService->reconcileGeneratedArtifactsWithTaskState($freshScope);
            $freshTaskSummary = $this->planJsonTaskService->summarize($freshScope);
            if ((int)($freshTaskSummary['total'] ?? 0) > 0) {
                $taskSummary = $freshTaskSummary;
                $result['task_summary'] = $freshTaskSummary;
                foreach (['content_manifest'] as $scopeKey) {
                    if (\array_key_exists($scopeKey, $freshScope)) {
                        $normalized[$scopeKey] = $freshScope[$scopeKey];
                    }
                }
                $result['normalized'] = $normalized;
            }
            $incompletePlanJsonTasks = $this->countIncompletePlanJsonTasks($taskSummary);
            if ($incompletePlanJsonTasks > 0 && \in_array($queueStatus, ['done', 'error', 'stop'], true)) {
                $now = \date('Y-m-d H:i:s');
                $failure = [
                    'blocked' => true,
                    'reason' => 'failed_plan_json_tasks',
                    'queue_id' => $queueId,
                    'queue_status' => $queueStatus,
                    'message' => 'Build queue reached terminal state while plan_json block work is still incomplete.',
                    'task_summary' => $taskSummary,
                ];
                $failedOperation = \array_replace(
                    \is_array($freshScope['active_operation'] ?? null) ? $freshScope['active_operation'] : $activeOperation,
                    [
                        'operation' => 'build',
                        'queue_status' => 'error',
                        'message' => (string)__(
                            'Build queue cannot continue: terminal queue state %{1} with %{2} incomplete plan_json block work.',
                            [$queueStatus !== '' ? $queueStatus : 'unknown', $incompletePlanJsonTasks]
                        ),
                        'progress_percent' => 100,
                        'queue_waiting_for_scheduler' => false,
                        'can_close_stream' => true,
                        'continue_other_operations' => false,
                        'failure_mode' => 'build_failed',
                        'retry_allowed' => 0,
                        'updated_at' => $now,
                    ]
                );
                $freshScope = $this->writeActiveOperationStateToScope($freshScope, $failedOperation);
                $freshScope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
                $freshScope['plan_json_task_summary'] = $taskSummary;
                $freshScope['latest_build_failed'] = 1;
                $freshScope['latest_build_failure'] = $failure;
                $freshScope['publish_blocked_by_latest_ai_failure'] = 1;
                $freshScope['publish_blocked_reason'] = $this->formatPublishBlockedByAiFailureMessage($failure);
                if (\is_array($freshScope['build_summary'] ?? null)) {
                    $freshScope['build_summary'] = \array_replace($freshScope['build_summary'], [
                        'active_operation' => 'build',
                        'can_publish' => false,
                        'last_failed_at' => $now,
                    ]);
                    unset($freshScope['build_summary']['task_summary']);
                }
                $this->sessionService->replaceScope($fresh->getId(), $adminId, $freshScope);

                $normalized = $freshScope;
                $result['normalized'] = $normalized;
                $result['active_operation'] = $failedOperation;
                $result['task_summary'] = $taskSummary;
                return $result;
            }
            if (!$this->isPlanJsonReadyForBuild($normalized) || $this->countIncompletePlanJsonTasks($taskSummary) <= 0) {
                if ($this->isPlanJsonReadyForBuild($normalized) && $this->countIncompletePlanJsonTasks($taskSummary) <= 0) {
                    $now = \date('Y-m-d H:i:s');
                    $doneOperation = \array_replace(
                        \is_array($freshScope['active_operation'] ?? null) ? $freshScope['active_operation'] : $activeOperation,
                        [
                            'operation' => 'build',
                            'queue_status' => 'done',
                            'message' => (string)__('Virtual theme generated'),
                            'progress_percent' => 100,
                            'queue_waiting_for_scheduler' => false,
                            'updated_at' => $now,
                        ]
                    );
                    $freshScope['active_operation'] = $doneOperation;
                    $activeOperations = \is_array($freshScope['active_operations'] ?? null) ? $freshScope['active_operations'] : [];
                    $activeOperations['build'] = $doneOperation;
                    $freshScope['active_operations'] = $activeOperations;
                    $freshScope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
                    $freshScope['build_summary'] = \array_replace(
                        \is_array($freshScope['build_summary'] ?? null) ? $freshScope['build_summary'] : [],
                        [
                            'active_operation' => 'build',
                            'can_publish' => true,
                            'last_generated_at' => $now,
                        ]
                    );
                    $freshScope['plan_json_task_summary'] = $taskSummary;
                    unset($freshScope['build_summary']['task_summary']);
                    $this->sessionService->replaceScope($fresh->getId(), $adminId, $freshScope);
                    $normalized = $freshScope;
                    $result['normalized'] = $normalized;
                    $result['active_operation'] = $doneOperation;
                }
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
            $queueWait = $this->buildAiSiteQueueSchedulerWaitPayload('build', $queueId, $reason);
        } else {
            return $result;
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
            (string)__('Detected unfinished plan_json block work; build queue has been resumed.'),
            [
                'operation' => 'build',
                'queue_id' => $queueId,
                'details' => [
                    'reason' => $reason,
                    'task_total' => (int)($taskSummary['total'] ?? 0),
                    'task_incomplete' => $this->countIncompletePlanJsonTasks($taskSummary),
                    'queue_wait' => $queueWait,
                ],
            ]
        );
        $this->logOperationSse('build_queue_auto_resume', [
            'public_id' => $session->getPublicId(),
            'reason' => $reason,
            'queue_id' => $queueId,
            'queue_status' => $queueStatus,
            'task_total' => (int)($taskSummary['total'] ?? 0),
            'task_incomplete' => $this->countIncompletePlanJsonTasks($taskSummary),
            'queue_wait' => $queueWait,
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
    private function countIncompletePlanJsonTasks(array $taskSummary): int
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
        foreach (['plan', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'publish'] as $operation) {
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
        $queueId = \is_array($buildQueueInfo) ? (int)($buildQueueInfo['queue_id'] ?? 0) : 0;
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
        $content = $this->decodeAiSiteQueueRowContent($queueRow);
        foreach ([
            $activeOperation['execution_token'] ?? '',
            $content['execution_token'] ?? '',
            $content['token'] ?? '',
            $this->extractAiSiteQueueContentString($queueRow, 'execution_token'),
            $this->extractAiSiteQueueContentString($queueRow, 'token'),
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
            'message' => (string)__('Detected unfinished plan_json block work; resuming the build queue from remaining tasks.'),
            'updated_at' => \date('Y-m-d H:i:s'),
            'details' => \array_replace($details, [
                'auto_resume_reason' => $reason,
                'resume_unfinished_tasks_only' => true,
            ]),
        ]);
    }

    /**
     * Comment removed.
     *
     * @param array<string, mixed> $activeOperation
     */
    private function shouldInspectOperationQueueInWorkspace(string $currentStage, array $activeOperation, string $operation): bool
    {
        // Comment removed.
        // Comment removed.
        $activeName = \trim((string)($activeOperation['operation'] ?? ''));
        $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        return $activeName === $operation && \in_array($activeStatus, ['queued', 'running', 'done', 'error', 'stop', 'cancelled', 'canceled'], true);
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
     * Comment removed.
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
     * @param array<string, mixed>|null $queueRow
     */
    private function emitObservedPlanStageOnePageProgressState(
        SseWriter $sse,
        ?array $queueRow,
        string $operation,
        string &$lastSignature
    ): void {
        if ($operation !== 'plan' || !$sse->isAlive() || !\is_array($queueRow) || $queueRow === []) {
            return;
        }

        $queueState = $this->buildQueueObserverPublicState($queueRow);
        $progress = \is_array($queueState['stage1_page_progress'] ?? null) ? $queueState['stage1_page_progress'] : [];
        if ($progress === []) {
            return;
        }

        $signature = \sha1((string)\json_encode($progress, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        if ($signature === $lastSignature) {
            return;
        }
        $lastSignature = $signature;

        $total = (int)($progress['total'] ?? 0);
        $done = (int)($progress['done_count'] ?? 0);
        $running = \max(0, (int)($progress['running_count'] ?? (\is_array($progress['running'] ?? null) ? \count($progress['running']) : 0)));
        $pending = \max(0, (int)($progress['pending_count'] ?? (\is_array($progress['pending'] ?? null) ? \count($progress['pending']) : 0)));
        $failed = \max(0, (int)($progress['failed_count'] ?? (\is_array($progress['failed'] ?? null) ? \count($progress['failed']) : 0)));
        $remaining = \array_key_exists('remaining_count', $progress)
            ? \max(0, (int)$progress['remaining_count'])
            : (($running + $pending) > 0 ? $running + $pending : \max(0, $total - $done - $failed));
        $progressPercent = $total > 0 ? 60 + (int)\floor(($done / \max(1, $total)) * 22) : (int)($queueRow['progress_percent'] ?? 0);
        $message = \trim((string)($queueRow['process'] ?? ''));
        if ($message === '' || \str_starts_with($message, 'Stage 1 page fanout:')) {
            $message = 'Stage 1 page fanout: total ' . $total . ' | done ' . $done . ' | remaining ' . $remaining;
        }
        $queueInfo = $this->queueObserverHelperService()->buildPanelPayload($queueRow, $queueState);

        $sse->sendEvent('progress', [
            'message' => $message,
            'operation' => 'plan',
            'progress_percent' => \max(0, \min(99, $progressPercent)),
            'progress_kind' => 'queue_info',
            'stage1_step' => 'plan_json_page',
            'stage1_phase' => 'fanout_progress',
            'stage1_page_progress' => $progress,
            'queue_id' => (int)($queueRow['queue_id'] ?? 0),
            'queue_status' => (string)($queueRow['status'] ?? ''),
            'queue_process' => $message,
            'queue_state' => $queueState,
            'queue_info' => $queueInfo,
            'observer_detail' => true,
            'queue_panel_update' => true,
        ]);
    }

    /**
     * Queue observers need a compact task-progress heartbeat. The queue row itself
     * is telemetry only; the authoritative task state remains in the session scope.
     *
     * @param array<string, mixed> $scope
     * @param array<string, mixed>|null $queueRow
     */
    private function emitObservedPlanJsonTaskProgressState(
        SseWriter $sse,
        array $scope,
        string $operation,
        ?array $queueRow,
        string &$lastSignature
    ): void {
        if ($operation !== 'build' || !$sse->isAlive()) {
            return;
        }

        $summary = $this->planJsonTaskService->summarize($scope);
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
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
            ? (string)__('Operation completed.')
            : (string)__('Operation failed.');
        $progressPercent = isset($activeOperation['progress_percent']) ? (int)$activeOperation['progress_percent'] : $this->resolveTaskSummaryProgressPercent($summary);

        $payload = $this->planJsonTaskProgressStatePayload(
            $summary,
            'build',
            $message,
            $progressPercent,
            $aiGenerating,
            $queueRow,
            $activeStatus
        );
        $this->emitTaskProgressStateEvent($sse, $payload);
    }

    /**
     * @param array<string, mixed> $summary
     * @param array<string, mixed>|null $queueRow
     * @return array<string, mixed>
     */
    private function planJsonTaskProgressStatePayload(
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
            'plan_json_task_summary' => $summary,
            'page_block_progress' => $this->planJsonTaskService->summarizePageBlockProgress($summary),
            'plan_json_block_progress' => $this->planJsonTaskProgressBlockRows($summary),
            'active_concurrency' => \max(0, (int)($summary['running'] ?? 0)),
            'ai_generating' => $aiGenerating,
            'active_operation_status' => $activeStatus,
        ];

        if (\is_array($queueRow) && $queueRow !== []) {
            $payload['queue_id'] = (int)($queueRow['queue_id'] ?? 0);
            $payload['queue_status'] = \trim((string)($queueRow['status'] ?? ''));
            $payload['queue_state'] = $this->buildQueueObserverPublicState($queueRow);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $summary
     * @return list<array<string, mixed>>
     */
    private function planJsonTaskProgressBlockRows(array $summary): array
    {
        $rows = [];
        foreach (\is_array($summary['groups'] ?? null) ? $summary['groups'] : [] as $groupKey => $group) {
            if (!\is_array($group)) {
                continue;
            }
            $pageType = \trim((string)($group['page_type'] ?? $groupKey));
            if ($pageType === '' || $pageType === 'shared') {
                continue;
            }
            foreach (\is_array($group['tasks'] ?? null) ? $group['tasks'] : [] as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskKey = \trim((string)($task['task_key'] ?? ''));
                $sectionKey = \trim((string)($task['section_code'] ?? ($task['component'] ?? $taskKey)));
                if ($taskKey === '' && $sectionKey === '') {
                    continue;
                }
                $rows[] = [
                    'page_type' => $pageType,
                    'block_id' => $taskKey,
                    'section_key' => $sectionKey,
                    'label' => \trim((string)($task['label'] ?? ($sectionKey !== '' ? $sectionKey : $taskKey))),
                    'status' => \trim((string)($task['status'] ?? 'pending')) ?: 'pending',
                    'message' => \trim((string)($task['message'] ?? '')),
                    'updated_at' => \trim((string)($task['updated_at'] ?? ($task['finished_at'] ?? ''))),
                ];
                if (\count($rows) >= 160) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * Comment removed.
     * Comment removed.
     * Comment removed.
     *
     * @param array<string, mixed> $payload
     */
    private function emitTaskProgressStateEvent(SseWriter $sse, array $payload): void
    {
        if (!$sse->isAlive()) {
            return;
        }
        $sse->sendEvent('task_progress', $payload);
        $sse->sendEvent('progress', $payload);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function emitPlanJsonTaskProgressStateFromScope(
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

        $summary = $this->planJsonTaskService->summarize($scope);
        if ((int)($summary['total'] ?? 0) <= 0) {
            return;
        }

        $this->emitTaskProgressStateEvent($sse, $this->planJsonTaskProgressStatePayload(
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
            'require_event_correlation' => $this->supportsBackgroundOperation($operation)
                && ($operationToken !== '' || $queueId > 0 || $jobKey !== '' || $jobType !== ''),
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
        if (\strlen($content) > self::AI_SITE_QUEUE_MAX_CONTENT_JSON_DECODE_BYTES) {
            return $this->extractAiSiteQueueContentEnvelope($content);
        }

        $decoded = \json_decode($content, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAiSiteQueueContentEnvelope(string $content): array
    {
        $summary = [];
        foreach (['public_id', 'job_key', 'job_type', 'status', 'token', 'execution_token', 'operation'] as $key) {
            $value = $this->extractJsonStringValue($content, $key);
            if ($value !== '') {
                $summary[$key] = $value;
            }
        }
        foreach (['admin_id', 'session_id'] as $key) {
            $value = $this->extractJsonIntegerValue($content, $key);
            if ($value !== null) {
                $summary[$key] = $value;
            }
        }

        return $summary;
    }

    private function extractAiSiteQueueContentString(?array $queueRow, string $key): string
    {
        if (!\is_array($queueRow)) {
            return '';
        }
        $content = $queueRow['content'] ?? null;
        if (\is_array($content)) {
            return \trim((string)($content[$key] ?? ''));
        }
        if (!\is_string($content) || \trim($content) === '') {
            return '';
        }
        if (\strlen($content) <= self::AI_SITE_QUEUE_MAX_CONTENT_JSON_DECODE_BYTES) {
            $decoded = \json_decode($content, true);
            if (\is_array($decoded)) {
                return \trim((string)($decoded[$key] ?? ''));
            }
        }

        return $this->extractJsonStringValue($content, $key);
    }

    private function extractJsonStringValue(string $json, string $key): string
    {
        if (\preg_match('/"' . \preg_quote($key, '/') . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $json, $match) !== 1) {
            return '';
        }
        $decoded = \json_decode('"' . $match[1] . '"');

        return \is_string($decoded) ? \trim($decoded) : '';
    }

    private function extractJsonIntegerValue(string $json, string $key): ?int
    {
        if (\preg_match('/"' . \preg_quote($key, '/') . '"\s*:\s*(\d+)/', $json, $match) !== 1) {
            return null;
        }

        return (int)$match[1];
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
                'message' => (string)__('Operation completed.'),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
            ],
            'info', 'warning', 'plan_saved', 'plan_generated', 'plan_refined', 'plan_rebuilt' => [
                'event_type' => $eventType,
                'message' => (string)($payload['message'] ?? ''),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
                'details' => $details,
                'state' => $state,
            ],
            'progress' => $this->buildObservedProgressPayload($payload),
            'ai_raw_chunk' => [
            'message' => (string)__('Operation completed.'),
                'operation' => (string)($payload['operation'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
                'suppressed_content' => true,
            ],
            'plan_chunk' => [
            'message' => (string)__('Operation completed.'),
                'operation' => (string)($payload['operation'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
                'suppressed_content' => true,
            ],
            'chunk' => [
            'message' => (string)__('Operation completed.'),
                'operation' => (string)($payload['operation'] ?? ''),
                'region' => (string)($details['region'] ?? $payload['region'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
                'suppressed_content' => true,
            ],
            'error' => [
                'message' => (string)__('Operation completed.'),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
                'details' => $details,
                'http_code' => isset($payload['code']) ? (int)$payload['code'] : 500,
            ],
            'operation_started' => [
                'message' => (string)__('Operation completed.'),
                'operation' => (string)($payload['operation'] ?? ''),
                'page_type' => (string)($payload['page_type'] ?? ''),
            ],
            'operation_progress' => $this->buildObservedProgressPayload($payload),
            'ai_chunk' => [
            'message' => (string)__('Operation completed.'),
                'operation' => (string)($payload['operation'] ?? ''),
                'region' => (string)($details['region'] ?? $payload['region'] ?? ''),
                'progress_percent' => isset($payload['progress_percent']) ? (int)$payload['progress_percent'] : 0,
                'suppressed_content' => true,
            ],
            'shared_component_generated' => $this->buildObservedSharedComponentPayload($session, $adminId, $payload, $details),
            'page_generated' => $this->buildObservedPageGeneratedPayload($session, $adminId, $payload, $details),
            'task_completed' => $this->buildObservedTaskCompletedPayload($session, $adminId, $payload, $details),
            'operation_failed' => [
                'message' => (string)__('Operation completed.'),
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
            'stage1_step',
            'stage1_phase',
            'ai_generating',
            'active_operation_status',
            'queue_id',
            'queue_status',
            'queue_state',
            'task_progress',
            'task_summary',
            'plan_json_task_summary',
            'page_total',
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
            return $this->buildWorkspaceEventStatePayload($payloadState);
        }

        if (!\in_array($eventType, [
            'plan_saved',
            'plan_generated',
            'plan_refined',
            'plan_rebuilt',
        ], true)) {
            return null;
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        return $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true)
        );
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
        $pagesByType = \is_array($state['materialized_pages_by_type'] ?? null) ? $state['materialized_pages_by_type'] : [];
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
        $payloadState = \is_array($payload['state'] ?? null)
            ? $this->buildWorkspaceEventStatePayload($payload['state'], $pageType !== '' ? [$pageType] : [])
            : $state;

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
            'state' => $payloadState,
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
                'page_types' => \array_values(\array_map('strval', \array_keys(\is_array($state['plan_json_pages'] ?? null) ? $state['plan_json_pages'] : []))),
            ],
            'publish' => [
                'publish_status' => (string)($state['publish_status'] ?? ''),
                'publish_verification' => \is_array($state['publish_verification'] ?? null) ? $state['publish_verification'] : [],
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
            ) {
                return 402;
            }
        }

        return 500;
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
     *   materialized_pages_by_type:array<string, array<string, mixed>>,
     *   plan_json_pages:array<string, array<string, mixed>>,
     *   preview_page_options:list<array<string, mixed>>,
     *   preview_page_id:int,
     *   preview_page_type:string,
     *   preview_full_url:string,
     *   visual_preview_url:string,
     *   visual_edit_url:string,
     *   pre_publish_visual_urls:array<string, string>,
     *   active_operation:array<string, mixed>,
     *   plan_queue_info:array<string, mixed>|null,
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
            $this->sessionService->loadScopeForStage(
                $session,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                $this->workspaceFastViewArtifactKeys($this->scopeCompatibilityService->normalizeStage($session->getStage()))
            )
        );
        $referenceImages = $this->resolveReferenceImagesFromScope($normalized);
        $normalized['reference_images'] = $referenceImages;
        $normalized = $this->scopeCompatibilityService->normalizeConfirmedPlanFlag($normalized);
        $normalized = $this->hydrateStageOnePlanPayloadFromPlanStageScope($session, $adminId, $normalized);
        // Comment removed.
        $profileStartedAt = \microtime(true);
        $normalized['website_profile'] = $this->profileGenerationService->generate($normalized, false);
        $this->logHotPathStage('build_workspace_state.website_profile', $profileStartedAt, [
            'public_id' => $session->getPublicId(),
        ]);
        $normalized['page_types'] = $this->scopeCompatibilityService->resolveScopedPageTypes($normalized);
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($normalized['workspace_track'] ?? ''));
        $normalized = $this->planJsonTaskService->ensureTaskScope(
            $normalized,
            \is_array($normalized['website_profile']) ? $normalized['website_profile'] : [],
            $workspaceTrack
        );
        $normalized = $this->normalizePlanJsonConfirmationForBuild($normalized);
        $assetManifestService = $this->assetManifestService();
        $assetManifest = ['version' => 1, 'slots' => []];
        if (!empty($normalized['plan_json']['confirmed'])
            || \is_array($normalized['asset_manifest'] ?? null)) {
            $assetManifest = $assetManifestService->syncFromPlanJson($normalized);
            $normalized['asset_manifest'] = $assetManifest;
            $normalized['verified_assets'] = $assetManifestService->extractVerifiedAssets($assetManifest);
        }
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
            $normalized['materialized_pages_by_type'] ?? []
        );
        $normalized['materialized_pages_by_type'] = $pagesByType;
        $normalized['preview_page_options'] = $this->scopeCompatibilityService->buildPreviewPageOptions($pagesByType);

        $virtualPagesStartedAt = \microtime(true);
        $planJson = \is_array($normalized['plan_json'] ?? null) ? $normalized['plan_json'] : [];
        $virtualPagesByType = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $virtualPagesByType = $this->decorateVirtualPagesWithUrls($session->getPublicId(), $virtualThemeId, $virtualPagesByType);
        $this->logHotPathStage('build_workspace_state.virtual_pages', $virtualPagesStartedAt, [
            'public_id' => $session->getPublicId(),
            'page_type_count' => \count($normalized['page_types']),
        ]);
        $planJson['pages'] = $virtualPagesByType;
        $normalized['plan_json'] = $planJson;
        $normalized = $this->planJsonTaskService->reconcileGeneratedArtifactsWithTaskState($normalized);
        $taskSummaryStartedAt = \microtime(true);
        $taskSummary = $this->planJsonTaskService->summarize($normalized);
        $this->logHotPathStage('build_workspace_state.task_summary', $taskSummaryStartedAt, [
            'public_id' => $session->getPublicId(),
            'task_total' => (int)($taskSummary['total'] ?? 0),
            'task_pending' => (int)($taskSummary['pending'] ?? 0),
        ]);
        $pendingGenerationPageTypes = $this->resolvePendingGenerationPageTypesFromTasks($normalized, $normalized['page_types']);
        if ($pendingGenerationPageTypes === []) {
            $pendingGenerationPageTypes = $this->resolvePendingGenerationPageTypes(
                $normalized['page_types'],
                $pagesByType,
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
        $prePublishVisualUrls = $this->normalizeAiSiteVisualUrlsToLocalBase($prePublishVisualUrls, [
            'scope' => $normalized,
            'plan_json_pages' => $virtualPagesByType,
            'preview_page_type' => $previewPageType,
        ]);
        $normalized['pre_publish_visual_urls'] = $prePublishVisualUrls;

        // Draft virtual-theme workspaces render from session scope; materialized Page URLs are only authoritative
        // after publishing or for non-virtual-theme tracks.
        if ($this->shouldUseWorkspacePreviewUrls($session, $workspaceTrack, $previewPageType, (int)$normalized['preview_page_id'])) {
            $normalized = \array_replace($normalized, $prePublishVisualUrls);
        } elseif ((int)$normalized['preview_page_id'] > 0) {
            $pageUrls = $this->normalizeAiSiteVisualUrlsToLocalBase(
                $this->visualUrlService->resolveUrls((int)$normalized['preview_page_id'], $virtualThemeId),
                [
                    'scope' => $normalized,
                    'plan_json_pages' => $virtualPagesByType,
                    'preview_page_type' => $previewPageType,
                ]
            );
            $normalized = \array_replace(
                $normalized,
                $pageUrls
            );
        } else {
            $normalized = \array_replace($normalized, $prePublishVisualUrls);
        }

        $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        $normalized = $this->discardForeignAiSiteQueueRuntimeState($normalized, (int)$session->getId());
        $activeOperation = \is_array($normalized['active_operation'] ?? null) ? $normalized['active_operation'] : [];
        $activeOperations = \is_array($normalized['active_operations'] ?? null) ? $normalized['active_operations'] : [];
        $supportedWorkspaceOperations = [
            'plan' => true,
            'build' => true,
            'publish' => true,
            'regenerate_page' => true,
            'block_regenerate' => true,
            'block_partial_patch' => true,
            'image_asset' => true,
        ];
        if (!isset($supportedWorkspaceOperations[\trim((string)($activeOperation['operation'] ?? ''))])) {
            $activeOperation = [];
        }
        $activeOperations = \array_intersect_key($activeOperations, $supportedWorkspaceOperations);
        $planOperation = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, 'plan');
        $buildOperation = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, 'build');
        $publishOperation = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, 'publish');
        $existingPlanQueueInfo = \is_array($normalized['plan_queue_info'] ?? null) ? $normalized['plan_queue_info'] : null;
        $existingBuildQueueInfo = \is_array($normalized['build_queue_info'] ?? null) ? $normalized['build_queue_info'] : null;
        $existingBuildQueueOperation = \is_array($existingBuildQueueInfo)
            ? $this->workspaceStateHelperService()->resolveStatusQueueInfoOperation($existingBuildQueueInfo)
            : '';
        $sharedBuildQueueOperations = [
            'build' => true,
            'publish' => true,
            'regenerate_page' => true,
            'block_regenerate' => true,
            'block_partial_patch' => true,
            'image_asset' => true,
        ];
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        $buildQueueOperationCandidate = '';
        foreach ([$activeOperationName, $existingBuildQueueOperation] as $operationCandidate) {
            $operationCandidate = \trim((string)$operationCandidate);
            if ($operationCandidate !== '' && isset($sharedBuildQueueOperations[$operationCandidate])) {
                $buildQueueOperationCandidate = $operationCandidate;
                break;
            }
        }
        if ($buildQueueOperationCandidate === '') {
            foreach (['publish', 'image_asset', 'block_partial_patch', 'block_regenerate', 'regenerate_page', 'build'] as $operationCandidate) {
                if (\is_array($activeOperations[$operationCandidate] ?? null) && $activeOperations[$operationCandidate] !== []) {
                    $buildQueueOperationCandidate = $operationCandidate;
                    break;
                }
            }
        }
        $buildQueueOperation = 'build';
        $buildQueueOperationState = $buildOperation;
        if ($buildQueueOperationCandidate !== '' && $buildQueueOperationCandidate !== 'build') {
            $buildQueueOperation = $buildQueueOperationCandidate;
            $buildQueueOperationState = $this->resolveWorkspaceQueueOperationState(
                $activeOperation,
                $activeOperations,
                $buildQueueOperationCandidate
            );
        }
        $shouldInspectPlanQueue = $this->shouldInspectOperationQueueInWorkspace($stage, $planOperation, 'plan')
            || (
                \is_array($existingPlanQueueInfo)
                && \in_array($this->readAiQueueInfoStatus($existingPlanQueueInfo), ['pending', 'queued', 'running', 'processing'], true)
            );
        $shouldInspectBuildQueue = $this->shouldInspectOperationQueueInWorkspace($stage, $buildQueueOperationState, $buildQueueOperation)
            || (
                \is_array($existingBuildQueueInfo)
                && \in_array($this->readAiQueueInfoStatus($existingBuildQueueInfo), ['pending', 'queued', 'running', 'processing'], true)
            );
        $planQueueInfo = $shouldInspectPlanQueue
            ? $this->PlanJsonStageQueueInfoPayload($session, $planOperation)
            : $existingPlanQueueInfo;
        if ($planQueueInfo === null) {
            $planQueueInfo = $existingPlanQueueInfo;
        }
        $buildQueueInfo = $shouldInspectBuildQueue
            ? $this->buildOperationStageQueueInfoPayload($session, $buildQueueOperationState, $buildQueueOperation)
            : $existingBuildQueueInfo;
        if ($buildQueueInfo === null) {
            $buildQueueInfo = $existingBuildQueueInfo;
        }
        if (\is_array($buildQueueInfo) && $buildQueueInfo !== []) {
            $resolvedBuildQueueOperation = $this->workspaceStateHelperService()->resolveStatusQueueInfoOperation($buildQueueInfo);
            if ($resolvedBuildQueueOperation === 'publish') {
                $buildQueueOperation = 'publish';
                $buildQueueOperationState = $publishOperation;
            } elseif ($resolvedBuildQueueOperation !== '' && $resolvedBuildQueueOperation !== 'plan') {
                $buildQueueOperation = $resolvedBuildQueueOperation;
                $buildQueueOperationState = $this->resolveWorkspaceQueueOperationState($activeOperation, $activeOperations, $resolvedBuildQueueOperation);
            }
        }
        foreach ([
            'plan' => [$planOperation, $planQueueInfo],
            $buildQueueOperation => [$buildQueueOperationState, $buildQueueInfo],
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
        if ($persist) {
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
        }
        $completionGateForWorkspace = $this->planJsonTaskService->inspectBuildCompletionGate($normalized);
        $completionGateForWorkspacePassed = !empty($completionGateForWorkspace['passed']);
        if ($completionGateForWorkspacePassed) {
            $now = \date('Y-m-d H:i:s');
            $taskSummary = \is_array($completionGateForWorkspace['summary'] ?? null)
                ? $completionGateForWorkspace['summary']
                : $taskSummary;
            $pendingGenerationPageTypes = [];
        $doneMessage = (string)__('Operation completed.');
            $buildDoneState = [
                'operation' => 'build',
                'queue_status' => 'done',
                'message' => $doneMessage,
                'updated_at' => $now,
                'finished_at' => $now,
                'progress_percent' => 100,
                'failure_mode' => '',
                'retry_allowed' => 0,
                'retryable_ai_failure_count' => 0,
                'queue_waiting_for_scheduler' => false,
                'can_close_stream' => false,
                'continue_other_operations' => false,
            ];
            $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
            if ($activeOperation === [] || \in_array($activeOperationName, ['build', 'visual_edit'], true)) {
                $activeOperation = \array_replace($activeOperation, $buildDoneState);
            }
            $activeOperations['build'] = \array_replace(
                \is_array($activeOperations['build'] ?? null) ? $activeOperations['build'] : [],
                $buildDoneState
            );
            $normalized['active_operation'] = $activeOperation;
            $normalized['active_operations'] = $activeOperations;
            $buildQueueInfo = \array_replace(\is_array($buildQueueInfo) ? $buildQueueInfo : [], [
                'queue_status' => 'done',
                'message' => $doneMessage,
                'process' => $doneMessage,
            ]);
            unset($buildQueueInfo['snapshot']);
            $normalized['build_queue_info'] = $buildQueueInfo;
        }
        $workspaceEntryQueueNotice = $this->buildWorkspaceEntryQueueNotice($activeOperation, [
            'plan' => $planQueueInfo,
            $buildQueueOperation => $buildQueueInfo,
        ]);
        $titleOk = \trim((string)($normalized['website_profile']['site_title'] ?? '')) !== '';
        $normalized = $this->planJsonTaskService->syncPlanJsonTaskFailuresToRetryableLedger($normalized);
        $normalized = $this->clearSupersededAiOperationFailures($normalized, $activeOperation);
        $activeOperation = \is_array($normalized['active_operation'] ?? null) ? $normalized['active_operation'] : [];
        $activeOperations = \is_array($normalized['active_operations'] ?? null) ? $normalized['active_operations'] : [];
        $taskSummary = $this->planJsonTaskService->summarize($normalized);
        $retryableAiFailureSummary = $this->planJsonTaskService->summarizeRetryableAiFailures($normalized);
        $planRetryableAiFailureSummary = $this->planJsonTaskService->summarizeRetryableAiFailures($normalized, 'plan');
        $buildRetryableAiFailureSummary = $this->planJsonTaskService->summarizeRetryableAiFailures($normalized, 'build');
        $hasRetryableAiFailures = (int)($retryableAiFailureSummary['count'] ?? 0) > 0;
        $hasPublishBlockingRetryableAiFailures = (int)($planRetryableAiFailureSummary['count'] ?? 0) > 0
            || (int)($buildRetryableAiFailureSummary['count'] ?? 0) > 0;
        $taskReady = $this->taskSummaryIndicatesCompleted($taskSummary);
        $published = $session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED
            || (string)($normalized['workspace_status'] ?? '') === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        $canPublish = $published || ($completionGateForWorkspacePassed && $taskReady && $titleOk);
        if ($hasPublishBlockingRetryableAiFailures) {
            $canPublish = false;
        }
        $activeOperationStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        if (
            \in_array($activeOperationStatus, ['queued', 'running'], true)
            && $this->shouldReclaimStaleActiveOperation($activeOperation)
            && $this->isActiveOperationStale($activeOperation)
        ) {
            $activeOperation = \array_replace($activeOperation, [
                'queue_status' => 'cancelled',
                    'message' => (string)__('Operation completed.'),
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
            $activeOperation['queue_status'] = 'done';
            $normalized['active_operation'] = $activeOperation;
        }
        $activeOperationStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        $publishBlockingBuildFailure = $this->resolveLatestPublishBlockingAiBuildFailure($normalized, $activeOperation, $buildQueueInfo);
        $publishBlockingAiRunning = $this->hasPublishBlockingAiBuildRunningState($normalized, $activeOperation, $buildQueueInfo);
        if (!empty($publishBlockingBuildFailure['blocked']) || $publishBlockingAiRunning) {
            $canPublish = false;
        }
        $normalized['latest_build_failed'] = !empty($publishBlockingBuildFailure['blocked']) ? 1 : 0;
        $normalized['latest_build_failure'] = !empty($publishBlockingBuildFailure['blocked']) ? $publishBlockingBuildFailure : [];
        $normalized['publish_blocked_by_latest_ai_failure'] = !empty($publishBlockingBuildFailure['blocked']) ? 1 : 0;
        $normalized['publish_blocked_reason'] = !empty($publishBlockingBuildFailure['blocked'])
            ? $this->formatPublishBlockedByAiFailureMessage($publishBlockingBuildFailure)
            : '';
        if (!empty($publishBlockingBuildFailure['blocked'])) {
            $normalized = $this->planJsonTaskService->syncPlanJsonTaskFailuresToRetryableLedger($normalized);
        }
        $workspaceStatus = $this->resolveWorkspaceStatus($session, $normalized, $canPublish, $pendingGenerationPageTypes);
        if (!empty($publishBlockingBuildFailure['blocked'])) {
            $workspaceStatus = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        }
        $normalized['workspace_status'] = $workspaceStatus;
        $normalized['can_publish'] = $canPublish ? 1 : 0;
        $normalized['build_summary'] = $this->buildSummary($virtualPagesByType, $activeOperation, $canPublish, $pendingGenerationPageTypes, $taskSummary);
        $hasStageOnePlan = $this->scopeCompatibilityService->hasPersistedStageOnePlan($normalized);
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

        $clientScope = $normalized;
        $clientScope['reference_images'] = $referenceImages;
        unset(
            $clientScope['plan_markdown'],
            $clientScope['plan_projection'],
            $clientScope['pagebuilder_pages_by_type'],
            $clientScope['page_type_layouts'],
            $clientScope['virtual_pages_by_type'],
            $clientScope['materialized_pages_by_type']
        );
        $designDirectionState = $this->designDirectionService()->buildWorkspaceDirectionState($normalized);
        $designDirectionStateForView = $this->pruneWorkspaceDesignDirectionStateForView($designDirectionState);
        unset($clientScope['_ai_generated_shared_components']);
        unset($clientScope['shared_components']);

        $result = [
            'public_id' => $session->getPublicId(),
            'stage' => $stage,
            'stage_label' => $this->getStageLabel($stage),
            'workspace_status' => $workspaceStatus,
            'publish_status' => $session->getPublishStatus(),
            'can_publish' => $canPublish,
            'retryable_ai_failures' => \is_array($normalized['retryable_ai_failures'] ?? null) ? $normalized['retryable_ai_failures'] : [],
            'retryable_ai_failure_count' => (int)($normalized['retryable_ai_failure_count'] ?? ($retryableAiFailureSummary['count'] ?? 0)),
            'next_stage_blocked_by_ai_failures' => $hasPublishBlockingRetryableAiFailures ? 1 : 0,
            'retryable_ai_failure_summary' => $retryableAiFailureSummary,
            'latest_build_failed' => !empty($normalized['latest_build_failed']),
            'latest_build_failure' => \is_array($normalized['latest_build_failure'] ?? null) ? $normalized['latest_build_failure'] : [],
            'publish_blocked_by_latest_ai_failure' => !empty($normalized['publish_blocked_by_latest_ai_failure']),
            'publish_blocked_reason' => (string)($normalized['publish_blocked_reason'] ?? ''),
            'workspace_track' => $workspaceTrack,
            'website_id' => $draftWebsiteId,
            'virtual_theme_id' => $virtualThemeId,
            'website_profile' => \is_array($normalized['website_profile']) ? $normalized['website_profile'] : [],
            'design_direction' => $designDirectionStateForView,
            'design_direction_snapshot' => \is_array($designDirectionStateForView['snapshot'] ?? null) ? $designDirectionStateForView['snapshot'] : [],
            'design_direction_match_reason' => (string)($designDirectionState['match_reason'] ?? ''),
            'design_direction_locked' => !empty($designDirectionState['locked']) ? 1 : 0,
            'design_direction_code' => (string)($designDirectionState['code'] ?? ''),
            'draft_website_id' => $draftWebsiteId,
            'pending_generation_page_types' => $pendingGenerationPageTypes,
            'plan_json_task_summary' => $taskSummary,
            'plan' => [
                'json' => \is_array($normalized['plan_json'] ?? null) ? $normalized['plan_json'] : [],
            ],
            'plan_json' => \is_array($normalized['plan_json'] ?? null) ? $normalized['plan_json'] : [],
            'stage1_validation_report' => \is_array($normalized['stage1_validation_report'] ?? null) ? $normalized['stage1_validation_report'] : [],
            'stage1_first_pass' => (int)($normalized['stage1_first_pass'] ?? 0),
            'stage1_generation_attempts' => \is_array($normalized['stage1_generation_attempts'] ?? null) ? $normalized['stage1_generation_attempts'] : [],
            'stage1_visual_qa_report' => \is_array($normalized['stage1_visual_qa_report'] ?? null) ? $normalized['stage1_visual_qa_report'] : [],
            'has_stage_one_plan' => $hasStageOnePlan,
            'content_manifest' => \is_array($normalized['content_manifest'] ?? null) ? $normalized['content_manifest'] : [],
            'plan_sse_url' => $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/plan-sse'),
            'refine_plan_page_url' => $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-refine-plan-page'),
            // Comment removed.
            'confirmed_plan_signature' => $this->resolveConfirmedPlanSignature($normalized),
            'has_pending_plan_json_tasks' => (int)($taskSummary['pending'] ?? 0) > 0,
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
            'build_queue_info' => $buildQueueInfo,
            'workspace_entry_notice' => $workspaceEntryQueueNotice,
            'build_summary' => $this->buildSummaryWithoutTaskSummary($normalized),
            'asset_manifest' => \is_array($normalized['asset_manifest'] ?? null) ? $normalized['asset_manifest'] : ['version' => 1, 'slots' => []],
            'asset_image_generation_failures' => \is_array($normalized['asset_image_generation_failures'] ?? null)
                ? $normalized['asset_image_generation_failures']
                : [],
            'verified_assets' => \is_array($normalized['verified_assets'] ?? null) ? $normalized['verified_assets'] : [],
            'reference_images' => $referenceImages,
            'top_logs' => $topLogs,
            'scope' => $clientScope,
            'events' => $events,
            'last_event_id' => $this->resolveWorkspaceLastEventId($events),
        ];

        $planStartDecision = $this->resolvePlanStartDecision($normalized);
        $missingPlanPageTypes = $this->planJsonTaskService->collectMissingSelectedPlanPageTypes($normalized);
        $result['plan_rebuild_required'] = !empty($planStartDecision['rebuild_required']);
        $result['plan_translation_required'] = !empty($planStartDecision['translation_required']);
        $result['plan_source_changed'] = !empty($planStartDecision['source_signature_changed']);
        $result['plan_locale_changed'] = !empty($planStartDecision['plan_locale_changed']);
        $result['plan_page_types_changed'] = !empty($planStartDecision['page_types_changed']);
        $result['plan_missing_page_types'] = $missingPlanPageTypes;
        $result['plan_input_stale'] = !empty($planStartDecision['rebuild_required'])
            || !empty($planStartDecision['source_signature_changed'])
            || !empty($planStartDecision['translation_required']);

        $this->logHotPathStage('build_workspace_state.total', $startedAt, [
            'public_id' => $session->getPublicId(),
            'event_limit' => $eventLimit,
            'persist' => $persist ? 1 : 0,
            'response_event_count' => \count($events),
        ]);

        return $this->decorateWorkspaceStateWithPollingPayload($result);
    }

    /**
     * Copy the already persisted plan-stage payload into the current workspace stage.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function hydrateStageOnePlanPayloadFromPlanStageScope(
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $hasStageOnePayload = (\is_array($scope['plan_json'] ?? null) && $scope['plan_json'] !== [])
            || \trim((string)($scope['plan_markdown'] ?? '')) !== '';
        if ($hasStageOnePayload) {
            return $scope;
        }

        $planStageScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_PLAN,
                ['plan_json', 'plan_markdown']
            )
        );
        if ($planStageScope === [] || $planStageScope === $scope) {
            return $scope;
        }

        foreach ([
            'plan_json',
            'plan_markdown',
            'plan_generated_at',
        ] as $key) {
            if (!\array_key_exists($key, $scope) || $scope[$key] === [] || $scope[$key] === '' || $scope[$key] === 0 || $scope[$key] === null) {
                if (\array_key_exists($key, $planStageScope)) {
                    $scope[$key] = $planStageScope[$key];
                }
            }
        }

        return $scope;
    }

    /**
     * Polling responses keep the full workspace state for existing UI hydration,
     * but expose the same compact status-envelope fields that SSE state payloads
     * carry. This keeps SSE and `post-workspace-state` aligned to one truth
     * source without replacing the polling response shape.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function decorateWorkspaceStateWithPollingPayload(array $state): array
    {
        return \array_replace($state, $this->buildWorkspaceStatusEnvelope($state, 'poller'));
    }

    /**
     * `post-start-plan` should not hydrate the complete workspace just to ask for
     * confirmation or resume a queued operation. The page already owns full plan
     * content; this patch carries only status fields needed by the UI.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildStartPlanLightweightStatePatch(
        AiSiteAgentSession $session,
        array $scope,
        bool $hasStageOnePlan
    ): array {
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $workspaceStatus = \trim((string)($scope['workspace_status'] ?? ''));
        if ($workspaceStatus === '') {
            $workspaceStatus = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
        }
        $publishStatus = (string)$session->getPublishStatus();
        $canPublish = !empty($scope['can_publish'])
            || $workspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
            || $publishStatus === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED;

        $state = [
            'public_id' => (string)$session->getPublicId(),
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'workspace_status' => $workspaceStatus,
            'publish_status' => $publishStatus,
            'can_publish' => $canPublish,
            'latest_build_failed' => !empty($scope['latest_build_failed']),
            'latest_build_failure' => \is_array($scope['latest_build_failure'] ?? null) ? $scope['latest_build_failure'] : [],
            'publish_blocked_by_latest_ai_failure' => !empty($scope['publish_blocked_by_latest_ai_failure']),
            'publish_blocked_reason' => (string)($scope['publish_blocked_reason'] ?? ''),
            'active_operation' => $activeOperation,
            'confirmed_plan_signature' => (string)($scope['confirmed_plan_signature'] ?? ''),
            'has_stage_one_plan' => $hasStageOnePlan,
        ];
        $executionToken = \trim((string)($activeOperation['execution_token'] ?? ''));
        if ($executionToken !== '') {
            $state['plan_sse_url'] = $this->buildOperationStreamUrl($session->getPublicId(), $executionToken);
        }

        return \array_replace($state, $this->buildWorkspaceStatusEnvelope($state, 'queue'));
    }

    /**
     * Confirmation requests only need a compact state patch for the current UI.
     * Sending the full workspace state here resends large plan/scope/event
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
            'latest_build_failed' => !empty($state['latest_build_failed']),
            'latest_build_failure' => \is_array($state['latest_build_failure'] ?? null) ? $state['latest_build_failure'] : [],
            'publish_blocked_by_latest_ai_failure' => !empty($state['publish_blocked_by_latest_ai_failure']),
            'publish_blocked_reason' => (string)($state['publish_blocked_reason'] ?? ''),
            'workspace_track' => (string)($state['workspace_track'] ?? ''),
            'website_id' => (int)($state['website_id'] ?? 0),
            'virtual_theme_id' => (int)($state['virtual_theme_id'] ?? 0),
            'draft_website_id' => (int)($state['draft_website_id'] ?? 0),
            'active_operation' => \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [],
            'plan_json_task_summary' => \is_array($state['plan_json_task_summary'] ?? null) ? $state['plan_json_task_summary'] : [],
            'build_summary' => $this->buildSummaryWithoutTaskSummary($state),
            'pending_generation_page_types' => \is_array($state['pending_generation_page_types'] ?? null) ? $state['pending_generation_page_types'] : [],
            'plan_queue_info' => \is_array($state['plan_queue_info'] ?? null) ? $state['plan_queue_info'] : null,
            'build_queue_info' => \is_array($state['build_queue_info'] ?? null) ? $state['build_queue_info'] : null,
        ], $this->buildWorkspaceStatusEnvelope($state, 'confirm'));

        if ($type === 'plan') {
            $payload['confirmed_plan_signature'] = (string)($state['confirmed_plan_signature'] ?? '');
            $payload['has_stage_one_plan'] = !empty($state['has_stage_one_plan']);
            $payload['plan_sse_url'] = (string)($state['plan_sse_url'] ?? '');
            $payload['refine_plan_page_url'] = (string)($state['refine_plan_page_url'] ?? '');
            return $payload;
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
            'latest_build_failed' => !empty($state['latest_build_failed']),
            'latest_build_failure' => \is_array($state['latest_build_failure'] ?? null) ? $state['latest_build_failure'] : [],
            'publish_blocked_by_latest_ai_failure' => !empty($state['publish_blocked_by_latest_ai_failure']),
            'publish_blocked_reason' => (string)($state['publish_blocked_reason'] ?? ''),
            'workspace_track' => (string)($state['workspace_track'] ?? ''),
            'website_id' => (int)($state['website_id'] ?? 0),
            'virtual_theme_id' => (int)($state['virtual_theme_id'] ?? 0),
            'draft_website_id' => (int)($state['draft_website_id'] ?? 0),
            'preview_page_id' => (int)($state['preview_page_id'] ?? 0),
            'preview_page_type' => (string)($state['preview_page_type'] ?? ''),
            'preview_full_url' => (string)($state['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($state['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($state['visual_edit_url'] ?? ''),
            'pre_publish_visual_urls' => \is_array($state['pre_publish_visual_urls'] ?? null) ? $state['pre_publish_visual_urls'] : [],
            'preview_page_options' => \is_array($state['preview_page_options'] ?? null) ? $state['preview_page_options'] : [],
            'active_operation' => \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [],
            'confirmed_plan_signature' => (string)($state['confirmed_plan_signature'] ?? ''),
            'has_stage_one_plan' => !empty($state['has_stage_one_plan']),
            'has_pending_plan_json_tasks' => !empty($state['has_pending_plan_json_tasks']),
            'plan_sse_url' => (string)($state['plan_sse_url'] ?? ''),
            'refine_plan_page_url' => (string)($state['refine_plan_page_url'] ?? ''),
            'plan_queue_info' => \is_array($state['plan_queue_info'] ?? null) ? $state['plan_queue_info'] : null,
            'build_queue_info' => \is_array($state['build_queue_info'] ?? null) ? $state['build_queue_info'] : null,
            'plan_json_task_summary' => \is_array($state['plan_json_task_summary'] ?? null) ? $state['plan_json_task_summary'] : [],
            'build_summary' => $this->buildSummaryWithoutTaskSummary($state),
            'pending_generation_page_types' => \is_array($state['pending_generation_page_types'] ?? null) ? $state['pending_generation_page_types'] : [],
            'website_profile' => \is_array($state['website_profile'] ?? null) ? $state['website_profile'] : [],
            'asset_manifest' => \is_array($state['asset_manifest'] ?? null) ? $state['asset_manifest'] : ['version' => 1, 'slots' => []],
            'verified_assets' => \is_array($state['verified_assets'] ?? null) ? $state['verified_assets'] : [],
            'reference_images' => \is_array($state['reference_images'] ?? null) ? $state['reference_images'] : [],
            'workspace_entry_queue_notice' => \is_array($state['workspace_entry_queue_notice'] ?? null) ? $state['workspace_entry_queue_notice'] : [],
        ], $this->buildWorkspaceStatusEnvelope($state, 'queue'));

        $payload['response_mode'] = 'compact_operation';
        $payload['response_operation'] = $operation;

        return $payload;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildWorkspaceStreamStatePayload(array $state): array
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
        $payload = \array_replace([
            'public_id' => (string)($state['public_id'] ?? ''),
            'stage' => (string)($state['stage'] ?? ''),
            'stage_label' => (string)($state['stage_label'] ?? ''),
            'workspace_status' => (string)($state['workspace_status'] ?? ''),
            'publish_status' => (string)($state['publish_status'] ?? ''),
            'can_publish' => !empty($state['can_publish']),
            'latest_build_failed' => !empty($state['latest_build_failed']),
            'latest_build_failure' => \is_array($state['latest_build_failure'] ?? null) ? $state['latest_build_failure'] : [],
            'publish_blocked_by_latest_ai_failure' => !empty($state['publish_blocked_by_latest_ai_failure']),
            'publish_blocked_reason' => (string)($state['publish_blocked_reason'] ?? ''),
            'workspace_track' => (string)($state['workspace_track'] ?? ''),
            'website_id' => (int)($state['website_id'] ?? 0),
            'virtual_theme_id' => (int)($state['virtual_theme_id'] ?? 0),
            'draft_website_id' => (int)($state['draft_website_id'] ?? 0),
            'has_stage_one_plan' => !empty($state['has_stage_one_plan']),
            'content_manifest' => \is_array($state['content_manifest'] ?? null) ? $state['content_manifest'] : [],
            'preview_page_options' => \is_array($state['preview_page_options'] ?? null) ? $state['preview_page_options'] : [],
            'preview_page_id' => (int)($state['preview_page_id'] ?? 0),
            'preview_page_type' => (string)($state['preview_page_type'] ?? ''),
            'preview_full_url' => (string)($state['preview_full_url'] ?? ''),
            'visual_preview_url' => (string)($state['visual_preview_url'] ?? ''),
            'visual_edit_url' => (string)($state['visual_edit_url'] ?? ''),
            'pre_publish_visual_urls' => \is_array($state['pre_publish_visual_urls'] ?? null) ? $state['pre_publish_visual_urls'] : [],
            'active_operation' => \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [],
            'plan_queue_info' => \is_array($state['plan_queue_info'] ?? null) ? $state['plan_queue_info'] : null,
            'build_queue_info' => \is_array($state['build_queue_info'] ?? null) ? $state['build_queue_info'] : null,
            'plan_json_task_summary' => \is_array($state['plan_json_task_summary'] ?? null) ? $state['plan_json_task_summary'] : [],
            'build_summary' => $this->buildSummaryWithoutTaskSummary($state),
            'pending_generation_page_types' => \is_array($state['pending_generation_page_types'] ?? null) ? $state['pending_generation_page_types'] : [],
        ], $this->buildWorkspaceStatusEnvelope($state, 'queue'));

        if ($workspaceStream) {
            $plan = \is_array($state['plan'] ?? null) ? $state['plan'] : [];
            $planJson = \is_array($plan['json'] ?? null)
                ? $plan['json']
                : (\is_array($state['plan_json'] ?? null) ? $state['plan_json'] : []);
            if ($planJson !== []) {
                $payload['plan'] = [
                    'json' => $planJson,
                ];
                $payload['plan_json'] = $planJson;
                $payload['confirmed_plan_signature'] = (string)($state['confirmed_plan_signature'] ?? '');
            }
            $payload['auto_start_build_after_stream'] = !empty($state['auto_start_build_after_stream']);
            $payload['last_event_id'] = $this->resolveWorkspaceLastEventId(\is_array($state['events'] ?? null) ? $state['events'] : []);
            $payload['events'] = $this->pruneWorkspaceEventsForSse(\is_array($state['events'] ?? null) ? $state['events'] : [], 6);
            $payload['top_logs'] = $this->pruneWorkspaceEventsForSse(\is_array($state['top_logs'] ?? null) ? $state['top_logs'] : [], 6);
            return $payload;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function buildSummaryWithoutTaskSummary(array $state): array
    {
        return \array_diff_key(
            \is_array($state['build_summary'] ?? null) ? $state['build_summary'] : [],
            ['task_summary' => true]
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function stripBuildSummaryNestedTaskSummary(array $payload): array
    {
        if (\is_array($payload['build_summary'] ?? null)) {
            unset($payload['build_summary']['task_summary']);
        }
        if (\is_array($payload['scope_patch'] ?? null)) {
            $payload['scope_patch'] = $this->stripBuildSummaryNestedTaskSummary($payload['scope_patch']);
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

    private function isPublishBlockingAiBuildOperation(string $operation): bool
    {
        return \in_array(\strtolower(\trim($operation)), ['build', 'visual_edit', 'regenerate_page', 'block_regenerate', 'block_partial_patch'], true);
    }

    private function isPublishBlockingAiFailureStatus(string $status): bool
    {
        return \in_array(\strtolower(\trim($status)), ['error', 'failed', 'fail', 'stop', 'stopped', 'cancelled', 'canceled'], true);
    }

    private function isPublishBlockingAiRunningStatus(string $status): bool
    {
        return \in_array(\strtolower(\trim($status)), ['queued', 'pending', 'running', 'processing'], true);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @return array<string, mixed>
     */
    private function clearSupersededAiOperationFailures(array $scope, array $activeOperation): array
    {
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        $successfulOperations = $this->collectSuccessfulAiOperations($activeOperations, $activeOperation);
        if ($successfulOperations === []) {
            return $scope;
        }

        foreach ($activeOperations as $operationKey => $operationState) {
            if (!\is_array($operationState) || !$this->isSupersededAiOperationFailure($operationState, $successfulOperations)) {
                continue;
            }
            unset($activeOperations[$operationKey]);
        }
        $scope['active_operations'] = $activeOperations;

        if ($activeOperation !== [] && $this->isSupersededAiOperationFailure($activeOperation, $successfulOperations)) {
            $scope['active_operation'] = [];
        }

        $latestFailure = \is_array($scope['latest_build_failure'] ?? null) ? $scope['latest_build_failure'] : [];
        $latestOperation = \strtolower(\trim((string)($latestFailure['operation'] ?? '')));
        if ($latestOperation === 'block_partial_patch' && !$this->hasActivePublishBlockingFailure($scope)) {
            $scope['latest_build_failed'] = 0;
            $scope['publish_blocked_by_latest_ai_failure'] = 0;
            unset($scope['latest_build_failure'], $scope['publish_blocked_reason']);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $activeOperations
     * @param array<string, mixed> $activeOperation
     * @return list<array<string, mixed>>
     */
    private function collectSuccessfulAiOperations(array $activeOperations, array $activeOperation): array
    {
        $operations = [];
        if ($this->isSuccessfulAiSupersedingOperation($activeOperation)) {
            $operations[] = $activeOperation;
        }
        foreach ($activeOperations as $operationState) {
            if (\is_array($operationState) && $this->isSuccessfulAiSupersedingOperation($operationState)) {
                $operations[] = $operationState;
            }
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $operationState
     */
    private function isSuccessfulAiSupersedingOperation(array $operationState): bool
    {
        $operation = \strtolower(\trim((string)($operationState['operation'] ?? '')));
        $status = \strtolower(\trim((string)($operationState['status'] ?? '')));

        return $status === 'done'
            && \in_array($operation, ['build', 'regenerate_page', 'block_regenerate'], true)
            && $this->collectAiOperationTargetKeys($operationState) !== [];
    }

    /**
     * @param array<string, mixed> $operationState
     * @param list<array<string, mixed>> $successfulOperations
     */
    private function isSupersededAiOperationFailure(array $operationState, array $successfulOperations): bool
    {
        $operation = \strtolower(\trim((string)($operationState['operation'] ?? '')));
        $status = \strtolower(\trim((string)($operationState['status'] ?? '')));
        if ($operation !== 'block_partial_patch' || !$this->isPublishBlockingAiFailureStatus($status)) {
            return false;
        }

        $failedTargets = $this->collectAiOperationTargetKeys($operationState);
        if ($failedTargets === []) {
            return false;
        }
        $failedAt = $this->readAiOperationTimestamp($operationState);
        foreach ($successfulOperations as $successfulOperation) {
            if ($failedAt > 0 && $this->readAiOperationTimestamp($successfulOperation) < $failedAt) {
                continue;
            }
            if (\array_intersect($failedTargets, $this->collectAiOperationTargetKeys($successfulOperation)) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function hasActivePublishBlockingFailure(array $scope): bool
    {
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if ($this->operationStateIsPublishBlockingFailure($activeOperation)) {
            return true;
        }
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        foreach ($activeOperations as $operationState) {
            if (\is_array($operationState) && $this->operationStateIsPublishBlockingFailure($operationState)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $operationState
     */
    private function operationStateIsPublishBlockingFailure(array $operationState): bool
    {
        $operation = \strtolower(\trim((string)($operationState['operation'] ?? '')));
        $status = \strtolower(\trim((string)($operationState['status'] ?? '')));

        return $this->isPublishBlockingAiBuildOperation($operation)
            && $this->isPublishBlockingAiFailureStatus($status);
    }

    /**
     * @param array<string, mixed> $operationState
     * @return list<string>
     */
    private function collectAiOperationTargetKeys(array $operationState): array
    {
        $keys = [];
        $append = static function ($value) use (&$keys): void {
            if (\is_array($value)) {
                foreach ($value as $item) {
                    $itemValue = \is_array($item) ? ($item['target_scope'] ?? $item['block_key'] ?? $item['component_code'] ?? $item['task_key'] ?? '') : $item;
                    $normalized = \strtolower(\trim((string)$itemValue));
                    if ($normalized !== '') {
                        $keys[] = $normalized;
                    }
                }
                return;
            }
            $normalized = \strtolower(\trim((string)$value));
            if ($normalized !== '') {
                $keys[] = $normalized;
            }
        };

        $details = \is_array($operationState['details'] ?? null) ? $operationState['details'] : [];
        foreach ([$operationState, $details] as $source) {
            foreach (['target_scope', 'block_id', 'block_key', 'component_code', 'task_key', 'section_code'] as $key) {
                $append($source[$key] ?? null);
            }
            foreach (['target_scopes', 'block_keys', 'component_codes', 'task_keys', 'section_codes', 'selected_blocks', 'selected_tasks', 'targets'] as $key) {
                $append($source[$key] ?? null);
            }
        }

        return \array_values(\array_unique(\array_filter($keys)));
    }

    /**
     * @param array<string, mixed> $operationState
     */
    private function readAiOperationTimestamp(array $operationState): int
    {
        foreach (['updated_at', 'finished_at', 'completed_at', 'started_at', 'created_at'] as $key) {
            $value = \trim((string)($operationState[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $timestamp = \strtotime($value);
            if ($timestamp !== false) {
                return (int)$timestamp;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{blocked:bool,operation:string,status:string,message:string}
     */
    private function resolvePublishBlockingAiFailureFromWorkspaceState(array $state): array
    {
        $existing = \is_array($state['latest_build_failure'] ?? null) ? $state['latest_build_failure'] : [];
        if (!empty($state['latest_build_failed']) && !empty($existing['blocked'])) {
            return $this->buildPublishBlockingAiFailurePayload(
                (string)($existing['operation'] ?? 'build'),
                (string)($existing['status'] ?? 'error'),
                (string)($existing['message'] ?? '')
            );
        }
        if (!empty($state['publish_blocked_by_latest_ai_failure'])) {
            return $this->buildPublishBlockingAiFailurePayload(
                (string)($existing['operation'] ?? 'build'),
                (string)($existing['status'] ?? 'error'),
                (string)($existing['message'] ?? ($state['publish_blocked_reason'] ?? ''))
            );
        }

        $scope = \is_array($state['scope'] ?? null) ? $state['scope'] : $state;
        $activeOperation = \is_array($state['active_operation'] ?? null) ? $state['active_operation'] : [];
        $buildQueueInfo = \is_array($state['build_queue_info'] ?? null) ? $state['build_queue_info'] : null;

        return $this->resolveLatestPublishBlockingAiBuildFailure($scope, $activeOperation, $buildQueueInfo);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $buildQueueInfo
     * @return array{blocked:bool,operation:string,status:string,message:string}
     */
    private function resolveLatestPublishBlockingAiBuildFailure(array $scope, array $activeOperation = [], ?array $buildQueueInfo = null): array
    {
        $completionGate = $this->planJsonTaskService->inspectBuildCompletionGate($scope);
        if (!empty($completionGate['passed'])) {
            return ['blocked' => false, 'operation' => '', 'status' => '', 'message' => ''];
        }
        if ($this->hasPublishBlockingAiBuildRunningState($scope, $activeOperation, $buildQueueInfo)) {
            return ['blocked' => false, 'operation' => '', 'status' => '', 'message' => ''];
        }

        $existing = \is_array($scope['latest_build_failure'] ?? null) ? $scope['latest_build_failure'] : [];
        if (!empty($scope['latest_build_failed']) && !empty($existing['blocked'])) {
            return $this->buildPublishBlockingAiFailurePayload(
                (string)($existing['operation'] ?? 'build'),
                (string)($existing['status'] ?? 'error'),
                (string)($existing['message'] ?? '')
            );
        }
        if (!empty($scope['publish_blocked_by_latest_ai_failure'])) {
            return $this->buildPublishBlockingAiFailurePayload(
                (string)($existing['operation'] ?? 'build'),
                (string)($existing['status'] ?? 'error'),
                (string)($existing['message'] ?? ($scope['publish_blocked_reason'] ?? ''))
            );
        }

        $candidates = [];
        $appendCandidate = static function (array $operationState, string $fallbackOperation = '') use (&$candidates): void {
            if ($operationState === []) {
                return;
            }
            if (\trim((string)($operationState['operation'] ?? '')) === '' && $fallbackOperation !== '') {
                $operationState['operation'] = $fallbackOperation;
            }
            $candidates[] = $operationState;
        };

        $appendCandidate($activeOperation);
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        foreach (['build', 'visual_edit', 'regenerate_page', 'block_regenerate', 'block_partial_patch'] as $operationKey) {
            if (\is_array($activeOperations[$operationKey] ?? null)) {
                $appendCandidate($activeOperations[$operationKey], $operationKey);
            }
        }

        foreach ($candidates as $candidate) {
            $operation = \trim((string)($candidate['operation'] ?? ''));
            if (!$this->isPublishBlockingAiBuildOperation($operation)) {
                continue;
            }
            $status = \strtolower(\trim((string)($candidate['status'] ?? '')));
            if ($this->isPublishBlockingAiFailureStatus($status)) {
                return $this->buildPublishBlockingAiFailurePayload(
                    $operation,
                    $status,
                    $this->readAiFailureMessage($candidate)
                );
            }
        }

        if (\is_array($buildQueueInfo)) {
            $queueStatus = $this->readAiQueueInfoStatus($buildQueueInfo);
            if ($this->isPublishBlockingAiFailureStatus($queueStatus)) {
                return $this->buildPublishBlockingAiFailurePayload('build', $queueStatus, $this->readAiQueueInfoMessage($buildQueueInfo));
            }
        }

        return ['blocked' => false, 'operation' => '', 'status' => '', 'message' => ''];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $buildQueueInfo
     */
    private function hasPublishBlockingAiBuildRunningState(array $scope, array $activeOperation = [], ?array $buildQueueInfo = null): bool
    {
        $candidates = [];
        $appendCandidate = static function (array $operationState, string $fallbackOperation = '') use (&$candidates): void {
            if ($operationState === []) {
                return;
            }
            if (\trim((string)($operationState['operation'] ?? '')) === '' && $fallbackOperation !== '') {
                $operationState['operation'] = $fallbackOperation;
            }
            $candidates[] = $operationState;
        };

        $appendCandidate($activeOperation);
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        foreach (['build', 'visual_edit', 'regenerate_page', 'block_regenerate', 'block_partial_patch'] as $operationKey) {
            if (\is_array($activeOperations[$operationKey] ?? null)) {
                $appendCandidate($activeOperations[$operationKey], $operationKey);
            }
        }
        foreach ($candidates as $candidate) {
            $operation = \trim((string)($candidate['operation'] ?? ''));
            if (
                $this->isPublishBlockingAiBuildOperation($operation)
                && $this->isPublishBlockingAiRunningStatus((string)($candidate['status'] ?? ''))
            ) {
                return true;
            }
        }

        return \is_array($buildQueueInfo)
            && $this->isPublishBlockingAiRunningStatus($this->readAiQueueInfoStatus($buildQueueInfo));
    }

    /**
     * @param array<string, mixed> $operationState
     */
    private function readAiFailureMessage(array $operationState): string
    {
        return \trim((string)(
            $operationState['message']
            ?? $operationState['error']
            ?? $operationState['process']
            ?? ''
        ));
    }

    /**
     * @param array<string, mixed> $queueInfo
     */
    private function readAiQueueInfoStatus(array $queueInfo): string
    {
        unset($queueInfo['snapshot']);
        return \strtolower(\trim((string)(
            $queueInfo['queue_status']
            ?? ''
        )));
    }

    /**
     * @param array<string, mixed> $queueInfo
     */
    private function readAiQueueInfoMessage(array $queueInfo): string
    {
        unset($queueInfo['snapshot']);
        foreach ([
            $queueInfo['result_tail'] ?? null,
            $queueInfo['message'] ?? null,
            $queueInfo['process'] ?? null,
        ] as $candidate) {
            $message = \trim((string)$candidate);
            if ($message !== '') {
                return $message;
            }
        }

        return '';
    }

    /**
     * @return array{blocked:bool,operation:string,status:string,message:string}
     */
    private function buildPublishBlockingAiFailurePayload(string $operation, string $status, string $message): array
    {
        $message = \trim($message);
        if ($message === '') {
            $message = 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes.';
        }

        return [
            'blocked' => true,
            'operation' => $operation !== '' ? $operation : 'build',
            'status' => $status !== '' ? $status : 'error',
            'message' => $message,
        ];
    }

    /**
     * @param array<string, mixed> $failure
     */
    private function formatPublishBlockedByAiFailureMessage(array $failure): string
    {
        $message = \trim((string)($failure['message'] ?? ''));
        if ($message === '') {
            return 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes.';
        }

        return 'Latest AI site build failed; publish is blocked until a successful AI rebuild completes. Error: ' . $message;
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
     * @param array<string, mixed> $queueState
     * @param array<string, mixed> $queueInfo
     * @param array<string, mixed> $activeOperation
     * @return array{input_tokens:int|null,output_tokens:int|null,total_tokens:int|null,token_cost_meta:array<string,mixed>|null}
     */
    private function resolveWorkspaceEnvelopeTokenUsage(array $queueState, array $queueInfo, array $activeOperation): array
    {
        return $this->workspaceStateHelperService()->resolveEnvelopeTokenUsage($queueState, $queueInfo, $activeOperation);
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
     * @param array<string, mixed> $queueState
     */
    private function resolveWorkspaceEnvelopeUpdatedAt(array $state, array $activeOperation, array $queueState): string
    {
        return $this->workspaceStateHelperService()->resolveEnvelopeUpdatedAt($state, $activeOperation, $queueState);
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
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function filterWorkspaceStateByStage(array $state, string $streamStage): array
    {
        return $this->workspaceStateHelperService()->filterStateByStage($state, $streamStage);
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
     * Comment removed.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function pruneWorkspaceStateForView(array $state): array
    {
        return $this->workspaceStateHelperService()->pruneStateForView($state);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function pruneWorkspaceDesignDirectionStateForView(array $state): array
    {
        $result = [];
        foreach (['mode', 'code', 'match_reason'] as $key) {
            if (\array_key_exists($key, $state) && !\is_array($state[$key]) && !\is_object($state[$key])) {
                $result[$key] = \is_string($state[$key]) ? $this->limitWorkspaceViewText((string)$state[$key], 600) : $state[$key];
            }
        }
        if (\array_key_exists('locked', $state)) {
            $result['locked'] = !empty($state['locked']) ? 1 : 0;
        }
        if (\is_array($state['snapshot'] ?? null)) {
            $result['snapshot'] = $this->pruneWorkspaceDesignDirectionSnapshotForView($state['snapshot']);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function pruneWorkspaceDesignDirectionSnapshotForView(array $snapshot): array
    {
        $result = [];
        foreach (['code', 'name', 'description', 'source_type', 'version'] as $key) {
            if (\array_key_exists($key, $snapshot) && !\is_array($snapshot[$key]) && !\is_object($snapshot[$key])) {
                $limit = $key === 'description' ? 1000 : 240;
                $result[$key] = \is_string($snapshot[$key]) ? $this->limitWorkspaceViewText((string)$snapshot[$key], $limit) : $snapshot[$key];
            }
        }
        foreach (['industry_tags', 'match_keywords', 'visual_keywords'] as $key) {
            if (!\is_array($snapshot[$key] ?? null)) {
                continue;
            }
            $items = [];
            foreach ($snapshot[$key] as $item) {
                if (\count($items) >= 12) {
                    break;
                }
                if (!\is_array($item) && !\is_object($item)) {
                    $items[] = $this->limitWorkspaceViewText((string)$item, 120);
                }
            }
            $result[$key] = $items;
            if (\count($snapshot[$key]) > \count($items)) {
                $result[$key . '_truncated'] = \count($snapshot[$key]) - \count($items);
            }
        }
        $result['slimmed_for_view'] = true;

        return $result;
    }

    private function limitWorkspaceViewText(string $text, int $limit): string
    {
        if ($limit <= 0 || \strlen($text) <= $limit) {
            return $text;
        }

        return \rtrim(\substr($text, 0, $limit - 3)) . '...';
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
     * @param array<string, array<string, mixed>> $virtualPagesByType
     * @return list<string>
     */
    private function resolvePendingGenerationPageTypes(
        array $pageTypes,
        array $pagesByType,
        array $virtualPagesByType
    ): array
    {
        $pending = [];
        foreach ($pageTypes as $pageType) {
            if (!\is_string($pageType) || $pageType === '') {
                continue;
            }
            if (!$this->isPageTypeGenerationComplete($pageType, $pagesByType, $virtualPagesByType)) {
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
        $summary = $this->planJsonTaskService->summarize($scope);
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
            && (int)($taskSummary['failed'] ?? 0) === 0
            && (int)($taskSummary['cancelled'] ?? 0) === 0
            && (int)($taskSummary['done'] ?? 0) >= (int)($taskSummary['total'] ?? 0);
    }

    /**
     * Stage-2 publish gate: the scheduler may mark every task terminal, but publish
     * must still prove the generated assets that become real PageBuilder blocks are
     * present and count-aligned with the task tree.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildStageTwoPublishReadinessReport(array $scope): array
    {
        $scope = $this->scopeCompatibilityService->normalizeScope($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $taskSummary = $this->planJsonTaskService->summarize($scope);
        $expected = $this->countExpectedStageTwoBlocksFromTaskSummary($taskSummary);
        $actual = $this->countActualStageTwoMaterializedBlocks($scope, $pageTypes);
        $failures = [];

        if ((int)($taskSummary['total'] ?? 0) <= 0) {
            $failures[] = 'stage2 task tree is empty';
        }
        if (!$this->taskSummaryIndicatesCompleted($taskSummary)) {
            $failures[] = \sprintf(
                'stage2 tasks are not fully complete: total=%d done=%d pending=%d running=%d failed=%d cancelled=%d',
                (int)($taskSummary['total'] ?? 0),
                (int)($taskSummary['done'] ?? 0),
                (int)($taskSummary['pending'] ?? 0),
                (int)($taskSummary['running'] ?? 0),
                (int)($taskSummary['failed'] ?? 0),
                (int)($taskSummary['cancelled'] ?? 0)
            );
        }

        $allPageTypes = \array_values(\array_unique(\array_merge(
            \array_keys($expected['page_blocks']),
            \array_keys($actual['page_blocks']),
            $pageTypes
        )));
        foreach ($allPageTypes as $pageType) {
            $pageType = (string)$pageType;
            $expectedCount = (int)($expected['page_blocks'][$pageType] ?? 0);
            $actualCount = (int)($actual['page_blocks'][$pageType] ?? 0);
            if ($expectedCount !== $actualCount) {
                $failures[] = \sprintf('page %s expected %d generated content blocks, got %d', $pageType, $expectedCount, $actualCount);
            }
        }

        foreach (\array_unique(\array_merge(\array_keys($expected['shared_blocks']), \array_keys($actual['shared_blocks']))) as $region) {
            $region = (string)$region;
            $expectedCount = (int)($expected['shared_blocks'][$region] ?? 0);
            $actualCount = (int)($actual['shared_blocks'][$region] ?? 0);
            if ($expectedCount !== $actualCount) {
                $failures[] = \sprintf('shared %s expected %d materialized blocks, got %d', $region, $expectedCount, $actualCount);
            }
        }

        $expectedTotal = (int)$expected['total'];
        $actualTotal = (int)$actual['total'];
        if ($expectedTotal !== $actualTotal) {
            $failures[] = \sprintf('stage2 expected %d materialized blocks, got %d', $expectedTotal, $actualTotal);
        }

        return [
            'passed' => $failures === [],
            'task_summary' => $taskSummary,
            'expected_total' => $expectedTotal,
            'actual_total' => $actualTotal,
            'expected_page_blocks' => $expected['page_blocks'],
            'actual_page_blocks' => $actual['page_blocks'],
            'expected_shared_blocks' => $expected['shared_blocks'],
            'actual_shared_blocks' => $actual['shared_blocks'],
            'failures' => $failures,
        ];
    }

    /**
     * @param array<string, mixed> $taskSummary
     * @return array{total:int,page_blocks:array<string,int>,shared_blocks:array<string,int>}
     */
    private function countExpectedStageTwoBlocksFromTaskSummary(array $taskSummary): array
    {
        $pageBlocks = [];
        $sharedBlocks = [];
        foreach (\is_array($taskSummary['groups'] ?? null) ? $taskSummary['groups'] : [] as $group) {
            if (!\is_array($group)) {
                continue;
            }
            foreach (\is_array($group['tasks'] ?? null) ? $group['tasks'] : [] as $task) {
                if (!\is_array($task)) {
                    continue;
                }
                $taskType = (string)($task['task_type'] ?? '');
                if ($taskType === 'page_section') {
                    $pageType = \trim((string)($task['page_type'] ?? $group['page_type'] ?? ''));
                    if ($pageType !== '') {
                        $pageBlocks[$pageType] = (int)($pageBlocks[$pageType] ?? 0) + 1;
                    }
                    continue;
                }
                if ($taskType === 'shared_component') {
                    $region = $this->resolveStageTwoSharedTaskRegion($task);
                    $sharedBlocks[$region] = (int)($sharedBlocks[$region] ?? 0) + 1;
                }
            }
        }
        \ksort($pageBlocks);
        \ksort($sharedBlocks);

        return [
            'total' => \array_sum($pageBlocks) + \array_sum($sharedBlocks),
            'page_blocks' => $pageBlocks,
            'shared_blocks' => $sharedBlocks,
        ];
    }

    /**
     * @param array<string, mixed> $task
     */
    private function resolveStageTwoSharedTaskRegion(array $task): string
    {
        $identity = \strtolower(\trim(\implode(' ', [
            (string)($task['task_key'] ?? ''),
            (string)($task['section_code'] ?? ''),
            (string)($task['component'] ?? ''),
            (string)($task['label'] ?? ''),
            (string)($task['group_key'] ?? ''),
        ])));
        if (\preg_match('/(?:^|[^a-z])(header)(?:[^a-z]|$)/i', $identity) === 1) {
            return 'header';
        }
        if (\preg_match('/(?:^|[^a-z])(footer)(?:[^a-z]|$)/i', $identity) === 1) {
            return 'footer';
        }

        $taskKey = \trim((string)($task['task_key'] ?? 'shared'));
        return $taskKey !== '' ? $taskKey : 'shared';
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $pageTypes
     * @return array{total:int,page_blocks:array<string,int>,shared_blocks:array<string,int>}
     */
    private function countActualStageTwoMaterializedBlocks(array $scope, array $pageTypes): array
    {
        $virtualPages = $this->planJsonPages($scope);
        $pageBlocks = [];
        foreach ($pageTypes as $pageType) {
            $pageType = (string)$pageType;
            $pageBlocks[$pageType] = $this->countGeneratedContentBlocksForPage(\is_array($virtualPages[$pageType] ?? null) ? $virtualPages[$pageType] : []);
        }
        $sharedBlocks = $this->countGeneratedSharedBlocks($scope);
        \ksort($pageBlocks);
        \ksort($sharedBlocks);

        return [
            'total' => \array_sum($pageBlocks) + \array_sum($sharedBlocks),
            'page_blocks' => $pageBlocks,
            'shared_blocks' => $sharedBlocks,
        ];
    }

    /**
     * @param array<string, mixed> $virtualPage
     */
    private function countGeneratedContentBlocksForPage(array $virtualPage): int
    {
        $count = 0;
        foreach ($this->planJsonDynamicBlocks($virtualPage) as $block) {
            if (!\is_array($block) || !$this->stageTwoGeneratedBlockIsEnabled($block)) {
                continue;
            }
            if (
                (int)($block['status'] ?? 0) === 1
                && \trim((string)($block['html'] ?? $block['html_content'] ?? $block['phtml'] ?? '')) !== ''
            ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string,int>
     */
    private function countGeneratedSharedBlocks(array $scope): array
    {
        $shared = [];
        $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null)
            ? $scope['plan_json']['shared_components']
            : [];
        foreach ($sharedComponents as $region => $component) {
            if (!\is_array($component)) {
                continue;
            }
            if (\trim((string)($component['code'] ?? $component['html'] ?? $component['phtml'] ?? '')) !== '') {
                $region = \trim((string)$region);
                $shared[$region !== '' ? $region : 'shared'] = 1;
            }
        }

        \ksort($shared);
        return $shared;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function stageTwoGeneratedBlockIsEnabled(array $block): bool
    {
        if (!\array_key_exists('enabled', $block)) {
            return true;
        }
        return !\in_array($block['enabled'], [false, 0, '0', 'false', 'off', 'no'], true);
    }

    /**
     * Publish has its own Stage-2 task/block integrity gate. Keep the strict
     * build-time quality report for diagnostics, but do not let stale stage-one
     * blueprint coverage or optional pending image slots override that gate.
     *
     * @param array<string, mixed> $qualityReport
     * @param array<string, mixed> $stageTwoReadiness
     * @return array<string, mixed>
     */
    private function normalizePublishQualityReport(array $qualityReport, array $stageTwoReadiness): array
    {
        $items = [];
        $stageTwoPassed = !empty($stageTwoReadiness['passed']);
        foreach (\is_array($qualityReport['items'] ?? null) ? $qualityReport['items'] : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $key = (string)($item['key'] ?? '');
            if ($key === 'task_coverage' && $stageTwoPassed) {
                $item['ok'] = true;
                $item['blocking'] = true;
                $item['level'] = 'pass';
                $item['detail'] = $this->formatStageTwoPublishReadinessDetail($stageTwoReadiness);
            } elseif ($key === 'plan_json_blocks_done' && $stageTwoPassed) {
                $item['ok'] = true;
                $item['blocking'] = true;
                $item['level'] = 'pass';
                $item['detail'] = (string)__('Publish requirement is not ready.');
                $taskSummary = \is_array($stageTwoReadiness['task_summary'] ?? null) ? $stageTwoReadiness['task_summary'] : [];
                $item['value'] = [
                    'total' => (int)($taskSummary['total'] ?? 0),
                    'done' => (int)($taskSummary['done'] ?? 0),
                    'pending' => (int)($taskSummary['pending'] ?? 0),
                    'running' => (int)($taskSummary['running'] ?? 0),
                    'failed' => (int)($taskSummary['failed'] ?? 0),
                    'cancelled' => (int)($taskSummary['cancelled'] ?? 0),
                ];
            } elseif ($key === 'visual_assets_safe' && !$this->publishQualityItemHasBrokenImages($item)) {
                $item['ok'] = true;
                $item['blocking'] = false;
                $item['level'] = 'warning';
                $item['detail'] = (string)__('Publish requirement is not ready.');
            }
            $items[] = $item;
        }

        $passed = true;
        foreach ($items as $item) {
            if (!empty($item['blocking']) && empty($item['ok'])) {
                $passed = false;
                break;
            }
        }

        $qualityReport['items'] = $items;
        $qualityReport['passed'] = $passed;

        return $qualityReport;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $qualityReport
     * @param array<string, mixed> $stageTwoReadiness
     * @return array<string, mixed>
     */
    private function buildStageOneVisualQaReport(array $scope, array $qualityReport, array $stageTwoReadiness): array
    {
        $items = [];
        foreach (\is_array($qualityReport['items'] ?? null) ? $qualityReport['items'] : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $key = \trim((string)($item['key'] ?? ''));
            if (!\in_array($key, ['theme_visible', 'visual_assets_safe', 'visual_depth', 'responsive_support', 'content_quality', 'language_consistency'], true)) {
                continue;
            }
            $items[$key] = [
                'ok' => !empty($item['ok']),
                'blocking' => !empty($item['blocking']),
                'level' => (string)($item['level'] ?? ''),
                'detail' => $item['detail'] ?? ($item['value'] ?? null),
            ];
        }

        return [
            'version' => 1,
            'source' => 'publish_quality_gate',
            'stage1_validation_contract_hash' => (string)($scope['plan_json']['stage1_validation_contract']['contract_hash'] ?? ''),
            'stage1_validation_hash' => (string)($scope['stage1_validation_report']['artifact_hash'] ?? ''),
            'passed' => !empty($qualityReport['passed']) && !empty($stageTwoReadiness['passed']),
            'browser_review_status' => 'pending',
            'browser_review_required' => true,
            'items' => $items,
            'created_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function publishQualityItemHasBrokenImages(array $item): bool
    {
        foreach (\is_array($item['value'] ?? null) ? $item['value'] : [] as $visuals) {
            if (!\is_array($visuals)) {
                continue;
            }
            if (\is_array($visuals['broken_images'] ?? null) && $visuals['broken_images'] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function formatStageTwoPublishReadinessDetail(array $report): string
    {
        $failures = \array_values(\array_filter(\array_map('strval', \is_array($report['failures'] ?? null) ? $report['failures'] : [])));
        if ($failures === []) {
        return (string)__('Operation completed.');
        }

        return \implode('; ', \array_slice($failures, 0, 6));
    }

    /**
     * @param array<string, array<string, mixed>> $pagesByType
     * @param array<string, array<string, mixed>> $virtualPagesByType
     */
    private function isPageTypeGenerationComplete(
        string $pageType,
        array $pagesByType,
        array $virtualPagesByType
    ): bool {
        $pageId = (int)($pagesByType[$pageType]['page_id'] ?? 0);
        if ($pageId <= 0) {
            return false;
        }

        $page = \is_array($virtualPagesByType[$pageType] ?? null) ? $virtualPagesByType[$pageType] : [];
        if ($page === []) {
            return false;
        }

        return $this->planJsonDynamicBlocks($page) !== [];
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
            $resolvedUrls = $this->normalizeAiSiteVisualUrlsToLocalBase(
                $this->visualUrlService->resolveVirtualUrls($publicId, $pageType, $virtualThemeId),
                $pageData
            );
            $virtualPagesByType[$pageType] = \array_replace(
                $pageData,
                $resolvedUrls
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
        $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $workspaceStatus = $this->scopeCompatibilityService->normalizeWorkspaceStatus((string)($scope['workspace_status'] ?? ''));

        if ($session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        }
        if ($session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHING || ($operation === 'publish' && \in_array($activeStatus, ['queued', 'running'], true))) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING;
        }
        // Comment removed.
        // Comment removed.
        if (\in_array($activeStatus, ['queued', 'running'], true) && $this->isPublishBlockingAiBuildOperation($operation)) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        }
        if ($canPublish) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        }
        if ($activeStatus === 'error' || $workspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        }
        if ($pendingGenerationPageTypes !== []) {
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
     *   materialized_pages_by_type:array<string, array<string, mixed>>,
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
                'materialized_pages_by_type' => [],
                'preview_page_id' => 0,
                'preview_page_type' => '',
            ];
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
     *   materialized_pages_by_type?:array<string, array<string, mixed>>,
     *   preview_page_id?:int,
     *   preview_page_type?:string
     * } $materialized
     * @return array<string, mixed>
     */
    private function mergeMaterializedPagesIntoScope(array $scope, array $materialized): array
    {
        $currentPages = $this->scopeCompatibilityService->normalizePagebuilderPagesByType($scope['materialized_pages_by_type'] ?? []);
        $newPages = \is_array($materialized['materialized_pages_by_type'] ?? null)
            ? $this->scopeCompatibilityService->normalizePagebuilderPagesByType($materialized['materialized_pages_by_type'])
            : [];
        $scope['materialized_pages_by_type'] = \array_replace($currentPages, $newPages);

        $previewPageType = \trim((string)($materialized['preview_page_type'] ?? ''));
        if ($previewPageType !== '') {
            $scope['preview_page_type'] = $previewPageType;
        }

        $previewPageId = (int)($materialized['preview_page_id'] ?? 0);
        if ($previewPageId > 0) {
            $scope['preview_page_id'] = $previewPageId;
        }

        if ($newPages !== [] && \is_array($scope['plan_json']['pages'] ?? null)) {
            foreach ($newPages as $pageType => $pageData) {
                if (!\is_array($scope['plan_json']['pages'][$pageType] ?? null)) {
                    continue;
                }
                $scope['plan_json']['pages'][$pageType]['materialized_page_id'] = (int)($pageData['page_id'] ?? 0);
            }
        }

        return $scope;
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,array<string,mixed>> $pagesByType
     * @return array<string,mixed>
     */
    private function withPlanJsonPages(array $scope, array $pagesByType): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $currentPages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        foreach ($pagesByType as $pageType => $pageData) {
            if (!\is_string($pageType) || !\is_array($pageData)) {
                continue;
            }
            $currentPage = \is_array($currentPages[$pageType] ?? null) ? $currentPages[$pageType] : [];
            $currentPages[$pageType] = \array_replace($currentPage, $pageData);
        }
        $planJson['pages'] = $currentPages;
        $scope['plan_json'] = (new AiSitePlanJsonStateService())->normalizePlanJson($planJson);

        return $scope;
    }

    private function silentSseWriter(): SseWriter
    {
        return new class extends SseWriter {
            public function start(): self
            {
                return $this;
            }

            public function sendEvent(string $event, mixed $data = null, ?int $id = null): self
            {
                unset($event, $data, $id);
                return $this;
            }

            public function sendData(mixed $data): self
            {
                unset($data);
                return $this;
            }

            public function complete(mixed $data = null): void
            {
                unset($data);
            }

            public function close(): void
            {
            }
        };
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,array<string,mixed>>
     */
    private function planJsonPages(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];

        return \array_filter($pages, static fn(mixed $page): bool => \is_array($page));
    }

    /**
     * @param array<string,mixed> $page
     * @return array<string,array<string,mixed>>
     */
    private function planJsonDynamicBlocks(array $page): array
    {
        $blocks = [];
        foreach ($page as $blockKey => $block) {
            if (!\is_string($blockKey) || !\is_array($block)) {
                continue;
            }
            if (\in_array($blockKey, [
                'name',
                'title',
                'label',
                'page_key',
                'page_type',
                'type',
                'status',
                'layout',
                'style',
                'page_meta',
                'meta',
                'seo',
                'route',
                'slug',
                'assets',
                'handle',
                'updated_at',
                'started_at',
                'finished_at',
                'last_generated_at',
                'attempt_no',
                'message',
                'error',
                'error_message',
                'page_goal',
                'page_label',
                'page_title',
                'page_status',
                'content_locale',
                'shared_context_hash',
                'theme_context_hash',
                'assembly_version',
                'generation_method',
                'page_design_plan',
                'theme_alignment_summary',
                'page_context_hash',
                'ordered_block_keys',
                'primary_keywords',
                'secondary_keywords',
                'meta_title',
                'meta_description',
                'meta_keywords',
                'route_path',
                'style_code',
                'style_settings',
                'design_tokens',
                'theme_css_ref',
                'navigation',
                'menus',
                'links',
                'settings',
                'preview_url',
                'preview_full_url',
                'visual_preview_url',
                'visual_edit_url',
                'virtual_preview_url',
                'virtual_edit_url',
                'ai_description',
                'materialized_page_id',
                'blocks',
                'block_previews',
                'sections',
                'section_refinements',
                'components',
            ], true)) {
                continue;
            }
            $blocks[$blockKey] = $block;
        }

        return $blocks;
    }

    /**
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private function planJsonPageBlockList(array $scope, string $pageType): array
    {
        $page = \is_array($scope['plan_json']['pages'][$pageType] ?? null) ? $scope['plan_json']['pages'][$pageType] : [];
        $blocks = [];
        foreach ($this->planJsonDynamicBlocks($page) as $blockKey => $block) {
            $blockId = \trim((string)($block['block_id'] ?? $block['component_code'] ?? $block['section_code'] ?? $blockKey));
            $fields = \is_array($block['fields'] ?? null) ? $block['fields'] : [];
            $config = \is_array($block['config'] ?? null) ? $block['config'] : $fields;
            $html = (string)($block['html'] ?? $block['html_content'] ?? $block['phtml'] ?? '');
            $block = \array_replace($block, [
                'block_key' => \trim((string)($block['block_key'] ?? $blockKey)),
                'page_type' => $pageType,
                'block_id' => $blockId !== '' ? $blockId : (string)$blockKey,
                'html' => $html,
                'config' => $config,
                'fields' => $fields !== [] ? $fields : $config,
                'field_schema' => \is_array($block['field_schema'] ?? null) ? $block['field_schema'] : [],
            ]);
            $blocks[] = $block;
        }

        return $blocks;
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
            'message' => (string)__('Operation completed.'),
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
        $scope = $this->discardForeignAiSiteQueueRuntimeState($scope, (int)$session->getId());
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        $preserveRunningQueueRow = false;
        $forceQueueTakeover = $this->shouldForceQueueTakeoverForOperation($operation, $scopePatch, $operationDetails);
        $skipActiveQueueGuardAfterTakeover = false;
        if (
            \in_array($activeStatus, ['queued', 'running'], true)
            && $this->shouldReclaimStaleActiveOperation($activeOperation)
            && $this->isActiveOperationStale($activeOperation)
        ) {
            $scope['active_operation'] = \array_replace($activeOperation, [
                'queue_status' => 'cancelled',
                    'message' => (string)__('Operation completed.'),
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'operation_cancelled',
                (string)__('Operation completed.'),
                ['details' => ['reason' => 'stale_active_operation']]
            );
            $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        }
        if (\in_array($activeStatus, ['queued', 'running'], true)) {
            $linkedQueueIssue = $this->resolveActiveOperationLinkedQueueIssue($activeOperation);
            if ($linkedQueueIssue !== []) {
                $scope = $this->markActiveOperationLinkedQueueStaleForRetry($scope, $activeOperation, $linkedQueueIssue);
                $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                $this->appendWorkspaceEvent(
                    $session->getId(),
                    $adminId,
                    $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                    'operation_cancelled',
                    'Linked queue is no longer active; allowing a fresh AI operation retry.',
                    [
                        'operation' => (string)($activeOperation['operation'] ?? ''),
                        'queue_id' => (int)($linkedQueueIssue['queue_id'] ?? 0),
                        'details' => $linkedQueueIssue,
                    ],
                    AiSiteAgentSessionEvent::LEVEL_WARNING
                );
                $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
                $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
            }
        }
        // Comment removed.
        // Comment removed.
        // Comment removed.
        if (
            \in_array($activeStatus, ['queued', 'running'], true)
            && \trim((string)($activeOperation['execution_token'] ?? '')) === ''
        ) {
            $scope['active_operation'] = \array_replace($activeOperation, [
                'queue_status' => 'cancelled',
                    'message' => (string)__('Operation completed.'),
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'operation_cancelled',
                'Active operation missing execution token; auto-recovered to allow restart.',
                [
                    'operation' => (string)($activeOperation['operation'] ?? ''),
                    'details' => ['reason' => 'missing_execution_token'],
                ],
                AiSiteAgentSessionEvent::LEVEL_WARNING
            );
            $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        }
        if ($forceQueueTakeover && $this->isAiSiteQueueBackedOperation($operation)) {
            $takeoverResult = $this->forceTakeoverActiveAiSiteQueueForFreshStart($session, $adminId, $operation, $stage, $scope);
            if (\is_array($takeoverResult['error'] ?? null)) {
                return \array_replace([
                    'success' => false,
                    'http_status' => 409,
                    'status_code' => 409,
                    'code' => 'AI_SITE_QUEUE_TAKEOVER_FAILED',
                    'operation' => $operation,
                ], $takeoverResult['error']);
            }
            if ((bool)($takeoverResult['taken_over'] ?? false)) {
                $scope = \is_array($takeoverResult['scope'] ?? null) ? $takeoverResult['scope'] : $scope;
                $skipActiveQueueGuardAfterTakeover = true;
                $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
                $activeStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
            }
        }
        $runningOperationState = $this->resolveRunningQueuedOperationState($scope, $operation);
        if ($runningOperationState !== []) {
            $runningOperation = \trim((string)($runningOperationState['operation'] ?? ''));
            $runningExecutionToken = \trim((string)($runningOperationState['execution_token'] ?? ''));
            if ($this->shouldReuseRunningQueuedOperation($operation, $runningOperation)) {
                $reusedOperation = $runningOperation !== '' ? $runningOperation : $operation;
                return [
                    'success' => false,
                    'message' => $this->buildRunningOperationReuseMessage($reusedOperation),
                    'operation' => $reusedOperation,
                    'execution_token' => $runningExecutionToken,
                    'stream_url' => $runningExecutionToken !== ''
                        ? $this->buildOperationStreamUrl($session->getPublicId(), $runningExecutionToken)
                        : '',
                ];
            }
            if ($operation === 'plan' || $runningOperation === 'plan') {
                $runningQueueId = (int)($runningOperationState['queue_id'] ?? 0);
                $runningQueueRow = null;
                if ($runningQueueId > 0) {
                    try {
                        $runningQueueRow = $this->findAiSiteQueueRowById($runningQueueId);
                    } catch (\Throwable) {
                        $runningQueueRow = null;
                    }
                    if (\is_object($runningQueueRow) && \method_exists($runningQueueRow, 'getData')) {
                        $runningQueueRow = $runningQueueRow->getData();
                    }
                }
                $runningOperationForState = $runningOperation !== '' ? $runningOperation : $operation;
                $runningState = $this->buildQueuedOperationState(
                    $session,
                    $stage,
                    $runningOperationForState,
                    $runningOperationState,
                    \is_array($runningQueueRow) ? $runningQueueRow : null
                );

                return [
                    'success' => false,
                    'http_status' => 409,
                    'status_code' => 409,
                    'message' => (string)__('Operation completed.'),
                    'operation' => $runningOperationForState,
                    'execution_token' => $runningExecutionToken,
                    'stream_url' => ($runningOperationForState !== '' && $runningExecutionToken !== '')
                        ? $this->buildOperationStreamUrl($session->getPublicId(), $runningExecutionToken)
                        : '',
                    'queue_id' => $runningQueueId,
                    'data' => $runningState,
                    'queue_wait' => $this->buildAiSiteQueueSchedulerWaitPayload(
                        $runningOperationForState,
                        $runningQueueId,
                        'existing_active_operation'
                    ),
                ];
            }
            if ($this->isAiSiteQueueBackedOperation($operation) && $this->isAiSiteQueueBackedOperation($runningOperation)) {
                $activeStreamUrl = ($runningOperation !== '' && $runningExecutionToken !== '')
                    ? $this->buildOperationStreamUrl($session->getPublicId(), $runningExecutionToken)
                    : '';

                return [
                    'success' => false,
                    'http_status' => 409,
                    'status_code' => 409,
                    'code' => 'AI_SITE_OPERATION_BUSY',
                    'message' => $this->buildRunningOperationBusyMessage($operation, $runningOperation),
                    'operation' => $operation,
                    'running_operation' => $runningOperation,
                    'execution_token' => '',
                    'stream_url' => '',
                    'active_operation' => [
                        'operation' => $runningOperation,
                        'execution_token' => $runningExecutionToken,
                        'stream_url' => $activeStreamUrl,
                    ],
                ];
            }
        }
        if ($this->isAiSiteQueueBackedOperation($operation) && !$skipActiveQueueGuardAfterTakeover) {
            $activeQueueRow = $this->findActiveAiSiteSessionQueueRow($session, $operation);
            if (\is_array($activeQueueRow) && $activeQueueRow !== []) {
                return $this->buildActiveAiSiteQueueAlreadyRunningResult(
                    $session,
                    $adminId,
                    $operation,
                    $stage,
                    $scope,
                    $activeQueueRow
                );
            }
        }

        $baseScope = $scope;
        if ($operation === 'build') {
            $baseScope = $this->normalizePlanJsonConfirmationForBuild($baseScope);
            $scope = $baseScope;
            $scopePatch = $this->planJsonTaskService->stripPlanJsonMutationScopePatch($scopePatch, $baseScope);
        }
        $scope = \array_replace($scope, $scopePatch);
        if ((int)($scope['fake_mode'] ?? 0) === 1) {
            $scopePatch['fake_mode'] = 1;
        }
        if ($operation === 'plan' && (int)($scopePatch['_force_rebuild'] ?? 0) === 1) {
            unset($scope['_artifact_refs']);
            foreach ([
                'plan_json',
                'plan_projection',
                'content_manifest',
                'build_contracts',
                'render_data_contract',
                'task_results',
                'qa_report_v2',
                'repair_patch',
            ] as $artifactScopeKey) {
                $scope[$artifactScopeKey] = [];
            }
            $scope['plan_markdown'] = '';
        }
        $scope['page_types'] = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if ($operation === 'build') {
            $scope = $this->planJsonTaskService->restorePlanJsonContract($scope, $baseScope);
            $scope = $this->normalizePlanJsonConfirmationForBuild($scope);
            $resetFailedOrInterruptedTasks = (int)($operationDetails['fresh_repair_failed_tasks'] ?? 0) === 1
                || (int)($operationDetails['resume_failed_tasks'] ?? 0) === 1;
            if ($resetFailedOrInterruptedTasks) {
                $isResumeRepair = (int)($operationDetails['resume_failed_tasks'] ?? 0) === 1;
                $scope = $this->planJsonTaskService->resetFailedTasksForFreshRepair(
                    $scope,
                    $isResumeRepair
                        ? 'Resume build after previous task failure'
                        : 'Fresh build repair after previous task failure'
                );
                $scope = $this->planJsonTaskService->resetRunningTasksForInterruptedBuild(
                    $scope,
                    $isResumeRepair
                        ? 'Resume build after interrupted task execution'
                        : 'Fresh build repair after interrupted task execution'
                );
            }
            $scope = $this->planJsonTaskService->ensureTaskScope(
                $scope,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                (string)($scope['workspace_track'] ?? '')
            );
            $scopePatch['plan_json_task_summary'] = $this->planJsonTaskService->summarize($scope);
            if (\is_array($scopePatch['build_summary'] ?? null)) {
                unset($scopePatch['build_summary']['task_summary']);
            }
        }
        if ($this->isPublishBlockingAiBuildOperation($operation)) {
            $scope['latest_build_failed'] = 0;
            $scope['publish_blocked_by_latest_ai_failure'] = 0;
            unset($scope['latest_build_failure'], $scope['publish_blocked_reason']);
        }
        if ($pageType !== '' && !\in_array($pageType, $scope['page_types'], true)) {
            throw new \InvalidArgumentException((string)__('Page type is not available in this scope.'));
        }

        $executionToken = \bin2hex(\random_bytes(16));
        $queueId = 0;
        $queueWait = [
            'queue_id' => 0,
            'queue_waiting_for_scheduler' => false,
            'can_close_stream' => false,
            'continue_other_operations' => false,
            'reason' => 'not_queued',
            'message' => '',
        ];
        $operationEnvelope = $this->buildOperationQueueEnvelope($session, $operation, $executionToken, 'queued');
        $scope['active_operation'] = \array_replace([
            'operation' => $operation,
            'execution_token' => $executionToken,
            'queue_status' => 'queued',
            'page_type' => $pageType,
            'started_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
            'claimed_by' => '',
            'claimed_at' => '',
            'retry_allowed' => 0,
            'queue_terminal_recovered' => 0,
            'progress_percent' => 0,
            'message' => (string)__('Operation completed.'),
        ], $operationEnvelope);
        if ($operationDetails !== []) {
            $scope['active_operation'] = $this->applyOperationDetailsToPayload($scope['active_operation'], $operationDetails);
        }
        if ($operation === 'plan') {
            $existingDetails = \is_array($scope['active_operation']['details'] ?? null) ? $scope['active_operation']['details'] : [];
            $scope['active_operation']['details'] = \array_replace($existingDetails, [
                'plan_locale' => (string)($scope['plan_locale'] ?? ''),
                'page_types' => \array_values(\array_map('strval', \is_array($scope['page_types'] ?? null) ? $scope['page_types'] : [])),
                'source_signature' => $this->PlanJsonSourceSignature($scope),
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
                $queueId = $this->enqueueOperationQueueTask($freshForQueue, $adminId, $operation, $executionToken, $scopePatch, $operationDetails, $preserveRunningQueueRow);
            } catch (\Throwable $throwable) {
                $failedWorkspaceStatus = $operation === 'publish'
                    ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED
                    : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PREPARING;
                $this->updateActiveOperation(
                    $freshForQueue,
                    $adminId,
                    ['queue_status' => 'error', 'message' => $throwable->getMessage()],
                    $failedWorkspaceStatus,
                    $operation === 'publish' ? AiSiteAgentSession::PUBLISH_STATUS_FAILED : null
                );
                throw $throwable;
            }
            $queueWait['queue_id'] = $queueId;

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
                $scope = $queueScope;
                $freshForQueue = $this->sessionService->loadById($session->getId(), $adminId) ?? $freshForQueue;
            }
        }
        if ($queueId > 0) {
            $queueWait = $this->buildAiSiteQueueSchedulerWaitPayload($operation, $queueId);
        }
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stage, 'operation_started', (string)__('Operation started.'));

        // Comment removed.
        // Comment removed.
        $queueRow = null;
        if ($queueId > 0) {
            try {
                $queueRow = $this->findAiSiteQueueRowById($queueId);
            } catch (\Throwable) {
                $queueRow = null;
            }
            if (\is_object($queueRow) && \method_exists($queueRow, 'getData')) {
                $queueRow = $queueRow->getData();
            }
        }
        $activeOperationForResponse = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $state = $this->buildQueuedOperationState(
            $freshForQueue,
            $stage,
            $operation,
            $activeOperationForResponse,
            \is_array($queueRow) ? $queueRow : null
        );
        return [
            'success' => true,
            'message' => (string)__('Operation completed.'),
            'execution_token' => $executionToken,
            'queue_id' => $queueId,
            'operation' => $operation,
            'stream_url' => $this->buildOperationStreamUrl($freshForQueue->getPublicId(), $executionToken),
            'data' => $state,
            'queue_wait' => $queueWait,
        ];
    }

    /**
     * @param array<string, mixed> $scopePatch
     * @param array<string, mixed> $operationDetails
     */
    private function shouldForceQueueTakeoverForOperation(string $operation, array $scopePatch, array $operationDetails): bool
    {
        if (!$this->isAiSiteQueueBackedOperation($operation)) {
            return false;
        }

        if (\in_array($operation, ['plan', 'build'], true)) {
            return true;
        }

        foreach ([$scopePatch, $operationDetails] as $payload) {
            if ($this->payloadRequestsQueueTakeover($payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadRequestsQueueTakeover(array $payload): bool
    {
        foreach (['_force_rebuild', 'force_queue_takeover', 'queue_takeover', 'takeover_queue'] as $key) {
            if ((int)($payload[$key] ?? 0) === 1) {
                return true;
            }
        }

        foreach (['_plan_sse_request', 'request', 'scope_patch', 'details'] as $nestedKey) {
            $nested = \is_array($payload[$nestedKey] ?? null) ? $payload[$nestedKey] : [];
            if ($nested !== [] && $this->payloadRequestsQueueTakeover($nested)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{taken_over?: bool, scope?: array<string, mixed>, error?: array<string, mixed>}
     */
    private function forceTakeoverActiveAiSiteQueueForFreshStart(
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $stage,
        array $scope
    ): array {
        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        $activeOperationName = \trim((string)($activeOperation['operation'] ?? ''));
        $operationStates = [];
        $seenOperationStateKeys = [];
        $appendOperationState = static function (array $state) use ($operation, &$operationStates, &$seenOperationStateKeys): void {
            $stateOperation = \trim((string)($state['operation'] ?? $operation));
            if ($stateOperation !== '' && $stateOperation !== $operation) {
                return;
            }
            $queueId = (int)($state['queue_id'] ?? 0);
            $token = \trim((string)($state['execution_token'] ?? $state['token'] ?? ''));
            $key = $operation . ':' . $queueId . ':' . $token;
            if (isset($seenOperationStateKeys[$key])) {
                return;
            }
            $seenOperationStateKeys[$key] = true;
            $state['operation'] = $operation;
            $operationStates[] = $state;
        };
        $operationStateIsInProgress = static function (array $state): bool {
            $status = \strtolower(\trim((string)($state['queue_status'] ?? $state['status'] ?? '')));

            return \in_array($status, ['pending', 'queued', 'running', 'processing'], true);
        };

        if ($activeOperationName === $operation) {
            $appendOperationState($activeOperation);
        }
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        if (\is_array($activeOperations[$operation] ?? null)) {
            $appendOperationState($activeOperations[$operation]);
        }

        $candidateRows = [];
        $seenQueueIds = [];
        foreach ($operationStates as $operationState) {
            $linkedQueueId = (int)($operationState['queue_id'] ?? 0);
            if ($linkedQueueId <= 0) {
                continue;
            }
            $linkedRow = $this->findAiSiteQueueRowById($linkedQueueId);
            if (\is_array($linkedRow) && $this->isObservedQueueInProgress($linkedRow) && $this->isAiSiteQueueRowForOperation($linkedRow, $operation)) {
                $queueId = (int)($linkedRow['queue_id'] ?? 0);
                if ($queueId > 0 && empty($seenQueueIds[$queueId])) {
                    $candidateRows[] = $linkedRow;
                    $seenQueueIds[$queueId] = true;
                }
            }
        }

        $activeQueueRow = $this->findActiveAiSiteSessionQueueRow($session, $operation);
        if (\is_array($activeQueueRow) && $activeQueueRow !== []) {
            $queueId = (int)($activeQueueRow['queue_id'] ?? 0);
            if ($queueId > 0 && empty($seenQueueIds[$queueId])) {
                $candidateRows[] = $activeQueueRow;
                $seenQueueIds[$queueId] = true;
            }
        }

        foreach ($candidateRows as $row) {
            $queueId = (int)($row['queue_id'] ?? 0);
            if ($queueId <= 0) {
                continue;
            }
            $rowOperation = $this->resolveAiSiteQueueRowOperation($row, $activeOperationName !== '' ? $activeOperationName : $operation);
            if ($rowOperation !== $operation) {
                continue;
            }
            $takeover = w_query('queue', 'takeover', [
                'queue_id' => $queueId,
                'force' => true,
                'owner' => 'system_scheduler',
                'reason' => 'pagebuilder_force_takeover',
                'mark_force_rebuild' => true,
                'clear_output' => false,
                'wake_scheduler' => false,
            ]);
            if (!\is_array($takeover) || empty($takeover['success'])) {
                return [
                    'error' => [
                        'message' => \is_array($takeover)
                            ? (string)($takeover['message'] ?? 'Queue takeover failed.')
                            : 'Queue takeover failed.',
                        'queue_id' => $queueId,
                    ],
                ];
            }

            $markOperationState = $operationStates[0] ?? \array_replace(
                $this->buildOperationQueueEnvelope($session, $operation, '', 'cancelled'),
                [
                    'operation' => $operation,
                    'queue_id' => $queueId,
                    'queue_status' => 'cancelled',
                ]
            );
            $scope = $this->markActiveOperationForceTakenOverForFreshStart($scope, $markOperationState, $operation, $queueId);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $stage,
                'operation_queue_takeover',
                'Active AI queue was force-taken over; a fresh operation will wait for the system scheduler.',
                ['operation' => $operation, 'queue_id' => $queueId],
                AiSiteAgentSessionEvent::LEVEL_WARNING
            );

            return ['taken_over' => true, 'scope' => $scope];
        }

        foreach ($operationStates as $operationState) {
            if (!$operationStateIsInProgress($operationState)) {
                continue;
            }
            $scope = $this->markActiveOperationForceTakenOverForFreshStart($scope, $operationState, $operation, (int)($operationState['queue_id'] ?? 0));
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $stage,
                'operation_queue_takeover',
                'Active operation was force-released because no live queue row was attached.',
                ['operation' => $operation, 'queue_id' => (int)($operationState['queue_id'] ?? 0)],
                AiSiteAgentSessionEvent::LEVEL_WARNING
            );

            return ['taken_over' => true, 'scope' => $scope];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @return array<string, mixed>
     */
    private function markActiveOperationForceTakenOverForFreshStart(
        array $scope,
        array $activeOperation,
        string $operation,
        int $queueId
    ): array {
        $details = \is_array($activeOperation['details'] ?? null) ? $activeOperation['details'] : [];
        $details = \array_replace($details, [
            'reason' => 'force_queue_takeover',
            'queue_id' => $queueId,
            'execute_in_request' => false,
        ]);

        return $this->writeActiveOperationStateToScope($scope, \array_replace($activeOperation, [
            'operation' => $operation,
            'status' => 'cancelled',
            'queue_status' => 'cancelled',
            'message' => 'Force takeover completed; waiting for a fresh system-scheduled operation.',
            'retry_allowed' => 1,
            'queue_terminal_recovered' => 1,
            'updated_at' => \date('Y-m-d H:i:s'),
            'details' => $details,
        ]));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveAiSiteQueueRowOperation(array $row, string $fallbackOperation = ''): string
    {
        $content = $this->decodeAiSiteQueueRowContent($row);
        $operation = \trim((string)($content['operation'] ?? $row['_resolved_operation'] ?? ''));

        return $operation !== '' ? $operation : \trim($fallbackOperation);
    }


    private function shouldEnqueueOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'image_asset'], true);
    }

    private function isAiSiteQueueBackedOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'image_asset'], true);
    }

    private function buildRunningOperationReuseMessage(string $operation): string
    {
        $operation = \trim($operation);
        if ($operation === 'build') {
            return 'Current site build is still running; reusing the current queue progress.';
        }
        if (\in_array($operation, ['block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
            return 'Current page or block AI operation is still running; reusing the current queue progress.';
        }
        if ($operation === 'plan') {
            return 'Current stage-one plan generation is still running; wait for it to finish.';
        }

        return 'Current workspace operation is still running; wait for it to finish.';
    }

    private function buildRunningOperationBusyMessage(string $requestedOperation, string $runningOperation): string
    {
        $requestedOperation = \trim($requestedOperation);
        $runningOperation = \trim($runningOperation);
        if ($runningOperation === 'build' && \in_array($requestedOperation, ['block_regenerate', 'block_partial_patch'], true)) {
            return (string)__('Operation completed.');
        }
        if ($runningOperation === 'build' && $requestedOperation === 'regenerate_page') {
            return (string)__('Operation completed.');
        }
        if ($requestedOperation === 'build' && \in_array($runningOperation, ['block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
            return (string)__('Operation completed.');
        }
        if ($runningOperation === 'build') {
            return (string)__('Operation completed.');
        }
        if (\in_array($runningOperation, ['block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
            return (string)__('Operation completed.');
        }

        return (string)__('Operation completed.');
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function resolveRunningQueuedOperationState(array $scope, string $requestedOperation): array
    {
        $candidates = [];
        $seen = [];
        $appendCandidate = static function (array $operationState, string $fallbackOperation = '') use (&$candidates, &$seen): void {
            $operation = \trim((string)($operationState['operation'] ?? $fallbackOperation));
            if ($operation === '') {
                return;
            }
            $queueId = (int)($operationState['queue_id'] ?? 0);
            $token = \trim((string)($operationState['execution_token'] ?? $operationState['token'] ?? ''));
            $key = $operation . ':' . $queueId . ':' . $token;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            if (\trim((string)($operationState['operation'] ?? '')) === '') {
                $operationState['operation'] = $operation;
            }
            $candidates[] = $operationState;
        };

        $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
        if ($activeOperation !== []) {
            $appendCandidate($activeOperation);
        }
        $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
        if (\is_array($activeOperations[$requestedOperation] ?? null)) {
            $appendCandidate($activeOperations[$requestedOperation], $requestedOperation);
        }
        foreach ($activeOperations as $operation => $operationState) {
            if (!\is_array($operationState)) {
                continue;
            }
            $appendCandidate($operationState, \is_string($operation) ? $operation : '');
        }

        foreach ($candidates as $candidate) {
            $running = $this->normalizeRunningQueuedOperationCandidate($candidate);
            if ($running !== []) {
                return $running;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $operationState
     * @return array<string, mixed>
     */
    private function normalizeRunningQueuedOperationCandidate(array $operationState): array
    {
        $operation = \trim((string)($operationState['operation'] ?? ''));
        if (!$this->isAiSiteQueueBackedOperation($operation)) {
            return [];
        }
        $status = \trim((string)($operationState['status'] ?? ''));
        if (!\in_array($status, ['pending', 'queued', 'running', 'processing'], true)) {
            return [];
        }
        if ($status === 'pending') {
            $status = 'queued';
        } elseif ($status === 'processing') {
            $status = 'running';
        }

        $queueId = (int)($operationState['queue_id'] ?? 0);
        if ($queueId > 0) {
            try {
                $queueRow = $this->findAiSiteQueueRowById($queueId);
            } catch (\Throwable) {
                $queueRow = null;
            }
            if (\is_object($queueRow) && \method_exists($queueRow, 'getData')) {
                $queueRow = $queueRow->getData();
            }
            if (!\is_array($queueRow) || $queueRow === []) {
                return [];
            }
            if (!$this->isAiSiteQueueRowLiveInProgress($queueRow)) {
                return [];
            }
            $queueStatus = \trim((string)($queueRow['status'] ?? ''));
            $status = $queueStatus === 'pending' ? 'queued' : $queueStatus;
            $operationState['queue_status'] = $queueStatus;
        }

        $operationState['operation'] = $operation;
        $operationState['status'] = $status;
        if (\trim((string)($operationState['execution_token'] ?? '')) === '' && \trim((string)($operationState['token'] ?? '')) !== '') {
            $operationState['execution_token'] = \trim((string)$operationState['token']);
        }

        return $operationState;
    }

    private function shouldReuseRunningQueuedOperation(string $requestedOperation, string $runningOperation): bool
    {
        $requestedOperation = \trim($requestedOperation);
        $runningOperation = \trim($runningOperation);
        if ($requestedOperation === '' || $runningOperation === '') {
            return false;
        }
        if ($requestedOperation === 'plan' || $runningOperation === 'plan') {
            return false;
        }

        return $requestedOperation === $runningOperation
            && $this->isAiSiteQueueBackedOperation($requestedOperation)
            && $this->isAiSiteQueueBackedOperation($runningOperation);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildActiveAiSiteQueueAlreadyRunningResult(
        AiSiteAgentSession $session,
        int $adminId,
        string $requestedOperation,
        string $stage,
        array $scope,
        array $queueRow
    ): array {
        $queueId = (int)($queueRow['queue_id'] ?? 0);
        $content = $this->decodeAiSiteQueueRowContent($queueRow);
        $runningOperation = \trim((string)(
            $content['operation']
            ?? $queueRow['_resolved_operation']
            ?? $requestedOperation
        ));
        if (!$this->isAiSiteQueueBackedOperation($runningOperation)) {
            $runningOperation = $requestedOperation;
        }
        $executionToken = \trim((string)($content['execution_token'] ?? $content['token'] ?? ''));
        $queueStatus = \strtolower(\trim((string)($queueRow['status'] ?? '')));
        $operationStatus = \in_array($queueStatus, ['running', 'processing'], true) ? 'running' : 'queued';
        $sameOperation = $requestedOperation === $runningOperation;
        $shouldSuppressPriorBuildFailure = $sameOperation
            && $this->isPublishBlockingAiBuildOperation($runningOperation)
            && $this->isPublishBlockingAiRunningStatus($operationStatus);
        $message = $sameOperation
            ? $this->buildRunningOperationReuseMessage($runningOperation)
            : $this->buildRunningOperationBusyMessage($requestedOperation, $runningOperation);

        $activeOperation = \array_replace(
            $this->buildOperationQueueEnvelope($session, $runningOperation, $executionToken, $operationStatus),
            [
                'operation' => $runningOperation,
                'execution_token' => $executionToken,
                'status' => $operationStatus,
                'queue_id' => $queueId,
                'message' => $message,
                'queue_waiting_for_scheduler' => $operationStatus !== 'running',
                'can_close_stream' => true,
                'continue_other_operations' => true,
                'updated_at' => \date('Y-m-d H:i:s'),
            ]
        );
        $scope = $this->writeActiveOperationStateToScope($scope, $activeOperation);
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        if ($shouldSuppressPriorBuildFailure) {
            $scope['latest_build_failed'] = 0;
            $scope['publish_blocked_by_latest_ai_failure'] = 0;
            unset($scope['latest_build_failure'], $scope['publish_blocked_reason']);
        }
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildQueuedOperationState($fresh, $stage, $runningOperation, $activeOperation, $queueRow);
        $streamUrl = $executionToken !== '' ? $this->buildOperationStreamUrl($fresh->getPublicId(), $executionToken) : '';

        return [
            'success' => $sameOperation,
            'http_status' => $sameOperation ? 200 : 409,
            'status_code' => $sameOperation ? 200 : 409,
            'code' => 'AI_SITE_QUEUE_ALREADY_ACTIVE',
            'message' => $message,
            'operation' => $sameOperation ? $runningOperation : $requestedOperation,
            'running_operation' => $runningOperation,
            'execution_token' => $sameOperation ? $executionToken : '',
            'stream_url' => $sameOperation ? $streamUrl : '',
            'queue_id' => $queueId,
            'start_sse' => $sameOperation && $streamUrl !== '',
            'active_operation' => [
                'operation' => $runningOperation,
                'execution_token' => $executionToken,
                'stream_url' => $streamUrl,
                'queue_id' => $queueId,
            ],
            'data' => $state,
            'queue_wait' => $this->buildAiSiteQueueSchedulerWaitPayload($runningOperation, $queueId, 'existing_active_queue'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveAiSiteSessionQueueRow(AiSiteAgentSession $session, string $requestedOperation): ?array
    {
        foreach ($this->resolveAiSiteQueueBackedOperationsForStartGuard($requestedOperation) as $operation) {
            foreach ($this->resolveAiSiteQueueLookupBizKeys((int)$session->getId(), $operation) as $bizKey) {
                foreach ($this->findAiSiteQueueRowsByBizKey($bizKey) as $row) {
                    if (!$this->isAiSiteQueueRowForOperation($row, $operation)) {
                        continue;
                    }
                    if (!$this->isAiSiteQueueRowLiveInProgress($row)) {
                        continue;
                    }
                    $row['_resolved_operation'] = $operation;

                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveAiSiteQueueBackedOperationsForStartGuard(string $requestedOperation): array
    {
        $operations = ['plan', 'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'image_asset'];
        $requestedOperation = \trim($requestedOperation);
        if ($requestedOperation !== '' && \in_array($requestedOperation, $operations, true)) {
            \array_unshift($operations, $requestedOperation);
        }

        return \array_values(\array_unique($operations));
    }

    /**
     * @return list<string>
     */
    private function resolveAiSiteQueueLookupBizKeys(int $sessionId, string $operation): array
    {
        return [$this->buildAiSiteQueueBizKey($sessionId, $operation)];
    }

    /**
     * @param array<string, mixed> $queueRow
     */
    private function isAiSiteQueueRowLiveInProgress(array $queueRow): bool
    {
        if (!$this->isObservedQueueInProgress($queueRow)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $activeOperation
     * @return array<string, mixed>
     */
    private function resolveActiveOperationLinkedQueueIssue(array $activeOperation): array
    {
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        if (!$this->isAiSiteQueueBackedOperation($operation)) {
            return [];
        }

        $queueId = (int)($activeOperation['queue_id'] ?? 0);
        if ($queueId <= 0) {
            return [];
        }

        try {
            $queueRow = $this->findAiSiteQueueRowById($queueId);
        } catch (\Throwable) {
            return [];
        }

        if (!\is_array($queueRow) || $queueRow === []) {
            return [
                'reason' => 'linked_queue_missing',
                'queue_id' => $queueId,
                'queue_status' => '',
                'message' => 'Linked queue #' . $queueId . ' no longer exists; starting a fresh AI operation is allowed.',
            ];
        }

        if ($this->isAiSiteQueueRowLiveInProgress($queueRow)) {
            return [];
        }

        $queueStatus = \strtolower(\trim((string)($queueRow['status'] ?? '')));
        if ($this->isObservedQueueTerminal($queueRow) || $queueStatus !== '') {
            $duplicateSkip = $this->isAiSiteQueueDuplicateSkipResult($queueRow);
            return [
                'reason' => $duplicateSkip ? 'linked_queue_duplicate_skip' : 'linked_queue_terminal',
                'queue_id' => $queueId,
                'queue_status' => $queueStatus,
                'message' => $duplicateSkip
                    ? 'Linked queue #' . $queueId . ' only skipped a duplicate stream; starting a fresh AI operation is allowed.'
                    : 'Linked queue #' . $queueId . ' is ' . ($queueStatus !== '' ? $queueStatus : 'not active') . '; starting a fresh AI operation is allowed.',
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed> $queueIssue
     * @return array<string, mixed>
     */
    private function markActiveOperationLinkedQueueStaleForRetry(array $scope, array $activeOperation, array $queueIssue): array
    {
        $details = \is_array($activeOperation['details'] ?? null) ? $activeOperation['details'] : [];
        $details = \array_replace($details, [
            'reason' => (string)($queueIssue['reason'] ?? 'linked_queue_terminal'),
            'queue_id' => (int)($queueIssue['queue_id'] ?? 0),
            'queue_status' => (string)($queueIssue['queue_status'] ?? ''),
        ]);
        $operationState = \array_replace($activeOperation, [
            'queue_status' => 'cancelled',
            'message' => (string)($queueIssue['message'] ?? 'Linked queue is no longer active; retry is allowed.'),
            'retry_allowed' => 1,
            'queue_terminal_recovered' => 1,
            'updated_at' => \date('Y-m-d H:i:s'),
            'details' => $details,
        ]);

        return $this->writeActiveOperationStateToScope($scope, $operationState);
    }

    /**
     * @param array<string, mixed> $queueRow
     */
    private function isAiSiteQueueDuplicateSkipResult(array $queueRow): bool
    {
        foreach (['process', 'result'] as $field) {
            $text = \strtolower((string)($queueRow[$field] ?? ''));
            if ($text === '') {
                continue;
            }
            if (
                \str_contains($text, 'duplicate_stream')
                || \str_contains($text, 'skipped duplicate')
                || \str_contains($text, 'duplicate ai execution')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $scopePatch
     * @param array<string, mixed> $operationDetails
     */
    private function enqueueOperationQueueTask(
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $executionToken,
        array $scopePatch = [],
        array $operationDetails = [],
        bool $preserveRunningQueueRow = false
    ): int {
        $scopePatch = $this->stripBuildSummaryNestedTaskSummary($scopePatch);
        $queueClass = $this->resolveAiSiteQueueClass($operation);
        if ($queueClass === '') {
            return 0;
        }
        $typeId = $this->resolveAiSiteQueueTypeId($queueClass);

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
        $requestPayload = \is_array($scopePatch['_plan_sse_request'] ?? null)
            ? $scopePatch['_plan_sse_request']
            : [];
        $detailRequestPayload = \is_array($operationDetails['_plan_sse_request'] ?? null)
            ? $operationDetails['_plan_sse_request']
            : [];
        $selectedSkillCodes = $this->scopeCompatibilityService->normalizeSelectedSkillCodes(
            $scopePatch[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY]
                ?? $operationDetails[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY]
                ?? $requestPayload[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY]
                ?? $detailRequestPayload[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY]
                ?? []
        );
        $fakeModeQueued = (int)($scopePatch['fake_mode'] ?? $operationDetails['fake_mode'] ?? $requestPayload['fake_mode'] ?? $detailRequestPayload['fake_mode'] ?? 0) === 1;
        if ($fakeModeQueued) {
            $content['fake_mode'] = 1;
        }
        if (
            $selectedSkillCodes !== []
            || \array_key_exists(AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY, $scopePatch)
            || \array_key_exists(AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY, $operationDetails)
            || \array_key_exists(AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY, $requestPayload)
            || \array_key_exists(AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY, $detailRequestPayload)
        ) {
            $content[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] = $selectedSkillCodes;
        }
        $content = $this->applyOperationDetailsToPayload($content, $operationDetails);
        if (\in_array($operation, ['build', 'block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
            $content['scope_patch'] = $this->compactAiSiteQueueScopePatchForStorage($operation, $scopePatch);
        }

        $queueName = $this->buildAiSiteQueueName($operation, $executionToken);
        $bizKey = $this->buildAiSiteQueueBizKey((int)$session->getId(), $operation);
        $retryOfQueueId = (int)($operationDetails['retry_of_queue_id'] ?? 0);
        $reusableQueueRow = null;
        if ($retryOfQueueId > 0) {
            $retryQueueRow = $this->findAiSiteQueueRowById($retryOfQueueId);
            if (\is_array($retryQueueRow) && $retryQueueRow !== [] && $this->isAiSiteQueueRowForOperation($retryQueueRow, $operation)) {
                $reusableQueueRow = $retryQueueRow;
            } else {
                throw new \RuntimeException(
                    'Original PageBuilder queue #' . $retryOfQueueId . ' is not available for retry.'
                );
            }
        } else {
            $reusableQueueRow = $this->findAiSiteOperationQueueRow($session, $operation);
        }
        $reuseFailureMessage = '';
        if (
            \is_array($reusableQueueRow)
            && $reusableQueueRow !== []
            && $this->shouldForceQueueTakeoverForOperation($operation, $scopePatch, $operationDetails)
            && $this->isAiSiteQueueRowLiveInProgress($reusableQueueRow)
        ) {
            $reusableQueueId = $this->resolveAiSiteQueueRowId($reusableQueueRow);
            if ($reusableQueueId > 0) {
                $takeover = w_query('queue', 'takeover', [
                    'queue_id' => $reusableQueueId,
                    'force' => true,
                    'owner' => 'system_scheduler',
                    'reason' => 'pagebuilder_replace_existing_queue',
                    'mark_force_rebuild' => true,
                    'clear_output' => false,
                    'wake_scheduler' => false,
                ]);
                if (!\is_array($takeover) || empty($takeover['success'])) {
                    $takeoverMessage = \is_array($takeover)
                        ? \trim((string)($takeover['message'] ?? ''))
                        : '';
                    throw new \RuntimeException($takeoverMessage !== '' ? $takeoverMessage : 'Queue takeover failed.');
                }
                $reloadedQueueRow = $this->findAiSiteQueueRowById($reusableQueueId, true);
                if (\is_array($reloadedQueueRow) && $reloadedQueueRow !== []) {
                    $reusableQueueRow = $reloadedQueueRow;
                }
            }
        }
        if (\is_array($reusableQueueRow) && $reusableQueueRow !== [] && $this->shouldReuseAiSiteQueueRow($reusableQueueRow, $preserveRunningQueueRow, $operation, $scopePatch, $operationDetails)) {
            $reusableQueueId = $this->resolveAiSiteQueueRowId($reusableQueueRow);
            $updated = w_query('queue', 'update', [
                'queue_id' => $reusableQueueId,
                'patch' => $this->buildAiSiteQueueReusePatch($queueName, $content, $bizKey, $typeId),
                'wake_scheduler' => false,
            ]);
            if (\is_array($updated) && ($updated['success'] ?? false)) {
                $queueId = (int)($updated['queue_id'] ?? 0);
                if ($queueId <= 0) {
                    $queueId = $reusableQueueId;
                }
                if ($queueId > 0) {
                    $this->stopSupersededPendingAiSiteQueueRows($bizKey, $queueId, $operation);
                    return $queueId;
                }
            }
            $reuseFailureMessage = \is_array($updated)
                ? \trim((string)($updated['message'] ?? ''))
                : 'invalid queue update response';
        }
        if ($retryOfQueueId > 0 && \is_array($reusableQueueRow) && $reusableQueueRow !== []) {
            $detail = $reuseFailureMessage !== '' ? ': ' . $reuseFailureMessage : '.';
            throw new \RuntimeException(
                'Original PageBuilder queue #' . $retryOfQueueId . ' could not be reset for retry' . $detail
            );
        }
        $created = w_query('queue', 'create', [
            'class' => $queueClass,
            'name' => $queueName,
            'module' => 'GuoLaiRen_PageBuilder',
            'content' => $content,
            'status' => 'pending',
            'auto' => true,
            'biz_key' => $bizKey,
            'wake_scheduler' => false,
        ]);
        $queueId = (int)(\is_array($created) ? ($created['queue_id'] ?? 0) : 0);
        if ($queueId <= 0 || !(\is_array($created) && ($created['success'] ?? false))) {
            $createFailureMessage = \is_array($created)
                ? \trim((string)($created['message'] ?? ''))
                : '';
            $detail = $createFailureMessage !== '' ? $createFailureMessage : $reuseFailureMessage;
            throw new \RuntimeException(
                $detail !== ''
                ? (string)__('Operation completed.')
                : (string)__('Operation failed.')
            );
        }
        $this->stopSupersededPendingAiSiteQueueRows($bizKey, $queueId, $operation);

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

    /**
     * Queue rows should identify the operation, not duplicate the session's
     * persisted build contract/artifacts. The runner reloads session scope and
     * composes the current task context at execution time.
     *
     * @param array<string, mixed> $scopePatch
     * @return array<string, mixed>
     */
    private function compactAiSiteQueueScopePatchForStorage(string $operation, array $scopePatch): array
    {
        if (!\in_array($operation, ['build', 'block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
            return $scopePatch;
        }

        $forceRebuild = (int)($scopePatch['_force_rebuild'] ?? 0) === 1;
        $selectedSkillCodes = $scopePatch[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] ?? null;
        $scopePatch = $this->planJsonTaskService->stripPlanJsonMutationScopePatch($scopePatch, []);
        foreach ([
            'plan_json_task_summary',
            'plan_projection',
            'content_manifest',
            'build_contracts',
            'render_data_contract',
            'qa_report_contract',
            'task_results',
            'qa_report_v2',
            'repair_patch',
        ] as $key) {
            unset($scopePatch[$key]);
        }
        if ($forceRebuild) {
            $scopePatch['_force_rebuild'] = 1;
        }
        if ($selectedSkillCodes !== null && !\array_key_exists(AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY, $scopePatch)) {
            $scopePatch[AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY] = $selectedSkillCodes;
        }

        return $scopePatch;
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
            'page_key',
            'page_types',
            'page_keys',
            'block_id',
            'block_ids',
            'component_code',
            'component_codes',
            'component_label',
            'component_labels',
            'instruction',
            'shared_region',
            'shared_regions',
            'stage_scope',
            'action',
            'source',
            'target_scope',
            'target_scopes',
            'block_key',
            'block_keys',
            'section_code',
            'section_codes',
            'task_key',
            'task_keys',
            'bucket',
            'buckets',
            'prompt_mode',
            'round',
            'mutation',
            'mutations',
            'block_config',
            'block_configs',
            'task_config',
            'task_configs',
            'operation_scope',
            'selection',
            'selected_blocks',
            'selected_tasks',
            'targets',
            'change_summary',
            'changed_fields',
            'reason',
            'retry_of_queue_id',
            'retry_source',
        ];
    }

    /**
     * Scope already blocks the live same-operation queue before we enqueue.
     * If a slot still looks running here, it is usually stale unless the caller
     * explicitly preserves the currently running row.
     *
     * @param array<string, mixed> $queueRow
     */
    private function shouldReuseAiSiteQueueRow(
        array $queueRow,
        bool $preserveRunningQueueRow = false,
        string $operation = '',
        array $scopePatch = [],
        array $operationDetails = []
    ): bool
    {
        if ($this->resolveAiSiteQueueRowId($queueRow) <= 0) {
            return false;
        }

        $status = \strtolower(\trim((string)($queueRow['status'] ?? '')));
        if (\in_array($status, ['running', 'processing'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $queueRow
     */
    private function resolveAiSiteQueueRowId(array $queueRow): int
    {
        $queueId = (int)($queueRow['queue_id'] ?? 0);
        if ($queueId > 0) {
            return $queueId;
        }

        return (int)($queueRow['id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $scopePatch
     * @param array<string, mixed> $operationDetails
     */
    private function buildAiSiteQueueReusePatch(string $queueName, array $content, string $bizKey, int $typeId): array
    {
        return [
            'name' => $queueName,
            'module' => 'GuoLaiRen_PageBuilder',
            'type_id' => $typeId,
            'content' => $content,
            'status' => 'pending',
            'auto' => true,
            'biz_key' => $bizKey,
            'result' => '',
            'process' => '',
            'pid' => 0,
            'finished' => 0,
        ];
    }

    private function stopSupersededPendingAiSiteQueueRows(string $bizKey, int $activeQueueId, string $operation): void
    {
        $bizKey = \trim($bizKey);
        if ($bizKey === '' || $activeQueueId <= 0 || !$this->isAiSiteQueueBackedOperation($operation)) {
            return;
        }

        try {
            $result = w_query('queue', 'list', [
                'biz_key' => $bizKey,
                'page_size' => 20,
            ]);
        } catch (\Throwable) {
            return;
        }

        foreach (\is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
            $row = \is_object($item) && \method_exists($item, 'getData') ? $item->getData() : $item;
            if (!\is_array($row)) {
                continue;
            }
            $queueId = $this->resolveAiSiteQueueRowId($row);
            if ($queueId <= 0 || $queueId === $activeQueueId) {
                continue;
            }
            $status = \strtolower(\trim((string)($row['status'] ?? '')));
            if (!\in_array($status, ['pending', 'queued'], true)) {
                continue;
            }
            if (!$this->isAiSiteQueueRowForOperation($row, $operation)) {
                continue;
            }
            try {
                w_query('queue', 'update', [
                    'queue_id' => $queueId,
                    'patch' => [
                        'status' => 'stop',
                        'pid' => 0,
                        'process' => 'Superseded by PageBuilder queue #' . $activeQueueId . '.',
                    ],
                ]);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function resolveAiSiteQueueClass(string $operation): string
    {
        return match ($operation) {
            'plan' => \GuoLaiRen\PageBuilder\Queue\AiSitePlanQueue::class,
            'build' => \GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue::class,
            'block_regenerate' => \GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue::class,
            'block_partial_patch' => \GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue::class,
            'regenerate_page' => \GuoLaiRen\PageBuilder\Queue\AiSiteBuildQueue::class,
            'image_asset' => \GuoLaiRen\PageBuilder\Queue\AiSiteAssetQueue::class,
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
            throw new \RuntimeException((string)__('Operation failed.'));
        }

        return $typeId;
    }

    /**
     * @return array{job_key?: string, job_type?: string, queue_status?: string, token?: string}
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
            'queue_status' => $status,
            'token' => $executionToken,
        ];
    }

    /**
     * @return array{queue_id:int, queue_waiting_for_scheduler:bool, can_close_stream:bool, continue_other_operations:bool, reason:string, message:string}
     */
    private function buildAiSiteQueueSchedulerWaitPayload(string $operation, int $queueId, string $reason = 'system_scheduler_wait'): array
    {
        $waiting = $queueId > 0;
        return [
            'queue_id' => $queueId,
            'queue_waiting_for_scheduler' => $waiting,
            'can_close_stream' => $waiting,
            'continue_other_operations' => $waiting,
            'reason' => $reason !== '' ? $reason : 'system_scheduler_wait',
            'message' => $waiting ? $this->buildAiSiteQueueSchedulerWaitMessage($operation, $queueId) : '',
        ];
    }

    /**
     * @param array<string, mixed> $activeOperation
     * @param array<string, mixed>|null $queueRow
     * @return array<string, mixed>
     */
    private function buildQueuedOperationState(
        AiSiteAgentSession $session,
        string $stageCode,
        string $operation,
        array $activeOperation,
        ?array $queueRow
    ): array {
        $queueInfo = \is_array($queueRow) && $queueRow !== []
            ? $this->buildQueueObserverPanelPayload($queueRow)
            : null;
        $queueStatus = \is_array($queueInfo)
            ? \trim((string)($queueInfo['queue_status'] ?? ''))
            : (\is_array($queueRow) ? \trim((string)($queueRow['status'] ?? '')) : '');
        $queueRecoveredForRetry = \is_array($queueInfo) && (
            !empty($queueInfo['queue_terminal_recovered'])
            || !empty($queueInfo['retry_allowed'])
            || \in_array(\trim((string)($queueInfo['semantic_status'] ?? '')), ['cancelled', 'canceled', 'stale'], true)
        );
        $activeOperationStatus = \trim((string)($activeOperation['queue_status'] ?? ''));
        if ($activeOperationStatus === 'pending') {
            $activeOperationStatus = 'queued';
        } elseif ($activeOperationStatus === 'processing') {
            $activeOperationStatus = 'running';
        }
        $operationStatus = \in_array($queueStatus, ['pending', 'queued', 'running'], true)
            ? ($queueStatus === 'running' ? 'running' : 'queued')
            : ($activeOperationStatus ?: 'queued');
        if ($queueRecoveredForRetry) {
            $operationStatus = 'cancelled';
        }

        $queueProcessLine = \is_array($queueRow) ? \trim((string)($queueRow['process'] ?? '')) : '';
        $waitingForScheduler = !$queueRecoveredForRetry && (
            $queueStatus === ''
                ? $operationStatus !== 'running'
                : \in_array($queueStatus, ['pending', 'queued'], true)
        );
        $queuedMessage = $waitingForScheduler
                ? (string)__('Operation completed.')
            : ($queueStatus === 'running'
                ? $queueProcessLine
                : (string)($activeOperation['message'] ?? ''));

        $activeOperation = \array_replace($activeOperation, [
            'operation' => $operation,
            'status' => $operationStatus,
            'queue_id' => \is_array($queueRow) ? (int)($queueRow['queue_id'] ?? 0) : (int)($activeOperation['queue_id'] ?? 0),
            'job_type' => $this->resolveAiSiteQueueJobType($operation),
            'message' => $queuedMessage,
            'queue_waiting_for_scheduler' => $waitingForScheduler,
            'can_close_stream' => true,
            'continue_other_operations' => !$queueRecoveredForRetry,
        ]);
        if ($queueRecoveredForRetry) {
            $activeOperation['retry_allowed'] = 1;
            $activeOperation['queue_terminal_recovered'] = 1;
            $activeOperation['semantic_status'] = 'cancelled';
        }

        $queuedWorkspaceStatus = $operation === 'publish'
            ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING
            : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        $state = [
            'public_id' => (string)$session->getPublicId(),
            'stage' => $stageCode,
            'workspace_status' => $queuedWorkspaceStatus,
            'can_publish' => false,
            'latest_build_failed' => false,
            'latest_build_failure' => [],
            'publish_blocked_by_latest_ai_failure' => false,
            'publish_blocked_reason' => '',
            'active_operation' => $activeOperation,
        ];
        $queueInfoKey = match ($operation) {
            'plan' => 'plan_queue_info',
            default => 'build_queue_info',
        };
        if (\is_array($queueInfo)) {
            $state[$queueInfoKey] = $queueInfo;
        }

        return \array_replace($state, $this->buildWorkspaceStatusEnvelope($state, 'queue'));
    }

    private function buildAiSiteQueueSchedulerWaitMessage(string $operation, int $queueId): string
    {
        return (string)__('Operation completed.');
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


    private function resolveAiSiteQueueReuseSlot(string $operation): string
    {
        return match ($operation) {
            'plan' => 'plan',
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
            'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'image_asset' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
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
        int $queueId = 0,
        bool $includePayload = false
    ): ?array {
        if ($queueId > 0) {
            $row = $this->findAiSiteQueueRowById($queueId, $includePayload);
            if (\is_array($row) && $row !== [] && $this->isAiSiteQueueRowForOperation($row, $operation)) {
                return $row;
            }
        }

        $row = $this->findAiSiteQueueRowByBizKey($this->buildAiSiteQueueBizKey((int)$session->getId(), $operation), $includePayload);
        if (\is_array($row) && $row !== [] && $this->isAiSiteQueueRowForOperation($row, $operation)) {
            return $row;
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

        $bizKeyMatch = $this->resolveAiSiteQueueRowBizKeyOperationMatch($row, $operation);
        if ($bizKeyMatch !== null) {
            return $bizKeyMatch;
        }

        $content = $row['content'] ?? null;
        if (\is_string($content)) {
            $content = \strlen($content) > self::AI_SITE_QUEUE_MAX_CONTENT_JSON_DECODE_BYTES
                ? $this->extractAiSiteQueueContentEnvelope($content)
                : (\is_array($decoded = \json_decode($content, true)) ? $decoded : null);
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

        if (\preg_match('/(?:^|:)asset:/', $bizKey) === 1) {
            return $operation === 'image_asset';
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
    private function findAiSiteQueueRowById(int $queueId, bool $includePayload = false): ?array
    {
        if ($queueId <= 0) {
            return null;
        }

        try {
            /** @var \Weline\Queue\Model\Queue $queue */
            $queue = clone ObjectManager::getInstance(\Weline\Queue\Model\Queue::class);
            $rows = $queue->clearData()
                ->reset()
                ->fields($includePayload ? self::AI_SITE_QUEUE_CONTENT_PAYLOAD_FIELDS : self::AI_SITE_QUEUE_CONTENT_LIGHT_FIELDS)
                ->where(\Weline\Queue\Model\Queue::schema_fields_ID, $queueId)
                ->limit(1)
                ->select()
                ->fetchArray();
            $row = \is_array($rows[0] ?? null) ? $rows[0] : [];

            return $row !== [] ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAiSiteQueueRowByBizKey(string $bizKey, bool $includePayload = false): ?array
    {
        $bizKey = \trim($bizKey);
        if ($bizKey === '') {
            return null;
        }

        $rows = $this->findAiSiteQueueRowsByBizKey($bizKey, $includePayload);

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findAiSiteQueueRowsByBizKey(string $bizKey, bool $includePayload = false): array
    {
        $bizKey = \trim($bizKey);
        if ($bizKey === '') {
            return [];
        }

        try {
            /** @var \Weline\Queue\Model\Queue $queue */
            $queue = clone ObjectManager::getInstance(\Weline\Queue\Model\Queue::class);
            $items = $queue->clearData()
                ->reset()
                ->fields($includePayload ? self::AI_SITE_QUEUE_CONTENT_PAYLOAD_FIELDS : self::AI_SITE_QUEUE_CONTENT_LIGHT_FIELDS)
                ->where(\Weline\Queue\Model\Queue::schema_fields_BIZ_KEY, $bizKey)
                ->order(\Weline\Queue\Model\Queue::schema_fields_ID, 'DESC')
                ->limit(20)
                ->select()
                ->fetchArray();
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


    /**
     * Comment removed.
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
        $scope = $this->planJsonTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks
        );
        $queueForcedAiRebuild = (int)($scope['_queue_force_build']['active'] ?? 0) === 1;
        if ($queueForcedAiRebuild) {
            $scope = $this->planJsonTaskService->clearBuildArtifactsForRegeneration($scope);
            $scope = $this->planJsonTaskService->resetPlanJsonTasksToPendingForRebuild($scope, false);
        } else {
            $scope = $this->planJsonTaskService->reconcileGeneratedArtifactsWithTaskState($scope);
        }
        // Comment removed.
        // Comment removed.
        // Comment removed.
        // Comment removed.
        $scope = $this->planJsonTaskService->resetRunningTasksForInterruptedBuild(
            $scope,
            'Build entry: clearing stale running tasks before scheduling.'
        );
        $scope = $this->planJsonTaskService->applyPagesMarkedSkipRemaining($scope);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $pageTypeLabels = Page::getPageTypes();
        // Keep one queue owner, but generate independent page sections in bounded parallel batches.
        $dispatchWindow = $this->resolvePageSectionBuildDispatchWindow();
        $initialPendingTasks = $this->planJsonTaskService->listPendingTasks($scope);
        $totalSteps = \max(1, \count($initialPendingTasks) + 1);
        $currentStep = 0;

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Preparing website workspace'), $progressPercent);
        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Preparing optional image asset slots'), \min(99, $progressPercent + 1));
        $autoAssetResult = $this->prepareBuildImageAssets($session, $adminId, $scope);
        $scope = $autoAssetResult['scope'];
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        if ($autoAssetResult['generated_slots'] !== []) {
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('Generated %{count} AI image assets before page build.', ['count' => (string)\count($autoAssetResult['generated_slots'])]),
                [
                    'event_type' => 'build_asset_generation_completed',
                    'generated_slots' => $autoAssetResult['generated_slots'],
                    'state' => $this->buildAssetIdentityWorkspaceStatePatchFromScope($scope),
                ]
            );
        }
        foreach ($autoAssetResult['failed_slots'] as $failedSlot) {
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('AI image asset generation failed for %{slot}: %{message}', [
                    'slot' => (string)($failedSlot['slot_id'] ?? ''),
                    'message' => (string)($failedSlot['message'] ?? ''),
                ]),
                [
                    'event_type' => 'build_asset_generation_failed',
                    'slot_id' => (string)($failedSlot['slot_id'] ?? ''),
                    'message' => (string)($failedSlot['message'] ?? ''),
                    'state' => $this->buildAssetIdentityWorkspaceStatePatchFromScope($scope),
                ]
            );
        }
        $this->assertBuildImageAssetsReady($autoAssetResult['failed_slots']);

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
        $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [];
        $environmentReadyEmitted = false;
        $parallelPageModeLogged = false;
        $buildLoopStallPasses = 0;

        while (true) {
            $taskBatch = $this->planJsonTaskService->pickConcurrentTasks($scope, $dispatchWindow);
            if ($taskBatch === []) {
                if ($this->planJsonTaskService->hasUnfinishedBlueprintTasks($scope)) {
                    $resetScope = $this->planJsonTaskService->resetRunningTasksForInterruptedBuild(
                        $scope,
                    (string)__('Operation completed.'),
                    );
                    if ($resetScope !== $scope && $buildLoopStallPasses < 32) {
                        $buildLoopStallPasses++;
                        $scope = $resetScope;
                        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                        $this->emitPlanJsonTaskProgressStateFromScope(
                            $sse,
                            $scope,
                            'build',
                            (string)__('Operation completed.'),
                            $this->resolveTaskSummaryProgressPercent($this->planJsonTaskService->summarize($scope))
                        );
                        continue;
                    }
                }
                break;
            }
            $buildLoopStallPasses = 0;

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

            if ($sharedTasks !== []) {
                $sharedTaskByRegion = [];
                foreach ($sharedTasks as $task) {
                    $this->assertActiveStreamLeaseAlive($session, $adminId);
                    $taskKey = (string)($task['task_key'] ?? '');
                    $region = (string)($task['region'] ?? '');
                    if ($taskKey === '' || $region === '') {
                        continue;
                    }
                    $currentStep++;
                    $sharedTaskByRegion[$region] = ['task' => $task, 'task_key' => $taskKey, 'progress_percent' => (int)(($currentStep / $totalSteps) * 100)];
                    $scope = $this->planJsonTaskService->markTaskRunning($scope, $taskKey);
                }
                if ($sharedTaskByRegion !== []) {
                    $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                    $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Generating shared header/footer'), (int)(($currentStep / $totalSteps) * 100));
                    if ((int)($scope['fake_mode'] ?? 0) === 1) {
                        foreach ($sharedTaskByRegion as $region => $taskMeta) {
                            $taskKey = (string)($taskMeta['task_key'] ?? '');
                            if ($taskKey === '') {
                                continue;
                            }
                            $component = $this->buildFakePlanJsonSharedComponent((string)$region, Page::TYPE_HOME, $scope['website_profile'], $scope);
                            $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, [
                                'region' => (string)$region,
                                'component' => $component,
                                'fake_mode' => 1,
                            ]);
                            $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [];
                            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                        }
                        continue;
                    }
                    foreach ($pageComponentGenerationService->generateSharedComponentEventsConcurrently($scope['website_profile'], $scope, \array_keys($sharedTaskByRegion)) as $region => $event) {
                        $taskMeta = \is_array($sharedTaskByRegion[(string)$region] ?? null) ? $sharedTaskByRegion[(string)$region] : [];
                        $taskKey = (string)($taskMeta['task_key'] ?? '');
                        $progressPercent = (int)($taskMeta['progress_percent'] ?? 0);
                        if ($taskKey === '') {
                            continue;
                        }
                        if (($event['status'] ?? '') !== 'fulfilled' || !\is_array($event['result'] ?? null) || $event['result'] === []) {
                            $throwable = ($event['error'] ?? null) instanceof \Throwable
                                ? $event['error']
                                : new \RuntimeException('Shared component generation returned an empty result.');
                            $failure = $this->handlePlanJsonTaskGenerationFailure($sse, $session, $adminId, $scope, $taskKey, 'shared_component', AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks, $throwable, [
                                'region' => (string)$region,
                                'progress_percent' => $progressPercent,
                            ]);
                            $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                            if (!empty($failure['fatal'])) {
                                throw $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : $throwable;
                            }
                            continue;
                        }
                        $component = $event['result'];
                        $sharedComponents[(string)$region] = $component;
                        $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, [
                            'region' => (string)$region,
                            'component' => $component,
                        ]);
                        $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [];
                        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                        $message = (string)$region === 'header' ? 'Shared header generated' : 'Shared footer generated';
                        $this->appendWorkspaceEvent($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'shared_component_generated', $message, ['operation' => 'build', 'details' => ['region' => (string)$region]]);
                        $freshShared = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                        $sharedState = $this->buildWorkspaceEventStatePayload($this->buildWorkspaceState($freshShared, $adminId, 80, true));
                        $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                            'task_key' => $taskKey,
                            'task_type' => 'shared_component',
                            'message' => $message,
                            'state' => $sharedState,
                        ]));
                    }
                }
            }

            if ($pageTasks === []) {
                continue;
            }

            if (!$parallelPageModeLogged) {
                $parallelPageModeLogged = true;
                $this->emitBuildInfoEvent($sse, 'Shared theme ready; remaining pages will be generated in concurrent batches.', [
                    'event_type' => 'build_parallel_mode_enabled',
                    'parallel' => true,
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
                ]);
            }

            $batchSpecs = $this->buildConcurrentPageTaskBatchSpecs(
                $pageComponentGenerationService,
                $pageTasks,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                $scope,
                $pageTypeLabels,
                $session,
                $adminId
            );
            $componentSpecs = \is_array($batchSpecs['components'] ?? null) ? $batchSpecs['components'] : [];
            $taskMeta = \is_array($batchSpecs['meta'] ?? null) ? $batchSpecs['meta'] : [];
            $taskSpecErrors = \is_array($batchSpecs['errors'] ?? null) ? $batchSpecs['errors'] : [];
            $retryTaskKeys = [];
            $failedTaskKeys = [];
            $fatalBatchThrowable = null;
            foreach ($taskSpecErrors as $taskKey => $error) {
                $taskKey = (string)$taskKey;
                $scope = $this->planJsonTaskService->markTaskRunning($scope, $taskKey);
                $failure = $this->handlePlanJsonTaskGenerationFailure(
                    $sse,
                    $session,
                    $adminId,
                    $scope,
                    $taskKey,
                    'page_section',
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
                    new \RuntimeException((string)(\is_array($error) ? ($error['message'] ?? 'Build task spec error') : 'Build task spec error')),
                    [
                        'page_type' => \is_array($error) ? (string)($error['page_type'] ?? '') : '',
                        'section_code' => \is_array($error) ? (string)($error['section_code'] ?? '') : '',
                        'progress_percent' => $currentStep > 0 ? (int)(($currentStep / $totalSteps) * 100) : 0,
                    ]
                );
                $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                if (!empty($failure['fatal'])) {
                    if (!$fatalBatchThrowable instanceof \Throwable) {
                        $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : new \RuntimeException('Build task spec error');
                    }
                    $failedTaskKeys[] = $taskKey;
                    continue;
                }
                $failedTaskKeys[] = $taskKey;
            }
            if ($componentSpecs === []) {
                if ($fatalBatchThrowable instanceof \Throwable) {
                    throw $fatalBatchThrowable;
                }
                continue;
            }

            $runningTaskKeys = \array_values(\array_map('strval', \array_keys($componentSpecs)));
            foreach ($runningTaskKeys as $runningTaskKey) {
                $scope = $this->planJsonTaskService->markTaskRunning($scope, $runningTaskKey);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->emitPlanJsonTaskProgressStateFromScope(
                $sse,
                $scope,
                'build',
                (string)__('Operation completed.'),
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
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
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
                    $failure = $this->handlePlanJsonTaskGenerationFailure(
                        $sse,
                        $session,
                        $adminId,
                        $scope,
                        (string)$taskKey,
                        'page_section',
                        AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
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
                        if (!$fatalBatchThrowable instanceof \Throwable) {
                            $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : $throwable;
                        }
                        $failedTaskKeys[] = (string)$taskKey;
                        continue;
                    }
                    $failedTaskKeys[] = (string)$taskKey;
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
                $scope = $this->withPlanJsonPages($scope, $virtualPages);
                $scope['virtual_theme_id'] = 0;
                $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? $pageType));
                $scope = $this->planJsonTaskService->markTaskDone($scope, (string)$taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);
                $materialized = $this->materializeGeneratedPages(
                    AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
                    (int)$draftWebsite['website_id'],
                    $scope['website_profile'],
                    [$pageType],
                    [],
                    [$pageType => $row]
                );
                $scope = $this->mergeMaterializedPagesIntoScope($scope, $materialized);
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                $pageId = (int)($scope['materialized_pages_by_type'][$pageType]['page_id'] ?? 0);
                if (!$environmentReadyEmitted && $pageId > 0) {
                    $environmentReadyEmitted = true;
                    $this->emitBuildEnvironmentReady($sse, $session, $adminId, $pageType, $pageLabel, $pageId, 0);
                }

                $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $state = $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true),
                    [$pageType]
                );
                $pageCompleted = $this->planJsonTaskService->arePageTasksComplete($scope, $pageType);
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
                        'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
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
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_html_blocks,
                    'batch_size' => \count($runningTaskKeys),
                    'page_types' => $batchPageTypes,
                    'task_keys' => $runningTaskKeys,
                    'completed_task_keys' => $completedTaskKeys,
                    'retry_task_keys' => $retryTaskKeys,
                    'failed_task_keys' => $failedTaskKeys,
                ]
            );
            $this->emitPlanJsonTaskProgressStateFromScope(
                $sse,
                $scope,
                'build',
                (string)__('Operation completed.'),
                $this->resolveTaskSummaryProgressPercent($this->planJsonTaskService->summarize($scope))
            );
        }

        $now = \date('Y-m-d H:i:s');
        $htmlTrackReady = $this->scopeCompatibilityService->htmlTrackHasCompleteBlocks($virtualPages, $pageTypes)
            || $this->htmlTrackHasMaterializedAiBlocks($scope, $virtualPages, $pageTypes);
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts([], $pageTypes);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0) {
            $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
        }

        $scope = $this->planJsonTaskService->finalizePlanJsonTaskStatesAfterRunLoop($scope);
        $scope = $this->planJsonTaskService->syncPlanJsonTaskFailuresToRetryableLedger($scope);
        $completionGate = $this->planJsonTaskService->inspectBuildCompletionGate($scope);
        $taskSummary = \is_array($completionGate['summary'] ?? null) ? $completionGate['summary'] : [];
        $hasBuildFailures = (int)($taskSummary['failed'] ?? 0) > 0 || $this->planJsonTaskService->hasRetryableAiFailures($scope, 'build');
        $hasOutstandingTasks = empty($completionGate['passed']);
        if (!$htmlTrackReady && !$hasBuildFailures) {
            throw new \RuntimeException((string)__('Operation failed.'));
        }
        $canPublishBuild = !$hasOutstandingTasks && $htmlTrackReady && !$hasBuildFailures;
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'last_generated_at' => $now,
            'active_operation' => 'build',
            'can_publish' => $canPublishBuild,
        ];
        $scope['plan_json_task_summary'] = $taskSummary;
        $scope['can_publish'] = $canPublishBuild ? 1 : 0;
        $scope['workspace_status'] = ($hasBuildFailures || $hasOutstandingTasks)
            ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED
            : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            ($hasBuildFailures || $hasOutstandingTasks) ? [
                'queue_status' => 'error',
                'updated_at' => $now,
                'message' => $hasBuildFailures
                    ? (string)__('HTML block build failed; unfinished AI items will retry on the same queue.')
                    : (string)__('Operation failed.'),
                'retry_allowed' => 0,
                'failure_mode' => 'build_failed',
                'retryable_ai_failure_count' => (int)($scope['retryable_ai_failure_count'] ?? 0),
                'queue_waiting_for_scheduler' => false,
            ] : [
                'queue_status' => 'done',
                'updated_at' => $now,
                'message' => (string)__('HTML blocks generated'),
                'queue_waiting_for_scheduler' => false,
            ]
        );
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, 0);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);
        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'build',
            ($hasBuildFailures || $hasOutstandingTasks)
                ? ($hasBuildFailures
                    ? __('HTML block build failed; unfinished AI items will retry on the same queue.')
                    : __('Operation failed'))
                : __('HTML blocks ready for preview or publish'),
            100,
            '',
            ($hasBuildFailures || $hasOutstandingTasks) ? 'error' : 'done',
            (string)($scope['workspace_status'] ?? '')
        );

        return [
            'message' => $hasBuildFailures
                ? (string)__('HTML block build failed; unfinished AI items will retry on the same queue.')
                : ($hasOutstandingTasks
                ? (string)__('Operation completed.')
                    : (string)__('HTML block build complete')),
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
        $dropNonPlanJsonPageBlocks = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? '')) === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
            || (string)($sectionBlock['type'] ?? '') === 'ai_generated_section';
        $allowedGeneratedBlockIds = $this->buildAllowedGeneratedContentBlockIdMap($pageType, $scope, $sectionBlockId);
        foreach ($existingBlocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $blockId = \trim((string)($block['block_id'] ?? ''));
            if ($this->isHtmlSharedBlock($blockId, $block)) {
                continue;
            }
            if ($dropNonPlanJsonPageBlocks && !$this->isGeneratedHtmlContentBlock($blockId, $block)) {
                continue;
            }
            if ($dropNonPlanJsonPageBlocks
                && $this->isGeneratedHtmlContentBlock($blockId, $block)
                && $allowedGeneratedBlockIds !== []
                && !isset($allowedGeneratedBlockIds[$blockId])
            ) {
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
     * @return array<string, true>
     */
    private function buildAllowedGeneratedContentBlockIdMap(string $pageType, array $scope, string $currentBlockId = ''): array
    {
        $allowed = [];
        $currentBlockId = \trim($currentBlockId);
        if ($currentBlockId !== '') {
            $allowed[$currentBlockId] = true;
        }

        $page = \is_array($scope['plan_json']['pages'][$pageType] ?? null) ? $scope['plan_json']['pages'][$pageType] : [];
        foreach ($this->planJsonDynamicBlocks($page) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $blockId = $this->normalizeGeneratedComponentCodeToBlockId((string)($item['code'] ?? $item['component'] ?? ''));
            if ($blockId !== '') {
                $allowed[$blockId] = true;
            }
        }

        foreach ($this->planJsonTaskService->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $definition = $this->planJsonTaskService->getTaskDefinition($scope, $taskKey);
            if (!\is_array($definition)) {
                continue;
            }
            $blockId = $this->normalizeGeneratedComponentCodeToBlockId((string)($definition['section_code'] ?? ''));
            if ($blockId !== '') {
                $allowed[$blockId] = true;
            }
        }

        return $allowed;
    }

    private function normalizeGeneratedComponentCodeToBlockId(string $componentCode): string
    {
        $componentCode = \trim($componentCode);
        if ($componentCode === '') {
            return '';
        }

        return \str_replace(['content/', '/'], ['', '-'], $componentCode);
    }

    private function normalizeLayoutComponentIdentity(string $value): string
    {
        $value = \strtolower(\trim($value));
        if ($value === '') {
            return '';
        }

        return \str_replace(['/', '_'], '-', $value);
    }

    /**
     * @param array<int,array<string,mixed>|mixed> $content
     * @return array<int,array<string,mixed>|mixed>
     */
    private function dedupeLayoutContentAfterBlockConfigUpdate(
        array $content,
        int $matchedIndex,
        string $normalizedComponentCode,
        string $normalizedBlockId
    ): array {
        if (!isset($content[$matchedIndex]) || !\is_array($content[$matchedIndex])) {
            return $content;
        }

        $matchedItem = $content[$matchedIndex];
        $matchedCode = $this->normalizeLayoutComponentIdentity((string)($matchedItem['code'] ?? $matchedItem['component'] ?? ''));
        $matchedInstance = $this->normalizeLayoutComponentIdentity((string)($matchedItem['instance_id'] ?? ''));
        $targets = \array_filter(\array_unique([$matchedCode, $matchedInstance, $normalizedComponentCode, $normalizedBlockId]));
        if ($targets === []) {
            return $content;
        }

        foreach ($content as $index => $item) {
            if ((int)$index === $matchedIndex || !\is_array($item)) {
                continue;
            }
            $itemCode = $this->normalizeLayoutComponentIdentity((string)($item['code'] ?? $item['component'] ?? ''));
            $itemInstance = $this->normalizeLayoutComponentIdentity((string)($item['instance_id'] ?? ''));
            if (($itemCode !== '' && \in_array($itemCode, $targets, true)) || ($itemInstance !== '' && \in_array($itemInstance, $targets, true))) {
                unset($content[$index]);
            }
        }

        return \array_values($content);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, array<string, mixed>> $virtualPages
     * @return list<array<string, mixed>>
     */
    private function resolveExistingAiHtmlBlocksForPage(string $pageType, array $scope, array $virtualPages = []): array
    {
        foreach ([
            $this->planJsonPageBlockList($scope, $pageType),
            $virtualPages[$pageType]['blocks'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                $blocks = \array_values(\array_filter($candidate, static function (mixed $block): bool {
                    return \is_array($block)
                        && \trim((string)($block['html'] ?? $block['html_content'] ?? $block['phtml'] ?? '')) !== '';
                }));
                if ($blocks !== []) {
                    return $blocks;
                }
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, array<string, mixed>> $virtualPages
     * @param list<string> $pageTypes
     */
    private function htmlTrackHasMaterializedAiBlocks(array $scope, array $virtualPages, array $pageTypes): bool
    {
        if ($pageTypes === []) {
            return false;
        }

        foreach ($pageTypes as $pageType) {
            if ($this->resolveExistingAiHtmlBlocksForPage($pageType, $scope, $virtualPages) === []) {
                return false;
            }
        }

        return true;
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
        $scope = $this->withPlanJsonPages($scope, $virtualPages);
        $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? ''));
        $scope = $this->dropPrePublishMaterializedPagesFromVirtualThemeScope($scope, $session);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, $websiteId);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)($scope['virtual_theme_id'] ?? 0));

        return $scope;
    }

    /**
     * plan_json.pages writes generated content into the virtual theme first. Entity
     * Page ids are publish artifacts only, so stale pre-publish materialization
     * must not leak back into the editing workspace.
     *
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function dropPrePublishMaterializedPagesFromVirtualThemeScope(array $scope, AiSiteAgentSession $session): array
    {
        $track = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if (
            $track !== AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
            || $session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED
        ) {
            return $scope;
        }

        $scope['materialized_pages_by_type'] = [];
        $scope['preview_page_id'] = 0;
        if (\is_array($scope['plan_json']['pages'] ?? null)) {
            foreach ($scope['plan_json']['pages'] as $pageType => $pageData) {
                if (!\is_array($pageData)) {
                    continue;
                }
                unset($pageData['page_id'], $pageData['materialized_page_id']);
                $scope['plan_json']['pages'][$pageType] = $pageData;
            }
        }

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

    /**
     * @param array<string,mixed> $layout
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function sortVirtualThemeLayoutContentByPlanJsonTasks(array $layout, array $scope, string $pageType): array
    {
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        if ($content === []) {
            return $layout;
        }

        $taskOrder = [];
        $index = 0;
        foreach ($this->planJsonTaskService->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $definition = $this->planJsonTaskService->getTaskDefinition($scope, (string)$taskKey);
            if (!\is_array($definition)) {
                continue;
            }
            $sectionCode = \trim((string)($definition['section_code'] ?? ''));
            if ($sectionCode === '') {
                continue;
            }
            $taskOrder[$sectionCode] = $index;
            $taskOrder[\str_replace('/', '-', $sectionCode)] = $index;
            $index++;
        }
        if ($taskOrder === []) {
            return $layout;
        }

        \usort($content, static function (array $left, array $right) use ($taskOrder): int {
            $leftCode = \trim((string)($left['code'] ?? $left['component'] ?? ''));
            $rightCode = \trim((string)($right['code'] ?? $right['component'] ?? ''));
            $leftOrder = $taskOrder[$leftCode] ?? $taskOrder[\str_replace('/', '-', $leftCode)] ?? 100000;
            $rightOrder = $taskOrder[$rightCode] ?? $taskOrder[\str_replace('/', '-', $rightCode)] ?? 100000;
            if ($leftOrder !== $rightOrder) {
                return $leftOrder <=> $rightOrder;
            }

            return ((int)($left['sort_order'] ?? 0)) <=> ((int)($right['sort_order'] ?? 0));
        });

        foreach ($content as $offset => &$row) {
            if (\is_array($row)) {
                $row['sort_order'] = ($offset + 1) * 10;
            }
        }
        unset($row);
        $layout['content'] = \array_values($content);

        return $layout;
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
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildAssetIdentityWorkspaceStatePatchFromScope(array $scope): array
    {
        $websiteProfile = \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [];
        $assetManifest = \is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : ['version' => 1, 'slots' => []];
        $verifiedAssets = \is_array($scope['verified_assets'] ?? null) ? $scope['verified_assets'] : [];
        $scopePatch = [
            'website_profile' => $websiteProfile,
            'asset_manifest' => $assetManifest,
            'verified_assets' => $verifiedAssets,
        ];
        foreach (['logo', 'icon', 'favicon'] as $key) {
            $value = \trim((string)($scope[$key] ?? ''));
            if ($value !== '') {
                $scopePatch[$key] = $value;
            }
        }

        return [
            'website_profile' => $websiteProfile,
            'asset_manifest' => $assetManifest,
            'verified_assets' => $verifiedAssets,
            'scope' => $scopePatch,
        ];
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
        array $pageTypeLabels,
        ?AiSiteAgentSession $session = null,
        int $adminId = 0
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
                $errors[$taskKey] = [
                    'task_key' => $taskKey,
                    'page_type' => $pageType,
                    'page_label' => (string)($pageTypeLabels[$pageType] ?? $pageType),
                    'section_code' => $sectionCode,
                    'message' => 'Build task missing plan-json section spec: ' . $taskKey,
                ];
                continue;
            }
            $resolvedSectionCode = \trim((string)($sectionSpec['code'] ?? $sectionCode));
            $resolvedSectionCode = $resolvedSectionCode !== '' ? $resolvedSectionCode : $sectionCode;

            $defaultConfig = \is_array($sectionSpec['default_config'] ?? null) ? $sectionSpec['default_config'] : [];
            $renderContext = \is_array($sectionSpec['render_context'] ?? null) ? $sectionSpec['render_context'] : [];
            if (
                $session instanceof AiSiteAgentSession
                && $adminId > 0
                && $this->sectionRequiresInlineImageAssetGenerator($defaultConfig, $renderContext)
            ) {
                $renderContext['_inline_image_asset_generator'] = $this->buildInlineImageAssetGenerator(
                    $session,
                    $adminId,
                    $scope,
                    $pageType,
                    $resolvedSectionCode,
                    (string)($sectionSpec['name'] ?? $resolvedSectionCode)
                );
            }

            $components[$taskKey] = [
                'componentCode' => $resolvedSectionCode,
                'name' => (string)($sectionSpec['name'] ?? $resolvedSectionCode),
                'region' => (string)($sectionSpec['region'] ?? 'content'),
                'prompt' => (string)($sectionSpec['prompt'] ?? ''),
                'defaultConfig' => $defaultConfig,
                'renderContext' => $renderContext,
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
     * @param array<string,mixed> $defaultConfig
     * @param array<string,mixed> $renderContext
     */
    private function sectionRequiresInlineImageAssetGenerator(array $defaultConfig, array $renderContext): bool
    {
        if (\trim((string)($defaultConfig['runtime.section_image_required'] ?? '')) === '1') {
            return true;
        }

        $visualContract = \is_array($renderContext['_visual_contract'] ?? null)
            ? $renderContext['_visual_contract']
            : [];
        if ($visualContract === []) {
            $raw = $defaultConfig['runtime.visual_contract_json'] ?? null;
            if (\is_string($raw) && \trim($raw) !== '') {
                $decoded = \json_decode($raw, true);
                $visualContract = \is_array($decoded) ? $decoded : [];
            }
        }

        if ((int)($visualContract['required'] ?? 0) === 1
            || (int)($visualContract['needs_image'] ?? 0) === 1) {
            return true;
        }

        $imageIntentRaw = $defaultConfig['runtime.block_image_intent_json'] ?? null;
        if (\is_string($imageIntentRaw) && \trim($imageIntentRaw) !== '') {
            $decodedIntent = \json_decode($imageIntentRaw, true);
            if (\is_array($decodedIntent) && $this->decodeImageIntentNeedsImage($decodedIntent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $imageIntent
     */
    private function decodeImageIntentNeedsImage(array $imageIntent): bool
    {
        $value = $imageIntent['needs_image'] ?? $imageIntent['required'] ?? null;
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'required', 'needed'], true);
    }

    /**
     * @param array<string, mixed> $validation
     */
    private function summarizeStageOnePlanValidationIssues(array $validation): string
    {
        $issues = \is_array($validation['issues'] ?? null) ? $validation['issues'] : [];
        if ($issues === []) {
            return '';
        }
        $parts = [];
        foreach (\array_slice($issues, 0, 6) as $issue) {
            if (!\is_array($issue)) {
                continue;
            }
            $code = \trim((string)($issue['code'] ?? $issue['reason_code'] ?? 'invalid'));
            $path = \trim((string)($issue['path'] ?? $issue['field_path'] ?? ''));
            $parts[] = ($path !== '' ? $path : 'stage1') . '=' . ($code !== '' ? $code : 'invalid');
        }

        return $parts !== [] ? \implode('; ', $parts) : '';
    }

    /**
     * @param array<string,mixed> $scope
     */
    private function buildInlineImageAssetGenerator(
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        string $pageType,
        string $sectionCode,
        string $sectionName
    ): \Closure {
        $assetScope = $this->buildInlineImageAssetScope($scope);
        return function (string $slotId, array $defaultConfig = [], array $renderContext = []) use (
            $session,
            $adminId,
            $assetScope,
            $pageType,
            $sectionCode,
            $sectionName
        ): array {
            /** @var AiSiteAutoAssetGenerationService $assetService */
            $assetService = ObjectManager::getInstance(AiSiteAutoAssetGenerationService::class);
            $visualContract = [];
            $visualContractRaw = $defaultConfig['runtime.visual_contract_json'] ?? null;
            if (\is_string($visualContractRaw) && \trim($visualContractRaw) !== '') {
                $decodedVisualContract = \json_decode($visualContractRaw, true);
                $visualContract = \is_array($decodedVisualContract) ? $decodedVisualContract : [];
            } elseif (\is_array($renderContext['_visual_contract'] ?? null)) {
                $visualContract = $renderContext['_visual_contract'];
            }
            $promptBrief = \trim((string)($defaultConfig['content.description'] ?? $defaultConfig['content.body'] ?? $defaultConfig['runtime.section_goal'] ?? ''));
            $visualSubject = \trim((string)($visualContract['subject'] ?? ''));
            $visualStyle = \trim((string)($visualContract['style'] ?? ''));
            if ($visualSubject !== '') {
                $promptBrief = \trim($visualSubject . ($visualStyle !== '' ? "\nStyle: " . $visualStyle : ''));
            }
            if ($promptBrief === '') {
                $promptBrief = 'Premium website section visual for ' . ($sectionName !== '' ? $sectionName : $sectionCode);
            }
            $slotType = \trim((string)($visualContract['slot_type'] ?? ''));
            if ($slotType === '') {
                $slotType = (int)($visualContract['strict_hero_cover'] ?? 0) === 1 ? 'hero_image' : 'section_image';
            }
            $slotSeed = [
                'slot_id' => $slotId,
                'slot_type' => $slotType,
                'kind' => $slotType === 'hero_image' ? 'hero_banner_background' : 'section_visual',
                'page_type' => $pageType,
                'section_code' => $sectionCode,
                'label' => $sectionName !== '' ? $sectionName : $sectionCode,
                'prompt_brief' => $promptBrief,
                'visual_contract' => $visualContract,
                'strict_hero_cover' => (int)($visualContract['strict_hero_cover'] ?? 0) === 1 ? 1 : 0,
                'status' => 'pending',
                'source' => 'planned',
                'final_url' => '',
                'locked_by_user' => 0,
                'image_generation_max_attempts' => 1,
            ];
            $latestAssetScope = $this->refreshInlineImageStateFromPersistedScope($session, $adminId, $assetScope);
            $result = $assetService->generateSlotAsset($session, $adminId, $latestAssetScope, $slotId, $slotSeed);
            if (\trim((string)($result['final_url'] ?? '')) === '' && \is_array($result['failed_slot'] ?? null)) {
                throw new \RuntimeException('Inline block image generation failed for ' . $slotId . ': ' . (string)($result['failed_slot']['message'] ?? 'unknown error'));
            }
            $resultScope = \is_array($result['scope'] ?? null) ? $result['scope'] : [];
            if ($resultScope !== []) {
                $this->persistInlineImageScopePatch($session, $adminId, $resultScope);
            }

            return $result;
        };
    }

    /**
     * Keep image generation prompts domain-aware without copying the full build
     * scope into every concurrent component closure.
     *
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildInlineImageAssetScope(array $scope): array
    {
        $keys = [
            'asset_manifest',
            'verified_assets',
            'asset_block_cache',
            'asset_image_generation_failures',
            'reference_image_insights',
            'reference_image_insights_signature',
            'website_profile',
            'brand_assets',
            'identity_assets',
            'logo',
            'icon',
            'favicon',
            'target_domain',
            'selected_domain',
            'domain',
            'site_domain',
            'public_domain',
            'default_locale',
            'default_language',
            'plan_locale',
            'content_generation_gate_required',
            'content_generation_gate_skip',
            'auto_generate_identity_assets_first',
            'auto_asset_prebuild_identity_only',
            'plan_generated_source_signature',
        ];
        $assetScope = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $scope)) {
                $assetScope[$key] = $scope[$key];
            }
        }

        return $assetScope;
    }

    /**
     * @param array<string,mixed> $resultScope
     */
    private function persistInlineImageScopePatch(AiSiteAgentSession $session, int $adminId, array $resultScope): void
    {
        $currentScope = $this->sessionService->loadScope($session);
        $resultScope = $this->mergeInlineImageResultScope($currentScope, $resultScope);
        $patch = [];
        foreach (['asset_manifest', 'asset_block_cache', 'verified_assets', 'asset_image_generation_failures'] as $key) {
            if (\array_key_exists($key, $resultScope)) {
                $patch[$key] = $resultScope[$key];
            }
        }
        foreach (['brand_assets', 'identity_assets', 'website_profile'] as $key) {
            if (\array_key_exists($key, $resultScope) && \is_array($resultScope[$key])) {
                $patch[$key] = $resultScope[$key];
            }
        }
        if ($patch !== []) {
            $this->sessionService->mergeScope((int)$session->getId(), $adminId, $patch);
        }
    }

    /**
     * Concurrent block image generation starts from a task-local scope slice.
     * Merge image state by slot so one completed task cannot overwrite another
     * task's generated final_url with its older pending manifest snapshot.
     *
     * @param array<string,mixed> $currentScope
     * @param array<string,mixed> $resultScope
     * @return array<string,mixed>
     */
    private function mergeInlineImageResultScope(array $currentScope, array $resultScope): array
    {
        if (\is_array($resultScope['asset_manifest'] ?? null)) {
            $resultScope['asset_manifest'] = $this->mergeInlineAssetManifest(
                \is_array($currentScope['asset_manifest'] ?? null) ? $currentScope['asset_manifest'] : [],
                $resultScope['asset_manifest']
            );
            $resultScope['verified_assets'] = $this->assetManifestService()->extractVerifiedAssets($resultScope['asset_manifest']);
        }

        if (\is_array($resultScope['asset_block_cache'] ?? null)) {
            $resultScope['asset_block_cache'] = $this->mergeInlineAssetBlockCache(
                \is_array($currentScope['asset_block_cache'] ?? null) ? $currentScope['asset_block_cache'] : [],
                $resultScope['asset_block_cache']
            );
        }

        if (\is_array($currentScope['asset_image_generation_failures'] ?? null)
            && \is_array($resultScope['asset_image_generation_failures'] ?? null)
        ) {
            $resultScope['asset_image_generation_failures'] = \array_values(\array_merge(
                $currentScope['asset_image_generation_failures'],
                $resultScope['asset_image_generation_failures']
            ));
        }

        return $resultScope;
    }

    /**
     * @param array<string,mixed> $currentManifest
     * @param array<string,mixed> $incomingManifest
     * @return array<string,mixed>
     */
    private function mergeInlineAssetManifest(array $currentManifest, array $incomingManifest): array
    {
        $assetManifestService = $this->assetManifestService();
        $current = $assetManifestService->normalize($currentManifest);
        $incoming = $assetManifestService->normalize($incomingManifest);
        $slots = \is_array($current['slots'] ?? null) ? $current['slots'] : [];

        foreach (\is_array($incoming['slots'] ?? null) ? $incoming['slots'] : [] as $slotId => $incomingSlot) {
            if (!\is_array($incomingSlot)) {
                continue;
            }
            $slotId = (string)$slotId;
            $currentSlot = \is_array($slots[$slotId] ?? null) ? $slots[$slotId] : [];
            $incomingFinalUrl = \trim((string)($incomingSlot['final_url'] ?? ''));
            $currentFinalUrl = \trim((string)($currentSlot['final_url'] ?? ''));
            $mergedSlot = \array_replace($currentSlot, $incomingSlot);
            if ($incomingFinalUrl === '' && $currentFinalUrl !== '') {
                foreach (['final_url', 'url', 'source', 'status', 'variants', 'error_message', 'execution_token'] as $field) {
                    if (\array_key_exists($field, $currentSlot)) {
                        $mergedSlot[$field] = $currentSlot[$field];
                    }
                }
            }
            $slots[$slotId] = $mergedSlot;
        }

        return [
            'version' => (int)($incoming['version'] ?? $current['version'] ?? 1),
            'updated_at' => \date('Y-m-d H:i:s'),
            'slots' => $slots,
        ];
    }

    /**
     * @param array<string,mixed> $currentCache
     * @param array<string,mixed> $incomingCache
     * @return array<string,mixed>
     */
    private function mergeInlineAssetBlockCache(array $currentCache, array $incomingCache): array
    {
        $currentSlots = \is_array($currentCache['slots'] ?? null) ? $currentCache['slots'] : [];
        $incomingSlots = \is_array($incomingCache['slots'] ?? null) ? $incomingCache['slots'] : [];

        return \array_replace($currentCache, $incomingCache, [
            'version' => (int)($incomingCache['version'] ?? $currentCache['version'] ?? 1),
            'updated_at' => \date('Y-m-d H:i:s'),
            'slots' => \array_replace($currentSlots, $incomingSlots),
        ]);
    }

    /**
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function refreshInlineImageStateFromPersistedScope(AiSiteAgentSession $session, int $adminId, array $scope): array
    {
        $fresh = $this->sessionService->loadById((int)$session->getId(), $adminId) ?? $session;
        $persistedScope = $this->sessionService->loadScope($fresh);

        return $this->mergeInlineImageResultScope(
            \is_array($persistedScope) ? $persistedScope : [],
            $scope
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $context
     * @return array{scope:array<string,mixed>,fatal:bool,throwable:\Throwable}
     */
    private function handlePlanJsonTaskGenerationFailure(
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

        $message = $this->summarizePlanJsonTaskThrowableForOperator($throwable);
        if ($message === '') {
            $message = $throwable::class;
        }
        $errorCode = (int)$throwable->getCode();
        $errorClass = $throwable::class;
        $reason = $message;
        if ($errorCode !== 0) {
            $reason .= ' [code=' . $errorCode . ']';
        }
        if ($errorClass !== '' && $errorClass !== $message) {
            $reason .= ' [' . $errorClass . ']';
        }
        $attemptNo = $this->planJsonTaskService->getTaskAttemptNo($scope, $taskKey);
        $progressPercent = \max(0, \min(99, (int)($context['progress_percent'] ?? 0)));
        $basePayload = \array_replace($context, [
            'parallel' => true,
            'workspace_track' => $workspaceTrack,
            'task_key' => $taskKey,
            'task_type' => $taskType,
            'attempt_no' => $attemptNo,
            'max_attempts' => self::PLAN_JSON_TASK_MAX_GENERATION_ATTEMPTS,
            'error_message' => $message,
            'error_code' => $errorCode,
            'error_class' => $errorClass,
            'failure_reason' => $reason,
        ]);

        $scope = $this->planJsonTaskService->markTaskFailed($scope, $taskKey, $message);
        $scope = $this->planJsonTaskService->syncPlanJsonTaskFailuresToRetryableLedger($scope);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->emitBuildInfoEvent(
            $sse,
            (string)__('Build task failed without automatic retry: %{task} 闂?%{reason}', [
                'task' => $taskKey,
                'reason' => $reason,
            ]),
            \array_replace($basePayload, [
                'event_type' => 'plan_json_task_failed',
                'batch_state' => 'failed',
            ])
        );
        $this->emitPlanJsonTaskProgressStateFromScope(
            $sse,
            $scope,
            'build',
            (string)__('Build task failed: %{task} 闂?%{reason}', ['task' => $taskKey, 'reason' => $reason]),
            $progressPercent,
            'failed'
        );
        $sse->sendEvent('task_failed', $this->enrichTaskEventPayload($scope, \array_replace($basePayload, [
            'message' => 'Build task failed: ' . $taskKey . ' 闂?' . $reason,
        ])));

        return [
            'scope' => $scope,
            'fatal' => true,
            'throwable' => $throwable,
        ];
    }

    private function summarizePlanJsonTaskThrowableForOperator(\Throwable $throwable): string
    {
        $message = \trim($throwable->getMessage());
        if ($message === '') {
            $message = $throwable::class;
        }
        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof \GuoLaiRen\PageBuilder\Exception\AiSiteComponentContractException) {
                continue;
            }
            $findings = \trim($current->renderFindingsForPrompt(4));
            if ($findings === '') {
                continue;
            }
            $message .= ' | contract findings: ' . (\preg_replace('/\s+/u', ' ', $findings) ?? $findings);
            break;
        }

        return \mb_substr($message, 0, 900, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $sectionComponent
     * @param array<string, mixed> $sectionBlock
     */
    private function isGeneratedSectionBlockUsable(array $sectionComponent, array $sectionBlock): bool
    {
        $componentCode = \trim((string)($sectionComponent['code'] ?? $sectionBlock['_pb_component_code'] ?? ''));
        if ($componentCode === '') {
            return false;
        }

        $html = \trim((string)($sectionBlock['html'] ?? $sectionComponent['html'] ?? ''));
        if ($html === '') {
            return false;
        }

        if (\strip_tags($html) === '' && !\str_contains($html, '<svg') && !\str_contains($html, '<img')) {
            return false;
        }

        return true;
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
        // Comment removed.
        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'build',
                    (string)__('Operation completed.'),
            1
        );
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScope($session, [])
        );
        return $this->runVirtualThemeBuildOperationV3($sse, $session, $adminId, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{scope:array<string,mixed>,page_types:list<string>,bad_matches:array<string,list<string>>}
     */
    private function invalidateQualityFailedVirtualThemePlanJsonTasks(array $scope): array
    {
        $badMatchesByPage = [];
        $invalidatedPageTypes = [];
        try {
            /** @var AiSiteQualityGateService $qualityGate */
            $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);
            $qualityReport = $qualityGate->inspectScope($scope);
        } catch (\Throwable) {
            return [
                'scope' => $scope,
                'page_types' => [],
                'bad_matches' => [],
            ];
        }

        $pageReports = \is_array($qualityReport['page_reports'] ?? null) ? $qualityReport['page_reports'] : [];
        foreach ($pageReports as $pageType => $pageReport) {
            $pageType = \trim((string)$pageType);
            if ($pageType === '' || !\is_array($pageReport)) {
                continue;
            }
            $badMatches = \array_values(\array_unique(\array_filter(
                \array_map('strval', \is_array($pageReport['bad_matches'] ?? null) ? $pageReport['bad_matches'] : []),
                static fn(string $match): bool => \trim($match) !== ''
            )));
            if ($badMatches === []) {
                continue;
            }
            $blockingBadMatches = $this->filterBlockingBuildQualityMatches($badMatches);
            if ($blockingBadMatches === []) {
                continue;
            }
            $taskKeys = $this->planJsonTaskService->listTaskKeysByPageType($scope, $pageType);
            if ($taskKeys === []) {
                continue;
            }
            $badMatchesByPage[$pageType] = $blockingBadMatches;
            $invalidatedPageTypes[] = $pageType;
            $message = 'Quality gate retry: ' . \implode(', ', $blockingBadMatches);
            $scope = $this->clearQualityFailedVirtualThemePageArtifacts($scope, $pageType);
            foreach ($taskKeys as $taskKey) {
                $scope = $this->planJsonTaskService->markTaskPendingForFreshRepair($scope, $taskKey, $message);
            }
        }

        return [
            'scope' => $scope,
            'page_types' => \array_values(\array_unique($invalidatedPageTypes)),
            'bad_matches' => $badMatchesByPage,
        ];
    }

    /**
     * @param list<string> $badMatches
     * @return list<string>
     */
    private function filterBlockingBuildQualityMatches(array $badMatches): array
    {
        $blocking = [];
        $patterns = [
            '/\b(?:AI_GENERATED_SECTION|task_key|section_code|block_key|page_type|field_content_requirements|implementation_detail|realtime_content|content_plan|image_slot|slot_id)\b/iu',
            '/\bcontent\/[a-z0-9_-]+\/[a-z0-9_-]+\b/iu',
            '/\b(?:AI content placeholder|ai-empty|placeholder content|placeholder|lorem ipsum|dummy copy|sample text|todo copy)\b/iu',
            '/Default Page Template|This is the default page/i',
            '/^(?:key message|next action|design intent|content plan|task split|implementation detail|primary subject|image brief|slot label)\b/iu',
            '/^(?:[a-z0-9]+[_\/:][a-z0-9_\/:-]+|[a-z0-9]+(?:-[a-z0-9]+){2,})$/iu',
            '/\b(?:visitors?|users?|customers?)\s+(?:see|can\s+review|can\s+verify|understand|are\s+guided|will\s+find)\b/iu',
            '/\b(?:before\s+publishing|reviewable\s+page\s+content|planning\s+observation|field\s+sample)\b/iu',
        ];
        foreach ($badMatches as $match) {
            $match = \trim((string)$match);
            if ($match === '') {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (\preg_match($pattern, $match) === 1) {
                    $blocking[] = $match;
                    break;
                }
            }
        }

        return \array_values(\array_unique($blocking));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function clearQualityFailedVirtualThemePageArtifacts(array $scope, string $pageType): array
    {
        $pageType = \trim($pageType);
        if ($pageType === '') {
            return $scope;
        }

        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        if ($pageTypes === []) {
            $pageTypes = [$pageType];
        }
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts([], $pageTypes);
        $layout = $this->scopeCompatibilityService->normalizeLayoutConfig([], $pageType);
        $layout['content'] = [];
        $pageTypeLayouts[$pageType] = $layout;
        if (\is_array($scope['plan_json']['pages'][$pageType] ?? null)) {
            foreach (\array_keys($this->planJsonDynamicBlocks($scope['plan_json']['pages'][$pageType])) as $blockKey) {
                unset($scope['plan_json']['pages'][$pageType][$blockKey]);
            }
            $scope['plan_json']['pages'][$pageType]['last_generated_at'] = '';
        }

        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0) {
            try {
                $this->virtualThemeService->saveGeneratedPageLayout($virtualThemeId, $pageType, $layout);
            } catch (\Throwable) {
                // Scope cleanup is authoritative for the repair queue; persistence will be retried during generation.
            }
        }

        return $scope;
    }

    private function runVirtualThemeBuildOperationV3(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $scope['website_profile'] = $this->profileGenerationService->generate($scope);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->planJsonTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        $queueForcedAiRebuild = (int)($scope['_queue_force_build']['active'] ?? 0) === 1;
        if ($queueForcedAiRebuild) {
            $scope = $this->planJsonTaskService->clearBuildArtifactsForRegeneration($scope);
            $scope = $this->planJsonTaskService->resetPlanJsonTasksToPendingForRebuild($scope, false);
        } else {
            $scope = $this->planJsonTaskService->reconcileGeneratedArtifactsWithTaskState($scope);
        }
        $qualityInvalidation = [
            'scope' => $scope,
            'page_types' => [],
            'bad_matches' => [],
        ];
        $buildSummaryBeforeQuality = $this->planJsonTaskService->summarize($scope);
        if (
            (int)($buildSummaryBeforeQuality['pending'] ?? 0) <= 0
            && (int)($buildSummaryBeforeQuality['running'] ?? 0) <= 0
            && (int)($buildSummaryBeforeQuality['failed'] ?? 0) <= 0
        ) {
            $qualityInvalidation = $this->invalidateQualityFailedVirtualThemePlanJsonTasks($scope);
        }
        $scope = \is_array($qualityInvalidation['scope'] ?? null) ? $qualityInvalidation['scope'] : $scope;
        $qualityInvalidatedPageTypes = \is_array($qualityInvalidation['page_types'] ?? null) ? $qualityInvalidation['page_types'] : [];
        if ($qualityInvalidatedPageTypes !== []) {
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('Quality gate invalidated generated pages; rebuilding affected tasks.'),
                [
                    'event_type' => 'build_quality_retry',
                    'page_types' => $qualityInvalidatedPageTypes,
                    'bad_matches' => \is_array($qualityInvalidation['bad_matches'] ?? null) ? $qualityInvalidation['bad_matches'] : [],
                ]
            );
        }
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        // Comment removed.
        $pageTypeLabels = Page::getPageTypes();
        // Keep one queue owner, but generate independent virtual-theme sections in bounded parallel batches.
        $dispatchWindow = $this->resolvePageSectionBuildDispatchWindow();
        $initialPendingTasks = $this->planJsonTaskService->listPendingTasks($scope);
        $totalSteps = \max(1, \count($initialPendingTasks) + 1);
        $currentStep = 0;

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Preparing virtual theme workspace'), $progressPercent);

        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Preparing optional image asset slots'), \min(99, $progressPercent + 1));
        $autoAssetResult = $this->prepareBuildImageAssets($session, $adminId, $scope);
        $scope = $autoAssetResult['scope'];
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        if ($autoAssetResult['generated_slots'] !== []) {
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('Generated %{count} AI image assets before page build.', ['count' => (string)\count($autoAssetResult['generated_slots'])]),
                [
                    'event_type' => 'build_asset_generation_completed',
                    'generated_slots' => $autoAssetResult['generated_slots'],
                    'state' => $this->buildAssetIdentityWorkspaceStatePatchFromScope($scope),
                ]
            );
        }
        foreach ($autoAssetResult['failed_slots'] as $failedSlot) {
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('AI image asset generation failed for %{slot}: %{message}', [
                    'slot' => (string)($failedSlot['slot_id'] ?? ''),
                    'message' => (string)($failedSlot['message'] ?? ''),
                ]),
                [
                    'event_type' => 'build_asset_generation_failed',
                    'slot_id' => (string)($failedSlot['slot_id'] ?? ''),
                    'message' => (string)($failedSlot['message'] ?? ''),
                    'state' => $this->buildAssetIdentityWorkspaceStatePatchFromScope($scope),
                ]
            );
        }
        $this->assertBuildImageAssetsReady($autoAssetResult['failed_slots']);
        $themeShell = $this->virtualThemeService->ensureThemeShell($scope, $scope['website_profile'], $session->getId());
        $scope['virtual_theme_id'] = (int)$themeShell['virtual_theme_id'];
        if ($queueForcedAiRebuild) {
            $this->virtualThemeService->resetGeneratedPageLayoutsForRebuild((int)$scope['virtual_theme_id'], $pageTypes);
        }

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [];
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts([], $pageTypes);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
        $environmentReadyEmitted = false;
        $parallelPageModeLogged = false;
        $buildLoopStallPasses = 0;

        while (true) {
            $taskBatch = $this->planJsonTaskService->pickConcurrentTasks($scope, $dispatchWindow);
            if ($taskBatch === []) {
                if ($this->planJsonTaskService->hasUnfinishedBlueprintTasks($scope)) {
                    $resetScope = $this->planJsonTaskService->resetRunningTasksForInterruptedBuild(
                        $scope,
                    (string)__('Operation completed.'),
                    );
                    if ($resetScope !== $scope && $buildLoopStallPasses < 32) {
                        $buildLoopStallPasses++;
                        $scope = $resetScope;
                        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                        $this->emitPlanJsonTaskProgressStateFromScope(
                            $sse,
                            $scope,
                            'build',
                            (string)__('Operation completed.'),
                            $this->resolveTaskSummaryProgressPercent($this->planJsonTaskService->summarize($scope))
                        );
                        continue;
                    }
                }
                break;
            }
            $buildLoopStallPasses = 0;

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

            if ($sharedTasks !== []) {
                $sharedTaskByRegion = [];
                foreach ($sharedTasks as $task) {
                    $this->assertActiveStreamLeaseAlive($session, $adminId);
                    $taskKey = (string)($task['task_key'] ?? '');
                    $region = (string)($task['region'] ?? '');
                    if ($taskKey === '' || $region === '') {
                        continue;
                    }
                    $currentStep++;
                    $sharedTaskByRegion[$region] = ['task' => $task, 'task_key' => $taskKey, 'progress_percent' => (int)(($currentStep / $totalSteps) * 100)];
                    $scope = $this->planJsonTaskService->markTaskRunning($scope, $taskKey);
                }
                if ($sharedTaskByRegion !== []) {
                    $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                    $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Generating shared header/footer'), (int)(($currentStep / $totalSteps) * 100));
                    if ((int)($scope['fake_mode'] ?? 0) === 1) {
                        foreach ($sharedTaskByRegion as $region => $taskMeta) {
                            $taskKey = (string)($taskMeta['task_key'] ?? '');
                            if ($taskKey === '') {
                                continue;
                            }
                            $component = $this->buildFakePlanJsonSharedComponent((string)$region, Page::TYPE_HOME, $scope['website_profile'], $scope);
                            $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, [
                                'region' => (string)$region,
                                'component' => $component,
                                'fake_mode' => 1,
                            ]);
                            $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [];
                            $virtualThemeId = (int)$scope['virtual_theme_id'];
                            $this->virtualThemeService->saveGeneratedSharedComponent($virtualThemeId, $component);
                            $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
                            $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
                            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                        }
                        continue;
                    }
                    foreach ($pageComponentGenerationService->generateSharedComponentEventsConcurrently($scope['website_profile'], $scope, \array_keys($sharedTaskByRegion)) as $region => $event) {
                        $taskMeta = \is_array($sharedTaskByRegion[(string)$region] ?? null) ? $sharedTaskByRegion[(string)$region] : [];
                        $taskKey = (string)($taskMeta['task_key'] ?? '');
                        $progressPercent = (int)($taskMeta['progress_percent'] ?? 0);
                        if ($taskKey === '') {
                            continue;
                        }
                        if (($event['status'] ?? '') !== 'fulfilled' || !\is_array($event['result'] ?? null) || $event['result'] === []) {
                            $throwable = ($event['error'] ?? null) instanceof \Throwable
                                ? $event['error']
                                : new \RuntimeException('Shared component generation returned an empty result.');
                            $failure = $this->handlePlanJsonTaskGenerationFailure($sse, $session, $adminId, $scope, $taskKey, 'shared_component', AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME, $throwable, [
                                'region' => (string)$region,
                                'progress_percent' => $progressPercent,
                            ]);
                            $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                            if (!empty($failure['fatal'])) {
                                throw $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : $throwable;
                            }
                            continue;
                        }
                        $component = $event['result'];
                        $sharedComponents[(string)$region] = $component;
                        $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, [
                            'region' => (string)$region,
                            'component' => $component,
                        ]);
                        $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [];
                        $virtualThemeId = (int)$scope['virtual_theme_id'];
                        $this->virtualThemeService->saveGeneratedSharedComponent($virtualThemeId, $component);
                        $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
                        $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
                        $scope = $this->withPlanJsonPages($scope, $this->planJsonPages($scope));
                        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                        $message = (string)$region === 'header' ? 'Shared header generated' : 'Shared footer generated';
                        $this->appendWorkspaceEvent($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'shared_component_generated', $message, ['operation' => 'build', 'details' => ['region' => (string)$region]]);
                        $freshShared = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                        $sharedState = $this->buildWorkspaceEventStatePayload($this->buildWorkspaceState($freshShared, $adminId, 80, true));
                        $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                            'task_key' => $taskKey,
                            'task_type' => 'shared_component',
                            'message' => $message,
                            'state' => $sharedState,
                        ]));
                    }
                }
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

            if ((int)($scope['fake_mode'] ?? 0) === 1) {
                $fakeBatchResult = $this->runFakeVirtualThemePlanJsonPageTaskBatch(
                    $sse,
                    $session,
                    $adminId,
                    $scope,
                    $pageTasks,
                    $pageTypeLabels,
                    $currentStep,
                    $totalSteps
                );
                $scope = \is_array($fakeBatchResult['scope'] ?? null) ? $fakeBatchResult['scope'] : $scope;
                $currentStep = (int)($fakeBatchResult['current_step'] ?? $currentStep);
                continue;
            }

            $batchSpecs = $this->buildConcurrentPageTaskBatchSpecs(
                $pageComponentGenerationService,
                $pageTasks,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                $scope,
                $pageTypeLabels,
                $session,
                $adminId
            );
            $componentSpecs = \is_array($batchSpecs['components'] ?? null) ? $batchSpecs['components'] : [];
            $taskMeta = \is_array($batchSpecs['meta'] ?? null) ? $batchSpecs['meta'] : [];
            $taskSpecErrors = \is_array($batchSpecs['errors'] ?? null) ? $batchSpecs['errors'] : [];
            $retryTaskKeys = [];
            $failedTaskKeys = [];
            $fatalBatchThrowable = null;
            foreach ($taskSpecErrors as $taskKey => $error) {
                $taskKey = (string)$taskKey;
                $scope = $this->planJsonTaskService->markTaskRunning($scope, $taskKey);
                $failure = $this->handlePlanJsonTaskGenerationFailure(
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
                    if (!$fatalBatchThrowable instanceof \Throwable) {
                        $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : new \RuntimeException('Build task spec error');
                    }
                    $failedTaskKeys[] = $taskKey;
                    continue;
                }
                $failedTaskKeys[] = $taskKey;
            }
            if ($componentSpecs === []) {
                if ($fatalBatchThrowable instanceof \Throwable) {
                    throw $fatalBatchThrowable;
                }
                continue;
            }

            $runningTaskKeys = \array_values(\array_map('strval', \array_keys($componentSpecs)));
            foreach ($runningTaskKeys as $runningTaskKey) {
                $scope = $this->planJsonTaskService->markTaskRunning($scope, $runningTaskKey);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->emitPlanJsonTaskProgressStateFromScope(
                $sse,
                $scope,
                'build',
                (string)__('Operation completed.'),
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
                    $failure = $this->handlePlanJsonTaskGenerationFailure(
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
                        if (!$fatalBatchThrowable instanceof \Throwable) {
                            $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : $throwable;
                        }
                        $failedTaskKeys[] = (string)$taskKey;
                        continue;
                    }
                    $failedTaskKeys[] = (string)$taskKey;
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

                $sectionComponent = [];
                $sectionBlock = [];
                try {
                    $blueprint = \is_array($meta['blueprint'] ?? null) ? $meta['blueprint'] : [];
                    $sectionComponent = \is_array($event['result'] ?? null) ? $event['result'] : [];
                    $sectionBlock = $this->htmlBlocksBuildService->buildGeneratedSectionBlock($sectionComponent);
                    if (!$this->isGeneratedSectionBlockUsable($sectionComponent, $sectionBlock)) {
                        $failure = $this->handlePlanJsonTaskGenerationFailure(
                            $sse,
                            $session,
                            $adminId,
                            $scope,
                            (string)$taskKey,
                            'page_section',
                            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                            new \RuntimeException('AI generated empty or unusable section block: ' . (string)$taskKey),
                            [
                                'page_type' => $pageType,
                                'task_keys' => $runningTaskKeys,
                                'completed_task_keys' => $completedTaskKeys,
                                'progress_percent' => $progressPercent,
                            ]
                        );
                        $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                        if (!empty($failure['fatal'])) {
                            if (!$fatalBatchThrowable instanceof \Throwable) {
                                $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : new \RuntimeException('AI generated empty section block.');
                            }
                            $failedTaskKeys[] = (string)$taskKey;
                            continue;
                        }
                        $failedTaskKeys[] = (string)$taskKey;
                        continue;
                    }
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
                    $layout = $this->sortVirtualThemeLayoutContentByPlanJsonTasks($layout, $scope, $pageType);
                    $this->virtualThemeService->saveGeneratedPageLayout((int)$scope['virtual_theme_id'], $pageType, $layout);
                    $pageTypeLayouts[$pageType] = $layout;

                    $currentVirtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType([$pageType], \array_replace($scope, [
                        'plan_json' => \array_replace(\is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [], ['pages' => $virtualPages]),
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
                            $sectionBlock,
                            $scope['website_profile'],
                            $scope
                        );
                        $virtualPages[$pageType] = $currentVirtualPages[$pageType];
                    }

                    $scope = $this->withPlanJsonPages($scope, $virtualPages);
                    $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? $pageType));
                    $scope = $this->planJsonTaskService->markTaskDone($scope, (string)$taskKey, [
                        'page_type' => $pageType,
                        'section_code' => $sectionCode,
                        'section_block' => $sectionBlock,
                        'component' => $sectionComponent,
                    ]);
                    $scope = $this->dropPrePublishMaterializedPagesFromVirtualThemeScope($scope, $session);
                    $scope = $this->refreshInlineImageStateFromPersistedScope($session, $adminId, $scope);
                    $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                    $pageId = 0;
                    if (!$environmentReadyEmitted && (int)($scope['virtual_theme_id'] ?? 0) > 0) {
                        $environmentReadyEmitted = true;
                        $this->emitBuildEnvironmentReady($sse, $session, $adminId, $pageType, $pageLabel, 0, (int)$scope['virtual_theme_id']);
                    }

                    $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                    $state = $this->buildWorkspaceEventStatePayload(
                        $this->buildWorkspaceState($fresh, $adminId, 80, true),
                        [$pageType]
                    );
                    $pageCompleted = $this->planJsonTaskService->arePageTasksComplete($scope, $pageType);
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
                } catch (\Throwable $throwable) {
                    $failure = $this->handlePlanJsonTaskGenerationFailure(
                        $sse,
                        $session,
                        $adminId,
                        $scope,
                        (string)$taskKey,
                        'page_section',
                        AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME,
                        new \RuntimeException(
                            'Build task post-generation materialization failed for ' . (string)$taskKey . ': ' . $throwable->getMessage(),
                            0,
                            $throwable
                        ),
                        [
                            'page_type' => $pageType,
                            'section_code' => $sectionCode,
                            'task_keys' => $runningTaskKeys,
                            'completed_task_keys' => $completedTaskKeys,
                            'failure_stage' => 'post_generation_materialization',
                            'component_code' => (string)($sectionComponent['code'] ?? ''),
                            'progress_percent' => $progressPercent,
                        ]
                    );
                    $scope = \is_array($failure['scope'] ?? null) ? $failure['scope'] : $scope;
                    if (!empty($failure['fatal'])) {
                        if (!$fatalBatchThrowable instanceof \Throwable) {
                            $fatalBatchThrowable = $failure['throwable'] instanceof \Throwable ? $failure['throwable'] : new \RuntimeException('AI generated empty section block.');
                        }
                        $failedTaskKeys[] = (string)$taskKey;
                        continue;
                    }
                    $failedTaskKeys[] = (string)$taskKey;
                    continue;
                }
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
                    'failed_task_keys' => $failedTaskKeys,
                ]
            );
            $this->emitPlanJsonTaskProgressStateFromScope(
                $sse,
                $scope,
                'build',
                (string)__('Page block batch completed: %{done}/%{total}', [
                    'done' => (string)(int)($this->planJsonTaskService->summarize($scope)['done'] ?? 0),
                    'total' => (string)(int)($this->planJsonTaskService->summarize($scope)['total'] ?? 0),
                ]),
                $this->resolveTaskSummaryProgressPercent($this->planJsonTaskService->summarize($scope))
            );
        }

        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts([], $pageTypes);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0) {
            $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
        }

        $scope = $this->planJsonTaskService->finalizePlanJsonTaskStatesAfterRunLoop($scope);
        $scope = $this->planJsonTaskService->syncPlanJsonTaskFailuresToRetryableLedger($scope);
        $scope = $this->refreshInlineImageStateFromPersistedScope($session, $adminId, $scope);

        $now = \date('Y-m-d H:i:s');
        $buildRegeneration = \is_array($scope['_build_regeneration'] ?? null) ? $scope['_build_regeneration'] : [];
        if ((int)($buildRegeneration['active'] ?? 0) === 1) {
            $scope['_build_regeneration'] = \array_replace($buildRegeneration, [
                'active' => 0,
                'finished_at' => $now,
            ]);
        }
        $completionGate = $this->planJsonTaskService->inspectBuildCompletionGate($scope);
        $taskSummary = \is_array($completionGate['summary'] ?? null) ? $completionGate['summary'] : [];
        $hasBuildFailures = (int)($taskSummary['failed'] ?? 0) > 0 || $this->planJsonTaskService->hasRetryableAiFailures($scope, 'build');
        $hasOutstandingTasks = empty($completionGate['passed']);
        $canPublishBuild = !$hasOutstandingTasks && !$hasBuildFailures;
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'last_generated_at' => $now,
            'active_operation' => 'build',
            'can_publish' => $canPublishBuild,
        ];
        $scope['plan_json_task_summary'] = $taskSummary;
        $scope['can_publish'] = $canPublishBuild ? 1 : 0;
        $scope['workspace_status'] = ($hasBuildFailures || $hasOutstandingTasks)
            ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED
            : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            ($hasBuildFailures || $hasOutstandingTasks) ? [
                'queue_status' => 'error',
                'updated_at' => $now,
                'message' => $hasBuildFailures
                    ? (string)__('Virtual theme build failed; unfinished AI items will retry on the same queue.')
                    : (string)__('Operation failed.'),
                'retry_allowed' => 0,
                'failure_mode' => 'build_failed',
                'retryable_ai_failure_count' => (int)($scope['retryable_ai_failure_count'] ?? 0),
                'queue_waiting_for_scheduler' => false,
            ] : [
                'queue_status' => 'done',
                'updated_at' => $now,
                'message' => (string)__('Virtual theme generated'),
                'queue_waiting_for_scheduler' => false,
            ]
        );

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)($scope['virtual_theme_id'] ?? 0));
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);

        $freshForCompletionGate = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $freshScopeForCompletionGate = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($freshForCompletionGate, AiSiteAgentSession::STAGE_VISUAL_EDIT, [])
        );
        $freshCompletionGate = $this->planJsonTaskService->inspectBuildCompletionGate($freshScopeForCompletionGate);
        if (!empty($freshCompletionGate['passed'])) {
            $scope = $freshScopeForCompletionGate;
            $taskSummary = \is_array($freshCompletionGate['summary'] ?? null) ? $freshCompletionGate['summary'] : $taskSummary;
            $hasOutstandingTasks = false;
            $hasBuildFailures = $this->planJsonTaskService->hasRetryableAiFailures($scope, 'build');
            $canPublishBuild = !$hasBuildFailures;
            $scope['plan_json_task_summary'] = $taskSummary;
            $scope['can_publish'] = $canPublishBuild ? 1 : 0;
            $scope['workspace_status'] = $canPublishBuild
                ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
                : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
            if ($canPublishBuild) {
                $scope['active_operation'] = \array_replace(
                    \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
                    [
                        'queue_status' => 'done',
                        'message' => (string)__('Virtual theme generated'),
                        'updated_at' => \date('Y-m-d H:i:s'),
                        'queue_waiting_for_scheduler' => false,
                    ]
                );
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            }
        }

        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'build',
            ($hasBuildFailures || $hasOutstandingTasks)
                ? ($hasBuildFailures
                    ? __('Virtual theme build failed; unfinished AI items will retry on the same queue.')
                    : __('Operation failed'))
                : __('Virtual theme ready for editing'),
            100,
            '',
            ($hasBuildFailures || $hasOutstandingTasks) ? 'error' : 'done',
            (string)($scope['workspace_status'] ?? '')
        );
        return [
            'message' => $hasBuildFailures
                ? (string)__('Virtual theme build failed; unfinished AI items will retry on the same queue.')
                : ($hasOutstandingTasks
                ? (string)__('Operation completed.')
                    : (string)__('Virtual theme build complete')),
            'draft_website_id' => (int)$draftWebsite['website_id'],
            'virtual_theme_id' => (int)($scope['virtual_theme_id'] ?? 0),
            'page_types' => $pageTypes,
        ];
    }

    /**
     * @param list<array<string,mixed>> $pageTasks
     * @param array<string,string> $pageTypeLabels
     * @return array{scope:array<string,mixed>,current_step:int}
     */
    private function runFakeVirtualThemePlanJsonPageTaskBatch(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope,
        array $pageTasks,
        array $pageTypeLabels,
        int $currentStep,
        int $totalSteps
    ): array {
        $runningTaskKeys = [];
        foreach ($pageTasks as $task) {
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $runningTaskKeys[] = $taskKey;
            $scope = $this->planJsonTaskService->markTaskRunning($scope, $taskKey);
        }
        if ($runningTaskKeys === []) {
            return ['scope' => $scope, 'current_step' => $currentStep];
        }

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->emitPlanJsonTaskProgressStateFromScope(
            $sse,
            $scope,
            'build',
            'Fake build batch started.',
            $currentStep > 0 ? (int)(($currentStep / $totalSteps) * 100) : 0
        );
        $this->emitBuildInfoEvent($sse, 'Fake mode generated page blocks without AI calls.', [
            'event_type' => 'build_fake_mode_batch',
            'batch_state' => 'started',
            'task_keys' => $runningTaskKeys,
        ]);

        $completedTaskKeys = [];
        foreach ($pageTasks as $task) {
            $this->assertActiveStreamLeaseAlive($session, $adminId);
            $taskKey = \trim((string)($task['task_key'] ?? ''));
            if ($taskKey === '') {
                continue;
            }
            $pageType = \trim((string)($task['page_type'] ?? ''));
            $sectionCode = \trim((string)($task['section_code'] ?? ''));
            $pageLabel = (string)($pageTypeLabels[$pageType] ?? $pageType);
            $block = $this->resolveFakePlanJsonBlockForTask($scope, $task);
            $sectionBlock = $this->buildFakePlanJsonSectionBlock($scope, $task, $block);
            $config = \is_array($sectionBlock['config'] ?? null) ? $sectionBlock['config'] : [];
            $sectionComponent = $this->buildFakePlanJsonSectionComponent($task, $sectionBlock);

            $currentStep++;
            $progressPercent = (int)(($currentStep / $totalSteps) * 100);
            $scope = $this->planJsonTaskService->markTaskDone($scope, $taskKey, [
                'page_type' => $pageType,
                'section_code' => $sectionCode,
                'section_block' => $sectionBlock,
                'component' => $sectionComponent,
                'fake_mode' => 1,
            ]);
            $scope = $this->syncFakePlanJsonSectionBlockToPreviewPage($scope, $task, $sectionBlock);
            if ($pageType !== '' && (int)($scope['virtual_theme_id'] ?? 0) > 0) {
                $virtualThemeId = (int)$scope['virtual_theme_id'];
                $sharedComponents = \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : [];
                $layout = $this->virtualThemeService->loadGeneratedPageLayout($virtualThemeId, $pageType);
                $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($layout, $pageType);
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
                $this->virtualThemeService->saveGeneratedContentComponent($virtualThemeId, $pageType, $sectionComponent);
                $layout = $this->virtualThemeService->mergeGeneratedContentIntoLayout($layout, $sectionComponent);
                $layout = $this->sortVirtualThemeLayoutContentByPlanJsonTasks($layout, $scope, $pageType);
                $this->virtualThemeService->saveGeneratedPageLayout($virtualThemeId, $pageType, $layout);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

            $message = 'Fake page block generated: ' . ($pageLabel !== '' ? $pageLabel : $pageType);
            $this->sendOperationProgress(
                $sse,
                $session,
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'build',
                $message,
                $progressPercent,
                $pageType
            );
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                $message,
                [
                    'operation' => 'build',
                    'page_type' => $pageType,
                    'details' => [
                        'section_code' => $sectionCode,
                        'fake_mode' => 1,
                    ],
                ]
            );
            $state = [];
            $sse->sendEvent('page_generated', $this->enrichTaskEventPayload($scope, [
                'task_key' => $taskKey,
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'page_id' => 0,
                'virtual_theme_id' => (int)($scope['virtual_theme_id'] ?? 0),
                'progress_percent' => $progressPercent,
                'section_code' => $sectionCode,
                'page_completed' => $pageType !== '' && $this->planJsonTaskService->arePageTasksComplete($scope, $pageType),
                'message' => $message,
                'state' => $state,
                'fake_mode' => 1,
            ]));
            $sse->sendEvent('task_completed', $this->enrichTaskEventPayload($scope, [
                'task_key' => $taskKey,
                'task_type' => 'page_section',
                'message' => 'Fake theme section task complete: ' . $pageLabel,
                'state' => $state,
                'fake_mode' => 1,
            ]));
            $completedTaskKeys[] = $taskKey;
        }

        $this->emitBuildInfoEvent($sse, 'Fake mode page block batch completed.', [
            'event_type' => 'build_fake_mode_batch',
            'batch_state' => 'completed',
            'task_keys' => $runningTaskKeys,
            'completed_task_keys' => $completedTaskKeys,
        ]);
        $this->emitPlanJsonTaskProgressStateFromScope(
            $sse,
            $scope,
            'build',
            (string)__('Page block batch completed: %{done}/%{total}', [
                'done' => (string)(int)($this->planJsonTaskService->summarize($scope)['done'] ?? 0),
                'total' => (string)(int)($this->planJsonTaskService->summarize($scope)['total'] ?? 0),
            ]),
            $this->resolveTaskSummaryProgressPercent($this->planJsonTaskService->summarize($scope))
        );

        return ['scope' => $scope, 'current_step' => $currentStep];
    }

    /**
     * @param array<string,mixed> $websiteProfile
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function buildFakePlanJsonSharedComponent(string $region, string $pageType, array $websiteProfile, array $scope): array
    {
        $region = \trim($region);
        $block = $region === 'footer'
            ? $this->htmlBlocksBuildService->buildSharedFooterBlock($pageType, $websiteProfile, $scope)
            : $this->htmlBlocksBuildService->buildSharedHeaderBlock($pageType, $websiteProfile, $scope);
        $html = (string)($block['html'] ?? '');

        return [
            'code' => $region === 'footer' ? 'footer/ai-site-footer' : 'header/ai-site-header',
            'name' => $region === 'footer' ? 'AI Site Footer' : 'AI Site Header',
            'region' => $region === 'footer' ? 'footer' : 'header',
            'html' => $html,
            'phtml' => $html,
            'default_config' => \is_array($block['config'] ?? null) ? $block['config'] : [],
            'ai_data' => [
                'fake_mode' => 1,
                'source' => 'plan_json.shared_components',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $task
     * @param array<string,mixed> $sectionBlock
     * @return array<string,mixed>
     */
    private function buildFakePlanJsonSectionComponent(array $task, array $sectionBlock): array
    {
        $pageType = \trim((string)($task['page_type'] ?? ''));
        $sectionCode = \trim((string)($task['section_code'] ?? $sectionBlock['section_code'] ?? 'section'));
        $blockId = \trim((string)($sectionBlock['block_id'] ?? ''));
        $componentCode = 'content/' . \str_replace(['/', '_'], '-', $blockId !== '' ? $blockId : ($pageType . '-' . $sectionCode));
        $html = (string)($sectionBlock['html'] ?? '');
        $config = \is_array($sectionBlock['config'] ?? null) ? $sectionBlock['config'] : [];

        return [
            'code' => $componentCode,
            'name' => (string)($config['headline'] ?? $sectionCode ?: $componentCode),
            'key' => \trim((string)($task['block_key'] ?? $task['section_key'] ?? $sectionCode)),
            'html' => $html,
            'phtml' => $html,
            'default_config' => $config,
            'config' => $config,
            'sort_order' => (int)($task['sort_order'] ?? $sectionBlock['sort_order'] ?? 0),
            'ai_data' => [
                'fake_mode' => 1,
                'source' => 'plan_json.pages.' . $pageType . '.' . \trim((string)($task['block_key'] ?? $task['section_key'] ?? $sectionCode)),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $task
     * @param array<string,mixed> $sectionBlock
     * @return array<string,mixed>
     */
    private function syncFakePlanJsonSectionBlockToPreviewPage(array $scope, array $task, array $sectionBlock): array
    {
        $pageType = \trim((string)($task['page_type'] ?? ''));
        if ($pageType === '' || \trim((string)($sectionBlock['html'] ?? '')) === '') {
            return $scope;
        }

        $pages = $this->planJsonPages($scope);
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        if ($page === []) {
            $pageLabels = Page::getPageTypes();
            $page = [
                'page_type' => $pageType,
                'title' => (string)($pageLabels[$pageType] ?? $pageType),
                'handle' => $pageType === Page::TYPE_HOME ? '' : Page::getDefaultHandleForType($pageType),
                'status' => 1,
                'fake_mode' => 1,
            ];
        }

        $blockKey = \trim((string)($task['block_key'] ?? $task['section_key'] ?? ''));
        if ($blockKey !== '' && \is_array($page[$blockKey] ?? null)) {
            $page[$blockKey] = \array_replace($page[$blockKey], [
                'status' => 1,
                'html' => (string)$sectionBlock['html'],
                'fields' => \is_array($sectionBlock['config'] ?? null) ? $sectionBlock['config'] : [],
                'field_schema' => \is_array($sectionBlock['field_schema'] ?? null) ? $sectionBlock['field_schema'] : [],
                'fake_mode' => 1,
            ]);
        }

        $page['status'] = 1;
        $page['last_generated_at'] = \date('Y-m-d H:i:s');
        $pages[$pageType] = $page;

        return $this->withPlanJsonPages($scope, $pages);
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private function resolveFakePlanJsonBlockForTask(array $scope, array $task): array
    {
        $pageType = \trim((string)($task['page_type'] ?? ''));
        $blockKey = \trim((string)($task['block_key'] ?? $task['section_key'] ?? ''));
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
        if ($blockKey !== '' && \is_array($page[$blockKey] ?? null)) {
            return $page[$blockKey];
        }

        $sectionCode = \trim((string)($task['section_code'] ?? ''));
        foreach ($this->planJsonDynamicBlocks($page) as $candidateKey => $candidateBlock) {
            $candidateSectionCode = \trim((string)($candidateBlock['section_code'] ?? $candidateBlock['component_code'] ?? ''));
            if ($candidateKey === $blockKey || ($sectionCode !== '' && $candidateSectionCode === $sectionCode)) {
                return $candidateBlock;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $task
     * @param array<string,mixed> $block
     * @return array<string,mixed>
     */
    private function buildFakePlanJsonSectionBlock(array $scope, array $task, array $block): array
    {
        $pageType = \trim((string)($task['page_type'] ?? ''));
        $blockKey = \trim((string)($task['block_key'] ?? $task['section_key'] ?? 'section'));
        $sectionCode = \trim((string)($task['section_code'] ?? $block['section_code'] ?? $blockKey));
        $siteName = $this->firstNonEmptyFakePlanJsonText([
            $scope['site_title'] ?? null,
            $scope['website_profile']['site_title'] ?? null,
            $scope['plan_json']['site_name'] ?? null,
            'Fake Site',
        ]);
        $headline = $this->firstNonEmptyFakePlanJsonText([
            $block['headline'] ?? null,
            $block['title'] ?? null,
            $block['section_title'] ?? null,
            $block['name'] ?? null,
            $block['content'] ?? null,
            $this->humanizeFakePlanJsonIdentifier($blockKey),
        ]);
        $body = $this->firstNonEmptyFakePlanJsonText([
            $block['body'] ?? null,
            $block['description'] ?? null,
            $block['summary'] ?? null,
            $block['content'] ?? null,
            'Fake mode rendered this block from plan_json without an AI call.',
        ]);
        $cta = $this->firstNonEmptyFakePlanJsonText([
            $block['cta_text'] ?? null,
            $block['primary_cta'] ?? null,
            'Review block',
        ]);
        $escape = static fn(string $value): string => \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = '<section class="pb-ai-fake-section" data-page-type="' . $escape($pageType) . '" data-block-key="' . $escape($blockKey) . '">'
            . '<div class="pb-ai-fake-section__inner">'
            . '<p class="pb-ai-fake-section__eyebrow">' . $escape($siteName) . ' / fake mode</p>'
            . '<h2>' . $escape($headline) . '</h2>'
            . '<p>' . $escape($body) . '</p>'
            . '<button type="button">' . $escape($cta) . '</button>'
            . '</div>'
            . '</section>';
        $config = [
            'headline' => $headline,
            'body' => $body,
            'cta_text' => $cta,
            'fake_mode' => 1,
            'page_type' => $pageType,
            'block_key' => $blockKey,
        ];

        return [
            'block_id' => 'fake-' . $pageType . '-' . ($sectionCode !== '' ? $sectionCode : $blockKey),
            'block_key' => $blockKey,
            'section_key' => $blockKey,
            'type' => 'ai_generated_section',
            'section_code' => $sectionCode !== '' ? $sectionCode : $blockKey,
            'html' => $html,
            'config' => $config,
            'field_schema' => [
                'headline' => ['type' => 'text'],
                'body' => ['type' => 'textarea'],
                'cta_text' => ['type' => 'text'],
            ],
        ];
    }

    /**
     * @param list<mixed> $values
     */
    private function firstNonEmptyFakePlanJsonText(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function humanizeFakePlanJsonIdentifier(string $value): string
    {
        $value = \trim(\preg_replace('/[_-]+/', ' ', $value) ?? $value);
        if ($value === '') {
            return 'Section';
        }

        return \ucwords(\strtolower($value));
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
            ensureTaskScope: fn (array $scope, array $websiteProfile, string $workspaceTrack): array => $this->planJsonTaskService->ensureTaskScope($scope, $websiteProfile, $workspaceTrack),
            resetPageTasksForRetry: fn (array $scope, string $pageType): array => $this->planJsonTaskService->resetPageTasksForRetry($scope, $pageType),
            normalizeWorkspaceTrack: fn (string $track): string => $this->scopeCompatibilityService->normalizeWorkspaceTrack($track),
            resolvePageTypeLabels: fn (): array => Page::getPageTypes(),
            sendOperationProgress: function (...$args): void {
                $this->sendOperationProgress(...$args);
            },
            buildVirtualPagesByType: fn (array $pageTypes, array $scope): array => $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope),
            buildPageBlueprint: fn (string $pageType, array $scope, array $websiteProfile): array => $this->pageBlueprintService->buildPageBlueprint($pageType, $scope, $websiteProfile),
            buildPlaceholderBlocksForPageType: fn (string $pageType, array $websiteProfile, array $scope): array => $this->htmlBlocksBuildService->buildPlaceholderBlocksForPageType($pageType, $websiteProfile, $scope),
            markTaskDone: fn (array $scope, string $taskKey, array $payload): array => $this->planJsonTaskService->markTaskDone($scope, $taskKey, $payload),
            materializeGeneratedPages: fn (string $track, int $websiteId, array $websiteProfile, array $pageTypes, array $layouts, array $virtualPages): array => $this->materializeGeneratedPages($track, $websiteId, $websiteProfile, $pageTypes, $layouts, $virtualPages),
            mergeMaterializedPagesIntoScope: fn (array $scope, array $materialized): array => $this->mergeMaterializedPagesIntoScope($scope, $materialized),
            summarizePlanJsonTasks: fn (array $scope): array => $this->planJsonTaskService->summarize($scope),
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
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT, ['plan_json'])
        );
        $publishBlock = $this->resolveLatestPublishBlockingAiBuildFailure(
            $scope,
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            null
        );
        if (!empty($publishBlock['blocked'])) {
            throw new \RuntimeException($this->formatPublishBlockedByAiFailureMessage($publishBlock));
        }
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->planJsonTaskService->syncPageTypeLayoutsWithSharedComponents($scope);
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $websiteProfile = $this->profileGenerationService->generate($scope);
        if (\is_array($scope['plan_json'] ?? null)) {
            $websiteProfile['plan_json'] = $scope['plan_json'];
        }

        $websiteId = \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId());
        $virtualThemeId = \max((int)($scope['virtual_theme_id'] ?? 0), (int)$session->getVirtualThemeId());
        $workspaceTrack = AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME;
        if ($websiteId <= 0 || $virtualThemeId <= 0) {
            throw new \RuntimeException((string)__('Operation failed.'));
        }
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', (string)__('Operation progress updated.'), 10);
        $stageTwoReadiness = $this->buildStageTwoPublishReadinessReport($scope);
        $scope['stage2_publish_readiness'] = $stageTwoReadiness;
        if (empty($stageTwoReadiness['passed'])) {
            throw new \RuntimeException((string)__('Operation failed.'));
        }
        $scope = $this->refreshScopeQualityContractsForPublishGate($scope);
        $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);
        $qualityReport = $this->normalizePublishQualityReport(
            $qualityGate->inspectScope($scope),
            $stageTwoReadiness
        );
        if (empty($qualityReport['passed'])) {
            throw new \RuntimeException((string)__('Operation failed.'));
        }

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', (string)__('Operation progress updated.'), 70);
        $published = $this->publishService->publish(
            $websiteId,
            $virtualThemeId,
            $websiteProfile,
            $pageTypes,
            $pageTypeLayouts,
            $this->planJsonPages($scope),
            $workspaceTrack,
            \is_array($scope['plan_json']['shared_components'] ?? null) ? $scope['plan_json']['shared_components'] : []
        );

        $scope['materialized_pages_by_type'] = $published['materialized_pages_by_type'] ?? [];
        $scope['stage1_visual_qa_report'] = $this->buildStageOneVisualQaReport($scope, $qualityReport, $stageTwoReadiness);
        $scope['publish_verification'] = \is_array($published['publish_verification'] ?? null)
            ? $published['publish_verification']
            : [];
        $scope['preview_page_id'] = (int)($published['preview_page_id'] ?? 0);
        $scope['preview_page_type'] = (string)($published['preview_page_type'] ?? ($scope['preview_page_type'] ?? ''));
        $scope['plan_json_task_summary'] = $this->planJsonTaskService->summarize($scope);
        if (\is_array($scope['build_summary'] ?? null)) {
            unset($scope['build_summary']['task_summary']);
        }
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        $scope = $this->writeActiveOperationStateToScope($scope, \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            [
                'operation' => 'publish',
                'queue_status' => 'done',
                'updated_at' => \date('Y-m-d H:i:s'),
                'message' => (string)__('Operation completed.'),
            ]
        ));

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_PUBLISH);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', (string)__('Operation progress updated.'), 100, '', 'done', AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED);

        return [
            'message' => (string)__('Operation completed.'),
            'website_id' => $websiteId,
            'virtual_theme_id' => $virtualThemeId,
            'materialized_pages_by_type' => $scope['materialized_pages_by_type'],
            'preview_page_id' => (int)$scope['preview_page_id'],
            'preview_page_type' => (string)$scope['preview_page_type'],
            'publish_verification' => $scope['publish_verification'],
        ];
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
                && $this->isPlanJsonTaskSummaryTerminal($scope)
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
            $summary = $this->planJsonTaskService->summarize($scope);
            if ((int)($summary['total'] ?? 0) > 0) {
                $payload = \array_replace(
                    $payload,
                    $this->planJsonTaskProgressStatePayload(
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
        if (\in_array($operation, ['build', 'regenerate_page', 'block_regenerate'], true)
            && (string)($payload['progress_kind'] ?? '') === 'task_progress'
        ) {
            $this->emitTaskProgressStateEvent($sse, $payload);
        } else {
            $sse->sendEvent('progress', $payload);
        }
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
        $definition = $this->planJsonTaskService->getTaskDefinition($scope, $taskKey);
        $runtime = \is_array($definition['runtime_context'] ?? null) ? $definition['runtime_context'] : [];
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
            $this->sessionService->loadScope($fresh, [])
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
        if ((string)($patch['status'] ?? '') === 'done') {
            $patch = \array_replace([
                'failure_mode' => '',
                'retry_allowed' => 0,
                'retryable_ai_failure_count' => 0,
                'retryable_ai_failures' => [],
                'semantic_status' => 'done',
                'progress_percent' => 100,
                'queue_waiting_for_scheduler' => false,
            ], $patch);
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

    /**
     * @param array<string, mixed> $scope
     */
    private function isPlanJsonTaskSummaryTerminal(array $scope): bool
    {
        $completionGate = $this->planJsonTaskService->inspectBuildCompletionGate($scope);

        return !empty($completionGate['passed']);
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
     * Comment removed.
     */
    private function assertActiveStreamLeaseAlive(AiSiteAgentSession $session, int $adminId, string $leaseToken = ''): void
    {
        // Comment removed.
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

    private function mutateScope(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('Invalid parameters.'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('Session not found.'), self::PARAMS_PUBLIC_ID);
        }
        $jsonKey = 'scope_patch';
        $isAutosave = \in_array(\strtolower(\trim((string)$this->getRequestBodyValue('autosave', '0'))), ['1', 'true', 'yes', 'on'], true);
        $error = '';
        $payload = $this->getRequestJsonObject($jsonKey, $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        if (isset($payload['page_types']) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $payload)) {
            $payload[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        $payload = $this->dropEmptyProfileIdentityPatchValues($payload);
        $scopeForDirection = $this->scopeCompatibilityService->normalizeScope(\array_replace(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN),
            $payload
        ));
        try {
            $directionPatch = $this->designDirectionService()->resolveSelectionForScope($scopeForDirection, $adminId, false);
            $payload = \array_replace($payload, $directionPatch);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            return $this->jsonError(
                'DESIGN_DIRECTION_INVALID',
                $invalidArgumentException->getMessage(),
                ['design_direction_code']
            );
        }
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, $payload);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => __('Operation failed.')]);
        }
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($session->getStage()),
            $isAutosave ? 'autosave_saved' : 'scope_updated',
            $isAutosave
                ? (string)__('Operation completed.')
                : (string)__('Operation failed.'),
            ['details' => ['keys' => \array_values(\array_map('strval', \array_keys($payload))), 'autosave' => $isAutosave ? 1 : 0]]
        );
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        if ($isAutosave) {
            $stage = $this->scopeCompatibilityService->normalizeStage($fresh->getStage());
            $scope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($fresh, $stage)
            );
            $autosaveScope = [];
            foreach ([
                'site_title',
                'site_tagline',
                'target_domain',
                'selected_domain',
                'default_locale',
                'plan_locale',
                'brief_description',
                'user_description',
                'page_types',
                AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY,
                AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY,
                'extra_page_types_panel_open',
                'reference_images',
                'site_profile_manual',
                'design_direction_mode',
                'design_direction_code',
                'design_direction_snapshot',
                'design_direction_match_reason',
                'design_direction_locked',
            ] as $scopeKey) {
                if (\array_key_exists($scopeKey, $scope)) {
                    $autosaveScope[$scopeKey] = $scope[$scopeKey];
                }
            }

            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('Operation completed.'),
                'autosave' => true,
                'data' => [
                    'public_id' => $fresh->getPublicId(),
                    'stage' => $stage,
                    'scope' => $autosaveScope,
                    'page_types' => \is_array($scope['page_types'] ?? null) ? \array_values($scope['page_types']) : [],
                ],
            ]);
        }

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

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
        );
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        if (!\is_array($planJson['pages'][$pageType] ?? null)) {
            return $this->jsonError('PLAN_JSON_PAGE_NOT_FOUND', 'Stage-1 plan JSON page not found.', ['public_id', 'page_type']);
        }

        $round = \max(1, (int)$this->getRequestBodyValue('round', ((int)($scope['plan_last_round'] ?? 0)) + 1));
        $targetScope = \trim((string)$this->getRequestBodyValue('target_scope', ''));
        $websiteProfile = $this->profileGenerationService->generate($scope, false);
        try {
            $artifacts = $this->planJsonGenerationService->refineDraftPlanPage(
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

        $nextPlanJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
        $nextPlanJson = (new AiSitePlanJsonStateService((int)$session->getId()))->setConfirmed($nextPlanJson, false);
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, [
            'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
            'plan_json' => $nextPlanJson,
            'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
            'plan_last_prompt_mode' => 'refine_page',
            'plan_last_target_scope' => $targetScope !== '' ? $targetScope : ('pages.' . $pageType),
            'plan_last_round' => $round,
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
            'Stage-1 current plan JSON page blocks refined.',
            [
                'operation' => 'refine_plan_page',
                'page_type' => $pageType,
                'details' => \is_array($artifacts['page_refine_summary'] ?? null) ? $artifacts['page_refine_summary'] : [],
            ]
        );
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        return $this->fetchJson([
            'success' => true,
            'message' => 'Stage-1 current plan JSON page blocks refined.',
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
            $artifacts = $this->planJsonGenerationService->reorderDraftPlanBlocks($scope, $pageType, $orderedBlockKeys);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }

        $nextPlanJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
        $nextPlanJson = (new AiSitePlanJsonStateService((int)$session->getId()))->setConfirmed($nextPlanJson, false);
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, [
            'plan_json' => $nextPlanJson,
            'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
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
        $blockKeys = $this->normalizeStringList($this->getRequestBodyValue('block_keys', []));
        if ($action !== 'create' && $blockKey !== '' && !\in_array($blockKey, $blockKeys, true)) {
            \array_unshift($blockKeys, $blockKey);
        }
        foreach (['component_codes', 'section_codes'] as $requestKey) {
            foreach ($this->normalizeStringList($this->getRequestBodyValue($requestKey, [])) as $candidate) {
                if (!\in_array($candidate, $blockKeys, true)) {
                    $blockKeys[] = $candidate;
                }
            }
        }
        if ($adminId <= 0 || $publicId === '' || $pageType === '' || !\in_array($action, ['create', 'delete', 'refine', 'rebuild'], true)) {
            return $this->jsonError('INVALID_PARAMS', 'Missing required params.', self::PARAMS_MUTATE_PLAN_BLOCK);
        }
        if ($action !== 'create' && $blockKeys === []) {
            return $this->jsonError('INVALID_PARAMS', 'block_key is required for refine/rebuild/delete.', self::PARAMS_MUTATE_PLAN_BLOCK);
        }

        $blockConfig = [];
        $blockConfigs = [];
        $rawBlockConfig = $this->getRequestBodyValue('block_config', null);
        if ($rawBlockConfig !== null && $rawBlockConfig !== '') {
            $error = '';
            $blockConfig = $this->getRequestJsonObject('block_config', $error);
            if ($error !== '') {
                return $this->fetchJson(['success' => false, 'message' => $error]);
            }
        }
        $rawBlockConfigs = $this->getRequestBodyValue('block_configs', null);
        if ($rawBlockConfigs !== null && $rawBlockConfigs !== '') {
            $error = '';
            $blockConfigs = $this->getRequestJsonObject('block_configs', $error);
            if ($error !== '') {
                return $this->fetchJson(['success' => false, 'message' => $error]);
            }
        }
        $instruction = \trim((string)$this->getRequestBodyValue('instruction', ''));
        if ($instruction !== '' && !isset($blockConfig['instruction'])) {
            $blockConfig['instruction'] = $instruction;
        }
        if ($blockConfigs === [] && $blockConfig !== [] && $blockKey !== '') {
            $blockConfigs[$blockKey] = $blockConfig;
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
            $targetScope = 'pages.' . $pageType . '.' . ($blockKey !== '' ? $blockKey : 'new');
        }
        $targetScopes = $this->normalizeStringList($this->getRequestBodyValue('target_scopes', []));
        if ($targetScope !== '' && !\in_array($targetScope, $targetScopes, true)) {
            \array_unshift($targetScopes, $targetScope);
        }
        foreach ($blockKeys as $candidateBlockKey) {
            $candidateTargetScope = 'pages.' . $pageType . '.' . $candidateBlockKey;
            if (!\in_array($candidateTargetScope, $targetScopes, true)) {
                $targetScopes[] = $candidateTargetScope;
            }
        }

        // Comment removed.
        // Comment removed.
        try {
            $workingScope = $scope;
            $mutationSummaries = [];
            $resultArtifacts = [];
            $resolvedBlockKeys = $action === 'create' ? ($blockKeys === [] ? [''] : $blockKeys) : $blockKeys;
            foreach ($resolvedBlockKeys as $candidateBlockKey) {
                $candidateBlockKey = (string)$candidateBlockKey;
                $candidatePatch = \is_array($blockConfigs[$candidateBlockKey] ?? null)
                    ? $blockConfigs[$candidateBlockKey]
                    : $blockConfig;
                $resultArtifacts = $this->planJsonGenerationService->mutateDraftPlanBlock(
                    $workingScope,
                    $pageType,
                    $action,
                    $candidateBlockKey,
                    $candidatePatch
                );
                $mutationSummary = \is_array($resultArtifacts['mutation_summary'] ?? null)
                    ? $resultArtifacts['mutation_summary']
                    : [];
                if ($mutationSummary !== []) {
                    $mutationSummaries[] = $mutationSummary;
                }
                $workingScope = $this->mergePlanBlockMutationArtifactsToScope($workingScope, $resultArtifacts);
            }
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }

        $planJson = \is_array($resultArtifacts['plan_json'] ?? null) ? $resultArtifacts['plan_json'] : [];
        $planJson = (new AiSitePlanJsonStateService((int)$session->getId()))->setConfirmed($planJson, false);
        $planMarkdown = (string)($resultArtifacts['markdown'] ?? '');
        $combinedSummary = $mutationSummaries === []
            ? (\is_array($resultArtifacts['mutation_summary'] ?? null) ? $resultArtifacts['mutation_summary'] : [])
            : $mutationSummaries[\count($mutationSummaries) - 1];

        $scopePatch = [
            'plan_json' => $planJson,
            'plan_markdown' => $planMarkdown,
            'plan_last_prompt_mode' => 'mutate_plan_block',
            'plan_last_target_scope' => $targetScope,
            'plan_last_round' => $round,
            '_plan_sse_request' => [],
        ];
        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => 'Failed to persist plan block mutation.']);
        }
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;

        $doneMessage = match ($action) {
            'message' => (string)__('Operation completed.'),
            'message' => (string)__('Operation completed.'),
            'message' => (string)__('Operation completed.'),
            'message' => (string)__('Operation completed.'),
        };
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
            'plan_block_mutated',
            $doneMessage,
            [
                'operation' => 'mutate_plan_block',
                'page_type' => $pageType,
                'action' => $action,
                'details' => $combinedSummary,
            ]
        );

        return $this->fetchJson([
            'success' => true,
            'message' => $doneMessage,
            'page_type' => $pageType,
            'mutation' => $combinedSummary,
            'mutation_summaries' => $mutationSummaries,
            'realtime' => true,
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
        ]);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $artifacts
     * @return array<string, mixed>
     */
    private function mergePlanBlockMutationArtifactsToScope(array $scope, array $artifacts): array
    {
        $next = $scope;
        if (\is_array($artifacts['plan_json'] ?? null)) {
            $next['plan_json'] = $artifacts['plan_json'];
        }
        if (\array_key_exists('markdown', $artifacts)) {
            $next['plan_markdown'] = (string)$artifacts['markdown'];
        }
        return $next;
    }

    private function buildOperationStreamUrl(string $publicId, string $executionToken): string
    {
        return $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/operation-sse', [
            'public_id' => $publicId,
            'execution_token' => $executionToken,
        ]);
    }

    private function supportsBackgroundOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'image_asset'], true);
    }

    private function shouldKeepQueuedObserverStreamOpen(string $operation): bool
    {
        return \trim($operation) === 'plan';
    }

    /**
     * Planning operations are intentionally long-running and should not be auto-reclaimed
     * just because their active_operation timestamp is prior.
     *
     * @param array<string, mixed> $activeOperation
     */
    private function shouldReclaimStaleActiveOperation(array $activeOperation): bool
    {
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        return $operation !== 'plan';
    }

    private function getObserverMaxIdleLoops(): int
    {
        return (int)\ceil(3000 / \max(1, self::OBSERVER_QUEUE_PROGRESS_POLL_INTERVAL_MS));
    }

    protected function fetchJson(array $data): string
    {
        $statusCode = (int)($data['http_status'] ?? 0);
        if ($statusCode <= 0 || $statusCode === 200) {
            return parent::fetchJson($data);
        }

        $response = \Weline\Framework\Http\Response::json($data, $statusCode);
        $context = \Weline\Framework\Context::getCurrent();
        if ($context !== null && $context->get('meta.type') === 'request') {
            if (\ob_get_level() > 0 && \ob_get_length() > 0) {
                \ob_clean();
            }
            throw new ResponseTerminateException($response);
        }

        return $response->getBody();
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
            $error = (string)__('Operation completed.');
            return [];
        }
        if (!\is_array($decoded)) {
            $error = (string)__('Operation completed.');
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

    private function getWebsiteAgentService(): WebsiteAgentService
    {
        return ObjectManager::getInstance(WebsiteAgentService::class);
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
                : ($accountName !== '' ? $accountName : (string)__('Account'));
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
            'label' => __('Ready'),
            'message' => (string)__('Operation completed.'),
                'registrar_code' => 'local_demo',
                'message' => (string)__('Operation completed.'),
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
     * @param array<string, mixed> $scope
     */
    private function loadExistingLinkedWebsitesMirrorSessionFromScope(array $scope, int $adminId): ?WebsitesAiSiteBuilderSession
    {
        $linkedPublicId = \trim((string)($scope['handoff_workspace_public_id'] ?? ''));
        if ($linkedPublicId === '') {
            return null;
        }

        $linkedSession = $this->getWebsitesSessionService()->loadByPublicId($linkedPublicId, $adminId);
        return $linkedSession instanceof WebsitesAiSiteBuilderSession ? $linkedSession : null;
    }

    /**
     * Comment removed.
     * Comment removed.
     */
    private function ensureLinkedWebsitesMirrorSession(AiSiteAgentSession $session, int $adminId): ?WebsitesAiSiteBuilderSession
    {
        return $this->websitesMirrorService->ensureMirrorSession($session, $adminId);
    }

    /**
     * Comment removed.
     * Comment removed.
     *
     * @return array<string, mixed>
     */
    private function buildLinkedWebsitesScopeFromPageBuilderSession(AiSiteAgentSession $session): array
    {
        return $this->websitesMirrorService->buildScopeFromSource($session);
    }

    /**
     * Comment removed.
     * Comment removed.
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
        $currentPlanSourceSignature = $hasPlanDraft ? $this->PlanJsonSourceSignature($scope) : '';
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
    private function PlanJsonSourceSignature(array $scope): string
    {
        $normalizedScope = \array_replace($scope, [
            'plan_locale' => $this->resolvePlanLocale($scope),
            'page_types' => $this->scopeCompatibilityService->resolveScopedPageTypes($scope),
        ]);

        return $this->planJsonGenerationService->buildSourceSignature($normalizedScope);
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

        return $this->planJsonGenerationService->buildSourceSignature($fallbackScope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function isPlanContentEmpty(array $scope): bool
    {
        return !$this->scopeCompatibilityService->hasPersistedStageOnePlan($scope);
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
                'queue_status' => 'cancelled',
                'message' => (string)__('Operation completed.'),
            ],
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
        );
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            'plan',
            'operation_cancelled',
            (string)__('Operation completed.'),
            ['operation' => 'plan', 'details' => ['reason' => 'plan_scope_changed']]
        );
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function getStageOptions(): array
    {
        return [
            [
                'value' => AiSiteAgentSession::STAGE_PLAN,
                'label' => __('Plan'),
            ],
            [
                'value' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'label' => __('Visual Edit'),
            ],
            [
                'value' => AiSiteAgentSession::STAGE_PUBLISH,
                'label' => __('Publish'),
            ],
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
        $rawBodyParams = $this->getRawRequestBodyParams();
        if (\array_key_exists($key, $rawBodyParams)) {
            return $rawBodyParams[$key];
        }
        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRawRequestBodyParams(): array
    {
        $cached = $this->request->getData('pb_ai_raw_request_body_params');
        if (\is_array($cached)) {
            return $cached;
        }

        $params = [];
        $rawBody = $this->request->getBodyParams(false);
        if (\is_string($rawBody)) {
            $rawBody = \trim($rawBody);
            if ($rawBody !== '') {
                if (\str_starts_with($rawBody, '{') || \str_starts_with($rawBody, '[')) {
                    $decoded = \json_decode($rawBody, true);
                    if (\is_array($decoded)) {
                        $params = $decoded;
                    }
                } elseif (\str_contains($rawBody, '=')) {
                    \parse_str($rawBody, $parsed);
                    if (\is_array($parsed)) {
                        $params = $parsed;
                    }
                }
            }
        }

        $this->request->setData('pb_ai_raw_request_body_params', $params);
        return $params;
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
     * Comment removed.
     * Comment removed.
     */
    private function releasePhpSessionLockForSse(): void
    {
        if (\function_exists('session_status') && \session_status() === \PHP_SESSION_ACTIVE) {
            @\session_write_close();
        }
    }

}
