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
        $memoryProbe = static function (string $phase, mixed $content = null): void {
            if ((string)\getenv('WELINE_DIAG_MEMORY') !== '1') {
                return;
            }
            \error_log('[MemoryProbe] ' . \json_encode([
                'component' => 'LayoutSlotRenderer',
                'phase' => $phase,
                'content_bytes' => \is_string($content) ? \strlen($content) : null,
                'usage' => \memory_get_usage(true),
                'peak' => \memory_get_peak_usage(true),
            ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        };
        $memoryProbe('start', $html);
        
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
            // Editor canvas (editor_mode=1) must not inherit start-preview Token identity
            // (same rule as ThemePreview\Content) — install typed editor_context instead.
            if ($this->isEditorCanvasRequest()) {
                $this->bootstrapEditorCanvasIdentity($template);
            } else {
                // Align processSlots identity with start-preview Token (scope/layout_option/target),
                // same as storefront editor_mode canvas — otherwise storefront falls back to RequestContext
                // defaults and draft edits from the visual editor never appear.
                $this->installPreviewLayoutIdentityFromToken();
            }
            $editorMode = $this->request->getParam('editor_mode');
            // frontend: editor_mode=1 的编辑器 iframe 不注入
            // backend: 预览环境即使 editor_mode=1 也要提供退出浮窗
            $shouldInjectPreviewFloat = ($editorMode !== '1' && $editorMode !== 'true')
                || $area === 'backend';
            if ($shouldInjectPreviewFloat) {
                $html = $this->injectPreviewExitButton($html);
                $html = $this->injectPreviewInterceptor($html);
            }
        } elseif ($this->isEditorCanvasRequest()) {
            \Weline\Framework\Cache\SharedResponseCachePolicy::forbid('theme_editor_canvas');
            $this->bootstrapEditorCanvasIdentity($template);
        }

        $allowBackendSlots = $area === 'backend' && $this->isBackendDashboardSlotRequest($template);

        // 普通后台页面保持原有行为；Dashboard 是后台 Theme 的特殊布局，需要进入 slot 渲染链。
        if ($area !== 'frontend' && !$allowBackendSlots) {
            $event->setData('content', $this->finalizeFrontendHtml($html, $area));
            return;
        }

        // === 第二步：处理插槽替换 ===
        // 检查是否包含插槽标记（支持新旧两种方式）
        // 注意：不再强制检查 isLayoutTemplate，因为 fetch_file_after 事件
        // 获取的是完整渲染后的 HTML，包含所有子模板（如 partials）的内容

        // wave8-8s5: HARD zero-runtime-fill for published storefront when shell is solidified.
        // wave8-8s5+safety: leftover required placeholders (list-filters / category-filters)
        // still take a narrow fill/overlay heal — publish/shell bake miss must not ship empty filters.
        // Editor canvas / editor_mode / preview Token keep the heavy fill path.
        // Narrow check (align PublishedSlotHost) — ignore authoritativePreviewContext false-positives
        // from panel probe cookies.
        if ($area === 'frontend' && $this->shouldForcePublishedZeroRuntimeFill()) {
            // wave9-9s5: gate FIRST — complete chrome shells skip fill WITHOUT prime/loadFragments
            // (prime was paying shell include tax on the supposed ≪100ms fast path).
            // wave9-9s6: empty footer--shell is 缺壳; try durable chrome.rendered splice
            // BEFORE prime so common incomplete shells can still +skip_fill_solidified ≪100.
            $gateReason = \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                ::shellSafetyNetFillReason($html);
            $needsSafetyNet = $gateReason !== 'none';

            $ctxBefore = RequestContext::get(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE
            );
            $reason = 'forced_published_storefront';
            if ($ctxBefore === false) {
                $reason = 'ctx_was_false';
            } elseif ($ctxBefore === true) {
                $reason = 'forced_despite_ctx_true';
            } elseif ($ctxBefore === null) {
                $reason = 'forced_ctx_unset';
            }

            $themeIdForPrime = 0;
            $pageTypeForPrime = '';
            $snapshotPrefill = false;
            if ($needsSafetyNet) {
                try {
                    $themeIdForPrime = $this->resolveThemeId($area);
                    if ($themeIdForPrime > 0) {
                        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller $prefillFiller */
                        $prefillFiller = ObjectManager::getInstance(
                            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller::class,
                        );
                        $html = $prefillFiller->prefillPublishedChromeFromRenderedSnapshot(
                            $html,
                            $themeIdForPrime,
                        );
                        $gateReason = \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                            ::shellSafetyNetFillReason($html);
                        $needsSafetyNet = $gateReason !== 'none';
                        $snapshotPrefill = !$needsSafetyNet;

                        // List/category: filter declaration placeholders with chrome already
                        // present → narrow overlay only (avoid ~1.7s whole-shell fill).
                        if ($needsSafetyNet
                            && \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller
                                ::shouldPreferNarrowFilterHeal($html)
                        ) {
                            if ($pageTypeForPrime === '') {
                                $pageTypeForPrime = $this->detectPageType($template);
                            }
                            $narrowPageType = $pageTypeForPrime !== ''
                                ? $pageTypeForPrime
                                : $this->resolveSafetyNetPageType($template, $html, '');
                            if ($narrowPageType === '') {
                                $narrowPageType = ThemeLayout::PAGE_TYPE_HOME;
                            }
                            $html = $prefillFiller->healNarrowFilterPlaceholders(
                                $html,
                                $themeIdForPrime,
                                $narrowPageType,
                                $area,
                            );
                            $gateReason = \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                                ::shellSafetyNetFillReason($html);
                            $needsSafetyNet = $gateReason !== 'none';
                            if (!$needsSafetyNet) {
                                $reason .= '+narrow_filter_heal';
                                $snapshotPrefill = true;
                            } elseif (
                                (
                                    $gateReason === 'missing_chrome_blank_header_or_footer'
                                    || $gateReason === 'missing_chrome_header_signals'
                                )
                                && (
                                    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                                        ::shellHasSubstantialHeaderChrome($html)
                                    || \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                                        ::shellHasStorefrontHeaderSignal($html)
                                )
                                // Empty weline-footer--shell also contains "weline-footer" — require real body.
                                && \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                                    ::shellHasSubstantialFooterChrome($html)
                                && (
                                    \str_contains($html, 'products-layout')
                                    || \str_contains($html, 'category-layout')
                                    || \str_contains($html, 'product-detail-layout')
                                    || \str_contains($html, 'theme-layout-product')
                                    || \str_contains($html, 'data-layout="product-detail"')
                                )
                            ) {
                                // Filters healed; exclusive chrome roots may still look blank while
                                // Partial header/footer bodies are already present — skip whole-shell fill.
                                $html = $prefillFiller->prefillPublishedChromeFromRenderedSnapshot(
                                    $html,
                                    $themeIdForPrime,
                                );
                                $needsSafetyNet = false;
                                $snapshotPrefill = true;
                                $reason .= '+narrow_filter_heal+chrome_partial_skip';
                                $gateReason = 'none';
                            }
                        }
                    }
                } catch (\Throwable) {
                    // soft — fall through to prime+heal when still incomplete
                }
            }

            // PDP / listing: chrome-only gate with Partial header+footer already present →
            // skip whole-shell fill (exclusive blank roots are declaration leftovers).
            // Bare "weline-footer" matches empty footer--shell — require substantial body.
            if ($needsSafetyNet
                && (
                    $gateReason === 'missing_chrome_blank_header_or_footer'
                    || $gateReason === 'missing_chrome_header_signals'
                )
                && (
                    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                        ::shellHasStorefrontHeaderSignal($html)
                    || \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                        ::shellHasSubstantialHeaderChrome($html)
                )
                && \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                    ::shellHasSubstantialFooterChrome($html)
                && (
                    \str_contains($html, 'products-layout')
                    || \str_contains($html, 'category-layout')
                    || \str_contains($html, 'product-detail-layout')
                    || \str_contains($html, 'theme-layout-product')
                    || \str_contains($html, 'data-layout="product-detail"')
                )
            ) {
                try {
                    if ($themeIdForPrime < 1) {
                        $themeIdForPrime = $this->resolveThemeId($area);
                    }
                    if ($themeIdForPrime > 0) {
                        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller $prefillFiller */
                        $prefillFiller = ObjectManager::getInstance(
                            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller::class,
                        );
                        $html = $prefillFiller->prefillPublishedChromeFromRenderedSnapshot(
                            $html,
                            $themeIdForPrime,
                        );
                    }
                } catch (\Throwable) {
                    // soft
                }
                $needsSafetyNet = false;
                $snapshotPrefill = true;
                $reason .= '+chrome_partial_skip';
                $gateReason = 'none';
            }

            if (!$needsSafetyNet) {
                if ($snapshotPrefill) {
                    $reason .= '+chrome_snapshot_prefill';
                }
                $reason .= '+skip_fill_solidified';
                if ($gateReason !== 'none' && !$snapshotPrefill) {
                    $reason .= '+gate_' . $gateReason;
                } elseif ($snapshotPrefill) {
                    $reason .= '+gate_none_after_snapshot';
                }
                RequestContext::set(
                    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_APPLIED,
                    true,
                );
                RequestContext::set(
                    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_REASON,
                    $reason,
                );
                try {
                    RequestLifecycleTrace::measurePhase(
                        'theme.layout_slot.zero_runtime_fill',
                        static fn () => null,
                        ['reason' => $reason, 'skipped_fill' => true, 'gate' => $gateReason],
                    );
                } catch (\Throwable) {
                    // trace is best-effort
                }
                // 布局固化与默认注入.md §3–§4: complete solidified shell → strip only.
                // Required JSON default_injections must already live in chrome.rendered /
                // layout bake (minus user_deleted). Per-request Overlay is NOT the primary path.
                //
                // 必装永远存在（2026-09-22 架构裁决 + spec/required-default-always-present.md §4）：
                // `+skip_fill_solidified` 只允许跳过 entity fill，不得跳过 required overlay。
                // 无可用固化产物时（CTX_FRAGMENTS 非数组），strip 前必须仍跑 required 注入，
                // 否则结账页等尚未固化的布局会留下空 theme-published-slot。
                $html = $this->fillRequiredDefaultsOnZeroFillPath(
                    $html,
                    $themeIdForPrime,
                    $pageTypeForPrime,
                    $area,
                    $template,
                );
                $html = SlotBoundaryMarkers::strip($html);
                $event->setData('content', $this->finalizeFrontendHtml($html, $area));
                return;
            }

            // Heal path only: last-chance prime so late bake deps can still set CTX=false.
            try {
                if ($themeIdForPrime < 1) {
                    $themeIdForPrime = $this->resolveThemeId($area);
                }
                $pageTypeForPrime = $this->detectPageType($template);
                if ($themeIdForPrime > 0 && $pageTypeForPrime !== '') {
                    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::primeStorefront(
                        $themeIdForPrime,
                        $pageTypeForPrime,
                    );
                }
            } catch (\Throwable) {
                // Continue — safety-net / strip still apply to whatever the shell already has.
            }

            $ctxAfter = RequestContext::get(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_USE_REACTIVE
            );
            if ($ctxAfter === false && $ctxBefore !== false) {
                $reason .= '+prime_recovered';
            }
            $reason .= '+safety_net_fill+gate_' . $gateReason;

            RequestContext::set(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_APPLIED,
                true,
            );
            RequestContext::set(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_ZERO_FILL_REASON,
                $reason,
            );
            try {
                RequestLifecycleTrace::measurePhase(
                    'theme.layout_slot.zero_runtime_fill',
                    static fn () => null,
                    ['reason' => $reason, 'skipped_fill' => false, 'gate' => $gateReason],
                );
            } catch (\Throwable) {
                // trace is best-effort
            }

            // Narrow safety net (§3.1): no usable solidified chrome / incomplete shell —
            // heal + bake-time Overlay for THIS response. Lasting fix = rebake /
            // chrome.rendered finalize (injection-collect / publish), not every-request Overlay.
            $themeId = $themeIdForPrime > 0 ? $themeIdForPrime : $this->resolveThemeId($area);
            $pageType = $this->resolveSafetyNetPageType($template, $html, $pageTypeForPrime);
            if ($pageType === '') {
                $pageType = ThemeLayout::PAGE_TYPE_HOME;
            }
            // 2026-09-26 用户纠偏（严格档「有固化就完全不注」）：
            // 页面固化产物已装载 ⇒ 运行时**禁止** heal / 注入 / 查部件声明。
            // 固化壳仍缺必装槽位 = **固化缺陷**：记硬失败，交由重固化（rebake）修复，
            // 不得每请求 Overlay 打补丁（否则「固化后运行时不得再注」形同虚设）。
            if (\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                ::publishedSolidifiedArtifactLoaded()
            ) {
                if (\function_exists('w_log_error')) {
                    \w_log_error(
                        'solidified_shell_missing_required_slot: ' . $gateReason,
                        [
                            'theme_id' => $themeId,
                            'page_type' => $pageType,
                            'area' => $area,
                            'gate' => $gateReason,
                        ],
                        'theme_layout_entity',
                    );
                }
            } elseif ($themeId > 0 && $pageType !== '') {
                try {
                    /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller $filler */
                    $filler = ObjectManager::getInstance(
                        \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller::class,
                    );
                    $html = $filler->healPublishedPlaceholderShell($html, $themeId, $pageType, $area);
                    // Actual $pageType (not HOME): requiredForPage inherits chrome-carrier only;
                    // forcing HOME would plan content→newsletter onto policy/terms.
                    // Skip second overlay when heal already closed the gate (avoid double work).
                    if (\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                        ::shellNeedsRuntimeSafetyNetFill($html)
                    ) {
                        $html = $filler->fillRequiredDefaultsOnShell(
                            $html,
                            $themeId,
                            $pageType,
                            ThemeLayout::STATUS_PUBLISHED,
                        );
                    }
                } catch (\Throwable) {
                    // Soft: still strip markers and deliver whatever healed.
                }
            }

            $html = SlotBoundaryMarkers::strip($html);
            $event->setData('content', $this->finalizeFrontendHtml($html, $area));
            return;
        }

        $isEditorOrPreview = $this->isEditorOrPreviewMode();
        $hasSlotMarkers = strpos($html, 'data-wslot') !== false || strpos($html, 'widget-slot-area') !== false;

        // Fast path: normal frontend HTML without reactive slot markers needs no fill.
        // wave8-8s2: published host emits theme-published-slot / data-slot-id only
        // (no data-wslot) → zero-runtime-fill early return. Editor/preview keep markers.
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

        // Storefront hard cut: fill from layout entities only — never processSlots/DB.
        if ($area === 'frontend' && !$this->isEditorOrPreviewMode()) {
            $html = $this->renderFromLayoutEntities($html, $themeId, $pageType, $status, $area);
            $event->setData('content', $this->finalizeFrontendHtml($html, $area));
            return;
        }

        /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityRuntime $entityRuntime */
        $entityRuntime = ObjectManager::getInstance(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityRuntime::class,
        );
        // Storefront may markSkipSlotProcessing for entity fill; editor/preview must still
        // processSlots so draft/workspace nodes land in nested w:slot (e.g. homepage-brands).
        if ($entityRuntime->shouldSkipSlotProcessing() && !$this->isEditorOrPreviewMode()) {
            $html = $this->fillChromeFromEntity($html, $themeId, $area);
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
        $memoryProbe('after_process_slots', $processedHtml);
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
        $memoryProbe('before_return', $event->getData('content'));
    }

    private function finalizeFrontendHtml(string $html, string $area): string
    {
        if ($area !== 'frontend' || $html === '') {
            return $html;
        }

        // Visual-editor canvas loads the real storefront route with editor_mode=1.
        // Inject editor assets here (ThemePreview\Content is no longer the canvas shell).
        if ($this->isEditorCanvasRequest()) {
            try {
                /** @var \Weline\Theme\Service\EditorModeAssetInjector $injector */
                $injector = ObjectManager::getInstance(\Weline\Theme\Service\EditorModeAssetInjector::class);

                return $injector->inject($html);
            } catch (\Throwable) {
                return $html;
            }
        }

        // wave8-8s4: published storefront outbound always strip markers (DEV probes too).
        // Editor canvas returns above; preview Token / editor_mode keep markers for the canvas.
        // Mini-cart footer-extras (coupon/留言) is nested inside header chrome — fill blank
        // nested chrome extensions before strip so skip_fill paths still deliver them.
        if ($this->shouldForcePublishedZeroRuntimeFill()) {
            $html = $this->ensureMiniCartFooterExtrasFilled($html);
            $html = SlotBoundaryMarkers::strip($html);
        } elseif (\defined('PROD') && PROD) {
            $html = $this->ensureMiniCartFooterExtrasFilled($html);
            $html = SlotBoundaryMarkers::strip($html);
        }

        // Design themes (hanfu/daocharms) may omit data-pdp-budget-phase; stamp for armed samples.
        $html = \Weline\Theme\Service\ThemePdpBudgetPhases::stampSectionAttributes($html);

        try {
            /** @var PreviewBootstrapAssetInjector $injector */
            $injector = ObjectManager::getInstance(PreviewBootstrapAssetInjector::class);

            return $injector->inject($html);
        } catch (\Throwable) {
            return $html;
        }
    }

    /**
     * Ensure mini-cart footer-extras (优惠券/留言) is filled from chrome payload when blank.
     */
    private function ensureMiniCartFooterExtrasFilled(string $html): string
    {
        if ($html === ''
            || (!\str_contains($html, 'data-slot-id="footer-extras"')
                && !\str_contains($html, 'data-wslot="footer-extras"'))
        ) {
            return $html;
        }
        if (!\preg_match(
            '/\bdata-(?:slot-id|wslot)=(["\'])footer-extras\1[^>]*>\s*<\/(?:div|section|aside)>/i',
            $html,
        )
            && \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
                ::shellSafetyNetFillReason($html) !== 'empty_critical_footer_extras'
        ) {
            return $html;
        }

        try {
            $themeId = $this->resolveThemeId('frontend');
            if ($themeId < 1) {
                return $html;
            }
            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller::class,
            );

            return $filler->fillBlankNestedChromeExtensions($html, $themeId);
        } catch (\Throwable) {
            return $html;
        }
    }

    /**
     * Storefront entity hard cut — no processSlots / getLayoutData fallback.
     */
    private function renderFromLayoutEntities(
        string $html,
        int $themeId,
        string $pageType,
        string $status,
        string $area,
    ): string {
        try {
            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller::class,
            );

            return $filler->fill($html, $themeId, $pageType, $status, $area);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_error')) {
                \w_log_error('theme_layout_entity_storefront_fill_failed: ' . $e->getMessage(), [
                    'theme_id' => $themeId,
                    'page_type' => $pageType,
                    'status' => $status,
                    'area' => $area,
                ], 'theme_layout_entity');
            }
            // Soft degrade: chrome + 有部件必入声明槽 on shell. Entity missing must not leave
            // required widgets absent; when required fill succeeds, deliver the page.
            $html = $this->fillChromeFromEntity($html, $themeId, $area);
            try {
                /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller $filler */
                $filler = ObjectManager::getInstance(
                    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller::class,
                );
                $html = $filler->fillRequiredDefaultsOnShell($html, $themeId, $pageType, $status);
            } catch (\Throwable $requiredError) {
                if (\function_exists('w_log_error')) {
                    \w_log_error(
                        'required_default_injection_soft_path_failed: ' . $requiredError->getMessage(),
                        [
                            'theme_id' => $themeId,
                            'page_type' => $pageType,
                        ],
                        'theme_layout_entity',
                    );
                }
                throw new \RuntimeException(
                    'required_default_injection_failed: ' . $requiredError->getMessage(),
                    0,
                    $requiredError,
                );
            }
            if (\defined('DEV') && DEV) {
                return $html . "\n<!-- theme_layout_entity_missing: "
                    . \htmlspecialchars($e->getMessage(), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')
                    . " -->\n";
            }

            return $html;
        }
    }

    /**
     * When FetchFileBefore marks entity skip: still inject chrome into empty header/footer.
     */
    private function fillChromeFromEntity(string $html, int $themeId, string $area): string
    {
        try {
            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller::class,
            );

            return $filler->fillChromeOnly($html, $themeId, $area, false);
        } catch (\Throwable) {
            return $html;
        }
    }

    private function isEditorCanvasRequest(): bool
    {
        $editorMode = \trim((string)$this->request->getParam('editor_mode', ''));
        if ($editorMode !== '1' && \strtolower($editorMode) !== 'true') {
            return false;
        }

        return true;
    }

    /**
     * Install draft LayoutIdentity from typed editor_context on the real storefront route.
     */
    private function bootstrapEditorCanvasIdentity(string $template = ''): void
    {
        if (!$this->isEditorCanvasRequest()) {
            return;
        }

        $raw = $this->request->getParam('editor_context', null);
        $decoded = $this->decodeEditorContextValue($raw);
        if ($decoded === null) {
            $themeId = (int)$this->request->getParam('theme_id', $this->request->getParam('frontend_theme_id', 0));
            if ($themeId < 1) {
                $themeId = $this->resolveThemeId('frontend');
            }
            $decoded = $this->resolveOrphanDeleteEditorContext($themeId, $this->detectPageType($template));
        }
        if ($decoded === null) {
            return;
        }

        try {
            /** @var \Weline\Theme\Service\Scoped\ThemeEditorContextFactory $factory */
            $factory = ObjectManager::getInstance(\Weline\Theme\Service\Scoped\ThemeEditorContextFactory::class);
            $typed = $factory->fromInput(['editor_context' => $decoded], 'layout');
            if (!$typed instanceof \Weline\Theme\Api\Scoped\ThemeEditorContext) {
                return;
            }

            /** @var \Weline\Theme\Service\ThemeLayoutScopeNormalizer $normalizer */
            $normalizer = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutScopeNormalizer::class);
            $scope = $normalizer->encodeStorageScope(
                $typed->scope->storageScope,
                $typed->scope->storeMode,
            );
            $locale = $typed->locale === 'default' ? '' : $typed->locale;
            RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, new LayoutIdentity(
                $typed->layoutOption !== '' ? $typed->layoutOption : 'default',
                $scope,
                $typed->targetType !== '' ? $typed->targetType : 'global',
                \max(0, (int)$typed->targetId),
                $locale,
            ));

            /** @var PreviewContextService $previewContext */
            $previewContext = ObjectManager::getInstance(PreviewContextService::class);
            $previewContext->persistCurrentRequestContext([
                'shell' => PreviewContextService::SHELL_THEME_EDITOR,
                'status' => (string)$this->request->getParam('status', PreviewContextService::DEFAULT_STATUS),
                'frontend_theme_id' => $typed->themeId,
                'theme_id' => $typed->themeId,
                'editor_area' => PreviewContextService::AREA_FRONTEND,
                'layout_option' => $typed->layoutOption,
                'target_type' => PreviewContextService::TARGET_TYPE_LAYOUT,
                'target_value' => $typed->layoutType,
                'editor_context' => $typed->toArray(),
            ]);
        } catch (\Throwable) {
        }
    }

    private function isEditorIframePreviewRequest(): bool
    {
        return $this->isEditorCanvasRequest();
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
        $context = $this->resolveLivePreviewContext();
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
        // Valid storefront preview Token must take the draft processSlots path even when
        // PreviewContextService::hasAuthoritativePreviewContext() is momentarily false
        // (cookie/scope carrier edge). Float chrome already keys off isPreviewMode().
        if ($this->previewTokenService->isPreviewMode()) {
            return true;
        }

        return $this->authoritativePreviewContext() !== null;
    }

    /**
     * 必装永远存在：`+skip_fill_solidified` 零补槽快路径上的 required 注入兜底。
     *
     * 权威：`app/code/Weline/Theme/doc/布局固化与默认注入.md` §3–§4 + 2026-09-26 用户纠偏：
     * 固化完备后运行时不得再实时注入；仅当本请求没有装载到页面固化片段
     * （`CTX_FRAGMENTS.page_html` 为空）时才允许 Overlay/安全网——且优先应触发
     * dynamicSolidify/rebake，而不是把每请求 Overlay 当主路径。
     *
     * `+skip_fill_solidified` **可以**跳过 entity fill；完备壳 required 已由 bake 承载。
     */
    private function fillRequiredDefaultsOnZeroFillPath(
        string $html,
        int $themeId,
        string $pageType,
        string $area,
        string $template,
    ): string {
        if ($html === '' || !\str_contains($html, 'data-slot-id=')) {
            return $html;
        }
        // 固化片段已装载且含页面 bake：required 已由 bake 承载，走零补槽快路径。
        // 仅有 chrome bake（page_html 为空）时页面槽仍是空壳，必须继续注入。
        // 2026-09-26 用户纠偏（严格档「有固化就完全不注」）：固化产物存在 ⇒ 运行时不得再注入。
        if (\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost
            ::publishedSolidifiedArtifactLoaded()
        ) {
            return $html;
        }
        if ($themeId < 1) {
            $themeId = $this->resolveThemeId($area);
        }
        if ($themeId < 1) {
            return $html;
        }
        if ($pageType === '') {
            $pageType = $this->detectPageType($template);
        }
        if ($pageType === '') {
            $pageType = $this->resolveSafetyNetPageType($template, $html, '');
        }
        if ($pageType === '') {
            return $html;
        }

        try {
            /** @var \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(
                \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller::class,
            );

            return $filler->fillRequiredDefaultsOnShell(
                $html,
                $themeId,
                $pageType,
                ThemeLayout::STATUS_PUBLISHED,
            );
        } catch (\Throwable $requiredError) {
            // 软降级：注入失败不得吞掉整页，仍需 strip 后交付。
            if (\function_exists('w_log_warning')) {
                \w_log_warning(
                    'required_default_injection_zero_fill_soft_skip: ' . $requiredError->getMessage(),
                    ['theme_id' => $themeId, 'page_type' => $pageType, 'area' => $area],
                    'theme_layout_entity',
                );
            }

            return $html;
        }
    }

    /**
     * wave8-8s4: published delivery gate for hard zero-runtime-fill.
     * Aligns with PublishedSlotHost::isEditorOrPreviewRequest (editor_mode + preview Token only).
     * Does NOT treat authoritativePreviewContext / panel cookies as preview — that false-positive
     * skipped the 8s3 CTX===false early-return and left LayoutSlot on the fill path.
     */
    private function shouldForcePublishedZeroRuntimeFill(): bool
    {
        if ($this->isEditorCanvasRequest()) {
            return false;
        }

        try {
            $editorMode = \trim((string)$this->request->getParam('editor_mode', ''));
            if ($editorMode === '1' || \strtolower($editorMode) === 'true') {
                return false;
            }
        } catch (\Throwable) {
            // fall through — treat as published
        }

        try {
            if ($this->previewTokenService->isPreviewMode()) {
                return false;
            }
        } catch (\Throwable) {
            // fall through
        }

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveLivePreviewContext(): ?array
    {
        $context = $this->authoritativePreviewContext();
        if (\is_array($context)) {
            return $context;
        }

        if (!$this->previewTokenService->isPreviewMode()) {
            return null;
        }

        $tokenData = $this->previewTokenService->getCurrentPreviewData();
        if (!\is_array($tokenData)) {
            return ['status' => ThemeLayout::STATUS_DRAFT];
        }

        $tokenContext = $tokenData['context'] ?? null;
        if (\is_array($tokenContext)) {
            return $tokenContext;
        }

        return [
            'status' => ThemeLayout::STATUS_DRAFT,
            'frontend_theme_id' => (int)($tokenData['theme_id'] ?? 0),
            'layout_option' => 'default',
            'scope' => PreviewContextService::DEFAULT_SCOPE,
            'target_value' => (string)($tokenData['page_type'] ?? ThemeLayout::PAGE_TYPE_HOME),
        ];
    }

    /**
     * Freeze LayoutIdentity from the preview Token so SlotRenderer processSlots
     * loads the same scoped draft workspace the visual editor just wrote.
     */
    private function installPreviewLayoutIdentityFromToken(): void
    {
        if (RequestContext::get(LayoutIdentity::REQUEST_CONTEXT_KEY) instanceof LayoutIdentity) {
            return;
        }

        $context = $this->resolveLivePreviewContext();
        if (!\is_array($context)) {
            return;
        }

        try {
            /** @var \Weline\Theme\Service\ThemeLayoutScopeNormalizer $normalizer */
            $normalizer = ObjectManager::getInstance(\Weline\Theme\Service\ThemeLayoutScopeNormalizer::class);
            $typed = $context['editor_context'] ?? null;
            if (\is_string($typed) && $typed !== '') {
                try {
                    $typed = \json_decode($typed, true, flags: \JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $typed = null;
                }
            }

            if (\is_array($typed) && $this->isTypedEditorContext($typed)) {
                /** @var \Weline\Theme\Service\Scoped\ThemeEditorContextFactory $factory */
                $factory = ObjectManager::getInstance(
                    \Weline\Theme\Service\Scoped\ThemeEditorContextFactory::class,
                );
                $editorContext = $factory->fromInput(['editor_context' => $typed], 'layout');
                $scope = $normalizer->encodeStorageScope(
                    $editorContext->scope->storageScope,
                    $editorContext->scope->storeMode,
                );
                $layoutOption = $editorContext->layoutOption !== '' ? $editorContext->layoutOption : 'default';
                $targetType = $editorContext->targetType !== '' ? $editorContext->targetType : 'global';
                $targetId = $targetType === 'global' ? 0 : \max(0, $editorContext->targetId);
                $locale = $editorContext->locale === 'default' ? '' : $editorContext->locale;
            } else {
                $normalized = $normalizer->normalize([
                    'scope' => (string)($context['scope'] ?? PreviewContextService::DEFAULT_SCOPE),
                    'store_mode' => (string)($context['store_mode'] ?? 'normal'),
                    'locale_code' => (string)($context['locale'] ?? $context['locale_code'] ?? ''),
                ]);
                $scope = $normalized['scope'];
                $locale = $normalized['locale_code'];
                $layoutOption = \trim((string)($context['layout_option'] ?? 'default'));
                if ($layoutOption === '') {
                    $layoutOption = 'default';
                }
                $targetType = \trim((string)(
                    $context['theme_layout_target_type']
                    ?? $context['theme_layout_source_target_type']
                    ?? 'global'
                ));
                if ($targetType === '') {
                    $targetType = 'global';
                }
                $targetId = $targetType === 'global'
                    ? 0
                    : \max(0, (int)(
                        $context['theme_layout_target_id']
                        ?? $context['theme_layout_source_target_id']
                        ?? 0
                    ));
            }

            RequestContext::set(LayoutIdentity::REQUEST_CONTEXT_KEY, new LayoutIdentity(
                $layoutOption,
                $scope,
                $targetType,
                $targetId,
                $locale,
            ));
        } catch (\Throwable) {
            // Best-effort: preview still continues with RequestContext ScopeIdentity fallback.
        }
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

        return str_contains($uri, 'theme/backend/theme-editor/compile-layout')
            || str_contains($uri, 'theme/backend/theme-editor/get-compile-layout');
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

    /**
     * Safety-net pageType: detectPageType can miss when fetch_file_after fileName is a
     * compiled cache path (no layouts/{type}). Fall back to CTX_LAYOUT_TYPE + shell slot ids.
     */
    private function resolveSafetyNetPageType(string $template, string $html, string $detected = ''): string
    {
        $pageType = \trim($detected);
        if ($pageType === '') {
            $pageType = \trim($this->detectPageType($template));
        }
        if ($pageType !== '' && $pageType !== ThemeLayout::PAGE_TYPE_DEFAULT) {
            return $pageType;
        }

        $ctxLayout = RequestContext::get(
            \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost::CTX_LAYOUT_TYPE
        );
        if (\is_string($ctxLayout) && \trim($ctxLayout) !== '') {
            $mapped = \trim($this->pageTypeResolver->mapLayoutTypeToPageType($ctxLayout));
            if ($mapped !== '') {
                return $mapped;
            }
        }

        if (\str_contains($html, 'list-filters')
            || \preg_match('/\bdata-placeholder\s*=\s*(["\'])list-filters\1/', $html) === 1
        ) {
            return ThemeLayout::PAGE_TYPE_PRODUCT_LIST;
        }
        if (\str_contains($html, 'category-filters')
            || \preg_match('/\bdata-placeholder\s*=\s*(["\'])category-filters\1/', $html) === 1
        ) {
            return ThemeLayout::PAGE_TYPE_CATEGORY;
        }
        // Cart / checkout shells often lack layout path in compiled fileName and have
        // no filter heuristics — still need a pageType so homepage chrome carrier
        // required injections (footer-*-links) can run on the zero-fill soft path.
        if (\preg_match('/\b(?:cart-layout|checkout-layout|data-page-type=["\']cart["\']|data-page-type=["\']checkout["\'])/i', $html) === 1
            || \str_contains($html, 'data-widget-code="cart-')
            || \str_contains($html, 'data-testid="cart-')
        ) {
            if (\str_contains($html, 'checkout') || \str_contains($html, 'data-widget-code="checkout-')) {
                return ThemeLayout::PAGE_TYPE_CHECKOUT;
            }

            return ThemeLayout::PAGE_TYPE_CART;
        }
        // Policy / terms shells: never fall through to HOME (that plans homepage
        // content widgets like newsletter-popup into data-slot-id=content).
        if (\preg_match(
            '/amazon-policy__|data-layout=["\']policy-|data-testid=["\']storefront-(?:privacy|refund|cookie|shipping|disclaimer|accessibility|term)/i',
            $html,
        ) === 1) {
            return ThemeLayout::PAGE_TYPE_POLICY;
        }
        if (\preg_match('/amazon-terms__|data-layout=["\']terms/i', $html) === 1) {
            return ThemeLayout::PAGE_TYPE_TERMS;
        }
        // Last resort: keep chrome inherit alive when detectPageType is empty.
        if ($pageType === '' || $pageType === ThemeLayout::PAGE_TYPE_DEFAULT) {
            return ThemeLayout::PAGE_TYPE_HOME;
        }

        return $pageType;
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
        $previewPublishNeedsLoginJson = \json_encode(
            (string)__('发布需要有效的后台登录，请重新登录后台后再试。'),
            $previewMessageJsonFlags
        ) ?: '"发布需要有效的后台登录，请重新登录后台后再试。"';
        $previewConfirmPublishJson = \json_encode((string)__('确认发布当前预览内容并退出？发布后，所有访客将看到最新更改。'), $previewMessageJsonFlags) ?: '"确认发布当前预览内容并退出？发布后，所有访客将看到最新更改。"';
        $previewConfirmOkJson = \json_encode((string)__('确认发布'), $previewMessageJsonFlags) ?: '"确认发布"';
        $previewConfirmCancelJson = \json_encode((string)__('取消'), $previewMessageJsonFlags) ?: '"取消"';
        $previewConfirmTitleJson = \json_encode((string)__('发布预览'), $previewMessageJsonFlags) ?: '"发布预览"';
        $previewVersionTitleJson = \json_encode((string)__('新建版本并发布'), $previewMessageJsonFlags) ?: '"新建版本并发布"';
        $previewVersionMessageJson = \json_encode((string)__('有未发布改动，请输入版本名称后发布（可留空自动 {version}）'), $previewMessageJsonFlags) ?: '"有未发布改动，请输入版本名称后发布（可留空自动 {version}）"';
        $previewVersionPlaceholderJson = \json_encode((string)__('版本名称'), $previewMessageJsonFlags) ?: '"版本名称"';
        $previewVersionOkJson = \json_encode((string)__('发布'), $previewMessageJsonFlags) ?: '"发布"';
        $previewPublishingTitleJson = \json_encode((string)__('正在发布主题'), $previewMessageJsonFlags) ?: '"正在发布主题"';
        $previewCheckingGateJson = \json_encode((string)__('检查发布条件…'), $previewMessageJsonFlags) ?: '"检查发布条件…"';
        $previewPublishingJson = \json_encode((string)__('正在发布…'), $previewMessageJsonFlags) ?: '"正在发布…"';
        $previewStepPrefixJson = \json_encode((string)__('步骤：'), $previewMessageJsonFlags) ?: '"步骤："';
        $previewDoNotCloseJson = \json_encode((string)__('请勿关闭或操作页面，以免发布中断。'), $previewMessageJsonFlags) ?: '"请勿关闭或操作页面，以免发布中断。"';
        $previewPublishSuccessRedirectJson = \json_encode((string)__('发布成功，正在打开已发布页面…'), $previewMessageJsonFlags) ?: '"发布成功，正在打开已发布页面…"';
        $previewFinalizeDoneJson = \json_encode((string)__('发布收尾完成'), $previewMessageJsonFlags) ?: '"发布收尾完成"';
        $previewExitPreviewRedirectJson = \json_encode((string)__('正在清理预览会话并准备跳转…'), $previewMessageJsonFlags) ?: '"正在清理预览会话并准备跳转…"';
        $previewCreateVersionPublishingJson = \json_encode((string)__('创建版本并发布…'), $previewMessageJsonFlags) ?: '"创建版本并发布…"';
        $previewPublishDoneManualRefreshJson = \json_encode((string)__('发布已完成，请手动刷新查看店面'), $previewMessageJsonFlags) ?: '"发布已完成，请手动刷新查看店面"';
        $previewProgressByStep = [
            'gate' => (string)__('检查发布条件'),
            'create_version' => (string)__('创建发布版本'),
            'scoped_publish' => (string)__('发布 Scoped 草稿'),
            'mark_version' => (string)__('标记版本已发布'),
            'restore_version' => (string)__('恢复历史版本'),
            'preview_scope' => (string)__('同步预览 Scope'),
            'bake' => (string)__('布局实体化'),
            'static_version' => (string)__('更新静态资源版本'),
            'generate_cache' => (string)__('重建主题生成缓存'),
            'clear_cache' => (string)__('清理运行时与 FPC 缓存'),
            'finalize_ok' => (string)__('发布收尾完成'),
            'exit_preview' => (string)__('正在清理预览会话并准备跳转'),
            'compose_redirect' => (string)__('正在生成跳转地址'),
            'redirect' => (string)__('发布成功，正在打开已发布页面'),
            'start' => (string)__('开始发布主题'),
            'done' => (string)__('发布收尾完成'),
        ];
        $previewProgressSourceMap = [
            '检查发布条件' => $previewProgressByStep['gate'],
            '检查发布条件…' => (string)__('检查发布条件…'),
            '创建发布版本' => $previewProgressByStep['create_version'],
            '发布 Scoped 草稿' => $previewProgressByStep['scoped_publish'],
            '标记版本已发布' => $previewProgressByStep['mark_version'],
            '恢复历史版本' => $previewProgressByStep['restore_version'],
            '同步预览 Scope' => $previewProgressByStep['preview_scope'],
            '布局实体化' => $previewProgressByStep['bake'],
            '更新静态资源版本' => $previewProgressByStep['static_version'],
            '重建主题生成缓存' => $previewProgressByStep['generate_cache'],
            '清理运行时与 FPC 缓存' => $previewProgressByStep['clear_cache'],
            '发布收尾完成' => $previewProgressByStep['finalize_ok'],
            '正在清理预览会话并准备跳转' => $previewProgressByStep['exit_preview'],
            '正在清理预览会话并准备跳转…' => (string)__('正在清理预览会话并准备跳转…'),
            '正在生成跳转地址' => $previewProgressByStep['compose_redirect'],
            '发布成功，正在打开已发布页面' => $previewProgressByStep['redirect'],
            '发布成功，正在打开已发布页面…' => (string)__('发布成功，正在打开已发布页面…'),
            '开始发布主题' => $previewProgressByStep['start'],
            '正在发布…' => (string)__('正在发布…'),
            '正在发布主题' => (string)__('正在发布主题'),
            '创建版本并发布…' => (string)__('创建版本并发布…'),
            '确认当前已发布布局' => (string)__('确认当前已发布布局'),
        ];
        $previewProgressByStepJson = \json_encode($previewProgressByStep, $previewMessageJsonFlags) ?: '{}';
        $previewProgressSourceMapJson = \json_encode($previewProgressSourceMap, $previewMessageJsonFlags) ?: '{}';
        $previewLocale = \trim((string)($this->request->getParam('locale', '') ?: (\Weline\Framework\Runtime\RequestContext::locale() ?? '')));
        if ($previewLocale === '' || \strcasecmp($previewLocale, 'default') === 0) {
            $previewLocale = \trim((string)\Weline\Framework\App\State::getLangLocal());
        }
        if ($previewLocale === '' || \strcasecmp($previewLocale, 'default') === 0) {
            $pathLocale = \Weline\Theme\Helper\WidgetI18n::localeFromRequestUri(
                (string)(\Weline\Framework\Env\WelineEnv::server('REQUEST_URI', '') ?: ($_SERVER['REQUEST_URI'] ?? ''))
            );
            $previewLocale = $pathLocale ?? 'zh_Hans_CN';
        }
        $previewLocaleJson = \json_encode($previewLocale, $previewMessageJsonFlags) ?: '"zh_Hans_CN"';
        $previewModeLabel = \htmlspecialchars((string)__('预览模式'), \ENT_QUOTES, 'UTF-8');
        $publishAndExitLabel = \htmlspecialchars((string)__('发布并退出'), \ENT_QUOTES, 'UTF-8');
        $exitPreviewLabel = \htmlspecialchars((string)__('退出预览'), \ENT_QUOTES, 'UTF-8');
        $tokenJson = \json_encode((string)$token, $previewMessageJsonFlags) ?: '""';
        $exitPreviewUrlJson = \json_encode((string)$exitPreviewUrl, $previewMessageJsonFlags) ?: '""';
        $publishAndExitUrlJson = \json_encode((string)$publishAndExitUrl, $previewMessageJsonFlags) ?: '""';

        // 浮窗 HTML 与内联脚本（文案经 __() 注入，随当前店面语言）
        $floatHtml = <<<HTML
<!-- Weline Theme Preview Exit Button -->
<div id="weline-preview-exit-float" data-w-preview-float="1" data-w-prompt-ui="theme-v405" style="
    position: fixed !important;
    bottom: 20px !important;
    right: 20px !important;
    left: auto !important;
    top: auto !important;
    z-index: 2147483100 !important;
    background: linear-gradient(135deg, var(--backend-color-gradient-start, #667eea) 0%, var(--backend-color-gradient-end, #764ba2) 100%) !important;
    border-radius: 12px !important;
    box-shadow: 0 8px 32px rgba(102, 126, 234, 0.4) !important;
    padding: 12px 16px !important;
    cursor: move !important;
    user-select: none !important;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
    min-width: 160px !important;
    transition: transform 0.2s, box-shadow 0.2s !important;
    margin: 0 !important;
    float: none !important;
    display: block !important;
    width: auto !important;
    height: auto !important;
    pointer-events: auto !important;
">
    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px; color: white; pointer-events: none;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <path d="M12 16v-4M12 8h.01"/>
        </svg>
        <span style="font-weight: 600; font-size: 14px;">{$previewModeLabel}</span>
    </div>
    <div style="display: flex; flex-direction: column; gap: 8px; pointer-events: auto;">
        <button type="button" id="weline-preview-publish-btn" style="
            padding: 8px 16px;
            background: rgba(255,255,255,0.98);
            color: var(--backend-color-gradient-start, #667eea);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            width: 100%;
            pointer-events: auto;
            position: relative;
            z-index: 1;
        ">
            {$publishAndExitLabel}
        </button>
        <button type="button" id="weline-preview-exit-btn" style="
            padding: 8px 16px;
            background: rgba(255,255,255,0.16);
            color: white;
            border: 1px solid rgba(255,255,255,0.35);
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            width: 100%;
            pointer-events: auto;
            position: relative;
            z-index: 1;
        ">
            {$exitPreviewLabel}
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
        pointer-events: none;
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
        publishNeedsLogin: {$previewPublishNeedsLoginJson},
        confirmPublish: {$previewConfirmPublishJson},
        confirmOk: {$previewConfirmOkJson},
        confirmCancel: {$previewConfirmCancelJson},
        confirmTitle: {$previewConfirmTitleJson},
        versionTitle: {$previewVersionTitleJson},
        versionMessage: {$previewVersionMessageJson},
        versionPlaceholder: {$previewVersionPlaceholderJson},
        versionOk: {$previewVersionOkJson},
        publishingTitle: {$previewPublishingTitleJson},
        checkingGate: {$previewCheckingGateJson},
        publishing: {$previewPublishingJson},
        stepPrefix: {$previewStepPrefixJson},
        doNotClose: {$previewDoNotCloseJson},
        publishSuccessRedirect: {$previewPublishSuccessRedirectJson},
        finalizeDone: {$previewFinalizeDoneJson},
        exitPreviewRedirect: {$previewExitPreviewRedirectJson},
        createVersionPublishing: {$previewCreateVersionPublishingJson},
        publishDoneManualRefresh: {$previewPublishDoneManualRefreshJson},
        progressByStep: {$previewProgressByStepJson},
        progressSourceMap: {$previewProgressSourceMapJson},
        locale: {$previewLocaleJson}
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
            'z-index:2147483200',
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

    function setPreviewFloatInteractive(enabled) {
        if (!floatEl) {
            return;
        }
        floatEl.style.pointerEvents = enabled ? 'auto' : 'none';
        floatEl.style.opacity = enabled ? '1' : '0.35';
    }

    var publishLockEl = null;
    var publishLockPrevOverflow = '';
    var publishLockSteps = [];

    function resetPublishControls() {
        if (publishBtn) {
            publishBtn.disabled = false;
            publishBtn.textContent = '发布并退出';
        }
        if (exitBtn) {
            exitBtn.disabled = false;
            exitBtn.textContent = '退出预览';
        }
        setPreviewFloatInteractive(true);
    }

    function closePublishProgressLock() {
        if (publishLockEl) {
            try { publishLockEl.remove(); } catch (e) {}
            publishLockEl = null;
        }
        publishLockSteps = [];
        try {
            document.documentElement.style.overflow = publishLockPrevOverflow || '';
        } catch (e) {}
        publishLockPrevOverflow = '';
    }

    function updatePublishProgress(message, progress, step) {
        if (!publishLockEl) {
            return;
        }
        var pct = isFinite(Number(progress))
            ? Math.max(0, Math.min(100, Math.round(Number(progress))))
            : null;
        var msg = String(message || '').trim() || previewMessages.publishing;
        var stepKey = String(step || '').trim();
        var titleEl = publishLockEl.querySelector('[data-w-publish-title]');
        var msgEl = publishLockEl.querySelector('[data-w-publish-message]');
        var pctEl = publishLockEl.querySelector('[data-w-publish-pct]');
        var barEl = publishLockEl.querySelector('[data-w-publish-bar]');
        var detailEl = publishLockEl.querySelector('[data-w-publish-detail]');
        var listEl = publishLockEl.querySelector('[data-w-publish-steps]');
        if (titleEl) {
            titleEl.textContent = previewMessages.publishingTitle;
        }
        if (msgEl) {
            msgEl.textContent = msg;
        }
        if (pctEl) {
            pctEl.textContent = pct === null ? '' : (String(pct) + '%');
        }
        if (barEl) {
            barEl.style.width = (pct === null ? 8 : pct) + '%';
            barEl.setAttribute('aria-valuenow', pct === null ? '0' : String(pct));
        }
        if (detailEl) {
            detailEl.textContent = stepKey
                ? (previewMessages.stepPrefix + stepKey)
                : previewMessages.doNotClose;
        }
        if (listEl && (msg || stepKey)) {
            var line = (pct === null ? '' : ('[' + pct + '%] ')) + msg + (stepKey ? (' · ' + stepKey) : '');
            if (!publishLockSteps.length || publishLockSteps[publishLockSteps.length - 1] !== line) {
                publishLockSteps.push(line);
                if (publishLockSteps.length > 8) {
                    publishLockSteps.shift();
                }
                listEl.textContent = '';
                for (var i = 0; i < publishLockSteps.length; i++) {
                    var row = document.createElement('div');
                    row.textContent = publishLockSteps[i];
                    row.style.cssText = 'padding:2px 0;color:var(--weline-theme-color-text-muted,#64748b);font:12px/1.45 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;';
                    listEl.appendChild(row);
                }
                try { listEl.scrollTop = listEl.scrollHeight; } catch (e) {}
            }
        }
        if (publishBtn) {
            publishBtn.textContent = pct === null ? msg : (msg + ' (' + pct + '%)');
        }
    }

    function openPublishProgressLock(initialMessage, initialProgress) {
        if (publishLockEl) {
            updatePublishProgress(initialMessage, initialProgress, '');
            return;
        }
        try {
            publishLockPrevOverflow = document.documentElement.style.overflow || '';
            document.documentElement.style.overflow = 'hidden';
        } catch (e) {}
        setPreviewFloatInteractive(false);
        if (exitBtn) {
            exitBtn.disabled = true;
        }
        if (publishBtn) {
            publishBtn.disabled = true;
        }
        publishLockSteps = [];
        publishLockEl = document.createElement('div');
        publishLockEl.setAttribute('data-w-preview-publish-lock', '1');
        publishLockEl.setAttribute('role', 'alertdialog');
        publishLockEl.setAttribute('aria-modal', 'true');
        publishLockEl.setAttribute('aria-busy', 'true');
        publishLockEl.setAttribute('aria-live', 'polite');
        publishLockEl.style.cssText = [
            'position:fixed',
            'inset:0',
            'z-index:2147483400',
            'display:flex',
            'align-items:center',
            'justify-content:center',
            'background:rgba(15,23,42,0.72)',
            'padding:24px',
            'pointer-events:auto',
            'cursor:wait'
        ].join(';');
        publishLockEl.addEventListener('click', function(event) {
            try { event.preventDefault(); event.stopPropagation(); } catch (e) {}
        }, true);
        publishLockEl.addEventListener('keydown', function(event) {
            try { event.preventDefault(); event.stopPropagation(); } catch (e) {}
        }, true);

        var card = document.createElement('div');
        card.style.cssText = [
            'width:min(440px,100%)',
            'border-radius:12px',
            'background:var(--weline-theme-color-surface,#fff)',
            'color:var(--weline-theme-color-text,#0f172a)',
            'box-shadow:0 24px 60px rgba(15,23,42,0.35)',
            'padding:22px 22px 18px',
            'font:14px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif',
            'pointer-events:none'
        ].join(';');

        var title = document.createElement('h3');
        title.setAttribute('data-w-publish-title', '1');
        title.textContent = previewMessages.publishingTitle;
        title.style.cssText = 'margin:0 0 8px;font-size:18px;line-height:1.3;';

        var message = document.createElement('p');
        message.setAttribute('data-w-publish-message', '1');
        message.style.cssText = 'margin:0 0 14px;color:var(--weline-theme-color-text-muted,#475569);';

        var meta = document.createElement('div');
        meta.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0 0 8px;';
        var detail = document.createElement('div');
        detail.setAttribute('data-w-publish-detail', '1');
        detail.style.cssText = 'flex:1;min-width:0;font-size:12px;color:var(--weline-theme-color-text-muted,#64748b);';
        var pct = document.createElement('div');
        pct.setAttribute('data-w-publish-pct', '1');
        pct.style.cssText = 'font:600 13px/1 tabular-nums;color:var(--weline-theme-color-primary,#2563eb);';
        meta.appendChild(detail);
        meta.appendChild(pct);

        var track = document.createElement('div');
        track.style.cssText = [
            'height:10px',
            'border-radius:999px',
            'background:var(--weline-theme-color-border,#e2e8f0)',
            'overflow:hidden',
            'margin:0 0 14px'
        ].join(';');
        var bar = document.createElement('div');
        bar.setAttribute('data-w-publish-bar', '1');
        bar.setAttribute('role', 'progressbar');
        bar.setAttribute('aria-valuemin', '0');
        bar.setAttribute('aria-valuemax', '100');
        bar.style.cssText = [
            'height:100%',
            'width:0%',
            'border-radius:inherit',
            'background:var(--weline-theme-color-primary,#2563eb)',
            'transition:width 0.25s ease'
        ].join(';');
        track.appendChild(bar);

        var steps = document.createElement('div');
        steps.setAttribute('data-w-publish-steps', '1');
        steps.style.cssText = [
            'max-height:140px',
            'overflow:auto',
            'border-top:1px solid var(--weline-theme-color-border,#e2e8f0)',
            'padding-top:10px'
        ].join(';');

        card.appendChild(title);
        card.appendChild(message);
        card.appendChild(meta);
        card.appendChild(track);
        card.appendChild(steps);
        publishLockEl.appendChild(card);
        document.body.appendChild(publishLockEl);
        updatePublishProgress(initialMessage || previewMessages.publishing, initialProgress, '');
    }

    function openPreviewModalOverlay(buildDialog, cancelValue) {
        setPreviewFloatInteractive(false);
        return new Promise(function(resolve) {
            var overlay = document.createElement('div');
            overlay.setAttribute('data-w-preview-modal', '1');
            overlay.style.cssText = [
                'position:fixed',
                'inset:0',
                'z-index:2147483200',
                'display:flex',
                'align-items:center',
                'justify-content:center',
                'background:rgba(15,23,42,0.45)',
                'padding:20px'
            ].join(';');

            function close(value) {
                try { overlay.remove(); } catch (e) {}
                setPreviewFloatInteractive(true);
                resolve(value);
            }

            var dialog = buildDialog(close);
            overlay.appendChild(dialog);
            overlay.addEventListener('click', function(event) {
                if (event.target === overlay) {
                    close(cancelValue);
                }
            });
            document.body.appendChild(overlay);
        });
    }

    function confirmPreviewAction(message) {
        // Always use local overlay above the preview float.
        // Weline.UI.dialog can mount under z-index 100050 float → looks like “点不动”.
        return openPreviewModalOverlay(function(close) {
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

            cancelBtn.addEventListener('click', function() { close(false); });
            okBtn.addEventListener('click', function() { close(true); });

            actions.appendChild(cancelBtn);
            actions.appendChild(okBtn);
            dialog.appendChild(title);
            dialog.appendChild(body);
            dialog.appendChild(actions);
            window.setTimeout(function() { try { okBtn.focus(); } catch (e) {} }, 0);
            return dialog;
        }, false).then(function(value) {
            return value === true;
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
                if (/data-login-form|管理员登录|admin\/login|login-form/i.test(trimmed)) {
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
    
    // 发布并退出：店面预览壳是 frontend Worker，禁止走 theme.editorRequest
    //（auth=backend 需要 AREA_BACKEND + backendBinding，否则会 toast「不满足操作授权要求」
    // 再被 catch 二次 toast「网络错误」）。与退出预览一致：同源 credentials fetch。
    // Prefer SSE so bake + cache clear progress is visible; fall back to JSON.
    function consumePublishSse(response, onProgress) {
        if (!response || !response.body || typeof response.body.getReader !== 'function') {
            return Promise.reject(new Error('stream'));
        }
        var reader = response.body.getReader();
        var decoder = new TextDecoder('utf-8');
        var buffer = '';
        var finalResult = null;
        var streamError = null;
        var lastProgress = null;

        function dispatchBlock(rawBlock) {
            var lines = String(rawBlock || '').split(new RegExp('\\\\r?\\\\n'));
            var eventName = 'message';
            var dataLines = [];
            for (var i = 0; i < lines.length; i++) {
                var line = lines[i];
                if (line.indexOf('event:') === 0) {
                    eventName = line.slice(6).trim() || 'message';
                } else if (line.indexOf('data:') === 0) {
                    dataLines.push(line.slice(5).trim());
                }
            }
            if (!dataLines.length) {
                return;
            }
            var data = {};
            try {
                data = JSON.parse(dataLines.join('\\n'));
            } catch (err) {
                return;
            }
            if (eventName === 'progress' || eventName === 'start') {
                lastProgress = data && typeof data === 'object' ? data : null;
                if (typeof onProgress === 'function') {
                    onProgress(data);
                }
                return;
            }
            if (eventName === 'error' || eventName === 'failed') {
                streamError = data && typeof data === 'object' ? data : { success: false, message: 'publish failed' };
                return;
            }
            if (eventName === 'done' || eventName === 'complete') {
                finalResult = data && typeof data === 'object' ? data : { success: true, data: data };
            }
        }

        function pump() {
            return reader.read().then(function(chunk) {
                if (chunk.done) {
                    if (buffer.trim()) {
                        dispatchBlock(buffer);
                    }
                    if (streamError) {
                        return streamError;
                    }
                    if (finalResult) {
                        if (finalResult.success === false) {
                            return finalResult;
                        }
                        return {
                            success: true,
                            message: finalResult.message || 'ok',
                            code: finalResult.code || 'theme_standard_publish_ok',
                            data: finalResult.data || finalResult,
                            redirect_url: (finalResult.data && finalResult.data.redirect_url) || finalResult.redirect_url
                        };
                    }
                    // Stream ended after 100% progress without a done frame (proxy/buffer race).
                    if (lastProgress && Number(lastProgress.progress) >= 100) {
                        return {
                            success: true,
                            message: lastProgress.message || 'ok',
                            code: 'theme_standard_publish_ok_stream_tail',
                            data: {
                                redirect_url: '/'
                            }
                        };
                    }
                    return Promise.reject(new Error('incomplete'));
                }
                buffer += decoder.decode(chunk.value, { stream: true });
                var parts = buffer.split(new RegExp('\\\\r?\\\\n\\\\r?\\\\n'));
                buffer = parts.pop() || '';
                for (var j = 0; j < parts.length; j++) {
                    dispatchBlock(parts[j]);
                }
                return pump();
            });
        }
        return pump();
    }

    function resolvePublishProgressMessage(message, step) {
        var stepKey = String(step || '').trim();
        var byStep = previewMessages.progressByStep || {};
        if (stepKey && byStep[stepKey]) {
            return String(byStep[stepKey]);
        }
        var msg = String(message || '').trim();
        var sourceMap = previewMessages.progressSourceMap || {};
        if (msg && sourceMap[msg]) {
            return String(sourceMap[msg]);
        }
        return msg || previewMessages.publishing;
    }

    function resolveStorefrontPublishLocale() {
        var configured = String(previewMessages.locale || '').trim();
        if (configured && configured !== 'default') {
            return configured;
        }
        try {
            var path = String(window.location && window.location.pathname || '');
            var match = path.match(/\/(ar_SA|bn_BD|de_DE|en_US|es_ES|fr_FR|hi_IN|id_ID|ja_JP|ko_KR|pt_BR|ru_RU|th_TH|ur_PK|vi_VN|zh_Hans_CN|zh_Hant_TW|zh_CN)(?:\/|$)/);
            if (match && match[1]) {
                return match[1];
            }
        } catch (e) {}
        return '';
    }

    function postPublishAndExit(bodyObj) {
        var payload = Object.assign({ stream: 1 }, bodyObj || {});
        var locale = resolveStorefrontPublishLocale();
        if (locale && !payload.locale) {
            payload.locale = locale;
        }
        return fetch(publishUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/event-stream'
            },
            credentials: 'include',
            body: JSON.stringify(payload)
        }).then(function(response) {
            var contentType = String(response.headers.get('content-type') || '').toLowerCase();
            if (contentType.indexOf('text/event-stream') !== -1) {
                if (!response.ok) {
                    return Promise.reject(new Error('non-json:' + response.status));
                }
                return consumePublishSse(response, function(event) {
                    var step = event && event.step ? String(event.step) : '';
                    var message = resolvePublishProgressMessage(
                        event && event.message ? String(event.message) : '',
                        step
                    );
                    var progress = Number(event && event.progress);
                    if (!message && !step && !isFinite(progress)) {
                        return;
                    }
                    if ((step === 'done' || step === 'finalize_ok') && !message) {
                        message = previewMessages.finalizeDone;
                    }
                    if ((step === 'exit_preview' || step === 'compose_redirect' || step === 'redirect') && !message) {
                        message = previewMessages.exitPreviewRedirect;
                    }
                    openPublishProgressLock(message || previewMessages.publishing, progress);
                    updatePublishProgress(message || previewMessages.publishing, progress, step);
                });
            }
            return parsePreviewJson(response);
        });
    }

    /**
     * Publish-and-exit must clear HttpOnly preview cookie via gateway?exit=1
     * (document.cookie / SSE mid-stream Set-Cookie cannot). Same contract as Exit Preview.
     */
    function buildPublishExitNavigateUrl(redirectUrl) {
        var target = String(redirectUrl || '/').trim() || '/';
        try {
            var parsed = new URL(target, window.location.origin);
            if (parsed.origin === window.location.origin) {
                target = parsed.pathname + parsed.search + parsed.hash;
            }
        } catch (e) {}
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

    function tearDownPreviewFloatChrome() {
        try {
            if (floatEl && floatEl.parentNode) {
                floatEl.remove();
            }
        } catch (e) {}
        closePublishProgressLock();
        setPreviewFloatInteractive(false);
    }

    function finishPublishRedirect(data) {
        var redirectUrl = (data && data.redirect_url)
            || (data && data.data && data.data.redirect_url)
            || '/';
        updatePublishProgress(previewMessages.publishSuccessRedirect, 100, 'redirect');
        // Unmount float immediately so success never leaves Preview Mode chrome stuck.
        tearDownPreviewFloatChrome();
        clearPreviewClientState();
        try { notifyParentPreviewExit(); } catch (e) {}
        var navigateUrl = buildPublishExitNavigateUrl(redirectUrl);
        try {
            window.location.replace(navigateUrl);
        } catch (e) {
            window.location.href = navigateUrl;
        }
        // If navigation is blocked, still unlock after a short delay.
        window.setTimeout(function() {
            closePublishProgressLock();
            resetPublishControls();
            showPreviewMessage(previewMessages.publishDoneManualRefresh, 'success');
        }, 8000);
    }

    function promptNewVersionName(nextNumber, suggestedName) {
        var autoLabel = String(suggestedName || '').trim()
            || (nextNumber ? ('v' + String(nextNumber)) : 'vN');
        var message = String(previewMessages.versionMessage || '有未发布改动，请输入版本名称后发布（可留空自动 {version}）')
            .split('{version}').join(autoLabel);
        var title = previewMessages.versionTitle || '新建版本并发布';
        var placeholder = previewMessages.versionPlaceholder || '版本名称';
        var okLabel = previewMessages.versionOk || previewMessages.confirmOk || '发布';
        var cancelLabel = previewMessages.confirmCancel || '取消';

        return openPreviewModalOverlay(function(close) {
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

            var titleEl = document.createElement('h3');
            titleEl.textContent = title;
            titleEl.style.cssText = 'margin:0 0 10px;font-size:18px;line-height:1.3;';

            var body = document.createElement('p');
            body.textContent = message;
            body.style.cssText = 'margin:0 0 12px;color:#475569;';

            var input = document.createElement('input');
            input.type = 'text';
            input.placeholder = placeholder;
            input.autocomplete = 'off';
            if (autoLabel && autoLabel !== 'vN') {
                input.value = autoLabel;
            }
            input.style.cssText = 'width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font:14px/1.4 inherit;margin:0 0 18px;';

            var actions = document.createElement('div');
            actions.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;';

            var cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.textContent = cancelLabel;
            cancelBtn.style.cssText = 'padding:8px 14px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#334155;cursor:pointer;';

            var okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.textContent = okLabel;
            okBtn.style.cssText = 'padding:8px 14px;border:1px solid #2563eb;border-radius:8px;background:#2563eb;color:#fff;cursor:pointer;';

            cancelBtn.addEventListener('click', function() { close(null); });
            okBtn.addEventListener('click', function() { close(String(input.value || '')); });
            input.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    close(String(input.value || ''));
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    close(null);
                }
            });

            actions.appendChild(cancelBtn);
            actions.appendChild(okBtn);
            dialog.appendChild(titleEl);
            dialog.appendChild(body);
            dialog.appendChild(input);
            dialog.appendChild(actions);
            window.setTimeout(function() {
                try {
                    input.focus();
                    if (input.value) {
                        input.select();
                    }
                } catch (e) {}
            }, 0);
            return dialog;
        }, null);
    }

    publishBtn.addEventListener('click', function(event) {
        try { event.preventDefault(); event.stopPropagation(); } catch (e) {}
        if (publishBtn.disabled) {
            return;
        }
        confirmPreviewAction(previewMessages.confirmPublish).then(function(confirmed) {
            if (!confirmed) {
                return;
            }

            openPublishProgressLock(previewMessages.checkingGate, 0);
            publishBtn.disabled = true;
            if (exitBtn) {
                exitBtn.disabled = true;
            }

            postPublishAndExit({ token: token })
            .then(function(payload) {
                var data = unwrapPreviewPayload(payload);
                if (data && data.success) {
                    finishPublishRedirect(data);
                    return;
                }
                if (data && data.code === 'theme_publish_requires_new_version') {
                    closePublishProgressLock();
                    var nextNo = data.data && data.data.next_version_number
                        ? data.data.next_version_number
                        : '';
                    var suggested = data.data && data.data.suggested_version_name
                        ? data.data.suggested_version_name
                        : '';
                    return promptNewVersionName(nextNo, suggested).then(function(versionName) {
                        if (versionName === null) {
                            resetPublishControls();
                            return;
                        }
                        openPublishProgressLock(previewMessages.createVersionPublishing, 0);
                        return postPublishAndExit({
                            token: token,
                            create_version: true,
                            version_name: versionName
                        }).then(function(retryPayload) {
                            var retryData = unwrapPreviewPayload(retryPayload);
                            if (retryData && retryData.success) {
                                finishPublishRedirect(retryData);
                                return;
                            }
                            closePublishProgressLock();
                            showPreviewMessage(
                                (retryData && retryData.message) || previewMessages.publishFailed,
                                'error'
                            );
                            resetPublishControls();
                        });
                    });
                }
                closePublishProgressLock();
                showPreviewMessage((data && data.message) || previewMessages.publishFailed, 'error');
                resetPublishControls();
            })
            .catch(function(err) {
                console.error('[WelinePreview] publish-and-exit failed:', err);
                closePublishProgressLock();
                var errKey = err && err.message ? String(err.message) : '';
                var msg = previewMessages.networkError;
                if (errKey === 'login') {
                    msg = previewMessages.publishNeedsLogin;
                } else if (errKey.indexOf('non-json:403') === 0 || errKey.indexOf('non-json:401') === 0) {
                    msg = previewMessages.publishNeedsLogin;
                } else if (errKey && errKey !== 'empty' && errKey.indexOf('non-json:') !== 0) {
                    msg = errKey;
                }
                showPreviewMessage(msg, 'error');
                resetPublishControls();
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
