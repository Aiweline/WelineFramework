/**
 * EAV管理器
 * 提供树形导航和详情编辑功能
 */
const EavManager = (function() {
    'use strict';

    // 配置
    let config = {
        apiBase: '',
        treeContainer: '#eav-tree',
        detailContainer: '#detail-form',
        searchInput: '#tree-search',
        storageKey: 'eavManagerState' // localStorage 键名
    };

    // 状态
    let state = {
        treeData: [],
        selectedNode: null,
        expandedNodes: new Set(),
        loading: false,
        loadingNodes: new Set() // 正在加载子节点的节点ID集合
    };

    /**
     * 保存状态到 localStorage
     */
    function saveStateToStorage() {
        try {
            const stateToSave = {
                selectedNodeId: state.selectedNode ? state.selectedNode.id : null,
                selectedNodeType: state.selectedNode ? state.selectedNode.type : null,
                selectedNodeNodeId: state.selectedNode ? state.selectedNode.nodeId : null,
                expandedNodes: Array.from(state.expandedNodes)
            };
            localStorage.setItem(config.storageKey, JSON.stringify(stateToSave));
        } catch (e) {
            console.warn('无法保存状态到 localStorage:', e);
        }
    }

    /**
     * 从 localStorage 恢复状态
     */
    function loadStateFromStorage() {
        try {
            const saved = localStorage.getItem(config.storageKey);
            if (saved) {
                const parsed = JSON.parse(saved);
                return {
                    selectedNodeId: parsed.selectedNodeId || null,
                    selectedNodeType: parsed.selectedNodeType || null,
                    selectedNodeNodeId: parsed.selectedNodeNodeId || null,
                    expandedNodes: new Set(parsed.expandedNodes || [])
                };
            }
        } catch (e) {
            console.warn('无法从 localStorage 恢复状态:', e);
        }
        return null;
    }

    // 节点类型配置
    const nodeTypes = {
        entity: {
            icon: 'mdi-cube-outline',
            label: '实体',
            color: '#6f42c1'
        },
        set: {
            icon: 'mdi-folder-outline',
            label: '属性集',
            color: '#fd7e14'
        },
        group: {
            icon: 'mdi-folder-multiple-outline',
            label: '属性组',
            color: '#20c997'
        },
        attribute: {
            icon: 'mdi-tag-outline',
            label: '属性',
            color: '#0dcaf0'
        }
    };

    /**
     * 初始化
     */
    function init(options) {
        config = { ...config, ...options };
        
        // 从 localStorage 恢复状态
        const savedState = loadStateFromStorage();
        if (savedState) {
            state.expandedNodes = savedState.expandedNodes;
        }
        
        bindEvents();
        
        // 加载树，完成后恢复选中节点
        loadTree('', true, function() {
            if (savedState && savedState.selectedNodeId) {
                restoreSelectedNode(savedState);
            }
        });
    }

    /**
     * 绑定事件
     */
    function bindEvents() {
        // 搜索
        let searchTimeout;
        $(config.searchInput).on('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                loadTree($(this).val());
            }, 300);
        });

        // 删除按钮
        $('#btn-delete').on('click', function() {
            if (state.selectedNode) {
                deleteNode(state.selectedNode);
            }
        });
    }

    /**
     * 加载树形数据
     * @param {string} search 搜索关键词
     * @param {boolean} preserveExpanded 是否保持展开状态
     * @param {function} onLoaded 加载完成后的回调（可选）
     */
    function loadTree(search = '', preserveExpanded = true, onLoaded) {
        state.loading = true;
        showLoading();

        $.ajax({
            url: config.apiBase + '/tree',  // /eav/backend/manager/tree
            method: 'GET',
            data: { search: search },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    state.treeData = response.data;
                    renderTree(preserveExpanded);
                    if (typeof onLoaded === 'function') {
                        onLoaded();
                    }
                } else {
                    showError(response.message || '加载失败');
                }
            },
            error: function(xhr, status, error) {
                showError('网络错误: ' + error);
            },
            complete: function() {
                state.loading = false;
            }
        });
    }

    /**
     * 显示加载中
     */
    function showLoading() {
        $(config.treeContainer).html(`
            <div class="tree-loading">
                <i class="mdi mdi-loading mdi-spin me-2"></i>
                加载中...
            </div>
        `);
    }

    /**
     * 显示错误
     */
    function showError(message) {
        $(config.treeContainer).html(`
            <div class="text-danger text-center p-3">
                <i class="mdi mdi-alert-circle me-2"></i>
                ${message}
            </div>
        `);
    }

    /**
     * 渲染树形结构
     */
    function renderTree(preserveExpanded = true) {
        const container = $(config.treeContainer);
        
        if (state.treeData.length === 0) {
            container.html(`
                <div class="text-muted text-center p-3">
                    <i class="mdi mdi-database-off-outline d-block mb-2" style="font-size: 32px;"></i>
                    暂无数据
                </div>
            `);
            return;
        }

        // 保存当前选中的节点ID
        const selectedNodeId = state.selectedNode ? state.selectedNode.id : null;
        
        // 如果不保持展开状态，清空展开节点集合
        if (!preserveExpanded) {
            state.expandedNodes.clear();
        }

        let html = '';
        state.treeData.forEach(node => {
            html += renderNode(node, 0);
        });
        container.html(html);
        
        // 恢复选中状态
        if (selectedNodeId) {
            const $selectedNode = container.find(`[data-id="${selectedNodeId}"]`);
            if ($selectedNode.length) {
                $selectedNode.addClass('active');
            }
        }
    }

    /**
     * 渲染单个节点
     */
    function renderNode(node, level) {
        const typeConfig = nodeTypes[node.type] || nodeTypes.entity;
        const isExpanded = state.expandedNodes.has(node.id);
        const isSelected = state.selectedNode && state.selectedNode.id === node.id;
        const isLoading = state.loadingNodes.has(node.id);
        const hasChildren = node.lazy || (node.children && node.children.length > 0);

        let html = `
            <div class="tree-node-wrapper" data-level="${level}">
                <div class="tree-node node-${node.type} ${isSelected ? 'active' : ''}" 
                     data-id="${node.id}" 
                     data-type="${node.type}"
                     data-node-id="${node.nodeId}"
                     onclick="EavManager.selectNode(this)">
                    <span class="tree-node-toggle ${!hasChildren ? 'empty' : ''}" onclick="EavManager.toggleNode(event, '${node.id}')">
                        <i class="mdi ${isExpanded ? 'mdi-chevron-down' : 'mdi-chevron-right'}"></i>
                    </span>
                    <i class="tree-node-icon mdi ${typeConfig.icon}"></i>
                    <span class="tree-node-label">${escapeHtml(node.name)}</span>
                    <span class="tree-node-code">(${escapeHtml(node.code)})</span>
                    ${node.isSystem ? '<span class="badge bg-secondary tree-node-badge">系统</span>' : ''}
                </div>
                <div class="tree-children ${isExpanded ? 'expanded' : ''}" data-parent="${node.id}">
        `;

        if (isExpanded) {
            if (isLoading) {
                // 正在加载中
                html += '<div class="tree-loading"><i class="mdi mdi-loading mdi-spin me-1"></i>加载中...</div>';
            } else if (node.loadError) {
                // 加载失败
                html += `<div class="tree-loading text-danger">
                    <i class="mdi mdi-alert-circle me-1"></i>加载失败 
                    <a href="#" onclick="EavManager.retryLoadChildren('${node.id}'); return false;" class="ms-2">重试</a>
                </div>`;
            } else if (node.children && node.children.length > 0) {
                // 有子节点
                node.children.forEach(child => {
                    html += renderNode(child, level + 1);
                });
            } else if (!node.lazy) {
                // 已加载但无子节点
                html += '<div class="tree-loading text-muted"><i class="mdi mdi-folder-open-outline me-1"></i>暂无数据</div>';
            } else {
                // lazy=true 但不在加载中，需要加载（异常状态，触发加载）
                html += '<div class="tree-loading"><i class="mdi mdi-loading mdi-spin me-1"></i>加载中...</div>';
                // 延迟触发加载
                setTimeout(function() {
                    const n = findNode(node.id);
                    if (n && n.lazy && !state.loadingNodes.has(n.id)) {
                        loadChildren(n);
                    }
                }, 100);
            }
        }

        html += '</div></div>';
        return html;
    }

    /**
     * 重试加载子节点
     */
    function retryLoadChildren(nodeId) {
        const node = findNode(nodeId);
        if (node) {
            node.lazy = true;
            node.loadError = false;
            node.children = [];
            loadChildren(node);
        }
    }

    /**
     * 切换节点展开/折叠
     */
    function toggleNode(event, nodeId) {
        event.stopPropagation();
        
        const node = findNode(nodeId);
        if (!node) return;

        if (state.expandedNodes.has(nodeId)) {
            state.expandedNodes.delete(nodeId);
            saveStateToStorage();
            renderTree();
        } else {
            state.expandedNodes.add(nodeId);
            saveStateToStorage();
            if (node.lazy && (!node.children || node.children.length === 0)) {
                loadChildren(node);
            } else {
                renderTree();
            }
        }
    }

    /**
     * 加载子节点
     * @param {object} node 节点对象
     * @param {function} onLoaded 加载完成后的回调（可选）
     */
    function loadChildren(node, onLoaded) {
        // 确保节点在展开状态中
        if (!state.expandedNodes.has(node.id)) {
            state.expandedNodes.add(node.id);
        }
        
        // 标记该节点正在加载
        state.loadingNodes.add(node.id);
        
        renderTree(true); // 先渲染显示加载中，保持展开状态
        saveStateToStorage(); // 保存展开状态

        $.ajax({
            url: config.apiBase + '/children',
            method: 'GET',
            data: { type: node.type, id: node.nodeId },
            dataType: 'json',
            timeout: 30000, // 30秒超时
            success: function(response) {
                // 移除加载状态
                state.loadingNodes.delete(node.id);
                
                if (response.success) {
                    node.children = response.data || [];
                    node.lazy = false; // 关键：标记已加载完成
                    renderTree(true); // 渲染时保持展开状态
                    if (typeof onLoaded === 'function') {
                        onLoaded();
                    }
                } else {
                    showToast(response.message || '加载子节点失败', 'error');
                    // 加载失败时也要设置 lazy = false，避免重复显示加载中
                    node.lazy = false;
                    node.children = [];
                    node.loadError = true;
                    renderTree(true);
                    if (typeof onLoaded === 'function') {
                        onLoaded();
                    }
                }
            },
            error: function(xhr, status, error) {
                // 移除加载状态
                state.loadingNodes.delete(node.id);
                
                showToast('网络错误: ' + error, 'error');
                // 加载失败时也要设置 lazy = false，避免持续显示加载中
                node.lazy = false;
                node.children = [];
                node.loadError = true;
                renderTree(true);
                if (typeof onLoaded === 'function') {
                    onLoaded();
                }
            }
        });
    }

    /**
     * 恢复选中的节点（递归加载路径上的所有父节点）
     */
    function restoreSelectedNode(savedState) {
        const nodeId = savedState.selectedNodeId;
        
        // 首先尝试直接找到节点
        let node = findNode(nodeId);
        
        if (node) {
            // 节点已存在，直接选中
            selectNodeById(nodeId);
            return;
        }
        
        // 节点不存在，需要递归加载展开的节点
        loadExpandedNodesRecursively(function() {
            // 加载完成后再次尝试选中
            node = findNode(nodeId);
            if (node) {
                selectNodeById(nodeId);
            }
        });
    }

    /**
     * 递归加载所有展开的节点
     */
    function loadExpandedNodesRecursively(onComplete) {
        const nodesToLoad = findNodesNeedingLoad(state.treeData, state.expandedNodes);
        
        if (nodesToLoad.length === 0) {
            if (typeof onComplete === 'function') {
                onComplete();
            }
            return;
        }
        
        let pending = nodesToLoad.length;
        nodesToLoad.forEach(function(node) {
            loadChildren(node, function() {
                pending--;
                if (pending <= 0) {
                    // 当前一批加载完成，检查是否还有下一层需要加载
                    loadExpandedNodesRecursively(onComplete);
                }
            });
        });
    }

    /**
     * 通过节点ID选中节点
     */
    function selectNodeById(nodeId) {
        const node = findNode(nodeId);
        if (!node) return;
        
        state.selectedNode = node;
        saveStateToStorage();
        
        // 更新DOM选中状态
        $('.tree-node').removeClass('active');
        const $el = $(config.treeContainer).find(`[data-id="${nodeId}"]`);
        if ($el.length) {
            $el.addClass('active');
        }
        
        // 加载详情
        loadDetail(node);
    }

    /**
     * 查找需要加载子节点的节点（已展开但尚无子节点数据）
     */
    function findNodesNeedingLoad(nodes, expandedIds) {
        let result = [];
        if (!nodes || !nodes.length) return result;
        for (let node of nodes) {
            if (expandedIds.has(node.id) && node.lazy && (!node.children || node.children.length === 0)) {
                result.push(node);
            }
            if (node.children && node.children.length > 0) {
                result = result.concat(findNodesNeedingLoad(node.children, expandedIds));
            }
        }
        return result;
    }

    /**
     * 递归加载所有已展开但尚无子节点数据的节点（多级展开时逐层加载）
     * @param {string} selectedNodeId 当前选中的节点ID，全部加载完成后用于恢复选中
     */
    function loadAllExpandedChildren(selectedNodeId) {
        const nodesToLoad = findNodesNeedingLoad(state.treeData, state.expandedNodes);
        
        if (nodesToLoad.length === 0) {
            renderTree(true);
            restoreSelectedAndDetail(selectedNodeId);
            return;
        }
        
        let pending = nodesToLoad.length;
        nodesToLoad.forEach(function(node) {
            loadChildren(node, function() {
                pending--;
                if (pending <= 0) {
                    // 当前一批加载完成，检查是否还有下一层需要加载
                    loadAllExpandedChildren(selectedNodeId);
                }
            });
        });
    }

    /**
     * 刷新当前选中的节点
     */
    function refreshCurrentNode() {
        if (!state.selectedNode) {
            loadTree(true);
            return;
        }
        
        const currentNode = state.selectedNode;
        const selectedNodeId = currentNode.id;
        
        // 保存当前展开的节点ID列表
        const expandedNodeIds = new Set(state.expandedNodes);
        
        // 重新加载树，加载完成后恢复展开并重新拉取已展开节点的子节点
        loadTree(true, true, function() {
            // 恢复展开状态
            expandedNodeIds.forEach(function(nodeId) {
                state.expandedNodes.add(nodeId);
            });
            
            // 递归加载所有已展开但尚无子节点数据的节点（实体→属性集→属性组→属性）
            loadAllExpandedChildren(selectedNodeId);
        });
    }

    /**
     * 恢复选中状态并加载详情
     */
    function restoreSelectedAndDetail(selectedNodeId) {
        setTimeout(function() {
            const $node = $(config.treeContainer).find('[data-id="' + selectedNodeId + '"]');
            if ($node.length) {
                $node.addClass('active');
                state.selectedNode = findNode(selectedNodeId);
                if (state.selectedNode) {
                    loadDetailForm(state.selectedNode);
                }
            }
        }, 50);
    }

    /**
     * 查找节点的父节点
     */
    function findParentNode(node) {
        if (node.type === 'entity') {
            return null; // 实体没有父节点
        }
        
        // 在树形数据中查找父节点
        function searchParent(nodes, targetNode) {
            for (let n of nodes) {
                if (n.id === targetNode.id) {
                    return null; // 不应该找到自己
                }
                
                if (n.children && n.children.length > 0) {
                    for (let child of n.children) {
                        if (child.id === targetNode.id) {
                            return n;
                        }
                        if (child.children && child.children.length > 0) {
                            const found = searchParent([child], targetNode);
                            if (found) return found;
                        }
                    }
                }
            }
            return null;
        }
        
        return searchParent(state.treeData, node);
    }

    /**
     * 选择节点
     */
    function selectNode(element) {
        const $el = $(element);
        const nodeId = $el.data('id');
        const node = findNode(nodeId);
        
        if (!node) return;

        // 更新选中状态
        state.selectedNode = node;
        $('.tree-node').removeClass('active');
        $el.addClass('active');
        
        // 保存状态到 localStorage
        saveStateToStorage();

        // 加载详情
        loadDetail(node);
    }

    /**
     * 加载详情
     */
    function loadDetail(node) {
        const typeConfig = nodeTypes[node.type] || nodeTypes.entity;
        
        // 显示头部
        $('#detail-header').show();
        $('#detail-title').text(node.name);
        $('#detail-subtitle').text(`${typeConfig.label}: ${node.code}`);
        
        // 显示/隐藏删除按钮
        $('#btn-delete').toggle(!node.isSystem);
        
        // 隐藏占位符，显示表单
        $('#detail-placeholder').hide();
        $(config.detailContainer).show();
        
        // 加载详情表单
        loadDetailForm(node);
    }

    /**
     * 加载详情表单
     */
    function loadDetailForm(node) {
        const container = $(config.detailContainer);
        container.html('<div class="text-center p-4"><i class="mdi mdi-loading mdi-spin"></i> 加载中...</div>');

        const detailAction = getDetailAction(node.type);
        
        $.ajax({
            url: config.apiBase + '/' + detailAction,
            method: 'GET',
            data: { id: node.nodeId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // 保存原始数据到selectedNode，用于验证
                    if (state.selectedNode) {
                        state.selectedNode.code = response.data.code;
                        state.selectedNode.class = response.data.class;
                        state.selectedNode.isSystem = response.data.is_system == 1 || response.data.is_system === '1' || response.data.is_system === true;
                    }
                    renderDetailForm(node.type, response.data);
                } else {
                    container.html(`<div class="alert alert-danger">${response.message || '加载失败'}</div>`);
                }
            },
            error: function(xhr, status, error) {
                container.html(`<div class="alert alert-danger">网络错误: ${error}</div>`);
            }
        });
    }

    /**
     * 渲染详情表单
     */
    function renderDetailForm(type, data) {
        const container = $(config.detailContainer);
        let html = '';

        switch (type) {
            case 'entity':
                html = renderEntityForm(data);
                break;
            case 'set':
                html = renderSetForm(data);
                break;
            case 'group':
                html = renderGroupForm(data);
                break;
            case 'attribute':
                html = renderAttributeForm(data);
                break;
        }

        container.html(html);
        initFormEvents(type, data);
    }

    /**
     * 渲染实体表单
     */
    function renderEntityForm(data) {
        // 更严格的系统实体判断：支持数字1、字符串"1"、布尔值true
        const isSystem = data.is_system == 1 || data.is_system === '1' || data.is_system === true || data.isSystem === true;
        return `
            <form id="entity-form" data-id="${data.eav_entity_id}">
                <div class="form-section">
                    <div class="form-section-title">基本信息</div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">实体代码 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" value="${escapeHtml(data.code)}" ${isSystem ? 'readonly' : ''} required style="${isSystem ? 'background-color: #e9ecef; cursor: not-allowed;' : ''}">
                            ${isSystem ? '<small class="text-muted">系统实体代码不可修改</small>' : ''}
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">实体名称 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="${escapeHtml(data.name || '')}" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">实体类 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="class" value="${escapeHtml(data.class || '')}" ${isSystem ? 'readonly' : ''} required style="${isSystem ? 'background-color: #e9ecef; cursor: not-allowed;' : ''}">
                        ${isSystem ? '<small class="text-muted">系统实体的实体类不可修改</small>' : '<small class="text-muted">完整的PHP类名，如: WeShop\\Product\\Model\\Product</small>'}
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">主键字段类型</label>
                            <select class="form-select" name="eav_entity_id_field_type">
                                <option value="integer" ${data.eav_entity_id_field_type === 'integer' ? 'selected' : ''}>INTEGER</option>
                                <option value="varchar" ${data.eav_entity_id_field_type === 'varchar' ? 'selected' : ''}>VARCHAR</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">主键字段长度</label>
                            <input type="number" class="form-control" name="eav_entity_id_field_length" value="${data.eav_entity_id_field_length || 11}">
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="mdi mdi-content-save me-1"></i> 保存
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="EavManager.resetForm()">
                        <i class="mdi mdi-refresh me-1"></i> 重置
                    </button>
                </div>
            </form>
        `;
    }

    /**
     * 渲染属性集表单
     */
    function renderSetForm(data) {
        return `
            <form id="set-form" data-id="${data.set_id}">
                <input type="hidden" name="eav_entity_id" value="${data.eav_entity_id}">
                <div class="form-section">
                    <div class="form-section-title">基本信息</div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">属性集代码 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" value="${escapeHtml(data.code)}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">属性集名称 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="${escapeHtml(data.name || '')}" required>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="mdi mdi-content-save me-1"></i> 保存
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="EavManager.resetForm()">
                        <i class="mdi mdi-refresh me-1"></i> 重置
                    </button>
                </div>
            </form>
        `;
    }

    /**
     * 渲染属性组表单
     */
    function renderGroupForm(data) {
        return `
            <form id="group-form" data-id="${data.group_id}">
                <input type="hidden" name="eav_entity_id" value="${data.eav_entity_id}">
                <input type="hidden" name="set_id" value="${data.set_id}">
                <div class="form-section">
                    <div class="form-section-title">基本信息</div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">属性组代码 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" value="${escapeHtml(data.code)}" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">属性组名称 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="${escapeHtml(data.name || '')}" required>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="mdi mdi-content-save me-1"></i> 保存
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="EavManager.resetForm()">
                        <i class="mdi mdi-refresh me-1"></i> 重置
                    </button>
                </div>
            </form>
        `;
    }

    /**
     * 渲染属性表单
     */
    function renderAttributeForm(data) {
        const isSystem = data.is_system == 1;
        return `
            <form id="attribute-form" data-id="${data.attribute_id}">
                <input type="hidden" name="eav_entity_id" value="${data.eav_entity_id}">
                <input type="hidden" name="set_id" value="${data.set_id}">
                <input type="hidden" name="group_id" value="${data.group_id}">
                <input type="hidden" name="type_id" value="${data.type_id}">
                
                <div class="form-section">
                    <div class="form-section-title">基本信息</div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">属性代码 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" value="${escapeHtml(data.code)}" ${isSystem ? 'readonly' : ''} required>
                            ${isSystem ? '<small class="text-muted">系统属性代码不可修改</small>' : ''}
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">属性名称 <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" value="${escapeHtml(data.name || '')}" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">属性类型</label>
                            <input type="text" class="form-control" value="${escapeHtml(data.type_name || '')}" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">前端元素</label>
                            <input type="text" class="form-control" value="${escapeHtml(data.element || '')}" readonly>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <div class="form-section-title">属性设置</div>
                    
                    <!-- 基本设置组 (basic_) -->
                    <div class="settings-group mb-3">
                        <div class="settings-group-title text-muted small mb-2">
                            <i class="mdi mdi-cog-outline me-1"></i>基本设置
                        </div>
                        <div class="row ps-3">
                            <div class="col-md-4 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="basic_is_enable" id="basic_is_enable" ${data.basic_is_enable == 1 ? 'checked' : ''}>
                                    <label class="form-check-label" for="basic_is_enable">启用</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 前端显示组 (frontend_) -->
                    <div class="settings-group mb-3">
                        <div class="settings-group-title text-muted small mb-2">
                            <i class="mdi mdi-eye-outline me-1"></i>前端显示
                        </div>
                        <div class="row ps-3">
                            <div class="col-md-4 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="frontend_is_visible" id="frontend_is_visible" ${data.frontend_is_visible == 1 ? 'checked' : ''}>
                                    <label class="form-check-label" for="frontend_is_visible">前端可见</label>
                                </div>
                            </div>
                            <div class="col-md-4 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="frontend_is_filterable" id="frontend_is_filterable" ${data.frontend_is_filterable == 1 ? 'checked' : ''}>
                                    <label class="form-check-label" for="frontend_is_filterable">可筛选</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 数据配置组 (data_) -->
                    <div class="settings-group mb-3">
                        <div class="settings-group-title text-muted small mb-2">
                            <i class="mdi mdi-database-outline me-1"></i>数据配置
                        </div>
                        <div class="row ps-3">
                            <div class="col-md-4 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="data_is_multiple" id="data_is_multiple" ${data.data_is_multiple == 1 ? 'checked' : ''}>
                                    <label class="form-check-label" for="data_is_multiple">多值</label>
                                </div>
                            </div>
                            <div class="col-md-4 mb-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="data_has_option" id="data_has_option" ${data.data_has_option == 1 ? 'checked' : ''}>
                                    <label class="form-check-label" for="data_has_option">有选项</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${data.data_has_option == 1 ? renderAttributeOptions(data.options || []) : ''}
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="mdi mdi-content-save me-1"></i> 保存
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="EavManager.resetForm()">
                        <i class="mdi mdi-refresh me-1"></i> 重置
                    </button>
                </div>
            </form>
        `;
    }

    /**
     * 渲染属性选项
     */
    function renderAttributeOptions(options) {
        let optionsHtml = '';
        options.forEach((opt, index) => {
            optionsHtml += `
                <tr data-option-id="${opt.option_id || ''}">
                    <td><input type="text" class="form-control form-control-sm" name="options[${index}][code]" value="${escapeHtml(opt.code || '')}"></td>
                    <td><input type="text" class="form-control form-control-sm" name="options[${index}][value]" value="${escapeHtml(opt.value || '')}"></td>
                    <td><input type="text" class="form-control form-control-sm" name="options[${index}][swatch_color]" value="${escapeHtml(opt.swatch_color || '')}"></td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="EavManager.removeOption(this)">
                            <i class="mdi mdi-delete"></i>
                        </button>
                    </td>
                </tr>
            `;
        });

        return `
            <div class="form-section">
                <div class="form-section-title d-flex justify-content-between align-items-center">
                    <span>属性选项</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="EavManager.addOption()">
                        <i class="mdi mdi-plus"></i> 添加选项
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm" id="options-table">
                        <thead>
                            <tr>
                                <th>代码</th>
                                <th>值</th>
                                <th>颜色</th>
                                <th width="50"></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${optionsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }

    /**
     * 初始化表单事件
     */
    function initFormEvents(type, data) {
        const formId = `#${type}-form`;
        
        $(formId).on('submit', function(e) {
            e.preventDefault();
            saveForm(type, $(this));
        });
    }

    /**
     * 保存表单
     */
    function saveForm(type, $form) {
        const id = $form.data('id');
        
        // 如果是系统实体，验证不允许修改的字段
        if (type === 'entity' && state.selectedNode && state.selectedNode.isSystem) {
            const originalCode = state.selectedNode.code;
            const originalClass = state.selectedNode.class || state.selectedNode.className;
            const newCode = $form.find('input[name="code"]').val();
            const newClass = $form.find('input[name="class"]').val();
            
            if (originalCode && newCode !== originalCode) {
                showToast('系统实体的代码不能修改', 'error');
                return;
            }
            if (originalClass && newClass !== originalClass) {
                showToast('系统实体的实体类不能修改', 'error');
                return;
            }
        }
        
        const formData = new FormData($form[0]);
        
        // 处理checkbox
        $form.find('input[type="checkbox"]').each(function() {
            formData.set($(this).attr('name'), $(this).is(':checked') ? 1 : 0);
        });
        
        // 添加ID
        const idField = getIdField(type);
        if (id) {
            formData.set(idField, id);
        }

        const $submitBtn = $form.find('button[type="submit"]');
        $submitBtn.prop('disabled', true).html('<i class="mdi mdi-loading mdi-spin me-1"></i> 保存中...');

        $.ajax({
            url: config.apiBase + '/' + getSaveAction(type),
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast(response.message || '保存成功', 'success');
                    // 只刷新右侧详情，不刷新左侧树，避免左侧一直显示「加载中...」
                    if (state.selectedNode) {
                        loadDetailForm(state.selectedNode);
                    }
                } else {
                    showToast(response.message || '保存失败', 'error');
                }
            },
            error: function(xhr, status, error) {
                showToast('网络错误: ' + error, 'error');
            },
            complete: function() {
                $submitBtn.prop('disabled', false).html('<i class="mdi mdi-content-save me-1"></i> 保存');
            }
        });
    }

    /**
     * 删除节点
     */
    function deleteNode(node) {
        if (node.isSystem) {
            showToast('系统节点不能删除', 'warning');
            return;
        }

        Swal.fire({
            title: '确认删除?',
            text: `确定要删除 ${nodeTypes[node.type].label} "${node.name}" 吗？`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: '删除',
            cancelButtonText: '取消'
        }).then((result) => {
            if (result.isConfirmed) {
                performDelete(node);
            }
        });
    }

    /**
     * 执行删除
     */
    function performDelete(node) {
        $.ajax({
            url: config.apiBase + '/delete',
            method: 'POST',
            data: { type: node.type, id: node.nodeId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showToast(response.message || '删除成功', 'success');
                    state.selectedNode = null;
                    resetDetailPanel();
                    loadTree();
                } else {
                    showToast(response.message || '删除失败', 'error');
                }
            },
            error: function(xhr, status, error) {
                showToast('网络错误: ' + error, 'error');
            }
        });
    }

    /**
     * 新增节点
     */
    function addNode(type) {
        // 根据类型确定需要的上下文
        let parentInfo = {};
        
        if (state.selectedNode) {
            // 根据选中节点类型确定上下文
            if (type === 'set' && state.selectedNode.type === 'entity') {
                parentInfo.eav_entity_id = state.selectedNode.nodeId;
            } else if (type === 'group') {
                if (state.selectedNode.type === 'set') {
                    parentInfo.set_id = state.selectedNode.nodeId;
                    parentInfo.eav_entity_id = state.selectedNode.entityId;
                } else if (state.selectedNode.type === 'entity') {
                    parentInfo.eav_entity_id = state.selectedNode.nodeId;
                }
            } else if (type === 'attribute') {
                if (state.selectedNode.type === 'group') {
                    parentInfo.group_id = state.selectedNode.nodeId;
                    parentInfo.set_id = state.selectedNode.setId;
                    parentInfo.eav_entity_id = state.selectedNode.entityId;
                } else if (state.selectedNode.type === 'set') {
                    parentInfo.set_id = state.selectedNode.nodeId;
                    parentInfo.eav_entity_id = state.selectedNode.entityId;
                } else if (state.selectedNode.type === 'entity') {
                    parentInfo.eav_entity_id = state.selectedNode.nodeId;
                }
            }
        }

        // 显示新建表单
        showNewForm(type, parentInfo);
    }

    /**
     * 显示新建表单
     */
    function showNewForm(type, parentInfo) {
        const typeConfig = nodeTypes[type];
        
        $('#detail-header').show();
        $('#detail-title').text('新建' + typeConfig.label);
        $('#detail-subtitle').text('');
        $('#btn-delete').hide();
        
        $('#detail-placeholder').hide();
        $(config.detailContainer).show();
        
        // 根据类型渲染空表单
        const emptyData = { ...parentInfo };
        renderDetailForm(type, emptyData);
    }

    /**
     * 重置表单
     */
    function resetForm() {
        if (state.selectedNode) {
            loadDetailForm(state.selectedNode);
        }
    }

    /**
     * 重置详情面板
     */
    function resetDetailPanel() {
        $('#detail-header').hide();
        $(config.detailContainer).hide();
        $('#detail-placeholder').show();
    }

    /**
     * 添加选项行
     */
    function addOption() {
        const $tbody = $('#options-table tbody');
        const index = $tbody.find('tr').length;
        const html = `
            <tr data-option-id="">
                <td><input type="text" class="form-control form-control-sm" name="options[${index}][code]" value=""></td>
                <td><input type="text" class="form-control form-control-sm" name="options[${index}][value]" value=""></td>
                <td><input type="text" class="form-control form-control-sm" name="options[${index}][swatch_color]" value=""></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="EavManager.removeOption(this)">
                        <i class="mdi mdi-delete"></i>
                    </button>
                </td>
            </tr>
        `;
        $tbody.append(html);
    }

    /**
     * 移除选项行
     */
    function removeOption(btn) {
        $(btn).closest('tr').remove();
    }

    // 辅助函数

    function findNode(nodeId, nodes = state.treeData) {
        for (let node of nodes) {
            if (node.id === nodeId) {
                return node;
            }
            if (node.children && node.children.length > 0) {
                const found = findNode(nodeId, node.children);
                if (found) return found;
            }
        }
        return null;
    }

    function getDetailAction(type) {
        const actions = {
            entity: 'entityDetail',
            set: 'setDetail',
            group: 'groupDetail',
            attribute: 'attributeDetail'
        };
        return actions[type] || type + 'Detail';
    }

    function getSaveAction(type) {
        const actions = {
            entity: 'entitySave',
            set: 'setSave',
            group: 'groupSave',
            attribute: 'attributeSave'
        };
        return actions[type] || type + 'Save';
    }

    function getIdField(type) {
        const fields = {
            entity: 'eav_entity_id',
            set: 'set_id',
            group: 'group_id',
            attribute: 'attribute_id'
        };
        return fields[type] || 'id';
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(message, type = 'info') {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: type,
                title: message,
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
        } else {
            alert(message);
        }
    }

    // 公开API
    return {
        init: init,
        loadTree: loadTree,
        toggleNode: toggleNode,
        selectNode: selectNode,
        addNode: addNode,
        resetForm: resetForm,
        addOption: addOption,
        removeOption: removeOption,
        retryLoadChildren: retryLoadChildren,
        clearStorage: function() {
            localStorage.removeItem(config.storageKey);
        }
    };
})();
