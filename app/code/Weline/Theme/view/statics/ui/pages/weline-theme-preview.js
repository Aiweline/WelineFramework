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

    function bindNolinkClickGuard() {
        if (document.body._nolinkClickGuardBound) {
            return;
        }
        document.body._nolinkClickGuardBound = true;
        // 捕获阶段拦截，避免父页链接桥接把点击转成预览跳转。
        document.addEventListener('click', function(e) {
            if (!isEditInteractionMode() || !isLinkBlockEnabled()) {
                return;
            }
            const link = e.target.closest && e.target.closest('a[href]');
            if (!link) {
                return;
            }
            if (link.closest('.slot-toolbar, .widget-hover-actions, [data-editor-interactive]')) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
        }, true);
    }

    function applyLinkBlock(enabled) {
        linkBlockEnabled = normalizeLinkBlockEnabled(enabled);
        document.documentElement.dataset.wEditorLinkBlock = linkBlockEnabled ? '1' : '0';
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

    function bindSlotHoverTargetEvents() {
        if (document.body._slotHoverTargetBound) {
            return;
        }
        document.body._slotHoverTargetBound = true;

        document.body.addEventListener('mousemove', function(e) {
            if (!isEditInteractionMode() || isWidgetSelectionTarget()) {
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

    function postPreviewMessage(type, detail) {
        if (window.parent === window) return;
        window.parent.postMessage({
            source: 'weline-theme-preview',
            type: type,
            ...(detail || {})
        }, EDITOR_ORIGIN);
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
            return;
        }

        // 迟到的旧 session 结束消息不得清掉一次新的拖拽。
        if (sessionId && activeDragSessionId && sessionId !== activeDragSessionId) return;
        clearIframeDropFeedback(null, false);
        activeDragWidget = null;
        activeDragSessionId = '';
        activeDropCandidate = null;
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

        const slotIdForLabel = slot.dataset.wslot || slot.getAttribute('data-slot') || '';
        if (slotIdForLabel) {
            toolbar.dataset.slotId = slotIdForLabel;
        }

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
            postPreviewMessage('slot-init-defaults', {
                slot_id: slot.dataset.wslot || '',
                area: slot.dataset.wslotPosition || slot.getAttribute('data-wslot-position') || '',
                name: slot.dataset.wslotName || slot.getAttribute('data-name') || '',
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
            slot_id: slot.dataset.wslot || '',
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
        const currentWidgets = getSlotWidgetElements(slot);
        const maxWidgets = slot.dataset.wslotMax ? parseInt(slot.dataset.wslotMax, 10) : -1;
        // 构建插槽数据
        const slotData = {
            id: slot.dataset.wslot,
            name: slot.dataset.wslotName || slot.dataset.wslot,
            accept: slot.dataset.wslotAccept ? slot.dataset.wslotAccept.split(',').map(s => s.trim()) : [],
            reject: slot.dataset.wslotReject ? slot.dataset.wslotReject.split(',').map(s => s.trim()).filter(Boolean) : [],
            multiple: slot.dataset.wslotMultiple !== 'false',
            exclusive: slot.dataset.wslotExclusive === 'true',
            max: maxWidgets,
            min: slot.dataset.wslotMin ? parseInt(slot.dataset.wslotMin, 10) : 0,
            current_count: currentWidgets.length,
            append: slot.dataset.wslotAppend === 'true',
            prepend: slot.dataset.wslotPrepend === 'true',
            area: slot.dataset.wslotPosition || '',
            position: slot.dataset.wslotPosition || ''
        };

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
     * 获取插槽内的部件元素（widget-wrapper / data-layout-id）
     * @param {HTMLElement} slot - 插槽元素
     * @returns {HTMLElement[]}
     */
    function getSlotWidgetElements(slot) {
        const candidates = Array.from(slot.querySelectorAll(
            '.widget-wrapper[data-layout-id], [data-layout-id], .widget-wrapper[data-widget-code], [data-widget-code]'
        )).filter(function(el) {
            return el.closest('[data-wslot]') === slot;
        });

        return candidates.filter(function(el, index) {
            return !candidates.slice(0, index).some(function(parent) {
                return parent.contains(el);
            });
        });
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

        return {
            session_id: activeDragSessionId,
            widget: widgetData,
            slot: slotData,
            sort_order: slotData.exclusive ? 0 : insertIndex,
            placement: placement,
            reference_layout_id: target
                ? String(target.dataset.layoutId || target.getAttribute('data-layout-id') || '')
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

    function showIframeDropFeedback(slot, mouseY, widgetData) {
        if (activeDropSlot && activeDropSlot !== slot) {
            clearIframeDropFeedback(activeDropSlot, false);
        }
        clearIframeDropFeedback(slot, false);
        activeDropSlot = slot;

        const candidate = buildIframeDropCandidate(slot, mouseY, widgetData);
        if (!candidate) return null;

        activeDropCandidate = candidate;
        slot._editorInsertIndex = candidate.sort_order;
        slot.classList.add('w-theme-preview-drop-target');
        slot.setAttribute('data-w-drop-position', candidate.placement);

        const slotName = slot.dataset.wslotName || slot.dataset.wslot || '当前插槽';
        if (candidate.placement === 'inside') {
            showIframeDropStatus(slot, '放入 ' + slotName);
        } else {
            const items = getSlotWidgetElements(slot);
            const target = candidate.placement === 'before'
                ? items[candidate.sort_order]
                : items[Math.max(0, candidate.sort_order - 1)];
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

        postPreviewMessage('drop-candidate', candidate);
        return candidate;
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

        // 插槽内链接：阻止导航跳转，但不阻止其他交互
        slot.addEventListener('click', function(e) {
            const link = e.target.closest('a[href]');
            if (link && !link.closest('.slot-toolbar') && !link.closest('[data-editor-interactive]')) {
                e.preventDefault(); // 仅阻止导航，不调用 selectSlot
                if (isLinkBlockEnabled()) {
                    e.stopPropagation();
                }
            }
            // 不拦截其他元素的点击 — 选择由工具栏"选择"按钮负责
        });

        // 拖放事件 — 带插入位置指示器
        slot.addEventListener('dragover', function(e) {
            if (!isEditInteractionMode()) return;
            const widgetData = readDragWidgetData(e);
            // iframe 内已有部件排序由父编辑器的 sortable 适配器处理。
            if (!widgetData) return;

            e.preventDefault();
            e.stopPropagation();
            const currentCount = getSlotWidgetElements(this).length;
            const maxWidgets = this.dataset.wslotMax ? parseInt(this.dataset.wslotMax, 10) : -1;
            const exclusive = this.dataset.wslotExclusive === 'true';
            const multiple = this.dataset.wslotMultiple !== 'false';
            const allowed = slotAcceptsWidget(
                normalizeCodeList(this.dataset.wslotAccept || ''),
                normalizeCodeList(this.dataset.wslotReject || ''),
                this.dataset.wslot,
                widgetData
            );
            const singleFull = !exclusive && !multiple && currentCount >= 1;
            const maxFull = !exclusive && maxWidgets > 0 && currentCount >= maxWidgets;
            const full = singleFull || maxFull;

            if (!allowed || full) {
                if (activeDropSlot && activeDropSlot !== this) {
                    clearIframeDropFeedback(activeDropSlot);
                }
                clearIframeDropFeedback(this);
                activeDropSlot = this;
                this.classList.add('drag-invalid');
                this.setAttribute('data-w-drop-position', 'invalid');
                showIframeDropStatus(this, singleFull ? '该插槽仅允许一个组件' : (full ? '该插槽已满' : '该组件不能放入此插槽'));
                e.dataTransfer.dropEffect = 'none';
                return;
            }

            e.dataTransfer.dropEffect = 'copy';
            this.classList.add('drag-over');
            this.classList.remove('drag-invalid');
            showIframeDropFeedback(this, e.clientY, widgetData);
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

            // 正常 drop 与父页 fallback 使用完全相同的候选，位置不会在释放瞬间重算漂移。
            const candidate = activeDropSlot === this
                && activeDropCandidate?.session_id === activeDragSessionId
                ? activeDropCandidate
                : buildIframeDropCandidate(this, e.clientY, widgetData);
            const insertIndex = candidate?.sort_order ?? this._editorInsertIndex;

            // 清理视觉状态，但保留父页候选直到提交消息到达。
            clearIframeDropFeedback(this, false);

            const isExclusive = this.dataset.wslotExclusive === 'true';
            const isMultiple = this.dataset.wslotMultiple !== 'false';
            const maxAttr = this.dataset.wslotMax;
            const maxWidgets = maxAttr ? parseInt(maxAttr, 10) : -1;
            const currentWidgets = getSlotWidgetElements(this);
            const currentCount = currentWidgets.length;

            const slotData = {
                id: this.dataset.wslot,
                name: this.dataset.wslotName || this.dataset.wslot,
                accept: this.dataset.wslotAccept ? this.dataset.wslotAccept.split(',').map(s => s.trim()) : [],
                reject: this.dataset.wslotReject ? this.dataset.wslotReject.split(',').map(s => s.trim()) : [],
                exclusive: isExclusive,
                multiple: isMultiple,
                max: maxWidgets,
                current_count: currentCount,
                position: this.dataset.wslotPosition || ''
            };

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
                this.classList.add('drag-invalid');
                setTimeout(() => this.classList.remove('drag-invalid'), 500);
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

            // 显示成功动画
            this.classList.add('slot-highlight');
            setTimeout(() => this.classList.remove('slot-highlight'), 1500);
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
    }

    // DOM 加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSlots);
    } else {
        initSlots();
    }

    // 监听动态添加的插槽 — 完整初始化（选择按钮 + 点击 + 拖放 + 占位符）
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    if (node.hasAttribute('data-wslot')) {
                        initSingleSlot(node);
                    }
                    node.querySelectorAll && node.querySelectorAll('[data-wslot]').forEach(initSingleSlot);
                }
            });
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });

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

function initialize() {
    root.dataset.wEditorPreview = 'true';
    mount(document);

    const observer = new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach((node) => {
            if (node instanceof Element) mount(node);
        }));
    });
    observer.observe(document.body, { childList: true, subtree: true });

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest('[data-editor-interactive], [data-w-preview-slot-action]')) return;
        const navigation = target.closest('a[href], button[type="submit"], input[type="submit"]');
        if (!navigation) return;
        event.preventDefault();
        notify('navigation-blocked');
    }, true);

    document.addEventListener('submit', (event) => {
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
