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
use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanService;
use GuoLaiRen\PageBuilder\Service\AiSiteBuildTaskService;
use GuoLaiRen\PageBuilder\Service\AiSiteQualityGateService;
use GuoLaiRen\PageBuilder\Service\AiSiteQueueSnapshotService;
use GuoLaiRen\PageBuilder\Service\AiSiteScopeCompatibilityService;
use GuoLaiRen\PageBuilder\Service\AiSiteSessionRuntime;
use GuoLaiRen\PageBuilder\Service\AiSiteSsePayloadNormalizer;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentWorkspaceDebugDefaults;
use GuoLaiRen\PageBuilder\Service\AiSiteVirtualThemeService;
use GuoLaiRen\PageBuilder\Service\AiSitePreviewLinkRewriteService;
use GuoLaiRen\PageBuilder\Service\AiSiteVisualUrlService;
use GuoLaiRen\PageBuilder\Service\AiSiteHtmlBlocksBuildService;
use GuoLaiRen\PageBuilder\Service\AiSiteExecutionBlueprintService;
use GuoLaiRen\PageBuilder\Service\AI\AiSiteSkillRegistry;
use GuoLaiRen\PageBuilder\Service\AI\DesignDirection\DesignDirectionService;
use GuoLaiRen\PageBuilder\Service\AI\Skill\CustomSkillRepository;
use GuoLaiRen\PageBuilder\Http\Sse\QueueDbWriter;
use Weline\Ai\Model\AiSkill;
use Weline\Ai\Service\AiService;
use Weline\Ai\Service\Skill\AdapterSkillResolver;
use Weline\Ai\Service\Skill\SkillRegistry as CoreSkillRegistry;
use Weline\Ai\Service\Skill\SkillRepository as AiSkillRepository;
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
use Weline\Websites\Service\AiWorkbench\SessionService as WebsitesSessionService;

#[Acl('GuoLaiRen_PageBuilder::ai_site_agent', 'AI Site Agent', 'mdi-robot-outline', 'PageBuilder AI Site Agent Workspace', 'Weline_Backend::page_builder_group')]
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
    private const WORKSPACE_STREAM_SNAPSHOT_PERSIST = false;
    private const STREAM_LEASE_SCOPE_KEY = '_workspace_stream_lease';
    private const STREAM_LEASE_TTL_SEC = 60;
    private const WORKSPACE_STREAM_MAX_EVENT_REPLAY = 300;
    private const WORKSPACE_FAST_VIEW_ARTIFACT_KEYS_BY_STAGE = [
        AiSiteAgentSession::STAGE_PLAN => [
            'plan_json',
            'plan_structured',
            'plan_markdown',
        ],
    ];
    private const PLAN_CONFIRMATION_ARTIFACT_KEYS = [
        'plan_json',
        'plan_structured',
        'plan_markdown',
        'build_plan_v2',
        'plan_projection',
        'content_manifest',
    ];
    private const BUILD_OPERATION_ARTIFACT_KEYS = [
        'plan_json',
        'build_plan_v2',
        'plan_projection',
        'content_manifest',
        'build_workbench',
        'build_contracts',
        'render_data_contract',
        'task_results',
        'qa_report',
        'repair_patch',
    ];
    private const WORKSPACE_FAST_VIEW_SCOPE_KEYS = [
        '_artifact_refs',
        'active_operation',
        'active_operations',
        'asset_image_generation_failures',
        'asset_manifest',
        'brief_description',
        'build_plan_confirmed',
        'build_plan_confirmed_at',
        'build_queue_info',
        'build_summary',
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
        'has_build_plan_v2',
        'latest_build_failed',
        'latest_build_failure',
        'locales',
        'page_type_layouts',
        'page_types',
        'page_types_user_customized',
        'pagebuilder_pages_by_type',
        'pending_generation_page_types',
        'plan_confirmed',
        'plan_confirmed_at',
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
        'virtual_pages_by_type',
        'virtual_theme_id',
        'design_tokens',
        'language_contract',
        'virtual_page_index',
        'theme_css_ref',
        'visual_edit_url',
        'visual_preview_url',
        'website_id',
        'website_profile',
        'workspace_status',
        'workspace_track',
    ];
    /**
     * 宸ヤ綔鍖?stream-sse 缁害绐楀彛锛氳秴杩囨鏃堕棿鏈敹鍒扮画绾﹁姹傦紙POST post-touch-stream-lease锛夊垯瑙嗕负鏂繛锛?
     * 鏈嶅姟绔皢缁撴潫浜嬩欢娴佸苟娓呯悊涓庢湰 lease 鍏宠仈鐨勬帓闃?杩愯涓搷浣溿€?
     * 鍓嶇蹇冭烦闂撮殧搴旀樉钁楀皬浜庢湰鍊硷紙褰撳墠椤甸潰绾?20s 涓€娆?POST 缁害锛夈€?
     *
     * 娉ㄦ剰锛氭鍊奸渶瑕佷笌 observeDuplicateOperationStream 涓殑 $maxIdleLoops 鍗忚皟锛?
     * 纭繚闀挎椂闂磋繍琛岀殑 AI 鐢熸垚浠诲姟锛堝澶у瀷椤甸潰鏋勫缓锛変笉浼氳璇垽涓鸿繃鏃躲€?
     * 褰撳墠璁剧疆涓?600 绉掞紙10鍒嗛挓锛夛紝浠ユ敮鎸佸鏉傜殑 AI 鍐呭鐢熸垚鍦烘櫙銆?
     */
    private const STALE_ACTIVE_OPERATION_TTL_SEC = 600;
    private const OBSERVER_QUEUE_SETTLE_DELAY_MS = 3000;
    private const BUILD_TASK_MAX_GENERATION_ATTEMPTS = 3;
    private const DEFAULT_PAGE_SECTION_BUILD_DISPATCH_WINDOW = 1;
    private const AI_SITE_QUEUE_CONTENT_LIGHT_FIELDS = 'queue_id,type_id,pid,name,module,status,finished,start_at,end_at,biz_key';
    private const AI_SITE_QUEUE_CONTENT_PAYLOAD_FIELDS = 'queue_id,type_id,pid,name,module,status,finished,start_at,end_at,biz_key,content,process,result';
    private const AI_SITE_QUEUE_MAX_CONTENT_JSON_DECODE_BYTES = 262144;

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
    private readonly AiSiteAgentStreamCodecService $streamCodecService;
    private readonly AiSiteAgentWorkspaceBridgeService $workspaceBridgeService;
    private readonly AiSiteAgentWorkspacePreviewService $workspacePreviewService;
    private readonly AiSiteQueueSnapshotService $queueSnapshotService;
    private readonly AiSiteAgentQueueObserverHelperService $queueObserverHelperService;
    private readonly AiSiteAgentWebsitesMirrorService $websitesMirrorService;
    private readonly AiSiteAgentWorkspaceStateHelperService $workspaceStateHelperService;
    private readonly AiSiteAgentQueueObserverStreamService $queueObserverStreamService;
    private readonly AiSiteAgentRegeneratePageOperationService $regeneratePageOperationService;
    private readonly AiSiteAgentWorkspaceEntryNoticeService $workspaceEntryNoticeService;
    private ?AiSiteSsePayloadNormalizer $ssePayloadNormalizer = null;
    private ?AiSiteBlockPartialPatchService $blockPartialPatchService = null;
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
        ?AiSiteAgentStreamCodecService $streamCodecService = null,
        ?AiSiteAgentWorkspaceBridgeService $workspaceBridgeService = null,
        ?AiSiteAgentWorkspacePreviewService $workspacePreviewService = null,
        ?AiSiteQueueSnapshotService $queueSnapshotService = null,
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
        $this->regeneratePageOperationService = $regeneratePageOperationService
            ?? ObjectManager::getInstance(AiSiteAgentRegeneratePageOperationService::class);
        $this->workspaceEntryNoticeService = $workspaceEntryNoticeService
            ?? ObjectManager::getInstance(AiSiteAgentWorkspaceEntryNoticeService::class);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_index', 'ai site agent index', 'mdi-robot-outline', 'ai site agent index', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function index(): string
    {
        $startedAt = \microtime(true);
        $workbenchHomeUrl = $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']);
        $showAll = (string)$this->request->getGet('show', '') === 'all';

        $adminId = (int)$this->getLoginUserId();
        $recent = $adminId > 0 ? $this->sessionService->listRecentSessionsForAdmin($adminId, $showAll ? 200 : 30) : [];
        $directionOptions = $adminId > 0 ? \array_values($this->designDirectionService()->listDirections($adminId, false)) : [];

        $this->assign('title', __('Message'));
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

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'AI 寤虹珯浼氳瘽', 'mdi-clipboard-text-outline', '鏌ョ湅涓庣紪杈?AI 寤虹珯浼氳瘽', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function workspace(): string
    {
        $startedAt = \microtime(true);
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            $this->assign('title', __('Message'));
            $this->assign('error_message', __('鏈櫥褰曟垨浼氳瘽浠ょ墝鏃犳晥'));
            return $this->fetch('workspace-error');
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->assign('title', __('Message'));
            $this->assign('error_message', __('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶'));
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
            // 绾夸笂榛樿涓嶉閫夎处鍙凤紝鐢辩敤鎴蜂富鍔ㄩ€夋嫨銆?
        if ($recommendedRegistrarLabel === '' && $preferredRegistrarAccountId > 0) {
            foreach ($registrarAccounts as $account) {
                if ((int)($account['account_id'] ?? 0) !== $preferredRegistrarAccountId) {
                    continue;
                }
                $recommendedRegistrarLabel = \trim((string)($account['label'] ?? ''));
                break;
            }
        }

        $this->assign('title', __('Message'));
        $this->assign('session', $session);
        $this->assign('workspace_state', $viewState);
        $this->assign('scope', $viewState['scope']);
        $this->assign('scope_preview', '{}');
        $this->assign('merge_scope_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-merge-scope'));
        $this->assign('replace_scope_url', $this->url->getBackendUrlPath('pagebuilder/backend/ai-site-agent/post-replace-scope'));
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

    /**
     * Read-only workspace rendering may display an already linked Websites
     * workbench, but it must not create/sync mirror sessions or write scope.
     *
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

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_index', 'AI design directions', 'mdi-compass-outline', 'Manage AI site design directions', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function designDirections(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $items = $adminId > 0 ? \array_values($this->designDirectionService()->listDirections($adminId, true)) : [];
        $this->assign('title', __('AI 璁捐鏂瑰悜妯℃澘'));
        $this->assign('design_direction_items', $items);
        $this->assign('design_direction_list_url', $this->url->getBackendUrlPath('ai/backend/style/post-catalog'));
        $this->assign('design_direction_save_url', $this->url->getBackendUrlPath('ai/backend/style/post-save'));
        $this->assign('design_direction_disable_url', $this->url->getBackendUrlPath('ai/backend/style/post-disable'));
        $this->assign('design_direction_delete_url', $this->url->getBackendUrlPath('ai/backend/style/post-delete'));
        $this->assign('design_direction_clone_builtin_url', $this->url->getBackendUrlPath('ai/backend/style/post-clone-builtin'));
        $this->assign('back_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/index'));

        return $this->fetch('design-directions');
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'ai site agent workspace', 'mdi-clipboard-text-outline', 'ai site agent workspace', 'GuoLaiRen_PageBuilder::ai_site_agent')]
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
        $requestedLocale = \trim((string)$this->request->getGet('locale', ''));
        $scope = $this->scopeCompatibilityService->normalizePreviewContentLocale($scope, $requestedLocale);
        $previewLocale = $this->scopeCompatibilityService->resolvePreviewContentLocale($scope, $requestedLocale);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType(
            $this->scopeCompatibilityService->resolveScopedPageTypes($scope),
            $scope,
            false
        );
        $pageType = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, $requestedPageType);
        if ($pageType === '' || !\is_array($virtualPages[$pageType] ?? null)) {
            return null;
        }

        // 浠呬俊浠昏 public_id 浼氳瘽涓嬬殑铏氭嫙涓婚锛涘拷鐣?GET 涓彲鑳借鎷︽埅鍣ㄦ敞鍏ョ殑鍏跺畠 virtual_theme_id銆?
        $virtualThemeId = (int)($context['virtual_theme_id'] ?? 0);
        $virtualPage = $virtualPages[$pageType];
        $styleCode = \trim((string)($this->request->getGet('style_code') ?: ($virtualPage['style_code'] ?? 'default')));
        $styleCode = $styleCode !== '' ? $styleCode : 'default';
        $locale = \trim((string)(
            $previewLocale !== ''
                ? $previewLocale
                : ($requestedLocale !== '' ? $requestedLocale : ($virtualPage['locale'] ?? State::getLang()))
        ));
        $locale = $locale !== '' ? $locale : State::getLang();
        $layout = $virtualLayoutService->getResolvedLayout($virtualThemeId, $pageType);
        $layout = $this->mergeAiSitePreviewLayoutFromScope($layout, $scope, $pageType);
        $virtualBlocks = \is_array($virtualPage['blocks'] ?? null) ? $virtualPage['blocks'] : [];
        $materializedPreview = $this->resolveMaterializedAiHtmlPreviewData($scope, $pageType);
        $materializedBlocks = \is_array($materializedPreview['blocks'] ?? null) ? $materializedPreview['blocks'] : [];
        if ($materializedBlocks !== []) {
            $virtualBlocks = $materializedBlocks;
            $virtualPage = \array_replace(
                $virtualPage,
                \is_array($materializedPreview['page'] ?? null) ? $materializedPreview['page'] : []
            );
            $virtualPage['blocks'] = $virtualBlocks;
            $virtualPages[$pageType] = $virtualPage;
        }
        $renderMode = $virtualBlocks === [] ? Page::RENDER_MODE_THEME : Page::RENDER_MODE_AI_HTML;
        $previewTitle = $this->sanitizeAiSiteVisitorTitle((string)($virtualPage['title'] ?? ''), $scope, $locale);
        $previewMetaTitle = $this->sanitizeAiSiteVisitorTitle((string)($virtualPage['meta_title'] ?? ''), $scope, $locale);
        if ($previewMetaTitle === '') {
            $previewMetaTitle = $previewTitle;
        }

        $page = ObjectManager::make(Page::class);
        $page->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_WEBSITE_ID => (int)($scope['draft_website_id'] ?? 0),
            Page::schema_fields_PARENT_ID => $pageType === Page::TYPE_HOME ? 0 : 1,
            Page::schema_fields_LAYOUT_PAGE_ID => 0,
            Page::schema_fields_STATUS => Page::STATUS_DRAFT,
            Page::schema_fields_TITLE => $previewTitle,
            Page::schema_fields_NAME => $previewTitle,
            Page::schema_fields_HANDLE => (string)($virtualPage['handle'] ?? ''),
            Page::schema_fields_STYLE => $styleCode,
            Page::schema_fields_TYPE => $pageType,
            Page::schema_fields_CONTENT => '',
            Page::schema_fields_META_TITLE => $previewMetaTitle,
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
     * @param array<string,mixed> $layout
     * @param array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function mergeAiSitePreviewLayoutFromScope(array $layout, array $scope, string $pageType): array
    {
        $pageTypeLayouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $scopeLayout = \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [];
        foreach (['header', 'footer'] as $region) {
            $regionLayout = \is_array($scopeLayout[$region] ?? null) ? $scopeLayout[$region] : [];
            if ($regionLayout === []) {
                continue;
            }
            $layout[$region] = \array_replace_recursive(
                \is_array($layout[$region] ?? null) ? $layout[$region] : [],
                $regionLayout
            );
        }

        return $this->scopeCompatibilityService->localizeSharedLayoutConfigForScope($layout, $scope, $pageType);
    }

    /**
     * 棰勮 iframe 鏃犳硶瑙ｆ瀽涓婁笅鏂囨椂杩斿洖鍗犱綅 HTML锛堜細璇濇湁鏁堟椂鍙紩瀵?AI 鐢熸垚锛屽苟鎼哄甫缁嗚妭浠诲姟瑙勫垝鏄惁宸茬‘璁わ級銆?
     */
    /**
     * @return array{blocks?:list<array<string,mixed>>,page?:array<string,mixed>}
     */
    private function resolveMaterializedAiHtmlPreviewData(array $scope, string $pageType): array
    {
        $pagesByType = $this->scopeCompatibilityService->normalizePagebuilderPagesByType(
            $scope['pagebuilder_pages_by_type'] ?? []
        );
        $pageId = (int)($scope['virtual_pages_by_type'][$pageType]['materialized_page_id'] ?? 0);
        if ($pageId <= 0) {
            $pageId = (int)($pagesByType[$pageType]['page_id'] ?? 0);
        }

        $row = $this->loadMaterializedAiHtmlPreviewPageRow(
            $pageId,
            $pageType,
            (int)($scope['draft_website_id'] ?? $scope['website_id'] ?? 0)
        );
        if ($row === []) {
            return [];
        }
        if (\trim((string)($row[Page::schema_fields_RENDER_MODE] ?? '')) !== Page::RENDER_MODE_AI_HTML) {
            return [];
        }

        $layout = \json_decode((string)($row[Page::schema_fields_AI_LAYOUT] ?? ''), true);
        $blocks = \is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        $blocks = \array_values(\array_filter($blocks, static function (mixed $block): bool {
            return \is_array($block)
                && \trim((string)($block['html'] ?? $block['config']['html_content'] ?? '')) !== '';
        }));
        if ($blocks === []) {
            return [];
        }

        return [
            'blocks' => $blocks,
            'page' => [
                'page_type' => (string)($row[Page::schema_fields_TYPE] ?? $pageType),
                'title' => (string)($row[Page::schema_fields_TITLE] ?? $row[Page::schema_fields_NAME] ?? ''),
                'handle' => (string)($row[Page::schema_fields_HANDLE] ?? ''),
                'locale' => (string)($row[Page::schema_fields_DEFAULT_LOCALE] ?? ''),
                'meta_title' => (string)($row[Page::schema_fields_META_TITLE] ?? ''),
                'meta_description' => (string)($row[Page::schema_fields_META_DESCRIPTION] ?? ''),
                'meta_keywords' => (string)($row[Page::schema_fields_META_KEYWORDS] ?? ''),
                'ai_description' => (string)($row[Page::schema_fields_AI_DESCRIPTION] ?? ''),
                'materialized_page_id' => (int)($row[Page::schema_fields_ID] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadMaterializedAiHtmlPreviewPageRow(int $pageId, string $pageType, int $websiteId): array
    {
        /** @var Page $pageModel */
        $pageModel = ObjectManager::make(Page::class);
        if ($pageId > 0) {
            $rows = (clone $pageModel)
                ->clearData()
                ->clearQuery()
                ->where(Page::schema_fields_ID, $pageId)
                ->pagination(1, 1)
                ->select()
                ->fetchArray();
            if (\is_array($rows[0] ?? null)) {
                return $rows[0];
            }
        }

        if ($websiteId <= 0 || $pageType === '') {
            return [];
        }

        $rows = (clone $pageModel)
            ->clearData()
            ->clearQuery()
            ->where(Page::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Page::schema_fields_TYPE, $pageType)
            ->pagination(1, 1)
            ->select()
            ->fetchArray();

        return \is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    private function sanitizeAiSiteVisitorTitle(string $value, array $scope, string $locale = ''): string
    {
        $title = \preg_replace('/\b(?:website\s*profile|websiteProfile|site\s*profile|profile_json|scope_json|target_domain)\b/iu', '', $value) ?? $value;
        $title = \preg_replace('/\s+/u', ' ', \trim($title)) ?? \trim($title);
        $localized = $this->localizeAiSiteGenericVisitorTitle($title, $scope, $locale);
        if ($localized !== '') {
            return $localized;
        }
        if ($title !== '') {
            return $title;
        }

        foreach ([
            $scope['site_title'] ?? null,
            $scope['website_profile']['site_title'] ?? null,
            $scope['site_name'] ?? null,
            $scope['website_profile']['brand_name'] ?? null,
        ] as $candidate) {
            if (!\is_scalar($candidate)) {
                continue;
            }
            $candidateTitle = \preg_replace('/\b(?:website\s*profile|websiteProfile|site\s*profile|profile_json|scope_json|target_domain)\b/iu', '', (string)$candidate) ?? (string)$candidate;
            $candidateTitle = \preg_replace('/\s+/u', ' ', \trim($candidateTitle)) ?? \trim($candidateTitle);
            if ($candidateTitle !== '') {
                return $candidateTitle;
            }
        }

        return 'Website';
    }

    private function localizeAiSiteGenericVisitorTitle(string $title, array $scope, string $explicitLocale = ''): string
    {
        $normalizedTitle = \strtolower(\trim($title));
        $key = match ($normalizedTitle) {
            'home', 'homepage', "\u{9996}\u{9875}", "\u{4E3B}\u{9875}" => 'home',
            'about', 'about us', "\u{5173}\u{4E8E}", "\u{5173}\u{4E8E}\u{6211}\u{4EEC}" => 'about',
            'contact', 'contact us', "\u{8054}\u{7CFB}", "\u{8054}\u{7CFB}\u{6211}\u{4EEC}" => 'contact',
            'privacy policy', 'privacy', "\u{9690}\u{79C1}\u{653F}\u{7B56}" => 'privacy_policy',
            'terms of service', 'terms', "\u{670D}\u{52A1}\u{6761}\u{6B3E}", "\u{670D}\u{52A1}\u{6761}\u{6B3E}\u{548C}\u{6761}\u{4EF6}" => 'terms_of_service',
            default => '',
        };
        if ($key === '') {
            return '';
        }

        $locale = \trim($explicitLocale);
        if ($locale === '') {
            $locale = \trim((string)(
                $scope['content_locale']
                    ?? $scope['website_profile']['content_locale']
                    ?? $scope['default_locale']
                    ?? $scope['default_language']
                    ?? $scope['website_profile']['default_locale']
                    ?? ''
            ));
        }

        if (\preg_match('/^(?:hi|hi[_-]in)(?:[_-]|$)/i', $locale) === 1) {
            return match ($key) {
                'home' => "\u{0939}\u{094B}\u{092E}",
                'about' => "\u{0939}\u{092E}\u{093E}\u{0930}\u{0947} \u{092C}\u{093E}\u{0930}\u{0947} \u{092E}\u{0947}\u{0902}",
                'contact' => "\u{0938}\u{0902}\u{092A}\u{0930}\u{094D}\u{0915} \u{0915}\u{0930}\u{0947}\u{0902}",
                'privacy_policy' => "\u{0917}\u{094B}\u{092A}\u{0928}\u{0940}\u{092F}\u{0924}\u{093E} \u{0928}\u{0940}\u{0924}\u{093F}",
                'terms_of_service' => "\u{0938}\u{0947}\u{0935}\u{093E} \u{0915}\u{0940} \u{0936}\u{0930}\u{094D}\u{0924}\u{0947}\u{0902}",
                default => '',
            };
        }

        if (\preg_match('/^th(?:[_-]|$)/i', $locale) === 1) {
            return match ($key) {
                'home' => 'value',
                'about' => '喙€喔佮傅喙堗涪喔о竵喔编笟喙€喔｀覆',
                'contact' => '喔曕复喔斷笗喙堗腑喙€喔｀覆',
                'privacy_policy' => '喔權箓喔⑧笟喔侧涪喔勦抚喔侧浮喙€喔涏箛喔權釜喙堗抚喔權笗喔编抚',
                'terms_of_service' => '喔傕箟喔竵喔赤斧喔權笖喔佮覆喔｀箖喔娻箟喔氞福喔脆竵喔侧福',
                default => '',
            };
        }
        if (\preg_match('/^(?:hi|hi[_-]in)(?:[_-]|$)/i', $locale) === 1) {
            return match ($key) {
                'home' => 'value',
                'about' => 'value',
                'contact' => '啶膏啶ぐ啷嵿 啶曕ぐ啷囙',
                'privacy_policy' => '啶椸啶え啷€啶い啶?啶ㄠ啶むた',
                'terms_of_service' => '啶膏啶掂ぞ 啶曕 啶多ぐ啷嵿い啷囙',
                default => '',
            };
        }
        if (\preg_match('/^pt(?:[_-]|$)/i', $locale) === 1) {
            return match ($key) {
                'home' => 'In铆cio',
                'about' => 'Sobre',
                'contact' => 'Contato',
                'privacy_policy' => 'Pol铆tica de Privacidade',
                'terms_of_service' => 'Termos de Servi莽o',
                default => '',
            };
        }
        if (\preg_match('/^(zh|zh[_-]hans|zh[_-]cn|zh[_-]sg)/i', $locale) === 1) {
            return match ($key) {
                'home' => '棣栭〉',
                'about' => '鍏充簬鎴戜滑',
                'contact' => '鑱旂郴鎴戜滑',
                'privacy_policy' => '闅愮鏀跨瓥',
                'terms_of_service' => '鏈嶅姟鏉℃',
                default => '',
            };
        }

        return '';
    }

    /**
     * Return an iframe-safe placeholder when the preview context cannot be resolved.
     */
    private function renderWorkspacePreviewUnavailablePage(int $adminId, string $publicId, string $requestedPageType): string
    {
        $sessionAccessible = false;
        $buildPlanConfirmed = false;
        $buildQueueRunning = false;
        if ($adminId > 0 && $publicId !== '' && $requestedPageType !== '') {
            $session = $this->sessionService->loadByPublicId($publicId, $adminId);
            if ($session !== null) {
                $sessionAccessible = true;
                $scope = $this->scopeCompatibilityService->normalizeScope(
                    $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
                );
                $buildPlanConfirmed = (int)($scope['build_plan_confirmed'] ?? 0) === 1
                    || \is_array($scope['build_plan_v2'] ?? null)
                    || \is_array($scope['plan_projection'] ?? null)
                    || (int)($scope['plan_confirmed'] ?? 0) === 1;
                $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
                $activeOperations = \is_array($scope['active_operations'] ?? null) ? $scope['active_operations'] : [];
                $activeBuild = \is_array($activeOperations['build'] ?? null) ? $activeOperations['build'] : [];
                $activeOpName = \strtolower(\trim((string)($activeOperation['operation'] ?? '')));
                $activeOpStatus = \strtolower(\trim((string)($activeOperation['status'] ?? '')));
                $buildStatus = \strtolower(\trim((string)($activeBuild['status'] ?? '')));
                $buildQueueRunning = ($activeOpName === 'build' && \in_array($activeOpStatus, ['pending', 'queued', 'running', 'processing'], true))
                    || \in_array($buildStatus, ['pending', 'queued', 'running', 'processing'], true);
            }
        }
        // 蹇呴』鐢?template() 鐩村嚭 HTML锛歠etch() 浼氳蛋 fetch_file_after锛孴heme 浼氭妸鍐呭鍖呰繘鍚庡彴鏁撮〉甯冨眬锛宨frame 鍐呬細鍑虹幇鍚庡彴椤舵爮/涓婚澹炽€?
        return $this->template('GuoLaiRen_PageBuilder::templates/Backend/AiSiteAgent/workspace-preview-unavailable.phtml', [
            'pb_preview_unavailable_session_ok' => $sessionAccessible,
            'pb_preview_unavailable_build_plan_confirmed' => $buildPlanConfirmed,
            'pb_preview_unavailable_build_queue_running' => $buildQueueRunning,
            'pb_preview_unavailable_page_type' => $requestedPageType,
        ]);
    }

    public function postMergeScope(): string
    {
        return $this->mutateScope(true);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鏇挎崲 scope', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postReplaceScope(): string
    {
        return $this->mutateScope(false);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鎺掑簭寤虹珯鏂规鍧?, ')]
    public function postSortPlanBlocks(): string
    {
        return $this->handleSortPlanBlocks();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鏂板/鍒犻櫎/閲嶅缓寤虹珯鏂规鍧?, ')]
    public function postMutatePlanBlock(): string
    {
        return $this->handleMutatePlanBlock();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '寰皟褰撳墠椤甸潰闃舵涓€鍧楁爲', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRefinePlanPage(): string
    {
        return $this->handleRefinePlanPage();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鏇存柊闃舵', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSetStage(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        $stage = $this->scopeCompatibilityService->normalizeStage((string)$this->getRequestBodyValue('stage', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('鍙傛暟鏃犳晥')]);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶')]);
        }

        $allowed = \array_column($this->getStageOptions(), 'value');
        if (!\in_array($stage, $allowed, true)) {
            return $this->fetchJson(['success' => false, 'message' => __('Message')]);
        }

        if ($stage === AiSiteAgentSession::STAGE_PUBLISH) {
            $state = $this->buildWorkspaceState($session, $adminId, 24, true);
            $publishBlock = $this->resolvePublishBlockingAiFailureFromWorkspaceState($state);
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
            if (empty($state['can_publish']) && $session->getPublishStatus() !== AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED) {
                return $this->fetchJson([
                    'success' => false,
                    'code' => 'WORKSPACE_NOT_READY',
                    'message' => __('Current workspace is not ready to publish. Finish AI page generation first.'),
                ]);
            }
        }

        $this->sessionService->setStage($session->getId(), $adminId, $stage);
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stage, 'stage_changed', (string)__('宸ヤ綔鍖洪樁娈靛凡鍒囨崲'));
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

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鍒楀嚭 AI 寤虹珯鎶€鑳?, ')]
    public function postSkillList(): string { return $this->handleSkillList(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯鎶€鑳?API', 'mdi-api', '鍒楀嚭 AI 寤虹珯鎶€鑳?GET 鍏煎', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPostSkillList(): string { return $this->handleSkillList(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯鎶€鑳?API', 'mdi-api', '鍒楀嚭 AI 寤虹珯鎶€鑳?GET', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getSkillList(): string { return $this->handleSkillList(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '淇濆瓨 AI 寤虹珯鎶€鑳?, ')]
    public function postSkillSave(): string { return $this->handleSkillSave(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '绂佺敤 AI 寤虹珯鎶€鑳?, ')]
    public function postSkillDisable(): string { return $this->handleSkillDisable(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI design direction API', 'mdi-compass-outline', 'List AI site design directions', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionList(): string { return $this->handleDesignDirectionList(); }

    public function getPostDesignDirectionList(): string { return $this->handleDesignDirectionList(); }

    public function getDesignDirectionList(): string { return $this->handleDesignDirectionList(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI design direction API', 'mdi-compass-outline', 'Save AI site design direction', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionSave(): string { return $this->handleDesignDirectionSave(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI design direction API', 'mdi-compass-outline', 'Disable AI site design direction', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionDisable(): string { return $this->handleDesignDirectionDisable(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI design direction API', 'mdi-compass-outline', 'Clone builtin AI site design direction', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionCloneBuiltin(): string { return $this->handleDesignDirectionCloneBuiltin(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI design direction API', 'mdi-compass-outline', 'Match AI site design direction', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDesignDirectionMatch(): string { return $this->handleDesignDirectionMatch(); }

    public function getPostDesignDirectionMatch(): string { return $this->handleDesignDirectionMatch(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鐢熸垚寤虹珯鏂规涔?, ')]
    public function postStartPlan(): string { return $this->handleStartPlan(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '纭寤虹珯鏂规涔?, ')]
    public function postConfirmPlan(): string { return $this->handleConfirmPlan(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '娴佸紡寰皟/閲嶅缓寤虹珯鏂规涔?, ')]
    public function postPlanSse(): void { $this->handlePlanSse(); }
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '娴佸紡寰皟/閲嶅缓寤虹珯鏂规涔?GET鍏煎)', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPlanSse(): void { $this->handlePlanSse(); }
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '娴佸紡寰皟/閲嶅缓寤虹珯鏂规涔?POST璺敱GET鍏煎)', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getPostPlanSse(): void { $this->handlePlanSse(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鏄惧紡缁х画鏈畬鎴愭瀯寤?, ')]
    public function postResumeBuild(): string { return $this->safeHandleStartBuild(true); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鍚姩涓婚鏋勫缓', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartBuild(): string { return $this->safeHandleStartBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI site agent API', 'mdi-api', 'Start AI asset generation', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartAssetGeneration(): string { return $this->handleStartAssetGeneration(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI site agent API', 'mdi-api', 'Upload AI site reference image', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postUploadReferenceImage(): string { return $this->handleUploadReferenceImage(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI site agent API', 'mdi-api', 'Start AI asset generation', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function startAssetGeneration(): string { return $this->handleStartAssetGeneration(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鎵ц铏氭嫙涓婚缂栨帓锛堝吋瀹癸級', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRunVirtualTheme(): string { return $this->safeHandleStartBuild(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鍚姩鍗曢〉閲嶅缓', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartRegeneratePage(): string { return $this->handleStartRegeneratePage(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鍚姩鍖哄潡 AI 寰皟', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartRefineComponent(): string { return $this->handleStartRefineComponent(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI Site Agent API', 'mdi-api', 'Start block partial patch', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPatchBlock(): string { return $this->handleStartPatchBlock(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI Site Agent API', 'mdi-api', 'Retry AI operation', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postRetryAiOperation(): string { return $this->handleRetryAiOperation(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鍖哄潡 AI 寰皟锛圫SE锛?, ')]
    public function postBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }
    public function getBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }
    public function getPostBlockRefineSse(): void { $this->handleBlockRegenerateSse(true); }

    public function postBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }
    public function getBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }
    public function getPostBlockRegenerateSse(): void { $this->handleBlockRegenerateSse(false); }

    public function postUpdateBlockConfig(): string { return $this->handleUpdateBlockConfig(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鍚姩鍙戝竷娴佺▼', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postStartPublish(): string { return $this->handleStartPublish(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鍒囨崲褰撳墠棰勮椤?, ')]
    public function postSwitchPreviewPage(): string { return $this->handleSwitchPreviewPageCompact(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鎷夊彇宸ヤ綔鍖哄揩鐓э紙鍚樁娈典竴闃熷垪淇℃伅锛?, ')]
    public function postWorkspaceSnapshot(): string
    {
        return $this->handleWorkspaceSnapshot();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鎸夐渶鎷夊彇 scope artifact', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postGetScopeArtifact(): string
    {
        return $this->handleGetScopeArtifact();
    }

    public function getGetScopeArtifact(): string
    {
        return $this->handleGetScopeArtifact();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 寤虹珯浼氳瘽 API', 'mdi-api', '鎸夐渶鎷夊彇宸ヤ綔鍖?artifact', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postWorkspaceArtifact(): string
    {
        return $this->handleGetScopeArtifact();
    }

    public function getWorkspaceArtifact(): string
    {
        return $this->handleGetScopeArtifact();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鎸夐渶鎷夊彇铏氭嫙涓婚鍧?, ')]
    public function postGetScopeBlock(): string
    {
        return $this->handleGetScopeBlock();
    }

    public function getGetScopeBlock(): string
    {
        return $this->handleGetScopeBlock();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'ai site agent api', 'mdi-api', 'ai site agent api', '鍙戝竷鍓嶆鏌?, ')]
    public function postPublishChecklist(): string { return $this->handlePublishChecklist(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'ai site agent workspace', 'mdi-clipboard-text-outline', 'ai site agent workspace', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postDeleteWorkspace(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('鍙傛暟鏃犳晥')]);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶')]);
        }

        if (!$this->sessionService->deleteSession($session->getId(), $adminId)) {
            return $this->fetchJson(['success' => false, 'message' => __('Message')]);
        }

        return $this->fetchJson(['success' => true, 'message' => __('宸ヤ綔鍖哄凡鍒犻櫎')]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'ai site agent workspace', 'mdi-clipboard-text-outline', 'ai site agent workspace', 'GuoLaiRen_PageBuilder::ai_site_agent')]
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
            'message' => __('鍘熺敓 SSE 妯″紡鏃犻渶棰濆缁害'),
            'native_sse' => true,
        ]);
    }

    /**
     * 娴嬭瘯 SSE 鐭疆璇紙鏃犻渶璁よ瘉锛?
     *
     * 鐢ㄤ簬楠岃瘉 SSE 鐭疆璇㈡満鍒舵槸鍚︽甯稿伐浣?
     * 璀﹀憡锛氫粎鐢ㄤ簬娴嬭瘯锛岀敓浜х幆澧冨簲鍒犻櫎姝ゆ柟娉?
     */
    public function getTestSse(): void
    {
        // 閲婃斁 PHP session 閿侊紝閬垮厤闃诲 SSE
        $this->releasePhpSessionLockForSse();

        $sse = new SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => 'Test SSE connection started']);
        $sse->sendEvent('test', ['timestamp' => time(), 'message' => 'This is a test event']);

        // 闈為樆濉炵ず渚嬶細鍙彂閫佷竴缁勭珛鍗冲彲鐢ㄤ簨浠讹紝涓嶅仛杞绛夊緟銆?
        $sse->sendEvent('poll', ['count' => 1, 'timestamp' => time()]);
        $sse->sendEvent('poll', ['count' => 2, 'timestamp' => time()]);
        $sse->sendEvent('poll', ['count' => 3, 'timestamp' => time()]);

        $sse->complete(['success' => true, 'message' => 'Test complete, please reconnect']);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'ai site agent workspace', 'mdi-clipboard-text-outline', 'ai site agent workspace', '鎵ц鏋勫缓/閲嶅缓/鍙戝竷鎿嶄綔娴?, ')]
    public function getOperationSse(): void { $this->handleOperationSse(); }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_create', '鍒涘缓 AI 寤虹珯浼氳瘽', 'mdi-plus', '鍒涘缓鏂扮殑 AI 寤虹珯浼氳瘽', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postCreateSession(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('Message')]);
        }

        $fakeMode = $this->getRequestBodyValue('fake_mode', '0');
        $fakeModeEnabled = $fakeMode === true
            || $fakeMode === 1
            || $fakeMode === '1'
            || $fakeMode === 'true';
        $siteTitle = \trim((string)$this->getRequestBodyValue('site_title', ''));
        $briefDescription = \trim((string)$this->getRequestBodyValue('brief_description', $this->getRequestBodyValue('user_description', '')));
        if ($siteTitle === '') {
            $siteTitle = AiSiteAgentWorkspaceDebugDefaults::SITE_TITLE;
        }
        if ($briefDescription === '') {
            $briefDescription = AiSiteAgentWorkspaceDebugDefaults::BRIEF_DESCRIPTION;
        }
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
                'pagebuilder_pages_by_type' => [],
                'virtual_pages_by_type' => [],
                'page_type_layouts' => [],
                'site_profile_manual' => [],
                'active_operation' => [],
                'build_summary' => [],
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

        return $this->fetchJson([
            'success' => true,
            'public_id' => $session->getPublicId(),
            'workspace_url' => $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $session->getPublicId()]),
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_domain_purchase', 'ai site agent domain purchase', 'mdi-api', 'ai site agent domain purchase', '鍦?PageBuilder 宸ヤ綔鍙颁腑浠ｇ悊 Websites 鍩熷悕璐拱宸ヤ綔娴?, ')]
    public function postStartDomainPurchase(): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('鍙傛暟鏃犳晥')]);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶')]);
        }

        $linkedWebsitesSession = $this->ensureLinkedWebsitesMirrorSession($session, $adminId);
        if (!$linkedWebsitesSession instanceof WebsitesAiSiteBuilderSession) {
            return $this->fetchJson(['success' => false, 'message' => __('Message')]);
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
                'message' => (string)($result['message'] ?? __('鍔犲叆鍩熷悕璐拱闃熷垪澶辫触')),
            ]);
        }

        $this->syncPageBuilderScopeFromLinkedWebsitesSession($session, $linkedWebsitesSession, $adminId);
        $freshPageBuilderSession = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $freshWebsitesSession = $this->getWebsitesSessionService()->loadById($linkedWebsitesSession->getId(), $adminId) ?? $linkedWebsitesSession;
        $state = $this->getWebsitesDomainPurchaseWorkbenchService()->buildViewState($freshWebsitesSession);

        return $this->fetchJson([
            'success' => true,
            'message' => (string)($result['message'] ?? __('Message')),
            'state' => $state,
            'pagebuilder_state' => $this->buildWorkspaceState($freshPageBuilderSession, $adminId, 80, true),
            'linked_public_id' => $freshWebsitesSession->getPublicId(),
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

    private function handleDesignDirectionList(): string
    {
        $adminId = (int)$this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('Message')]);
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
            return $this->fetchJson(['success' => false, 'message' => __('Message')]);
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
            return $this->fetchJson(['success' => false, 'message' => __('Message')]);
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
            return $this->jsonError('INVALID_PARAMS', (string)__('鍙傛暟鏃犳晥'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶'), self::PARAMS_PUBLIC_ID);
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
        $targetDomain = \trim((string)($scope['target_domain'] ?? ''));
        if ($targetDomain === '') {
            return $this->jsonError(
                'TARGET_DOMAIN_REQUIRED',
                (string)__('璇峰厛濉啓瑕佺粦瀹氱殑鐩爣鍩熷悕'),
                ['target_domain']
            );
        }
        $scopeBeforeBriefPageTypeExpansion = $scope;
        $scope = $this->scopeCompatibilityService->augmentLegacyDefaultPageTypesFromBrief($scope);
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
        if ($requestedPlanLocale !== '') {
            $scope['content_locale'] = (string)$scope['plan_locale'];
            $scope['ai_content_locale'] = (string)$scope['plan_locale'];
            $scopePatch['content_locale'] = (string)$scope['plan_locale'];
            $scopePatch['ai_content_locale'] = (string)$scope['plan_locale'];
        }
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
        // 鏂规鐢熸垚寮€濮嬫椂鍏堟寔涔呭寲鐢ㄦ埛鏈疆杈撳叆锛堝煙鍚?涓€鍙ヨ瘽鎻忚堪/璇█/椤甸潰绫诲瀷绛夛級锛屽啀鍏ラ槦鐢熸垚浠诲姟銆?
        $persistPatch = \array_replace($scopePatch, [
            'plan_locale' => (string)$scope['plan_locale'],
            'plan_confirmed' => 0,
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
        $hasRetryablePlanFailures = $this->buildTaskService->hasRetryableAiFailures($currentScope, 'plan');
        if (
            $hasPlanDraft
            && \in_array((string)($planStartDecision['action'] ?? ''), ['rebuild', 'translate'], true)
            && !$confirmRegenerate
        ) {
            $confirmMessage = (string)($planStartDecision['action'] ?? '') === 'translate'
                ? (string)__('Message')
                : (string)__('妫€娴嬪埌寤虹珯闇€姹傚凡鍙樻洿銆傛槸鍚︾珛鍗抽噸寤哄缓绔欐柟妗堬紵');
            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('Message'),
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
                'message' => (string)__('Message'),
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
        ];
        if ($hasRetryablePlanFailures && $effectivePlanPromptMode === 'resume_plan') {
            $planOperationDetails['resume_failed_tasks'] = 1;
        }
        $result = $this->startOperation($session, $adminId, 'plan', $stage, \array_replace($planRebuildResetPatch, [
            'plan_locale' => (string)$scope['plan_locale'],
            'content_locale' => (string)($scope['content_locale'] ?? $scope['plan_locale'] ?? ''),
            'ai_content_locale' => (string)($scope['ai_content_locale'] ?? $scope['content_locale'] ?? $scope['plan_locale'] ?? ''),
            'plan_confirmed' => 0,
            '_plan_sse_request' => [
                'prompt_mode' => $effectivePlanPromptMode,
                'instruction' => $requestedInstruction,
                'target_scope' => $requestedTargetScope,
                'round' => $requestedRound,
                'plan_locale' => (string)$scope['plan_locale'],
                'content_locale' => (string)($scope['content_locale'] ?? $scope['plan_locale'] ?? ''),
                AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY => $selectedSkillCodes,
                'design_direction_mode' => (string)($scope['design_direction_mode'] ?? DesignDirectionService::MODE_AUTO),
                'design_direction_code' => (string)($scope['design_direction_code'] ?? ''),
                'design_direction_snapshot' => \is_array($scope['design_direction_snapshot'] ?? null) ? $scope['design_direction_snapshot'] : [],
                'design_direction_hash' => (string)($scope['design_direction_hash'] ?? ''),
            ],
        ]), '', AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING, $planOperationDetails);
        if (empty($result['success'])) {
            if ((string)($result['operation'] ?? '') === 'plan') {
                $resumeExecutionToken = \trim((string)($result['execution_token'] ?? ''));
                $resumeStreamUrl = \trim((string)($result['stream_url'] ?? ''));
                if ($resumeExecutionToken !== '' && $resumeStreamUrl !== '') {
                    return $this->fetchJson([
                        'success' => true,
                        'message' => (string)__('Message'),
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
                        'data' => $this->buildWorkspaceOperationPayload(
                            $this->buildWorkspaceState($session, $adminId, 24, true),
                            'plan'
                        ),
                    ]);
                }
                // 鍚庣璇嗗埆鍒?plan 宸?鍗犱綅"锛屼絾缂哄皯鏈夋晥 execution_token/stream_url锛屾棤娉?SSE 澶嶇敤锛?
                // 涓诲姩鍙栨秷鏃?active_operation 骞堕噸鏂板彂璧蜂竴娆″惎鍔紝閬垮厤鍓嶇闄峰叆"缂哄皯 SSE 鍙傛暟"銆?
                $this->cancelActivePlanOperationForScopeChange($session, $adminId);
                $session = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $stage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
                $result = $this->startOperation($session, $adminId, 'plan', $stage, \array_replace($planRebuildResetPatch, [
                    'plan_locale' => (string)$scope['plan_locale'],
                    'content_locale' => (string)($scope['content_locale'] ?? $scope['plan_locale'] ?? ''),
                    'ai_content_locale' => (string)($scope['ai_content_locale'] ?? $scope['content_locale'] ?? $scope['plan_locale'] ?? ''),
                    'plan_confirmed' => 0,
                    '_plan_sse_request' => [
                        'prompt_mode' => $effectivePlanPromptMode,
                        'instruction' => $requestedInstruction,
                        'target_scope' => $requestedTargetScope,
                        'round' => $requestedRound,
                        'plan_locale' => (string)$scope['plan_locale'],
                        'content_locale' => (string)($scope['content_locale'] ?? $scope['plan_locale'] ?? ''),
                        AiSiteScopeCompatibilityService::SELECTED_SKILL_CODES_KEY => $selectedSkillCodes,
                        'design_direction_mode' => (string)($scope['design_direction_mode'] ?? DesignDirectionService::MODE_AUTO),
                        'design_direction_code' => (string)($scope['design_direction_code'] ?? ''),
                        'design_direction_snapshot' => \is_array($scope['design_direction_snapshot'] ?? null) ? $scope['design_direction_snapshot'] : [],
                        'design_direction_hash' => (string)($scope['design_direction_hash'] ?? ''),
                    ],
                ]), '', AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING, $planOperationDetails);
                if (empty($result['success'])) {
                    return $this->fetchJson([
                        'success' => false,
                        'message' => (string)($result['message'] ?? __('Message')),
                        'operation' => (string)($result['operation'] ?? 'plan'),
                    ]);
                }
                // 璧板埌姝ゅ璇存槑娓呯悊鍚庨噸璇曞惎鍔ㄦ垚鍔燂紝缁х画璧颁笅鏂规甯告垚鍔熷搷搴旇矾寰勩€?
            } else {
                return $this->fetchJson([
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('褰撳墠鏃犳硶鍚姩寤虹珯鏂规鐢熸垚')),
                    'operation' => (string)($result['operation'] ?? ''),
                ]);
            }
        }

        $responseState = \is_array($result['data'] ?? null)
            ? $result['data']
            : $this->buildWorkspaceOperationPayload(
                $this->buildWorkspaceState($session, $adminId, 24, true),
                'plan'
            );
        return $this->fetchJson([
            'success' => true,
            'message' => (string)__('Message'),
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
            return $this->jsonError('INVALID_PARAMS', (string)__('鍙傛暟鏃犳晥'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶'), self::PARAMS_PUBLIC_ID);
        }

        $requestedStage = $this->scopeCompatibilityService->normalizeStage(
            \trim((string)$this->getRequestBodyValue('stage', ''))
        );
        $currentStage = $this->scopeCompatibilityService->normalizeStage((string)$session->getStage());
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_PLAN,
                self::PLAN_CONFIRMATION_ARTIFACT_KEYS
            )
        );
        if ($requestedStage !== '' && $requestedStage !== AiSiteAgentSession::STAGE_PLAN) {
            $requestedStageScope = $this->scopeCompatibilityService->normalizeScope(
                $this->sessionService->loadScopeForStage($session, $requestedStage)
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
        if (!$this->hasConfirmableStageOnePlanPayload($scope)) {
            return $this->jsonError('PLAN_NOT_READY', (string)__('Message'), ['public_id']);
        }
        $planConfirmationStage1Validation = \is_array($scope['stage1_validation_report'] ?? null)
            ? $scope['stage1_validation_report']
            : [];
        $hasStageOnePayload = $this->isUsableStageOnePlanJsonValue($scope['plan_json'] ?? null)
            || $this->isUsableStageOnePlanJsonValue($scope['plan_structured'] ?? null);
        $existingBuildPlanV2 = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if (!$hasStageOnePayload && $existingBuildPlanV2 === []) {
            return $this->jsonError('PLAN_NOT_READY', (string)__('Message'), ['public_id']);
        }

        if ($this->buildTaskService->hasRetryableAiFailures($scope, 'plan')) {
            $summary = $this->buildTaskService->summarizeRetryableAiFailures($scope, 'plan');
            return $this->jsonError(
                'RETRYABLE_AI_FAILURES_PENDING',
                (string)__('Message'),
                ['public_id'],
                ['retryable_ai_failure_count' => (int)($summary['count'] ?? 0), 'retryable_ai_failures' => $summary]
            );
        }
        $stageOneCoverage = $this->inspectStageOnePlanPageTypeCoverage($scope);
        if (($stageOneCoverage['missing'] ?? []) !== []) {
            return $this->jsonError(
                'PLAN_PAGE_TYPES_INCOMPLETE',
                (string)__('Message', [
                    'page_types' => \implode(', ', \array_slice($stageOneCoverage['missing'], 0, 12)),
                ]),
                ['public_id', 'page_types'],
                ['page_type_coverage' => $stageOneCoverage]
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

        $scopePatch = \array_replace($directionLockPatch, [
            'plan_confirmed' => 1,
        ]);
        $buildPlanSourceScope = \array_replace($scope, $scopePatch);
        foreach ([
            'build_plan_v2_validation',
            'build_plan_confirmed',
            'build_plan_confirmed_at',
            'has_build_plan_v2',
        ] as $derivedBuildPlanKey) {
            unset($buildPlanSourceScope[$derivedBuildPlanKey]);
        }
        $buildPlanPatch = $this->buildPlanV2ConfirmationScopePatch($buildPlanSourceScope);
        unset($buildPlanSourceScope);
        if ((int)($buildPlanPatch['build_plan_confirmed'] ?? 0) !== 1) {
            $buildPlanValidation = \is_array($buildPlanPatch['build_plan_v2_validation'] ?? null)
                ? $buildPlanPatch['build_plan_v2_validation']
                : [];
            $buildPlanErrors = \is_array($buildPlanValidation['errors'] ?? null)
                ? \array_values(\array_filter(\array_map('strval', $buildPlanValidation['errors']), static fn(string $error): bool => \trim($error) !== ''))
                : [];
            $detail = $buildPlanErrors !== []
                ? \implode('; ', \array_slice($buildPlanErrors, 0, 6))
                : '';
            $message = $detail !== ''
                ? (string)__('鏂规鍚堝悓鏍￠獙澶辫触锛?{detail}', ['detail' => $detail])
                : (string)__('Message');

            return $this->jsonError(
                'BUILD_PLAN_V2_INVALID',
                $message,
                ['public_id'],
                [
                    'build_plan_v2_validation' => $buildPlanValidation,
                    'stage1_validation' => $planConfirmationStage1Validation,
                ]
            );
        }
        $scopePatch = \array_replace($scopePatch, $buildPlanPatch);

        $confirmedScope = $this->scopeCompatibilityService->normalizeScope(\array_replace($scope, $scopePatch));
        $confirmedScope = $this->buildTaskService->ensureTaskScope(
            $confirmedScope,
            \is_array($confirmedScope['website_profile'] ?? null) ? $confirmedScope['website_profile'] : [],
            (string)($confirmedScope['workspace_track'] ?? '')
        );
        $scopePatch = \array_replace($scopePatch, $this->buildTaskService->extractBuildPlanDerivedScopePatch($confirmedScope));
        $scopePatch['build_summary'] = [
            'build_plan_contract_id' => (string)($scopePatch['build_plan_v2']['contract_meta']['id'] ?? ''),
        ];

        $confirmOnly = (int)$this->getRequestBodyValue('confirm_only', 0) === 1
            || (int)$this->getRequestBodyValue('build_deferred', 0) === 1
            || \strtolower(\trim((string)$this->getRequestBodyValue('start_build', '1'))) === '0';

        $this->sessionService->mergeScope($session->getId(), $adminId, $scopePatch);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($fresh->getStage()),
            'plan_confirmed',
            (string)__('Message'),
            [
                'operation' => 'plan_confirm',
                'details' => [
                    'build_plan_contract_id' => (string)($scopePatch['build_plan_v2']['contract_meta']['id'] ?? ''),
                    'build_plan_block_count' => \is_array($scopePatch['build_plan_v2']['blocks'] ?? null) ? \count($scopePatch['build_plan_v2']['blocks']) : 0,
                ],
            ]
        );

        if ($confirmOnly) {
            $state = $this->buildWorkspaceState($fresh, $adminId, 24, true);
            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('Build plan confirmed; you can generate a selected block first.'),
                'start_sse' => false,
                'operation' => 'plan_confirm',
                'data' => $this->buildWorkspaceConfirmPayload($state, 'plan'),
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
            $existingData = \is_array($buildStartResult['data'] ?? null) ? $buildStartResult['data'] : [];
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $fresh;
            $confirmState = $this->buildWorkspaceState($fresh, $adminId, 24, true);
            $buildStartResult['data'] = \array_replace(
                $this->buildWorkspaceConfirmPayload($confirmState, 'plan'),
                $existingData
            );
            $buildStartResult['message'] = (string)__('Message');
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
            $this->sendSseContractError($sse, 'INVALID_PARAMS', (string)__('Message'), ['public_id', 'prompt_mode']);
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->sendSseContractError($sse, 'SESSION_NOT_FOUND', (string)__('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶'), ['public_id'], 404);
            $sse->complete(['success' => false]);
            return;
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_PLAN)
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
            $instruction = $instruction !== ''
                ? ((string)__('Message') . ' ' . $instruction)
                : (string)__('Message');
        }
        if ($effectivePromptMode === 'rebuild') {
            $instruction = $instruction !== ''
                ? ((string)__('Message') . ' ' . $instruction)
                : (string)__('Message');
        } else {
            $instruction = $instruction !== ''
                ? ((string)__('Message') . ' ' . $instruction)
                : (string)__('Message');
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
                ? (string)__('姝ｅ湪閲嶅缓寤虹珯鏂规')
                : (string)__('姝ｅ湪寰皟寤虹珯鏂规'),
            'prompt_mode' => $effectivePromptMode,
            'round' => $round,
            'target_scope' => $targetScope,
            'plan_locale' => $requestedPlanLocale,
            'locale_changed_force_rebuild' => ($effectivePromptMode !== $promptMode) ? 1 : 0,
        ]);
        $sse->sendEvent('progress', [
            'message' => (string)__('Message'),
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
                foreach (['stage1_step', 'stage1_phase', 'page_type', 'page_total'] as $key) {
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
                ? $this->executionBlueprintService->rebuildDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                    'instruction' => $instruction,
                    'round' => $round,
                ], $emitAiChunkProgress, $emitPlanPipelineProgress)
                : $this->executionBlueprintService->refineDraftPlan($scope, \is_array($websiteProfile) ? $websiteProfile : [], [
                    'instruction' => $instruction,
                    'target_scope' => $targetScope,
                    'round' => $round,
                ], $emitAiChunkProgress, $emitPlanPipelineProgress);
            $this->flushPlanSseRawChunkBuffer($sse, $effectivePromptMode, $rawChunkInfoBuffer);
            if ((int)($scope['fake_mode'] ?? 0) !== 1 && (int)($artifacts['ai_generated'] ?? 0) !== 1) {
                throw new \RuntimeException((string)__('Message'));
            }

            $derivedPatch = \is_array($artifacts['derived_scope_patch'] ?? null) ? $artifacts['derived_scope_patch'] : [];
            $markdown = (string)($artifacts['markdown'] ?? '');
            $structured = \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [];
            $planJson = \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [];
            $scopePatch = \array_replace($derivedPatch, [
                'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
                'plan_json' => $planJson,
                'plan_markdown' => $markdown,
                'plan_structured' => $structured !== [] ? $structured : $planJson,
                'plan_workbench' => \is_array($artifacts['plan_workbench'] ?? null) ? $artifacts['plan_workbench'] : [],
                'plan_locale' => $this->resolvePlanLocale($scope),
                'plan_ai_generated' => (int)($artifacts['ai_generated'] ?? 0),
                'stage1_contract' => \is_array($planJson['stage1_contract'] ?? null) ? $planJson['stage1_contract'] : [],
                'stage1_validation_report' => \is_array($planJson['stage1_validation_report'] ?? null) ? $planJson['stage1_validation_report'] : [],
                'stage1_first_pass' => (int)($planJson['stage1_first_pass'] ?? 0),
                'stage1_generation_attempts' => \is_array($planJson['stage1_generation_attempts'] ?? null) ? $planJson['stage1_generation_attempts'] : [],
                'partial_retry_required' => (int)($artifacts['partial_retry_required'] ?? 0),
                'plan_generated_at' => \date('Y-m-d H:i:s'),
                // 寰皟鍦ㄥ凡鏈夌‘璁ょ姸鎬佷笅榛樿淇濇寔宸茬‘璁わ紝閬垮厤浠呭仛鏂囨/鍖哄潡澧炶ˉ鍚庢墦鏂悗缁樁娈点€?
                'plan_confirmed' => $effectivePromptMode === 'rebuild' ? 0 : (int)($scope['plan_confirmed'] ?? 0),
                'plan_last_prompt_mode' => $effectivePromptMode,
                'plan_last_target_scope' => $targetScope,
                'plan_last_round' => $round,
                'plan_generated_locale' => $requestedPlanLocale,
                'plan_generated_page_types' => \array_values(\array_map('strval', $requestedPageTypes)),
                'plan_generated_source_signature' => $this->buildPlanSourceSignature($scope),
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
                (string)__('Message'),
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
                    ? (string)__('寤虹珯鏂规宸查噸寤猴紝璇风‘璁ゅ悗杩涘叆鏋勫缓娴佺▼')
                    : (string)__('寤虹珯鏂规宸插井璋冿紝璇风‘璁ゅ悗杩涘叆鏋勫缓娴佺▼'),
                [
                    'operation' => $effectivePromptMode === 'rebuild' ? 'rebuild_plan' : 'refine_plan',
                    'details' => [
                        'target_scope' => $targetScope,
                        'round' => $round,
                        'plan_locale' => $requestedPlanLocale,
                    ],
                ]
            );
            $sse->sendEvent('progress', [
                'message' => (string)__('姝ｅ湪杈撳嚭鏇存柊鍚庣殑寤虹珯鏂规'),
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
                    ? (string)__('寤虹珯鏂规閲嶅缓瀹屾垚')
                    : (string)__('寤虹珯鏂规寰皟瀹屾垚'),
                'prompt_mode' => $effectivePromptMode,
                'requested_prompt_mode' => $promptMode,
                'plan_locale' => $requestedPlanLocale,
                'plan' => [
                    'json' => $planJson,
                    'markdown' => $markdown,
                    'structured' => $structured !== [] ? $structured : $planJson,
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
                    'message' => (string)__('Message'),
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
            'build_plan_v2' => [],
            'plan_projection' => [],
            'content_manifest' => [],
            'build_plan_v2_validation' => [],
            'build_plan_confirmed' => 0,
            'build_plan_confirmed_at' => '',
            'has_build_plan_v2' => 0,
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
            'stage1_contract' => [],
            'stage1_validation_report' => [],
            'stage1_first_pass' => 0,
            'stage1_generation_attempts' => [],
            'publish_quality_gate' => [],
            'shared_components' => [],
            'theme_context_snapshot' => [],
            'shared_prompt_context' => [],
            'plan_rebuild_summary' => [],
            'plan_change_scope_report' => [],
            'plan_generation_progress' => [],
            '_plan_generation_checkpoint' => [],
            'retryable_ai_failures' => [],
            'retryable_ai_failure_count' => 0,
            'next_stage_blocked_by_ai_failures' => 0,
            'partial_retry_required' => 0,
            'publish_blocked_by_latest_ai_failure' => 0,
            'build_summary' => [],
            '_plan_sse_request' => [],
            'build_workbench' => [],
            'build_contracts' => [],
            'render_data_contract' => [],
            'task_results' => [],
            'qa_report_v2' => [],
            'repair_patch' => [],
        ];
    }

    private function persistExistingPlanDraftOrThrow(
        AiSiteAgentSession $session,
        int $adminId,
        string $source
    ): AiSiteAgentSession {
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_PLAN,
                self::PLAN_CONFIRMATION_ARTIFACT_KEYS
            )
        );
        $planJson = $this->isUsableStageOnePlanJsonValue($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $planStructured = $this->isUsableStageOnePlanJsonValue($scope['plan_structured'] ?? null) ? $scope['plan_structured'] : [];
        if ($planStructured === [] && $planJson !== []) {
            $planStructured = $planJson;
        }
        if ($planJson === [] && $planStructured !== []) {
            $planJson = $planStructured;
        }
        $planMarkdown = \trim((string)($scope['plan_markdown'] ?? ''));

        if ($planJson === [] && $planStructured === []) {
            throw new \RuntimeException('Stage-one plan draft is not ready to save source=' . $source);
        }

        $patch = [
            'plan_json' => $planJson,
            'plan_structured' => $planStructured,
            'plan_markdown' => $planMarkdown,
            'plan_generated_at' => (string)($scope['plan_generated_at'] ?? \date('Y-m-d H:i:s')),
        ];

        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, $patch);
        if (!$saved) {
            throw new \RuntimeException('Stage-one plan draft persist failed: mergeScope returned false.');
        }

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $freshScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage(
                $fresh,
                AiSiteAgentSession::STAGE_PLAN,
                self::PLAN_CONFIRMATION_ARTIFACT_KEYS
            )
        );
        $freshPlanJson = $this->isUsableStageOnePlanJsonValue($freshScope['plan_json'] ?? null) ? $freshScope['plan_json'] : [];
        $freshPlanStructured = $this->isUsableStageOnePlanJsonValue($freshScope['plan_structured'] ?? null) ? $freshScope['plan_structured'] : [];
        if ($freshPlanJson !== [] || $freshPlanStructured !== []) {
            return $fresh;
        }

        throw new \RuntimeException('Stage-one plan draft persist verification failed source=' . $source);
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
                (string)__('Message'),
                self::PARAMS_PUBLIC_ID
            );
        }
    }

    private function handleStartBuild(bool $isResume = false): string
    {
        $adminId = (int)$this->getLoginUserId();
        $publicId = \trim((string)$this->getRequestBodyValue('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->jsonError('INVALID_PARAMS', (string)__('鍙傛暟鏃犳晥'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶'), self::PARAMS_PUBLIC_ID);
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
        $siteProfileManual = \is_array($scopePatch['site_profile_manual'] ?? null) ? $scopePatch['site_profile_manual'] : [];
        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale', 'plan_locale'] as $manualField) {
            if (
                \array_key_exists($manualField, $scopePatch)
                && $this->isMeaningfulProfileManualValue($scopePatch[$manualField])
                && !\array_key_exists($manualField, $siteProfileManual)
            ) {
                $siteProfileManual[$manualField] = true;
            }
        }
        if ($siteProfileManual !== []) {
            $scopePatch['site_profile_manual'] = $siteProfileManual;
        }
        $currentScope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                self::BUILD_OPERATION_ARTIFACT_KEYS
            )
        );
        $currentScope = $this->normalizeBuildPlanConfirmationForBuild($currentScope);
        $scopePatch = $this->buildTaskService->stripBuildPlanMutationScopePatch($scopePatch, $currentScope);
        $mergedScope = $this->scopeCompatibilityService->normalizeScope(\array_replace($currentScope, $scopePatch));
        $mergedScope = $this->buildTaskService->restoreBuildPlanContract($mergedScope, $currentScope);
        $mergedScope = $this->normalizeBuildPlanConfirmationForBuild($mergedScope);
        $websiteProfile = $this->profileGenerationService->generate($mergedScope, false);
        if (\is_array($websiteProfile) && $websiteProfile !== []) {
            $mergedScope['website_profile'] = $websiteProfile;
            $scopePatch['website_profile'] = $websiteProfile;
        }
        if ($this->isBuildPlanReadyForBuild($mergedScope)) {
            $mergedScope = $this->buildTaskService->ensureTaskScope(
                $mergedScope,
                \is_array($mergedScope['website_profile'] ?? null) ? $mergedScope['website_profile'] : [],
                (string)($mergedScope['workspace_track'] ?? '')
            );
            $scopePatch = \array_replace($scopePatch, $this->buildTaskService->extractBuildPlanDerivedScopePatch($mergedScope));
        }
        if ($this->buildTaskService->hasRetryableAiFailures($mergedScope, 'plan')) {
            $summary = $this->buildTaskService->summarizeRetryableAiFailures($mergedScope, 'plan');
            return $this->jsonError(
                'RETRYABLE_AI_FAILURES_PENDING',
                (string)__('Message'),
                ['public_id'],
                ['retryable_ai_failure_count' => (int)($summary['count'] ?? 0), 'retryable_ai_failures' => $summary]
            );
        }
        $buildPlanCoverage = $this->buildTaskService->inspectConfirmedBuildPlanPageTypeCoverage($mergedScope);
        if (
            \is_array($mergedScope['build_plan_v2'] ?? null)
            && ($mergedScope['build_plan_v2'] ?? []) !== []
            && (($buildPlanCoverage['missing_page_types'] ?? []) !== [] || ($buildPlanCoverage['missing_page_section_tasks'] ?? []) !== [])
        ) {
            $missing = \array_values(\array_unique(\array_merge(
                \is_array($buildPlanCoverage['missing_page_types'] ?? null) ? $buildPlanCoverage['missing_page_types'] : [],
                \is_array($buildPlanCoverage['missing_page_section_tasks'] ?? null) ? $buildPlanCoverage['missing_page_section_tasks'] : []
            )));
            return $this->jsonError(
                'BUILD_PLAN_PAGE_TYPES_INCOMPLETE',
                (string)__('Message', [
                    'page_types' => \implode(', ', \array_slice($missing, 0, 12)),
                ]),
                ['public_id', 'page_types'],
                ['page_type_coverage' => $buildPlanCoverage]
            );
        }
        if (!$this->isBuildPlanReadyForBuild($mergedScope)) {
            return $this->jsonError(
                'BUILD_PLAN_REQUIRED_BEFORE_BUILD',
                (string)__('Message'),
                ['public_id', 'build_plan_confirmed']
            );
        }
        if ($isResume) {
            $resumeSummary = $this->buildTaskService->summarize($mergedScope);
            $resumeTaskCount = (int)($resumeSummary['pending'] ?? 0)
                + (int)($resumeSummary['failed'] ?? 0)
                + (int)($resumeSummary['running'] ?? 0);
            $retryableSummary = $this->buildTaskService->summarizeRetryableAiFailures($mergedScope, 'build');
            $retryableFailureCount = (int)($retryableSummary['count'] ?? 0);
            if ($resumeTaskCount <= 0 && $retryableFailureCount <= 0) {
                return $this->fetchJson([
                    'success' => true,
                    'message' => (string)__('褰撳墠娌℃湁寰呯户缁殑鏋勫缓浠诲姟'),
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
            return $this->jsonError('INVALID_PARAMS', (string)__('鍙傛暟鏃犳晥'), ['public_id', 'slot_id']);
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶'), self::PARAMS_PUBLIC_ID);
        }

        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage($session, AiSiteAgentSession::STAGE_VISUAL_EDIT)
        );
        $assetManifestService = $this->assetManifestService();
        $manifest = $assetManifestService->syncFromBuildPlan($scope);
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
            'status' => 'queued',
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
            'status' => 'queued',
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
                $isStaleRunning = false;
                if ($normalizedStatus === 'running') {
                    $pid = (int)($existing['pid'] ?? 0);
                    $isStaleRunning = $pid <= 0 || !\Weline\Framework\System\Process\Processer::isRunningByPid($pid);
                }
                if (\in_array($normalizedStatus, ['pending', 'queued', 'running'], true) && !$isStaleRunning) {
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
                        'process' => $isStaleRunning
                            ? 'Recovered stale image asset queue runtime; waiting for system scheduler.'
                            : '',
                        'pid' => 0,
                        'finished' => 0,
                    ],
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

    /**
     * @param array<string, mixed> $report
     */
    private function formatBuildPlanPublishReadinessDetail(array $report): string
    {
        $failures = \array_values(\array_filter(\array_map('strval', \is_array($report['failures'] ?? null) ? $report['failures'] : [])));
        if ($failures === []) {
            return (string)__('Message');
        }

        return \implode('; ', \array_slice($failures, 0, 6));
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
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $operation = \trim((string)($activeOperation['operation'] ?? ''));
        $workspaceStatus = $this->scopeCompatibilityService->normalizeWorkspaceStatus((string)($scope['workspace_status'] ?? ''));

        if ($session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        }
        if ($session->getPublishStatus() === AiSiteAgentSession::PUBLISH_STATUS_PUBLISHING || ($operation === 'publish' && \in_array($activeStatus, ['queued', 'running'], true))) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING;
        }
        // 浼樺厛璇嗗埆"姝ｅ湪鏋勫缓涓?锛氬鏋滄煇 publish-blocking AI 鎿嶄綔杩樺湪 queued/running锛?
        // 鍗充究 scope.workspace_status 娈嬬暀 FAILED 涔熷簲褰撴樉绀?BUILDING锛岄伩鍏?UI 鎶婃椿鐫€鐨勯槦鍒楄鏍囦负宸插け璐ャ€?
        if (\in_array($activeStatus, ['queued', 'running'], true) && $this->isPublishBlockingAiBuildOperation($operation)) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        }
        if ($activeStatus === 'error' || $workspaceStatus === AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        }
        if ($canPublish) {
            return AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
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
        if (\is_array($materialized['pagebuilder_pages_by_type']['pagebuilder_pages_by_type'] ?? null)) {
            $nested = $materialized['pagebuilder_pages_by_type'];
            $materialized['pagebuilder_pages_by_type'] = $nested['pagebuilder_pages_by_type'];
            $materialized['preview_page_id'] = (int)($materialized['preview_page_id'] ?? $nested['preview_page_id'] ?? 0);
            $materialized['preview_page_type'] = (string)($materialized['preview_page_type'] ?? $nested['preview_page_type'] ?? '');
            if (empty($materialized['home_page_id']) && !empty($nested['home_page_id'])) {
                $materialized['home_page_id'] = (int)$nested['home_page_id'];
            }
        }
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

        if ($newPages !== [] && \is_array($scope['virtual_pages_by_type'] ?? null)) {
            foreach ($newPages as $pageType => $pageData) {
                if (!\is_array($scope['virtual_pages_by_type'][$pageType] ?? null)) {
                    continue;
                }
                $scope['virtual_pages_by_type'][$pageType]['materialized_page_id'] = (int)($pageData['page_id'] ?? 0);
            }
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
        int $virtualThemeId,
        int $virtualLayoutId = 0
    ): void {
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceEventStatePayload(
            $this->buildWorkspaceState($fresh, $adminId, 80, true),
            [$pageType]
        );
        $sse->sendEvent('environment_ready', [
            'message' => (string)__('缂栬緫鐜宸插噯澶囧ソ锛屽彲鍏堣皟鏁村凡鐢熸垚椤甸潰'),
            'page_type' => $pageType,
            'page_label' => $pageLabel,
            'page_id' => $pageId,
            'virtual_theme_id' => $virtualThemeId,
            'virtual_layout_id' => $virtualLayoutId,
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
        $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
        $preserveRunningQueueRow = false;
        $forceQueueTakeover = $this->shouldForceQueueTakeoverForOperation($operation, $scopePatch, $operationDetails);
        $skipActiveQueueGuardAfterTakeover = false;
        if (
            \in_array($activeStatus, ['queued', 'running'], true)
            && $this->shouldReclaimStaleActiveOperation($activeOperation)
            && $this->isActiveOperationStale($activeOperation)
        ) {
            $scope['active_operation'] = \array_replace($activeOperation, [
                'status' => 'cancelled',
                'message' => (string)__('Message'),
                'updated_at' => \date('Y-m-d H:i:s'),
            ]);
            $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $this->scopeCompatibilityService->normalizeStage($session->getStage()),
                'operation_cancelled',
                (string)__('Message'),
                ['details' => ['reason' => 'stale_active_operation']]
            );
            $activeOperation = \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [];
            $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
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
                $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
            }
        }
        // 娈嬬暀鏃犳晥鐘舵€侊細active_operation 澶勪簬 queued/running 浣?execution_token 缂哄け
        // 锛堝彲鑳藉洜鍓嶆浠诲姟寮傚父涓柇/閮ㄥ垎鍐欏叆鏈竻鐞嗭級銆傝繖绉嶇姸鎬佷笅鏃㈡棤娉曞鐢?SSE锛屼篃鏃犳硶缁х画鎺ㄨ繘锛?
        // 蹇呴』涓诲姩鍥炴敹锛屽惁鍒欎細瀵艰嚧鍓嶇鎷垮埌绌虹殑 execution_token/stream_url 鑰岄櫡鍏?鎿嶄綔宸插垱寤轰絾缂哄皯 SSE 鍙傛暟"寰幆銆?
        if (
            \in_array($activeStatus, ['queued', 'running'], true)
            && \trim((string)($activeOperation['execution_token'] ?? '')) === ''
        ) {
            $scope['active_operation'] = \array_replace($activeOperation, [
                'status' => 'cancelled',
                'message' => (string)__('Message'),
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
            $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
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
                $activeStatus = \trim((string)($activeOperation['status'] ?? ''));
            }
        }
        $runningOperationState = $this->resolveRunningQueuedOperationState($scope, $operation);
        if ($runningOperationState !== []) {
            $runningOperation = \trim((string)($runningOperationState['operation'] ?? ''));
            $runningExecutionToken = \trim((string)($runningOperationState['execution_token'] ?? ''));
            if ($this->shouldReuseRunningQueuedOperation($operation, $runningOperation)) {
                $reusedOperation = $runningOperation !== '' ? $runningOperation : $operation;
                return [
                    'success' => true,
                    'http_status' => 200,
                    'status_code' => 200,
                    'message' => $this->buildRunningOperationReuseMessage($reusedOperation),
                    'operation' => $reusedOperation,
                    'execution_token' => $runningExecutionToken,
                    'stream_url' => $runningExecutionToken !== ''
                        ? $this->buildOperationStreamUrl($session->getPublicId(), $runningExecutionToken)
                        : '',
                ];
            }
            if ($operation === 'plan' || $runningOperation === 'plan') {
                return [
                    'success' => false,
                    'http_status' => 409,
                    'status_code' => 409,
                    'message' => __('褰撳墠宸叉湁姝ｅ湪鎵ц鐨勫缓绔欐柟妗堢敓鎴愶紝璇峰厛绛夊緟瀹屾垚'),
                    'operation' => $runningOperation,
                    'execution_token' => $runningExecutionToken,
                    'stream_url' => ($runningOperation !== '' && $runningExecutionToken !== '')
                        ? $this->buildOperationStreamUrl($session->getPublicId(), $runningExecutionToken)
                        : '',
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
            $baseScope = $this->normalizeBuildPlanConfirmationForBuild($baseScope);
            $scope = $baseScope;
            $scopePatch = $this->buildTaskService->stripBuildPlanMutationScopePatch($scopePatch, $baseScope);
        }
        $scope = \array_replace($scope, $scopePatch);
        if ($operation === 'plan' && (int)($scopePatch['_force_rebuild'] ?? 0) === 1) {
            unset($scope['_artifact_refs']);
            foreach ([
                'plan_json',
                'plan_structured',
                'build_plan_v2',
                'plan_projection',
                'content_manifest',
                'build_workbench',
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
            $scope = $this->buildTaskService->restoreBuildPlanContract($scope, $baseScope);
            $scope = $this->normalizeBuildPlanConfirmationForBuild($scope);
            $resetFailedOrInterruptedTasks = (int)($operationDetails['fresh_repair_failed_tasks'] ?? 0) === 1
                || (int)($operationDetails['resume_failed_tasks'] ?? 0) === 1;
            if ($resetFailedOrInterruptedTasks) {
                $isResumeRepair = (int)($operationDetails['resume_failed_tasks'] ?? 0) === 1;
                $scope = $this->buildTaskService->resetFailedTasksForFreshRepair(
                    $scope,
                    $isResumeRepair
                        ? 'Resume build after previous task failure'
                        : 'Fresh build repair after previous task failure'
                );
                $scope = $this->buildTaskService->resetRunningTasksForInterruptedBuild(
                    $scope,
                    $isResumeRepair
                        ? 'Resume build after interrupted task execution'
                        : 'Fresh build repair after interrupted task execution'
                );
            }
            $scope = $this->buildTaskService->ensureTaskScope(
                $scope,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                (string)($scope['workspace_track'] ?? '')
            );
        }
        if ($this->isPublishBlockingAiBuildOperation($operation)) {
            $scope['latest_build_failed'] = 0;
            $scope['publish_blocked_by_latest_ai_failure'] = 0;
            unset($scope['latest_build_failure'], $scope['publish_blocked_reason']);
        }
        if ($pageType !== '' && !\in_array($pageType, $scope['page_types'], true)) {
            return ['success' => false, 'message' => __('Message')];
        }

        if ((int)($scope['fake_mode'] ?? 0) !== 1 && $this->requiresFrontendAiProviderReadinessCheck($operation)) {
            try {
                $this->assertFrontendAiProviderReadyBeforeQueue($operation);
            } catch (\Throwable $throwable) {
                return $this->buildFrontendAiProviderReadinessFailureResult(
                    $session,
                    $adminId,
                    $operation,
                    $stage,
                    $scope,
                    $throwable
                );
            }
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
            'status' => 'queued',
            'page_type' => $pageType,
            'started_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
            'claimed_by' => '',
            'claimed_at' => '',
            'retry_allowed' => 0,
            'queue_terminal_recovered' => 0,
            'progress_percent' => 0,
            'message' => (string)__('Message'),
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
                $queueId = $this->enqueueOperationQueueTask($freshForQueue, $adminId, $operation, $executionToken, $scopePatch, $operationDetails, $preserveRunningQueueRow);
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
        $this->appendWorkspaceEvent($session->getId(), $adminId, $stage, 'operation_queued', (string)__('Message', ['operation' => $operation, 'page_type' => $pageType]));

        // 鎶婂垰鍏ラ槦鐨勭湡瀹?queue row 鍠傜粰 checkpoint state锛屽墠绔娆℃嬁鍒板搷搴斿氨鑳芥纭樉绀?queued/running锛?
        // 涓嶅啀渚濊禆 SSE 绗竴甯э紱鍚屾椂閬垮厤 PID/杩涚▼鎺㈡椿璺緞銆?
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
        $state = $this->buildQueuedOperationCheckpointState(
            $freshForQueue,
            $stage,
            $operation,
            $activeOperationForResponse,
            \is_array($queueRow) ? $queueRow : null
        );
        return [
            'success' => true,
            'message' => __('Message'),
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
        $activeStatus = \strtolower(\trim((string)($activeOperation['status'] ?? '')));
        if (!\in_array($activeStatus, ['pending', 'queued', 'running', 'processing'], true)) {
            return [];
        }
        if ($activeOperationName !== '' && $activeOperationName !== $operation) {
            return [];
        }

        $candidateRows = [];
        $seenQueueIds = [];
        $linkedQueueId = (int)($activeOperation['queue_id'] ?? 0);
        if ($linkedQueueId > 0) {
            $linkedRow = $this->findAiSiteQueueRowById($linkedQueueId);
            if (\is_array($linkedRow) && $this->isObservedQueueInProgress($linkedRow)) {
                $candidateRows[] = $linkedRow;
                $seenQueueIds[$linkedQueueId] = true;
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
            $takeover = w_query('queue', 'update', [
                'queue_id' => $queueId,
                'patch' => [
                    'status' => 'stop',
                    'finished' => 1,
                    'process' => 'PageBuilder force restart requested; previous queue marked stopped without HTTP worker takeover.',
                ],
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

            $scope = $this->markActiveOperationForceTakenOverForFreshStart($scope, $activeOperation, $operation, $queueId);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $stage,
                'operation_queue_takeover',
                'Active AI queue was marked stopped; a fresh operation will wait for the system scheduler.',
                ['operation' => $operation, 'queue_id' => $queueId],
                AiSiteAgentSessionEvent::LEVEL_WARNING
            );

            return ['taken_over' => true, 'scope' => $scope];
        }

        if ($activeOperationName === $operation) {
            $scope = $this->markActiveOperationForceTakenOverForFreshStart($scope, $activeOperation, $operation, 0);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                $stage,
                'operation_queue_takeover',
                'Active operation was force-released because no live queue row was attached.',
                ['operation' => $operation, 'queue_id' => 0],
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

    private function requiresFrontendAiProviderReadinessCheck(string $operation): bool
    {
        return false;
    }

    private function assertFrontendAiProviderReadyBeforeQueue(string $operation): void
    {
        $response = AiService::generateText(
            'PageBuilder AI readiness check. Reply with OK only.',
            null,
            $this->resolveFrontendAiProviderReadinessScenario($operation),
            null,
            [
                'allow_zero_balance_provider' => true,
                'temperature' => 0.0,
                'max_tokens' => 8,
                'timeout' => 45,
                'disable_conversation_history' => true,
                'disable_conversation_persist' => true,
                'session_id' => 'pagebuilder_ai_provider_preflight',
            ]
        );
        // 棰勬鎺㈤拡鍋跺彂杩斿洖绌轰覆锛堜緥濡傛ā鍨?缃戝叧鐭殏鎶栧姩锛夛紝
        // 杩欓噷涓嶅簲闃绘柇鐪熷疄涓氬姟璇锋眰鍏ラ槦锛涚敱瀹為檯闃熷垪浠诲姟缁х画鎵ц骞跺湪杩愯鎬佺粰鍑虹湡瀹為敊璇€?
        if (\trim((string)$response) === '') {
            return;
        }
    }

    private function resolveFrontendAiProviderReadinessScenario(string $operation): string
    {
        $operation = \strtolower(\trim($operation));
        if ($operation === 'plan') {
            return 'pagebuilder_plan_generation';
        }

        return 'pagebuilder_component_generation';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function buildFrontendAiProviderReadinessFailureResult(
        AiSiteAgentSession $session,
        int $adminId,
        string $operation,
        string $stage,
        array $scope,
        \Throwable $throwable
    ): array {
        $message = $this->formatFrontendAiProviderReadinessFailureMessage($throwable);
        $now = \date('Y-m-d H:i:s');
        $activeOperation = [
            'operation' => $operation,
            'execution_token' => '',
            'status' => 'error',
            'page_type' => '',
            'queue_id' => 0,
            'started_at' => $now,
            'updated_at' => $now,
            'message' => $message,
            'preflight_failed' => 1,
        ];
        $scope['active_operation'] = $activeOperation;
        $scope = $this->writeActiveOperationStateToScope($scope, $activeOperation);
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
        $failure = ['blocked' => false, 'operation' => $operation, 'status' => 'error', 'message' => $message];
        if ($this->isPublishBlockingAiBuildOperation($operation)) {
            $failure = $this->buildPublishBlockingAiFailurePayload($operation, 'error', $message);
            $scope['latest_build_failed'] = 1;
            $scope['latest_build_failure'] = $failure;
            $scope['publish_blocked_by_latest_ai_failure'] = 1;
            $scope['publish_blocked_reason'] = $this->formatPublishBlockedByAiFailureMessage($failure);
        }

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->setStage($session->getId(), $adminId, $stage);
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $stage,
            'ai_provider_preflight_failed',
            $message,
            ['operation' => $operation, 'queue_id' => 0],
            AiSiteAgentSessionEvent::LEVEL_ERROR
        );

        $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
        $state = $this->buildWorkspaceState($fresh, $adminId, 24, true);

        return [
            'success' => false,
            'code' => 'AI_PROVIDER_NOT_READY',
            'message' => $message,
            'operation' => $operation,
            'queue_id' => 0,
            'start_sse' => false,
            'latest_build_failure' => $failure,
            'publish_blocked_by_latest_ai_failure' => !empty($failure['blocked']),
            'data' => $this->buildWorkspaceOperationPayload($state, $operation),
        ];
    }

    private function formatFrontendAiProviderReadinessFailureMessage(\Throwable $throwable): string
    {
        $raw = \trim((string)$throwable->getMessage());
        if ($raw === '') {
            $raw = 'Unknown AI provider error.';
        }
        if (\strlen($raw) > 2000) {
            $raw = \substr($raw, 0, 2000) . '...';
        }

        return 'AI provider readiness check failed before queue creation. Real AI generation cannot start. Error: ' . $raw;
    }

    private function shouldEnqueueOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'image_asset', 'publish'], true);
    }

    private function isAiSiteQueueBackedOperation(string $operation): bool
    {
        return \in_array($operation, ['plan', 'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'image_asset', 'publish'], true);
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
            return (string)__('Message');
        }
        if ($runningOperation === 'build' && $requestedOperation === 'regenerate_page') {
            return (string)__('Message');
        }
        if ($requestedOperation === 'build' && \in_array($runningOperation, ['block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
            return (string)__('Message');
        }
        if ($runningOperation === 'build') {
            return (string)__('Message');
        }
        if (\in_array($runningOperation, ['block_regenerate', 'block_partial_patch', 'regenerate_page'], true)) {
            return (string)__('Message');
        }

        return (string)__('Message');
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
            if (!$this->isObservedQueueInProgress($queueRow)) {
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
        $state = $this->buildWorkspaceState($fresh, $adminId, 24, true);
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
            'data' => $this->buildWorkspaceOperationPayload($state, $runningOperation),
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
        return \array_values(\array_unique(\array_merge(
            [$this->buildAiSiteQueueBizKey($sessionId, $operation)],
            $this->resolveAiSiteLegacyQueueBizKeys($sessionId, $operation)
        )));
    }

    /**
     * @param array<string, mixed> $queueRow
     */
    private function isAiSiteQueueRowLiveInProgress(array $queueRow): bool
    {
        if (!$this->isObservedQueueInProgress($queueRow)) {
            return false;
        }

        $status = \strtolower(\trim((string)($queueRow['status'] ?? '')));
        if (!\in_array($status, ['running', 'processing'], true)) {
            return true;
        }

        $pid = (int)($queueRow['pid'] ?? 0);

        return $pid > 0 && \Weline\Framework\System\Process\Processer::isRunningByPid($pid);
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

        if ($this->isObservedQueueInProgress($queueRow)) {
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
            'status' => 'cancelled',
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
        $reusableQueueRow = $this->findAiSiteOperationQueueRow($session, $operation);
        $reuseFailureMessage = '';
        if (\is_array($reusableQueueRow) && $reusableQueueRow !== [] && $this->shouldReuseAiSiteQueueRow($reusableQueueRow, $preserveRunningQueueRow, $operation, $scopePatch, $operationDetails)) {
            $reusableQueueId = (int)($reusableQueueRow['queue_id'] ?? $reusableQueueRow['id'] ?? 0);
            $updated = w_query('queue', 'update', [
                'queue_id' => $reusableQueueId,
                'patch' => $this->buildAiSiteQueueReusePatch($queueName, $content, $bizKey, $typeId),
            ]);
            if (\is_array($updated) && ($updated['success'] ?? false)) {
                $queueId = (int)($updated['queue_id'] ?? 0);
                if ($queueId > 0) {
                    $this->stopSupersededPendingAiSiteQueueRows($bizKey, $queueId, $operation);
                    return $queueId;
                }
            }
            $reuseFailureMessage = \is_array($updated)
                ? \trim((string)($updated['message'] ?? ''))
                : 'invalid queue update response';
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
                    ? (string)__('鍒涘缓闃熷垪浠诲姟澶辫触锛?{1}', [$detail])
                    : (string)__('Message')
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
     * Queue rows should identify the operation, not duplicate the session's'
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
        $scopePatch = $this->buildTaskService->stripBuildPlanMutationScopePatch($scopePatch, []);
        foreach ([
            'build_plan_v2',
            'plan_projection',
            'content_manifest',
            'build_workbench',
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
        if ((int)($queueRow['queue_id'] ?? 0) <= 0) {
            return false;
        }

        $status = \strtolower(\trim((string)($queueRow['status'] ?? '')));
        if (\in_array($status, ['pending', 'queued'], true)) {
            return true;
        }

        if (\in_array($status, ['running', 'processing'], true)) {
            $pid = (int)($queueRow['pid'] ?? 0);

            return !$preserveRunningQueueRow && $pid <= 0;
        }

        return false;
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
            $queueId = (int)($row['queue_id'] ?? 0);
            if ($queueId <= 0 || $queueId >= $activeQueueId) {
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
                        'process' => 'Superseded by newer PageBuilder queue #' . $activeQueueId . '.',
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
            'publish' => \GuoLaiRen\PageBuilder\Queue\AiSitePublishQueue::class,
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
            throw new \RuntimeException((string)__('Message'));
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
    private function buildQueuedOperationCheckpointState(
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
            ? \trim((string)($queueInfo['status'] ?? $queueInfo['queue_status'] ?? $queueInfo['snapshot']['status'] ?? ''))
            : (\is_array($queueRow) ? \trim((string)($queueRow['status'] ?? '')) : '');
        $queueRecoveredForRetry = \is_array($queueInfo) && (
            !empty($queueInfo['queue_terminal_recovered'])
            || !empty($queueInfo['retry_allowed'])
            || \in_array(\trim((string)($queueInfo['semantic_status'] ?? '')), ['cancelled', 'canceled', 'stale'], true)
        );
        $operationStatus = \in_array($queueStatus, ['pending', 'queued', 'running'], true)
            ? ($queueStatus === 'running' ? 'running' : 'queued')
            : (\trim((string)($activeOperation['status'] ?? '')) ?: 'queued');
        if ($queueRecoveredForRetry) {
            $operationStatus = 'cancelled';
        }

        $queueProcessLine = \is_array($queueRow) ? \trim((string)($queueRow['process'] ?? '')) : '';
        $queuePid = \is_array($queueRow) ? (int)($queueRow['pid'] ?? 0) : 0;
        $waitingForScheduler = !$queueRecoveredForRetry && (
            $queueStatus === ''
            || \in_array($queueStatus, ['pending', 'queued'], true)
            || (\in_array($queueStatus, ['running', 'processing'], true) && $queuePid <= 0)
        );
        $checkpointMessage = $waitingForScheduler
            ? (string)__('Message')
            : ($queueStatus === 'running'
                ? $queueProcessLine
                : (string)($activeOperation['message'] ?? ''));

        $activeOperation = \array_replace($activeOperation, [
            'operation' => $operation,
            'status' => $operationStatus,
            'queue_id' => \is_array($queueRow) ? (int)($queueRow['queue_id'] ?? 0) : (int)($activeOperation['queue_id'] ?? 0),
            'job_type' => $this->resolveAiSiteQueueJobType($operation),
            'message' => $checkpointMessage,
            'queue_waiting_for_scheduler' => $waitingForScheduler,
            'can_close_stream' => true,
            'continue_other_operations' => !$queueRecoveredForRetry,
        ]);
        if ($queueRecoveredForRetry) {
            $activeOperation['retry_allowed'] = 1;
            $activeOperation['queue_terminal_recovered'] = 1;
            $activeOperation['semantic_status'] = 'cancelled';
        }

        $checkpointWorkspaceStatus = $operation === 'publish'
            ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHING
            : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_BUILDING;
        $state = [
            'public_id' => (string)$session->getPublicId(),
            'stage' => $stageCode,
            'workspace_status' => $checkpointWorkspaceStatus,
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
        return (string)__('Message', [
            'queue_id' => (string)$queueId,
            'operation' => $operation,
        ]);
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
        int $queueId = 0
    ): ?array {
        if ($queueId > 0) {
            $row = $this->findAiSiteQueueRowById($queueId);
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
     * 榛樿 HTML 鍖哄潡杞細涓嶅垱寤鸿櫄鎷熶富棰橈紝浠呭～鍏?scope.virtual_pages_by_type.blocks
     *
     * @return array<string, mixed>
     */
    private function runHtmlBlocksBuildOperationV3(
        SseWriter $sse,
        AiSiteAgentSession $session,
        int $adminId,
        array $scope
    ): array {
        $scope['website_profile'] = $this->profileGenerationService->generate($scope, false);
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS
        );
        $queueForcedAiRebuild = (int)($scope['_queue_force_build']['active'] ?? 0) === 1;
        if ($queueForcedAiRebuild) {
            $scope = $this->buildTaskService->clearBuildArtifactsForRegeneration($scope);
            $scope = $this->buildTaskService->resetBuildTasksToPendingForRebuild($scope, false);
        } else {
            $scope = $this->buildTaskService->reconcileGeneratedArtifactsWithTaskState($scope);
        }
        // 缁敓鎴愬叆鍙ｉ槻寰★細鎶婁笂娆＄‖宕╋紙OOM/kill -9/Worker 杩涚▼姝讳骸锛屾湭璧?PHP catch锛夋畫鐣欑殑
        // status=running 浠诲姟娓呭洖 pending+attempt_no=0锛岄伩鍏嶅弽澶嶉噸鍚妸 attempt_no 绱鍒?
        // BUILD_TASK_MAX_GENERATION_ATTEMPTS 鍚庤姘镐箙鏍?failed銆傚凡 reconcile 杩囩殑浜х墿宸?done锛?
        // 涓嶄細琚繖閲屼簩娆℃壈鍔ㄣ€?
        $scope = $this->buildTaskService->resetRunningTasksForInterruptedBuild(
            $scope,
            'Build entry: clearing stale running tasks before scheduling.'
        );
        $scope = $this->buildTaskService->applyPagesMarkedSkipRemaining($scope);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $pageTypeLabels = Page::getPageTypes();
        // Keep one queue owner, but generate independent page sections in bounded parallel batches.
        $dispatchWindow = $this->resolvePageSectionBuildDispatchWindow();
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
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Generating required image assets'), \min(99, $progressPercent + 1));
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
                    $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);
                }
                if ($sharedTaskByRegion !== []) {
                    $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                    $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Generating shared header/footer'), (int)(($currentStep / $totalSteps) * 100));
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
                            $failure = $this->handleBuildTaskGenerationFailure($sse, $session, $adminId, $scope, $taskKey, 'shared_component', AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS, $throwable, [
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
                        $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
                        $scope['shared_components'][(string)$region] = $component;
                        $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['region' => (string)$region]);
                        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                        $message = (string)$region === 'header' ? 'Shared header generated' : 'Shared footer generated';
                        $this->appendWorkspaceEvent($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'shared_component_generated', $message, ['operation' => 'build', 'details' => ['region' => (string)$region]]);
                        $sse->sendEvent('build_plan_block_completed', $this->enrichTaskEventPayload($scope, [
                            'task_key' => $taskKey,
                            'task_type' => 'shared_component',
                            'message' => $message,
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
                    'workspace_track' => AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS,
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
                $scope = $this->buildTaskService->markTaskRunning($scope, $runningTaskKey);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->emitBuildTaskProgressSnapshotFromScope(
                $sse,
                $scope,
                'build',
                (string)__('AI 姝ｅ湪鐢熸垚椤甸潰浠诲姟'),
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
                $sse->sendEvent('build_plan_block_completed', $this->enrichTaskEventPayload($scope, [
                    'task_key' => (string)$taskKey,
                    'task_type' => 'page_section',
                    'message' => 'HTML section task complete: ' . $pageLabel,
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
                    'failed_task_keys' => $failedTaskKeys,
                ]
            );
        }

        $now = \date('Y-m-d H:i:s');
        $htmlTrackReady = $this->scopeCompatibilityService->htmlTrackHasCompleteBlocks($virtualPages, $pageTypes)
            || $this->htmlTrackHasMaterializedAiBlocks($scope, $virtualPages, $pageTypes);
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0) {
            $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
            $scope['page_type_layouts'] = $pageTypeLayouts;
        }

        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0) {
            $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
            $scope['page_type_layouts'] = $pageTypeLayouts;
        }

        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0) {
            $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
            $scope['page_type_layouts'] = $pageTypeLayouts;
        }

        $scope = $this->buildTaskService->finalizeBuildTaskStatesAfterRunLoop($scope);
        $scope = $this->buildTaskService->syncBuildTaskFailuresToRetryableLedger($scope);
        $completionGate = $this->buildTaskService->inspectBuildCompletionGate($scope);
        $taskSummary = \is_array($completionGate['summary'] ?? null) ? $completionGate['summary'] : [];
        $hasBuildFailures = (int)($taskSummary['failed'] ?? 0) > 0 || $this->buildTaskService->hasRetryableAiFailures($scope, 'build');
        $hasOutstandingTasks = empty($completionGate['passed']);
        if (!$htmlTrackReady && !$hasBuildFailures) {
            throw new \RuntimeException((string)__('Message'));
        }
        $canPublishBuild = !$hasOutstandingTasks && $htmlTrackReady && !$hasBuildFailures;
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'last_generated_at' => $now,
            'active_operation' => 'build',
            'can_publish' => $canPublishBuild,
        ];
        $scope['can_publish'] = $canPublishBuild ? 1 : 0;
        $scope['workspace_status'] = ($hasBuildFailures || $hasOutstandingTasks)
            ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED
            : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            ($hasBuildFailures || $hasOutstandingTasks) ? [
                'status' => 'error',
                'updated_at' => $now,
                'message' => $hasBuildFailures
                    ? (string)__('HTML block build has failed tasks; the scheduler will retry unfinished work.')
                    : (string)__('Message'),
                'retry_allowed' => 0,
                'failure_mode' => 'build_failed',
                'retryable_ai_failure_count' => (int)($scope['retryable_ai_failure_count'] ?? 0),
                'queue_waiting_for_scheduler' => false,
            ] : [
                'status' => 'done',
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
                    ? __('HTML block build has failed tasks; the scheduler will retry unfinished work.')
                    : __('Message'))
                : __('HTML blocks ready for preview or publish'),
            100,
            '',
            ($hasBuildFailures || $hasOutstandingTasks) ? 'error' : 'done',
            (string)($scope['workspace_status'] ?? '')
        );

        return [
            'message' => $hasBuildFailures
                ? (string)__('HTML block build has failed tasks; the scheduler will retry unfinished work.')
                : ($hasOutstandingTasks
                    ? (string)__('Message')
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
        $dropLegacyPageBlocks = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? '')) === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
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
            if ($dropLegacyPageBlocks && !$this->isGeneratedHtmlContentBlock($blockId, $block)) {
                continue;
            }
            if ($dropLegacyPageBlocks
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

        $pageTypeLayouts = \is_array($scope['page_type_layouts'] ?? null) ? $scope['page_type_layouts'] : [];
        $layout = \is_array($pageTypeLayouts[$pageType] ?? null) ? $pageTypeLayouts[$pageType] : [];
        foreach (\is_array($layout['content'] ?? null) ? $layout['content'] : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $blockId = $this->normalizeGeneratedComponentCodeToBlockId((string)($item['code'] ?? $item['component'] ?? ''));
            if ($blockId !== '') {
                $allowed[$blockId] = true;
            }
        }

        foreach ($this->buildTaskService->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $definition = $this->buildTaskService->getTaskDefinition($scope, $taskKey);
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
            $virtualPages[$pageType]['blocks'] ?? null,
            $scope['virtual_pages_by_type'][$pageType]['blocks'] ?? null,
        ] as $candidate) {
            if (\is_array($candidate) && $candidate !== []) {
                return \array_values(\array_filter($candidate, static fn(mixed $block): bool => \is_array($block)));
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
        $scope['page_type_layouts'] = $pageTypeLayouts;
        $scope['virtual_pages_by_type'] = $virtualPages;
        $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? ''));
        $scope = $this->dropPrePublishMaterializedPagesFromVirtualThemeScope($scope, $session);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, $websiteId);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, (int)($scope['virtual_theme_id'] ?? 0));

        return $scope;
    }

    /**
     * BuildPlan v2 writes generated content into the virtual theme first. Entity
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

        $scope['pagebuilder_pages_by_type'] = [];
        $scope['materialized_pages_by_type'] = [];
        $scope['preview_page_id'] = 0;
        if (\is_array($scope['virtual_pages_by_type'] ?? null)) {
            foreach ($scope['virtual_pages_by_type'] as $pageType => $pageData) {
                if (!\is_array($pageData)) {
                    continue;
                }
                unset($pageData['page_id'], $pageData['materialized_page_id']);
                $scope['virtual_pages_by_type'][$pageType] = $pageData;
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
    private function sortVirtualThemeLayoutContentByBuildTasks(array $layout, array $scope, string $pageType): array
    {
        $content = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
        if ($content === []) {
            return $layout;
        }

        $taskOrder = [];
        $index = 0;
        foreach ($this->buildTaskService->listTaskKeysByPageType($scope, $pageType) as $taskKey) {
            $definition = $this->buildTaskService->getTaskDefinition($scope, (string)$taskKey);
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
                    'message' => 'Build task missing build-plan section spec: ' . $taskKey,
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

        return (int)($visualContract['required'] ?? 0) === 1
            || (int)($visualContract['needs_image'] ?? 0) === 1;
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
                'max_image_generation_attempts' => 1,
                'image_timeout' => 20,
                'image_generation_timeout' => 20,
                'timeout' => 20,
            ];
            $latestAssetScope = $this->refreshInlineImageStateFromPersistedScope($session, $adminId, $assetScope);
            $result = $assetService->generateSlotAsset($session, $adminId, $latestAssetScope, $slotId, $slotSeed);
            $resultScope = \is_array($result['scope'] ?? null) ? $result['scope'] : [];
            if ($resultScope !== []) {
                $this->persistInlineImageScopePatch($session, $adminId, $resultScope);
            }
            if (\trim((string)($result['final_url'] ?? '')) === '' && \is_array($result['failed_slot'] ?? null)) {
                throw new \RuntimeException('Inline block image generation failed for ' . $slotId . ': ' . (string)($result['failed_slot']['message'] ?? 'unknown error'));
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
            'image_generation_requires_build_ready',
            'image_generation_build_ready_check_skip',
            'auto_generate_identity_assets_first',
            'auto_asset_prebuild_identity_only',
            'stage1_contract',
            'plan_generated_source_signature',
            'theme_context_snapshot',
            'plan_workbench',
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
        foreach (['asset_manifest', 'asset_block_cache', 'verified_assets', 'asset_image_generation_failures', 'asset_image_generation_deferred'] as $key) {
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
     * task's generated final_url with its older pending manifest snapshot.'
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

        $message = $this->summarizeBuildTaskThrowableForOperator($throwable);
        if ($message === '') {
            $message = $throwable::class;
        }
        $publicMessage = $this->summarizeBuildTaskThrowableForProduct($throwable);
        $errorCode = 0;
        $errorClass = 'generation_failed';
        $reason = $publicMessage;
        $attemptNo = $this->buildTaskService->getTaskAttemptNo($scope, $taskKey);
        $maxAttempts = self::BUILD_TASK_MAX_GENERATION_ATTEMPTS;
        $progressPercent = \max(0, \min(99, (int)($context['progress_percent'] ?? 0)));
        $basePayload = \array_replace($context, [
            'parallel' => true,
            'workspace_track' => $workspaceTrack,
            'task_key' => $taskKey,
            'task_type' => $taskType,
            'attempt_no' => $attemptNo,
            'max_attempts' => $maxAttempts,
            'error_message' => $publicMessage,
            'error_code' => $errorCode,
            'error_class' => $errorClass,
            'failure_reason' => $reason,
        ]);

        if ($attemptNo < $maxAttempts) {
            $scope = $this->buildTaskService->markTaskPendingForRetry($scope, $taskKey, $message);
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $retryPayload = \array_replace($basePayload, [
                'error_message' => 'Generation is retrying in the current queue.',
                'failure_reason' => 'Generation is retrying in the current queue.',
            ]);
            $this->emitBuildInfoEvent(
                $sse,
                (string)__('鏋勫缓浠诲姟鏈疆澶辫触锛屽綋鍓嶉槦鍒楀皢绔嬪嵆閲嶈瘯锛?{task} 鈥?%{reason}', [
                    'task' => $taskKey,
                    'reason' => 'retrying in the current queue',
                ]),
                \array_replace($retryPayload, [
                    'event_type' => 'build_task_retry_scheduled',
                    'batch_state' => 'retrying',
                    'retry_in_current_queue' => true,
                ])
            );
            $this->emitBuildTaskProgressSnapshotFromScope(
                $sse,
                $scope,
                'build',
                (string)__('鏋勫缓浠诲姟灏嗙珛鍗抽噸璇曪細%{task}', ['task' => $taskKey]),
                $progressPercent,
                'running'
            );

            return [
                'scope' => $scope,
                'fatal' => false,
                'throwable' => $throwable,
            ];
        }

        $scope = $this->buildTaskService->markTaskFailed($scope, $taskKey, $message);
        $scope = $this->buildTaskService->syncBuildTaskFailuresToRetryableLedger($scope);
        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->emitBuildInfoEvent(
            $sse,
            (string)__('Build task failed and was recorded for scheduler retry: %{task} 鈥?%{reason}', [
                'task' => $taskKey,
                'reason' => $reason,
            ]),
            \array_replace($basePayload, [
                'event_type' => 'build_plan_block_failed',
                'batch_state' => 'failed',
            ])
        );
        $this->emitBuildTaskProgressSnapshotFromScope(
            $sse,
            $scope,
            'build',
            (string)__('Build task failed: %{task} 鈥?%{reason}', ['task' => $taskKey, 'reason' => $reason]),
            $progressPercent,
            'failed'
        );
        $sse->sendEvent('build_plan_block_failed', $this->enrichTaskEventPayload($scope, \array_replace($basePayload, [
            'message' => 'Build task failed: ' . $taskKey . ' 鈥?' . $reason,
        ])));

        return [
            'scope' => $scope,
            'fatal' => false,
            'throwable' => $throwable,
        ];
    }

    private function summarizeBuildTaskThrowableForOperator(\Throwable $throwable): string
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

    private function summarizeBuildTaskThrowableForProduct(\Throwable $throwable): string
    {
        $message = $this->summarizeBuildTaskThrowableForOperator($throwable);
        $message = \trim((string)(\preg_replace('/\s+/u', ' ', $message) ?? $message));
        if ($message === '') {
            return 'AI generation failed. The section will need another generation attempt.';
        }

        $lower = \mb_strtolower($message, 'UTF-8');
        if (\str_contains($lower, 'required_image_asset_unresolved')
            || \str_contains($lower, 'inline block image generation failed')
            || \str_contains($lower, 'image generation failed')
            || \str_contains($lower, 'vectorengine')
            || \str_contains($lower, 'generatecontent')
            || \str_contains($lower, 'chat pre-consumed quota')
            || \str_contains($lower, 'user quota')
            || \str_contains($lower, 'need quota')
        ) {
            return 'Image generation is temporarily unavailable. The section will need another generation attempt.';
        }

        if (\str_contains($lower, 'openssl')
            || \str_contains($lower, 'ssl_read')
            || \str_contains($lower, 'curl')
            || \str_contains($lower, 'operation timed out')
            || \str_contains($lower, 'operation too slow')
            || \str_contains($lower, 'timed out after')
        ) {
            return 'AI generation timed out. The section will need another generation attempt.';
        }

        if (\str_contains($lower, 'contract findings')
            || \str_contains($lower, 'hard policy')
            || \str_contains($lower, 'quality gate failed')
            || \str_contains($lower, 'quality gate did not')
            || \str_contains($lower, 'component contract')
        ) {
            return 'AI output did not pass the section quality gate. The section will need another generation attempt.';
        }

        if ((\preg_match('/https?:\\/\\//i', $message) === 1)
            || (\preg_match('/\\brequest\\s*id\\b/i', $message) === 1)
            || (\preg_match('/\\bHTTP\\s*:?\\s*\\d{3}\\b/i', $message) === 1)
            || (\preg_match('/\\b[A-Za-z_]+Exception\\b/', $message) === 1)
        ) {
            return 'AI generation failed. The section will need another generation attempt.';
        }

        return \mb_substr($message, 0, 320, 'UTF-8');
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
        // 闃熷垪/CLI 涓嬮鏉″彲瑙佽繘搴︼細鍚庣画铏氭嫙涓婚璺緞浼氬厛璺?profile 鐢熸垚锛屽彲鑳借€楁椂杈冮暱锛岄伩鍏嶈棰嗗悗闀挎椂闂存棤 SSE銆?
        $this->sendOperationProgress(
            $sse,
            $session,
            $adminId,
            AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'build',
            (string)__('姝ｅ湪鎵ц锛岃绋嶅€?..'),
            1
        );
        $scope = $this->scopeCompatibilityService->normalizeScope(
            $this->sessionService->loadScopeForStage(
                $session,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                self::BUILD_OPERATION_ARTIFACT_KEYS
            )
        );
        return $this->runVirtualThemeBuildOperationV3($sse, $session, $adminId, $scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{scope:array<string,mixed>,page_types:list<string>,bad_matches:array<string,list<string>>}
     */
    private function invalidateQualityFailedVirtualThemeBuildTasks(array $scope): array
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
            $taskKeys = $this->buildTaskService->listTaskKeysByPageType($scope, $pageType);
            if ($taskKeys === []) {
                continue;
            }
            $badMatchesByPage[$pageType] = $blockingBadMatches;
            $invalidatedPageTypes[] = $pageType;
            $message = 'Quality gate retry: ' . \implode(', ', $blockingBadMatches);
            $scope = $this->clearQualityFailedVirtualThemePageArtifacts($scope, $pageType);
            foreach ($taskKeys as $taskKey) {
                $scope = $this->buildTaskService->markTaskPendingForFreshRepair($scope, $taskKey, $message);
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
            '/\b(?:AI_GENERATED_SECTION|task_key|section_code|block_key|page_type|field_content_requirements|build_plan|implementation_detail|realtime_content|content_plan|image_slot|slot_id)\b/iu',
            '/\bcontent\/[a-z0-9_-]+\/[a-z0-9_-]+\b/iu',
            '/\b(?:AI content placeholder|ai-empty|placeholder content|placeholder|lorem ipsum|dummy copy|sample text|todo copy)\b/iu',
            '/Default Page Template|This is the default page|娆㈣繋璁块棶|榛樿椤甸潰妯℃澘/iu',
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
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $layout = $this->scopeCompatibilityService->normalizeLayoutConfig($pageTypeLayouts[$pageType] ?? [], $pageType);
        $layout['content'] = [];
        $pageTypeLayouts[$pageType] = $layout;
        $scope['page_type_layouts'] = $pageTypeLayouts;

        if (\is_array($scope['virtual_pages_by_type'][$pageType] ?? null)) {
            $scope['virtual_pages_by_type'][$pageType]['blocks'] = [];
            $scope['virtual_pages_by_type'][$pageType]['last_generated_at'] = '';
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
        $scope = $this->buildTaskService->ensureTaskScope(
            $scope,
            \is_array($scope['website_profile']) ? $scope['website_profile'] : [],
            AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME
        );
        $queueForcedAiRebuild = (int)($scope['_queue_force_build']['active'] ?? 0) === 1;
        if ($queueForcedAiRebuild) {
            $scope = $this->buildTaskService->clearBuildArtifactsForRegeneration($scope);
            $scope = $this->buildTaskService->resetBuildTasksToPendingForRebuild($scope, false);
        } else {
            $scope = $this->buildTaskService->reconcileGeneratedArtifactsWithTaskState($scope);
        }
        $qualityInvalidation = [
            'scope' => $scope,
            'page_types' => [],
            'bad_matches' => [],
        ];
        $buildSummaryBeforeQuality = $this->buildTaskService->summarize($scope);
        if (
            (int)($buildSummaryBeforeQuality['pending'] ?? 0) <= 0
            && (int)($buildSummaryBeforeQuality['running'] ?? 0) <= 0
            && (int)($buildSummaryBeforeQuality['failed'] ?? 0) <= 0
        ) {
            $qualityInvalidation = $this->invalidateQualityFailedVirtualThemeBuildTasks($scope);
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
        // queue:run -f锛歘queue_force_build.active=1 鏃跺己鍒惰蛋 AI锛屽苟缁曡繃鍏变韩 Header/Footer 鐨勮繘绋嬪唴闈欐€佺紦瀛樸€?
        $pageTypeLabels = Page::getPageTypes();
        // Keep one queue owner, but generate independent virtual-theme sections in bounded parallel batches.
        $dispatchWindow = $this->resolvePageSectionBuildDispatchWindow();
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
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Generating required image assets'), \min(99, $progressPercent + 1));
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
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope, false);
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
                    $scope = $this->buildTaskService->markTaskRunning($scope, $taskKey);
                }
                if ($sharedTaskByRegion !== []) {
                    $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
                    $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('Generating shared header/footer'), (int)(($currentStep / $totalSteps) * 100));
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
                            $failure = $this->handleBuildTaskGenerationFailure($sse, $session, $adminId, $scope, $taskKey, 'shared_component', AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME, $throwable, [
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
                        $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
                        $scope['shared_components'][(string)$region] = $component;
                        $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['region' => (string)$region]);
                        $virtualThemeId = (int)$scope['virtual_theme_id'];
                        $this->virtualThemeService->saveGeneratedSharedComponent($virtualThemeId, $component);
                        $pageTypeLayouts = $this->applySharedComponentToPageLayouts($pageTypes, $pageTypeLayouts, $component);
                        $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
                        $scope['page_type_layouts'] = $pageTypeLayouts;
                        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                        $message = (string)$region === 'header' ? 'Shared header generated' : 'Shared footer generated';
                        $this->appendWorkspaceEvent($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'shared_component_generated', $message, ['operation' => 'build', 'details' => ['region' => (string)$region]]);
                        $sse->sendEvent('build_plan_block_completed', $this->enrichTaskEventPayload($scope, [
                            'task_key' => $taskKey,
                            'task_type' => 'shared_component',
                            'message' => $message,
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
                $scope = $this->buildTaskService->markTaskRunning($scope, $runningTaskKey);
            }
            $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
            $this->emitBuildTaskProgressSnapshotFromScope(
                $sse,
                $scope,
                'build',
                (string)__('AI 姝ｅ湪鐢熸垚椤甸潰浠诲姟'),
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
                        $failure = $this->handleBuildTaskGenerationFailure(
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
                    $layout = $this->sortVirtualThemeLayoutContentByBuildTasks($layout, $scope, $pageType);
                    $virtualLayoutId = $this->virtualThemeService->saveGeneratedPageLayout((int)$scope['virtual_theme_id'], $pageType, $layout);
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
                            $sectionBlock,
                            $scope['website_profile'],
                            $scope
                        );
                        $virtualPages[$pageType] = $currentVirtualPages[$pageType];
                    }

                    $scope['page_type_layouts'] = $pageTypeLayouts;
                    $scope['virtual_pages_by_type'] = $virtualPages;
                    $scope['preview_page_type'] = $this->scopeCompatibilityService->resolvePreviewPageType($virtualPages, (string)($scope['preview_page_type'] ?? $pageType));
                    $scope = $this->buildTaskService->markTaskDone($scope, (string)$taskKey, ['page_type' => $pageType, 'section_code' => $sectionCode]);
                    $scope = $this->dropPrePublishMaterializedPagesFromVirtualThemeScope($scope, $session);
                    $scope = $this->refreshInlineImageStateFromPersistedScope($session, $adminId, $scope);
                    $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                    $pageId = 0;
                    if (!$environmentReadyEmitted && (int)($scope['virtual_theme_id'] ?? 0) > 0) {
                        $environmentReadyEmitted = true;
                        $this->emitBuildEnvironmentReady($sse, $session, $adminId, $pageType, $pageLabel, 0, (int)$scope['virtual_theme_id'], $virtualLayoutId);
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
                                'virtual_layout_id' => $virtualLayoutId,
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
                        'virtual_layout_id' => $virtualLayoutId,
                        'virtual_page_key' => (int)$scope['virtual_theme_id'] . ':' . $pageType,
                        'progress_percent' => $progressPercent,
                        'section_code' => $sectionCode,
                        'page_completed' => $pageCompleted,
                        'message' => $pageGeneratedMessage,
                        'state' => $state,
                    ]));
                    $sse->sendEvent('build_plan_block_completed', $this->enrichTaskEventPayload($scope, [
                        'task_key' => (string)$taskKey,
                        'task_type' => 'page_section',
                        'message' => 'Theme section task complete: ' . $pageLabel,
                        'state' => $state,
                    ]));
                    $completedTaskKeys[] = (string)$taskKey;
                } catch (\Throwable $throwable) {
                    $failure = $this->handleBuildTaskGenerationFailure(
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
        }

        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $virtualThemeId = (int)($scope['virtual_theme_id'] ?? 0);
        if ($virtualThemeId > 0) {
            $this->saveGeneratedPageLayoutsForTypes($virtualThemeId, $pageTypes, $pageTypeLayouts);
            $scope['page_type_layouts'] = $pageTypeLayouts;
        }

        $scope = $this->buildTaskService->finalizeBuildTaskStatesAfterRunLoop($scope);
        $scope = $this->buildTaskService->syncBuildTaskFailuresToRetryableLedger($scope);
        $scope = $this->refreshInlineImageStateFromPersistedScope($session, $adminId, $scope);

        $now = \date('Y-m-d H:i:s');
        $buildRegeneration = \is_array($scope['_build_regeneration'] ?? null) ? $scope['_build_regeneration'] : [];
        if ((int)($buildRegeneration['active'] ?? 0) === 1) {
            $scope['_build_regeneration'] = \array_replace($buildRegeneration, [
                'active' => 0,
                'finished_at' => $now,
            ]);
        }
        $completionGate = $this->buildTaskService->inspectBuildCompletionGate($scope);
        $taskSummary = \is_array($completionGate['summary'] ?? null) ? $completionGate['summary'] : [];
        $hasBuildFailures = (int)($taskSummary['failed'] ?? 0) > 0 || $this->buildTaskService->hasRetryableAiFailures($scope, 'build');
        $hasOutstandingTasks = empty($completionGate['passed']);
        $canPublishBuild = !$hasOutstandingTasks && !$hasBuildFailures;
        $scope['build_summary'] = [
            'page_count' => \count($virtualPages),
            'last_generated_at' => $now,
            'active_operation' => 'build',
            'can_publish' => $canPublishBuild,
        ];
        $scope['can_publish'] = $canPublishBuild ? 1 : 0;
        $scope['workspace_status'] = ($hasBuildFailures || $hasOutstandingTasks)
            ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED
            : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH;
        $scope['active_operation'] = \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            ($hasBuildFailures || $hasOutstandingTasks) ? [
                'status' => 'error',
                'updated_at' => $now,
                'message' => $hasBuildFailures
                    ? (string)__('Virtual theme build has failed tasks; the scheduler will retry unfinished work.')
                    : (string)__('Message'),
                'retry_allowed' => 0,
                'failure_mode' => 'build_failed',
                'retryable_ai_failure_count' => (int)($scope['retryable_ai_failure_count'] ?? 0),
                'queue_waiting_for_scheduler' => false,
            ] : [
                'status' => 'done',
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
            $this->sessionService->loadScopeForStage(
                $freshForCompletionGate,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                self::BUILD_OPERATION_ARTIFACT_KEYS
            )
        );
        $freshCompletionGate = $this->buildTaskService->inspectBuildCompletionGate($freshScopeForCompletionGate);
        if (!empty($freshCompletionGate['passed'])) {
            $scope = $freshScopeForCompletionGate;
            $taskSummary = \is_array($freshCompletionGate['summary'] ?? null) ? $freshCompletionGate['summary'] : $taskSummary;
            $hasOutstandingTasks = false;
            $hasBuildFailures = $this->buildTaskService->hasRetryableAiFailures($scope, 'build');
            $canPublishBuild = !$hasBuildFailures;
            $scope['can_publish'] = $canPublishBuild ? 1 : 0;
            $scope['workspace_status'] = $canPublishBuild
                ? AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
                : AiSiteScopeCompatibilityService::WORKSPACE_STATUS_FAILED;
            if ($canPublishBuild) {
                $scope['active_operation'] = \array_replace(
                    \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
                    [
                        'status' => 'done',
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
                    ? __('Virtual theme build has failed tasks; the scheduler will retry unfinished work.')
                    : __('Message'))
                : __('Virtual theme ready for editing'),
            100,
            '',
            ($hasBuildFailures || $hasOutstandingTasks) ? 'error' : 'done',
            (string)($scope['workspace_status'] ?? '')
        );
        return [
            'message' => $hasBuildFailures
                ? (string)__('Virtual theme build has failed tasks; the scheduler will retry unfinished work.')
                : ($hasOutstandingTasks
                    ? (string)__('Message')
                    : (string)__('Virtual theme build complete')),
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
            regenerateAiGeneratedVirtualThemePage: fn (array $scope, array $websiteProfile, array $pageTypes, array $pageTypeLayouts, string $pageType, int $sessionId): array => $this->virtualThemeService->regenerateAiGeneratedVirtualThemePage($scope, $websiteProfile, $pageTypes, $pageTypeLayouts, $pageType, $sessionId),
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
        $publishBlock = $this->resolveLatestPublishBlockingAiBuildFailure(
            $scope,
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            null
        );
        if (!empty($publishBlock['blocked'])) {
            throw new \RuntimeException($this->formatPublishBlockedByAiFailureMessage($publishBlock));
        }
        $pageTypes = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $pageTypeLayouts = $this->scopeCompatibilityService->normalizePageTypeLayouts($scope['page_type_layouts'] ?? [], $pageTypes);
        $websiteProfile = $this->profileGenerationService->generate($scope, false);

        $websiteId = \max((int)($scope['draft_website_id'] ?? 0), (int)($scope['website_id'] ?? 0), (int)$session->getWebsiteId());
        $virtualThemeId = \max((int)($scope['virtual_theme_id'] ?? 0), (int)$session->getVirtualThemeId());
        $workspaceTrack = AiSiteScopeCompatibilityService::WORKSPACE_TRACK_VIRTUAL_THEME;
        if ($websiteId <= 0 || $virtualThemeId <= 0) {
            throw new \RuntimeException((string)__('Message'));
        }
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', __('姝ｅ湪鏍￠獙鏂规鍧椾笌鐢熸垚鍖哄潡鏁伴噺'), 10);
        $buildPlanReadiness = $this->buildBuildPlanPublishReadinessReport($scope);
        $scope['build_plan_publish_readiness'] = $buildPlanReadiness;
        if (empty($buildPlanReadiness['passed'])) {
            throw new \RuntimeException((string)__('Message') . ' ' . $this->formatBuildPlanPublishReadinessDetail($buildPlanReadiness));
        }
        $scope = $this->refreshScopeQualityContractsForPublishGate($scope);
        $qualityGate = ObjectManager::getInstance(AiSiteQualityGateService::class);
        $qualityReport = $this->normalizePublishQualityReport($qualityGate->inspectScope($scope));
        if (empty($qualityReport['passed'])) {
            throw new \RuntimeException((string)__('Message'));
        }

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', __('Message'));
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
        $scope['publish_quality_gate'] = $qualityReport;
        $scope['publish_verification'] = \is_array($published['publish_verification'] ?? null)
            ? $published['publish_verification']
            : [];
        $scope['preview_page_id'] = (int)($published['preview_page_id'] ?? 0);
        $scope['preview_page_type'] = (string)($published['preview_page_type'] ?? ($scope['preview_page_type'] ?? ''));
        $scope['workspace_status'] = AiSiteScopeCompatibilityService::WORKSPACE_STATUS_PUBLISHED;
        $scope = $this->writeActiveOperationStateToScope($scope, \array_replace(
            \is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [],
            [
                'operation' => 'publish',
                'status' => 'done',
                'updated_at' => \date('Y-m-d H:i:s'),
                'message' => (string)__('鍙戝竷瀹屾垚'),
            ]
        ));

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_PUBLISHED);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_PUBLISH);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_PUBLISH, 'publish', __('姝ｅ紡椤甸潰宸插垱寤哄苟涓婄嚎'), 100);
        return ['message' => (string)__('鍙戝竷瀹屾垚'), 'published' => $published];
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
                && $this->isBuildTaskSummaryTerminal($scope)
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
        if (\in_array($operation, ['build', 'regenerate_page', 'block_regenerate'], true)
            && (string)($payload['progress_kind'] ?? '') === 'build_plan_progress'
        ) {
            $this->emitTaskProgressSnapshotEvent($sse, $payload);
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
        $definition = $this->buildTaskService->getTaskDefinition($scope, $taskKey);
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
    private function isBuildTaskSummaryTerminal(array $scope): bool
    {
        $completionGate = $this->buildTaskService->inspectBuildCompletionGate($scope);

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
     * 褰撳墠杩炴帴鎸佹湁鐨?lease 鏄惁鍥犺繃鏈熻€屽け鏁堬紙鎺掗櫎銆宻cope 宸茶鍏跺畠鏍囩椤垫敼鍐?token銆嶇殑鎯呭喌锛夈€?
     */
    private function assertActiveStreamLeaseAlive(AiSiteAgentSession $session, int $adminId, string $leaseToken = ''): void
    {
        // 鏍囧噯 SSE 妯″紡涓嬩笉鍋?lease 鏍￠獙锛岃繛鎺ョ敓鍛藉懆鏈熺敱娴忚鍣?TCP 鑷劧绠＄悊銆?
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
            return $this->jsonError('INVALID_PARAMS', (string)__('鍙傛暟鏃犳晥'), self::PARAMS_PUBLIC_ID);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->jsonError('SESSION_NOT_FOUND', (string)__('浼氳瘽涓嶅瓨鍦ㄦ垨鏃犳潈璁块棶'), self::PARAMS_PUBLIC_ID);
        }
        $jsonKey = $merge ? 'scope_patch' : 'scope';
        $isAutosave = \in_array(\strtolower(\trim((string)$this->getRequestBodyValue('autosave', '0'))), ['1', 'true', 'yes', 'on'], true);
        $error = '';
        $payload = $this->getRequestJsonObject($jsonKey, $error);
        if ($error !== '') {
            return $this->fetchJson(['success' => false, 'message' => $error]);
        }
        $saveTarget = \strtolower(\trim((string)$this->getRequestBodyValue('save_target', '')));
        if ($merge && $isAutosave && $payload === [] && ($saveTarget === 'plan' || ($saveTarget === '' && $this->isTruthyRequestFlag('save_plan_draft')))) {
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
                (string)__('Message'),
                ['details' => ['autosave' => 1, 'source' => 'save_button']]
            );

            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('Message'),
                'autosave' => true,
            ]);
        }
        if (isset($payload['page_types']) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $payload)) {
            $payload[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        $payload = $this->dropEmptyProfileIdentityPatchValues($payload);
        $siteProfileManual = \is_array($payload['site_profile_manual'] ?? null) ? $payload['site_profile_manual'] : [];
        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale', 'plan_locale'] as $manualField) {
            if (
                \array_key_exists($manualField, $payload)
                && $this->isMeaningfulProfileManualValue($payload[$manualField])
                && !\array_key_exists($manualField, $siteProfileManual)
            ) {
                $siteProfileManual[$manualField] = true;
            }
        }
        if ($siteProfileManual !== []) {
            $payload['site_profile_manual'] = $siteProfileManual;
        }
        $saved = $merge ? $this->sessionService->mergeScope($session->getId(), $adminId, $payload) : $this->sessionService->replaceScope($session->getId(), $adminId, $payload);
        if (!$saved) {
            return $this->fetchJson(['success' => false, 'message' => __('淇濆瓨澶辫触')]);
        }
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            $this->scopeCompatibilityService->normalizeStage($session->getStage()),
            $isAutosave ? 'autosave_saved' : ($merge ? 'scope_merged' : 'scope_replaced'),
            $isAutosave
                ? (string)__('宸ヤ綔鍖哄凡鑷姩淇濆瓨')
                : ($merge ? (string)__('宸ヤ綔鍖轰俊鎭凡鍚堝苟淇濆瓨') : (string)__('宸ヤ綔鍖轰俊鎭凡鏁翠綋鏇挎崲')),
            ['details' => ['keys' => \array_values(\array_map('strval', \array_keys($payload))), 'autosave' => $isAutosave ? 1 : 0]]
        );
        if ($isAutosave) {
            return $this->fetchJson([
                'success' => true,
                'message' => (string)__('宸ヤ綔鍖哄凡鑷姩淇濆瓨'),
                'autosave' => true,
            ]);
        }
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

        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, [
            'website_profile' => \is_array($websiteProfile) ? $websiteProfile : [],
            'plan_json' => \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
            'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
            'plan_structured' => \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
            'plan_workbench' => \is_array($artifacts['plan_workbench'] ?? null) ? $artifacts['plan_workbench'] : [],
            'plan_last_prompt_mode' => 'refine_page',
            'plan_last_target_scope' => $targetScope !== '' ? $targetScope : ('pages.' . $pageType),
            'plan_last_round' => $round,
            'plan_confirmed' => (int)($scope['plan_confirmed'] ?? 0),
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

        $saved = $this->sessionService->mergeScope($session->getId(), $adminId, [
            'plan_json' => \is_array($artifacts['plan_json'] ?? null) ? $artifacts['plan_json'] : [],
            'plan_markdown' => (string)($artifacts['markdown'] ?? ''),
            'plan_structured' => \is_array($artifacts['structured'] ?? null) ? $artifacts['structured'] : [],
            'plan_workbench' => \is_array($artifacts['plan_workbench'] ?? null) ? $artifacts['plan_workbench'] : [],
            'plan_confirmed' => 0,
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
            $targetScope = 'pages.' . $pageType . '.blocks.' . ($blockKey !== '' ? $blockKey : 'new');
        }
        $targetScopes = $this->normalizeStringList($this->getRequestBodyValue('target_scopes', []));
        if ($targetScope !== '' && !\in_array($targetScope, $targetScopes, true)) {
            \array_unshift($targetScopes, $targetScope);
        }
        foreach ($blockKeys as $candidateBlockKey) {
            $candidateTargetScope = 'pages.' . $pageType . '.blocks.' . $candidateBlockKey;
            if (!\in_array($candidateTargetScope, $targetScopes, true)) {
                $targetScopes[] = $candidateTargetScope;
            }
        }

        // 闃舵涓€鍧楃骇 mutate 瀹炴椂鎵ц锛氬簳灞?mutateDraftPlanBlock 浠呭仛鏈湴缁撴瀯璁＄畻/閲嶆帓锛?
        // 涓嶈皟鐢?AI锛涗负浜嗛伩鍏?绛夐槦鍒楄皟搴?鐨勪綋鎰熸垚鏈紝缁熶竴鍦ㄥ綋鍓嶈姹傚唴鍚屾钀藉簱杩斿洖銆?
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
                $resultArtifacts = $this->executionBlueprintService->mutateDraftPlanBlock(
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

        $structured = \is_array($resultArtifacts['structured'] ?? null) ? $resultArtifacts['structured'] : [];
        $planJson = \is_array($resultArtifacts['plan_json'] ?? null) ? $resultArtifacts['plan_json'] : [];
        $planMarkdown = (string)($resultArtifacts['markdown'] ?? '');
        $planWorkbench = \is_array($resultArtifacts['plan_workbench'] ?? null) ? $resultArtifacts['plan_workbench'] : [];
        $combinedSummary = $mutationSummaries === []
            ? (\is_array($resultArtifacts['mutation_summary'] ?? null) ? $resultArtifacts['mutation_summary'] : [])
            : $mutationSummaries[\count($mutationSummaries) - 1];

        $scopePatch = [
            'plan_json' => $planJson,
            'plan_markdown' => $planMarkdown,
            'plan_structured' => $structured,
            'plan_workbench' => $planWorkbench,
            'plan_confirmed' => 0,
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
            'create' => (string)__('Message'),
            'delete' => (string)__('Message'),
            'rebuild' => (string)__('Message'),
            default => (string)__('Message'),
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
        if (\is_array($artifacts['structured'] ?? null)) {
            $next['plan_structured'] = $artifacts['structured'];
        }
        if (\is_array($artifacts['plan_json'] ?? null)) {
            $next['plan_json'] = $artifacts['plan_json'];
        }
        if (\array_key_exists('markdown', $artifacts)) {
            $next['plan_markdown'] = (string)$artifacts['markdown'];
        }
        if (\is_array($artifacts['plan_workbench'] ?? null)) {
            $next['plan_workbench'] = $artifacts['plan_workbench'];
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
        return \in_array($operation, ['plan', 'build', 'block_regenerate', 'block_partial_patch', 'regenerate_page', 'image_asset', 'publish'], true);
    }

    private function shouldKeepQueuedObserverStreamOpen(string $operation): bool
    {
        return false;
    }

    /**
     * Planning operations are intentionally long-running and should not be auto-reclaimed
     * just because their active_operation timestamp is old.
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
        return 3;
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
            $error = (string)__('JSON 鏃犳晥锛?{1}', [$jsonException->getMessage()]);
            return [];
        }
        if (!\is_array($decoded)) {
            $error = (string)__('璇锋眰浣撳繀椤绘槸 JSON 瀵硅薄');
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
                : ($accountName !== '' ? $accountName : (string)__('Message'));
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
                'label' => (string)__('鏈湴渚涘簲鍟?- 鏈湴榛樿璐﹀彿'),
                'registrar_name' => (string)__('Message'),
                'registrar_code' => 'local_demo',
                'account_name' => (string)__('鏈湴榛樿璐﹀彿'),
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
     * 钖勫３杞彂锛氬畾浣?鍒涘缓 Websites 渚ч暅鍍忎細璇濄€?
     * 瀹為檯瀹炵幇杩佺Щ鍒?{@see AiSiteAgentWebsitesMirrorService::ensureMirrorSession}锛圧4.3锛夈€?
     */
    private function ensureLinkedWebsitesMirrorSession(AiSiteAgentSession $session, int $adminId): ?WebsitesAiSiteBuilderSession
    {
        return $this->websitesMirrorService->ensureMirrorSession($session, $adminId);
    }

    /**
     * 钖勫３杞彂锛氭妸 PageBuilder scope 褰掍竴鍖栨垚 Websites 鍙啓 scope銆?
     * 瀹為檯瀹炵幇杩佺Щ鍒?{@see AiSiteAgentWebsitesMirrorService::buildScopeFromSource}锛圧4.3锛夈€?
     *
     * @return array<string, mixed>
     */
    private function buildLinkedWebsitesScopeFromPageBuilderSession(AiSiteAgentSession $session): array
    {
        return $this->websitesMirrorService->buildScopeFromSource($session);
    }

    /**
     * 钖勫３杞彂锛氭妸 Websites 渚?scope 鍙樻洿 merge 鍥?PageBuilder 浼氳瘽銆?
     * 瀹為檯瀹炵幇杩佺Щ鍒?{@see AiSiteAgentWebsitesMirrorService::syncScopeBack}锛圧4.3锛夈€?
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
        $actualPlanPageTypes = $hasPlanDraft ? $this->collectActualStageOnePlanPageTypesFromScope($scope) : [];
        if ($actualPlanPageTypes !== []) {
            $lastGeneratedPageTypes = $actualPlanPageTypes;
        }
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
     * @return array{expected:list<string>,actual:list<string>,missing:list<string>}
     */
    private function inspectStageOnePlanPageTypeCoverage(array $scope): array
    {
        $expected = $this->scopeCompatibilityService->resolveScopedPageTypes($scope);
        $actual = $this->collectActualStageOnePlanPageTypesFromScope($scope);

        return [
            'expected' => $expected,
            'actual' => $actual,
            'missing' => $this->missingPageTypes($expected, $actual),
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function collectActualStageOnePlanPageTypesFromScope(array $scope): array
    {
        $actual = [];
        foreach ([
            $scope['plan_json']['pages'] ?? null,
            $scope['plan_structured']['pages'] ?? null,
        ] as $source) {
            foreach ($this->collectPageTypesFromPlanPageCollection($source) as $pageType) {
                $actual[$pageType] = true;
            }
        }

        return \array_values(\array_keys($actual));
    }

    /**
     * @return list<string>
     */
    private function collectPageTypesFromPlanPageCollection(mixed $source): array
    {
        if (!\is_array($source)) {
            return [];
        }

        $pageTypes = [];
        foreach ($source as $key => $page) {
            if (!\is_array($page)) {
                continue;
            }
            $pageType = \trim((string)($page['page_type'] ?? $page['type'] ?? (\is_string($key) ? $key : '')));
            if ($pageType !== '') {
                $pageTypes[$pageType] = true;
            }
        }

        return \array_values(\array_keys($pageTypes));
    }

    /**
     * @param list<string>|array<int, mixed> $expected
     * @param list<string>|array<int, mixed> $actual
     * @return list<string>
     */
    private function missingPageTypes(array $expected, array $actual): array
    {
        $actualSet = [];
        foreach ($actual as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '') {
                $actualSet[$pageType] = true;
            }
        }

        $missing = [];
        foreach ($expected as $pageType) {
            $pageType = \trim((string)$pageType);
            if ($pageType !== '' && !isset($actualSet[$pageType])) {
                $missing[] = $pageType;
            }
        }

        return \array_values(\array_unique($missing));
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
                'status' => 'cancelled',
                'message' => (string)__('Message'),
            ],
            AiSiteScopeCompatibilityService::WORKSPACE_STATUS_CAN_PUBLISH
        );
        $this->appendWorkspaceEvent(
            $session->getId(),
            $adminId,
            'plan',
            'operation_cancelled',
            (string)__('妫€娴嬪埌闃舵涓€杈撳叆鍙樻洿锛屽凡鍙栨秷鏃т换鍔″苟閲嶆柊鎺掗槦鐢熸垚'),
            ['operation' => 'plan', 'details' => ['reason' => 'plan_scope_changed']]
        );
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function getStageOptions(): array
    {
        return [
            ['value' => AiSiteAgentSession::STAGE_PLAN, 'label' => (string)__('璁″垝闃舵')],
            ['value' => AiSiteAgentSession::STAGE_VISUAL_EDIT, 'label' => (string)__('铏氭嫙缂栬緫')],
            ['value' => AiSiteAgentSession::STAGE_PUBLISH, 'label' => (string)__('纭鍙戝竷')],
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
     * FPM 鍦烘櫙涓嬶紝SSE 闀胯繛鎺ラ渶瑕佸敖蹇噴鏀?session 鏂囦欢閿侊紝
     * 閬垮厤鍚屼竴鐢ㄦ埛鐨勫苟鍙戣姹傦紙鍚?EventSource 閲嶈繛锛変簰鐩搁樆濉炪€?
     */
    private function releasePhpSessionLockForSse(): void
    {
        if (\function_exists('session_status') && \session_status() === \PHP_SESSION_ACTIVE) {
            @\session_write_close();
        }
    }

}







