/**
 * 主题编辑器 - iframe 内编辑模式脚本
 * 
 * 这个文件在编辑模式下被注入到 iframe 中
 * 不会影响前端正式页面
 */
(function() {
    'use strict';
    
    // 启用编辑模式
    document.body.classList.add('editor-mode');
    
    // 选择按钮的 SVG 图标
    const SELECT_ICON = '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
    const INFO_ICON = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>';
    
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
        // 构建插槽数据
        const slotData = {
            id: slot.dataset.wslot,
            name: slot.dataset.wslotName || slot.dataset.wslot,
            accept: slot.dataset.wslotAccept ? slot.dataset.wslotAccept.split(',').map(s => s.trim()) : [],
            multiple: slot.dataset.wslotMultiple === 'true',
            exclusive: slot.dataset.wslotExclusive === 'true',
            append: slot.dataset.wslotAppend === 'true',
            prepend: slot.dataset.wslotPrepend === 'true',
            area: slot.dataset.wslotPosition || ''
        };
        
        // 通知父窗口
        if (window.parent !== window) {
            window.parent.postMessage({
                type: 'slot-selected',
                slot: slotData
            }, '*');
        }
        
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
        return Array.from(slot.querySelectorAll(
            '.widget-wrapper[data-layout-id], [data-widget-code], .widget-content'
        )).filter(function(el) {
            // 只取直接在此 slot 下的，不取嵌套 slot 内的
            return el.closest('[data-wslot]') === slot;
        });
    }
    
    /**
     * 计算鼠标在部件列表中的插入位置
     * @param {HTMLElement[]} items - 部件元素数组
     * @param {number} mouseY - 鼠标 clientY
     * @returns {number}
     */
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
    function showIframeInsertionIndicator(slot, mouseY) {
        removeIframeInsertionIndicators(slot);
        
        var items = getSlotWidgetElements(slot);
        if (items.length === 0) {
            // 空插槽：整体高亮
            slot.classList.add('editor-drop-empty');
            slot._editorInsertIndex = 0;
            return;
        }
        
        var insertIndex = getIframeInsertionIndex(items, mouseY);
        slot._editorInsertIndex = insertIndex;
        
        // 创建指示线
        var indicator = document.createElement('div');
        indicator.className = 'editor-insert-indicator';
        indicator.innerHTML = '<span class="editor-insert-dot"></span>' +
                              '<span class="editor-insert-line"></span>' +
                              '<span class="editor-insert-dot"></span>';
        
        if (insertIndex < items.length) {
            items[insertIndex].parentNode.insertBefore(indicator, items[insertIndex]);
        } else {
            var lastItem = items[items.length - 1];
            if (lastItem.nextSibling) {
                lastItem.parentNode.insertBefore(indicator, lastItem.nextSibling);
            } else {
                lastItem.parentNode.appendChild(indicator);
            }
        }
    }
    
    /**
     * 移除所有插入指示器
     * @param {HTMLElement} [scope] - 限定范围
     */
    function removeIframeInsertionIndicators(scope) {
        var root = scope || document;
        root.querySelectorAll('.editor-insert-indicator').forEach(function(el) { el.remove(); });
        root.querySelectorAll('.editor-drop-empty').forEach(function(el) { el.classList.remove('editor-drop-empty'); });
        if (scope) {
            scope._editorInsertIndex = null;
        }
    }
    
    /**
     * 初始化单个插槽的所有交互能力
     * 包括：选择按钮、点击选中、拖放接收、占位符点击
     * 
     * @param {HTMLElement} slot - 带有 data-wslot 属性的插槽元素
     */
    /** 带嵌套可编辑插槽的容器部件 code，这些部件内的 [data-wslot] 需要初始化为可拖放目标 */
    var CONTAINER_WITH_NESTED_SLOTS = ['content-container', 'header-container', 'footer-container'];

    /**
     * Backend layout historically did not declare theme slots in its templates.
     * In editor iframe mode, expose stable structural slots without changing normal backend pages.
     */
    function initBackendStructuralSlots() {
        var isBackendLayout = document.documentElement.dataset.theme === 'backend'
            || (!!document.getElementById('layout-wrapper') && !!document.getElementById('page-topbar'));

        if (!isBackendLayout) return;

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
        
        // 部件预览（.widget-content）内的 slot：仅当外层是「带嵌套插槽的容器」时才初始化为可放置区，其余跳过
        var inContent = slot.closest('.widget-content');
        if (inContent) {
            var wrapper = slot.closest('.widget-wrapper');
            var code = (wrapper && wrapper.dataset && wrapper.dataset.widgetCode) || '';
            if (CONTAINER_WITH_NESTED_SLOTS.indexOf(code) === -1) return;
        }

        slot._editorSlotInitialized = true;
        
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
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('drag-over');
            this.classList.remove('drag-invalid');
            
            // 计算并显示插入位置指示器
            showIframeInsertionIndicator(this, e.clientY);
        });
        
        slot.addEventListener('dragleave', function(e) {
            if (!this.contains(e.relatedTarget)) {
                this.classList.remove('drag-over', 'drag-invalid');
                removeIframeInsertionIndicators(this);
            }
        });
        
        slot.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // 获取插入索引（在清理前读取）
            const insertIndex = this._editorInsertIndex;
            
            // 清理视觉状态
            this.classList.remove('drag-over', 'drag-invalid');
            removeIframeInsertionIndicators(this);
            
            // 获取拖放的部件数据
            let widgetData;
            try {
                const jsonData = e.dataTransfer.getData('application/json') || e.dataTransfer.getData('text/plain');
                if (jsonData) {
                    widgetData = JSON.parse(jsonData);
                }
            } catch (err) {
                console.error('Failed to parse widget data:', err);
                return;
            }
            
            if (!widgetData) {
                console.error('No widget data found in drop event');
                return;
            }
            
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
                exclusive: isExclusive,
                multiple: isMultiple,
                position: this.dataset.wslotPosition || ''
            };
            
            // 检查部件是否被接受（accept 为空或包含 * 时允许所有）
            const acceptedCodes = slotData.accept;
            const widgetCode = widgetData.code;
            const widgetSlot = widgetData.slot;
            const isWildcard = acceptedCodes.length === 0 || acceptedCodes.includes('*');
            
            const allowed = isWildcard ||
                           (widgetSlot && widgetSlot === slotData.id) || 
                           acceptedCodes.includes(widgetCode);
            
            if (!allowed) {
                if (window.parent !== window) {
                    window.parent.postMessage({
                        type: 'widget-rejected',
                        widget: widgetData,
                        slot: slotData,
                        reason: `插槽 "${slotData.name}" 不接受部件 "${widgetData.name || widgetCode}"`
                    }, '*');
                }
                this.classList.add('drag-invalid');
                setTimeout(() => this.classList.remove('drag-invalid'), 500);
                return;
            }
            
            // 满额检查（独占模式走替换逻辑，不受此限制）
            if (!isExclusive && maxWidgets > 0 && currentCount >= maxWidgets) {
                if (window.parent !== window) {
                    window.parent.postMessage({
                        type: 'widget-rejected',
                        widget: widgetData,
                        slot: slotData,
                        reason: `插槽 "${slotData.name}" 已满（${currentCount}/${maxWidgets}）`
                    }, '*');
                }
                return;
            }
            
            // 计算 sort_order
            let sortOrder;
            if (isExclusive) {
                sortOrder = 0;
            } else if (insertIndex != null) {
                sortOrder = insertIndex;
            } else {
                sortOrder = currentCount;
            }
            
            // 通知父窗口部件被放入插槽（附带 sort_order）
            if (window.parent !== window) {
                window.parent.postMessage({
                    type: 'widget-dropped',
                    widget: widgetData,
                    slot: slotData,
                    sort_order: sortOrder
                }, '*');
            }
            
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
    
})();
