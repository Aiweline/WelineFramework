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
use GuoLaiRen\PageBuilder\Service\QuickBuildAggregator;
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
    private const PARAMS_PUBLIC_ID = ['public_id'];
    private const PARAMS_OPERATION_SSE = ['public_id', 'execution_token'];
    private const PARAMS_REGENERATE = ['public_id', 'page_type'];
    private const PARAMS_REFINE_COMPONENT = ['public_id', 'page_type', 'component_code', 'instruction'];
    private const PARAMS_UPDATE_BLOCK = ['public_id', 'page_type', 'block_id', 'block_config'];
    private const WORKSPACE_STREAM_SNAPSHOT_PERSIST = false;
    private const STREAM_LEASE_SCOPE_KEY = '_workspace_stream_lease';
    private const STREAM_LEASE_TTL_SEC = 60;
    /**
     * 工作区 stream-sse 续约窗口：超过此时间未收到续约请求（POST post-touch-stream-lease）则视为断连，
     * 服务端将结束事件流并清理与本 lease 关联的排队/运行中操作。
     * 前端心跳间隔应显著小于本值（当前页面约 20s 一次 POST 续约）。
     */
    private const STALE_ACTIVE_OPERATION_TTL_SEC = 180;

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
        $expertUi = $this->isAiSiteAgentExpertUiRequested();
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
        if ($preferredRegistrarAccountId <= 0 && $registrarAccounts !== []) {
            $preferredRegistrarAccountId = (int)($registrarAccounts[0]['account_id'] ?? 0);
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
        $this->assign('scope_preview', $expertUi ? $this->encodePrettyJson($state['scope']) : '{}');
        $this->assign('state_json_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/get-state-json', ['public_id' => $publicId]));
        $this->assign('merge_scope_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-merge-scope'));
        $this->assign('replace_scope_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-replace-scope'));
        $this->assign('set_stage_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-set-stage'));
        $this->assign('run_virtual_theme_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_build_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-build'));
        $this->assign('start_regenerate_page_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-regenerate-page'));
        $this->assign('start_refine_component_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-refine-component'));
        $this->assign('start_block_refine_sse_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-block-refine-sse'));
        $this->assign('start_block_regenerate_sse_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-block-regenerate-sse'));
        $this->assign('update_block_config_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-update-block-config'));
        $this->assign('start_publish_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-publish'));
        $this->assign('operation_sse_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/operation-sse', ['public_id' => $publicId]));
        $this->assign('stream_sse_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/stream-sse', ['public_id' => $publicId]));
        $this->assign('switch_preview_page_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-switch-preview-page'));
        $this->assign('publish_check_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-publish-checklist'));
        $this->assign('delete_workspace_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-delete-workspace'));
        $this->assign('recommend_domain_url', $this->url->getBackendUrl('websites/backend/site-builder-agent/recommend-domain'));
        $this->assign('check_domain_url', $this->url->getBackendUrl('websites/backend/site-builder-agent/check-domain'));
        $this->assign('start_domain_purchase_url', $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/post-start-domain-purchase'));
        $this->assign('domain_purchase_sse_url', $this->url->getBackendUrl('websites/backend/site-builder-agent/domain-purchase-sse'));
        $this->assign('domain_purchase_state', $domainPurchaseState);
        $this->assign('recommended_domain_list', $recommendedDomainList);
        $this->assign('recommended_registrar_label', $recommendedRegistrarLabel);
        $this->assign('preferred_registrar_account_id', $preferredRegistrarAccountId);
        $this->assign('registrar_accounts', $registrarAccounts);
        $this->assign('linked_workbench_public_id', $linkedWebsitesSession?->getPublicId() ?? '');
        $this->assign('back_url', $this->url->getBackendUrl('websites/backend/site-builder-agent/index', ['provider' => 'pagebuilder']));
        $this->assign('stage_options', $this->getStageOptions());

        $this->assign('guided_ui', !$expertUi);
        $this->assign(
            'workspace_expert_url',
            $this->url->getBackendUrl('pagebuilder/backend/ai-site-agent/workspace', ['public_id' => $publicId, 'expert' => '1'])
        );
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
            $message = (string)__('预览页面不存在或无访问权限');
            $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:24px;font-family:system-ui,sans-serif;color:#b91c1c;background:#fff7f7;">'
                . \htmlspecialchars($message, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                . '</body></html>';
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

        $virtualThemeId = \max(
            (int)$this->request->getGet('virtual_theme_id', 0),
            (int)($context['virtual_theme_id'] ?? 0)
        );
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
            // JSON state fetch is also a read path; keep it side-effect free.
            'data' => $this->buildWorkspaceState($session, $adminId, 80, false),
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
                'fake_mode' => $fakeModeEnabled ? 1 : 0,
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
        if (isset($scopePatch['page_types']) && !\array_key_exists(AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY, $scopePatch)) {
            $scopePatch[AiSiteScopeCompatibilityService::PAGE_TYPES_USER_CUSTOMIZED_KEY] = 1;
        }
        $siteProfileManual = \is_array($scopePatch['site_profile_manual'] ?? null) ? $scopePatch['site_profile_manual'] : [];
        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale'] as $manualField) {
            if (\array_key_exists($manualField, $scopePatch) && !\array_key_exists($manualField, $siteProfileManual)) {
                $siteProfileManual[$manualField] = true;
            }
        }
        if ($siteProfileManual !== []) {
            $scopePatch['site_profile_manual'] = $siteProfileManual;
        }
        $mergedScope = $this->scopeCompatibilityService->normalizeScope(\array_replace($session->getScopeArray(), $scopePatch));
        $targetDomain = \strtolower(\trim((string)($mergedScope['target_domain'] ?? '')));
        if ($targetDomain === '') {
            return $this->jsonError(
                'DOMAIN_REQUIRED_BEFORE_BUILD',
                (string)__('请先完成域名推荐并确认目标域名后，再开始生成主题/页面'),
                ['public_id', 'target_domain']
            );
        }
        $startStage = $this->scopeCompatibilityService->normalizeStage($session->getStage());
        if ($startStage !== AiSiteAgentSession::STAGE_VISUAL_EDIT) {
            $startStage = AiSiteAgentSession::STAGE_VIRTUAL_THEME;
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
        @\set_time_limit(0);
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
            __('姝ｅ湪重建区块：%{page}', ['page' => $pageLabel]),
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
                return $this->fetchJson(['success' => false, 'message' => __('域名尚未就绪，请先等待域名流程完成后再确认建站发布。')]);
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

        foreach ($blocks as $index => $block) {
            if (!\is_array($block) || \trim((string)($block['block_id'] ?? '')) !== $blockId) {
                continue;
            }

            $updatedBlock = $this->htmlBlocksBuildService->rebuildBlock(
                $block,
                \is_array($scope['website_profile'] ?? null) ? $scope['website_profile'] : [],
                $scope,
                $blockConfig
            );
            $blocks[$index] = $updatedBlock;
            break;
        }

        if ($updatedBlock === null) {
            return $this->fetchJson(['success' => false, 'message' => __('未找到要更新的区块')]);
        }

        $virtualPage['blocks'] = \array_values($blocks);
        $virtualPage['last_generated_at'] = \date('Y-m-d H:i:s');
        $virtualPages[$pageType] = $virtualPage;
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
            'block' => $updatedBlock,
            'data' => $this->buildWorkspaceState($fresh, $adminId, 80, true),
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
        if (\strlen($tabToken) > 64) {
            $tabToken = \substr($tabToken, 0, 64);
        }
        $this->logStreamSse('request_received', [
            'admin_id' => $adminId,
            'public_id' => $publicId,
            'last_event_id' => $lastEventId,
            'stream_kind' => 'workspace',
            'tab_token' => $tabToken !== '' ? $tabToken : null,
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
        $maxIdleLoops = 180;  // 最多连续 180 次无新事件（约 3 分钟），之后强制检测
        $consecutiveIdleLoops = 0;
        $maxTotalLoops = 86400;  // 最多运行 24 小时（兜底）
        $forceCloseAfterIdleLoops = 60;  // 连续 60 次无新事件（约 1 分钟）后强制关闭

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

                // 连续多次无事件，尝试主动检测连接状态
                if ($consecutiveIdleLoops > $maxIdleLoops) {
                    $this->logStreamSse('idle_max_reached', [
                        'session_id' => $session->getId(),
                        'loop_count' => $loopCount,
                        'consecutive_idle_loops' => $consecutiveIdleLoops,
                    ], 'warning');
                    break;
                }

                // 连续多次无事件且 isAlive 仍返回 true，发送 comment 检测对端是否真的活着
                if ($consecutiveIdleLoops === $forceCloseAfterIdleLoops) {
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

            if (\in_array($status, ['done', 'error', 'cancelled'], true)) {
                return ['ok' => false, 'reason' => 'terminal', 'message' => (string)__('该操作已结束，请重新发起')];
            }

            if ($status === 'running') {
                return ['ok' => false, 'reason' => 'duplicate_stream'];
            }

            if ($status !== 'queued') {
                return ['ok' => false, 'reason' => 'terminal', 'message' => (string)__('操作状态异常，请刷新后重试')];
            }

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
        if ($operation === '' || (string)($activeOperation['execution_token'] ?? '') !== $executionToken) {
            $this->sendSseContractError($sse, 'OPERATION_NOT_FOUND', (string)__('未找到待执行的工作区操作'), self::PARAMS_OPERATION_SSE, 404);
            $sse->complete(['success' => false]);
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
                $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
                $sse->sendEvent('warning', [
                    'message' => (string)__('该操作已在其他连接中执行，本连接不重复运行'),
                    'operation' => $operation,
                ]);
                $sse->complete([
                    'success' => true,
                    'duplicate_stream' => true,
                    'message' => (string)__('已同步当前状态'),
                    'state' => $this->buildWorkspaceEventStatePayload(
                        $this->buildWorkspaceState($fresh, $adminId, 80, true)
                    ),
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
            $result = match ($operation) {
                'build' => $this->runBuildOperation($sse, $session, $adminId),
                'regenerate_page' => $this->runRegeneratePageOperation($sse, $session, $adminId, (string)($activeOperation['page_type'] ?? '')),
                'publish' => $this->runPublishOperation($sse, $session, $adminId),
                default => throw new \RuntimeException((string)__('未知操作')),
            };
            $fresh = $this->sessionService->loadById($session->getId(), $adminId) ?? $session;
            $sse->complete([
                'success' => true,
                'message' => (string)($result['message'] ?? __('操作执行完成')),
                'operation' => $operation,
                'data' => $result,
                'state' => $this->buildWorkspaceEventStatePayload(
                    $this->buildWorkspaceState($fresh, $adminId, 80, true)
                ),
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
        if (\in_array($activeOperationStatus, ['queued', 'running'], true) && $this->isActiveOperationStale($activeOperation)) {
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
            'virtual_pages_by_type' => $virtualPagesByType,
            'pending_generation_page_types' => $pendingGenerationPageTypes,
            'build_task_summary' => $taskSummary,
            'auto_start_build_after_stream' => $this->shouldAutoStartBuildAfterWorkspaceStream($workspaceStatus, $activeOperation, $pendingGenerationPageTypes),
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

        unset($state['events']);

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
            $scope['pre_publish_visual_urls']
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
        if (\in_array($activeStatus, ['queued', 'running'], true) && $this->isActiveOperationStale($activeOperation)) {
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
                $sse->sendEvent('task_completed', [
                    'task_key' => 'shared:header',
                    'task_type' => 'shared_component',
                    'message' => (string)__('共享任务已完成：Header'),
                ]);
            }
            if (\is_array($sharedComponents['footer'] ?? null)) {
                $scope['shared_components']['footer'] = $sharedComponents['footer'];
                $scope = $this->buildTaskService->markTaskDone($scope, 'shared:footer', ['region' => 'footer']);
                $sse->sendEvent('task_completed', [
                    'task_key' => 'shared:footer',
                    'task_type' => 'shared_component',
                    'message' => (string)__('共享任务已完成：Footer'),
                ]);
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
            $sse->sendEvent('task_completed', [
                'task_key' => 'page:' . $pageType,
                'task_type' => 'page_group',
                'message' => (string)__('页面任务已完成：%{page}', ['page' => $pageLabel]),
            ]);
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
        $pendingTasks = $this->buildTaskService->listPendingTasks($scope);
        $totalSteps = \max(1, \count($pendingTasks) + 1);
        $currentStep = 0;

        $currentStep++;
        $progressPercent = (int)(($currentStep / $totalSteps) * 100);
        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('姝ｅ湪鍑嗗缃戠珯璧勬枡'), $progressPercent);
        $draftWebsite = $this->draftWebsiteService->ensureDraftWebsite($scope, $scope['website_profile']);
        $scope['draft_website_id'] = (int)$draftWebsite['website_id'];
        $scope['website_id'] = (int)$draftWebsite['website_id'];
        $scope['selected_website_id'] = (int)$draftWebsite['website_id'];

        /** @var AiSitePageComponentGenerationService $pageComponentGenerationService */
        $pageComponentGenerationService = ObjectManager::getInstance(AiSitePageComponentGenerationService::class);
        $virtualPages = $this->scopeCompatibilityService->buildVirtualPagesByType($pageTypes, $scope);
        $sharedComponents = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
        $environmentReadyEmitted = false;

        foreach ($pendingTasks as $task) {
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
                    $region === 'header' ? __('姝ｅ湪鐢熸垚鍏变韩 Header') : __('姝ｅ湪鐢熸垚鍏变韩 Footer'),
                    $progressPercent
                );
                $component = $pageComponentGenerationService->generateSharedComponent($region, $scope['website_profile'], $scope);
                $sharedComponents[$region] = $component;
                $scope['shared_components'] = \is_array($scope['shared_components'] ?? null) ? $scope['shared_components'] : [];
                $scope['shared_components'][$region] = $component;
                $scope = $this->buildTaskService->markTaskDone($scope, $taskKey, ['region' => $region]);
                $this->sessionService->replaceScope($session->getId(), $adminId, $scope);

                $message = $region === 'header'
                    ? (string)__('鍏变韩浠诲姟宸插畬鎴愶細Header')
                    : (string)__('鍏变韩浠诲姟宸插畬鎴愶細Footer');
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
                $sse->sendEvent('task_completed', [
                    'task_key' => $taskKey,
                    'task_type' => $taskType,
                    'message' => $message,
                    'state' => $sharedState,
                ]);
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
                __('姝ｅ湪鐢熸垚 HTML 鍖哄潡锛?{page}', ['page' => $pageLabel]),
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
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('椤甸潰宸茬敓鎴愶細%{page}', ['page' => $pageLabel]),
                [
                    'operation' => 'build',
                    'page_type' => $pageType,
                    'details' => ['section_code' => $sectionCode],
                ]
            );
            $sse->sendEvent('page_generated', [
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'page_id' => $pageId,
                'virtual_theme_id' => 0,
                'progress_percent' => $progressPercent,
                'section_code' => $sectionCode,
                'state' => $state,
            ]);
            $sse->sendEvent('task_completed', [
                'task_key' => $taskKey,
                'task_type' => $taskType,
                'message' => (string)__('鍖哄潡浠诲姟宸插畬鎴愶細%{page}', ['page' => $pageLabel]),
                'state' => $state,
            ]);
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
        /*
        $scope['active_operation'] = \array_replace(\is_array($scope['active_operation'] ?? null) ? $scope['active_operation'] : [], ['status' => 'done', 'updated_at' => $now, 'message' => (string)__('HTML 鍖哄潡宸茬敓鎴?)]);

        $this->sessionService->replaceScope($session->getId(), $adminId, $scope);
        $this->sessionService->bindWebsite($session->getId(), $adminId, (int)$draftWebsite['website_id']);
        $this->sessionService->bindVirtualTheme($session->getId(), $adminId, 0);
        $this->sessionService->setStage($session->getId(), $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT);
        $this->sessionService->setPublishStatus($session->getId(), $adminId, AiSiteAgentSession::PUBLISH_STATUS_DRAFT);

        $this->sendOperationProgress($sse, $session, $adminId, AiSiteAgentSession::STAGE_VISUAL_EDIT, 'build', __('HTML 鍖哄潡宸插氨缁紝鍙瑙堟垨鍙戝竷'), 100);

        return ['message' => (string)__('HTML 鍖哄潡鏋勫缓瀹屾垚'), 'draft_website_id' => (int)$draftWebsite['website_id'], 'virtual_theme_id' => 0, 'page_types' => $pageTypes];
        */
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

        if (\is_array($sharedComponents['header'] ?? null)) {
            $blocks[] = $this->htmlBlocksBuildService->buildGeneratedSharedBlock('header', $pageType, $sharedComponents['header']);
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

        if (\is_array($sharedComponents['footer'] ?? null)) {
            $blocks[] = $this->htmlBlocksBuildService->buildGeneratedSharedBlock('footer', $pageType, $sharedComponents['footer']);
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
     * @return array<string, mixed>
     */
    private function runBuildOperation(SseWriter $sse, AiSiteAgentSession $session, int $adminId): array
    {
        $this->assertActiveStreamLeaseAlive($session, $adminId);
        $scope = $this->scopeCompatibilityService->normalizeScope($session->getScopeArray());
        $workspaceTrack = $this->scopeCompatibilityService->normalizeWorkspaceTrack((string)($scope['workspace_track'] ?? ''));
        if ($workspaceTrack === AiSiteScopeCompatibilityService::WORKSPACE_TRACK_HTML_BLOCKS) {
            return $this->runHtmlBlocksBuildOperationV2($sse, $session, $adminId, $scope);
        }
        return $this->runVirtualThemeBuildOperationV2($sse, $session, $adminId, $scope);
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
            $sse->sendEvent('task_completed', [
                'task_key' => 'shared:' . $region,
                'task_type' => 'shared_component',
                'message' => $message,
                'state' => $state,
            ]);
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
            $sse->sendEvent('task_completed', [
                'task_key' => 'page:' . $pageType,
                'task_type' => 'page_group',
                'message' => (string)__('页面任务已完成：%{page}', ['page' => $pageLabel]),
                'state' => $state,
            ]);
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
        $pendingTasks = $this->buildTaskService->listPendingTasks($scope);
        $totalSteps = \max(1, \count($pendingTasks) + 1);
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

        foreach ($pendingTasks as $task) {
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
                $sse->sendEvent('task_completed', [
                    'task_key' => $taskKey,
                    'task_type' => $taskType,
                    'message' => $message,
                    'state' => $sharedState,
                ]);
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
            $this->appendWorkspaceEvent(
                $session->getId(),
                $adminId,
                AiSiteAgentSession::STAGE_VISUAL_EDIT,
                'page_generated',
                (string)__('椤甸潰宸茬敓鎴愶細%{page}', ['page' => $pageLabel]),
                [
                    'operation' => 'build',
                    'page_type' => $pageType,
                    'details' => ['section_code' => $sectionCode, 'virtual_theme_id' => (int)$scope['virtual_theme_id']],
                ]
            );
            $sse->sendEvent('page_generated', [
                'page_type' => $pageType,
                'page_label' => $pageLabel,
                'page_id' => $pageId,
                'virtual_theme_id' => (int)$scope['virtual_theme_id'],
                'progress_percent' => $progressPercent,
                'section_code' => $sectionCode,
                'state' => $state,
            ]);
            $sse->sendEvent('task_completed', [
                'task_key' => $taskKey,
                'task_type' => $taskType,
                'message' => (string)__('Theme section task complete: %{page}', ['page' => $pageLabel]),
                'state' => $state,
            ]);
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
        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale'] as $manualField) {
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

        if ($options === []) {
            $options = [
                [
                    'account_id' => 900001,
                    'label' => (string)__('本地演示服务商 - 本地演示主账号'),
                    'registrar_name' => (string)__('本地演示服务商'),
                    'registrar_code' => 'local_demo',
                    'account_name' => (string)__('本地演示主账号'),
                ],
                [
                    'account_id' => 900002,
                    'label' => (string)__('沙盒域名 - 本地演示备用账号'),
                    'registrar_name' => (string)__('沙盒域名'),
                    'registrar_code' => 'sandbox_demo',
                    'account_name' => (string)__('本地演示备用账号'),
                ],
            ];
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
            'locales',
            'page_types',
            'recommended_pages',
        ] as $field) {
            if (\array_key_exists($field, $scope)) {
                $patch[$field] = $scope[$field];
            }
        }

        foreach (['site_title', 'site_tagline', 'target_domain', 'brief_description', 'default_locale'] as $manualField) {
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
