<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Controller\Backend;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Service\AI\AiResponseJsonParser;
use GuoLaiRen\PageBuilder\Service\AiSiteAgentSessionService;
use GuoLaiRen\PageBuilder\Service\QuickBuildAggregator;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

/**
 * PageBuilder AI 建站工作台：会话入口与 scope 工作台（与 Weline_Websites 建站智能体互补）
 */
#[Acl('GuoLaiRen_PageBuilder::ai_site_agent', 'AI 建站工作台', 'mdi-robot-outline', 'PageBuilder AI 建站会话与流水线', 'Weline_Backend::page_builder_group')]
class AiSiteAgent extends BaseController
{
    public function __construct(
        private readonly AiSiteAgentSessionService $sessionService,
    ) {
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_index', 'AI 建站工作台', 'mdi-robot-outline', '进入 AI 建站工作台', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function index(): string
    {
        $adminId = (int) $this->getLoginUserId();
        $recent = $adminId > 0 ? $this->sessionService->listRecentSessionsForAdmin($adminId, 30) : [];

        $this->assign('title', __('AI 建站工作台'));
        $this->assign('recent_sessions', $recent);

        return $this->fetch();
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_workspace', 'AI 建站会话', 'mdi-clipboard-text-outline', '查看与编辑 AI 建站会话', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function workspace(): string
    {
        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            $this->assign('title', __('AI 建站工作台'));
            $this->assign('error_message', __('未登录或会话令牌无效'));
            return $this->fetch('AiSiteAgent/workspace-error');
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $this->assign('title', __('AI 建站工作台'));
            $this->assign('error_message', __('会话不存在或无权访问'));
            return $this->fetch('AiSiteAgent/workspace-error');
        }

        $scope = $session->getScopeArray();
        $scopePreview = $scope === [] ? '{}' : (string) \json_encode($scope, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
        $sessionId = $session->getId();
        $events = $this->sessionService->listRecentEvents($sessionId, $adminId, 200);
        $lastEventId = $this->sessionService->getLatestEventId($sessionId, $adminId);

        /** @var Url $urlHelper */
        $urlHelper = ObjectManager::getInstance(Url::class);
        $previewFullUrl = '';
        $previewPageId = (int) ($scope['preview_page_id'] ?? 0);
        if ($previewPageId > 0 && $session->getWelineThemeId() > 0) {
            $previewFullUrl = $urlHelper->getBackendUrl('*/backend/preview/full', [
                'page_id' => $previewPageId,
                'weline_theme_id' => $session->getWelineThemeId(),
            ]);
        }

        $this->assign('title', __('AI 建站会话'));
        $this->assign('session', $session);
        $this->assign('scope_preview', $scopePreview);
        $this->assign('events', $events);
        $this->assign('preview_full_url', $previewFullUrl);
        $this->assign('stage_options', $this->getStageOptions());
        $this->assign('stream_sse_path', 'pagebuilder/backend/aiSiteAgent/stream-sse');
        $this->assign('last_event_id', $lastEventId);
        $this->assign('scope', $scope);
        $this->assign('wizard_links', [
            'quick_build_wizard' => $urlHelper->getBackendUrl('pagebuilder/backend/quickBuild/wizard'),
            'domain_management' => $urlHelper->getBackendUrl('pagebuilder/backend/domainManagement/index'),
            'website_management' => $urlHelper->getBackendUrl('pagebuilder/backend/websiteManagement/index'),
            'page_builder' => $urlHelper->getBackendUrl('pagebuilder/backend/page/index'),
            'site_builder_agent' => $urlHelper->getBackendUrl('*/backend/site-builder-agent/index'),
        ]);
        $this->assign('ai_module_available', \class_exists(\Weline\Ai\Service\AiService::class));
        $this->assign('query_domain_status_url', $urlHelper->getBackendUrl('pagebuilder/backend/aiSiteAgent/post-query-domain-status'));
        $this->assign('ai_generate_brief_url', $urlHelper->getBackendUrl('pagebuilder/backend/aiSiteAgent/post-ai-generate-brief'));
        $this->assign('publish_check_url', $urlHelper->getBackendUrl('pagebuilder/backend/aiSiteAgent/post-publish-checklist'));

        return $this->fetch();
    }

    /**
     * GET JSON：会话状态 + 最近事件（供前端轮询或外部工具）
     */
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '读取 AI 建站会话状态', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getStateJson(): string
    {
        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getGet('public_id', ''));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }
        $events = $this->sessionService->listRecentEvents($session->getId(), $adminId, 80);

        return $this->fetchJson([
            'success' => true,
            'data' => [
                'public_id' => $session->getPublicId(),
                'stage' => $session->getStage(),
                'publish_status' => $session->getPublishStatus(),
                'website_id' => $session->getWebsiteId(),
                'weline_theme_id' => $session->getWelineThemeId(),
                'scope' => $session->getScopeArray(),
                'events' => $events,
            ],
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '合并 scope', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postMergeScope(): string
    {
        return $this->jsonMutateScope(true);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '替换 scope', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postReplaceScope(): string
    {
        return $this->jsonMutateScope(false);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '更新阶段', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postSetStage(): string
    {
        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getPost('public_id', ''));
        $stage = \trim((string) $this->request->getPost('stage', ''));
        if ($publicId === '') {
            $publicId = \trim((string) $this->request->getBodyParam('public_id', ''));
        }
        if ($stage === '') {
            $stage = \trim((string) $this->request->getBodyParam('stage', ''));
        }
        if ($adminId <= 0 || $publicId === '' || $stage === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }
        $allowed = \array_flip(\array_column($this->getStageOptions(), 'value'));
        if (!isset($allowed[$stage])) {
            return $this->fetchJson(['success' => false, 'message' => __('无效的阶段')]);
        }
        $ok = $this->sessionService->setStage($session->getId(), $adminId, $stage);
        if (!$ok) {
            return $this->fetchJson(['success' => false, 'message' => __('保存失败')]);
        }
        $this->sessionService->appendEvent($session->getId(), $adminId, 'stage', ['stage' => $stage]);

        return $this->fetchJson(['success' => true, 'stage' => $stage]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '绑定主题与站点', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postBindLinks(): string
    {
        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getPost('public_id', ''));
        if ($publicId === '') {
            $publicId = \trim((string) $this->request->getBodyParam('public_id', ''));
        }
        $websiteId = (int) $this->request->getPost('website_id', $this->request->getBodyParam('website_id', 0));
        $welineThemeId = (int) $this->request->getPost('weline_theme_id', $this->request->getBodyParam('weline_theme_id', 0));
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }
        if ($websiteId <= 0 && $welineThemeId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('请填写站点 ID 或主题 ID')]);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }
        $sid = $session->getId();
        if ($websiteId > 0) {
            $this->sessionService->bindWebsite($sid, $adminId, $websiteId);
        }
        if ($welineThemeId > 0) {
            $this->sessionService->bindWelineTheme($sid, $adminId, $welineThemeId);
        }
        $this->sessionService->appendEvent($sid, $adminId, 'bind', [
            'website_id' => $websiteId,
            'weline_theme_id' => $welineThemeId,
        ]);

        $fresh = $this->sessionService->loadById($sid, $adminId);

        return $this->fetchJson([
            'success' => true,
            'website_id' => $fresh?->getWebsiteId() ?? 0,
            'weline_theme_id' => $fresh?->getWelineThemeId() ?? 0,
        ]);
    }

    /**
     * SSE：快照 + 增量事件（轮询 DB），默认最长约 15 分钟后自动 done，客户端可重连并带 last_event_id
     */
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_stream', 'AI 建站事件流', 'mdi-access-point', '订阅 AI 建站会话事件流', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function getStreamSse(): void
    {
        $sse = new SseWriter();
        $sse->start();

        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getGet('public_id', ''));
        $lastEventId = (int) $this->request->getGet('last_event_id', 0);

        if ($adminId <= 0 || $publicId === '') {
            $sse->sendError(__('参数无效'));
            $sse->complete(['success' => false]);
            return;
        }

        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            $sse->sendError(__('会话不存在或无权访问'));
            $sse->complete(['success' => false]);
            return;
        }

        $sid = $session->getId();
        $sse->sendEvent('start', ['message' => __('已连接事件流')]);
        $sse->sendEvent('snapshot', [
            'public_id' => $session->getPublicId(),
            'stage' => $session->getStage(),
            'publish_status' => $session->getPublishStatus(),
            'website_id' => $session->getWebsiteId(),
            'weline_theme_id' => $session->getWelineThemeId(),
            'scope' => $session->getScopeArray(),
            'last_event_id' => $lastEventId,
        ]);

        $deadline = \time() + 900;
        while (\time() < $deadline && $sse->isAlive()) {
            $newEvents = $this->sessionService->listEventsAfterId($sid, $adminId, $lastEventId, 80);
            foreach ($newEvents as $ev) {
                $eid = (int) ($ev['event_id'] ?? 0);
                if ($eid > $lastEventId) {
                    $lastEventId = $eid;
                }
                $sse->sendEvent('log', $ev);
            }
            $sse->maybeHeartbeat();
            \usleep(2000000);
        }

        $sse->complete([
            'success' => true,
            'message' => __('事件流已结束，可重新连接以继续监听'),
            'last_event_id' => $lastEventId,
        ]);
    }

    /**
     * 查询 scope / 表单中的目标域名在 SaaS 侧的生命周期状态（QuickBuild 同源能力）
     */
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '查询域名状态', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postQueryDomainStatus(): string
    {
        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getPost('public_id', ''));
        if ($publicId === '') {
            $publicId = \trim((string) $this->request->getBodyParam('public_id', ''));
        }
        $domain = \trim((string) $this->request->getPost('domain', ''));
        if ($domain === '') {
            $domain = \trim((string) $this->request->getBodyParam('domain', ''));
        }
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }
        if ($domain === '') {
            $scope = $session->getScopeArray();
            $domain = \trim((string) ($scope['target_domain'] ?? ''));
        }
        if ($domain === '') {
            return $this->fetchJson(['success' => false, 'message' => __('请填写目标域名')]);
        }

        try {
            /** @var QuickBuildAggregator $aggregator */
            $aggregator = ObjectManager::getInstance(QuickBuildAggregator::class);
            $status = $aggregator->getDomainLifecycleStatus($domain);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }

        $this->sessionService->appendEvent($session->getId(), $adminId, 'domain_status', [
            'domain' => $domain,
            'raw' => $status,
        ]);

        return $this->fetchJson([
            'success' => true,
            'domain' => $domain,
            'data' => $status,
        ]);
    }

    /**
     * 根据需求描述 AI 生成站点标题、一句话与简报段落，合并入 scope
     */
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', 'AI 生成简报', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postAiGenerateBrief(): string
    {
        if (!\class_exists(\Weline\Ai\Service\AiService::class)) {
            return $this->fetchJson(['success' => false, 'message' => __('当前环境未启用 AI 模块')]);
        }

        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getPost('public_id', ''));
        if ($publicId === '') {
            $publicId = \trim((string) $this->request->getBodyParam('public_id', ''));
        }
        $hints = \trim((string) $this->request->getPost('hints', ''));
        if ($hints === '') {
            $hints = \trim((string) $this->request->getBodyParam('hints', ''));
        }
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }
        if ($hints === '') {
            $scope = $session->getScopeArray();
            $hints = \trim((string) ($scope['brief_description'] ?? $scope['user_description'] ?? ''));
        }
        if ($hints === '') {
            return $this->fetchJson(['success' => false, 'message' => __('请先填写需求描述')]);
        }

        $prompt = "你是建站顾问。仅输出一个 JSON 对象，不要 Markdown、不要解释。键：site_title（string），site_tagline（string），brief_description（string，2~5 句中文）。\n用户描述：\n" . $hints;

        try {
            /** @var \Weline\Ai\Service\AiService $ai */
            $ai = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            $text = $ai->generate($prompt, null, 'pagebuilder_component_generation', null, [], $adminId, true);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }

        /** @var AiResponseJsonParser $parser */
        $parser = ObjectManager::getInstance(AiResponseJsonParser::class);
        $arr = $parser->extractAndDecode($text);
        if (!\is_array($arr)) {
            return $this->fetchJson(['success' => false, 'message' => __('AI 返回无法解析为 JSON')]);
        }

        $patch = [];
        foreach (['site_title', 'site_tagline', 'brief_description'] as $k) {
            if (isset($arr[$k]) && \is_string($arr[$k]) && \trim($arr[$k]) !== '') {
                $patch[$k] = \trim($arr[$k]);
            }
        }
        if ($patch === []) {
            return $this->fetchJson(['success' => false, 'message' => __('AI 未返回有效字段')]);
        }

        $sid = $session->getId();
        if (!$this->sessionService->mergeScope($sid, $adminId, $patch)) {
            return $this->fetchJson(['success' => false, 'message' => __('保存失败')]);
        }
        $this->sessionService->appendEvent($sid, $adminId, 'ai_brief', ['keys' => \array_keys($patch)]);

        $fresh = $this->sessionService->loadById($sid, $adminId);

        return $this->fetchJson([
            'success' => true,
            'scope' => $fresh?->getScopeArray() ?? [],
        ]);
    }

    /**
     * 发布前检查：会话绑定、预览页、域名状态（只检查，不执行发布）
     */
    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_api', 'AI 建站会话 API', 'mdi-api', '发布前检查', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postPublishChecklist(): string
    {
        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getPost('public_id', ''));
        if ($publicId === '') {
            $publicId = \trim((string) $this->request->getBodyParam('public_id', ''));
        }
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }

        $scope = $session->getScopeArray();
        $websiteId = $session->getWebsiteId();
        $themeId = $session->getWelineThemeId();
        $previewPageId = (int)($scope['preview_page_id'] ?? 0);
        $targetDomain = \trim((string)($scope['target_domain'] ?? ''));

        $domainStatus = null;
        $domainChecked = false;
        if ($targetDomain !== '') {
            try {
                /** @var QuickBuildAggregator $aggregator */
                $aggregator = ObjectManager::getInstance(QuickBuildAggregator::class);
                $domainStatus = $aggregator->getDomainLifecycleStatus($targetDomain);
                $domainChecked = \is_array($domainStatus) && $domainStatus !== [];
            } catch (\Throwable) {
                $domainChecked = false;
            }
        }

        $items = [
            [
                'key' => 'website_bound',
                'label' => __('已绑定站点 ID'),
                'ok' => $websiteId > 0,
                'value' => $websiteId,
            ],
            [
                'key' => 'theme_bound',
                'label' => __('已绑定主题 ID'),
                'ok' => $themeId > 0,
                'value' => $themeId,
            ],
            [
                'key' => 'preview_page',
                'label' => __('已设置预览页面 ID'),
                'ok' => $previewPageId > 0,
                'value' => $previewPageId,
            ],
            [
                'key' => 'target_domain',
                'label' => __('已设置目标域名'),
                'ok' => $targetDomain !== '',
                'value' => $targetDomain,
            ],
            [
                'key' => 'domain_status',
                'label' => __('已获取域名状态'),
                'ok' => $domainChecked,
                'value' => $domainStatus,
            ],
        ];
        $passed = true;
        foreach ($items as $item) {
            if (empty($item['ok'])) {
                $passed = false;
                break;
            }
        }

        $payload = [
            'passed' => $passed,
            'items' => $items,
            'stage' => $session->getStage(),
            'publish_status' => $session->getPublishStatus(),
        ];
        $this->sessionService->appendEvent($session->getId(), $adminId, 'publish_check', $payload);

        return $this->fetchJson([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function getStageOptions(): array
    {
        $map = [
            AiSiteAgentSession::STAGE_BRIEF => __('阶段：需求简报'),
            AiSiteAgentSession::STAGE_DOMAIN => __('阶段：域名'),
            AiSiteAgentSession::STAGE_DOMAIN_WAIT => __('阶段：等待域名就绪'),
            AiSiteAgentSession::STAGE_VIRTUAL_THEME => __('阶段：虚拟主题'),
            AiSiteAgentSession::STAGE_PAGE_TYPES => __('阶段：页面类型'),
            AiSiteAgentSession::STAGE_CONTENT => __('阶段：内容生成'),
            AiSiteAgentSession::STAGE_VISUAL_EDIT => __('阶段：可视化微调'),
            AiSiteAgentSession::STAGE_PUBLISH => __('阶段：发布'),
        ];
        $out = [];
        foreach ($map as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label];
        }
        return $out;
    }

    private function jsonMutateScope(bool $merge): string
    {
        $adminId = (int) $this->getLoginUserId();
        $publicId = \trim((string) $this->request->getPost('public_id', ''));
        $scopeRaw = $this->request->getPost('scope', $this->request->getPost('scope_patch', ''));
        if ($publicId === '') {
            $publicId = \trim((string) $this->request->getBodyParam('public_id', ''));
        }
        if ($scopeRaw === '' || $scopeRaw === null) {
            $scopeRaw = $this->request->getBodyParam('scope', $this->request->getBodyParam('scope_patch', ''));
        }
        if ($adminId <= 0 || $publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('参数无效')]);
        }
        if (!\is_string($scopeRaw)) {
            $scopeRaw = (string) \json_encode($scopeRaw, \JSON_UNESCAPED_UNICODE);
        }
        $scopeRaw = \trim($scopeRaw);
        if ($scopeRaw === '') {
            return $this->fetchJson(['success' => false, 'message' => __('Scope 不能为空')]);
        }
        try {
            $decoded = \json_decode($scopeRaw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->fetchJson(['success' => false, 'message' => __('JSON 无效：%{1}', [$e->getMessage()])]);
        }
        if (!\is_array($decoded)) {
            return $this->fetchJson(['success' => false, 'message' => __('Scope 须为 JSON 对象')]);
        }
        $session = $this->sessionService->loadByPublicId($publicId, $adminId);
        if ($session === null) {
            return $this->fetchJson(['success' => false, 'message' => __('会话不存在或无权访问')]);
        }
        $sid = $session->getId();
        if ($merge) {
            $ok = $this->sessionService->mergeScope($sid, $adminId, $decoded);
        } else {
            $ok = $this->sessionService->replaceScope($sid, $adminId, $decoded);
        }
        if (!$ok) {
            return $this->fetchJson(['success' => false, 'message' => __('保存失败')]);
        }
        $this->sessionService->appendEvent($sid, $adminId, $merge ? 'scope_merge' : 'scope_replace', ['keys' => \array_keys($decoded)]);

        $fresh = $this->sessionService->loadById($sid, $adminId);

        return $this->fetchJson([
            'success' => true,
            'scope' => $fresh?->getScopeArray() ?? [],
        ]);
    }

    #[Acl('GuoLaiRen_PageBuilder::ai_site_agent_create', '创建 AI 建站会话', 'mdi-plus', '创建新的 AI 建站会话', 'GuoLaiRen_PageBuilder::ai_site_agent')]
    public function postCreateSession(): string
    {
        $adminId = (int) $this->getLoginUserId();
        if ($adminId <= 0) {
            return $this->fetchJson(['success' => false, 'message' => __('未登录')]);
        }

        try {
            $session = $this->sessionService->createSession($adminId, []);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('创建会话失败：%{1}', [$e->getMessage()]),
            ]);
        }

        $publicId = $session->getPublicId();
        if ($publicId === '') {
            return $this->fetchJson(['success' => false, 'message' => __('创建会话失败，请重试')]);
        }

        /** @var Url $urlHelper */
        $urlHelper = ObjectManager::getInstance(Url::class);
        $workspaceUrl = $urlHelper->getBackendUrl('*/backend/aiSiteAgent/workspace', ['public_id' => $publicId]);

        return $this->fetchJson([
            'success' => true,
            'public_id' => $publicId,
            'workspace_url' => $workspaceUrl,
        ]);
    }
}
