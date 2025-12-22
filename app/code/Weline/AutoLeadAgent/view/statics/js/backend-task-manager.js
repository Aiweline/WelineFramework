/**
 * 自动寻客后台任务管理脚本
 *
 * 功能：
 * - 页面加载时弹出引导对话框
 * - 检查是否有未完成任务
 * - 创建新的寻找客户任务
 * - 继续未完成的任务
 * - 真正驱动浏览器端侧AI模型执行任务
 */

var AutoLeadAgentTaskManager = (function () {
    'use strict';

    var modalInstance = null;
    var hasActiveTask = false;
    var activeTaskId = 0;
    var GUIDE_SEEN_KEY = 'auto_lead_agent_guide_seen';

    // 任务运行状态面板
    var taskRunningPanel = null;

    function getRootElement() {
        return document.querySelector('.auto-lead-agent-index');
    }

    function init() {
        var root = getRootElement();
        if (!root) {
            return;
        }

        hasActiveTask = root.getAttribute('data-has-active-task') === '1';
        activeTaskId = parseInt(root.getAttribute('data-active-task-id') || '0', 10) || 0;

        setupEventListeners();
        createTaskRunningPanel();
        
        // 首先检测中断任务（优先级最高）
        checkAndMarkInterruptedTasks();
        
        // 然后加载任务列表
        loadTaskList().then(function() {
            maybeShowGuideModal();
        });
        
        // 已使用SSE连接，不需要轮询
        // 只在初始化时执行一次
        checkAndMarkInterruptedTasks();
        loadTaskList();
        
        // 初始化TaskRunner
        if (typeof AutoLeadAgentTaskRunner !== 'undefined') {
            AutoLeadAgentTaskRunner.init();
        }
    }

    /**
     * 异步加载任务列表
     */
    function loadTaskList() {
        var container = document.getElementById('task-list-container');
        if (!container) {
            return Promise.resolve();
        }

        var baseUrl = window.location.pathname;
        var url = baseUrl.replace(/\/index$/, '/task-list');

        return fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.data && data.data.tasks) {
                renderTaskList(data.data.tasks);
            }
        })
        .catch(function(err) {
            console.error('loadTaskList error:', err);
        });
    }

    /**
     * 渲染任务列表
     */
    function renderTaskList(tasks) {
        var container = document.getElementById('task-list-container');
        if (!container) return;

        if (!tasks || tasks.length === 0) {
            container.innerHTML = '<div class="alert alert-info mb-0">暂无任务</div>';
            return;
        }

        var viewUrlBase = window.location.pathname.replace(/\/index$/, '/view');
        
        var html = '<div class="table-responsive">' +
            '<table class="table table-bordered table-hover align-middle">' +
            '<thead class="table-light"><tr>' +
            '<th>任务ID</th><th>来源类型</th><th>状态</th><th>潜在客户</th><th>创建时间</th><th>操作</th>' +
            '</tr></thead><tbody>';

        tasks.forEach(function(task) {
            var badgeClass = 'secondary';
            var statusIcon = 'mdi-clock-outline';
            if (task.status === 'running' || task.status === 'inferring' || task.status === 'crawling') {
                badgeClass = 'primary';
                statusIcon = 'mdi-cog mdi-spin';
            } else if (task.status === 'inferring') {
                badgeClass = 'info';
                statusIcon = 'mdi-brain mdi-spin';
            } else if (task.status === 'crawling') {
                badgeClass = 'success';
                statusIcon = 'mdi-web mdi-spin';
            } else if (task.status === 'completed') {
                badgeClass = 'success';
                statusIcon = 'mdi-check-circle';
            } else if (task.status === 'failed') {
                badgeClass = 'danger';
                statusIcon = 'mdi-alert-circle';
            } else if (task.status === 'paused') {
                badgeClass = 'warning';
                statusIcon = 'mdi-pause-circle';
            }

            var statusText = task.status;
            if (task.status === 'pending') statusText = '待执行';
            else if (task.status === 'running') statusText = '运行中';
            else if (task.status === 'inferring') statusText = '推理中';
            else if (task.status === 'crawling') statusText = '爬取中';
            else if (task.status === 'completed') statusText = '已完成';
            else if (task.status === 'failed') statusText = '失败';
            else if (task.status === 'cancelled') statusText = '已取消';
            else if (task.status === 'paused') statusText = '已暂停';

            var actionBtns = '';
            if (task.status === 'pending' || task.status === 'failed' || task.status === 'cancelled' || task.status === 'paused') {
                actionBtns += '<button type="button" class="btn btn-sm btn-primary continue-task-btn" data-task-id="' + task.task_id + '">' +
                    '<i class="mdi mdi-play-circle"></i> 继续</button> ';
            }
            actionBtns += '<a href="' + viewUrlBase + '?taskId=' + task.task_id + '" class="btn btn-sm btn-outline-secondary">' +
                '<i class="mdi mdi-eye"></i> 查看</a> ';
            // 删除按钮：非运行状态的任务可以删除
            if (task.status !== 'running' && task.status !== 'inferring' && task.status !== 'crawling') {
                actionBtns += '<button type="button" class="btn btn-sm btn-danger delete-task-btn" data-task-id="' + task.task_id + '" title="删除任务">' +
                    '<i class="mdi mdi-delete"></i> 删除</button>';
            }

            // 显示找到的客户数量，而不是进度条
            var foundCount = parseInt(task.found_count || 0, 10);
            var foundDisplay = '<span class="badge bg-info"><i class="mdi mdi-account-multiple me-1"></i>' + 
                foundCount + ' 个</span>';

            // 显示来源类型信息
            var sourceTypeDisplay = '';
            if (task.source_type) {
                var sourceTypeName = task.source_type === 'store' ? '店铺' : task.source_type;
                sourceTypeDisplay = '<span class="badge bg-secondary">' + sourceTypeName + '</span>';
                if (task.source_id) {
                    sourceTypeDisplay += ' <small class="text-muted">#' + task.source_id + '</small>';
                }
            } else if (task.store_id) {
                // 兼容旧数据
                sourceTypeDisplay = '<span class="badge bg-secondary">店铺</span> <small class="text-muted">#' + task.store_id + '</small>';
            } else {
                sourceTypeDisplay = '<span class="text-muted">-</span>';
            }
            
            html += '<tr data-task-id="' + task.task_id + '">' +
                '<td>' + task.task_id + '</td>' +
                '<td>' + sourceTypeDisplay + '</td>' +
                '<td><span class="badge bg-' + badgeClass + '"><i class="mdi ' + statusIcon + ' me-1"></i>' + statusText + '</span></td>' +
                '<td>' + foundDisplay + '</td>' +
                '<td>' + task.created_at + '</td>' +
                '<td><div class="btn-group" role="group">' + actionBtns + '</div></td>' +
                '</tr>';
        });

        html += '</tbody></table></div>';
        container.innerHTML = html;

        // 重新绑定继续按钮事件
        bindContinueButtons();
        // 绑定删除按钮事件
        bindDeleteButtons();
    }
    
    /**
     * 绑定删除按钮事件
     */
    function bindDeleteButtons() {
        document.querySelectorAll('.delete-task-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var taskId = this.getAttribute('data-task-id');
                if (!taskId) return;
                
                if (!confirm('确定要删除任务 #' + taskId + ' 吗？\n\n删除后无法恢复，请谨慎操作。')) {
                    return;
                }
                
                deleteTask(taskId);
            });
        });
    }
    
    /**
     * 删除任务
     */
    function deleteTask(taskId) {
        // 根据Weline路由规则，deleteTask 方法对应 /delete-task 路由，使用DELETE请求
        // 使用当前路径构建正确的URL
        var currentPath = window.location.pathname;
        // 从 /auto-lead-agent/backend/index/index 转换为 /auto-lead-agent/backend/index/delete-task
        // 将task_id作为URL参数传递，因为DELETE请求的body可能不被所有服务器支持
        var url = currentPath.replace(/\/[^\/]+$/, '/delete-task') + '?task_id=' + encodeURIComponent(taskId);
        
        fetch(url, {
            method: 'DELETE',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(data) {
            if (data.success) {
                // 从列表中移除该任务行
                var taskRow = document.querySelector('tr[data-task-id="' + taskId + '"]');
                if (taskRow) {
                    taskRow.remove();
                }
                // 如果列表为空，显示提示
                var container = document.getElementById('task-list-container');
                if (container && container.querySelectorAll('tbody tr').length === 0) {
                    container.innerHTML = '<div class="alert alert-info mb-0">暂无任务</div>';
                }
                // 显示成功消息
                if (typeof showToast === 'function') {
                    showToast('success', data.message || '任务删除成功');
                } else {
                    alert(data.message || '任务删除成功');
                }
            } else {
                alert(data.message || '删除失败');
            }
        })
        .catch(function(error) {
            console.error('Delete task error:', error);
            alert('删除任务失败：' + error.message);
        });
    }

    /**
     * 绑定继续按钮事件
     */
    function bindContinueButtons() {
        var continueBtns = document.querySelectorAll('.continue-task-btn');
        continueBtns.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var taskId = parseInt(btn.getAttribute('data-task-id') || '0', 10);
                if (taskId > 0) {
                    continueTask(taskId);
                }
            });
        });
    }

    /**
     * 检测并标记中断的 running 任务
     * 如果有 running 状态的任务但 TaskRunner 没有在运行，自动标记为失败
     */
    function checkAndMarkInterruptedTasks() {
        // 检查 TaskRunner 是否正在运行任务
        var taskRunnerRunning = false;
        var currentRunningTaskId = null;
        
        if (typeof AutoLeadAgentTaskRunner !== 'undefined') {
            if (typeof AutoLeadAgentTaskRunner.isRunning === 'function') {
                taskRunnerRunning = AutoLeadAgentTaskRunner.isRunning();
            }
            if (typeof AutoLeadAgentTaskRunner.getCurrentTaskId === 'function') {
                currentRunningTaskId = AutoLeadAgentTaskRunner.getCurrentTaskId();
            }
        }

        console.log('[TaskManager] 检测中断任务 - 模型运行状态:', taskRunnerRunning, '当前任务ID:', currentRunningTaskId);

        if (taskRunnerRunning && currentRunningTaskId) {
            // 模型正在运行特定任务，不需要标记
            console.log('[TaskManager] 模型正在运行任务', currentRunningTaskId, '，跳过检测');
            return;
        }

        // 模型没有运行，标记所有 running 状态的任务为异常
        console.log('[TaskManager] 模型待机，开始标记中断任务...');
        
        var baseUrl = window.location.pathname;
        var url = baseUrl.replace(/\/index$/, '/mark-interrupted');

        console.log('[TaskManager] 请求URL:', url);

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'task_id=0'
        })
        .then(function(response) { 
            console.log('[TaskManager] 响应状态:', response.status);
            return response.json(); 
        })
        .then(function(data) {
            console.log('[TaskManager] 响应数据:', data);
            if (data.success) {
                if (data.data && data.data.marked_count > 0) {
                    console.log('[TaskManager] 已标记', data.data.marked_count, '个中断任务为异常');
                    // 重新加载任务列表
                    loadTaskList();
                } else {
                    console.log('[TaskManager] 没有需要标记的中断任务');
                }
            } else {
                console.warn('[TaskManager] 标记失败:', data.message);
            }
        })
        .catch(function(err) {
            console.error('[TaskManager] checkAndMarkInterruptedTasks 请求错误:', err);
        });
    }

    function setupEventListeners() {
        var createBtn = document.getElementById('auto-lead-agent-create-task-btn');
        if (createBtn) {
            createBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                showGuideModal(false);
            });
        }

        var startBtn = document.getElementById('auto-lead-agent-start-task-btn');
        if (startBtn) {
            startBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                createTaskFromForm();
            });
        }

        var resumeBtn = document.getElementById('auto-lead-agent-resume-task-btn');
        if (resumeBtn) {
            resumeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (activeTaskId > 0) {
                    continueTask(activeTaskId);
                }
            });
        }

        // 监听TaskRunner事件
        window.addEventListener('taskRunnerStatusChange', function(e) {
            handleTaskStatusChange(e.detail);
        });
        
        window.addEventListener('taskRunnerProgress', function(e) {
            handleTaskProgress(e.detail);
        });
    }

    /**
     * 创建任务运行状态面板
     */
    function createTaskRunningPanel() {
        if (taskRunningPanel) return;

        taskRunningPanel = document.createElement('div');
        taskRunningPanel.id = 'task-running-panel';
        taskRunningPanel.className = 'card mb-4';
        taskRunningPanel.style.display = 'none';
        taskRunningPanel.innerHTML = 
            '<div class="card-header bg-primary text-white">' +
                '<div class="d-flex justify-content-between align-items-center">' +
                    '<h5 class="card-title mb-0">' +
                        '<i class="mdi mdi-robot-outline me-2"></i>' +
                        '<span id="task-panel-title">AI模型执行中</span>' +
                    '</h5>' +
                    '<button type="button" class="btn btn-sm btn-outline-light" id="task-stop-btn">' +
                        '<i class="mdi mdi-stop"></i> 停止' +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="card-body">' +
                '<div class="mb-3">' +
                    '<label class="form-label">任务进度</label>' +
                    '<div class="progress" style="height: 25px;">' +
                        '<div class="progress-bar progress-bar-striped progress-bar-animated" ' +
                             'id="task-progress-bar" role="progressbar" style="width: 0%">0%</div>' +
                    '</div>' +
                '</div>' +
                '<div id="task-status-message" class="text-muted mb-3">' +
                    '<i class="mdi mdi-loading mdi-spin me-1"></i> 准备中...' +
                '</div>' +
                '<div class="row">' +
                    '<div class="col-md-4">' +
                        '<div class="d-flex align-items-center">' +
                            '<i class="mdi mdi-clock-outline text-info me-2 fs-4"></i>' +
                            '<div>' +
                                '<small class="text-muted">运行时间</small>' +
                                '<div id="task-elapsed-time" class="fw-bold">0s</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<div class="d-flex align-items-center">' +
                            '<i class="mdi mdi-brain text-warning me-2 fs-4"></i>' +
                            '<div>' +
                                '<small class="text-muted">推理次数</small>' +
                                '<div id="task-inference-count" class="fw-bold">0</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="col-md-4">' +
                        '<div class="d-flex align-items-center">' +
                            '<i class="mdi mdi-account-group text-success me-2 fs-4"></i>' +
                            '<div>' +
                                '<small class="text-muted">已找到客户</small>' +
                                '<div id="task-candidates-count" class="fw-bold">0</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        // 插入到统计卡片后面
        var statsRow = document.querySelector('.auto-lead-agent-index > .row.mb-4');
        if (statsRow) {
            statsRow.parentNode.insertBefore(taskRunningPanel, statsRow.nextSibling);
        }

        // 绑定停止按钮
        var stopBtn = document.getElementById('task-stop-btn');
        if (stopBtn) {
            stopBtn.addEventListener('click', function() {
                if (typeof AutoLeadAgentTaskRunner !== 'undefined') {
                    AutoLeadAgentTaskRunner.stopTask();
                }
            });
        }

        // 启动计时器
        startElapsedTimeTimer();
    }

    var elapsedTimeInterval = null;
    var taskStartTime = null;

    function startElapsedTimeTimer() {
        if (elapsedTimeInterval) {
            clearInterval(elapsedTimeInterval);
        }
        
        elapsedTimeInterval = setInterval(function() {
            if (taskStartTime) {
                var elapsed = Math.floor((Date.now() - taskStartTime) / 1000);
                var minutes = Math.floor(elapsed / 60);
                var seconds = elapsed % 60;
                var timeStr = minutes > 0 
                    ? minutes + 'm ' + seconds + 's'
                    : seconds + 's';
                
                var el = document.getElementById('task-elapsed-time');
                if (el) el.textContent = timeStr;
            }
        }, 1000);
    }

    /**
     * 显示任务运行面板
     */
    function showTaskPanel() {
        if (taskRunningPanel) {
            taskRunningPanel.style.display = 'block';
            taskStartTime = Date.now();
            
            // 重置显示
            var progressBar = document.getElementById('task-progress-bar');
            if (progressBar) {
                progressBar.style.width = '0%';
                progressBar.textContent = '0%';
            }
            
            var statusMsg = document.getElementById('task-status-message');
            if (statusMsg) {
                statusMsg.innerHTML = '<i class="mdi mdi-loading mdi-spin me-1"></i> 准备中...';
            }
        }
    }

    /**
     * 隐藏任务运行面板
     */
    function hideTaskPanel() {
        if (taskRunningPanel) {
            taskRunningPanel.style.display = 'none';
            taskStartTime = null;
        }
    }

    /**
     * 处理任务状态变化
     */
    function handleTaskStatusChange(detail) {
        var status = detail.status;
        var titleEl = document.getElementById('task-panel-title');
        var headerEl = taskRunningPanel ? taskRunningPanel.querySelector('.card-header') : null;
        
        switch (status) {
            case 'loading':
                if (titleEl) titleEl.textContent = '正在加载AI模型...';
                if (headerEl) headerEl.className = 'card-header bg-warning text-dark';
                break;
            case 'running':
                if (titleEl) titleEl.textContent = 'AI模型执行中';
                if (headerEl) headerEl.className = 'card-header bg-primary text-white';
                break;
            case 'completed':
                if (titleEl) titleEl.textContent = '任务完成';
                if (headerEl) headerEl.className = 'card-header bg-success text-white';
                // 不再自动刷新页面，避免中断 background script 处理
                // 显示提示信息，让用户手动刷新
                var statusMsg = document.getElementById('task-status-message');
                if (statusMsg) {
                    statusMsg.innerHTML = '<i class="mdi mdi-check-circle me-1 text-success"></i> 任务已完成！您可以手动刷新页面查看结果。';
                }
                // 可选：添加一个刷新按钮提示
                console.log('[TaskManager] 任务已完成，等待用户手动刷新页面');
                break;
            case 'failed':
                if (titleEl) titleEl.textContent = '任务失败';
                if (headerEl) headerEl.className = 'card-header bg-danger text-white';
                break;
            case 'idle':
                hideTaskPanel();
                break;
        }
    }

    /**
     * 处理任务进度更新
     */
    function handleTaskProgress(detail) {
        var foundCount = detail.foundCount || 0;
        var message = detail.message || '';
        
        // 不再使用进度条，改为显示找到的客户数量
        var progressBar = document.getElementById('task-progress-bar');
        if (progressBar) {
            // 使用一个模拟进度，基于找到的客户数量
            var displayProgress = Math.min(100, foundCount * 2); // 每找到一个客户增加2%
            progressBar.style.width = displayProgress + '%';
            progressBar.textContent = '已找到 ' + foundCount + ' 个客户';
            progressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
            progressBar.classList.add('bg-success');
        }
        
        var statusMsg = document.getElementById('task-status-message');
        if (statusMsg && message) {
            statusMsg.innerHTML = '<i class="mdi mdi-cog mdi-spin me-1"></i> ' + message;
        }
        
        // 更新候选客户数
        if (typeof AutoLeadAgentTaskRunner !== 'undefined') {
            var state = AutoLeadAgentTaskRunner.getState();
            
            var inferenceEl = document.getElementById('task-inference-count');
            if (inferenceEl && state.metrics) {
                inferenceEl.textContent = state.metrics.totalInferences || 0;
            }
            
            var candidatesEl = document.getElementById('task-candidates-count');
            if (candidatesEl) {
                candidatesEl.textContent = state.foundCount || foundCount || 0;
            }
        }
        
        // 同步更新全局的找到客户数
        if (typeof window.updateFoundCount === 'function') {
            window.updateFoundCount(foundCount);
        }
    }

    function maybeShowGuideModal() {
        try {
            var guideSeen = localStorage.getItem(GUIDE_SEEN_KEY);
            // 首次访问一定弹出；以后只有有未完成任务时提示继续
            if (!guideSeen || hasActiveTask) {
                showGuideModal(true);
            }
        } catch (e) {
            showGuideModal(true);
        }
    }

    function showGuideModal(fromAutoCheck) {
        var modalEl = document.getElementById('leadSearchGuideModal');
        if (!modalEl) {
            return;
        }

        var guideSection = document.getElementById('lead-search-guide-section');
        var resumeSection = document.getElementById('lead-search-resume-section');
        var resumeBtn = document.getElementById('auto-lead-agent-resume-task-btn');

        if (hasActiveTask && activeTaskId > 0) {
            if (resumeSection) resumeSection.classList.remove('d-none');
            if (guideSection) guideSection.classList.add('d-none');
            if (resumeBtn) resumeBtn.classList.remove('d-none');
        } else {
            if (guideSection) guideSection.classList.remove('d-none');
            if (resumeSection) resumeSection.classList.add('d-none');
            if (resumeBtn) resumeBtn.classList.add('d-none');
        }

        try {
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                // 兼容 Bootstrap 4 和 5
                if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
                    // Bootstrap 5
                    modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
                } else {
                    // Bootstrap 4 或旧版本
                    if (!modalInstance) {
                        // 创建新实例
                        modalInstance = new bootstrap.Modal(modalEl);
                    }
                }
                
                // 监听模态框显示事件，确保在显示时加载数据
                modalEl.addEventListener('shown.bs.modal', function onShown() {
                    loadSearchEngines();
                    loadTargetWebsites();
                    // 只执行一次，然后移除监听器
                    modalEl.removeEventListener('shown.bs.modal', onShown);
                }, { once: true });
                
                modalInstance.show();
            } else if (typeof $ !== 'undefined' && $.fn.modal) {
                // 使用 jQuery Bootstrap（如果可用）
                $(modalEl).on('shown.bs.modal', function() {
                    loadSearchEngines();
                    loadTargetWebsites();
                    $(modalEl).off('shown.bs.modal');
                });
                $(modalEl).modal('show');
            } else {
                // Fallback 简单弹窗
                alert('自动寻客：请在页面上方点击"新建寻找客户任务"按钮开始使用。');
                // 即使fallback也尝试加载
                setTimeout(function() {
                    loadSearchEngines();
                    loadTargetWebsites();
                }, 100);
            }
        } catch (e) {
            console.error('AutoLeadAgentTaskManager.showGuideModal error:', e);
            // 如果所有方法都失败，尝试直接显示模态框
            try {
                if (modalEl) {
                    modalEl.style.display = 'block';
                    modalEl.classList.add('show');
                    var backdrop = document.createElement('div');
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                }
            } catch (e2) {
                console.error('AutoLeadAgentTaskManager.showGuideModal fallback error:', e2);
            }
        }

        // 记录已看过引导
        try {
            localStorage.setItem(GUIDE_SEEN_KEY, '1');
        } catch (e) {}

        // 加载来源类型列表
        loadSourceTypes();
        // 加载搜索引擎和目标网站列表
        loadSearchEngines();
        loadTargetWebsites();
        
        // 监听来源类型选择变化（延迟绑定，确保DOM已加载）
        setTimeout(function() {
            var sourceTypeSelect = document.getElementById('auto-lead-agent-source-type-select');
            if (sourceTypeSelect) {
                sourceTypeSelect.addEventListener('change', function() {
                    handleSourceTypeChange(this.value);
                });
            }
        }, 100);
    }

    /**
     * 加载来源类型列表
     */
    function loadSourceTypes() {
        var url;
        if (typeof window.url === 'function') {
            url = window.url('auto-lead-agent/backend/index/source-types');
        } else if (typeof window.backend_url === 'function') {
            url = window.backend_url('auto-lead-agent/backend/index/source-types');
        } else {
            var baseUrl = window.location.pathname;
            url = baseUrl.replace(/\/[^\/]+$/, '/source-types');
        }
        
        var sourceTypeSelect = document.getElementById('auto-lead-agent-source-type-select');
        if (!sourceTypeSelect) return;
        
        fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
            return response.json();
        })
        .then(function(result) {
            if (result.success && result.data && result.data.length > 0) {
                // 清空现有选项（保留第一个空选项）
                sourceTypeSelect.innerHTML = '<option value="">请选择来源类型</option>';
                
                // 添加来源类型选项
                result.data.forEach(function(sourceType) {
                    var option = document.createElement('option');
                    option.value = sourceType.type || '';
                    option.textContent = sourceType.name || sourceType.type || '';
                    option.setAttribute('data-options', JSON.stringify(sourceType.options || []));
                    sourceTypeSelect.appendChild(option);
                });
            } else {
                sourceTypeSelect.innerHTML = '<option value="">暂无可用来源类型</option>';
            }
        })
        .catch(function(error) {
            console.error('Load source types error:', error);
            sourceTypeSelect.innerHTML = '<option value="">加载失败：' + error.message + '</option>';
        });
    }

    function createTaskFromForm() {
        var form = document.getElementById('auto-lead-agent-create-task-form');
        if (!form) {
            return;
        }

        // 获取来源类型和来源ID
        var sourceTypeSelect = document.getElementById('auto-lead-agent-source-type-select');
        var sourceIdSelect = document.getElementById('auto-lead-agent-source-id-select');
        var sourceType = sourceTypeSelect ? sourceTypeSelect.value : '';
        var sourceId = sourceIdSelect ? parseInt(sourceIdSelect.value || '0', 10) : 0;
        
        // 兼容字段：store_id（如果来源类型是store，则使用sourceId作为storeId）
        var storeId = 0;
        if (sourceType === 'store') {
            storeId = sourceId;
        }

        if (!sourceType) {
            alert('请选择来源类型');
            if (sourceTypeSelect) {
                sourceTypeSelect.focus();
            }
            return;
        }
        
        if (!sourceId) {
            alert('请选择具体项');
            if (sourceIdSelect) {
                sourceIdSelect.focus();
            }
            return;
        }
        
        // 获取选中的搜索引擎
        var selectedEngines = [];
        document.querySelectorAll('input[name="selected_search_engines[]"]:checked').forEach(function(cb) {
            selectedEngines.push(cb.value);
        });
        
        // 获取选中的目标网站
        var selectedWebsites = [];
        document.querySelectorAll('input[name="selected_target_websites[]"]:checked').forEach(function(cb) {
            selectedWebsites.push(cb.value);
        });
        
        // 验证
        if (selectedEngines.length === 0) {
            alert('请至少选择一个搜索引擎');
            return;
        }
        
        if (selectedWebsites.length === 0) {
            alert('请至少选择一个目标网站');
            return;
        }

        createTask(storeId, sourceType, sourceId);
    }
    
    /**
     * 处理来源类型选择变化
     */
    function handleSourceTypeChange(sourceType) {
        var sourceTypeSelect = document.getElementById('auto-lead-agent-source-type-select');
        var sourceIdSelect = document.getElementById('auto-lead-agent-source-id-select');
        var sourceOptionsContainer = document.getElementById('source-options-container');
        var sourceOptionsDescription = document.getElementById('source-options-description');
        
        if (!sourceTypeSelect || !sourceIdSelect || !sourceOptionsContainer) return;
        
        if (!sourceType) {
            // 隐藏选项容器
            sourceOptionsContainer.style.display = 'none';
            sourceIdSelect.innerHTML = '<option value="">请先选择来源类型</option>';
            return;
        }
        
        // 获取选中的来源类型选项
        var selectedOption = sourceTypeSelect.options[sourceTypeSelect.selectedIndex];
        if (!selectedOption) return;
        
        // 获取该类型的选项列表
        var optionsJson = selectedOption.getAttribute('data-options');
        if (!optionsJson) {
            sourceOptionsContainer.style.display = 'none';
            sourceIdSelect.innerHTML = '<option value="">该类型暂无可用选项</option>';
            return;
        }
        
        try {
            var options = JSON.parse(optionsJson);
            if (!Array.isArray(options) || options.length === 0) {
                sourceOptionsContainer.style.display = 'none';
                sourceIdSelect.innerHTML = '<option value="">该类型暂无可用选项</option>';
                return;
            }
            
            // 显示选项容器
            sourceOptionsContainer.style.display = 'block';
            
            // 清空并填充选项
            sourceIdSelect.innerHTML = '<option value="">请选择具体项</option>';
            options.forEach(function(option) {
                var opt = document.createElement('option');
                opt.value = option.id || '';
                opt.textContent = option.name || '未知';
                if (option.description) {
                    opt.setAttribute('title', option.description);
                }
                sourceIdSelect.appendChild(opt);
            });
            
            // 更新描述文本
            if (sourceOptionsDescription) {
                sourceOptionsDescription.textContent = '请选择要寻找客户的具体项';
            }
        } catch (e) {
            console.error('Parse source type options error:', e);
            sourceOptionsContainer.style.display = 'none';
            sourceIdSelect.innerHTML = '<option value="">解析选项失败</option>';
        }
    }

    /**
     * 加载搜索引擎列表
     */
    function loadSearchEngines() {
        var engines = ['Baidu', 'Google', 'Bing', 'DuckDuckGo', 'Yandex', 'Yahoo', '360搜索', '搜狗', 
                       'Ecosia', 'Qwant', 'Startpage', 'Naver', 'Yahoo Japan', 'Ask.com', 'AOL Search'];
        
        var container = document.getElementById('search-engines-container');
        if (!container) return;
        
        // 创建可搜索的多选组件
        var html = '<div class="searchable-multiselect">';
        html += '<input type="text" class="form-control form-control-sm mb-2" placeholder="搜索搜索引擎..." id="search-engine-filter">';
        html += '<div class="multiselect-list" style="max-height: 200px; overflow-y: auto;">';
        
        engines.forEach(function(engine) {
            var engineId = 'search-engine-' + engine.replace(/\s+/g, '-').toLowerCase();
            html += '<div class="form-check mb-2 multiselect-item" data-name="' + engine.toLowerCase() + '">';
            html += '<input class="form-check-input" type="checkbox" name="selected_search_engines[]" value="' + engine + '" id="' + engineId + '">';
            html += '<label class="form-check-label" for="' + engineId + '">' + engine + '</label>';
            html += '</div>';
        });
        
        html += '</div>';
        html += '</div>';
        
        container.innerHTML = html;
        
        // 绑定搜索功能
        var filterInput = container.querySelector('#search-engine-filter');
        if (filterInput) {
            filterInput.addEventListener('input', function() {
                var keyword = this.value.toLowerCase();
                container.querySelectorAll('.multiselect-item').forEach(function(item) {
                    var name = item.getAttribute('data-name');
                    if (name.indexOf(keyword) !== -1) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        }
    }

    /**
     * 加载目标网站列表
     */
    function loadTargetWebsites() {
        // 使用正确的路由：listActive 方法对应 /target-website/list-active
        // 根据Weline路由规则，listActive 会转换为 list-active
        var url;
        // 根据Weline路由规则，listActive 方法对应 list-active 路由
        // 路径格式：auto-lead-agent/backend/target-website/list-active
        if (typeof window.url === 'function') {
            // 使用 window.url 函数构建URL
            url = window.url('auto-lead-agent/backend/target-website/list-active');
        } else if (typeof window.backend_url === 'function') {
            // 使用 window.backend_url 函数
            url = window.backend_url('auto-lead-agent/backend/target-website/list-active');
        } else {
            // Fallback: 使用当前路径构建
            var baseUrl = window.location.pathname;
            // 从当前路径提取基础路径，然后构建目标URL
            // 例如：/xxx/auto-lead-agent/backend/index/index -> /xxx/auto-lead-agent/backend/target-website/list-active
            url = baseUrl.replace(/\/[^\/]+\/[^\/]+$/, '/target-website/list-active');
        }
        
        console.log('[TaskManager] 加载目标网站列表，URL:', url);
        
        var container = document.getElementById('target-websites-container');
        if (!container) return;
        
        // 显示加载状态
        container.innerHTML = '<div class="text-center py-3">' +
            '<div class="spinner-border spinner-border-sm text-primary" role="status">' +
            '<span class="visually-hidden">加载中...</span>' +
            '</div>' +
            '<p class="mt-2 text-muted small">正在加载目标网站列表...</p>' +
            '</div>';
        
        fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status + ': ' + response.statusText);
            }
            return response.json();
        })
        .then(function(result) {
            if (result.success && result.data && result.data.length > 0) {
                // 创建可搜索的多选组件
                var html = '<div class="searchable-multiselect">';
                html += '<input type="text" class="form-control form-control-sm mb-2" placeholder="搜索目标网站..." id="target-website-filter">';
                html += '<div class="multiselect-list" style="max-height: 200px; overflow-y: auto;">';
                
                result.data.forEach(function(website) {
                    var websiteId = 'target-website-' + website.target_website_id;
                    var searchText = (website.name + ' ' + website.domain).toLowerCase();
                    html += '<div class="form-check mb-2 multiselect-item" data-name="' + searchText + '">';
                    html += '<input class="form-check-input" type="checkbox" name="selected_target_websites[]" value="' + website.target_website_id + '" id="' + websiteId + '">';
                    html += '<label class="form-check-label" for="' + websiteId + '">';
                    html += '<strong>' + website.name + '</strong> <code class="ms-2">' + website.domain + '</code>';
                    html += '</label>';
                    html += '</div>';
                });
                
                html += '</div>';
                html += '</div>';
                
                container.innerHTML = html;
                
                // 绑定搜索功能
                var filterInput = container.querySelector('#target-website-filter');
                if (filterInput) {
                    filterInput.addEventListener('input', function() {
                        var keyword = this.value.toLowerCase();
                        container.querySelectorAll('.multiselect-item').forEach(function(item) {
                            var name = item.getAttribute('data-name');
                            if (name.indexOf(keyword) !== -1) {
                                item.style.display = '';
                            } else {
                                item.style.display = 'none';
                            }
                        });
                    });
                }
            } else {
                container.innerHTML = '<div class="text-warning">' + 
                    (result.message || '暂无可用的目标网站，请先在"搜索目标网站管理"中添加') + 
                    '</div>';
            }
        })
        .catch(function(error) {
            console.error('Load target websites error:', error);
            container.innerHTML = '<div class="text-danger">加载目标网站列表失败：' + error.message + 
                '<br><small>请检查网络连接或联系管理员</small></div>';
        });
    }

    function createTask(storeId, sourceType, sourceId) {
        // 获取选中的搜索引擎
        var selectedEngines = [];
        document.querySelectorAll('input[name="selected_search_engines[]"]:checked').forEach(function(cb) {
            selectedEngines.push(cb.value);
        });
        
        // 获取选中的目标网站
        var selectedWebsites = [];
        document.querySelectorAll('input[name="selected_target_websites[]"]:checked').forEach(function(cb) {
            selectedWebsites.push(cb.value);
        });
        
        // 验证
        if (selectedEngines.length === 0) {
            alert('请至少选择一个搜索引擎');
            return;
        }
        
        if (selectedWebsites.length === 0) {
            alert('请至少选择一个目标网站');
            return;
        }
        
        // 基于当前页面路径构建URL，只替换方法名
        var currentPath = window.location.pathname;
        var url = currentPath.replace(/\/[^\/]+$/, '/create-task');
        
        // 构建请求体
        var body = 'store_id=' + encodeURIComponent(String(storeId)) + 
                  '&source_type=' + encodeURIComponent(sourceType || '') +
                  '&source_id=' + encodeURIComponent(String(sourceId || 0));
        
        // 添加搜索引擎
        selectedEngines.forEach(function(engine) {
            body += '&selected_search_engines[]=' + encodeURIComponent(engine);
        });
        
        // 添加目标网站
        selectedWebsites.forEach(function(websiteId) {
            body += '&selected_target_websites[]=' + encodeURIComponent(websiteId);
        });

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            if (result && result.success) {
                if (modalInstance) {
                    modalInstance.hide();
                }
                
                // 获取任务ID和来源类型画像信息
                var taskId = result.data && result.data.task_id;
                var sourceTypeProfile = result.data && result.data.source_type_profile;
                var storeProfile = result.data && result.data.store_profile || sourceTypeProfile; // 兼容字段
                
                if (taskId) {
                    // 显示任务面板并启动AI模型执行任务
                    showTaskPanel();
                    runTaskWithModel(taskId, storeId, sourceTypeProfile || storeProfile);
                } else {
                    alert(result.message || '任务创建成功，但无法获取任务ID');
                    window.location.reload();
                }
            } else {
                alert((result && result.message) ? result.message : '创建搜索任务失败');
            }
        }).catch(function (error) {
            console.error('AutoLeadAgentTaskManager.createTask error:', error);
            alert('创建搜索任务失败：' + (error.message || ''));
        });
    }

    /**
     * 使用AI模型执行任务
     */
    function runTaskWithModel(taskId, storeId, sourceTypeProfile) {
        // 如果没有来源类型画像，构建一个默认的
        if (!sourceTypeProfile) {
            sourceTypeProfile = {
                source_type: 'store',
                source_id: storeId,
                id: storeId,
                name: '来源 ' + storeId,
                description: '自动寻客任务',
                industry: '通用',
                region: '',
                products: [],
                keywords: []
            };
        }
        
        // 从任务数据中读取搜索引擎和目标网站
        var baseUrl = window.location.pathname;
        var taskUrl = baseUrl.replace(/\/[^\/]+$/, '/task-detail?task_id=' + taskId);
        
        fetch(taskUrl, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(response) {
            return response.json();
        })
        .then(function(result) {
            if (result.success && result.data) {
                var taskData = result.data;
                var selectedSearchEngines = taskData.selected_search_engines || [];
                var selectedTargetWebsites = taskData.selected_target_websites || [];
                
                // 将任务配置添加到sourceTypeProfile
                sourceTypeProfile.selected_search_engines = selectedSearchEngines;
                sourceTypeProfile.selected_target_websites = selectedTargetWebsites;
            }
            
            // 检查TaskRunner是否可用
            if (typeof AutoLeadAgentTaskRunner === 'undefined') {
                console.error('TaskRunner not loaded');
                alert('AI模型执行器未加载，请刷新页面后重试');
                return;
            }
            
            // 显示任务运行面板（在任务开始前）
            if (typeof window.showTaskRunningPanel === 'function') {
                window.showTaskRunningPanel(taskId);
            }
            
            // 启动任务（传递来源类型画像）
            AutoLeadAgentTaskRunner.startTask(taskId, storeId, sourceTypeProfile)
                .then(function(success) {
                    if (!success) {
                        console.error('Task failed to start');
                    }
                })
                .catch(function(error) {
                    console.error('Task error:', error);
                    alert('任务执行失败：' + (error.message || '未知错误'));
                });
        })
        .catch(function(error) {
            console.error('Load task detail error:', error);
            // 即使加载失败也继续执行任务
            if (typeof AutoLeadAgentTaskRunner !== 'undefined') {
                // 显示任务运行面板（在任务开始前）
                if (typeof window.showTaskRunningPanel === 'function') {
                    window.showTaskRunningPanel(taskId);
                }
                
                // 启动任务（传递来源类型画像）
                AutoLeadAgentTaskRunner.startTask(taskId, storeId, sourceTypeProfile)
                    .then(function(success) {
                        if (!success) {
                            console.error('Task failed to start');
                        }
                    })
                    .catch(function(error) {
                        console.error('Task error:', error);
                        alert('任务执行失败：' + (error.message || '未知错误'));
                    });
            } else {
                alert('AI模型执行器未加载，请刷新页面后重试');
            }
        });
    }

    function continueTask(taskId) {
        if (!taskId) {
            alert('任务ID参数无效');
            return;
        }

        // 基于当前页面路径构建URL，只替换方法名
        var currentPath = window.location.pathname;
        var url = currentPath.replace(/\/[^\/]+$/, '/continue-task');

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: 'task_id=' + encodeURIComponent(String(taskId))
        }).then(function (response) {
            return response.json();
        }).then(function (result) {
            if (result && result.success) {
                if (modalInstance) {
                    modalInstance.hide();
                }
                
                // 获取来源类型画像信息并启动任务
                var storeId = result.data && result.data.store_id;
                var sourceTypeProfile = result.data && result.data.source_type_profile;
                var storeProfile = result.data && result.data.store_profile || sourceTypeProfile; // 兼容字段
                
                // 显示任务面板并启动AI模型执行任务
                showTaskPanel();
                runTaskWithModel(taskId, storeId, sourceTypeProfile || storeProfile);
            } else {
                alert((result && result.message) ? result.message : '继续任务失败');
            }
        }).catch(function (error) {
            console.error('AutoLeadAgentTaskManager.continueTask error:', error);
            alert('继续任务失败：' + (error.message || ''));
        });
    }

    // 自动初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return {
        createTask: createTask,
        continueTask: continueTask,
        showGuideModal: showGuideModal,
        runTaskWithModel: runTaskWithModel
    };
})();
