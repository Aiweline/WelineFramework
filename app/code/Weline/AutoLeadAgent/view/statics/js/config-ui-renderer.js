/**
 * AutoLeadAgent 配置页 - UI 渲染器
 */

var ConfigUIRenderer = (function () {
    'use strict';

    var elements = {
        badge: 'hf-model-current-badge',
        listBody: 'hf_model_list_body',
        detailName: 'hf_model_detail_name',
        detailStatus: 'hf_model_detail_status',
        detailSize: 'hf_model_detail_size',
        detailTask: 'hf_model_detail_task',
        detailTags: 'hf_model_detail_tags'
    };

    function getEl(id) { return document.getElementById(id); }

    /**
     * 获取设备可用内存（MB）
     * 优先使用 navigator.deviceMemory，fallback 到 performance.memory
     * @returns {number} 可用内存 MB，默认返回 4096（4GB）
     */
    function getDeviceMemoryMB() {
        // navigator.deviceMemory 返回设备 RAM 大小（GB），但仅支持部分浏览器且可能被舍入
        if (typeof navigator !== 'undefined' && navigator.deviceMemory) {
            var deviceMemGB = navigator.deviceMemory;
            console.log('[ConfigUIRenderer] 设备内存:', deviceMemGB, 'GB');
            return deviceMemGB * 1024; // 转换为 MB
        }
        
        // performance.memory (仅 Chrome 支持)
        if (typeof performance !== 'undefined' && performance.memory) {
            var jsHeapLimit = performance.memory.jsHeapSizeLimit;
            var limitMB = jsHeapLimit / (1024 * 1024);
            console.log('[ConfigUIRenderer] JS 堆上限:', limitMB.toFixed(0), 'MB');
            // JS 堆上限通常是系统内存的一部分，估算设备内存约为堆限制的 2-4 倍
            return limitMB * 2;
        }
        
        // 默认假设 4GB
        console.log('[ConfigUIRenderer] 无法检测设备内存，默认 4GB');
        return 4096;
    }

    /**
     * 获取模型所需的最小内存（MB）
     * 考虑模型大小 + 加载时的额外开销（约 2.5 倍）
     * @param {number} modelSizeMB 模型大小 MB
     * @returns {number} 所需内存 MB
     */
    function getRequiredMemoryMB(modelSizeMB) {
        if (!modelSizeMB || modelSizeMB <= 0) return 0;
        // 加载模型时需要的内存约为模型大小的 2.5 倍（模型本身 + 中间状态 + 推理缓存）
        return Math.ceil(modelSizeMB * 2.5);
    }

    /**
     * 检查模型是否超出内存限制
     * @param {number} modelSizeMB 模型大小 MB
     * @param {number} deviceMemoryMB 设备内存 MB
     * @returns {Object} { exceeded: boolean, required: number, available: number, message: string }
     */
    function checkMemoryLimit(modelSizeMB, deviceMemoryMB) {
        var requiredMB = getRequiredMemoryMB(modelSizeMB);
        // 预留 30% 内存给系统和其他应用
        var availableMB = deviceMemoryMB * 0.7;
        var exceeded = requiredMB > availableMB;
        
        return {
            exceeded: exceeded,
            required: requiredMB,
            available: availableMB,
            message: exceeded 
                ? '此模型需要约 ' + Math.ceil(requiredMB / 1024 * 10) / 10 + ' GB 内存，超出设备可用内存（约 ' + Math.ceil(availableMB / 1024 * 10) / 10 + ' GB）'
                : ''
        };
    }

    // 缓存设备内存值
    var cachedDeviceMemory = null;
    function getCachedDeviceMemory() {
        if (cachedDeviceMemory === null) {
            cachedDeviceMemory = getDeviceMemoryMB();
        }
        return cachedDeviceMemory;
    }

    /**
     * 渲染模型列表
     */
    function renderModelList(models, onModelSelect) {
        var listBody = getEl(elements.listBody);
        if (!listBody) return;
        listBody.innerHTML = '';

        console.log('[ConfigUIRenderer] 渲染模型列表，数量:', models ? models.length : '加载中');
        if (models && models.length > 0) {
            console.log('[ConfigUIRenderer] 第一个模型示例:', models[0]);
        }

        // models 为 null 表示加载中
        if (models === null) {
            listBody.innerHTML = '<tr><td colspan="4" class="text-center py-4">' +
                '<div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>' +
                '<span class="small text-muted">正在连接扩展并获取 Hugging Face 模型...</span>' +
                '<div class="mt-2 small text-warning" style="font-size: 0.75rem;">提示：如果一直显示此信息，请确保已安装并开启 <span class="fw-bold">AutoLeadAgent 扩展</span></div>' +
                '</td></tr>';
            return;
        }

        if (!models || !models.length) {
            listBody.innerHTML = '<tr><td colspan="4" class="text-muted small text-center">未找到匹配的模型</td></tr>';
            return;
        }

        // 获取设备内存（缓存）
        var deviceMemoryMB = getCachedDeviceMemory();
        console.log('[ConfigUIRenderer] 设备内存:', deviceMemoryMB, 'MB');

        models.forEach(function (m) {
            var modelId = m.id || m.name || '';
            var modelSizeMB = m.estimated_size_mb || m._sizeMB || 0;

            // 检查模型是否支持 WebLLM/ONNX 格式
            var isSupported = typeof HFModelManager !== 'undefined' && HFModelManager.isModelSupportedForWebLLM ?
                HFModelManager.isModelSupportedForWebLLM(modelId) : true;

            // 检查内存限制
            var memoryCheck = checkMemoryLimit(modelSizeMB, deviceMemoryMB);
            var exceedsMemory = memoryCheck.exceeded;

            // 检查模型是否已下载
            var isDownloaded = false;
            if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.checkModelDownloadedByMetadata) {
                var downloadStatus = LocalFileStorage.checkModelDownloadedByMetadata(modelId);
                isDownloaded = downloadStatus.downloaded;
            }

            var tr = document.createElement('tr');
            tr.className = 'hf-model-row';
            tr.setAttribute('data-model-id', modelId);
            
            // 如果超出内存，添加视觉提示
            if (exceedsMemory) {
                tr.classList.add('table-secondary');
                tr.style.opacity = '0.7';
            }

            var tdName = document.createElement('td');
            tdName.style.width = '20%';
            tdName.style.maxWidth = '20%';
            tdName.style.overflow = 'hidden';
            tdName.style.textOverflow = 'ellipsis';
            tdName.style.whiteSpace = 'nowrap';
            tdName.title = modelId;
            if (m._recommended) {
                var badge = document.createElement('span');
                // 根据模型大小设置不同颜色
                var sizeMB = m._sizeMB || 0;
                if (sizeMB >= 600) {
                    badge.className = 'badge bg-danger me-1';
                } else if (sizeMB >= 400) {
                    badge.className = 'badge bg-warning text-dark me-1';
                } else {
                    badge.className = 'badge bg-success me-1';
                }
                badge.style.cssText = 'font-size:0.65rem;vertical-align:middle;';
                badge.textContent = m._recLabel || '推荐';
                tdName.appendChild(badge);
                var nameSpan = document.createElement('span');
                nameSpan.textContent = modelId;
                tdName.appendChild(nameSpan);
            } else {
                tdName.textContent = modelId;
            }

            var tdSize = document.createElement('td');
            tdSize.className = 'text-end small text-muted';
            tdSize.textContent = ConfigUtils.formatFileSizeFromMB(m.estimated_size_mb) || '-';

            var tdDownloads = document.createElement('td');
            tdDownloads.className = 'text-end small text-muted';
            tdDownloads.textContent = ConfigUtils.formatNumber(m.downloads || 0);

            var tdAction = document.createElement('td');
            tdAction.className = 'text-center';

            // 根据支持状态、内存限制和下载状态显示不同的按钮
            if (!isSupported) {
                tdAction.innerHTML = '<button class="btn btn-sm btn-outline-secondary" disabled title="该模型不支持 WebLLM/ONNX 格式">不支持</button>';
            } else if (exceedsMemory) {
                // 超出内存限制
                tdAction.innerHTML = '<button class="btn btn-sm btn-outline-danger" disabled title="' + memoryCheck.message + '">' +
                    '<i class="mdi mdi-memory me-1"></i>内存不足</button>';
            } else if (isDownloaded) {
                tdAction.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check"></i> 已下载</span>';
            } else {
                tdAction.innerHTML = '<button class="btn btn-sm btn-outline-primary select-btn">选择</button>';
            }

            tr.appendChild(tdName);
            tr.appendChild(tdSize);
            tr.appendChild(tdDownloads);
            tr.appendChild(tdAction);

            tr.addEventListener('click', function () {
                // 不支持的模型或超出内存的模型不允许选择
                if (!isSupported) return;
                if (exceedsMemory) {
                    if (typeof ConfigUtils !== 'undefined') {
                        ConfigUtils.safeToast('warning', memoryCheck.message);
                    }
                    return;
                }

                // 高亮选中行
                var rows = listBody.querySelectorAll('.hf-model-row');
                rows.forEach(function (r) { r.classList.remove('table-active'); });
                tr.classList.add('table-active');

                // 按钮变为 loading 状态
                var btn = tdAction.querySelector('.select-btn');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>加载中...';
                    btn.classList.remove('btn-outline-primary');
                    btn.classList.add('btn-primary');
                }

                // 详情区域显示 loading
                var detailName = document.getElementById('hf-model-detail-name');
                var detailStatus = document.getElementById('hf-model-detail-status');
                if (detailName) detailName.textContent = modelId;
                if (detailStatus) {
                    detailStatus.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>正在获取模型信息...';
                    detailStatus.className = 'badge bg-info';
                }

                // 回调（加载详情等）
                if (onModelSelect) onModelSelect(modelId, m);

                // 详情加载完成后恢复按钮（延时检测 detailStatus 变化）
                var checkCount = 0;
                var checkTimer = setInterval(function () {
                    checkCount++;
                    var st = document.getElementById('hf-model-detail-status');
                    // 如果详情已加载完成（状态文字变了）或超时 10s
                    if ((st && st.textContent.indexOf('加载中') === -1 && st.textContent.indexOf('获取') === -1) || checkCount > 20) {
                        clearInterval(checkTimer);
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="mdi mdi-check me-1"></i>已选择';
                            btn.classList.remove('btn-primary');
                            btn.classList.add('btn-success');
                        }
                    }
                }, 500);
            });

            listBody.appendChild(tr);
        });
    }

    /**
     * 渲染模型详情
     */
    function renderModelDetail(info) {
        var detailName = getEl(elements.detailName);
        var detailStatus = getEl(elements.detailStatus);
        var detailSize = getEl(elements.detailSize);
        var detailTask = getEl(elements.detailTask);
        var detailTags = getEl(elements.detailTags);

        if (detailName) detailName.textContent = info.id || info.name || '尚未选择模型';
        if (detailStatus) detailStatus.innerHTML = info.id ? '<span class="badge bg-secondary">已选择</span>' : '';
        if (detailSize) detailSize.textContent = info.estimated_size_mb ? ('大小约：' + ConfigUtils.formatFileSizeFromMB(info.estimated_size_mb)) : '';
        if (detailTask) detailTask.textContent = info.pipeline_tag ? ('任务类型：' + info.pipeline_tag) : '';

        if (detailTags) {
            detailTags.innerHTML = '';
            (info.tags || []).forEach(function (t) {
                var span = document.createElement('span');
                span.className = 'badge bg-light text-dark me-1 mb-1';
                span.textContent = t;
                detailTags.appendChild(span);
            });
        }
    }

    /**
     * 更新当前模型徽章
     */
    function updateCurrentBadge(modelId) {
        var badge = getEl(elements.badge);
        if (badge) badge.textContent = modelId || '未配置模型';
    }

    /**
     * 更新下载进度 UI
     */
    function updateDownloadProgress(state) {
        var modal = document.getElementById('hf-download-modal');
        if (!modal) {
            console.warn('[ConfigUIRenderer] 下载弹窗元素不存在');
            return;
        }

        // 兼容不同版本的 Bootstrap
        if (state.isDownloading && !modal.classList.contains('show')) {
            try {
                // Bootstrap 5.x
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var bsModal;
                    if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
                        bsModal = bootstrap.Modal.getOrCreateInstance(modal);
                    } else {
                        // Bootstrap 5.0 早期版本
                        bsModal = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
                    }
                    bsModal.show();
                } else {
                    // 降级：jQuery Bootstrap
                    if (typeof $ !== 'undefined' && typeof $.fn.modal !== 'undefined') {
                        $(modal).modal('show');
                    }
                }
            } catch (e) {
                console.warn('[ConfigUIRenderer] 无法显示下载模态框:', e);
            }
        }

        // 总进度
        var totalBar = document.getElementById('hf-download-progress-bar');
        var totalText = document.getElementById('hf-download-progress-text');
        var speedText = document.getElementById('hf-download-speed');
        if (totalBar) {
            totalBar.style.width = state.progress + '%';
            totalBar.textContent = Math.round(state.progress) + '%';
            totalBar.setAttribute('aria-valuenow', state.progress);
        }
        if (totalText) {
            totalText.textContent = ConfigUtils.formatFileSize(state.downloadedSize) + ' / ' +
                ConfigUtils.formatFileSize(state.totalSize) + ' (' + Math.round(state.progress) + '%)';
        }
        if (speedText && state.downloadSpeed !== undefined && state.downloadSpeed !== null) {
            var speedMB = state.downloadSpeed / 1024 / 1024; // MB/s
            if (speedMB < 1) {
                speedText.textContent = '速度: ' + speedMB.toFixed(2) + ' MB/s';
            } else if (speedMB < 1024) {
                speedText.textContent = '速度: ' + (speedMB / 1024).toFixed(2) + ' GB/s';
            } else {
                speedText.textContent = '速度: ' + (speedMB / 1024 / 1024).toFixed(2) + ' TB/s';
            }
        }

        // 当前文件
        var fileText = document.getElementById('hf-download-progress-file');
        var fileSpeedText = document.getElementById('hf-download-file-speed');
        if (fileText) fileText.textContent = '当前文件: ' + (state.currentFile || '准备中...');

        // 当前文件进度
        var fileBar = document.getElementById('hf-download-file-progress-bar');
        var fileProgressText = document.getElementById('hf-download-file-progress-text');
        if (fileBar && state.currentFileProgress !== undefined) {
            fileBar.style.width = state.currentFileProgress + '%';
            fileBar.textContent = Math.round(state.currentFileProgress) + '%';
            fileBar.setAttribute('aria-valuenow', state.currentFileProgress);
        }
        if (fileProgressText && state.currentFileSize > 0) {
            fileProgressText.textContent = ConfigUtils.formatFileSize(state.currentFileDownloaded || 0) + ' / ' +
                ConfigUtils.formatFileSize(state.currentFileSize);
        }

        // 计算并显示剩余时间
        var etaText = document.getElementById('hf-download-file-eta');
        if (etaText) {
            // 检查是否有有效的下载速度和总大小
            if (state.downloadSpeed !== undefined && state.downloadSpeed !== null && state.downloadSpeed > 0 && state.totalSize > 0) {
                var remainingSize = state.totalSize - state.downloadedSize;
                var remainingSeconds = remainingSize / state.downloadSpeed;

                if (remainingSeconds > 0) {
                    if (remainingSeconds < 60) {
                        etaText.textContent = '剩余时间: ' + Math.round(remainingSeconds) + ' 秒';
                    } else if (remainingSeconds < 3600) {
                        etaText.textContent = '剩余时间: ' + Math.round(remainingSeconds / 60) + ' 分钟';
                    } else {
                        etaText.textContent = '剩余时间: ' + (remainingSeconds / 3600).toFixed(1) + ' 小时';
                    }
                } else {
                    etaText.textContent = '剩余时间: 即将完成';
                }
            } else {
                etaText.textContent = '剩余时间: 计算中...';
            }
        }
    }

    /**
     * 隐藏下载模态框
     */
    function hideDownloadModal() {
        var modal = document.getElementById('hf-download-modal');
        if (modal) {
            try {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var bsModal = bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                } else if (typeof $ !== 'undefined' && typeof $.fn.modal !== 'undefined') {
                    $(modal).modal('hide');
                }
            } catch (e) {
                console.warn('[ConfigUIRenderer] 无法隐藏下载模态框:', e);
            }
        }
    }

    return {
        renderModelList: renderModelList,
        renderModelDetail: renderModelDetail,
        updateCurrentBadge: updateCurrentBadge,
        updateDownloadProgress: updateDownloadProgress,
        hideDownloadModal: hideDownloadModal
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigUIRenderer = ConfigUIRenderer;
}
