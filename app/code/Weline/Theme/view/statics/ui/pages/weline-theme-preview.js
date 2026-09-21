/* Weline UI source: statics/js/editor-mode.js */
/**
 * 主题编辑器 - iframe 内编辑模式脚本
 *
 * 这个文件在编辑模式下被注入到 iframe 中
 * 不会影响前端正式页面
 */
(function() {
    'use strict';

    const EDITOR_ORIGIN = window.location.origin;
    let activeDragWidget = null;
    let activeDragSessionId = '';
    let activeDropSlot = null;
    let activeDropCandidate = null;
    /** Parent-selected slot id (recommendation filter); preferred when under the pointer. */
    let activePreferredSlotId = '';
    /** 最近一次已画进 DOM 的插入位；同身份跳过清建。跨帧上报不靠这份状态。 */
    let lastRenderedDropCandidateKey = '';
    /**
     * 预览帧邮箱。冷事件立即 postMessage；热事件只保留最新快照，每帧最多投递一条。
     * 父页同源直调传 notifyParent:false，不进邮箱——返回值就是权威。
     */
    const PREVIEW_FRAME_HOT_TYPES = {
        'drop-candidate': true,
        'drop-candidate-clear': true,
        'slot-hover-sync': true,
    };
    const previewFrameBus = {
        pending: null,
        rafId: 0,
        lastKey: '',
    };

    function normalizeInteractionMode(mode) {
        return mode === 'preview' ? 'preview' : 'edit';
    }

    function normalizeSelectionTarget(mode) {
        const value = String(mode || '').trim().toLowerCase();
        if (value === 'slot' || value === 'widget') {
            return value;
        }
        return 'default';
    }

    function normalizeLinkBlockEnabled(value) {
        if (value === true || value === 1 || value === '1' || value === 'true' || value === 'on') {
            return true;
        }
        return false;
    }

    function readBootInteractionMode() {
        try {
            const params = new URLSearchParams(window.location.search);
            if (params.has('interaction_mode')) {
                return normalizeInteractionMode(params.get('interaction_mode'));
            }
        } catch (error) {
            // Ignore malformed URL search params.
        }
        return 'edit';
    }

    function readBootSelectionTarget() {
        try {
            const params = new URLSearchParams(window.location.search);
            if (params.has('selection_target')) {
                return normalizeSelectionTarget(params.get('selection_target'));
            }
        } catch (error) {
            // Ignore malformed URL search params.
        }
        return 'default';
    }

    function readBootLinkBlockEnabled() {
        try {
            const params = new URLSearchParams(window.location.search);
            if (params.has('link_block')) {
                return normalizeLinkBlockEnabled(params.get('link_block'));
            }
        } catch (error) {
            // Ignore malformed URL search params.
        }
        return false;
    }

    const bootInteractionMode = readBootInteractionMode();
    const bootSelectionTarget = readBootSelectionTarget();
    const bootLinkBlockEnabled = readBootLinkBlockEnabled();

    document.documentElement.dataset.wEditorPreview = 'true';
    document.documentElement.dataset.wEditorPreviewEngine = 'full';
    document.documentElement.dataset.wEditorInteraction = bootInteractionMode;
    document.documentElement.dataset.wEditorSelectionTarget = bootSelectionTarget;
    document.documentElement.dataset.wEditorLinkBlock = bootLinkBlockEnabled ? '1' : '0';

    // 启用编辑模式（真预览时不挂 editor-mode，避免闪出插槽标记）
    if (bootInteractionMode === 'edit') {
        document.body.classList.add('editor-mode');
    }
    let interactionMode = bootInteractionMode;
    let selectionTarget = bootSelectionTarget;
    let linkBlockEnabled = bootLinkBlockEnabled;

    function isEditInteractionMode() {
        return interactionMode !== 'preview';
    }

    function isSlotSelectionTarget() {
        return selectionTarget === 'slot';
    }

    function isWidgetSelectionTarget() {
        return selectionTarget === 'widget';
    }

    function isLinkBlockEnabled() {
        return linkBlockEnabled === true;
    }

    function preferredSlotHoverIndex(chain) {
        if (!chain.length) {
            return 0;
        }
        // 默认最内层（指针下真实容器）；外层大槽用穿透按钮 pin 上移，避免 header 吞掉子槽工具条。
        return deepestSlotHoverIndex(chain);
    }

    function clearEmptySlotPlaceholders() {
        document.querySelectorAll('[data-wslot].slot-mode-hit-area').forEach(function(slot) {
            slot.classList.remove('slot-mode-hit-area');
            if (slot.dataset.wSlotModeMinHeightApplied === '1') {
                slot.style.minHeight = '';
                delete slot.dataset.wSlotModeMinHeightApplied;
            }
        });
    }

    function refreshEmptySlotPlaceholders() {
        clearEmptySlotPlaceholders();
        if (!isEditInteractionMode() || !isSlotSelectionTarget()) {
            return;
        }
        document.querySelectorAll('[data-wslot]').forEach(function(slot) {
            slot.classList.add('slot-mode-hit-area');
            const rect = slot.getBoundingClientRect();
            const widgets = typeof getSlotWidgetElements === 'function'
                ? getSlotWidgetElements(slot)
                : [];
            const collapsed = rect.height < 32 || rect.width < 32 || widgets.length === 0;
            if (collapsed && (!slot.style.minHeight || parseFloat(slot.style.minHeight) < 48)) {
                slot.style.minHeight = '48px';
                slot.dataset.wSlotModeMinHeightApplied = '1';
            }
        });
    }

    function applySelectionTarget(mode) {
        selectionTarget = normalizeSelectionTarget(mode);
        document.documentElement.dataset.wEditorSelectionTarget = selectionTarget;
        if (selectionTarget === 'widget') {
            clearSlotHoverClearTimer();
            resetSlotHoverState();
            document.querySelectorAll('.slot-info-card, .slot-select-tree').forEach(function(el) {
                el.remove();
            });
        }
        if (selectionTarget === 'slot') {
            document.querySelectorAll('.widget-wrapper.show-actions, .widget-wrapper.selected').forEach(function(el) {
                el.classList.remove('show-actions', 'selected');
            });
        }
        refreshEmptySlotPlaceholders();
    }

    function isShopperRuntimeEventTarget(target) {
        if (!(target instanceof Element)) {
            return false;
        }
        // Keep native shopper widgets working inside the visual editor.
        // Do NOT include "view cart" / checkout anchors — those still navigate preview layouts.
        return !!target.closest([
            '[data-editor-interactive]',
            '[data-w-header-account]',
            '.account-logged-in',
            '.account-dropdown',
            '[data-mini-cart-trigger]',
            '[data-mini-cart-close]',
            '[data-mini-cart-overlay]',
            '[data-qty-decrease]',
            '[data-qty-increase]',
            '[data-qty-input]',
            '[data-remove-item]',
            '[data-action="add"]',
            '[data-action="add"]',
            '[data-action="wishlist-toggle"]',
            '[data-action="compare-toggle"]',
            '.weline-cart-product-add-to-cart',
            '.weline-cart-product-card-add-to-cart',
            '[data-w-product-purchase-actions]',
            '[data-purchase-loading]'
        ].join(', '));
    }

    function bindNolinkClickGuard() {
        if (document.body._nolinkClickGuardBound) {
            return;
        }
        document.body._nolinkClickGuardBound = true;
        // 捕获阶段只拦 a 的默认跳转；禁止 stopPropagation，否则父级选不中部件。
        document.addEventListener('click', function(e) {
            if (!isEditInteractionMode() || !isLinkBlockEnabled()) {
                return;
            }
            const link = e.target.closest && e.target.closest('a[href]');
            if (!link) {
                return;
            }
            if (isShopperRuntimeEventTarget(link) || link.closest('.slot-toolbar, .widget-hover-actions')) {
                return;
            }
            e.preventDefault();
        }, true);
    }

    function applyLinkBlock(enabled) {
        linkBlockEnabled = normalizeLinkBlockEnabled(enabled);
        document.documentElement.dataset.wEditorLinkBlock = linkBlockEnabled ? '1' : '0';
    }

    /**
     * 部件配置里的静态 href（如 promo-banner link）不经 Url::getFrontendUrl，
     * 在画布 iframe 最终展示/跳转前把当前请求的主题身份 query 补上。
     */
    const EDITOR_IDENTITY_CARRY_KEYS = [
        'theme_id',
        'frontend_theme_id',
        'editor_mode',
        'shell',
        'editor_context',
        'status',
        'version_id',
        'editor_area',
        'preview_area',
        'interaction_mode',
    ];

    function readEditorIdentityCarryParams() {
        try {
            const params = new URLSearchParams(window.location.search || '');
            const carry = {};
            EDITOR_IDENTITY_CARRY_KEYS.forEach(function(key) {
                if (!params.has(key)) {
                    return;
                }
                const value = String(params.get(key) || '').trim();
                if (value !== '') {
                    carry[key] = value;
                }
            });
            if (!carry.theme_id && !carry.frontend_theme_id) {
                return null;
            }
            if (!carry.editor_mode) {
                carry.editor_mode = '1';
            }
            if (!carry.shell) {
                carry.shell = 'theme-editor';
            }
            if (!carry.frontend_theme_id && carry.theme_id) {
                carry.frontend_theme_id = carry.theme_id;
            }
            return carry;
        } catch (error) {
            return null;
        }
    }

    function isEditorIdentityCarryHref(href) {
        const raw = String(href || '').trim();
        if (raw === '' || raw === '#') {
            return false;
        }
        const lower = raw.toLowerCase();
        if (
            lower.startsWith('#')
            || lower.startsWith('mailto:')
            || lower.startsWith('tel:')
            || lower.startsWith('javascript:')
            || lower.startsWith('data:')
        ) {
            return false;
        }
        try {
            const url = new URL(raw, window.location.href);
            if (url.origin !== window.location.origin) {
                return false;
            }
            return true;
        } catch (error) {
            return raw.startsWith('/') && !raw.startsWith('//');
        }
    }

    function appendEditorIdentityToHref(href, carry) {
        if (!carry || !isEditorIdentityCarryHref(href)) {
            return href;
        }
        try {
            const url = new URL(String(href), window.location.href);
            Object.keys(carry).forEach(function(key) {
                const existing = String(url.searchParams.get(key) || '').trim();
                if (existing !== '' && existing !== '0') {
                    return;
                }
                url.searchParams.set(key, String(carry[key]));
            });
            // 相对原链：同站绝对 path+query+hash，避免硬写 host 改变状态栏观感。
            return url.pathname + url.search + url.hash;
        } catch (error) {
            return href;
        }
    }

    function rewriteAnchorEditorIdentity(anchor) {
        if (!(anchor instanceof Element) || !anchor.getAttribute) {
            return;
        }
        if (anchor.getAttribute('data-w-editor-identity-carried') === '1') {
            return;
        }
        const carry = readEditorIdentityCarryParams();
        if (!carry) {
            return;
        }
        const href = anchor.getAttribute('href');
        const next = appendEditorIdentityToHref(href, carry);
        if (next && next !== href) {
            anchor.setAttribute('href', next);
        }
        anchor.setAttribute('data-w-editor-identity-carried', '1');
    }

    function rewriteStorefrontAnchors(root) {
        const carry = readEditorIdentityCarryParams();
        if (!carry) {
            return;
        }
        const scope = root && root.querySelectorAll ? root : document;
        if (scope.matches && scope.matches('a[href]')) {
            rewriteAnchorEditorIdentity(scope);
        }
        if (!scope.querySelectorAll) {
            return;
        }
        scope.querySelectorAll('a[href]').forEach(rewriteAnchorEditorIdentity);
    }

    function bindEditorIdentityHrefCarry() {
        if (document.body._editorIdentityHrefCarryBound) {
            return;
        }
        document.body._editorIdentityHrefCarryBound = true;
        // 点击兜底：动态插入、未改写到的静态配置链在跳转前补参。
        document.addEventListener('click', function(e) {
            if (isLinkBlockEnabled()) {
                return;
            }
            const link = e.target && e.target.closest && e.target.closest('a[href]');
            if (!link || isShopperRuntimeEventTarget(link) || link.closest('.slot-toolbar, .widget-hover-actions')) {
                return;
            }
            rewriteAnchorEditorIdentity(link);
        }, true);
        rewriteStorefrontAnchors(document);
    }

    function applyInteractionMode(mode) {
        interactionMode = mode === 'preview' ? 'preview' : 'edit';
        document.documentElement.dataset.wEditorInteraction = interactionMode;
        document.body.classList.toggle('editor-mode', interactionMode === 'edit');
        if (interactionMode === 'preview') {
            document.querySelectorAll('.slot-active, [data-state="selected"]').forEach(function(el) {
                el.classList.remove('slot-active');
                if (el.getAttribute('data-state') === 'selected') {
                    el.removeAttribute('data-state');
                }
            });
            document.querySelectorAll('.slot-info-card, .slot-select-tree').forEach(function(el) {
                el.remove();
            });
            document.querySelectorAll('[data-wslot]').forEach(function(slot) {
                slot._infoCardOpen = false;
                slot._selectTreeOpen = false;
            });
            document.querySelectorAll('.widget-wrapper.show-actions, .widget-wrapper.selected').forEach(function(el) {
                el.classList.remove('show-actions', 'selected');
            });
            clearSlotHoverClearTimer();
            resetSlotHoverState();
            clearEmptySlotPlaceholders();
            clearIframeDropFeedback(null, false);
            activeDragWidget = null;
            activeDragSessionId = '';
            activeDropCandidate = null;
            activePreferredSlotId = '';
        } else {
            refreshEmptySlotPlaceholders();
        }
    }

    // 选择按钮的 SVG 图标
    const SELECT_ICON = '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
    const INFO_ICON = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>';
    const INIT_ICON = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>';
    const SLOT_PENETRATE_UP_ICON = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 10.828 7.05 15.778 5.636 14.364 12 8l6.364 6.364L16.95 15.778 12 10.828z"/></svg>';
    const SLOT_PENETRATE_DOWN_ICON = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 13.172l4.95-4.95 1.414 1.414L12 16l-6.364-6.364L7.05 10.222 12 13.172z"/></svg>';

    let slotHoverChain = [];
    let slotHoverIndex = 0;
    let slotHoverPinned = false;
    let slotHoverClearTimer = 0;
    let slotHoverPendingKey = '';
    const SLOT_HOVER_STICKY_MS = 180;
    const SLOT_HOVER_CHROME_SELECTOR = '.widget-hover-actions, .slot-toolbar, .slot-select-tree, .slot-info-card';

    function getSlotChainFromElement(el) {
        const chain = [];
        let node = el;
        while (node && node.nodeType === 1 && node !== document.body) {
            if (node.hasAttribute && node.hasAttribute('data-wslot')) {
                chain.unshift(node);
            }
            node = node.parentElement;
        }
        return chain;
    }

    /** 指针下默认命中最内层插槽（用户正在指向的容器）。 */
    function deepestSlotHoverIndex(chain) {
        return Math.max(0, chain.length - 1);
    }

    function clearSlotHoverClearTimer() {
        if (slotHoverClearTimer) {
            window.clearTimeout(slotHoverClearTimer);
            slotHoverClearTimer = 0;
        }
        slotHoverPendingKey = '';
    }

    function clearSlotHoverTargets() {
        document.querySelectorAll('[data-w-slot-hover-target="true"]').forEach(function(el) {
            const toolbar = el.querySelector(':scope > .widget-hover-actions, :scope > .slot-toolbar');
            if (toolbar) {
                hideSlotToolbarChrome(toolbar);
            }
            el.removeAttribute('data-w-slot-hover-target');
            el.classList.remove('slot-hover-target');
        });
        document.querySelectorAll('.slot-penetrate-btn').forEach(function(btn) {
            btn.hidden = true;
        });
    }

    function resetSlotHoverState() {
        slotHoverChain = [];
        slotHoverIndex = 0;
        slotHoverPinned = false;
        clearSlotHoverTargets();
    }

    function slotChainKey(chain) {
        return chain.map(function(slot) {
            return slot.dataset.wslot || '';
        }).join('|');
    }

    function keepSlotHoverFromChrome(target) {
        const chrome = target && target.closest ? target.closest(SLOT_HOVER_CHROME_SELECTOR) : null;
        if (!chrome) {
            return false;
        }
        clearSlotHoverClearTimer();
        const owner = chrome.closest('[data-wslot]');
        if (!owner) {
            return true;
        }
        if (!slotHoverChain.length || slotHoverChain.indexOf(owner) < 0) {
            slotHoverChain = getSlotChainFromElement(owner);
        }
        const ownerIdx = slotHoverChain.indexOf(owner);
        slotHoverIndex = ownerIdx >= 0 ? ownerIdx : deepestSlotHoverIndex(slotHoverChain);
        applySlotHoverTarget(slotHoverChain, slotHoverIndex);
        return true;
    }

    function scheduleSlotHoverTransition(nextChain) {
        const pending = nextChain.slice();
        const pendingKey = slotChainKey(pending);
        // 同一目标过渡中勿因持续 mousemove 反复重置计时，否则离开后工具条永不消失。
        if (slotHoverClearTimer && slotHoverPendingKey === pendingKey) {
            return;
        }
        clearSlotHoverClearTimer();
        slotHoverPendingKey = pendingKey;
        slotHoverClearTimer = window.setTimeout(function() {
            slotHoverClearTimer = 0;
            slotHoverPendingKey = '';
            if (!pending.length) {
                resetSlotHoverState();
                return;
            }
            slotHoverChain = pending;
            slotHoverPinned = false;
            slotHoverIndex = preferredSlotHoverIndex(slotHoverChain);
            applySlotHoverTarget(slotHoverChain, slotHoverIndex);
        }, SLOT_HOVER_STICKY_MS);
    }

    function updateSlotPenetrateButtons(chain, index) {
        if (!chain.length) {
            return;
        }
        const target = chain[index];
        if (!target) {
            return;
        }
        const toolbar = target.querySelector(':scope > .widget-hover-actions, :scope > .slot-toolbar');
        if (!toolbar) {
            return;
        }
        const upBtn = toolbar.querySelector('.slot-penetrate-up');
        const downBtn = toolbar.querySelector('.slot-penetrate-down');
        if (upBtn) {
            upBtn.hidden = !(chain.length > 1 && index > 0);
        }
        if (downBtn) {
            downBtn.hidden = !(chain.length > 1 && index < chain.length - 1);
        }
    }

    function applySlotHoverTarget(chain, index) {
        if (!chain.length) {
            clearSlotHoverTargets();
            return;
        }
        const boundedIndex = Math.max(0, Math.min(index, chain.length - 1));
        const target = chain[boundedIndex];
        const prev = document.querySelector('[data-w-slot-hover-target="true"]');
        // 同目标勿 clear/set：避免每帧 mousemove 触发 float hide + pending 不可见
        if (prev === target) {
            updateSlotPenetrateButtons(chain, boundedIndex);
            return;
        }
        clearSlotHoverTargets();
        target.setAttribute('data-w-slot-hover-target', 'true');
        target.classList.add('slot-hover-target');
        const toolbar = target.querySelector(':scope > .widget-hover-actions, :scope > .slot-toolbar');
        if (toolbar) {
            syncSlotToolbarFloat(toolbar);
        }
        updateSlotPenetrateButtons(chain, boundedIndex);
        postPreviewMessage('slot-hover-sync', {
            slot_id: target.dataset.wslot || '',
        });
    }

    function isShopperFlyoutPointerTarget(target) {
        if (!target || typeof target.closest !== 'function') {
            return false;
        }
        return !!target.closest([
            '[data-w-header-account]',
            '.account-logged-in',
            '.account-dropdown',
            '[data-w-popover-panel]',
            '.header-category-panel',
            '[data-w-mega-menu]',
            '.category-item--mega',
            '.category-item.has-children',
        ].join(', '));
    }

    function bindSlotHoverTargetEvents() {
        if (document.body._slotHoverTargetBound) {
            return;
        }
        document.body._slotHoverTargetBound = true;

        document.body.addEventListener('mousemove', function(e) {
            if (!isEditInteractionMode() || isWidgetSelectionTarget()) {
                return;
            }
            // 店面悬停层（个人中心、分类大菜单）展开时收起插槽工具条。
            // 工具条 fixed 在 header 右上角，会盖住下拉并打断 :hover，导致菜单点不中。
            if (isShopperFlyoutPointerTarget(e.target)) {
                clearSlotHoverClearTimer();
                clearSlotHoverTargets();
                return;
            }
            // 类似 tooltip：移到工具条/选择树/信息卡时保持，可点击操作。
            if (keepSlotHoverFromChrome(e.target)) {
                return;
            }

            const chain = getSlotChainFromElement(e.target);
            const currentTarget = slotHoverChain[slotHoverIndex] || null;

            // 仍在当前目标插槽内（含后代）：立即更新，取消关闭。
            if (currentTarget && currentTarget.contains(e.target)) {
                clearSlotHoverClearTimer();
                const chainKey = slotChainKey(chain);
                const prevKey = slotChainKey(slotHoverChain);
                if (chainKey !== prevKey) {
                    slotHoverChain = chain;
                }
                if (slotHoverPinned) {
                    const keepIdx = slotHoverChain.indexOf(currentTarget);
                    slotHoverIndex = keepIdx >= 0 ? keepIdx : preferredSlotHoverIndex(slotHoverChain);
                    if (keepIdx < 0) {
                        slotHoverPinned = false;
                    }
                } else {
                    slotHoverIndex = preferredSlotHoverIndex(slotHoverChain);
                }
                applySlotHoverTarget(slotHoverChain, slotHoverIndex);
                return;
            }

            // 离开当前目标（含外置工具条与 slot 之间的空隙）：延迟切换/关闭，便于移入提示条。
            if (currentTarget && slotHoverChain.length) {
                applySlotHoverTarget(slotHoverChain, slotHoverIndex);
                scheduleSlotHoverTransition(chain);
                return;
            }

            clearSlotHoverClearTimer();
            if (!chain.length) {
                resetSlotHoverState();
                return;
            }
            slotHoverChain = chain;
            slotHoverPinned = false;
            slotHoverIndex = preferredSlotHoverIndex(slotHoverChain);
            applySlotHoverTarget(slotHoverChain, slotHoverIndex);
        });

        document.body.addEventListener('mouseleave', function() {
            scheduleSlotHoverTransition([]);
        });
    }

    function appendSlotPenetrateButtons(toolbar) {
        if (toolbar.querySelector('.slot-penetrate-up')) {
            return;
        }

        const upBtn = document.createElement('button');
        upBtn.className = 'slot-penetrate-up slot-penetrate-btn';
        upBtn.type = 'button';
        upBtn.title = '上级插槽';
        upBtn.hidden = true;
        upBtn.innerHTML = SLOT_PENETRATE_UP_ICON;
        upBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (slotHoverIndex > 0) {
                slotHoverIndex -= 1;
                slotHoverPinned = true;
                applySlotHoverTarget(slotHoverChain, slotHoverIndex);
            }
        });

        const downBtn = document.createElement('button');
        downBtn.className = 'slot-penetrate-down slot-penetrate-btn';
        downBtn.type = 'button';
        downBtn.title = '下级插槽';
        downBtn.hidden = true;
        downBtn.innerHTML = SLOT_PENETRATE_DOWN_ICON;
        downBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (slotHoverIndex < slotHoverChain.length - 1) {
                slotHoverIndex += 1;
                slotHoverPinned = true;
                applySlotHoverTarget(slotHoverChain, slotHoverIndex);
            }
        });

        toolbar.appendChild(upBtn);
        toolbar.appendChild(downBtn);
    }

    function previewFrameMessageKey(type, detail) {
        if (type === 'drop-candidate') {
            return 'drop\0' + dropCandidateIdentityKey(detail);
        }
        if (type === 'drop-candidate-clear') {
            return 'clear\0' + String((detail && detail.session_id) || '');
        }
        if (type === 'slot-hover-sync') {
            return 'hover\0' + String((detail && detail.slot_id) || '');
        }
        return String(type || '');
    }

    function deliverPreviewFrameMessage(type, detail) {
        if (window.parent === window) return;
        window.parent.postMessage({
            source: 'weline-theme-preview',
            type: type,
            lane: PREVIEW_FRAME_HOT_TYPES[type] ? 'hot' : 'cold',
            ...(detail || {})
        }, EDITOR_ORIGIN);
    }

    function flushPreviewFrameBus() {
        previewFrameBus.rafId = 0;
        const pending = previewFrameBus.pending;
        previewFrameBus.pending = null;
        if (!pending) {
            return;
        }
        deliverPreviewFrameMessage(pending.type, pending.detail);
    }

    function cancelPreviewFrameBus() {
        if (previewFrameBus.rafId) {
            cancelAnimationFrame(previewFrameBus.rafId);
            previewFrameBus.rafId = 0;
        }
        previewFrameBus.pending = null;
        previewFrameBus.lastKey = '';
    }

    /**
     * 跨帧唯一出口。热路径 latest-wins + 每帧一条；冷路径立即投递。
     */
    function postPreviewMessage(type, detail) {
        if (!PREVIEW_FRAME_HOT_TYPES[type]) {
            deliverPreviewFrameMessage(type, detail);
            return;
        }
        const key = previewFrameMessageKey(type, detail);
        if (!key || key === previewFrameBus.lastKey) {
            return;
        }
        previewFrameBus.lastKey = key;
        previewFrameBus.pending = { type: type, detail: detail || {} };
        if (previewFrameBus.rafId) {
            return;
        }
        previewFrameBus.rafId = requestAnimationFrame(flushPreviewFrameBus);
    }

    /**
     * Editor preview language switch: never navigate / write storefront cookies.
     * Forward locale to the parent Theme Editor so toolbar + preview reload together.
     * Capture phase runs before Weline UI language-switcher (which would assign location).
     */
    function readLanguageOptionLocale(option) {
        if (!(option instanceof Element)) return '';
        return String(
            option.getAttribute('data-lang')
            || option.dataset?.lang
            || option.getAttribute('data-locale')
            || ''
        ).trim();
    }

    function isEditorLanguageOption(target) {
        if (!(target instanceof Element)) return null;
        // Prefer official language-switcher options; avoid bare a[data-lang] menu stubs.
        const option = target.closest(
            '.w-language-switcher__option[data-lang], [data-language-option][data-lang], .language-option[data-lang], [data-weline-choice-switcher="language"] [data-lang]'
        );
        if (!(option instanceof Element)) return null;
        const switcher = option.closest(
            '[data-i18n-switcher], [data-w-component*="language-switcher"], .w-language-switcher, [data-weline-choice-switcher="language"]'
        );
        if (!(switcher instanceof Element)
            && !option.hasAttribute('data-language-option')
            && !option.classList.contains('w-language-switcher__option')
        ) {
            return null;
        }
        const locale = readLanguageOptionLocale(option);
        return locale ? { option, locale } : null;
    }

    document.addEventListener('click', function(event) {
        const hit = isEditorLanguageOption(event.target);
        if (!hit) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        try {
            const bag = window.__WelineLocaleSwitchTrace || (window.__WelineLocaleSwitchTrace = []);
            bag.push({
                t: Date.now(),
                stage: 'editor-mode-capture',
                locale: hit.locale,
                href: String(window.location.href || ''),
            });
            if (window.parent && window.parent !== window) {
                const parentBag = window.parent.__WelineLocaleSwitchTrace
                    || (window.parent.__WelineLocaleSwitchTrace = []);
                parentBag.push({
                    t: Date.now(),
                    stage: 'editor-mode-capture',
                    locale: hit.locale,
                    href: String(window.location.href || ''),
                    from: 'iframe-editor-mode',
                });
            }
        } catch (_error) {
        }
        postPreviewMessage('locale-change', { locale: hit.locale });
    }, true);

    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin || event.source !== window.parent) return;
        const data = event.data;
        if (!data || data.source !== 'weline-theme-editor') return;

        if (data.type === 'interaction-mode') {
            applyInteractionMode(data.mode);
            if (data.selection_target != null) {
                applySelectionTarget(data.selection_target);
            }
            if (data.link_block != null) {
                applyLinkBlock(data.link_block);
            }
            return;
        }

        if (data.type === 'selection-target') {
            applySelectionTarget(data.mode);
            return;
        }

        if (data.type === 'link-block') {
            applyLinkBlock(data.enabled);
            return;
        }

        if (data.type !== 'drag-state') return;
        if (!isEditInteractionMode()) return;

        const sessionId = String(data.session_id || '');
        if (data.phase === 'start' && sessionId && data.widget && data.widget.code) {
            clearIframeDropFeedback(null, false);
            activeDragSessionId = sessionId;
            activeDragWidget = data.widget;
            activePreferredSlotId = String(data.selected_slot_id || data.preferred_slot_id || '').trim();
            lastRenderedDropCandidateKey = '';
            cancelPreviewFrameBus();
            return;
        }

        // 迟到的旧 session 结束消息不得清掉一次新的拖拽。
        if (sessionId && activeDragSessionId && sessionId !== activeDragSessionId) return;
        clearIframeDropFeedback(null, false);
        activeDragWidget = null;
        activeDragSessionId = '';
        activeDropCandidate = null;
        activePreferredSlotId = '';
        lastRenderedDropCandidateKey = '';
        cancelPreviewFrameBus();
    });

    /**
     * 插槽 accept 协议（与 theme-editor.js / ThemePlaceableRegistry 保持一致）
     */
    function normalizeCode(value) {
        return String(value == null ? '' : value).trim().toLowerCase();
    }

    function normalizeCodeList(value) {
        if (value == null || value === false) {
            return [];
        }
        let items = [];
        if (Array.isArray(value)) {
            value.forEach(item => {
                if (Array.isArray(item)) {
                    items = items.concat(item);
                } else {
                    items.push(item);
                }
            });
        } else {
            const raw = String(value).trim();
            if (raw.startsWith('[')) {
                try {
                    return normalizeCodeList(JSON.parse(raw));
                } catch (err) {
                    // fall through
                }
            }
            items = raw.split(',');
        }
        const seen = new Set();
        items.forEach(item => {
            const code = normalizeCode(item);
            if (code) {
                seen.add(code);
            }
        });
        return Array.from(seen);
    }

    function getEditorLayoutContext() {
        const params = new URLSearchParams(window.location.search);
        return {
            pageType: normalizeCode(params.get('page_type') || ''),
            layoutType: normalizeCode(params.get('layout_type') || 'homepage'),
            layoutOption: normalizeCode(params.get('layout_option') || 'default'),
        };
    }

    function isDashboardLayoutContext() {
        const ctx = getEditorLayoutContext();
        return ctx.pageType === 'dashboard' || ctx.layoutType === 'dashboard';
    }

    function expandPageLayoutSupportCodes(pageLayouts) {
        const layouts = normalizeCodeList(pageLayouts);
        const codes = [];
        const ctx = getEditorLayoutContext();

        layouts.forEach(layout => {
            if (!layout || layout === '*') {
                return;
            }
            codes.push(`layout-${layout}`);
            if (ctx.layoutType && layout === ctx.layoutType && ctx.layoutOption && ctx.layoutOption !== 'default') {
                codes.push(`layout-${ctx.layoutType}-${ctx.layoutOption}`);
            }
        });

        return codes;
    }

    /** layout-homepage-minimal-content ↔ layout-homepage-content */
    function expandAcceptCodesForLayout(acceptCodes) {
        const normalized = normalizeCodeList(acceptCodes);
        const expanded = new Set(normalized);

        normalized.forEach(accept => {
            const match = accept.match(/^layout-([^-]+)-([^-]+)-(.+)$/);
            if (match) {
                expanded.add(`layout-${match[1]}-${match[3]}`);
            }
        });

        return Array.from(expanded);
    }

    function collectWidgetSupportCodes(widgetData) {
        const codes = [
            widgetData?.code,
            widgetData?.type,
            widgetData?.slot,
        ];

        normalizeCodeList(widgetData?.position || []).forEach(code => codes.push(code));
        normalizeCodeList(widgetData?.supports || []).forEach(code => codes.push(code));
        normalizeCodeList(widgetData?.slots || []).forEach(code => codes.push(code));
        expandPageLayoutSupportCodes(widgetData?.pageLayouts || widgetData?.page_layouts || [])
            .forEach(code => codes.push(code));

        return normalizeCodeList(codes);
    }

    function slotAcceptsWidget(acceptCodes, rejectCodes, slotId, widgetData) {
        const normalizedAccept = expandAcceptCodesForLayout(acceptCodes);
        const normalizedReject = normalizeCodeList(rejectCodes);
        const widgetCodes = collectWidgetSupportCodes(widgetData);
        const normalizedSlotId = normalizeCode(slotId);

        if (normalizedReject.some(code => widgetCodes.includes(code))) {
            return false;
        }
        if (normalizedSlotId && widgetCodes.includes(normalizedSlotId)) {
            return true;
        }
        if (normalizedAccept.length === 0 || normalizedAccept.includes('*')) {
            return true;
        }
        return normalizedAccept.some(accept => widgetCodes.includes(accept));
    }

    /**
     * 预览 iframe 优先用本页 Weline.UI；否则复用父主题编辑器已加载的 UI（与部件 hover 同源）。
     */
    function resolveEditorUi() {
        try {
            if (window.Weline && window.Weline.UI) {
                return window.Weline.UI;
            }
        } catch (err) {}
        try {
            if (window.parent && window.parent !== window && window.parent.Weline && window.parent.Weline.UI) {
                return window.parent.Weline.UI;
            }
        } catch (err) {}
        return null;
    }

    function attachSlotToolbarFloat(toolbar) {
        if (!(toolbar instanceof HTMLElement)) {
            return null;
        }
        toolbar.setAttribute('data-w-component', 'anchored-float');
        toolbar.setAttribute('data-w-float-self', '1');
        toolbar.setAttribute('data-w-placement', 'top-end');
        toolbar.setAttribute('data-w-portal', '0');
        const UI = resolveEditorUi();
        if (UI && UI.floating && typeof UI.floating.attach === 'function') {
            return UI.floating.attach(toolbar, {
                placement: 'top-end',
                portal: false,
                self: true,
            });
        }
        return null;
    }

    function syncSlotToolbarFloat(toolbar) {
        if (!(toolbar instanceof HTMLElement)) {
            return;
        }
        const UI = resolveEditorUi();
        const floatApi = UI && typeof UI.get === 'function' ? UI.get(toolbar, 'anchored-float') : null;
        if (floatApi && typeof floatApi.sync === 'function') {
            floatApi.sync();
            return;
        }
        if (floatApi && typeof floatApi.place === 'function') {
            floatApi.place();
            return;
        }
        toolbar.dispatchEvent(new CustomEvent('weline:anchored-float:place'));
    }

    function hideSlotToolbarChrome(toolbar) {
        if (!(toolbar instanceof HTMLElement)) {
            return;
        }
        const UI = resolveEditorUi();
        const floatApi = UI && typeof UI.get === 'function' ? UI.get(toolbar, 'anchored-float') : null;
        if (floatApi && typeof floatApi.hide === 'function') {
            floatApi.hide();
            return;
        }
        toolbar.dispatchEvent(new CustomEvent('weline:anchored-float:hide'));
    }

    /**
     * 构建与父页 handleSlotSelected / openWidgetPanelForSlotSelection 对齐的插槽资料。
     * 悬浮工具条必须自带这些字段，不能依赖点击时再 closest 找回插槽节点。
     */
    function buildSlotSelectionPayload(slot) {
        if (!(slot instanceof HTMLElement)) {
            return null;
        }
        const slotId = String(slot.dataset.wslot || slot.getAttribute('data-wslot') || '').trim();
        if (!slotId) {
            return null;
        }
        const currentWidgets = getSlotWidgetElements(slot);
        const maxWidgets = slot.dataset.wslotMax ? parseInt(slot.dataset.wslotMax, 10) : -1;
        return {
            id: slotId,
            name: slot.dataset.wslotName || slot.getAttribute('data-name') || slotId,
            accept: slot.dataset.wslotAccept
                ? slot.dataset.wslotAccept.split(',').map(function(s) { return s.trim(); }).filter(Boolean)
                : [],
            reject: slot.dataset.wslotReject
                ? slot.dataset.wslotReject.split(',').map(function(s) { return s.trim(); }).filter(Boolean)
                : [],
            multiple: slot.dataset.wslotMultiple !== 'false',
            exclusive: slot.dataset.wslotExclusive === 'true',
            max: maxWidgets,
            min: slot.dataset.wslotMin ? parseInt(slot.dataset.wslotMin, 10) : 0,
            current_count: currentWidgets.length,
            append: slot.dataset.wslotAppend === 'true',
            prepend: slot.dataset.wslotPrepend === 'true',
            area: slot.dataset.wslotPosition || slot.getAttribute('data-wslot-position') || '',
            position: slot.dataset.wslotPosition || slot.getAttribute('data-wslot-position') || '',
        };
    }

    function stampSlotToolbarSelection(toolbar, slot) {
        const payload = buildSlotSelectionPayload(slot);
        if (!(toolbar instanceof HTMLElement) || !payload) {
            return null;
        }
        toolbar.dataset.slotId = payload.id;
        toolbar.setAttribute('data-slot-id', payload.id);
        toolbar.setAttribute('data-slot-selection', JSON.stringify(payload));
        if (payload.area) {
            toolbar.dataset.wslotPosition = payload.area;
        }
        if (payload.name) {
            toolbar.dataset.wslotName = payload.name;
        }
        return payload;
    }

    /**
     * 为插槽添加选择按钮
     * @param {HTMLElement} slot - 插槽元素
     */
    function addSelectButton(slot) {
        // 检查是否已有按钮（与部件同用 .widget-hover-actions）
        if (slot.querySelector(':scope > .widget-hover-actions, :scope > .slot-toolbar')) return;

        if (getComputedStyle(slot).position === 'static') {
            slot.style.position = 'relative';
        }

        // 与部件 .widget-hover-actions 同源：父页 floating.attach + anchored-float
        const toolbar = document.createElement('div');
        toolbar.className = 'widget-hover-actions slot-toolbar';
        toolbar.setAttribute('data-w-component', 'anchored-float');
        toolbar.setAttribute('data-w-float-self', '1');
        toolbar.setAttribute('data-w-placement', 'top-end');
        toolbar.setAttribute('data-w-portal', '0');
        toolbar.setAttribute('data-slot-hover-actions', '1');

        const stamped = stampSlotToolbarSelection(toolbar, slot);
        const slotIdForLabel = stamped?.id || slot.dataset.wslot || slot.getAttribute('data-wslot') || '';

        // 选择按钮
        const btn = document.createElement('button');
        btn.className = 'slot-select-btn';
        const selectLabel = slotIdForLabel === 'header' || slotIdForLabel === 'footer' ? '整段' : '选择';
        btn.innerHTML = SELECT_ICON + '<span>' + selectLabel + '</span>';
        btn.setAttribute('type', 'button');
        btn.setAttribute('data-action', 'slot-select');
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            document.querySelectorAll('.slot-info-card').forEach(function(c) {
                c.remove();
            });
            closeSlotSelectTrees();
            slot._infoCardOpen = false;
            stampSlotToolbarSelection(toolbar, slot);
            selectSlot(slot);
        });

        // 信息按钮
        const infoBtn = document.createElement('button');
        infoBtn.className = 'slot-info-btn';
        infoBtn.innerHTML = INFO_ICON;
        infoBtn.setAttribute('type', 'button');
        infoBtn.setAttribute('title', '信息');
        infoBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSlotInfoCard(slot, toolbar);
        });

        // 初始化默认部件（仅显式回填；删除后刷新不会自动补）
        const initBtn = document.createElement('button');
        initBtn.className = 'slot-init-btn';
        initBtn.innerHTML = INIT_ICON + '<span>初始化</span>';
        initBtn.setAttribute('type', 'button');
        initBtn.setAttribute('data-action', 'slot-init-defaults');
        initBtn.setAttribute('title', '初始化默认部件');
        initBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const payload = stampSlotToolbarSelection(toolbar, slot) || buildSlotSelectionPayload(slot) || {};
            postPreviewMessage('slot-init-defaults', {
                slot_id: payload.id || slot.dataset.wslot || '',
                area: payload.area || slot.dataset.wslotPosition || slot.getAttribute('data-wslot-position') || '',
                name: payload.name || slot.dataset.wslotName || slot.getAttribute('data-name') || '',
            });
        });

        const kindLabel = document.createElement('span');
        kindLabel.className = 'slot-toolbar-kind';
        kindLabel.textContent = '插槽';
        kindLabel.setAttribute('aria-hidden', 'true');

        toolbar.appendChild(kindLabel);
        toolbar.appendChild(btn);
        toolbar.appendChild(initBtn);
        toolbar.appendChild(infoBtn);
        appendSlotPenetrateButtons(toolbar);
        slot.appendChild(toolbar);
        // 不在 iframe 内 attach：与部件一致，由父页挂载
        postPreviewMessage('slot-hover-sync', {
            slot_id: slotIdForLabel,
        });
    }

    function escapeSlotTreeText(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function getDirectChildSlots(slot) {
        return Array.from(slot.querySelectorAll('[data-wslot]')).filter(function(child) {
            return child !== slot && child.parentElement && child.parentElement.closest('[data-wslot]') === slot;
        });
    }

    function countDescendantSlots(slot) {
        return Array.from(slot.querySelectorAll('[data-wslot]')).filter(function(child) {
            return child !== slot;
        }).length;
    }

    function closeSlotSelectTrees(exceptSlot) {
        document.querySelectorAll('.slot-select-tree').forEach(function(card) {
            const owner = card.closest('[data-wslot]');
            if (exceptSlot && owner === exceptSlot) {
                return;
            }
            card.remove();
            if (owner) {
                owner._selectTreeOpen = false;
            }
        });
    }

    function renderSlotTreeNode(slot, depth) {
        const id = slot.dataset.wslot || '?';
        const name = slot.dataset.wslotName || id;
        const childCount = countDescendantSlots(slot);
        const children = getDirectChildSlots(slot);
        const isActive = slot.classList.contains('slot-active');
        let html = '<button type="button" class="slot-select-tree-item'
            + (isActive ? ' is-active' : '')
            + '" data-slot-tree-id="' + escapeSlotTreeText(id) + '"'
            + ' data-slot-tree-depth="' + depth + '"'
            + ' style="--slot-tree-depth:' + depth + '">'
            + '<span class="slot-select-tree-name">' + escapeSlotTreeText(name) + '</span>'
            + '<code class="slot-select-tree-id">' + escapeSlotTreeText(id) + '</code>'
            + (childCount ? '<span class="slot-select-tree-meta">' + childCount + ' 子槽</span>' : '')
            + '</button>';
        children.forEach(function(child) {
            html += renderSlotTreeNode(child, depth + 1);
        });
        return html;
    }

    function findSlotInSubtree(root, slotId) {
        if ((root.dataset.wslot || '') === slotId) {
            return root;
        }
        const nodes = root.querySelectorAll('[data-wslot]');
        for (let i = 0; i < nodes.length; i += 1) {
            if ((nodes[i].dataset.wslot || '') === slotId) {
                return nodes[i];
            }
        }
        return null;
    }

    function positionSlotFloatingCard(card) {
        requestAnimationFrame(function() {
            const cardRect = card.getBoundingClientRect();
            const vw = window.innerWidth;
            const vh = window.innerHeight;

            if (cardRect.left < 4) {
                card.style.right = 'auto';
                card.style.left = '0';
            } else if (cardRect.right > vw - 4) {
                card.style.left = 'auto';
                card.style.right = '0';
                const recalc = card.getBoundingClientRect();
                if (recalc.left < 4) {
                    card.style.right = 'auto';
                    card.style.left = (-recalc.left + 4) + 'px';
                }
            }

            if (cardRect.bottom > vh - 4) {
                card.style.top = 'auto';
                card.style.bottom = 'calc(100% + 6px)';
            }
        });
    }

    /**
     * hover「选择」：无嵌套直接选中；有子槽时弹出子树，点选后再联动右侧部件推荐。
     */
    function toggleSlotSelectTree(slot, toolbar) {
        if (!isEditInteractionMode()) {
            return;
        }

        document.querySelectorAll('.slot-info-card').forEach(function(c) {
            c.remove();
        });
        slot._infoCardOpen = false;

        if (slot._selectTreeOpen) {
            closeSlotSelectTrees();
            return;
        }

        closeSlotSelectTrees();

        if (countDescendantSlots(slot) === 0) {
            selectSlot(slot);
            return;
        }

        const card = document.createElement('div');
        card.className = 'slot-select-tree';
        card.innerHTML = ''
            + '<div class="slot-select-tree-header">'
            + '<strong>选择插槽</strong>'
            + '<span>点选后右侧推荐可用部件</span>'
            + '</div>'
            + '<div class="slot-select-tree-list">'
            + renderSlotTreeNode(slot, 0)
            + '</div>';

        card.addEventListener('click', function(e) {
            e.stopPropagation();
            const item = e.target.closest('.slot-select-tree-item');
            if (!item) {
                return;
            }
            const slotId = item.getAttribute('data-slot-tree-id') || '';
            const target = findSlotInSubtree(slot, slotId) || slot;
            closeSlotSelectTrees();
            selectSlot(target);
        });

        toolbar.appendChild(card);
        slot._selectTreeOpen = true;
        positionSlotFloatingCard(card);

        function closeTree(e) {
            if (!card.contains(e.target) && !e.target.closest('.slot-select-btn')) {
                card.remove();
                slot._selectTreeOpen = false;
                document.removeEventListener('click', closeTree, true);
            }
        }
        setTimeout(function() {
            document.addEventListener('click', closeTree, true);
        }, 0);
    }

    /**
     * 切换插槽信息卡片
     */
    function toggleSlotInfoCard(slot, toolbar) {
        // 关闭其他已打开的卡片
        document.querySelectorAll('.slot-info-card').forEach(c => c.remove());
        closeSlotSelectTrees();

        // 如果已有卡片在这个 slot，说明是切换关闭
        if (slot._infoCardOpen) {
            slot._infoCardOpen = false;
            return;
        }

        const id = slot.dataset.wslot || '?';
        const name = slot.dataset.wslotName || id;
        const isExclusive = slot.dataset.wslotExclusive === 'true';
        const isMultiple = slot.dataset.wslotMultiple === 'true';
        const acceptRaw = slot.dataset.wslotAccept || '';
        const position = slot.dataset.wslotPosition || '';
        const acceptList = acceptRaw ? acceptRaw.split(',').map(s => s.trim()) : [];

        // 模式标签
        let modeLine = '';
        if (isExclusive) {
            modeLine = '<span class="sic-badge sic-badge-exclusive">独占 · 仅1个部件</span>';
        } else if (isMultiple) {
            modeLine = '<span class="sic-badge sic-badge-multiple">可放多个部件</span>';
        } else {
            modeLine = '<span class="sic-badge sic-badge-single">单部件</span>';
        }

        // accept 列表
        let acceptLine = '';
        if (acceptList.length === 0 || acceptList.includes('*')) {
            acceptLine = '<span class="sic-tag sic-tag-all">全部</span>';
        } else {
            acceptLine = acceptList.map(c => `<span class="sic-tag">${c}</span>`).join('');
        }

        const card = document.createElement('div');
        card.className = 'slot-info-card';
        card.innerHTML = `
            <div class="sic-header">
                <strong>${name}</strong>
                <code>${id}</code>
            </div>
            <div class="sic-row">${modeLine}</div>
            ${position ? `<div class="sic-row"><span class="sic-label">区域</span><span class="sic-value">${position}</span></div>` : ''}
            <div class="sic-row"><span class="sic-label">接受</span><div class="sic-tags">${acceptLine}</div></div>
        `;

        // 点击卡片本身不冒泡
        card.addEventListener('click', function(e) { e.stopPropagation(); });

        toolbar.appendChild(card);
        slot._infoCardOpen = true;

        // 边界检测：自动调整水平和垂直方向
        requestAnimationFrame(() => {
            const cardRect = card.getBoundingClientRect();
            const vw = window.innerWidth;
            const vh = window.innerHeight;

            // 水平边界检测
            if (cardRect.left < 4) {
                // 左侧溢出 → 改为向右展开
                card.style.right = 'auto';
                card.style.left = '0';
            } else if (cardRect.right > vw - 4) {
                // 右侧溢出 → 确保向左展开（默认行为，但可能需要进一步调整）
                card.style.left = 'auto';
                card.style.right = '0';
                // 如果向左也溢出，限制最大宽度
                const recalc = card.getBoundingClientRect();
                if (recalc.left < 4) {
                    card.style.right = 'auto';
                    card.style.left = -recalc.left + 4 + 'px';
                }
            }

            // 垂直边界检测
            if (cardRect.bottom > vh - 4) {
                // 下方溢出 → 改为向上展示
                card.style.top = 'auto';
                card.style.bottom = 'calc(100% + 6px)';
            }
        });

        // 点击其他地方关闭
        function closeCard(e) {
            if (!card.contains(e.target) && !e.target.closest('.slot-info-btn')) {
                card.remove();
                slot._infoCardOpen = false;
                document.removeEventListener('click', closeCard, true);
            }
        }
        setTimeout(() => document.addEventListener('click', closeCard, true), 0);
    }

    /**
     * 选中插槽
     * @param {HTMLElement} slot - 插槽元素
     */
    function selectSlot(slot) {
        // 部件模式只触发部件，不激活插槽。
        if (isWidgetSelectionTarget()) {
            return;
        }
        const slotData = buildSlotSelectionPayload(slot);
        if (!slotData) {
            return;
        }

        postPreviewMessage('slot-selected', { slot: slotData });

        // 高亮当前插槽
        document.querySelectorAll('[data-wslot]').forEach(s => s.classList.remove('slot-active'));
        slot.classList.add('slot-active');
        slotHoverChain = getSlotChainFromElement(slot);
        slotHoverIndex = Math.max(0, slotHoverChain.indexOf(slot));
        slotHoverPinned = true;
        applySlotHoverTarget(slotHoverChain, slotHoverIndex);
    }

    // ========== iframe 内拖拽排序辅助函数 ==========

    /**
     * 获取插槽内的部件元素（widget-wrapper / data-node-uid / data-layout-id）
     * @param {HTMLElement} slot - 插槽元素
     * @returns {HTMLElement[]}
     */
    function getSlotWidgetElements(slot) {
        const candidates = Array.from(slot.querySelectorAll(
            '.widget-wrapper[data-node-uid], .widget-wrapper[data-layout-id], [data-node-uid], [data-layout-id], .widget-wrapper[data-widget-code], [data-widget-code]'
        )).filter(function(el) {
            return el.closest('[data-wslot]') === slot;
        });

        return candidates.filter(function(el, index) {
            return !candidates.slice(0, index).some(function(parent) {
                return parent.contains(el);
            });
        });
    }

    // Hex identity is node_uid only; keep data-layout-id for non-hex legacy keys.
    function resolvePreviewWidgetIdentity(element) {
        const nodeUid = String(element?.getAttribute?.('data-node-uid') || element?.dataset?.nodeUid || '')
            .trim()
            .toLowerCase();
        if (/^[a-f0-9]{32}$/.test(nodeUid)) {
            return nodeUid;
        }
        return String(element?.dataset?.layoutId || element?.getAttribute?.('data-layout-id') || '').trim();
    }

    function readDragWidgetData(event) {
        if (activeDragWidget && activeDragWidget.code) {
            return activeDragWidget;
        }

        try {
            const jsonData = event.dataTransfer.getData('application/json')
                || event.dataTransfer.getData('text/plain');
            if (!jsonData) return null;
            const data = JSON.parse(jsonData);
            return data && data.code ? data : null;
        } catch (error) {
            return null;
        }
    }

    /**
     * 计算鼠标在部件列表中的插入位置
     * @param {HTMLElement[]} items - 部件元素数组
     * @param {number} mouseY - 鼠标 clientY
     * @returns {number}
     */
    function getIframeSlotData(slot) {
        const exclusive = slot.dataset.wslotExclusive === 'true';
        const multiple = slot.dataset.wslotMultiple !== 'false';
        const maxWidgets = slot.dataset.wslotMax
            ? Number.parseInt(slot.dataset.wslotMax, 10)
            : -1;

        return {
            id: slot.dataset.wslot,
            name: slot.dataset.wslotName || slot.dataset.wslot,
            accept: normalizeCodeList(slot.dataset.wslotAccept || ''),
            reject: normalizeCodeList(slot.dataset.wslotReject || ''),
            exclusive: exclusive,
            multiple: multiple,
            max: Number.isFinite(maxWidgets) ? maxWidgets : -1,
            current_count: getSlotWidgetElements(slot).length,
            position: slot.dataset.wslotPosition || ''
        };
    }

    function buildIframeDropCandidate(slot, mouseY, widgetData) {
        if (!activeDragSessionId || !widgetData || !widgetData.code) return null;

        const items = getSlotWidgetElements(slot);
        const slotData = getIframeSlotData(slot);
        let insertIndex = 0;
        let placement = 'inside';
        let target = null;

        if (items.length > 0) {
            insertIndex = getIframeInsertionIndex(items, mouseY);
            if (insertIndex < items.length) {
                target = items[insertIndex];
                placement = 'before';
            } else {
                target = items[items.length - 1];
                placement = 'after';
            }
        }

        // sort_order must match the visual DOM insert index (templates included).
        // Persisted-only counts made "before template" become sort 0 while CoW still
        // appended after the template; keep DOM index and let the renderer insert there.
        const sortOrder = slotData.exclusive ? 0 : insertIndex;

        return {
            session_id: activeDragSessionId,
            widget: widgetData,
            slot: slotData,
            sort_order: sortOrder,
            dom_insert_index: insertIndex,
            placement: placement,
            reference_layout_id: target
                ? resolvePreviewWidgetIdentity(target)
                : '',
            pointer_y: Number.isFinite(Number(mouseY)) ? Number(mouseY) : null
        };
    }

    function getIframeInsertionIndex(items, mouseY) {
        if (items.length === 0) return 0;

        for (var i = 0; i < items.length; i++) {
            var rect = items[i].getBoundingClientRect();
            var midY = rect.top + rect.height / 2;
            if (mouseY < midY) {
                return i;
            }
        }
        return items.length;
    }

    /**
     * 显示插入位置指示器
     * @param {HTMLElement} slot - 插槽元素
     * @param {number} mouseY - 鼠标 clientY
     */
    function showIframeDropStatus(slot, text) {
        slot.querySelectorAll(':scope > .w-theme-preview-drop-feedback').forEach(function(el) { el.remove(); });
        const status = document.createElement('div');
        status.className = 'w-theme-preview-drop-feedback';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
        status.textContent = text;
        slot.appendChild(status);

        // 预览内容可能保持桌面最小宽度；提示仍须约束在 iframe 可视边界内。
        const slotRect = slot.getBoundingClientRect();
        const statusRect = status.getBoundingClientRect();
        const viewportPadding = 8;
        const halfWidth = statusRect.width / 2;
        const minCenter = viewportPadding + halfWidth;
        const maxCenter = Math.max(minCenter, window.innerWidth - viewportPadding - halfWidth);
        const preferredCenter = slotRect.left + (slotRect.width / 2);
        const viewportCenter = Math.min(maxCenter, Math.max(minCenter, preferredCenter));
        const maxTop = Math.max(viewportPadding, window.innerHeight - viewportPadding - statusRect.height);
        const viewportTop = Math.min(maxTop, Math.max(viewportPadding, slotRect.top + viewportPadding));

        status.style.setProperty('--w-theme-preview-drop-feedback-left', `${viewportCenter - slotRect.left}px`);
        status.style.setProperty('--w-theme-preview-drop-feedback-top', `${viewportTop - slotRect.top}px`);
    }

    function dropCandidateIdentityKey(candidate) {
        if (!candidate || !candidate.slot) {
            return '';
        }
        return [
            String(candidate.session_id || ''),
            String(candidate.slot.id || ''),
            String(candidate.sort_order),
            String(candidate.placement || ''),
            String(candidate.reference_layout_id || ''),
        ].join('\0');
    }

    function publishDropCandidate(candidate) {
        postPreviewMessage('drop-candidate', candidate);
        return true;
    }

    function showIframeDropFeedback(slot, mouseY, widgetData, options) {
        const candidate = buildIframeDropCandidate(slot, mouseY, widgetData);
        if (!candidate) return null;

        const nextKey = dropCandidateIdentityKey(candidate);
        // 指针仍在同一插入位：跳过 DOM 清建；消息侧已有签名去重。
        if (nextKey
            && nextKey === lastRenderedDropCandidateKey
            && activeDropSlot === slot
            && activeDropCandidate
            && dropCandidateIdentityKey(activeDropCandidate) === nextKey) {
            activeDropCandidate = candidate;
            slot._editorInsertIndex = candidate.sort_order;
            return activeDropCandidate;
        }

        if (activeDropSlot && activeDropSlot !== slot) {
            clearIframeDropFeedback(activeDropSlot, false);
        }
        clearIframeDropFeedback(slot, false);
        activeDropSlot = slot;

        activeDropCandidate = candidate;
        lastRenderedDropCandidateKey = nextKey;
        slot._editorInsertIndex = candidate.sort_order;
        slot.classList.add('w-theme-preview-drop-target');
        slot.setAttribute('data-w-drop-position', candidate.placement);

        const slotName = slot.dataset.wslotName || slot.dataset.wslot || '当前插槽';
        if (candidate.placement === 'inside') {
            showIframeDropStatus(slot, '放入 ' + slotName);
        } else {
            const items = getSlotWidgetElements(slot);
            const domIndex = Number.isFinite(Number(candidate.dom_insert_index))
                ? Number(candidate.dom_insert_index)
                : Number(candidate.sort_order);
            const target = candidate.placement === 'before'
                ? items[domIndex]
                : items[Math.max(0, (candidate.placement === 'after' ? items.length : domIndex) - 1)];
            const targetName = target?.dataset.widgetName
                || target?.dataset.widgetCode
                || target?.getAttribute('aria-label')
                || '当前块';
            target?.classList.add(candidate.placement === 'before'
                ? 'w-theme-preview-drop-before'
                : 'w-theme-preview-drop-after');
            target?.setAttribute('data-w-drop-position', candidate.placement);
            showIframeDropStatus(slot, candidate.placement === 'before'
                ? '插入到 ' + targetName + ' 前'
                : '插入到 ' + targetName + ' 后');
        }

        if (!options || options.notifyParent !== false) {
            publishDropCandidate(candidate);
        }
        return candidate;
    }

    /**
     * 父页 drop-bridge / dragend 的同源入口。
     * 返回值就是插入位；调用方传 notifyParent:false 时不进跨帧邮箱。
     * 命中策略：自 elementFromPoint 向上收集 [data-wslot]，优先最深且 accept/容量通过的插槽，
     * 避免外层 header 等容器吞掉本可落入内层/同点其它插槽的放置。
     */
    function slotHasDropCapacity(slot, widgetData) {
        const currentCount = getSlotWidgetElements(slot).length;
        const maxWidgets = slot.dataset.wslotMax ? parseInt(slot.dataset.wslotMax, 10) : -1;
        const exclusive = slot.dataset.wslotExclusive === 'true';
        const multiple = slot.dataset.wslotMultiple !== 'false';
        const singleFull = !exclusive && !multiple && currentCount >= 1;
        const maxFull = !exclusive && maxWidgets > 0 && currentCount >= maxWidgets;
        return {
            allowed: slotAcceptsWidget(
                normalizeCodeList(slot.dataset.wslotAccept || ''),
                normalizeCodeList(slot.dataset.wslotReject || ''),
                slot.dataset.wslot,
                widgetData
            ),
            full: singleFull || maxFull,
            singleFull: singleFull,
            currentCount: currentCount,
            maxWidgets: maxWidgets,
        };
    }

    function collectSlotsAtPoint(clientX, clientY) {
        const hit = document.elementFromPoint(clientX, clientY);
        const chain = [];
        const seen = new Set();
        let el = hit;
        while (el && el !== document.documentElement) {
            if (el.matches && el.matches('[data-wslot]') && !seen.has(el)) {
                seen.add(el);
                chain.push(el);
            }
            el = el.parentElement;
        }
        document.querySelectorAll('[data-wslot]').forEach(function(slot) {
            if (seen.has(slot)) return;
            const rect = slot.getBoundingClientRect();
            if (rect.width <= 0 || rect.height <= 0) return;
            if (clientX >= rect.left && clientX <= rect.right
                && clientY >= rect.top && clientY <= rect.bottom) {
                seen.add(slot);
                chain.push(slot);
            }
        });
        return { hit: hit, slots: chain };
    }

    /**
     * Prefer the parent-selected recommendation slot when it is under the pointer,
     * then keep deepest-first order so nested rejects can fall through to parents.
     */
    function orderSlotsForDrop(slots) {
        const preferredId = normalizeCode(activePreferredSlotId);
        if (!preferredId || !slots.length) {
            return slots.slice();
        }
        const preferred = [];
        const rest = [];
        slots.forEach(function(slot) {
            if (normalizeCode(slot.dataset.wslot || '') === preferredId) {
                preferred.push(slot);
            } else {
                rest.push(slot);
            }
        });
        return preferred.concat(rest);
    }

    function describeDropRejectReason(status) {
        if (!status) {
            return '该组件不能放入此插槽';
        }
        if (status.singleFull) {
            return '该插槽仅允许一个组件';
        }
        if (status.full) {
            return '该插槽已满';
        }
        return '该组件不能放入此插槽';
    }

    /**
     * Walk slot chain under the pointer; return first accept+capacity hit.
     * Does not mutate drop UI (caller decides feedback).
     */
    function resolvePreferredSlotElement() {
        const preferredId = normalizeCode(activePreferredSlotId);
        if (!preferredId) {
            return null;
        }
        const nodes = document.querySelectorAll('[data-wslot]');
        for (let i = 0; i < nodes.length; i++) {
            if (normalizeCode(nodes[i].dataset.wslot || '') === preferredId) {
                return nodes[i];
            }
        }
        return null;
    }

    function findAcceptingDropSlotAtPoint(clientX, clientY, widgetData) {
        const collected = collectSlotsAtPoint(clientX, clientY);
        if (!collected.hit && !collected.slots.length) {
            // Pointer may be over chrome; still allow parent-selected recommendation slot.
            const preferredOnly = resolvePreferredSlotElement();
            if (preferredOnly) {
                const status = slotHasDropCapacity(preferredOnly, widgetData);
                if (status.allowed && !status.full) {
                    return {
                        hit: collected.hit,
                        slot: preferredOnly,
                        invalidSlot: null,
                        reason: '',
                        status: status,
                        viaPreferred: true,
                    };
                }
            }
            return { hit: collected.hit, slot: null, invalidSlot: null, reason: '' };
        }
        const ordered = orderSlotsForDrop(collected.slots || []);
        let invalidSlot = null;
        let invalidReason = '';
        for (let i = 0; i < ordered.length; i++) {
            const slot = ordered[i];
            const status = slotHasDropCapacity(slot, widgetData);
            if (status.allowed && !status.full) {
                return { hit: collected.hit, slot: slot, invalidSlot: null, reason: '', status: status };
            }
            if (!invalidSlot) {
                invalidSlot = slot;
                invalidReason = describeDropRejectReason(status);
            }
        }

        // Selected recommendation slot accepts this widget: use it even when the
        // pointer is over a rejecting nested slot (e.g. hero) or empty chrome.
        const preferred = resolvePreferredSlotElement();
        if (preferred) {
            const status = slotHasDropCapacity(preferred, widgetData);
            if (status.allowed && !status.full) {
                return {
                    hit: collected.hit,
                    slot: preferred,
                    invalidSlot: null,
                    reason: '',
                    status: status,
                    viaPreferred: true,
                };
            }
        }

        return { hit: collected.hit, slot: null, invalidSlot: invalidSlot, reason: invalidReason };
    }

    function resolveDropAtPoint(clientX, clientY, widgetData, options) {
        if (!isEditInteractionMode()) {
            return null;
        }

        const data = widgetData || activeDragWidget;
        if (!data || !data.code) {
            return null;
        }

        const x = Number(clientX);
        const y = Number(clientY);
        if (!Number.isFinite(x) || !Number.isFinite(y)) {
            return null;
        }

        const found = findAcceptingDropSlotAtPoint(x, y, data);
        if (!found.hit) {
            clearIframeDropFeedback(null, true);
            return null;
        }
        if (!found.slot) {
            if (found.invalidSlot) {
                const invalidKey = 'invalid\0' + String(found.invalidSlot.dataset.wslot || '') + '\0' + String(found.reason || '');
                if (activeDropSlot === found.invalidSlot
                    && lastRenderedDropCandidateKey === invalidKey
                    && found.invalidSlot.classList.contains('drag-invalid')) {
                    return null;
                }
                if (activeDropSlot && activeDropSlot !== found.invalidSlot) {
                    clearIframeDropFeedback(activeDropSlot);
                }
                clearIframeDropFeedback(found.invalidSlot);
                activeDropSlot = found.invalidSlot;
                lastRenderedDropCandidateKey = invalidKey;
                found.invalidSlot.classList.add('drag-invalid');
                found.invalidSlot.setAttribute('data-w-drop-position', 'invalid');
                showIframeDropStatus(found.invalidSlot, found.reason || '该组件不能放入此插槽');
            } else {
                clearIframeDropFeedback(null, true);
            }
            return null;
        }

        return showIframeDropFeedback(found.slot, y, data, options);
    }

    /**
     * 清理所有插入反馈；取消、完成和跨 iframe 离开时都必须执行。
     * @param {HTMLElement} [scope] - 限定范围
     */
    function clearIframeDropFeedback(scope, notifyParent = true) {
        const root = scope || document;
        const clearsActive = !scope
            || activeDropSlot === scope
            || (scope.contains && activeDropSlot && scope.contains(activeDropSlot));
        const clearedSessionId = activeDropCandidate?.session_id || activeDragSessionId;

        root.querySelectorAll('.w-theme-preview-drop-feedback').forEach(function(el) { el.remove(); });
        root.querySelectorAll('.w-theme-preview-drop-before, .w-theme-preview-drop-after').forEach(function(el) {
            el.classList.remove('w-theme-preview-drop-before', 'w-theme-preview-drop-after');
            el.removeAttribute('data-w-drop-position');
        });

        const slots = [];
        if (scope && scope.matches && scope.matches('[data-wslot]')) slots.push(scope);
        root.querySelectorAll('[data-wslot]').forEach(function(slot) { slots.push(slot); });
        slots.forEach(function(slot) {
            slot.classList.remove('drag-over', 'drag-invalid', 'w-theme-preview-drop-target');
            slot.removeAttribute('data-w-drop-position');
            slot._editorInsertIndex = null;
        });

        if (clearsActive) {
            const hadCandidate = !!activeDropCandidate;
            activeDropSlot = null;
            activeDropCandidate = null;
            lastRenderedDropCandidateKey = '';
            cancelPreviewFrameBus();
            if (notifyParent && hadCandidate && clearedSessionId) {
                postPreviewMessage('drop-candidate-clear', {
                    session_id: clearedSessionId
                });
            }
        }
    }

    /**
     * 初始化单个插槽的所有交互能力
     * 包括：选择按钮、点击选中、拖放接收、占位符点击
     *
     * @param {HTMLElement} slot - 带有 data-wslot 属性的插槽元素
     */
    /**
     * Backend layout historically did not declare theme slots in its templates.
     * In editor iframe mode, expose stable structural slots without changing normal backend pages.
     */
    function initBackendStructuralSlots() {
        var isBackendLayout = document.documentElement.dataset.theme === 'backend'
            || (!!document.getElementById('layout-wrapper') && !!document.getElementById('page-topbar'));

        if (!isBackendLayout || isDashboardLayoutContext()) return;

        [
            ['#page-topbar', 'backend-topbar', 'Backend Topbar', 'header'],
            ['.topnav', 'backend-topnav', 'Backend Topnav', 'header'],
            ['.vertical-menu', 'backend-sidebar', 'Backend Sidebar', 'sidebar'],
            ['main.backend-main-content, #main-content.backend-main-content, main#main-content', 'backend-content', 'Backend Content', 'content'],
            ['.footer', 'backend-footer', 'Backend Footer', 'footer'],
            ['.right-bar', 'backend-right-sidebar', 'Backend Right Sidebar', 'right-sidebar']
        ].forEach(function(definition) {
            var slot = document.querySelector(definition[0]);
            if (!slot || slot.hasAttribute('data-wslot')) return;

            slot.setAttribute('data-wslot', definition[1]);
            slot.setAttribute('data-wslot-name', definition[2]);
            slot.setAttribute('data-wslot-accept', '*');
            slot.setAttribute('data-wslot-multiple', 'true');
            slot.setAttribute('data-wslot-position', definition[3]);
        });
    }

    function initSingleSlot(slot) {
        // 防止重复初始化
        if (slot._editorSlotInitialized) return;

        // container:<layout_id> 是内部辅助 ID，不是可放置的真实 slot。
        const slotId = String(slot.dataset.wslot || '');
        if (!slotId || slotId.indexOf('container:') === 0) return;

        slot._editorSlotInitialized = true;

        // 仅在编辑态扩展根内容 slot 的可命中画布，不改变正式前台布局和嵌套容器尺寸。
        const parentSlot = slot.parentElement?.closest('[data-wslot]');
        const slotPosition = normalizeCode(slot.dataset.wslotPosition || '');
        const normalizedSlotId = normalizeCode(slotId);
        if (!parentSlot && (
            slotPosition === 'content'
            || normalizedSlotId === 'content'
            || normalizedSlotId.endsWith('-content')
        )) {
            slot.classList.add('w-theme-preview-canvas-slot');
        }

        // 添加选择按钮
        addSelectButton(slot);

        // 禁链开启时只拦插槽内 a 跳转；不 stopPropagation，点击仍可冒泡选中部件。
        slot.addEventListener('click', function(e) {
            if (!isLinkBlockEnabled()) {
                return;
            }
            const link = e.target.closest && e.target.closest('a[href]');
            if (!link || isShopperRuntimeEventTarget(link) || link.closest('.slot-toolbar, .widget-hover-actions')) {
                return;
            }
            e.preventDefault();
        });

        // 拖放事件 — 带插入位置指示器
        slot.addEventListener('dragover', function(e) {
            if (!isEditInteractionMode()) return;
            const widgetData = readDragWidgetData(e);
            // iframe 内已有部件排序由父编辑器的 sortable 适配器处理。
            if (!widgetData) return;

            e.preventDefault();
            e.stopPropagation();
            // Walk the slot chain under the pointer (and prefer parent-selected slot).
            // Never reject solely on the innermost slot — e.g. hero exclusive reject
            // must fall through to homepage-content / selected Homepage brands.
            const candidate = resolveDropAtPoint(e.clientX, e.clientY, widgetData);
            e.dataTransfer.dropEffect = candidate ? 'copy' : 'none';
        });

        slot.addEventListener('dragleave', function(e) {
            const slotElement = this;
            const clientX = Number(e.clientX);
            const clientY = Number(e.clientY);
            requestAnimationFrame(function() {
                const pointIsVisible = Number.isFinite(clientX)
                    && Number.isFinite(clientY)
                    && clientX >= 0
                    && clientX < window.innerWidth
                    && clientY >= 0
                    && clientY < window.innerHeight;
                const hit = pointIsVisible ? document.elementFromPoint(clientX, clientY) : null;
                if (!hit || !slotElement.contains(hit)) {
                    clearIframeDropFeedback(slotElement);
                }
            });
        });

        slot.addEventListener('drop', function(e) {
            if (!isEditInteractionMode()) return;
            const widgetData = readDragWidgetData(e);
            if (!widgetData) return;

            e.preventDefault();
            e.stopPropagation();

            // Resolve accepting slot under pointer (may be parent/selected, not `this`).
            const found = findAcceptingDropSlotAtPoint(e.clientX, e.clientY, widgetData);
            const targetSlot = found.slot || null;
            if (!targetSlot) {
                clearIframeDropFeedback(this, false);
                if (found.invalidSlot) {
                    found.invalidSlot.classList.add('drag-invalid');
                    setTimeout(() => found.invalidSlot.classList.remove('drag-invalid'), 500);
                    postPreviewMessage('widget-rejected', {
                        widget: widgetData,
                        slot: getIframeSlotData(found.invalidSlot),
                        reason: found.reason || `插槽不接受部件 "${widgetData.name || widgetData.code}"`
                    });
                }
                return;
            }

            // 正常 drop 与父页 fallback 使用完全相同的候选，位置不会在释放瞬间重算漂移。
            const candidate = activeDropSlot === targetSlot
                && activeDropCandidate?.session_id === activeDragSessionId
                ? activeDropCandidate
                : buildIframeDropCandidate(targetSlot, e.clientY, widgetData);
            const insertIndex = candidate?.sort_order ?? targetSlot._editorInsertIndex;

            // 清理视觉状态，但保留父页候选直到提交消息到达。
            clearIframeDropFeedback(targetSlot, false);

            const isExclusive = targetSlot.dataset.wslotExclusive === 'true';
            const isMultiple = targetSlot.dataset.wslotMultiple !== 'false';
            const maxAttr = targetSlot.dataset.wslotMax;
            const maxWidgets = maxAttr ? parseInt(maxAttr, 10) : -1;
            const currentWidgets = getSlotWidgetElements(targetSlot);
            const currentCount = currentWidgets.length;

            const slotData = getIframeSlotData(targetSlot);

            let sortOrder;
            if (isExclusive) {
                sortOrder = 0;
            } else if (candidate && Number.isFinite(candidate.sort_order)) {
                sortOrder = candidate.sort_order;
            } else if (insertIndex != null) {
                sortOrder = insertIndex;
            } else {
                sortOrder = currentCount;
            }

            if (!widgetData) {
                console.error('No widget data found in drop event');
                postPreviewMessage('widget-dropped', {
                    widget: null,
                    slot: slotData,
                    sort_order: sortOrder,
                    missing_data: true
                });
                return;
            }

            const allowed = slotAcceptsWidget(
                slotData.accept,
                slotData.reject,
                slotData.id,
                widgetData
            );

            if (!allowed) {
                postPreviewMessage('widget-rejected', {
                    widget: widgetData,
                    slot: slotData,
                    reason: `插槽 "${slotData.name}" 不接受部件 "${widgetData.name || widgetData.code}"`
                });
                targetSlot.classList.add('drag-invalid');
                setTimeout(() => targetSlot.classList.remove('drag-invalid'), 500);
                return;
            }

            // 满额检查（独占模式走替换逻辑，不受此限制）
            if (!isExclusive && !isMultiple && currentCount >= 1) {
                postPreviewMessage('widget-rejected', {
                    widget: widgetData,
                    slot: slotData,
                    reason: `插槽 "${slotData.name}" 仅允许一个组件`
                });
                return;
            }

            if (!isExclusive && maxWidgets > 0 && currentCount >= maxWidgets) {
                postPreviewMessage('widget-rejected', {
                    widget: widgetData,
                    slot: slotData,
                    reason: `插槽 "${slotData.name}" 已满（${currentCount}/${maxWidgets}）`
                });
                return;
            }

            // 通知父窗口部件被放入插槽（附带 sort_order）
            postPreviewMessage('widget-dropped', {
                session_id: activeDragSessionId,
                widget: widgetData,
                slot: slotData,
                sort_order: sortOrder,
                placement: candidate?.placement || 'inside',
                reference_layout_id: candidate?.reference_layout_id || ''
            });
            activeDragWidget = null;
            activePreferredSlotId = '';

            // 显示成功动画
            targetSlot.classList.add('slot-highlight');
            setTimeout(() => targetSlot.classList.remove('slot-highlight'), 1500);
        });

        // 初始化插槽内的占位符点击事件
        slot.querySelectorAll('.slot-placeholder').forEach(function(placeholder) {
            if (placeholder._editorPlaceholderInitialized) return;
            placeholder._editorPlaceholderInitialized = true;
            placeholder.addEventListener('click', function(e) {
                e.stopPropagation();
                const slotArea = this.closest('[data-wslot]');
                if (slotArea) {
                    selectSlot(slotArea);
                }
            });
        });
    }

    /**
     * 初始化所有插槽
     */
    function initSlots() {
        initBackendStructuralSlots();
        bindSlotHoverTargetEvents();
        bindNolinkClickGuard();
        bindEditorIdentityHrefCarry();
        document.querySelectorAll('[data-wslot]').forEach(initSingleSlot);

        // 初始化不在插槽内的独立占位符
        document.querySelectorAll('.slot-placeholder').forEach(function(placeholder) {
            if (placeholder._editorPlaceholderInitialized) return;
            placeholder._editorPlaceholderInitialized = true;
            placeholder.addEventListener('click', function(e) {
                e.stopPropagation();
                const slotArea = this.closest('[data-wslot]');
                if (slotArea) {
                    selectSlot(slotArea);
                }
            });
        });
        refreshEmptySlotPlaceholders();
        reportWidgetHtmlHealth();
    }

    /**
     * 读取服务端挂在 widget-wrapper 上的 HTML 健康检测结果，用 Toast 提示（禁止 alert）。
     * DEV / 预览态由 SlotRendererService 写入 data-w-widget-health*。
     * 明细 toast 带「定位」：用 CSS 强制把异常 .widget-wrapper 弹窗暴露。
     */
    function ensureWidgetHealthLocateStyles() {
        if (document.getElementById('w-widget-health-locate-style')) return;
        var style = document.createElement('style');
        style.id = 'w-widget-health-locate-style';
        style.textContent = [
            'html.w-widget-health-locate-open{overflow:hidden!important;}',
            '.w-widget-health-locate-backdrop{position:fixed;inset:0;z-index:calc(var(--weline-z-toast,1100) + 20);border:0;padding:0;margin:0;cursor:pointer;background:color-mix(in srgb,var(--weline-theme-text,#111) 42%,transparent);}',
            '.w-widget-health-locate-host{outline:3px solid var(--weline-theme-warning,#c9a227)!important;outline-offset:4px!important;position:relative!important;z-index:calc(var(--weline-z-toast,1100) + 19)!important;min-block-size:3rem!important;background:color-mix(in srgb,var(--weline-theme-warning,#c9a227) 8%,var(--weline-theme-surface,#fff))!important;}',
            '.w-widget-health-locate-pop,.w-widget-health-locate-panel{position:fixed!important;inset-block-start:50%!important;inset-inline-start:50%!important;transform:translate(-50%,-50%)!important;z-index:calc(var(--weline-z-toast,1100) + 21)!important;width:min(40rem,calc(100dvw - 2rem))!important;max-block-size:min(80dvh,calc(100dvh - 2rem))!important;overflow:auto!important;margin:0!important;padding:var(--weline-space-4,1rem)!important;display:grid!important;gap:var(--weline-space-3,.75rem)!important;background:var(--weline-theme-surface-raised,#fff)!important;color:var(--weline-theme-text,#111)!important;border:2px solid var(--weline-theme-danger,#b42318)!important;border-radius:var(--weline-radius-md,8px)!important;box-shadow:var(--weline-theme-shadow-md,0 12px 32px rgba(0,0,0,.28))!important;}',
            '.w-widget-health-locate-chrome{display:flex;justify-content:space-between;align-items:center;gap:var(--weline-space-2,.5rem);}',
            '.w-widget-health-locate-title{font-weight:600;margin:0;}',
            '.w-widget-health-locate-meta{font-size:var(--weline-font-size-sm,.875rem);color:var(--weline-theme-text-muted,#666);}',
            '.w-widget-health-locate-issue{display:grid;gap:var(--weline-space-1,.25rem);padding:var(--weline-space-3,.75rem);border:1px solid var(--weline-theme-border,#ddd);border-radius:var(--weline-radius-sm,6px);background:var(--weline-theme-surface,#fff);}',
            '.w-widget-health-locate-detail{margin:0;padding:var(--weline-space-2,.5rem);overflow:auto;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:var(--weline-font-size-sm,.875rem);background:color-mix(in srgb,var(--weline-theme-danger,#b42318) 8%,var(--weline-theme-surface,#fff));border-radius:var(--weline-radius-sm,6px);}'
        ].join('');
        (document.head || document.documentElement).appendChild(style);
    }
    function dismissWidgetHealthLocate() {
        document.querySelectorAll('[data-w-widget-health-locate-active="1"]').forEach(function (node) {
            node.classList.remove('w-widget-health-locate-pop');
            node.classList.remove('w-widget-health-locate-host');
            node.removeAttribute('data-w-widget-health-locate-active');
        });
        document.querySelectorAll('[data-w-widget-health-locate-panel],[data-w-widget-health-locate-chrome],.w-widget-health-locate-backdrop').forEach(function (node) {
            node.remove();
        });
        document.documentElement.classList.remove('w-widget-health-locate-open');
        try { document.removeEventListener('keydown', onWidgetHealthLocateKeydown, true); } catch (e) {}
    }
    function onWidgetHealthLocateKeydown(event) {
        if (event && event.key === 'Escape') dismissWidgetHealthLocate();
    }
    function findWidgetHealthNode(item) {
        var nodes = document.querySelectorAll('.widget-wrapper[data-w-widget-health]');
        var slot = String((item && item.slot) || '');
        var code = String((item && item.code) || '');
        var matched = null;
        nodes.forEach(function (node) {
            if (!(node instanceof HTMLElement) || matched) return;
            var nodeSlot = String(node.dataset.slotId || '');
            var nodeCode = String(node.dataset.widgetCode || '');
            if (slot && code && nodeSlot === slot && nodeCode === code) matched = node;
            else if (!matched && slot && nodeSlot === slot) matched = node;
            else if (!matched && code && nodeCode === code) matched = node;
        });
        if (!matched && item && item.el instanceof HTMLElement) matched = item.el;
        return matched;
    }
    function resolveLocateHost(node, item) {
        if (!(node instanceof HTMLElement)) return null;
        var slot = String((item && item.slot) || node.dataset.slotId || '').trim();
        if (slot) {
            try {
                var byAttr = document.querySelector('[data-wslot="' + slot.replace(/"/g, '') + '"]');
                if (byAttr instanceof HTMLElement) return byAttr;
            } catch (e) {}
        }
        var closest = node.closest('[data-wslot]');
        if (closest instanceof HTMLElement) return closest;
        if (node.parentElement instanceof HTMLElement) return node.parentElement;
        return node;
    }
    function collectHealthIssues(item, node) {
        var issues = [];
        if (item && Array.isArray(item.issues)) issues = item.issues.slice();
        if (!issues.length && node) {
            try {
                var parsed = JSON.parse(node.getAttribute('data-w-widget-health-issues') || '[]');
                if (Array.isArray(parsed)) issues = parsed;
            } catch (e) {}
        }
        return issues;
    }
    function forceExposeHealthPanel(item, node, host) {
        var issues = collectHealthIssues(item, node);
        var panel = document.createElement('section');
        panel.className = 'w-widget-health-locate-pop w-widget-health-locate-panel';
        panel.setAttribute('data-w-widget-health-locate-panel', '1');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        var titleText = String((item && (item.name || item.code)) || (node && (node.getAttribute('data-widget-name') || node.dataset.widgetCode)) || 'widget');
        var slotText = String((item && item.slot) || (node && node.dataset.slotId) || (host && host.getAttribute('data-wslot')) || '');
        if (slotText) titleText += ' @' + slotText;
        var chrome = document.createElement('div');
        chrome.className = 'w-widget-health-locate-chrome';
        chrome.setAttribute('data-w-widget-health-locate-chrome', '1');
        var title = document.createElement('h2');
        title.className = 'w-widget-health-locate-title';
        title.textContent = '部件异常：' + titleText;
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'w-button';
        closeBtn.dataset.size = 'sm';
        closeBtn.dataset.tone = 'neutral';
        closeBtn.textContent = '关闭定位';
        closeBtn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            dismissWidgetHealthLocate();
        });
        chrome.appendChild(title);
        chrome.appendChild(closeBtn);
        panel.appendChild(chrome);
        var meta = document.createElement('p');
        meta.className = 'w-widget-health-locate-meta';
        meta.textContent = '上层容器：' + (host && host.getAttribute('data-wslot')
            ? ('[data-wslot="' + host.getAttribute('data-wslot') + '"]')
            : (host && host.className ? ('.' + String(host.className).split(/\\s+/).filter(Boolean).join('.')) : 'parent'));
        panel.appendChild(meta);
        if (!issues.length) {
            var empty = document.createElement('p');
            empty.textContent = '未解析到错误明细，已高亮上层容器。';
            panel.appendChild(empty);
        } else {
            issues.forEach(function (issue) {
                var block = document.createElement('div');
                block.className = 'w-widget-health-locate-issue';
                var msg = document.createElement('strong');
                msg.textContent = String((issue && (issue.message || issue.code)) || 'HTML 异常');
                block.appendChild(msg);
                var detail = String((issue && issue.detail) || '').trim();
                if (detail) {
                    var pre = document.createElement('pre');
                    pre.className = 'w-widget-health-locate-detail';
                    pre.textContent = detail;
                    block.appendChild(pre);
                }
                panel.appendChild(block);
            });
        }
        document.body.appendChild(panel);
        return panel;
    }
    function locateHealthWidget(item) {
        ensureWidgetHealthLocateStyles();
        dismissWidgetHealthLocate();
        var node = findWidgetHealthNode(item);
        if (!node) {
            showWidgetHealthToast('无法定位异常部件' + ((item && item.slot) ? (' @' + item.slot) : ''), 'warning');
            return;
        }
        var host = resolveLocateHost(node, item) || node;
        var backdrop = document.createElement('button');
        backdrop.type = 'button';
        backdrop.className = 'w-widget-health-locate-backdrop';
        backdrop.setAttribute('aria-label', '关闭部件定位');
        backdrop.addEventListener('click', dismissWidgetHealthLocate);
        document.body.appendChild(backdrop);
        host.classList.add('w-widget-health-locate-host');
        host.setAttribute('data-w-widget-health-locate-active', '1');
        forceExposeHealthPanel(item, node, host);
        document.documentElement.classList.add('w-widget-health-locate-open');
        document.addEventListener('keydown', onWidgetHealthLocateKeydown, true);
        try { host.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (e) {}
    }
    function buildHealthToastMessage(text, item) {
        const wrap = document.createElement('div');
        wrap.style.display = 'grid';
        wrap.style.gap = '0.5rem';
        const copy = document.createElement('span');
        copy.textContent = text;
        wrap.appendChild(copy);
        if (item) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-button';
            btn.dataset.size = 'sm';
            btn.dataset.tone = 'primary';
            btn.textContent = '定位';
            btn.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                locateHealthWidget(item);
            });
            wrap.appendChild(btn);
        }
        return wrap;
    }

    function reportWidgetHtmlHealth() {
        if (document.documentElement.dataset.wWidgetHealthReported === '1') {
            return;
        }
        const nodes = document.querySelectorAll('.widget-wrapper[data-w-widget-health]');
        if (!nodes.length) {
            return;
        }
        document.documentElement.dataset.wWidgetHealthReported = '1';

        const findings = [];
        nodes.forEach(function(node) {
            if (!(node instanceof HTMLElement)) {
                return;
            }
            const severity = String(node.dataset.wWidgetHealth || 'warning').toLowerCase();
            let issues = [];
            try {
                const raw = node.getAttribute('data-w-widget-health-issues') || '[]';
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    issues = parsed;
                }
            } catch (err) {
                issues = [{ severity: severity, code: 'parse_error', message: '部件健康数据解析失败' }];
            }
            if (!issues.length) {
                return;
            }
            findings.push({
                severity: severity,
                code: String(node.dataset.widgetCode || ''),
                module: String(node.dataset.widgetModule || ''),
                slot: String(node.dataset.slotId || ''),
                name: String(node.dataset.widgetName || node.getAttribute('data-widget-name') || node.dataset.widgetCode || 'widget'),
                issues: issues,
                el: node,
            });
        });

        if (!findings.length) {
            return;
        }

        const errorCount = findings.filter(function(item) { return item.severity === 'error'; }).length;
        const warningCount = findings.filter(function(item) { return item.severity === 'warning'; }).length;
        const summaryTone = errorCount > 0 ? 'error' : (warningCount > 0 ? 'warning' : 'info');
        const summary = '部件 HTML 健康检测：' + findings.length + ' 个部件异常'
            + (errorCount ? '（错误 ' + errorCount + '）' : '')
            + (warningCount ? '（警告 ' + warningCount + '）' : '');

        showWidgetHealthToast(summary, summaryTone, findings.length === 1 ? findings[0] : null);

        findings.slice(0, 8).forEach(function(item) {
            const first = item.issues[0] || {};
            const detail = (item.name || item.code || 'widget')
                + (item.slot ? ' @' + item.slot : '')
                + '：'
                + String(first.message || first.code || 'HTML 异常');
            const tone = item.severity === 'error' ? 'error' : (item.severity === 'warning' ? 'warning' : 'info');
            showWidgetHealthToast(detail, tone, item);
        });

        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    source: 'weline-theme-preview',
                    type: 'widget-health',
                    summary: summary,
                    severity: summaryTone,
                    findings: findings.map(function(item) {
                        return {
                            severity: item.severity,
                            code: item.code,
                            module: item.module,
                            slot: item.slot,
                            name: item.name,
                            issues: item.issues,
                        };
                    }),
                }, EDITOR_ORIGIN);
            }
        } catch (err) {
            // cross-origin parent: local toast already shown
        }
    }

    function showWidgetHealthToast(message, type, item) {
        const text = String(message || '').trim();
        if (!text) {
            return;
        }
        const tone = type === 'error' ? 'danger' : (['success', 'warning', 'info', 'danger'].includes(type) ? type : 'info');
        const payload = item ? buildHealthToastMessage(text, item) : text;
        const duration = item ? 0 : (type === 'error' ? 8000 : 5000);
        try {
            const UI = resolveEditorUi();
            if (UI && UI.toast && typeof UI.toast.show === 'function') {
                UI.toast.show(payload, { tone: tone, duration: duration });
                return;
            }
        } catch (err) {}
        try {
            if (window.Weline && window.Weline.UI && window.Weline.UI.toast && typeof window.Weline.UI.toast.show === 'function') {
                window.Weline.UI.toast.show(payload, { tone: tone, duration: duration });
                return;
            }
        } catch (err) {}
        try {
            console.warn('[ThemePreview][widget-health]', text);
        } catch (err) {}
    }

    // DOM 加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSlots);
    } else {
        initSlots();
    }

    // Dense widget childList: disconnect + trailing idle (never double-rAF reobserve).
    const pendingSlots = new Set();
    const pendingWidgets = new Set();
    const slotObserveOptions = { childList: true, subtree: true };
    if (document.body) {
        const coalesce = (typeof window.Weline !== 'undefined'
            && window.Weline.dom
            && typeof window.Weline.dom.observe === 'function')
            ? window.Weline.dom.observe.bind(window.Weline.dom)
            : (typeof window.Weline !== 'undefined'
            && typeof window.Weline.observeMutationsCoalesced === 'function')
            ? window.Weline.observeMutationsCoalesced
            : function observeMutationsCoalescedFallback(spec) {
                /* ARCH_MO_FALLBACK_START */
                const options = (spec && spec.options) || { childList: true, subtree: true };
                const idleTimeoutMs = 100;
                let idleHandle = null;
                let timeoutHandle = null;
                let disposed = false;
                const clearTimers = function() {
                    if (idleHandle != null && typeof window.cancelIdleCallback === 'function') {
                        try { window.cancelIdleCallback(idleHandle); } catch (_) {}
                        idleHandle = null;
                    }
                    if (timeoutHandle != null) {
                        window.clearTimeout(timeoutHandle);
                        timeoutHandle = null;
                    }
                };
                const runFlush = function() {
                    if (disposed) return;
                    clearTimers();
                    try { mo.disconnect(); } catch (_) {}
                    try {
                        if (typeof spec.onFlush === 'function') spec.onFlush();
                    } finally {
                        if (!disposed && document.body) {
                            try { mo.observe(document.body, options); } catch (_) {}
                        }
                    }
                };
                const schedule = function() {
                    if (disposed) return;
                    clearTimers();
                    try { mo.disconnect(); } catch (_) {}
                    timeoutHandle = window.setTimeout(function() {
                        timeoutHandle = null;
                        var run = function() {
                            idleHandle = null;
                            runFlush();
                        };
                        if (typeof window.requestIdleCallback === 'function') {
                            idleHandle = window.requestIdleCallback(run, { timeout: 50 });
                        } else {
                            run();
                        }
                    }, idleTimeoutMs);
                };
                const mo = new MutationObserver(function(records) {
                    if (disposed) return;
                    try { mo.disconnect(); } catch (_) {}
                    if (typeof spec.onRecords === 'function') {
                        try { spec.onRecords(records); } catch (_) {}
                    }
                    schedule();
                });
                try { mo.observe(document.body, options); } catch (_) {}
                return { disconnect: function() {
                    disposed = true;
                    clearTimers();
                    try { mo.disconnect(); } catch (_) {}
                } };
                /* ARCH_MO_FALLBACK_END */
            };
        coalesce({
            target: document.body,
            options: slotObserveOptions,
            idleTimeoutMs: 100,
            onRecords: function(mutations) {
                mutations.forEach(function(mutation) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType !== 1) {
                            return;
                        }
                        if (node.hasAttribute && node.hasAttribute('data-wslot')) {
                            pendingSlots.add(node);
                        }
                        if (node.matches && node.matches('[data-layout-id], [data-node-uid], [data-weline-template-widget="1"]')) {
                            pendingWidgets.add(node);
                        }
                        if (node.querySelectorAll) {
                            node.querySelectorAll('[data-wslot]').forEach(function(el) {
                                pendingSlots.add(el);
                            });
                            node.querySelectorAll('[data-layout-id], [data-node-uid], [data-weline-template-widget="1"]').forEach(function(el) {
                                pendingWidgets.add(el);
                            });
                        }
                    });
                });
            },
            onFlush: function() {
                const batch = Array.from(pendingSlots);
                const widgetCount = pendingWidgets.size;
                pendingSlots.clear();
                pendingWidgets.clear();
                // Only newly added slots — never full-body querySelectorAll rescan (feeds delivery_storm).
                batch.forEach(initSingleSlot);
                if (batch.length || widgetCount) {
                    postPreviewMessage('preview-structure-changed', {
                        slots: batch.length,
                        widgets: widgetCount,
                    });
                    batch.forEach(function(slot) {
                        rewriteStorefrontAnchors(slot);
                    });
                    if (widgetCount) {
                        rewriteStorefrontAnchors(document);
                    }
                }
            },
        });
    }

    document.addEventListener('drop', function() {
        activeDragWidget = null;
        clearIframeDropFeedback(null, false);
    });

    window.addEventListener('pagehide', function() {
        clearIframeDropFeedback(null, false);
        activeDragWidget = null;
        activeDragSessionId = '';
        activeDropCandidate = null;
    });

    window.Weline = window.Weline || {};
    window.Weline.Theme = window.Weline.Theme || {};
    window.Weline.Theme.Preview = Object.assign({}, window.Weline.Theme.Preview || {}, {
        resolveDropAtPoint: resolveDropAtPoint,
        clearDropFeedback: function() {
            clearIframeDropFeedback(null, true);
        },
        getActiveDropCandidate: function() {
            return activeDropCandidate;
        },
    });

})();

/* Weline UI source: ui/js/pages/theme-preview.js */
const root = document.documentElement;
const parentOrigin = window.location.origin;
const mountedSlots = new WeakSet();

function integer(value, fallback) {
    const parsed = Number.parseInt(String(value ?? ''), 10);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function list(value) {
    return String(value || '')
        .split(',')
        .map((item) => item.trim().toLowerCase())
        .filter(Boolean);
}

function directWidgets(slot) {
    return [...slot.querySelectorAll('[data-layout-id], [data-widget-code]')]
        .filter((widget) => widget.closest('[data-wslot]') === slot);
}

function slotPayload(slot) {
    return {
        id: String(slot.dataset.wslot || ''),
        name: String(slot.dataset.wslotName || slot.dataset.wslot || ''),
        accept: list(slot.dataset.wslotAccept),
        reject: list(slot.dataset.wslotReject),
        multiple: slot.dataset.wslotMultiple !== 'false',
        exclusive: slot.dataset.wslotExclusive === 'true',
        min: integer(slot.dataset.wslotMin, 0),
        max: integer(slot.dataset.wslotMax, -1),
        current_count: directWidgets(slot).length,
        position: String(slot.dataset.wslotPosition || ''),
    };
}

function notify(type, detail = {}) {
    if (window.parent === window) return;
    window.parent.postMessage({ source: 'weline-theme-preview', type, ...detail }, parentOrigin);
}

function selectSlot(slot) {
    document.querySelectorAll('[data-wslot][data-state="selected"]').forEach((candidate) => {
        if (candidate !== slot) candidate.removeAttribute('data-state');
    });
    slot.dataset.state = 'selected';
    notify('slot-selected', { slot: slotPayload(slot) });
}

function mountSlot(slot) {
    if (!(slot instanceof HTMLElement) || mountedSlots.has(slot)) return;
    mountedSlots.add(slot);
    const name = String(slot.dataset.wslotName || slot.dataset.wslot || 'Slot');
    if (!slot.hasAttribute('aria-label')) slot.setAttribute('aria-label', name);

    // 完整编辑引擎已经提供 slot 选择、信息面板和拖放能力；此处只做无引擎时的最小适配。
    if (root.dataset.wEditorPreviewEngine === 'full') return;

    const action = document.createElement('button');
    action.type = 'button';
    action.className = 'w-theme-preview-slot-action';
    action.dataset.wPreviewSlotAction = 'select';
    action.textContent = `选择 · ${name}`;
    action.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        selectSlot(slot);
    });
    slot.append(action);
}

function mount(scope = document) {
    if (scope instanceof Element && scope.matches('[data-wslot]')) mountSlot(scope);
    scope.querySelectorAll?.('[data-wslot]').forEach(mountSlot);
}

function clearPreviewClientState() {
    document.cookie = 'weline_preview_token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    try {
        localStorage.removeItem('weline_preview_float_pos');
    } catch (error) {
        // Ignore storage failures.
    }
    try {
        sessionStorage.removeItem('weline_live_preview_token');
    } catch (error) {
        // Ignore storage failures.
    }
    try {
        if (window.WelineThemePreviewBootstrap
            && typeof window.WelineThemePreviewBootstrap.clearClientToken === 'function') {
            window.WelineThemePreviewBootstrap.clearClientToken();
        }
    } catch (error) {
        // Ignore bootstrap availability failures.
    }
}

function stripPreviewTokenFromUrl() {
    try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('weline_preview_token')) {
            return '';
        }
        url.searchParams.delete('weline_preview_token');
        return url.toString();
    } catch (error) {
        return '';
    }
}

function buildExitRedirectTarget() {
    const cleanUrl = stripPreviewTokenFromUrl();
    if (cleanUrl) {
        try {
            const parsed = new URL(cleanUrl, window.location.origin);
            return parsed.pathname + parsed.search + parsed.hash;
        } catch (error) {
            return cleanUrl;
        }
    }
    try {
        const current = new URL(window.location.href);
        current.searchParams.delete('weline_preview_token');
        return current.pathname + current.search + current.hash;
    } catch (error) {
        return window.location.pathname + window.location.search + window.location.hash;
    }
}

function buildExitNavigateUrl(exitUrl, previewToken) {
    const target = buildExitRedirectTarget();
    try {
        const gateway = new URL(exitUrl, window.location.origin);
        gateway.searchParams.set('exit', '1');
        gateway.searchParams.set('redirect', target);
        if (previewToken) {
            gateway.searchParams.set('token', previewToken);
        }
        return gateway.toString();
    } catch (error) {
        const joinChar = exitUrl.includes('?') ? '&' : '?';
        return `${exitUrl}${joinChar}exit=1&redirect=${encodeURIComponent(target)}`
            + (previewToken ? `&token=${encodeURIComponent(previewToken)}` : '');
    }
}

function navigatePreviewExit(exitUrl, previewToken = '') {
    clearPreviewClientState();
    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                source: 'weline-theme-preview',
                type: 'preview-exit',
            }, window.location.origin);
        }
    } catch (error) {
        // Ignore cross-origin parent access failures.
    }
    window.location.replace(buildExitNavigateUrl(exitUrl, previewToken));
}

function bindPreviewExitButtons() {
    document.querySelectorAll('[data-w-preview-exit]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement) || button.dataset.wPreviewExitBound === '1') {
            return;
        }
        button.dataset.wPreviewExitBound = '1';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (button.disabled) {
                return;
            }
            button.disabled = true;
            const exitUrl = String(button.dataset.wPreviewExitUrl || '').trim();
            const tokenMatch = document.cookie.match(/(?:^|;\s*)weline_preview_token(?:_w\d+)?=([^;]+)/);
            const urlToken = new URLSearchParams(window.location.search).get('weline_preview_token') || '';
            const previewToken = decodeURIComponent(String(urlToken || (tokenMatch ? tokenMatch[1] : '') || '')).trim();
            navigatePreviewExit(exitUrl, previewToken);
        });
    });
}

function initialize() {
    root.dataset.wEditorPreview = 'true';
    mount(document);
    bindPreviewExitButtons();

    // Dense storefront hydrate: disconnect + trailing idle (never double-rAF reobserve).
    const pendingMountRoots = new Set();
    const mountObserveOptions = { childList: true, subtree: true };
    if (!document.body) {
        // no-op when body missing
    } else {
        const coalesce = (typeof window.Weline?.dom?.observe === 'function')
            ? window.Weline.dom.observe.bind(window.Weline.dom)
            : (typeof window.Weline?.observeMutationsCoalesced === 'function')
            ? window.Weline.observeMutationsCoalesced
            : function observeMutationsCoalescedFallback(spec) {
                /* ARCH_MO_FALLBACK_START */
                const options = (spec && spec.options) || { childList: true, subtree: true };
                const idleTimeoutMs = 100;
                let flushScheduled = false;
                let idleHandle = null;
                let timeoutHandle = null;
                let disposed = false;
                const clearTimers = () => {
                    if (idleHandle != null && typeof window.cancelIdleCallback === 'function') {
                        try { window.cancelIdleCallback(idleHandle); } catch (_) {}
                        idleHandle = null;
                    }
                    if (timeoutHandle != null) {
                        window.clearTimeout(timeoutHandle);
                        timeoutHandle = null;
                    }
                };
                const runFlush = () => {
                    if (disposed) return;
                    flushScheduled = false;
                    clearTimers();
                    try { mo.disconnect(); } catch (_) {}
                    try { if (typeof spec.onFlush === 'function') spec.onFlush(); } finally {
                        if (!disposed && document.body) {
                            try { mo.observe(document.body, options); } catch (_) {}
                        }
                    }
                };
                const schedule = () => {
                    if (disposed) return;
                    clearTimers();
                    flushScheduled = true;
                    try { mo.disconnect(); } catch (_) {}
                    timeoutHandle = window.setTimeout(() => {
                        timeoutHandle = null;
                        const run = () => {
                            idleHandle = null;
                            runFlush();
                        };
                        if (typeof window.requestIdleCallback === 'function') {
                            idleHandle = window.requestIdleCallback(run, { timeout: 50 });
                        } else {
                            run();
                        }
                    }, idleTimeoutMs);
                };
                const mo = new MutationObserver((records) => {
                    if (disposed) return;
                    try { mo.disconnect(); } catch (_) {}
                    if (typeof spec.onRecords === 'function') {
                        try { spec.onRecords(records); } catch (_) {}
                    }
                    schedule();
                });
                try { mo.observe(document.body, options); } catch (_) {}
                return {
                    disconnect() {
                        disposed = true;
                        flushScheduled = false;
                        clearTimers();
                        try { mo.disconnect(); } catch (_) {}
                    },
                };
                /* ARCH_MO_FALLBACK_END */
            };
        coalesce({
            target: document.body,
            options: mountObserveOptions,
            idleTimeoutMs: 100,
            onRecords(records) {
                records.forEach((record) => record.addedNodes.forEach((node) => {
                    if (node instanceof Element) pendingMountRoots.add(node);
                }));
            },
            onFlush() {
                const batch = Array.from(pendingMountRoots);
                pendingMountRoots.clear();
                // Only newly added roots — never remount(document) (feeds delivery_storm / starves parent microtasks).
                batch.forEach((node) => {
                    if (node.isConnected) mount(node);
                });
            },
        });
    }

    document.addEventListener('click', (event) => {
        // 禁链：只拦 a 默认跳转；不 stopPropagation，父页/部件选中仍能收到点击。
        if (document.documentElement.dataset.wEditorLinkBlock !== '1') return;
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest('[data-editor-interactive], [data-w-preview-slot-action]')) return;
        const navigation = target.closest('a[href]');
        if (!navigation || navigation.closest('.slot-toolbar, .widget-hover-actions')) return;
        event.preventDefault();
        notify('navigation-blocked');
    }, true);

    document.addEventListener('submit', (event) => {
        if (document.documentElement.dataset.wEditorLinkBlock !== '1') return;
        if (!(event.target instanceof HTMLFormElement) || event.target.closest('[data-editor-interactive]')) return;
        event.preventDefault();
        notify('navigation-blocked');
    }, true);

    notify('ready', { slots: document.querySelectorAll('[data-wslot]').length });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
} else {
    initialize();
}
