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
    
    /**
     * 初始化单个插槽的所有交互能力
     * 包括：选择按钮、点击选中、拖放接收、占位符点击
     * 
     * @param {HTMLElement} slot - 带有 data-wslot 属性的插槽元素
     */
    function initSingleSlot(slot) {
        // 防止重复初始化
        if (slot._editorSlotInitialized) return;
        
        // 跳过 widget-content 内的嵌套 slot（这些是部件预览 HTML，不是实际可编辑插槽）
        if (slot.closest('.widget-content') || slot.closest('.widget-wrapper')) return;
        
        slot._editorSlotInitialized = true;
        
        // 添加选择按钮
        addSelectButton(slot);
        
        // 点击事件（备用，主要用选择按钮）
        slot.addEventListener('click', function(e) {
            // 如果点击的是工具栏内的按钮，不处理
            if (e.target.closest('.slot-toolbar')) return;
            
            // data-editor-interactive 标记的元素保持原生交互，不拦截
            if (e.target.closest('[data-editor-interactive]')) return;
            
            // 嵌套插槽：点击落在内层子插槽中时，由子插槽处理，外层跳过
            const closestSlot = e.target.closest('[data-wslot]');
            if (closestSlot && closestSlot !== this) return;
            
            // 如果点击的是链接或按钮，阻止默认行为并选中插槽
            if (e.target.closest('a') || e.target.closest('button')) {
                e.preventDefault();
            }
            e.stopPropagation();
            
            selectSlot(this);
        });
        
        // 拖放事件
        slot.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.add('drag-over');
            this.classList.remove('drag-invalid');
        });
        
        slot.addEventListener('dragleave', function(e) {
            if (!this.contains(e.relatedTarget)) {
                this.classList.remove('drag-over', 'drag-invalid');
            }
        });
        
        slot.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            this.classList.remove('drag-over', 'drag-invalid');
            
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
            
            const slotData = {
                id: this.dataset.wslot,
                name: this.dataset.wslotName || this.dataset.wslot,
                accept: this.dataset.wslotAccept ? this.dataset.wslotAccept.split(',').map(s => s.trim()) : [],
                exclusive: this.dataset.wslotExclusive === 'true',
                multiple: this.dataset.wslotMultiple === 'true',
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
            
            // 通知父窗口部件被放入插槽
            if (window.parent !== window) {
                window.parent.postMessage({
                    type: 'widget-dropped',
                    widget: widgetData,
                    slot: slotData
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
