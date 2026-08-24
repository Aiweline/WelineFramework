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

    document.documentElement.dataset.wEditorPreview = 'true';
    document.documentElement.dataset.wEditorPreviewEngine = 'full';
    document.documentElement.dataset.wEditorInteraction = 'edit';

    // 启用编辑模式
    document.body.classList.add('editor-mode');
    let interactionMode = 'edit';

    function isEditInteractionMode() {
        return interactionMode !== 'preview';
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
            document.querySelectorAll('.slot-info-card').forEach(function(el) {
                el.remove();
            });
            document.querySelectorAll('.widget-wrapper.show-actions, .widget-wrapper.selected').forEach(function(el) {
                el.classList.remove('show-actions', 'selected');
            });
            clearIframeDropFeedback(null, false);
            activeDragWidget = null;
            activeDragSessionId = '';
            activeDropCandidate = null;
        }
    }

    // 选择按钮的 SVG 图标
    const SELECT_ICON = '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
    const INFO_ICON = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>';

    function postPreviewMessage(type, detail) {
        if (window.parent === window) return;
        window.parent.postMessage({
            source: 'weline-theme-preview',
            type: type,
            ...(detail || {})
        }, EDITOR_ORIGIN);
    }

    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin || event.source !== window.parent) return;
        const data = event.data;
        if (!data || data.source !== 'weline-theme-editor') return;

        if (data.type === 'interaction-mode') {
            applyInteractionMode(data.mode);
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
     * 计算选择按钮的最佳位置
     * @param {HTMLElement} slot - 插槽元素
     * @param {HTMLElement} btn - 按钮元素
     * @returns {object} - { top, left, position }
     */
    function calculateButtonPosition(slot, btn) {
        const slotRect = slot.getBoundingClientRect();
        const btnWidth = 100; // 工具栏预估宽度（选择 + 信息按钮）
        const btnHeight = 24; // 按钮预估高度
        const padding = 4;    // 内边距
        const margin = 2;     // 外边距

        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;

        let top, left;
        let positionClass = 'inside'; // inside, outside-top, outside-bottom, outside-left, outside-right

        // 默认：内部左上角
        top = padding;
        left = padding;

        // 检查 slot 是否太小无法容纳按钮
        const slotTooSmall = slotRect.width < btnWidth + padding * 2 || slotRect.height < btnHeight + padding * 2;

        if (slotTooSmall) {
            // 尝试放在外部
            // 优先顺序：上方 > 下方 > 右侧 > 左侧

            // 上方
            if (slotRect.top > btnHeight + margin) {
                top = -btnHeight - margin;
                left = 0;
                positionClass = 'outside-top';
            }
            // 下方
            else if (viewportHeight - slotRect.bottom > btnHeight + margin) {
                top = slotRect.height + margin;
                left = 0;
                positionClass = 'outside-bottom';
            }
            // 右侧
            else if (viewportWidth - slotRect.right > btnWidth + margin) {
                top = 0;
                left = slotRect.width + margin;
                positionClass = 'outside-right';
            }
            // 左侧
            else if (slotRect.left > btnWidth + margin) {
                top = 0;
                left = -btnWidth - margin;
                positionClass = 'outside-left';
            }
            // 实在没地方，强制内部
            else {
                top = padding;
                left = padding;
                positionClass = 'inside-forced';
            }
        } else {
            // Slot 足够大，检查按钮是否会超出视口
            const btnAbsLeft = slotRect.left + left;
            const btnAbsTop = slotRect.top + top;

            // 如果左上角超出视口，调整到右上角
            if (btnAbsLeft < 0) {
                left = slotRect.width - btnWidth - padding;
            }
            if (btnAbsTop < 0) {
                top = slotRect.height - btnHeight - padding;
            }
        }

        return { top, left, positionClass };
    }

    /**
     * 为插槽添加选择按钮
     * @param {HTMLElement} slot - 插槽元素
     */
    function addSelectButton(slot) {
        // 检查是否已有按钮
        if (slot.querySelector('.slot-toolbar')) return;

        if (getComputedStyle(slot).position === 'static') {
            slot.style.position = 'relative';
        }

        // 按钮容器（选择 + 信息）
        const toolbar = document.createElement('div');
        toolbar.className = 'slot-toolbar';

        // 选择按钮
        const btn = document.createElement('button');
        btn.className = 'slot-select-btn';
        btn.innerHTML = SELECT_ICON + '<span>选择</span>';
        btn.setAttribute('type', 'button');
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            selectSlot(slot);
        });

        // 信息按钮
        const infoBtn = document.createElement('button');
        infoBtn.className = 'slot-info-btn';
        infoBtn.innerHTML = INFO_ICON;
        infoBtn.setAttribute('type', 'button');
        infoBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            toggleSlotInfoCard(slot, toolbar);
        });

        toolbar.appendChild(btn);
        toolbar.appendChild(infoBtn);

        // 鼠标进入时更新位置
        slot.addEventListener('mouseenter', function() {
            updateButtonPosition(slot, toolbar);
        });

        // 滚动时更新位置
        window.addEventListener('scroll', function() {
            if (slot.matches(':hover')) {
                updateButtonPosition(slot, toolbar);
            }
        }, { passive: true });

        slot.appendChild(toolbar);
    }

    /**
     * 切换插槽信息卡片
     */
    function toggleSlotInfoCard(slot, toolbar) {
        // 关闭其他已打开的卡片
        document.querySelectorAll('.slot-info-card').forEach(c => c.remove());

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
     * 更新按钮位置
     */
    function updateButtonPosition(slot, btn) {
        const pos = calculateButtonPosition(slot, btn);
        btn.style.top = pos.top + 'px';
        btn.style.left = pos.left + 'px';
    }

    /**
     * 选中插槽
     * @param {HTMLElement} slot - 插槽元素
     */
    function selectSlot(slot) {
        if (!isEditInteractionMode()) {
            return;
        }
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
