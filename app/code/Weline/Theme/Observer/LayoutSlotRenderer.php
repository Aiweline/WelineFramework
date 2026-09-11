<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\Theme\Api\Layout\LayoutIdentity;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Service\ThemeTargetIdentityResolver;
use Weline\Theme\Service\PreviewBootstrapAssetInjector;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\PreviewRequestInspector;
use Weline\Theme\Service\PreviewTokenService;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\SlotBoundaryMarkers;
use Weline\Theme\Service\ThemeCacheGenerator;
use Weline\Theme\Service\ThemeContextService;
use Weline\Theme\Service\ThemePageTypeResolver;
use Weline\Theme\Service\ThemeSlotContractService;

/**
 * 甯冨眬鎻掓Ы娓叉煋鍣?Observer
 * 
 * 鍦ㄦ帶鍒跺櫒妯℃澘娓叉煋瀹屾垚鍚庯紝澶勭悊鎻掓Ы鏇挎崲锛?
 * 1. 妫€娴?HTML 涓殑 data-wslot / widget-slot-area 鍏冪礌
 * 2. 浠庢暟鎹簱鑾峰彇璇ラ〉闈㈢殑閮ㄤ欢甯冨眬閰嶇疆
 * 3. 娓叉煋閮ㄤ欢骞跺～鍏呭埌瀵瑰簲鎻掓Ы
 * 4. 杩斿洖鏈€缁堢殑 HTML
 * 
 * 鐩戝惉浜嬩欢锛歐eline_Framework_Controller::fetch_file_after
 * 
 * 鐘舵€佸垽鏂€昏緫锛?
 * 1. 鍚庡彴鍙鍖栫紪杈戝櫒 iframe锛坋ditor_mode=1锛夛細榛樿鍔犺浇 draft锛屽彲閫氳繃 status 鍙傛暟鍒囨崲
 * 2. 鍓嶅彴棰勮锛坧review_mode=1锛夛細鍔犺浇 draft 鏁版嵁棰勮
 * 3. 鍓嶅彴姝ｅ父璁块棶锛氬姞杞?published 鏁版嵁
 * 
 * URL 鍙傛暟锛?
 * - editor_mode=1锛氭爣璇嗗悗鍙扮紪杈戝櫒 iframe
 * - preview_mode=1锛氭爣璇嗗墠鍙拌崏绋块瑙?
 * - status=draft/published锛氭槑纭寚瀹氳鍔犺浇鐨勭増鏈紙浼樺厛绾ф渶楂橈級
 */
class LayoutSlotRenderer implements ObserverInterface
{
    private SlotRendererService $slotRenderer;
    private ThemeContextService $themeContext;
    private Request $request;
    private ThemeCacheGenerator $cacheGenerator;
    private Url $url;
    private PreviewTokenService $previewTokenService;
    private PreviewRequestInspector $previewRequestInspector;
    private ThemePageTypeResolver $pageTypeResolver;
    private ?ThemeSlotContractService $slotContractService = null;
    private bool $isEnabled = true;

    public function __construct(
        SlotRendererService $slotRenderer,
        ThemeContextService $themeContext,
        Request $request,
        ThemeCacheGenerator $cacheGenerator,
        Url $url,
        PreviewTokenService $previewTokenService,
        PreviewRequestInspector $previewRequestInspector,
        ThemePageTypeResolver $pageTypeResolver
    ) {
        $this->slotRenderer = $slotRenderer;
        $this->themeContext = $themeContext;
        $this->request = $request;
        $this->cacheGenerator = $cacheGenerator;
        $this->url = $url;
        $this->previewTokenService = $previewTokenService;
        $this->previewRequestInspector = $previewRequestInspector;
        $this->pageTypeResolver = $pageTypeResolver;
    }

    public function execute(Event &$event): void
    {
        if (!$this->isEnabled) {
            return;
        }

        // 鑾峰彇浜嬩欢鏁版嵁锛坒etch_file_after 浜嬩欢浣跨敤 content 鍜?fileName锛?
        $html = (string)$event->getData('content');
        $template = (string)$event->getData('fileName');
        
        // 濡傛灉 HTML 涓虹┖锛岀洿鎺ヨ繑鍥?
        if (empty($html)) {
            return;
        }
        
        // 鍒ゆ柇鍖哄煙锛堜粠妯℃澘璺緞鎴栧叾浠栦笂涓嬫枃鍒ゆ柇锛?
        $area = $this->detectArea($template);
        
        // === 绗竴姝ワ細澶勭悊棰勮妯″紡锛堢嫭绔嬩簬鎻掓Ы澶勭悊锛?==
        // 妫€娴?URL 鍙傛暟涓殑棰勮 token锛屽鏋滄湁鏁堝垯璁剧疆 Cookie锛堝疄鐜伴瑙堢姸鎬佹寔涔呭寲锛?
        $urlToken = $this->request->getParam(PreviewTokenService::TOKEN_KEY);
        if ($urlToken
            && !$this->previewRequestInspector->shouldKeepPreviewStateOnlyForCurrentRequest()
            && $this->previewRequestInspector->shouldAllowPreviewTokenCookie()
            && $this->previewTokenService->validateToken($urlToken)) {
            // 鑷姩璁剧疆 Cookie锛岃繖鏍峰悗缁〉闈㈣烦杞笉闇€瑕佹瘡娆￠兘甯?token 鍙傛暟
            $this->previewTokenService->setPreviewCookie($urlToken);
        }
        
        // 棰勮妯″紡涓嬫敞鍏ラ€€鍑洪瑙堟诞绐楀拰 AJAX 鎷︽埅鍣紙闈炵紪杈戝櫒 iframe 妯″紡锛?
        // 杩欎釜閫昏緫蹇呴』鍦ㄦ彃妲芥鏌ヤ箣鍓嶆墽琛岋紝鍥犱负鍗充娇椤甸潰娌℃湁鎻掓Ы锛屼篃闇€瑕佹樉绀洪€€鍑烘寜閽?
        if ($this->previewTokenService->isPreviewMode()) {
            // Preview HTML must never be published into the anonymous storefront FPC.
            \Weline\Framework\Cache\SharedResponseCachePolicy::forbid('theme_preview_mode');
            $editorMode = $this->request->getParam('editor_mode');
            // frontend: editor_mode=1 的编辑器 iframe 不注入
            // backend: 预览环境即使 editor_mode=1 也要提供退出浮窗
            $shouldInjectPreviewFloat = ($editorMode !== '1' && $editorMode !== 'true')
                || $area === 'backend';
            if ($shouldInjectPreviewFloat) {
                $html = $this->injectPreviewExitButton($html);
                $html = $this->injectPreviewInterceptor($html);
            }
        }

        $allowBackendSlots = $area === 'backend' && $this->isBackendDashboardSlotRequest($template);

        // 普通后台页面保持原有行为；Dashboard 是后台 Theme 的特殊布局，需要进入 slot 渲染链。
        if ($area !== 'frontend' && !$allowBackendSlots) {
            $event->setData('content', $this->finalizeFrontendHtml($html, $area));
            return;
        }

        // === 绗簩姝ワ細澶勭悊鎻掓Ы鏇挎崲 ===
        // 妫€鏌ユ槸鍚﹀寘鍚彃妲芥爣璁帮紙鏀寔鏂版棫涓ょ鏂瑰紡锛?
        // 娉ㄦ剰锛氫笉鍐嶅己鍒舵鏌?isLayoutTemplate锛屽洜涓?fetch_file_after 浜嬩欢
        // 鑾峰彇鐨勬槸瀹屾暣娓叉煋鍚庣殑 HTML锛屽寘鍚墍鏈夊瓙妯℃澘锛堝 partials锛夌殑鍐呭
        $hasSlotMarkers = strpos($html, 'data-wslot') !== false || strpos($html, 'widget-slot-area') !== false;

        // Fast path: normal frontend HTML without slot markers needs no theme or DOM pass.
        $isEditorOrPreview = $this->isEditorOrPreviewMode();
        if (!$hasSlotMarkers && !$isEditorOrPreview) {
            $event->setData('content', $this->finalizeFrontendHtml($html, $area));
            return;
        }

        // Resolve preview/active theme through the shared theme context.
        $themeId = $this->resolveThemeId($area);
        // 濡傛灉娌℃湁涓婚 ID锛屾棤娉曞鐞嗘彃妲?
        if (!$themeId) {
            // 鏇存柊浜嬩欢鏁版嵁锛堝彲鑳藉凡娉ㄥ叆棰勮閫€鍑烘寜閽級
            $event->setData('content', $this->finalizeFrontendHtml($html, $area));
            return;
        }

        // 纭畾椤甸潰绫诲瀷
        $pageType = $this->detectPageType($template);

        // 妫€娴嬭鍔犺浇鐨勭姸鎬佺増鏈?
        $status = $this->detectStatus();
        
        // 鍒ゆ柇鏄惁涓虹紪杈?棰勮妯″紡锛堢敤浜庢樉绀鸿鍛婄瓑锛?
        $shouldReportSlotContractWarnings = $this->isLayoutTemplate($template) || stripos($html, '</body>') !== false;

        $slotContractWarnings = [];
        if ($this->shouldShowEditorSlotDiagnostics() && $shouldReportSlotContractWarnings) {
            $slotContractWarnings = $this->collectMissingSlotWarnings($area, $pageType, $this->detectLayoutOption());
            if (!empty($slotContractWarnings)) {
                $html = $this->getThemeSlotContractService()->injectMissingSlotWarningHtml($html, $slotContractWarnings);
                $this->getThemeSlotContractService()->notifyMissingDefaultSlots($slotContractWarnings, $area);
            }
        }

        if (!$hasSlotMarkers) {
            $event->setData('content', $this->finalizeFrontendHtml($html, $area));
            return;
        }

        // Non-editor preview: content renderer already filled wrappers — skip re-process
        // to avoid duplicates. Editor preview must still processSlots so CoW can drop
        // empty/shredded template shells and park wrapper inners through DOM safely.
        if ($this->isThemePreviewContentRequest($template)
            && $this->htmlHasRenderedWidgetWrappers($html)
            && !$this->shouldShowEditorSlotDiagnostics()
        ) {
            $html = $this->slotRenderer->finalizePreviewWidgetHealth($html);
            $event->setData('content', $this->finalizeFrontendHtml($html, $area));
            return;
        }

        // 鐢熶骇鐜妫€鏌ワ細濡傛灉鏈夌紦瀛樹笖涓嶆槸棰勮妯″紡锛屽彲浠ヨ烦杩囧疄鏃舵覆鏌?
        // 娉ㄦ剰锛氳繖閲屾垜浠粛鐒舵墽琛屽疄鏃舵覆鏌擄紝鍥犱负缂撳瓨妯℃澘搴旇鍦ㄦ洿楂樺眰绾у鐞?
        // 濡傛灉闇€瑕佽烦杩囷紝鍙栨秷涓嬮潰鐨勬敞閲?
        // if (!$isPreviewMode && !DEV && $this->cacheGenerator->isCacheValid($themeId)) {
        //     return;
        // }

        // 澶勭悊鎻掓Ы鏇挎崲
        $accountSidebarBefore = $this->htmlHasAccountSidebar($html);
        $processedHtml = $this->slotRenderer->processSlots($html, $themeId, $pageType, $status, $area);
        $accountSidebarAfter = $this->htmlHasAccountSidebar($processedHtml);
        if ($this->isAccountHtml($html) || $this->isAccountHtml($processedHtml)) {
            RequestLifecycleTrace::recordSpan('theme::layoutSlotRenderer::accountHtml', 0.0, 'theme', null, [
                'input_len' => \strlen($html),
                'output_len' => \strlen($processedHtml),
                'has_sidebar_before' => $accountSidebarBefore,
                'has_sidebar_after' => $accountSidebarAfter,
                'has_slot_markers' => $hasSlotMarkers,
                'theme_id' => $themeId,
                'page_type' => $pageType,
                'status' => $status,
            ]);
            $this->logAccountSidebarDebug('layout_slot_renderer', [
                'template' => $template,
                'input_len' => \strlen($html),
                'output_len' => \strlen($processedHtml),
                'has_sidebar_before' => $accountSidebarBefore,
                'has_sidebar_after' => $accountSidebarAfter,
                'has_slot_markers' => $hasSlotMarkers,
                'theme_id' => $themeId,
                'page_type' => $pageType,
                'status' => $status,
            ]);
            if ($accountSidebarBefore && !$accountSidebarAfter && \function_exists('w_log_warning')) {
                \w_log_warning('[AccountSidebar] slot renderer removed account sidebar', [
                    'uri' => (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''),
                    'input_len' => \strlen($html),
                    'output_len' => \strlen($processedHtml),
                    'theme_id' => $themeId,
                    'page_type' => $pageType,
                ], 'account_sidebar');
            }
        }
        // 妫€鏌ユ槸鍚︽湁瀛ゅ効閮ㄤ欢锛堟壘涓嶅埌瀵瑰簲鎻掓Ы鐨勯儴浠讹級
        // 杩欎簺閮ㄤ欢鐨勯厤缃暟鎹笉浼氳鍒犻櫎锛屽彧鏄棤娉曞湪褰撳墠甯冨眬涓樉绀?
        if ($this->slotRenderer->hasOrphanWidgets()) {
            $orphans = $this->slotRenderer->getOrphanWidgets();
            
            // 仅在主题编辑器可视化预览（iframe / 预览壳）下展示；真实前台预览不打扰访客。
            if ($this->shouldShowEditorSlotDiagnostics()) {
                $processedHtml = $this->injectOrphanWarnings($processedHtml, $orphans, $themeId, $pageType);
            }
            
            // 璁板綍璀﹀憡鏃ュ織锛堝彲閫夛級
            // 寮€鍙戞ā寮忎笅鍙互杈撳嚭鍒版帶鍒跺彴
            if (defined('DEV') && DEV) {
                foreach ($orphans as $orphan) {
                    w_log_warning('[Widget Orphan] ' . ($orphan['message'] ?? 'Unknown orphan widget'));
                }
            }
        }

        if ($this->slotRenderer->hasUnavailableWidgets()) {
            $unavailable = $this->slotRenderer->getUnavailableWidgets();
            if ($this->shouldShowEditorSlotDiagnostics()) {
                $processedHtml = $this->injectUnavailableWarnings($processedHtml, $unavailable, $themeId, $pageType);
            }
            if (defined('DEV') && DEV) {
                foreach ($unavailable as $item) {
                    w_log_warning('[Widget Unavailable] ' . ($item['message'] ?? 'Unknown unavailable widget'));
                }
            }
        } elseif ($this->shouldShowEditorSlotDiagnostics()
            && \str_contains($processedHtml, 'widget-unavailable-tip')
        ) {
            $this->slotRenderer->syncUnavailableWidgetsFromHtml($processedHtml);
            if ($this->slotRenderer->hasUnavailableWidgets()) {
                $unavailable = $this->slotRenderer->getUnavailableWidgets();
                $processedHtml = $this->injectUnavailableWarnings($processedHtml, $unavailable, $themeId, $pageType);
            }
        }

        // 鏇存柊浜嬩欢鏁版嵁锛坒etch_file_after 浜嬩欢浣跨敤 content锛?
        $processedHtml = $this->slotRenderer->finalizePreviewWidgetHealth($processedHtml);
        $event->setData('content', $this->finalizeFrontendHtml($processedHtml, $area));
    }

    private function finalizeFrontendHtml(string $html, string $area): string
    {
        if ($area !== 'frontend' || $html === '' || $this->isEditorIframePreviewRequest()) {
            return $html;
        }

        if (\defined('PROD') && PROD) {
            $html = SlotBoundaryMarkers::strip($html);
        }

        try {
            /** @var PreviewBootstrapAssetInjector $injector */
            $injector = ObjectManager::getInstance(PreviewBootstrapAssetInjector::class);

            return $injector->inject($html);
        } catch (\Throwable) {
            return $html;
        }
    }

    private function isEditorIframePreviewRequest(): bool
    {
        $editorMode = \trim((string)$this->request->getParam('editor_mode', ''));
        if ($editorMode !== '1' && \strtolower($editorMode) !== 'true') {
            return false;
        }

        $uri = \strtolower((string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''));

        return \str_contains($uri, 'theme/frontend/theme-preview/content');
    }

    private function shouldDebugAccountSidebar(): bool
    {
        try {
            return (string)$this->request->getGet('debug_sidebar', '') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private function logAccountSidebarDebug(string $stage, array $context = []): void
    {
        if (!$this->shouldDebugAccountSidebar()) {
            return;
        }

        try {
            $context += [
                'request_id' => (string)(\Weline\Framework\Runtime\RequestContext::getId() ?? ''),
                'uri' => (string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''),
                'lang' => (string)\Weline\Framework\App\State::getLang(),
                'lang_local' => (string)\Weline\Framework\App\State::getLangLocal(),
                'currency' => (string)\Weline\Framework\App\State::getCurrency(),
            ];
        } catch (\Throwable) {
        }

        \error_log('[AccountSidebarTrace] ' . $stage . ' ' . (\json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'));
    }

    private function isAccountHtml(string $html): bool
    {
        return \str_contains($html, 'account-dashboard-layout')
            || \str_contains($html, 'account-main-content')
            || \str_contains($html, 'account-index');
    }

    private function htmlHasAccountSidebar(string $html): bool
    {
        return \str_contains($html, 'class="account-sidebar')
            || \str_contains($html, "class='account-sidebar");
    }

    private function collectMissingSlotWarnings(string $area, string $layoutType = '', string $layoutOption = ''): array
    {
        try {
            $theme = $this->themeContext->resolveTheme($area, null, true);
            if (!$theme || !$theme->getId()) {
                return [];
            }

            $warnings = $this->getThemeSlotContractService()->collectMissingDefaultSlots($area, $theme);
            $logicalKey = $this->normalizeCurrentLayoutLogicalKey($layoutType, $layoutOption);
            if ($logicalKey !== '') {
                $warnings = \array_values(\array_filter(
                    $warnings,
                    static fn(array $warning): bool => (string)($warning['logical_key'] ?? '') === $logicalKey
                ));
            }

            return $warnings;
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV && function_exists('w_log_warning')) {
                \w_log_warning('[Theme Slot Missing] scan failed: ' . $e->getMessage());
            }
            return [];
        }
    }

    private function detectLayoutOption(): string
    {
        $layoutOption = \trim((string)$this->request->getParam('layout_option', ''));
        if ($layoutOption === '') {
            $layoutOption = \trim((string)$this->request->getParam('layout_code', ''));
        }

        return $layoutOption !== '' ? $layoutOption : 'default';
    }

    private function normalizeCurrentLayoutLogicalKey(string $layoutType, string $layoutOption): string
    {
        $layoutType = \trim(\str_replace('\\', '/', $layoutType), '/ ');
        $layoutOption = \trim(\str_replace('\\', '/', $layoutOption), '/ ');
        if ($layoutType === '') {
            return '';
        }
        if ($layoutOption === '') {
            $layoutOption = 'default';
        }

        return 'layouts/' . $layoutType . '/' . $layoutOption;
    }

    private function getThemeSlotContractService(): ThemeSlotContractService
    {
        if ($this->slotContractService instanceof ThemeSlotContractService) {
            return $this->slotContractService;
        }

        /** @var ThemeSlotContractService $service */
        $service = ObjectManager::getInstance(ThemeSlotContractService::class);
        $this->slotContractService = $service;
        return $service;
    }

    /**
     * 鍦ㄩ瑙堟ā寮忎笅娉ㄥ叆瀛ゅ効閮ㄤ欢璀﹀憡
     * 
     * 鍦ㄩ〉闈㈠簳閮ㄦ坊鍔犱竴涓鍛婇潰鏉匡紝鎻愮ず缂栬緫鑰呮湁浜涢儴浠舵棤娉曞湪褰撳墠甯冨眬涓樉绀?
     */
    private function injectOrphanWarnings(string $html, array $orphans, int $themeId, string $pageType): string
    {
        if (empty($orphans)) {
            return $html;
        }
        
        $warningItems = [];
        $orphanSlotIds = [];
        $orphanLayoutIds = [];
        foreach ($orphans as $orphan) {
            $widgetName = htmlspecialchars((string)($orphan['widget_name'] ?? '未知组件'));
            $slotId = htmlspecialchars((string)($orphan['slot_id'] ?? '未知插槽'));
            $warningItems[] = "<li><strong>{$widgetName}</strong> - 找不到插槽 <code>{$slotId}</code></li>";
            if (!empty($orphan['slot_id'])) {
                $orphanSlotIds[] = $orphan['slot_id'];
            }
            $layoutId = (int)($orphan['layout_id'] ?? 0);
            if ($layoutId > 0) {
                $orphanLayoutIds[] = $layoutId;
            }
        }
        
        $orphanSlotIdsJson = htmlspecialchars(json_encode(array_values(array_unique($orphanSlotIds))));
        $orphanLayoutIdsJson = htmlspecialchars(json_encode(array_values(array_unique($orphanLayoutIds))));
        $editorContext = $this->resolveOrphanDeleteEditorContext($themeId, $pageType);
        $editorContextJson = htmlspecialchars(
            json_encode($editorContext ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        
        // 鐢熸垚姝ｇ‘鐨勫悗鍙癠RL锛堥伒寰?weline-routing 鎶€鑳借鑼冿級
        $removeOrphanWidgetsUrl = htmlspecialchars($this->url->getBackendUrl('theme/backend/theme-editor/remove-orphan-widgets'));
        
        $warningHtml = <<<HTML
<div id="orphan-widgets-warning" data-editor-interactive style="
    position: fixed;
    bottom: 20px;
    right: 20px;
    max-width: 400px;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 2147483000;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 14px;
    pointer-events: auto;
">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <strong style="color: #856404;">组件警告</strong>
        <button id="btnDismissOrphanWarning" type="button" style="
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #856404;
        ">&times;</button>
    </div>
    <p style="margin: 0 0 10px 0; color: #856404;">以下组件无法在当前布局中生效（配置已保留）：</p>
    <ul style="margin: 0; padding-left: 20px; color: #856404;">
HTML;
        $warningHtml .= implode("\n", $warningItems);
        $warningHtml .= <<<HTML
    </ul>
    <p style="margin: 10px 0 5px 0; font-size: 12px; color: #856404;">
        提示：这些组件可能需要重新配置到新的插槽位置。
    </p>
    <div id="orphan-actions" style="display: flex; gap: 8px; margin-top: 10px;">
        <button id="btnConfirmDelete" data-orphan-slots='{$orphanSlotIdsJson}' data-orphan-layout-ids='{$orphanLayoutIdsJson}' data-editor-context='{$editorContextJson}' style="
            flex: 1;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        ">
            删除这些组件
        </button>
        <button id="btnDismissOrphanLater" type="button" style="
            flex: 1;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
        ">
            稍后处理
        </button>
    </div>
    <div id="confirm-message" style="display: none; margin-top: 10px; padding: 10px; background: #f8d7da; border-radius: 4px; color: #721c24; font-size: 13px;">
        <strong>确认删除</strong>
        <p style="margin: 5px 0;">此操作将永久删除这些无效组件配置，不可恢复。</p>
        <div style="display: flex; gap: 8px; margin-top: 8px;">
            <button id="btnConfirmYes" style="
                flex: 1;
                background: #dc3545;
                color: white;
                border: none;
                border-radius: 4px;
                padding: 6px 12px;
                cursor: pointer;
                font-size: 12px;
            ">
                确认删除
            </button>
            <button id="btnConfirmNo" style="
                flex: 1;
                background: #6c757d;
                color: white;
                border: none;
                border-radius: 4px;
                padding: 6px 12px;
                cursor: pointer;
                font-size: 12px;
            ">
                取消
            </button>
        </div>
    </div>
    <div id="delete-status" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px; font-size: 13px;"></div>
</div>
<script>
(function() {
    const btnConfirmDelete = document.getElementById('btnConfirmDelete');
    const confirmMessage = document.getElementById('confirm-message');
    const orphanActions = document.getElementById('orphan-actions');
    const btnConfirmYes = document.getElementById('btnConfirmYes');
    const btnConfirmNo = document.getElementById('btnConfirmNo');
    const btnDismissOrphanWarning = document.getElementById('btnDismissOrphanWarning');
    const btnDismissOrphanLater = document.getElementById('btnDismissOrphanLater');
    const deleteStatus = document.getElementById('delete-status');
    const warningPanel = document.getElementById('orphan-widgets-warning');

    function dismissWarningPanel() {
        if (warningPanel) {
            warningPanel.remove();
        }
    }

    if (btnDismissOrphanWarning) {
        btnDismissOrphanWarning.addEventListener('click', dismissWarningPanel);
    }

    if (btnDismissOrphanLater) {
        btnDismissOrphanLater.addEventListener('click', dismissWarningPanel);
    }
    
    // 鐐瑰嚮鍒犻櫎鎸夐挳 - 鏄剧ず纭娑堟伅
    if (btnConfirmDelete) {
        btnConfirmDelete.addEventListener('click', function() {
            orphanActions.style.display = 'none';
            confirmMessage.style.display = 'block';
        });
    }
    
    // 鍙栨秷鍒犻櫎
    if (btnConfirmNo) {
        btnConfirmNo.addEventListener('click', function() {
            confirmMessage.style.display = 'none';
            orphanActions.style.display = 'flex';
        });
    }
    
    // 纭鍒犻櫎
    if (btnConfirmYes) {
        btnConfirmYes.addEventListener('click', function() {
            const btn = document.querySelector('[data-orphan-slots]');
            if (!btn) return;
            
            const orphanSlots = JSON.parse(btn.getAttribute('data-orphan-slots') || '[]');
            const orphanLayoutIds = JSON.parse(btn.getAttribute('data-orphan-layout-ids') || '[]');
            const urlParams = new URLSearchParams(window.location.search);
            const themeId = urlParams.get('theme_id')
                || urlParams.get('frontend_theme_id')
                || urlParams.get('weline_theme_id')
                || '';
            const pageType = urlParams.get('page_type') || urlParams.get('layout_type') || 'homepage';
            const status = urlParams.get('status') || 'draft';
            const editorContext = (function resolveEditorContext() {
                const fromAttr = btn.getAttribute('data-editor-context') || '';
                if (fromAttr && fromAttr !== '{}' && fromAttr !== 'null') {
                    try {
                        const parsed = JSON.parse(fromAttr);
                        if (parsed && typeof parsed === 'object' && parsed.scope) {
                            return parsed;
                        }
                    } catch (error) {}
                }
                const raw = urlParams.get('editor_context') || '';
                if (!raw) {
                    return null;
                }
                try {
                    const parsed = JSON.parse(raw);
                    return parsed && typeof parsed === 'object' ? parsed : null;
                } catch (error) {
                    return null;
                }
            })();
            const payload = {
                theme_id: themeId,
                slot_ids: orphanSlots,
                layout_ids: orphanLayoutIds,
                page_type: pageType,
                status: status,
            };
            if (editorContext) {
                payload.editor_context = editorContext;
            }
            
            // 鏄剧ず澶勭悊涓?
            confirmMessage.style.display = 'none';
            deleteStatus.style.display = 'block';
            deleteStatus.style.background = '#d1ecf1';
            deleteStatus.style.color = '#0c5460';
            deleteStatus.textContent = '正在删除...';
            
            // 闃叉閲嶅鐐瑰嚮
            btnConfirmYes.disabled = true;
            
            // 鍙戣发起删除请求（携带 typed editor_context，避免 theme_editor_typed_scope_required）
            fetch('{$removeOrphanWidgetsUrl}', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    deleteStatus.style.background = '#d4edda';
                    deleteStatus.style.color = '#155724';
                    deleteStatus.textContent = '✓ ' + (data.message || '删除成功') + '，即将刷新...';
                    // 绔嬪嵆闅愯棌鏁翠釜璀﹀憡闈㈡澘
                    const panel = document.getElementById('orphan-widgets-warning');
                    if (panel) {
                        setTimeout(() => panel.remove(), 800);
                    }
                    setTimeout(() => {
                        const reloadUrl = new URL(window.location.href);
                        reloadUrl.searchParams.set('_t', String(Date.now()));
                        window.location.replace(reloadUrl.toString());
                    }, 1000);
                } else {
                    deleteStatus.style.background = '#f8d7da';
                    deleteStatus.style.color = '#721c24';
                    deleteStatus.textContent = '✗ ' + (data.message || '删除失败');
                    btnConfirmYes.disabled = false;
                }
            })
            .catch(error => {
                console.error('删除失败:', error);
                deleteStatus.style.background = '#f8d7da';
                deleteStatus.style.color = '#721c24';
                deleteStatus.textContent = '✗ 删除失败，请查看控制台';
                btnConfirmYes.disabled = false;
            });
        });
    }
})();
</script>
HTML;
        
        // 鍦?</body> 鍓嶆彃鍏ヨ鍛?
        if (strpos($html, '</body>') !== false) {
            $html = str_replace('</body>', $warningHtml . '</body>', $html);
        } else {
            // 濡傛灉娌℃湁 </body> 鏍囩锛岀洿鎺ヨ拷鍔犲埌鏈熬
            $html .= $warningHtml;
        }
        
        return $html;
    }

    /**
     * 编辑器预览：注入失效部件汇总面板（定义/模板缺失），按 node_uid 调用 remove-widget。
     *
     * @param list<array<string, mixed>> $unavailable
     */
    private function injectUnavailableWarnings(string $html, array $unavailable, int $themeId, string $pageType): string
    {
        if ($unavailable === []) {
            return $html;
        }

        $warningItems = [];
        $nodeUids = [];
        foreach ($unavailable as $item) {
            $widgetName = htmlspecialchars((string)($item['widget_name'] ?? $item['widget_code'] ?? '未知组件'));
            $slotId = htmlspecialchars((string)($item['slot_id'] ?? '未知插槽'));
            $code = htmlspecialchars((string)($item['widget_code'] ?? ''));
            $warningItems[] = "<li><strong>{$widgetName}</strong> (<code>{$code}</code>) — 插槽 <code>{$slotId}</code></li>";
            $nodeUid = strtolower(trim((string)($item['node_uid'] ?? '')));
            if ($nodeUid !== '' && preg_match('/^[a-f0-9]{32}$/D', $nodeUid) === 1) {
                $nodeUids[] = $nodeUid;
            }
        }

        $nodeUidsJson = htmlspecialchars(
            json_encode(array_values(array_unique($nodeUids)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $editorContext = $this->resolveOrphanDeleteEditorContext($themeId, $pageType);
        $editorContextJson = htmlspecialchars(
            json_encode($editorContext ?? new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $removeWidgetUrl = htmlspecialchars($this->url->getBackendUrl('theme/backend/theme-editor/remove-widget'));
        $bottomOffset = $this->slotRenderer->hasOrphanWidgets() ? '220px' : '20px';

        $warningHtml = <<<HTML
<div id="unavailable-widgets-warning" data-editor-interactive style="
    position: fixed;
    bottom: {$bottomOffset};
    right: 20px;
    max-width: 400px;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 2147483000;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 14px;
    pointer-events: auto;
">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <strong style="color: #856404;">失效部件</strong>
        <button id="btnDismissUnavailableWarning" type="button" data-editor-interactive style="
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #856404;
            pointer-events: auto;
        ">&times;</button>
    </div>
    <p style="margin: 0 0 10px 0; color: #856404;">以下部件定义或模板已缺失，仍留在当前布局配置中：</p>
    <ul style="margin: 0; padding-left: 20px; color: #856404;">
HTML;
        $warningHtml .= implode("\n", $warningItems);
        $warningHtml .= <<<HTML
    </ul>
    <p style="margin: 10px 0 5px 0; font-size: 12px; color: #856404;">
        可从当前草稿版本移除这些失效配置（不影响已发布版本）。
    </p>
    <div id="unavailable-actions" style="display: flex; gap: 8px; margin-top: 10px;">
        <button id="btnConfirmRemoveUnavailable" type="button" data-editor-interactive data-unavailable-node-uids='{$nodeUidsJson}' data-editor-context='{$editorContextJson}' style="
            flex: 1;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
            pointer-events: auto;
        ">
            从当前版本移除
        </button>
        <button id="btnDismissUnavailableLater" type="button" data-editor-interactive style="
            flex: 1;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 13px;
            pointer-events: auto;
        ">
            稍后处理
        </button>
    </div>
    <div id="unavailable-confirm-message" style="display: none; margin-top: 10px; padding: 10px; background: #f8d7da; border-radius: 4px; color: #721c24; font-size: 13px;">
        <strong>确认移除</strong>
        <p style="margin: 5px 0;">将从当前草稿版本删除这些失效部件配置，不可恢复。</p>
        <div style="display: flex; gap: 8px; margin-top: 8px;">
            <button id="btnUnavailableConfirmYes" type="button" data-editor-interactive style="
                flex: 1;
                background: #dc3545;
                color: white;
                border: none;
                border-radius: 4px;
                padding: 6px 12px;
                cursor: pointer;
                font-size: 12px;
                pointer-events: auto;
            ">
                确认移除
            </button>
            <button id="btnUnavailableConfirmNo" type="button" data-editor-interactive style="
                flex: 1;
                background: #6c757d;
                color: white;
                border: none;
                border-radius: 4px;
                padding: 6px 12px;
                cursor: pointer;
                font-size: 12px;
                pointer-events: auto;
            ">
                取消
            </button>
        </div>
    </div>
    <div id="unavailable-delete-status" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px; font-size: 13px;"></div>
</div>
<script>
(function() {
    const removeWidgetUrl = '{$removeWidgetUrl}';
    const panel = document.getElementById('unavailable-widgets-warning');
    const btnConfirm = document.getElementById('btnConfirmRemoveUnavailable');
    const confirmMessage = document.getElementById('unavailable-confirm-message');
    const actions = document.getElementById('unavailable-actions');
    const btnYes = document.getElementById('btnUnavailableConfirmYes');
    const btnNo = document.getElementById('btnUnavailableConfirmNo');
    const btnDismiss = document.getElementById('btnDismissUnavailableWarning');
    const btnLater = document.getElementById('btnDismissUnavailableLater');
    const deleteStatus = document.getElementById('unavailable-delete-status');

    function dismissPanel() {
        if (panel) panel.remove();
    }
    if (btnDismiss) btnDismiss.addEventListener('click', dismissPanel);
    if (btnLater) btnLater.addEventListener('click', dismissPanel);

    function resolveEditorContext(fromEl) {
        const urlParams = new URLSearchParams(window.location.search);
        if (fromEl) {
            const fromAttr = fromEl.getAttribute('data-editor-context') || '';
            if (fromAttr && fromAttr !== '{}' && fromAttr !== 'null') {
                try {
                    const parsed = JSON.parse(fromAttr);
                    if (parsed && typeof parsed === 'object' && parsed.scope) {
                        return parsed;
                    }
                } catch (e) {}
            }
        }
        if (btnConfirm) {
            const fromAttr = btnConfirm.getAttribute('data-editor-context') || '';
            if (fromAttr && fromAttr !== '{}' && fromAttr !== 'null') {
                try {
                    const parsed = JSON.parse(fromAttr);
                    if (parsed && typeof parsed === 'object' && parsed.scope) {
                        return parsed;
                    }
                } catch (e) {}
            }
        }
        const raw = urlParams.get('editor_context') || '';
        if (!raw) return null;
        try {
            const parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function themeIdFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('theme_id')
            || urlParams.get('frontend_theme_id')
            || urlParams.get('weline_theme_id')
            || '';
    }

    function pageTypeFromUrl() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('page_type') || urlParams.get('layout_type') || 'homepage';
    }

    function removeOneNodeUid(nodeUid, editorContext) {
        const payload = {
            theme_id: themeIdFromUrl(),
            node_uid: nodeUid,
            page_type: pageTypeFromUrl(),
            layout_type: pageTypeFromUrl(),
            status: 'draft',
        };
        if (editorContext) {
            payload.editor_context = editorContext;
        }
        return fetch(removeWidgetUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        }).then(function (response) { return response.json(); });
    }

    function reloadPreview() {
        const reloadUrl = new URL(window.location.href);
        reloadUrl.searchParams.set('_t', String(Date.now()));
        window.location.replace(reloadUrl.toString());
    }

    async function removeNodeUids(nodeUids, editorContext, statusEl, disableBtn) {
        if (!nodeUids.length) {
            if (statusEl) {
                statusEl.style.display = 'block';
                statusEl.style.background = '#f8d7da';
                statusEl.style.color = '#721c24';
                statusEl.textContent = '✗ 无可用 node_uid，无法从当前版本移除';
            }
            return false;
        }
        if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.style.background = '#d1ecf1';
            statusEl.style.color = '#0c5460';
            statusEl.textContent = '正在移除...';
        }
        if (disableBtn) disableBtn.disabled = true;
        let ok = 0;
        let lastError = '';
        for (let i = 0; i < nodeUids.length; i++) {
            try {
                const data = await removeOneNodeUid(nodeUids[i], editorContext);
                if (data && data.success) {
                    ok++;
                } else {
                    lastError = (data && data.message) ? data.message : '删除失败';
                }
            } catch (e) {
                lastError = '网络错误';
            }
        }
        if (ok > 0) {
            if (statusEl) {
                statusEl.style.background = '#d4edda';
                statusEl.style.color = '#155724';
                statusEl.textContent = '✓ 已从当前版本移除 ' + ok + ' 个失效部件，即将刷新...';
            }
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        source: 'weline-theme-preview',
                        type: 'layout-draft-mutated',
                        reason: 'remove-unavailable-widget',
                        removed: ok,
                    }, window.location.origin);
                }
            } catch (e) {}
            setTimeout(function () {
                if (panel) panel.remove();
            }, 800);
            setTimeout(reloadPreview, 1000);
            return true;
        }
        if (statusEl) {
            statusEl.style.background = '#f8d7da';
            statusEl.style.color = '#721c24';
            statusEl.textContent = '✗ ' + (lastError || '删除失败');
        }
        if (disableBtn) disableBtn.disabled = false;
        if (confirmMessage) confirmMessage.style.display = 'none';
        if (actions) actions.style.display = 'flex';
        return false;
    }

    if (btnConfirm) {
        btnConfirm.addEventListener('click', function () {
            if (actions) actions.style.display = 'none';
            if (confirmMessage) confirmMessage.style.display = 'block';
        });
    }
    if (btnNo) {
        btnNo.addEventListener('click', function () {
            if (confirmMessage) confirmMessage.style.display = 'none';
            if (actions) actions.style.display = 'flex';
        });
    }
    if (btnYes) {
        btnYes.addEventListener('click', function () {
            const nodeUids = JSON.parse((btnConfirm && btnConfirm.getAttribute('data-unavailable-node-uids')) || '[]');
            const editorContext = resolveEditorContext(btnConfirm);
            if (confirmMessage) confirmMessage.style.display = 'none';
            removeNodeUids(nodeUids, editorContext, deleteStatus, btnYes);
        });
    }

    document.addEventListener('click', function (event) {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const btn = target.closest('[data-action="remove-unavailable-widget"]');
        if (!btn) return;
        event.preventDefault();
        event.stopPropagation();
        const wrapper = btn.closest('.widget-wrapper');
        let nodeUid = '';
        if (btn.hasAttribute('data-node-uid')) {
            nodeUid = String(btn.getAttribute('data-node-uid') || '').toLowerCase();
        }
        if (!nodeUid && wrapper) {
            nodeUid = String(wrapper.getAttribute('data-node-uid') || '').toLowerCase();
        }
        if (!/^[a-f0-9]{32}$/.test(nodeUid)) {
            if (window.Weline && window.Weline.UI && window.Weline.UI.toast) {
                window.Weline.UI.toast.show('缺少 node_uid，无法从当前版本移除', { tone: 'warning', duration: 5000 });
            } else {
                console.warn('[UnavailableWidget] missing node_uid');
            }
            return;
        }
        if (!window.confirm('确认从当前草稿版本移除此失效部件？')) {
            return;
        }
        const editorContext = resolveEditorContext(btnConfirm);
        const statusEl = deleteStatus || document.createElement('div');
        removeNodeUids([nodeUid], editorContext, statusEl, btn);
    }, true);
})();
</script>
HTML;

        if (strpos($html, '</body>') !== false) {
            $html = str_replace('</body>', $warningHtml . '</body>', $html);
        } else {
            $html .= $warningHtml;
        }

        return $html;
    }

    /** @return array<string,mixed>|null */
    private function resolveOrphanDeleteEditorContext(int $themeId, string $pageType): ?array
    {
        $fromRequest = $this->decodeEditorContextValue($this->request->getParam('editor_context', null));
        if ($fromRequest !== null) {
            return $fromRequest;
        }

        if ($themeId <= 0) {
            return null;
        }

        $previewContext = $this->authoritativePreviewContext() ?? [];
        $fromPreview = $this->decodeEditorContextValue($previewContext['editor_context'] ?? null);
        if ($fromPreview !== null) {
            return $fromPreview;
        }

        $layoutIdentity = RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY);
        if ($layoutIdentity instanceof LayoutIdentity) {
            $scopeIdentity = $this->resolveOrphanDeleteScopeIdentity($previewContext);
            if ($scopeIdentity instanceof ScopeIdentity) {
                return $this->buildOrphanDeleteEditorContext(
                    $themeId,
                    $pageType,
                    $previewContext,
                    $layoutIdentity->layoutOption,
                    $layoutIdentity->localeCode !== '' ? $layoutIdentity->localeCode : 'default',
                    $layoutIdentity->targetType,
                    $layoutIdentity->targetId,
                    $scopeIdentity,
                );
            }
        }

        /** @var ThemeTargetIdentityResolver $targetResolver */
        $targetResolver = ObjectManager::getInstance(ThemeTargetIdentityResolver::class);
        [$targetType, $targetId] = $targetResolver->resolveFirst([
            [
                'target_type' => $previewContext['theme_layout_source_target_type'] ?? null,
                'target_id' => $previewContext['theme_layout_source_target_id'] ?? null,
            ],
            [
                'target_type' => $previewContext['theme_layout_target_type'] ?? null,
                'target_id' => $previewContext['theme_layout_target_id'] ?? null,
            ],
            [
                'target_type' => $this->request->getParam('theme_layout_source_target_type'),
                'target_id' => $this->request->getParam('theme_layout_source_target_id'),
            ],
            [
                'target_type' => $this->request->getParam('theme_layout_target_type'),
                'target_id' => $this->request->getParam('theme_layout_target_id'),
            ],
        ], true);
        if ($targetType === '') {
            $targetType = ThemeVirtualLayout::TARGET_GLOBAL;
            $targetId = 0;
        }

        $scopeIdentity = $this->resolveOrphanDeleteScopeIdentity($previewContext);
        if (!$scopeIdentity instanceof ScopeIdentity) {
            return null;
        }

        $locale = \trim((string)($previewContext['locale'] ?? $this->request->getParam('locale', '')));

        return $this->buildOrphanDeleteEditorContext(
            $themeId,
            $pageType,
            $previewContext,
            $this->detectLayoutOption(),
            $locale !== '' ? $locale : 'default',
            $targetType,
            $targetId,
            $scopeIdentity,
        );
    }

    /** @return array<string,mixed>|null */
    private function decodeEditorContextValue(mixed $raw): ?array
    {
        if (\is_string($raw) && $raw !== '') {
            try {
                $decoded = \json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                if (\is_array($decoded) && $this->isTypedEditorContext($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable) {
            }

            return null;
        }

        if (\is_array($raw) && $this->isTypedEditorContext($raw)) {
            return $raw;
        }

        return null;
    }

    /** @param array<string,mixed> $context */
    private function isTypedEditorContext(array $context): bool
    {
        $scope = $context['scope'] ?? null;
        if (\is_string($scope) && $scope !== '') {
            try {
                $scope = \json_decode($scope, true, flags: JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return false;
            }
        }

        return \is_array($scope)
            && (\is_array($scope['identity'] ?? null) || isset($scope['scope_kind']));
    }

    /** @param array<string,mixed> $previewContext */
    private function resolveOrphanDeleteScopeIdentity(array $previewContext): ?ScopeIdentity
    {
        $scopeIdentity = RequestContext::scopeIdentity();
        if ($scopeIdentity instanceof ScopeIdentity) {
            return $scopeIdentity;
        }

        $storageScope = \trim((string)($previewContext['scope'] ?? $this->request->getParam('scope', '')));
        if ($storageScope === '') {
            return ScopeIdentity::global();
        }

        try {
            /** @var ScopeHierarchyInterface $scopes */
            $scopes = ObjectManager::getInstance(ScopeHierarchyInterface::class);
            return $scopes->fromStorageScope($storageScope, true) ?? ScopeIdentity::global();
        } catch (\Throwable) {
            return ScopeIdentity::global();
        }
    }

    /**
     * @param array<string,mixed> $previewContext
     * @return array<string,mixed>
     */
    private function buildOrphanDeleteEditorContext(
        int $themeId,
        string $pageType,
        array $previewContext,
        string $layoutOption,
        string $locale,
        string $targetType,
        int $targetId,
        ScopeIdentity $scopeIdentity,
    ): array {
        /** @var PreviewContextService $previewService */
        $previewService = ObjectManager::getInstance(PreviewContextService::class);
        $area = $previewService->normalizeArea((string)($previewContext['editor_area'] ?? PreviewContextService::AREA_FRONTEND));

        return [
            'scope' => ['identity' => $scopeIdentity->toArray()],
            'area' => $area,
            'resource_type' => 'layout',
            'theme_id' => $themeId,
            'layout_type' => $pageType !== '' ? $pageType : 'homepage',
            'layout_option' => $layoutOption !== '' ? $layoutOption : 'default',
            'locale' => $locale !== '' ? $locale : 'default',
            'target_type' => $targetType !== '' ? $targetType : ThemeVirtualLayout::TARGET_GLOBAL,
            'target_id' => \max(0, $targetId),
        ];
    }

    /**
     * 妫€娴嬭鍔犺浇鐨勫竷灞€鐘舵€佺増鏈?
     * 
     * 浼樺厛绾э細
     * 1. URL 鍙傛暟 status=draft/published锛堟渶楂樹紭鍏堢骇锛岀敤浜庣増鏈垏鎹級
     * 2. 棰勮 Token锛圲RL鍙傛暟/Cookie/Header锛? 鏂板
     * 3. editor_mode=1锛堝悗鍙扮紪杈戝櫒 iframe锛岄粯璁ゅ姞杞?draft锛?
     * 4. preview_mode=1锛堝墠鍙拌崏绋块瑙堬紝鍔犺浇 draft锛?
     * 5. 榛樿锛堝墠鍙版甯歌闂紝鍔犺浇 published锛?
     * 
     * @return string ThemeLayout::STATUS_DRAFT 鎴?ThemeLayout::STATUS_PUBLISHED
     */
    private function detectStatus(): string
    {
        $context = $this->authoritativePreviewContext();
        if ($context !== null) {
            return ($context['status'] ?? ThemeLayout::STATUS_DRAFT) === ThemeLayout::STATUS_PUBLISHED
                ? ThemeLayout::STATUS_PUBLISHED
                : ThemeLayout::STATUS_DRAFT;
        }
        
        // 榛樿锛氬墠鍙版甯歌闂紝鍔犺浇宸插彂甯冪増鏈?
        return ThemeLayout::STATUS_PUBLISHED;
    }
    
    /**
     * 妫€娴嬫槸鍚︿负缂栬緫鍣ㄦ垨棰勮妯″紡
     * 
     * 鐢ㄤ簬鍒ゆ柇鏄惁闇€瑕佹樉绀鸿皟璇曚俊鎭紙濡傚鍎块儴浠惰鍛婏級鍜屾敞鍏ラ瑙堟诞绐?
     * 
     * @return bool
     */
    private function isEditorOrPreviewMode(): bool
    {
        return $this->authoritativePreviewContext() !== null;
    }

    /**
     * 组件/插槽诊断仅面向主题编辑器可视化预览，真实前台预览不注入。
     */
    private function shouldShowEditorSlotDiagnostics(): bool
    {
        if (!$this->isEditorOrPreviewMode()) {
            return false;
        }

        if ($this->previewRequestInspector->isEditorMode()) {
            return true;
        }

        return $this->previewRequestInspector->isPreviewShellPath();
    }

    /** @return array<string,mixed>|null */
    private function authoritativePreviewContext(): ?array
    {
        try {
            /** @var PreviewContextService $service */
            $service = ObjectManager::getInstance(PreviewContextService::class);
            if (!$service->hasAuthoritativePreviewContext()) {
                return null;
            }

            return $service->getCurrentContext();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 妫€娴嬪尯鍩燂紙鍓嶇/鍚庣锛?     */
    private function detectArea(string $template): string
    {
        // 鍚庣妯℃澘鐗瑰緛
        $backendPatterns = [
            '/backend/',
            '/Backend/',
            'Backend::',
        ];

        foreach ($backendPatterns as $pattern) {
            if (strpos($template, $pattern) !== false) {
                return 'backend';
            }
        }

        return 'frontend';
    }

    private function isBackendDashboardSlotRequest(string $template): bool
    {
        $pageType = strtolower(trim((string)$this->request->getParam('page_type', '')));
        $layoutType = strtolower(trim((string)$this->request->getParam('layout_type', '')));
        $isDashboardLayoutRequest = $pageType === ThemeLayout::PAGE_TYPE_DASHBOARD
            || $layoutType === ThemeLayout::PAGE_TYPE_DASHBOARD;

        $normalizedTemplate = strtolower(str_replace('\\', '/', $template));
        if (str_contains($normalizedTemplate, '/layouts/dashboard/')
            || str_contains($normalizedTemplate, 'theme/backend/layouts/dashboard/')) {
            return true;
        }

        $uri = strtolower((string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''));
        if (str_contains($uri, 'dashboard/backend/dashboard')) {
            return true;
        }

        if (!$isDashboardLayoutRequest) {
            return false;
        }

        return str_contains($uri, 'theme/backend/theme-editor/layout-preview')
            || str_contains($uri, 'theme/backend/theme-editor/compile-layout')
            || str_contains($uri, 'theme/backend/theme-editor/get-compile-layout');
    }

    private function isThemePreviewContentTemplate(string $template): bool
    {
        $normalized = \strtolower(\str_replace('\\', '/', $template));

        return \str_contains($normalized, 'templates/frontend/theme-preview/content.phtml')
            || \str_contains($normalized, 'templates/backend/theme-preview/content.phtml');
    }

    private function isThemePreviewContentRequest(string $template): bool
    {
        if ($this->isThemePreviewContentTemplate($template)) {
            return true;
        }

        $uri = \strtolower((string)($this->request->getServer('REQUEST_URI') ?? $this->request->getUri() ?? ''));

        return \str_contains($uri, 'theme/frontend/theme-preview/content')
            || \str_contains($uri, 'theme/backend/theme-preview/content');
    }

    private function htmlHasRenderedWidgetWrappers(string $html): bool
    {
        return \str_contains($html, 'class="widget-wrapper"')
            || \str_contains($html, "class='widget-wrapper'")
            || \str_contains($html, 'data-node-uid=')
            || \str_contains($html, 'data-layout-id=');
    }

    /**
     * 鍒ゆ柇鏄惁涓哄竷灞€妯℃澘
     */
    private function isLayoutTemplate(string $template): bool
    {
        // 甯冨眬妯℃澘璺緞鐗瑰緛
        $layoutPatterns = [
            '/layouts/',
            'layouts::',
            '/layout/',
        ];

        foreach ($layoutPatterns as $pattern) {
            if (strpos($template, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 浠庢ā鏉胯矾寰勬娴嬮〉闈㈢被鍨?
     * 
     * 灏嗘ā鏉跨洰褰曞悕鏄犲皠鍒版暟鎹簱涓殑椤甸潰绫诲瀷
     */
    private function detectPageType(string $template): string
    {
        $requestLayoutType = $this->pageTypeResolver->resolveLayoutType(
            null,
            null,
            $this->request,
            ''
        );
        if ($requestLayoutType !== '') {
            return $this->pageTypeResolver->mapLayoutTypeToPageType($requestLayoutType);
        }

        $templateLayoutType = $this->extractLayoutTypeFromTemplate($template);
        if ($templateLayoutType !== '') {
            return $this->pageTypeResolver->mapLayoutTypeToPageType($templateLayoutType);
        }

        return ThemeLayout::PAGE_TYPE_DEFAULT;
    }

    private function resolveThemeId(string $area): int
    {
        try {
            $theme = $this->themeContext->resolveTheme($area, null, true);
            return (int)($theme?->getId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    private function extractLayoutTypeFromTemplate(string $template): string
    {
        if (preg_match('/layouts[\/\\\\]([\w-]+)[\/\\\\]/', $template, $matches)) {
            return (string)$matches[1];
        }

        return '';
    }

    /**
     * 鍚敤/绂佺敤澶勭悊鍣?
     */
    public function setEnabled(bool $enabled): void
    {
        $this->isEnabled = $enabled;
    }
    
    /**
     * 娉ㄥ叆棰勮閫€鍑烘诞绐?
     * 
     * 鍦ㄩ瑙堟ā寮忎笅锛屽湪椤甸潰搴曢儴鍙充晶娉ㄥ叆涓€涓彲鎷栧姩鐨勬诞绐楋紝
     * 鎻愪緵"閫€鍑洪瑙?鍜?鍙戝竷骞堕€€鍑?涓や釜鎿嶄綔
     * 
     * @param string $html 鍘熷 HTML
     * @return string 娉ㄥ叆娴獥鍚庣殑 HTML
     */
    private function injectPreviewExitButton(string $html): string
    {
        $token = $this->previewTokenService->getTokenFromRequest() ?? '';
        
        // 前台预览网关退出（Token 鉴权，已注册路由，无需后台登录）
        $exitPreviewUrl = $this->url->getFrontendUrl('theme/frontend/theme-preview/gateway', ['exit' => '1']);
        $publishAndExitUrl = $this->url->getBackendUrl('theme/backend/theme-editor/publish-and-exit');
        $previewMessageJsonFlags = \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT;
        $previewExitFailedJson = \json_encode((string)__('退出预览失败'), $previewMessageJsonFlags) ?: '"退出预览失败"';
        $previewPublishFailedJson = \json_encode((string)__('发布失败'), $previewMessageJsonFlags) ?: '"发布失败"';
        $previewNetworkErrorJson = \json_encode((string)__('网络错误，请重试'), $previewMessageJsonFlags) ?: '"网络错误，请重试"';
        $previewConfirmPublishJson = \json_encode((string)__('确认发布当前预览内容并退出？发布后，所有访客将看到最新更改。'), $previewMessageJsonFlags) ?: '"确认发布当前预览内容并退出？发布后，所有访客将看到最新更改。"';
        $previewConfirmOkJson = \json_encode((string)__('确认发布'), $previewMessageJsonFlags) ?: '"确认发布"';
        $previewConfirmCancelJson = \json_encode((string)__('取消'), $previewMessageJsonFlags) ?: '"取消"';
        $previewConfirmTitleJson = \json_encode((string)__('发布预览'), $previewMessageJsonFlags) ?: '"发布预览"';
        $tokenJson = \json_encode((string)$token, $previewMessageJsonFlags) ?: '""';
        $exitPreviewUrlJson = \json_encode((string)$exitPreviewUrl, $previewMessageJsonFlags) ?: '""';
        $publishAndExitUrlJson = \json_encode((string)$publishAndExitUrl, $previewMessageJsonFlags) ?: '""';
        
        // 娴獥 HTML 鍜屽唴鑱旀牱寮?鑴氭湰
        $floatHtml = <<<HTML
<!-- Weline Theme Preview Exit Button -->
<div id="weline-preview-exit-float" style="
    position: fixed !important;
    bottom: 20px !important;
    right: 20px !important;
    left: auto !important;
    top: auto !important;
    z-index: 2147483647 !important;
    background: linear-gradient(135deg, var(--backend-color-gradient-start, #667eea) 0%, var(--backend-color-gradient-end, #764ba2) 100%) !important;
    border-radius: 12px !important;
    box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4) !important;
    padding: 12px 16px !important;
    cursor: move !important;
    user-select: none !important;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
    min-width: 140px !important;
    transition: transform 0.2s, box-shadow 0.2s !important;
    margin: 0 !important;
    float: none !important;
    display: block !important;
    width: auto !important;
    height: auto !important;
">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px; color: white;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 16v-4M12 8h.01"/>
        </svg>
        <span style="font-weight: 600; font-size: 14px;">预览模式</span>
    </div>
    <div style="display: flex; flex-direction: column; gap: 8px;">
        <button id="weline-preview-exit-btn" style="
            padding: 8px 16px;
            background: rgba(255,255,255,0.95);
            color: #667eea;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            width: 100%;
        ">
            退出预览
        </button>
        <button id="weline-preview-publish-btn" style="
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            width: 100%;
        ">
            发布并退出
        </button>
    </div>
    <div style="
        position: absolute;
        top: -8px;
        left: 50%;
        transform: translateX(-50%);
        width: 40px;
        height: 4px;
        background: rgba(255,255,255,0.5);
        border-radius: 2px;
    "></div>
</div>
<script>
(function() {
    var floatEl = document.getElementById('weline-preview-exit-float');
    var exitBtn = document.getElementById('weline-preview-exit-btn');
    var publishBtn = document.getElementById('weline-preview-publish-btn');
    var token = {$tokenJson};
    var exitUrl = {$exitPreviewUrlJson};
    var publishUrl = {$publishAndExitUrlJson};
    var previewMessages = {
        exitFailed: {$previewExitFailedJson},
        publishFailed: {$previewPublishFailedJson},
        networkError: {$previewNetworkErrorJson},
        confirmPublish: {$previewConfirmPublishJson},
        confirmOk: {$previewConfirmOkJson},
        confirmCancel: {$previewConfirmCancelJson},
        confirmTitle: {$previewConfirmTitleJson}
    };

    function showPreviewMessage(message, type) {
        var finalType = type === 'success' || type === 'warning' || type === 'info' ? type : 'error';
        var finalMessage = String(message || '');
        try {
            if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast[finalType] === 'function') {
                window.Weline.UI.toast[finalType](finalMessage);
                return;
            }
        } catch (e) {}

        var toast = document.createElement('div');
        toast.setAttribute('role', 'status');
        toast.textContent = finalMessage;
        toast.style.cssText = [
            'position:fixed',
            'right:20px',
            'bottom:100px',
            'z-index:2147483647',
            'max-width:320px',
            'padding:12px 16px',
            'border-radius:8px',
            'box-shadow:0 8px 24px rgba(15,23,42,0.2)',
            'background:' + (finalType === 'success' ? '#059669' : finalType === 'warning' ? '#d97706' : finalType === 'info' ? '#2563eb' : '#dc2626'),
            'color:#fff',
            'font:500 13px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif'
        ].join(';');
        document.body.appendChild(toast);
        window.setTimeout(function() {
            toast.remove();
        }, 3800);
    }

    function confirmPreviewAction(message) {
        try {
            if (window.Weline && window.Weline.UI && window.Weline.UI.dialog && typeof window.Weline.UI.dialog.confirm === 'function') {
                return Promise.resolve(window.Weline.UI.dialog.confirm(message));
            }
        } catch (e) {}

        return new Promise(function(resolve) {
            var overlay = document.createElement('div');
            overlay.style.cssText = [
                'position:fixed',
                'inset:0',
                'z-index:2147483647',
                'display:flex',
                'align-items:center',
                'justify-content:center',
                'background:rgba(15,23,42,0.45)',
                'padding:20px'
            ].join(';');

            var dialog = document.createElement('div');
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.style.cssText = [
                'width:min(420px,100%)',
                'border-radius:10px',
                'background:#fff',
                'box-shadow:0 24px 60px rgba(15,23,42,0.3)',
                'padding:20px',
                'font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif',
                'color:#0f172a'
            ].join(';');

            var title = document.createElement('h3');
            title.textContent = previewMessages.confirmTitle;
            title.style.cssText = 'margin:0 0 10px;font-size:18px;line-height:1.3;';
            var body = document.createElement('p');
            body.textContent = String(message || '');
            body.style.cssText = 'margin:0 0 18px;color:#475569;';

            var actions = document.createElement('div');
            actions.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;';

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.textContent = previewMessages.confirmCancel;
            cancelBtn.style.cssText = 'padding:8px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;cursor:pointer;';

            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.textContent = previewMessages.confirmOk;
            okBtn.style.cssText = 'padding:8px 14px;border:1px solid #2563eb;border-radius:8px;background:#2563eb;color:#fff;cursor:pointer;';

            function close(value) {
                overlay.remove();
                resolve(value);
            }

            cancelBtn.addEventListener('click', function() { close(false); });
            okBtn.addEventListener('click', function() { close(true); });
            overlay.addEventListener('click', function(event) {
                if (event.target === overlay) {
                    close(false);
                }
            });

            actions.appendChild(cancelBtn);
            actions.appendChild(okBtn);
            dialog.appendChild(title);
            dialog.appendChild(body);
            dialog.appendChild(actions);
            overlay.appendChild(dialog);
            document.body.appendChild(overlay);
            okBtn.focus();
        });
    }

    function clearPreviewClientState() {
        document.cookie = 'weline_preview_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        try { localStorage.removeItem('weline_preview_float_pos'); } catch (e) {}
        try { sessionStorage.removeItem('weline_live_preview_token'); } catch (e) {}
        try {
            if (window.WelineThemePreviewBootstrap && typeof window.WelineThemePreviewBootstrap.clearClientToken === 'function') {
                window.WelineThemePreviewBootstrap.clearClientToken();
            }
        } catch (e) {}
    }

    function stripPreviewTokenFromUrl() {
        try {
            var url = new URL(window.location.href);
            var changed = false;
            if (url.searchParams.has('weline_preview_token')) {
                url.searchParams.delete('weline_preview_token');
                changed = true;
            }
            return changed ? url.toString() : '';
        } catch (e) {
            return '';
        }
    }

    function buildExitRedirectTarget() {
        var cleanUrl = stripPreviewTokenFromUrl();
        if (cleanUrl) {
            try {
                var parsed = new URL(cleanUrl, window.location.origin);
                return parsed.pathname + parsed.search + parsed.hash;
            } catch (e) {
                return cleanUrl;
            }
        }
        try {
            var current = new URL(window.location.href);
            current.searchParams.delete('weline_preview_token');
            return current.pathname + current.search + current.hash;
        } catch (e) {
            return window.location.pathname + window.location.search + window.location.hash;
        }
    }

    function buildExitNavigateUrl() {
        var target = buildExitRedirectTarget();
        try {
            var gateway = new URL(exitUrl, window.location.origin);
            gateway.searchParams.set('exit', '1');
            gateway.searchParams.set('redirect', target);
            if (token) {
                gateway.searchParams.set('token', token);
            }
            return gateway.toString();
        } catch (e) {
            var joinChar = exitUrl.indexOf('?') >= 0 ? '&' : '?';
            return exitUrl + joinChar + 'exit=1&redirect=' + encodeURIComponent(target)
                + (token ? '&token=' + encodeURIComponent(token) : '');
        }
    }

    function notifyParentPreviewExit() {
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    source: 'weline-theme-preview',
                    type: 'preview-exit'
                }, window.location.origin);
            }
        } catch (e) {}
    }

    /**
     * 退出预览：清客户端 token 态，再 GET gateway?exit=1 让服务端清 HttpOnly Cookie。
     * 不因 parent!==window 提前 return（内嵌壳会卡死）；也不做 POST/form 多重跳转。
     */
    function navigateExitPreview() {
        clearPreviewClientState();
        try { notifyParentPreviewExit(); } catch (e) {}
        window.location.replace(buildExitNavigateUrl());
    }

    function finishExit() {
        navigateExitPreview();
    }

    function unwrapPreviewPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return {};
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'success')) {
            return payload;
        }
        if (payload.data && typeof payload.data === 'object' && Object.prototype.hasOwnProperty.call(payload.data, 'success')) {
            return payload.data;
        }
        return payload;
    }

    function parsePreviewJson(response) {
        return response.text().then(function(text) {
            var trimmed = String(text || '').trim();
            if (!trimmed) {
                throw new Error('empty');
            }
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                if (/data-login-form|管理员登录|admin\\/login|login-form/i.test(trimmed)) {
                    throw new Error('login');
                }
                throw new Error('non-json:' + response.status);
            }
        });
    }

    function requestExitPreview() {
        var body = JSON.stringify({ token: token });
        return fetch(exitUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            credentials: 'include',
            body: body
        }).then(parsePreviewJson);
    }
    
    if (!floatEl || !exitBtn || !publishBtn) {
        return;
    }
    // 鎷栧姩鍔熻兘
    var isDragging = false;
    var startX, startY, startLeft, startBottom;
    
    // 浠?localStorage 鎭㈠浣嶇疆
    var savedPos = localStorage.getItem('weline_preview_float_pos');
    if (savedPos) {
        try {
            var pos = JSON.parse(savedPos);
            floatEl.style.right = pos.right + 'px';
            floatEl.style.bottom = pos.bottom + 'px';
        } catch(e) {}
    }
    
    floatEl.addEventListener('mousedown', function(e) {
        if (e.target.tagName === 'BUTTON') return;
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        startLeft = floatEl.offsetLeft;
        startBottom = window.innerHeight - floatEl.offsetTop - floatEl.offsetHeight;
        floatEl.style.transition = 'none';
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        var dx = e.clientX - startX;
        var dy = e.clientY - startY;
        var newRight = window.innerWidth - startLeft - floatEl.offsetWidth - dx;
        var newBottom = startBottom - dy;
        
        // 杈圭晫闄愬埗
        newRight = Math.max(10, Math.min(newRight, window.innerWidth - floatEl.offsetWidth - 10));
        newBottom = Math.max(10, Math.min(newBottom, window.innerHeight - floatEl.offsetHeight - 10));
        
        floatEl.style.right = newRight + 'px';
        floatEl.style.bottom = newBottom + 'px';
        floatEl.style.left = 'auto';
        floatEl.style.top = 'auto';
    });
    
    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            floatEl.style.transition = 'transform 0.2s, box-shadow 0.2s';
            // 淇濆瓨浣嶇疆鍒?localStorage
            localStorage.setItem('weline_preview_float_pos', JSON.stringify({
                right: parseInt(floatEl.style.right),
                bottom: parseInt(floatEl.style.bottom)
            }));
        }
    });
    
    // 退出预览：清 token 客户端态后跳 gateway?exit=1
    exitBtn.addEventListener('click', function(event) {
        try { event.preventDefault(); event.stopPropagation(); } catch (e) {}
        if (exitBtn.disabled) {
            return;
        }
        exitBtn.disabled = true;
        exitBtn.textContent = '处理中...';
        navigateExitPreview();
    });
    
    // 鍙戝竷骞堕€€鍑烘寜閽?
    publishBtn.addEventListener('click', function() {
        confirmPreviewAction(previewMessages.confirmPublish).then(function(confirmed) {
            if (!confirmed) {
                return;
            }
        
        publishBtn.disabled = true;
        publishBtn.textContent = '发布中...';

        var publishBody = JSON.stringify({ token: token });
        var publishPromise;
        if (window.Weline && window.Weline.Api && typeof window.Weline.Api.resource === 'function') {
            publishPromise = Promise.resolve(window.Weline.Api.resource('theme')).then(function(api) {
                if (!api || typeof api.editorRequest !== 'function') {
                    throw new Error('no-editor-request');
                }
                return api.editorRequest({
                    url: publishUrl,
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: publishBody
                });
            });
        } else {
            publishPromise = fetch(publishUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'include',
                body: publishBody
            }).then(parsePreviewJson);
        }

        publishPromise
        .then(function(payload) {
            var data = unwrapPreviewPayload(payload);
            if (data && data.success) {
                clearPreviewClientState();
                window.location.href = (data.redirect_url || (data.data && data.data.redirect_url) || '/');
            } else {
                showPreviewMessage((data && data.message) || previewMessages.publishFailed, 'error');
                publishBtn.disabled = false;
                publishBtn.textContent = '发布并退出';
            }
        })
        .catch(function(err) {
            console.error('[WelinePreview] publish-and-exit failed:', err);
            showPreviewMessage(previewMessages.networkError, 'error');
            publishBtn.disabled = false;
            publishBtn.textContent = '发布并退出';
        });
        });
    });
})();
</script>
<!-- /Weline Theme Preview Exit Button -->
HTML;
        
        // 鍦?</body> 鍓嶆彃鍏ユ诞绐?HTML
        if (stripos($html, '</body>') !== false) {
            $html = str_ireplace('</body>', $floatHtml . '</body>', $html);
        } else {
            // 濡傛灉娌℃湁 </body> 鏍囩锛岃拷鍔犲埌鏈熬
            $html .= $floatHtml;
        }
        
        return $html;
    }
    
    /**
     * 娉ㄥ叆棰勮璇锋眰鎷︽埅鍣?
     * 
     * 鎷︽埅鎵€鏈?fetch 鍜?XMLHttpRequest 璇锋眰锛岃嚜鍔ㄦ坊鍔犻瑙?token header锛?
     * 纭繚鏁翠釜棰勮浼氳瘽涓墍鏈?AJAX 璇锋眰閮芥惡甯﹂瑙堟爣璇?
     * 
     * @param string $html 鍘熷 HTML
     * @return string 娉ㄥ叆鎷︽埅鍣ㄥ悗鐨?HTML
     */
    private function injectPreviewInterceptor(string $html): string
    {
        $token = $this->previewTokenService->getTokenFromRequest() ?? '';
        $tokenHeader = PreviewTokenService::TOKEN_HEADER;
        $tokenKey = PreviewTokenService::TOKEN_KEY;
        
        if (empty($token)) {
            return $html;
        }
        
        $interceptorScript = <<<HTML
<!-- Weline Theme Preview Request Interceptor -->
<script>
(function() {
    var previewToken = '{$token}';
    var tokenHeader = '{$tokenHeader}';
    var tokenKey = '{$tokenKey}';
    
    // 鎷︽埅 fetch 璇锋眰
    var originalFetch = window.fetch;
    window.fetch = function(input, init) {
        init = init || {};
        init.headers = init.headers || {};
        
        // 娣诲姞棰勮 token header
        if (init.headers instanceof Headers) {
            init.headers.set(tokenHeader, previewToken);
        } else if (Array.isArray(init.headers)) {
            init.headers.push([tokenHeader, previewToken]);
        } else {
            init.headers[tokenHeader] = previewToken;
        }
        
        // 纭繚鎼哄甫 credentials锛堜互鍙戦€?Cookie锛?
        if (!init.credentials) {
            init.credentials = 'same-origin';
        }
        
        return originalFetch.call(this, input, init);
    };
    
    // 鎷︽埅 XMLHttpRequest
    var originalXHROpen = XMLHttpRequest.prototype.open;
    var originalXHRSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
        this._previewIntercepted = true;
        return originalXHROpen.apply(this, arguments);
    };
    
    XMLHttpRequest.prototype.send = function(body) {
        if (this._previewIntercepted) {
            this.setRequestHeader(tokenHeader, previewToken);
        }
        return originalXHRSend.apply(this, arguments);
    };
    
    // 涓哄姩鎬佸垱寤虹殑閾炬帴娣诲姞棰勮鍙傛暟
    document.addEventListener('click', function(e) {
        var link = e.target.closest('a');
        if (link && link.href && link.href.indexOf(window.location.origin) === 0) {
            // 濡傛灉閾炬帴娌℃湁棰勮 token锛屾坊鍔犲畠
            if (link.href.indexOf(tokenKey + '=') === -1) {
                var separator = link.href.indexOf('?') !== -1 ? '&' : '?';
                // 涓嶄慨鏀?href锛岃€屾槸鍦ㄥ鑸椂娣诲姞锛堥伩鍏嶅奖鍝嶆樉绀猴級
            }
        }
    }, true);
    
    console.log('[Weline Preview] 璇锋眰鎷︽埅鍣ㄥ凡鍚敤锛孴oken:', previewToken.substring(0, 20) + '...');
})();
</script>
<!-- /Weline Theme Preview Request Interceptor -->
HTML;
        
        // 鍦?<head> 缁撴潫鍓嶆垨 <body> 寮€濮嬪悗灏芥棭娉ㄥ叆
        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $interceptorScript . '</head>', $html);
        } elseif (stripos($html, '<body') !== false) {
            // 鍦?<body> 鏍囩鍚庢敞鍏?
            $html = preg_replace('/(<body[^>]*>)/i', '$1' . $interceptorScript, $html, 1);
        } else {
            // 鍦ㄥ紑澶存敞鍏?
            $html = $interceptorScript . $html;
        }
        
        return $html;
    }
}
