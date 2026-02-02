/**
 * 主题编辑器 - iframe 内编辑模式脚本
 * 
 * 这个文件在编辑模式下被注入到 iframe 中
 * 不会影响前端正式页面
 */
(function() {
    'use strict';
    
    // #region agent log
    fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'editor-mode.js:11',message:'JS loaded, adding editor-mode class',data:{bodyExists:!!document.body,bodyClasses:document.body?document.body.className:'N/A'},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'A'})}).catch(()=>{});
    // #endregion
    
    // 启用编辑模式
    document.body.classList.add('editor-mode');
    
    // #region agent log
    fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'editor-mode.js:15',message:'After adding editor-mode class',data:{bodyClasses:document.body.className},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'A'})}).catch(()=>{});
    
    // 选择按钮的 SVG 图标
    const SELECT_ICON = '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
    
    /**
     * 计算选择按钮的最佳位置
     * @param {HTMLElement} slot - 插槽元素
     * @param {HTMLElement} btn - 按钮元素
     * @returns {object} - { top, left, position }
     */
    function calculateButtonPosition(slot, btn) {
        const slotRect = slot.getBoundingClientRect();
        const btnWidth = 70;  // 按钮预估宽度
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
        if (slot.querySelector('.slot-select-btn')) return;
        
        const btn = document.createElement('button');
        btn.className = 'slot-select-btn';
        btn.innerHTML = SELECT_ICON + '<span>选择</span>';
        btn.setAttribute('type', 'button');
        
        // 点击按钮时选择插槽
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            // 触发插槽选中
            selectSlot(slot);
        });
        
        // 鼠标进入时更新按钮位置
        slot.addEventListener('mouseenter', function() {
            updateButtonPosition(slot, btn);
        });
        
        // 滚动时更新位置
        window.addEventListener('scroll', function() {
            if (slot.matches(':hover')) {
                updateButtonPosition(slot, btn);
            }
        }, { passive: true });
        
        slot.appendChild(btn);
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
     * 初始化所有插槽
     */
    function initSlots() {
        // #region agent log
        const slots = document.querySelectorAll('[data-wslot]');
        fetch('http://127.0.0.1:7243/ingest/c0ecf822-3bcf-4f3d-a88a-8940482b2d3a',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({location:'editor-mode.js:initSlots',message:'initSlots called',data:{slotsFound:slots.length,slotIds:Array.from(slots).slice(0,5).map(s=>s.dataset.wslot)},timestamp:Date.now(),sessionId:'debug-session',hypothesisId:'B'})}).catch(()=>{});
        // #endregion
        
        document.querySelectorAll('[data-wslot]').forEach(slot => {
            // 添加选择按钮
            addSelectButton(slot);
            
            // 点击事件（备用，主要用选择按钮）
            slot.addEventListener('click', function(e) {
                // 如果点击的是选择按钮，不处理
                if (e.target.closest('.slot-select-btn')) return;
                
                // 如果点击的是链接或按钮，阻止默认行为并选中插槽
                if (e.target.closest('a') || e.target.closest('button')) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                
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
                    exclusive: this.dataset.wslotExclusive === 'true'
                };
                
                // 检查部件是否被接受
                const acceptedCodes = slotData.accept;
                const widgetCode = widgetData.code;
                const widgetSlot = widgetData.slot;
                
                const allowed = (widgetSlot && widgetSlot === slotData.id) || 
                               acceptedCodes.includes(widgetCode) || 
                               acceptedCodes.length === 0;
                
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
        });
        
        // 占位符点击事件
        document.querySelectorAll('.slot-placeholder').forEach(placeholder => {
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
    
    // 监听动态添加的插槽
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    if (node.hasAttribute('data-wslot')) {
                        addSelectButton(node);
                    }
                    node.querySelectorAll && node.querySelectorAll('[data-wslot]').forEach(addSelectButton);
                }
            });
        });
    });
    
    observer.observe(document.body, { childList: true, subtree: true });
    
})();
