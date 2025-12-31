// AutoLeadAgent 配置页 - Hugging Face 模型管理前端逻辑
// 负责模型搜索、详情展示及保存模型配置

(function () {
    'use strict';

    function safeToast(type, message, duration) {
        try {
            duration = duration || 3000; // 默认3秒
            if (typeof showToast === 'function') {
                showToast(type, message, duration);
                return;
            }
            if (typeof Toastify !== 'undefined') {
                Toastify({
                    text: message,
                    duration: duration,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: type === 'success' ? '#28a745' : (type === 'warning' ? '#ffc107' : '#dc3545')
                }).showToast();
                return;
            }
            if (typeof toastr !== 'undefined' && typeof toastr[type] === 'function') {
                toastr[type](message);
                return;
            }
        } catch (e) {
            // ignore
        }
        // 降级方案：使用 console 输出，不使用 alert
        console.log('[Toast]', type, ':', message);
    }
    
    // 导出 safeToast 到全局，供其他模块使用
    if (typeof window !== 'undefined') {
        window.safeToast = safeToast;
    }

    function getBaseConfigUrl() {
        var path = window.location.pathname || '';
        // /auto-lead-agent/backend/config/index -> /auto-lead-agent/backend/config
        return path.replace(/\/index[^\/]*$/, '');
    }

    function buildUrl(action, params) {
        var url = getBaseConfigUrl() + '/' + action;
        if (params && typeof params === 'object') {
            var qs = new URLSearchParams(params).toString();
            if (qs) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
            }
        }
        return url;
    }

    function formatNumber(num) {
        if (num === null || num === undefined || isNaN(num)) {
            return '0';
        }
        num = Number(num);
        if (num >= 1000000) {
            return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        }
        if (num >= 1000) {
            return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        }
        return String(num);
    }

    /**
     * 格式化文件大小（字节转换为 MB/GB）
     */
    function formatFileSize(bytes) {
        if (!bytes || bytes === 0 || isNaN(bytes)) {
            return '-';
        }
        bytes = Number(bytes);
        
        // 转换为 MB
        var mb = bytes / 1024 / 1024;
        
        // 如果大于 1024 MB，显示为 GB
        if (mb >= 1024) {
            var gb = mb / 1024;
            return gb.toFixed(2).replace(/\.0+$/, '') + ' GB';
        }
        
        // 显示为 MB
        return mb.toFixed(1).replace(/\.0+$/, '') + ' MB';
    }

    /**
     * 格式化文件大小（从 MB 值）
     */
    function formatFileSizeFromMB(mb) {
        if (!mb || mb === 0 || isNaN(mb)) {
            return '-';
        }
        mb = Number(mb);
        
        // 如果大于 1024 MB，显示为 GB
        if (mb >= 1024) {
            var gb = mb / 1024;
            return gb.toFixed(2).replace(/\.0+$/, '') + ' GB';
        }
        
        // 显示为 MB
        return mb.toFixed(1).replace(/\.0+$/, '') + ' MB';
    }

    function bindHFModelConfig() {
        var card = document.getElementById('hf-model-config-card');
        if (!card) {
            return;
        }

        var currentModelId = card.getAttribute('data-current-model-id') || '';
        var enabledFlag = card.getAttribute('data-enabled') === '1';
        var cacheSize = parseInt(card.getAttribute('data-cache-size') || '10240', 10);

        var badge = document.getElementById('hf-model-current-badge');
        var enabledInput = document.getElementById('hf_model_enabled');
        var cacheInput = document.getElementById('hf_model_cache_size');
        var searchInput = document.getElementById('hf_model_search_input');
        var taskSelect = document.getElementById('hf_model_task_select');
        var searchBtn = document.getElementById('hf_model_search_btn');
        var listBody = document.getElementById('hf_model_list_body');
        var detailName = document.getElementById('hf_model_detail_name');
        var detailStatus = document.getElementById('hf_model_detail_status');
        var detailSize = document.getElementById('hf_model_detail_size');
        var detailTask = document.getElementById('hf_model_detail_task');
        var detailTags = document.getElementById('hf_model_detail_tags');
        var refreshBtn = document.getElementById('hf_model_refresh_btn');
        var saveBtn = document.getElementById('hf_model_save_btn');

        var selectedModelId = currentModelId || '';
        
        // 扩展 ID 缓存
        var extensionId = null;
        var extensionIdDetecting = false;
        var extensionReady = false;
        var extensionVersion = null;

        if (enabledInput) {
            enabledInput.checked = !!enabledFlag;
        }
        if (cacheInput && !isNaN(cacheSize)) {
            cacheInput.value = cacheSize;
        }
        if (badge) {
            badge.textContent = currentModelId || badge.textContent || '未配置模型';
        }

        /**
         * 监听扩展就绪消息
         */
        window.addEventListener('message', function (event) {
            if (event.source !== window) return;
            
            // 监听扩展就绪消息
            if (event.data && event.data.type === 'AUTOLEADAGENT_READY') {
                extensionReady = true;
                extensionVersion = event.data.version;
                console.log('[HFModelConfig] 扩展已就绪，版本:', extensionVersion);
                
                // 扩展就绪后，标记为可用，不需要立即 ping（避免与 checkExtensionAvailable 冲突）
                // ping 验证会在 checkExtensionAvailable 中进行
            }
        });

        /**
         * 检测并获取扩展 ID
         */
        function detectExtensionId() {
            return new Promise(function (resolve) {
                // 如果扩展已就绪（通过 content script），直接返回 true（使用 postMessage 方式）
                if (extensionReady) {
                    resolve('content-script'); // 使用特殊标识表示通过 content script
                    return;
                }

                // 如果已经检测到扩展 ID，直接返回
                if (extensionId) {
                    resolve(extensionId);
                    return;
                }

                // 如果正在检测，等待检测完成
                if (extensionIdDetecting) {
                    var checkInterval = setInterval(function () {
                        if (!extensionIdDetecting) {
                            clearInterval(checkInterval);
                            resolve(extensionId || (extensionReady ? 'content-script' : null));
                        }
                    }, 100);
                    return;
                }

                extensionIdDetecting = true;

                // 检查 Chrome API 是否可用
                if (typeof chrome === 'undefined' || !chrome.runtime || !chrome.runtime.sendMessage) {
                    extensionIdDetecting = false;
                    // 即使没有 chrome.runtime，也可以尝试使用 postMessage（content script 可能已加载）
                    resolve('content-script');
                    return;
                }

                // 尝试已知的扩展 ID 列表
                var possibleIds = window.AUTOLEADAGENT_EXTENSION_IDS || [];
                
                // 如果没有配置扩展 ID，尝试使用 content script 方式（即使还没收到就绪消息）
                if (possibleIds.length === 0) {
                    extensionIdDetecting = false;
                    // 即使没有收到就绪消息，也尝试使用 content script（可能消息还没到达）
                    resolve('content-script');
                    return;
                }

                // 尝试每个可能的扩展 ID
                var triedCount = 0;
                var found = false;

                possibleIds.forEach(function (extId) {
                    try {
                        chrome.runtime.sendMessage(extId, { action: 'ping' }, function (response) {
                            triedCount++;
                            
                            if (found) return; // 已经找到，忽略后续响应

                            if (chrome.runtime.lastError) {
                                // 这个 ID 不对，继续尝试下一个
                                if (triedCount >= possibleIds.length) {
                                    extensionIdDetecting = false;
                                    resolve(null);
                                }
                                return;
                            }

                            if (response && response.success) {
                                found = true;
                                extensionId = extId;
                                extensionIdDetecting = false;
                                console.log('[HFModelConfig] 检测到扩展 ID:', extId, '版本:', response.version);
                                resolve(extId);
                            } else if (triedCount >= possibleIds.length) {
                                extensionIdDetecting = false;
                                resolve(null);
                            }
                        });
                    } catch (e) {
                        triedCount++;
                        console.warn('[HFModelConfig] 尝试扩展 ID 失败:', extId, e);
                        if (triedCount >= possibleIds.length && !found) {
                            extensionIdDetecting = false;
                            resolve(null);
                        }
                    }
                });

                // 如果列表为空，立即返回
                if (possibleIds.length === 0) {
                    extensionIdDetecting = false;
                    resolve(null);
                }
            });
        }

        /**
         * 发送消息到扩展（自动检测扩展 ID）
         * 优化：修复内存泄漏、统一超时处理、增强错误处理
         */
        function sendMessageToExtension(message, callback) {
            detectExtensionId().then(function (extId) {
                // 如果使用 content script 方式（extId === 'content-script' 或 extId === null）
                if (!extId || extId === 'content-script') {
                    // 通过 window.postMessage 发送消息，让 content script 转发到 background
                    console.log('[HFModelConfig] 通过 content script 转发消息到扩展');
                    
                    var requestId = 'hf_req_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                    var hasResponded = false; // 防止重复响应
                    var timeoutId = null;
                    
                    var responseHandler = function (event) {
                        if (event.source !== window) return;
                        if (event.data && event.data.type === 'AUTOLEADAGENT_RESPONSE' && event.data.requestId === requestId) {
                            // 防止重复处理响应
                            if (hasResponded) {
                                console.warn('[HFModelConfig] 收到重复响应，已忽略:', requestId);
                                return;
                            }
                            hasResponded = true;
                            
                            // 清理监听器和超时
                            window.removeEventListener('message', responseHandler);
                            if (timeoutId) {
                                clearTimeout(timeoutId);
                                timeoutId = null;
                            }
                            
                            if (callback) {
                                callback(event.data.response);
                            }
                        }
                    };
                    window.addEventListener('message', responseHandler);
                    
                    // 确定 action 字段
                    // 如果消息有 type 字段（如 HF_SEARCH_MODELS），使用 type 作为 action
                    // 如果消息有 action 字段（如 ping），使用 action
                    var action = message.type || message.action || 'unknown';
                    
                    // 将消息转换为 content script 期望的格式
                    // content script 会将 action 和 payload 合并后发送给 background
                    // background 的 onMessage 监听器会检查 request.action 或 request.type
                    window.postMessage({
                        type: 'AUTOLEADAGENT_REQUEST',
                        action: action, // 例如 'HF_SEARCH_MODELS' 或 'ping'
                        payload: message, // 完整的消息对象，content script 会展开合并
                        requestId: requestId
                    }, '*');
                    
                    // 设置超时（根据消息类型设置不同的超时时间，与 content.js 保持一致）
                    var timeout = 30000; // 默认30秒
                    if (action === 'ping') {
                        timeout = 5000; // ping 操作5秒超时
                    } else if (message.type === 'HF_DOWNLOAD_MODEL') {
                        timeout = 1800000; // 模型下载操作30分钟超时（模型文件可能很大）
                    } else if (message.type && message.type.startsWith('HF_')) {
                        timeout = 60000; // 其他 HF_ 类型的操作60秒超时
                    } else if (action === 'crawl') {
                        timeout = 600000; // crawl 操作10分钟超时
                    }
                    
                    timeoutId = setTimeout(function () {
                        // 防止超时后仍处理响应
                        if (hasResponded) {
                            return;
                        }
                        hasResponded = true;
                        
                        window.removeEventListener('message', responseHandler);
                        if (callback) {
                            console.warn('[HFModelConfig] 消息超时，action:', action, 'requestId:', requestId);
                            callback({
                                success: false,
                                error: '扩展响应超时（' + Math.floor(timeout / 1000) + '秒），请检查扩展是否正常运行',
                                errorType: 'timeout_error',
                                requestId: requestId
                            });
                        }
                    }, timeout);
                    return;
                }

                // 使用检测到的扩展 ID 直接发送消息（externally_connectable 方式）
                try {
                    chrome.runtime.sendMessage(extId, message, function (response) {
                        // 检查扩展错误
                        if (chrome.runtime.lastError) {
                            var errorMsg = chrome.runtime.lastError.message;
                            console.error('[HFModelConfig] 扩展通信错误:', errorMsg);
                            
                            // 特殊处理扩展上下文失效错误
                            if (errorMsg && errorMsg.includes('Extension context invalidated')) {
                                if (callback) {
                                    callback({
                                        success: false,
                                        error: '扩展上下文已失效：扩展可能已被重新加载，请刷新页面后重试',
                                        errorType: 'extension_context_invalidated',
                                        suggestion: '请刷新页面并重新加载扩展'
                                    });
                                }
                                return;
                            }
                            
                            if (callback) {
                                callback({
                                    success: false,
                                    error: '扩展通信错误: ' + errorMsg,
                                    errorType: 'extension_error'
                                });
                            }
                            return;
                        }
                        
                        if (callback) {
                            callback(response);
                        }
                    });
                } catch (e) {
                    console.error('[HFModelConfig] 发送消息到扩展失败:', e);
                    if (callback) {
                        callback({
                            success: false,
                            error: '发送消息异常: ' + (e.message || String(e)),
                            errorType: 'send_error'
                        });
                    }
                }
            }).catch(function (error) {
                console.error('[HFModelConfig] 检测扩展ID失败:', error);
                if (callback) {
                    callback({
                        success: false,
                        error: '检测扩展失败: ' + (error.message || String(error)),
                        errorType: 'detection_error'
                    });
                }
            });
        }

        function renderModelList(models) {
            if (!listBody) return;
            listBody.innerHTML = '';

            if (!models || !models.length) {
                var trEmpty = document.createElement('tr');
                var tdEmpty = document.createElement('td');
                tdEmpty.colSpan = 4; // 更新为4列（包括操作列）
                tdEmpty.className = 'text-muted small text-center';
                tdEmpty.textContent = '未找到匹配的模型，请尝试更换搜索关键词';
                trEmpty.appendChild(tdEmpty);
                listBody.appendChild(trEmpty);
                return;
            }

            // 检查每个模型是否已下载，并排序（已下载的排在最前面）
            Promise.all(models.map(function(m) {
                var modelId = m.id || m.name || '';
                if (!modelId) {
                    return Promise.resolve({ model: m, isDownloaded: false });
                }
                
                // 检查模型是否已下载（本地文件系统）
                return checkModelExists(modelId).then(function(isDownloaded) {
                    return { model: m, isDownloaded: isDownloaded };
                }).catch(function() {
                    return { model: m, isDownloaded: false };
                });
            })).then(function(modelsWithStatus) {
                // 排序：已下载的排在最前面
                modelsWithStatus.sort(function(a, b) {
                    if (a.isDownloaded && !b.isDownloaded) return -1;
                    if (!a.isDownloaded && b.isDownloaded) return 1;
                    return 0;
                });

                // 渲染模型列表
                modelsWithStatus.forEach(function(item) {
                    var m = item.model;
                    var isDownloaded = item.isDownloaded;
                    
                    var tr = document.createElement('tr');
                    tr.className = 'hf-model-row';
                    if (isDownloaded) {
                        tr.classList.add('table-success');
                    }
                    tr.setAttribute('data-model-id', m.id || m.name || '');

                    var tdName = document.createElement('td');
                    var nameContainer = document.createElement('div');
                    nameContainer.style.display = 'flex';
                    nameContainer.style.alignItems = 'center';
                    nameContainer.style.gap = '8px';
                    
                    var nameText = document.createElement('span');
                    nameText.textContent = m.name || m.id || '';
                    nameContainer.appendChild(nameText);
                    
                    // 如果已下载，显示标记
                    if (isDownloaded) {
                        var downloadedBadge = document.createElement('span');
                        downloadedBadge.className = 'badge bg-success';
                        downloadedBadge.textContent = '已下载';
                        downloadedBadge.style.fontSize = '0.75rem';
                        nameContainer.appendChild(downloadedBadge);
                    }
                    
                    tdName.appendChild(nameContainer);

                    // 计算模型大小（优先使用 estimated_size_mb，其次使用 estimated_size）
                    var modelSize = '-';
                    var hasSize = false;
                    if (m.estimated_size_mb && m.estimated_size_mb > 0) {
                        modelSize = formatFileSizeFromMB(m.estimated_size_mb);
                        hasSize = true;
                    } else if (m.estimated_size && m.estimated_size > 0) {
                        modelSize = formatFileSize(m.estimated_size);
                        hasSize = true;
                    } else if (m.size && m.size > 0) {
                        modelSize = formatFileSize(m.size);
                        hasSize = true;
                    }

                    var tdSize = document.createElement('td');
                    tdSize.className = 'text-end small text-muted';
                    var currentModelId = m.id || m.name || '';
                    tdSize.setAttribute('data-model-id', currentModelId);
                    
                    if (hasSize) {
                        tdSize.textContent = modelSize;
                        tdSize.setAttribute('data-size-mb', m.estimated_size_mb || (m.estimated_size ? (m.estimated_size / 1024 / 1024).toFixed(2) : '') || (m.size ? (m.size / 1024 / 1024).toFixed(2) : ''));
                    } else {
                        // 如果没有大小信息，显示"计算中..."并异步计算
                        tdSize.textContent = '计算中...';
                        tdSize.setAttribute('data-calculating', 'true');
                        
                        // 如果有 siblings 信息，直接计算
                        if (m.siblings && Array.isArray(m.siblings) && m.siblings.length > 0) {
                            calculateModelSizeFromSiblings(currentModelId, m.siblings).then(function(totalSize) {
                                if (totalSize > 0) {
                                    var sizeText = formatFileSize(totalSize);
                                    tdSize.textContent = sizeText;
                                    tdSize.removeAttribute('data-calculating');
                                    tdSize.setAttribute('data-size-mb', (totalSize / 1024 / 1024).toFixed(2));
                                    // 更新模型对象，以便后续使用
                                    m.estimated_size = totalSize;
                                    m.estimated_size_mb = (totalSize / 1024 / 1024).toFixed(2);
                                } else {
                                    tdSize.textContent = '未知';
                                    tdSize.removeAttribute('data-calculating');
                                }
                            }).catch(function(error) {
                                console.error('[HFModelConfig] 计算模型大小失败:', currentModelId, error);
                                tdSize.textContent = '未知';
                                tdSize.removeAttribute('data-calculating');
                            });
                        } else {
                            // 如果没有 siblings，尝试获取模型信息
                            loadModelInfoForSize(currentModelId, tdSize);
                        }
                    }

                    var tdDownloads = document.createElement('td');
                    tdDownloads.className = 'text-end small text-muted';
                    tdDownloads.textContent = formatNumber(m.downloads || 0);

                    tr.appendChild(tdName);
                    tr.appendChild(tdSize);
                    tr.appendChild(tdDownloads);

                    tr.addEventListener('click', function () {
                        var modelId = tr.getAttribute('data-model-id') || '';
                        if (!modelId) return;
                        selectedModelId = modelId;

                        console.log('[HFModelConfig] 选择模型（仅选择，不下载）:', modelId);

                        var rows = listBody.querySelectorAll('.hf-model-row');
                        rows.forEach(function (row) {
                            row.classList.remove('table-active');
                        });
                        tr.classList.add('table-active');

                        // 注意：这里只是获取模型信息用于显示，不是下载模型
                        // 真正的下载在点击"保存为当前模型"按钮时通过扩展进行
                        loadModelInfo(modelId);
                    });

                    listBody.appendChild(tr);
                });
            }).catch(function(error) {
                console.error('[HFModelConfig] 检查模型下载状态失败:', error);
                // 如果检查失败，仍然渲染列表（不排序）
                models.forEach(function (m) {
                    var tr = document.createElement('tr');
                    tr.className = 'hf-model-row';
                    tr.setAttribute('data-model-id', m.id || m.name || '');

                    var tdName = document.createElement('td');
                    tdName.textContent = m.name || m.id || '';

                    var modelSize = '-';
                    var hasSize = false;
                    if (m.estimated_size_mb) {
                        modelSize = formatFileSizeFromMB(m.estimated_size_mb);
                        hasSize = true;
                    } else if (m.estimated_size) {
                        modelSize = formatFileSize(m.estimated_size);
                        hasSize = true;
                    } else if (m.size) {
                        modelSize = formatFileSize(m.size);
                        hasSize = true;
                    }

                    var tdSize = document.createElement('td');
                    tdSize.className = 'text-end small text-muted';
                    var modelId = m.id || m.name || '';
                    tdSize.setAttribute('data-model-id', modelId);
                    
                    if (hasSize) {
                        tdSize.textContent = modelSize;
                        tdSize.setAttribute('data-size-mb', m.estimated_size_mb || (m.estimated_size ? (m.estimated_size / 1024 / 1024).toFixed(2) : '') || (m.size ? (m.size / 1024 / 1024).toFixed(2) : ''));
                    } else {
                        // 如果没有大小信息，显示"计算中..."并异步计算
                        tdSize.textContent = '计算中...';
                        tdSize.setAttribute('data-calculating', 'true');
                        
                        // 如果有 siblings 信息，直接计算
                        if (m.siblings && Array.isArray(m.siblings) && m.siblings.length > 0) {
                            calculateModelSizeFromSiblings(modelId, m.siblings).then(function(totalSize) {
                                if (totalSize > 0) {
                                    var sizeText = formatFileSize(totalSize);
                                    tdSize.textContent = sizeText;
                                    tdSize.removeAttribute('data-calculating');
                                    tdSize.setAttribute('data-size-mb', (totalSize / 1024 / 1024).toFixed(2));
                                    // 更新模型对象，以便后续使用
                                    m.estimated_size = totalSize;
                                    m.estimated_size_mb = (totalSize / 1024 / 1024).toFixed(2);
                                } else {
                                    tdSize.textContent = '未知';
                                    tdSize.removeAttribute('data-calculating');
                                }
                            }).catch(function(error) {
                                console.error('[HFModelConfig] 计算模型大小失败:', modelId, error);
                                tdSize.textContent = '未知';
                                tdSize.removeAttribute('data-calculating');
                            });
                        } else {
                            // 如果没有 siblings，尝试获取模型信息
                            loadModelInfoForSize(modelId, tdSize);
                        }
                    }

                    var tdDownloads = document.createElement('td');
                    tdDownloads.className = 'text-end small text-muted';
                    tdDownloads.textContent = formatNumber(m.downloads || 0);

                    // 操作列（错误处理路径，不检查下载状态）
                    var tdActions = document.createElement('td');
                    tdActions.className = 'text-center';
                    tdActions.innerHTML = '<span class="text-muted small">-</span>';

                    tr.appendChild(tdName);
                    tr.appendChild(tdSize);
                    tr.appendChild(tdDownloads);
                    tr.appendChild(tdActions);

                    tr.addEventListener('click', function () {
                        var modelId = tr.getAttribute('data-model-id') || '';
                        if (!modelId) return;
                        selectedModelId = modelId;

                        var rows = listBody.querySelectorAll('.hf-model-row');
                        rows.forEach(function (row) {
                            row.classList.remove('table-active');
                        });
                        tr.classList.add('table-active');

                        loadModelInfo(modelId);
                    });

                    listBody.appendChild(tr);
                });
            });
        }

        /**
         * 删除模型文件
         */
        function deleteModelFiles(modelId, modelName) {
            if (!modelId) {
                safeToast('error', '模型ID无效');
                return;
            }
            
            // 获取模型信息以显示大小
            var modelSize = '未知大小';
            if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.getModelMetadataInfo) {
                LocalFileStorage.getModelMetadataInfo(modelId).then(function(metadata) {
                    if (metadata && metadata.totalSize) {
                        modelSize = formatFileSize(metadata.totalSize);
                    } else if (metadata && metadata.files && metadata.files.length > 0) {
                        // 如果没有 totalSize，计算文件总大小
                        var totalSize = 0;
                        metadata.files.forEach(function(f) {
                            totalSize += f.size || 0;
                        });
                        if (totalSize > 0) {
                            modelSize = formatFileSize(totalSize);
                        }
                    }
                    showDeleteConfirm(modelId, modelName, modelSize);
                }).catch(function() {
                    showDeleteConfirm(modelId, modelName, modelSize);
                });
            } else {
                showDeleteConfirm(modelId, modelName, modelSize);
            }
        }

        /**
         * 显示删除确认对话框
         */
        function showDeleteConfirm(modelId, modelName, modelSize) {
            var confirmMsg = '确定要清理模型 "' + modelName + '" 的缓存文件吗？\n\n';
            confirmMsg += '模型大小: ' + modelSize + '\n';
            confirmMsg += '此操作将删除所有已下载的模型文件，释放存储空间。\n';
            confirmMsg += '删除后如需使用该模型，需要重新下载。\n\n';
            confirmMsg += '是否继续？';
            
            if (!confirm(confirmMsg)) {
                return;
            }
            
            // 显示加载状态
            safeToast('info', '正在清理模型文件...');
            
            // 调用删除接口（删除本地文件系统中的模型）
            var url = buildUrl('delete-model-cache');
            var formData = new FormData();
            formData.append('model_id', modelId);
            
            fetch(url, {
                method: 'POST',
                body: formData
            })
            .then(function(res) {
                return res.json();
            })
            .then(function(data) {
                if (data && data.success) {
                    console.log('[HFModelConfig] 模型缓存已删除:', data);
                    safeToast('success', '模型缓存已清理');
                    
                    // 刷新模型列表
                    if (searchInput && taskSelect) {
                        searchModels();
                    }
                    
                    // 如果删除的是当前选中的模型，清空详情面板
                    if (selectedModelId === modelId) {
                        selectedModelId = null;
                        if (detailName) {
                            detailName.textContent = '尚未选择模型';
                        }
                        if (detailStatus) {
                            detailStatus.innerHTML = '';
                        }
                        if (detailSize) {
                            detailSize.textContent = '';
                        }
                    }
                } else {
                    var errorMsg = (data && data.message) || '清理模型失败';
                    safeToast('error', errorMsg);
                }
            })
            .catch(function(error) {
                console.error('[HFModelConfig] 删除模型失败:', error);
                safeToast('error', '清理模型失败: ' + (error.message || error.toString()));
            });
        }

        function renderModelDetail(info) {
            if (!info) {
                console.warn('[HFModelConfig] renderModelDetail: info 为空');
                return;
            }
            var modelId = info.id || info.name || '';
            console.log('[HFModelConfig] renderModelDetail: 渲染模型详情', modelId);
            
            // 打印完整的 JSON 字符串，方便复制查看结构
            try {
                var jsonString = JSON.stringify(info, null, 2);
                console.log('[HFModelConfig] renderModelDetail: 模型详情 JSON 字符串:');
                console.log(jsonString);
                // 同时打印对象（方便在控制台展开查看）
                console.log('[HFModelConfig] renderModelDetail: 模型详情对象:', info);
            } catch (error) {
                console.error('[HFModelConfig] renderModelDetail: 序列化 JSON 失败:', error);
                console.log('[HFModelConfig] renderModelDetail: 原始对象:', info);
            }
            
            if (detailName) {
                detailName.textContent = info.name || info.id || '未知模型';
            }
            
            // 检查并显示下载状态（本地文件系统）
            if (detailStatus && modelId) {
                detailStatus.innerHTML = '<span class="badge bg-secondary">检查中...</span>';
                checkModelExists(modelId).then(function(isDownloaded) {
                    if (detailStatus) {
                        if (isDownloaded) {
                            detailStatus.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check-circle me-1"></i>已下载</span>';
                        } else {
                            detailStatus.innerHTML = '<span class="badge bg-warning"><i class="mdi mdi-download me-1"></i>未下载</span>';
                        }
                    }
                }).catch(function(error) {
                    console.error('[HFModelConfig] 检查下载状态失败:', error);
                    if (detailStatus) {
                        detailStatus.innerHTML = '<span class="badge bg-secondary">状态未知</span>';
                    }
                });
            }
            
            if (detailSize) {
                // 先检查列表中是否已经计算过大小
                var existingSize = null;
                if (listBody) {
                    var rows = listBody.querySelectorAll('.hf-model-row');
                    for (var i = 0; i < rows.length; i++) {
                        var row = rows[i];
                        if (row.getAttribute('data-model-id') === modelId) {
                            var sizeCell = row.querySelector('td[data-model-id="' + modelId + '"]');
                            if (sizeCell) {
                                var sizeText = sizeCell.textContent.trim();
                                var sizeMB = sizeCell.getAttribute('data-size-mb');
                                // 如果已经有实际大小（不是"计算中..."、"未知"、"点击查看"），直接使用
                                if (sizeText && 
                                    sizeText !== '计算中...' && 
                                    sizeText !== '未知' && 
                                    sizeText !== '点击查看' &&
                                    sizeText !== '-' &&
                                    sizeMB) {
                                    existingSize = {
                                        text: sizeText,
                                        mb: parseFloat(sizeMB)
                                    };
                                    console.log('[HFModelConfig] 使用列表中已计算的大小:', modelId, existingSize.text);
                                    break;
                                }
                            }
                        }
                    }
                }
                
                // 如果列表中已有大小，直接使用
                if (existingSize) {
                    detailSize.textContent = '大小约：' + existingSize.text;
                    // 更新 info 对象，以便后续使用
                    info.estimated_size = existingSize.mb * 1024 * 1024;
                    info.estimated_size_mb = existingSize.mb.toFixed(2);
                } else if (info.estimated_size_mb && info.estimated_size_mb > 0) {
                    // 优先使用 estimated_size_mb 或 estimated_size
                    detailSize.textContent = '大小约：' + formatFileSizeFromMB(info.estimated_size_mb);
                } else if (info.estimated_size && info.estimated_size > 0) {
                    detailSize.textContent = '大小约：' + formatFileSize(info.estimated_size);
                } else if (info.siblings && Array.isArray(info.siblings) && info.siblings.length > 0) {
                    // 如果 API 没有提供大小，且列表中没有，才尝试从 siblings 中计算
                    detailSize.textContent = '计算中...';
                    calculateModelSizeFromSiblings(modelId, info.siblings).then(function(totalSize) {
                        if (detailSize && totalSize > 0) {
                            detailSize.textContent = '大小约：' + formatFileSize(totalSize);
                            // 同时更新 info 对象，以便后续使用
                            info.estimated_size = totalSize;
                            info.estimated_size_mb = (totalSize / 1024 / 1024).toFixed(2);
                            
                            // 同时更新列表中的大小显示
                            if (listBody) {
                                var rows = listBody.querySelectorAll('.hf-model-row');
                                for (var i = 0; i < rows.length; i++) {
                                    var row = rows[i];
                                    if (row.getAttribute('data-model-id') === modelId) {
                                        var sizeCell = row.querySelector('td[data-model-id="' + modelId + '"]');
                                        if (sizeCell && (sizeCell.textContent === '计算中...' || sizeCell.textContent === '未知')) {
                                            sizeCell.textContent = formatFileSize(totalSize);
                                            sizeCell.setAttribute('data-size-mb', (totalSize / 1024 / 1024).toFixed(2));
                                            sizeCell.removeAttribute('data-calculating');
                                        }
                                        break;
                                    }
                                }
                            }
                        } else if (detailSize) {
                            detailSize.textContent = '大小未知';
                        }
                    }).catch(function(error) {
                        console.error('[HFModelConfig] 计算模型大小失败:', error);
                        if (detailSize) {
                            detailSize.textContent = '大小未知';
                        }
                    });
                } else {
                    detailSize.textContent = '大小未知';
                }
            }
            
            // 更新列表中的模型大小显示（如果该模型在列表中）
            if (listBody && (info.id || info.name)) {
                var modelId = info.id || info.name;
                var rows = listBody.querySelectorAll('.hf-model-row');
                console.log('[HFModelConfig] 查找列表中的模型行:', modelId, '找到', rows.length, '行');
                
                rows.forEach(function (row) {
                    if (row.getAttribute('data-model-id') === modelId) {
                        var sizeCell = row.querySelector('td[data-model-id="' + modelId + '"]') || row.querySelector('td:nth-child(2)');
                        if (sizeCell) {
                            // 检查列表中是否已经有有效大小（不是"计算中..."、"未知"等）
                            var currentSize = sizeCell.textContent.trim();
                            var hasValidSize = currentSize && 
                                              currentSize !== '计算中...' && 
                                              currentSize !== '未知' && 
                                              currentSize !== '点击查看' &&
                                              currentSize !== '-' &&
                                              currentSize !== '无法获取' &&
                                              sizeCell.getAttribute('data-size-mb');
                            
                            // 如果列表中已经有有效大小，不更新（避免重复计算）
                            if (hasValidSize) {
                                console.log('[HFModelConfig] 列表中已有大小，跳过更新:', modelId, currentSize);
                                return;
                            }
                            
                            var newSize = '-';
                            var sizeMB = null;
                            
                            // 尝试从多个来源获取大小信息（只使用已计算好的，不重新计算）
                            if (info.estimated_size_mb && info.estimated_size_mb > 0) {
                                sizeMB = info.estimated_size_mb;
                                newSize = formatFileSizeFromMB(sizeMB);
                            } else if (info.estimated_size && info.estimated_size > 0) {
                                sizeMB = (info.estimated_size / 1024 / 1024).toFixed(2);
                                newSize = formatFileSize(info.estimated_size);
                            }
                            // 注意：不再从 siblings 计算，因为列表渲染时已经计算过了，避免重复计算
                            
                            if (newSize !== '-') {
                                sizeCell.textContent = newSize;
                                sizeCell.className = 'text-end small text-muted';
                                sizeCell.style.cursor = '';
                                sizeCell.title = '';
                                if (sizeMB) {
                                    sizeCell.setAttribute('data-size-mb', sizeMB);
                                }
                                sizeCell.removeAttribute('data-calculating');
                                console.log('[HFModelConfig] 已更新列表中的模型大小:', modelId, newSize, '(', sizeMB, 'MB)');
                            } else {
                                // 如果没有大小信息，且列表中没有，保持原状（可能是"计算中..."）
                                console.log('[HFModelConfig] 无法从详情获取模型大小，保持列表原状:', modelId);
                                sizeCell.title = '无法获取模型大小信息';
                            }
                        } else {
                            console.warn('[HFModelConfig] 未找到大小单元格:', modelId);
                        }
                    }
                });
            } else {
                console.warn('[HFModelConfig] 无法更新列表大小: listBody=', !!listBody, 'modelId=', info.id || info.name);
            }
            if (detailTask) {
                if (info.pipeline_tag) {
                    detailTask.textContent = '任务类型：' + info.pipeline_tag;
                } else {
                    detailTask.textContent = '';
                }
            }
            if (detailTags) {
                detailTags.innerHTML = '';
                var tags = info.tags || [];
                if (tags.length) {
                    var frag = document.createDocumentFragment();
                    tags.forEach(function (t) {
                        var span = document.createElement('span');
                        span.className = 'badge bg-light text-dark me-1 mb-1';
                        span.textContent = t;
                        frag.appendChild(span);
                    });
                    detailTags.appendChild(frag);
                } else {
                    var p = document.createElement('div');
                    p.className = 'text-muted small';
                    p.textContent = '该模型暂无标签信息，可点击模型页查看详细介绍。';
                    detailTags.appendChild(p);
                }
            }
        }

        function searchModels() {
            if (!taskSelect) return;
            var q = searchInput ? (searchInput.value || '').trim() : '';
            var task = taskSelect.value || 'text-generation';

            if (listBody) {
                listBody.innerHTML = '<tr><td colspan=\"3\" class=\"text-center text-muted small\">正在加载模型列表...</td></tr>';
            }

            console.log('[HFModelConfig] 通过扩展搜索模型:', { query: q, task: task });

            // 检查扩展是否可用
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                safeToast('error', '浏览器扩展未安装或不可用，无法搜索模型');
                renderModelList([]);
                return;
            }

            // 通过扩展搜索模型
            var message = {
                type: 'HF_SEARCH_MODELS',
                query: q,
                task: task,
                limit: 50
            };

            sendMessageToExtension(message, function (response) {
                console.log('[HFModelConfig] 收到搜索响应:', response);

                if (!response) {
                    console.error('[HFModelConfig] 扩展未响应，可能扩展未安装或未启用');
                    safeToast('error', '扩展未响应，请确保扩展已安装并启用。如果已安装，请刷新页面后重试。');
                    if (listBody) {
                        listBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted small">扩展未响应，请检查扩展状态</td></tr>';
                    }
                    return;
                }

                if (!response.success) {
                    console.error('[HFModelConfig] 搜索失败:', response.error);
                    var errorMsg = response.error || '搜索模型失败';
                    var errorType = response.errorType || '';
                    var originalError = response.originalError || '';
                    
                    // 判断是否是网络错误
                    var isNetworkError = errorMsg.includes('网络') || 
                                        errorMsg.includes('Failed to fetch') || 
                                        errorMsg.includes('timeout') || 
                                        errorMsg.includes('超时') ||
                                        errorMsg.includes('连接失败') ||
                                        errorType === 'NetworkError' ||
                                        errorType === 'AbortError';
                    
                    if (isNetworkError) {
                        // 显示网络错误模态框
                        var errorDetails = originalError ? ('原始错误: ' + originalError) : errorMsg;
                        showNetworkErrorModal(errorMsg, errorDetails);
                    } else {
                        // 其他错误，使用 toast 提示
                        safeToast('error', errorMsg);
                    }
                    
                    // 更新列表显示
                    if (listBody) {
                        if (isNetworkError) {
                            listBody.innerHTML = '<tr><td colspan="3" class="text-center text-warning small" style="padding: 20px;">网络连接失败，请查看弹出的提示框</td></tr>';
                        } else {
                            var errorHtml = errorMsg.replace(/\n/g, '<br>');
                            listBody.innerHTML = '<tr><td colspan="3" class="text-center text-danger small" style="padding: 20px;">' + errorHtml + '</td></tr>';
                        }
                    }
                    return;
                }

                console.log('[HFModelConfig] 搜索成功，找到', response.total || 0, '个模型');
                console.log('[HFModelConfig] 模型数据:', response.data);
                
                if (!response.data || !Array.isArray(response.data) || response.data.length === 0) {
                    console.warn('[HFModelConfig] 搜索结果为空');
                    if (listBody) {
                        listBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted small">未找到匹配的模型，请尝试更换搜索关键词</td></tr>';
                    }
                    return;
                }
                
                renderModelList(response.data);
            });
        }

        function loadModelInfo(modelId) {
            if (!modelId) {
                return;
            }

            console.log('[HFModelConfig] 通过扩展获取模型信息（不下载）:', modelId);

            if (detailName) {
                detailName.textContent = modelId + ' (loading...)';
            }

            // 检查扩展是否可用
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                safeToast('error', '浏览器扩展未安装或不可用，无法获取模型信息');
                if (detailName) {
                    detailName.textContent = modelId + ' (扩展不可用)';
                }
                return;
            }

            // 通过扩展获取模型信息
            var message = {
                type: 'HF_GET_MODEL_INFO',
                modelId: modelId
            };

            sendMessageToExtension(message, function (response) {
                console.log('[HFModelConfig] 收到模型信息响应:', response);

                if (!response) {
                    safeToast('error', '扩展未响应，请确保扩展已安装并启用');
                    if (detailName) {
                        detailName.textContent = modelId + ' (未响应)';
                    }
                    return;
                }

                if (response.needLogin) {
                    // 需要登录
                    console.log('[HFModelConfig] 获取模型信息需要登录，已打开登录页面');
                    showLoginModalForModelInfo(response.loginTabId, modelId);
                    // 等待登录成功后再继续（通过 handleLoginSuccessForModelInfo）
                    window.__pendingModelInfoModelId = modelId;
                    return;
                }

                if (!response.success) {
                    var errorMsg = response.error || '获取模型信息失败';
                    var errorType = response.errorType || '';
                    var originalError = response.originalError || '';
                    
                    // 判断是否是网络错误
                    var isNetworkError = errorMsg.includes('网络') || 
                                        errorMsg.includes('Failed to fetch') || 
                                        errorMsg.includes('timeout') || 
                                        errorMsg.includes('超时') ||
                                        errorMsg.includes('连接失败') ||
                                        errorType === 'NetworkError' ||
                                        errorType === 'AbortError';
                    
                    if (isNetworkError) {
                        // 显示网络错误模态框
                        var errorDetails = originalError ? ('原始错误: ' + originalError) : errorMsg;
                        showNetworkErrorModal(errorMsg, errorDetails);
                    } else {
                        // 其他错误，使用 toast 提示
                        safeToast('error', errorMsg);
                    }
                    
                    if (detailName) {
                        detailName.textContent = modelId + ' (获取失败)';
                    }
                    return;
                }

                console.log('[HFModelConfig] 模型信息获取成功（通过扩展，仅信息，未下载）');
                console.log('[HFModelConfig] 模型信息数据:', response.data);
                console.log('[HFModelConfig] 模型大小信息:', {
                    estimated_size_mb: response.data?.estimated_size_mb,
                    estimated_size: response.data?.estimated_size,
                    siblings: response.data?.siblings?.length
                });
                renderModelDetail(response.data || {});
            });
        }

        /**
         * 处理登录成功（用于获取模型信息）
         */
        function handleLoginSuccessForModelInfo(modelId) {
            console.log('[HFModelConfig] 登录成功，重新获取模型信息:', modelId);
            hideLoginModal();

            if (window.__pendingModelInfoModelId === modelId) {
                // 重新获取模型信息
                loadModelInfo(modelId);
                // 清理
                window.__pendingModelInfoModelId = null;
            }
        }

        /**
         * 显示登录提示模态框（用于获取模型信息）
         */
        function showLoginModalForModelInfo(loginTabId, modelId) {
            var modal = document.getElementById('hf-login-modal');
            if (modal) {
                // 确保 aria-hidden 正确设置
                modal.setAttribute('aria-hidden', 'false');
                modal.removeAttribute('aria-hidden');
                
                var bsModal = new bootstrap.Modal(modal, {
                    backdrop: 'static',
                    keyboard: false
                });
                bsModal.show();
                
                // 监听模态框显示事件，确保 aria-hidden 正确
                modal.addEventListener('shown.bs.modal', function () {
                    modal.setAttribute('aria-hidden', 'false');
                }, { once: true });

                // 更新按钮文字
                var btnText = document.getElementById('hf-login-check-btn-text');
                if (btnText) {
                    btnText.textContent = '我已登录，继续获取信息';
                }

                // 绑定手动检查登录按钮
                var checkBtn = document.getElementById('hf-login-check-btn');
                if (checkBtn) {
                    checkBtn.onclick = function () {
                        checkLoginAndContinueForModelInfo(modelId);
                    };
                }
            }
        }

        /**
         * 手动检查登录并继续获取模型信息
         */
        function checkLoginAndContinueForModelInfo(modelId) {
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                safeToast('error', '浏览器扩展未安装或不可用');
                return;
            }

            var checkBtn = document.getElementById('hf-login-check-btn');
            if (checkBtn) {
                checkBtn.disabled = true;
                checkBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 检查中...';
            }

            var message = {
                type: 'HF_CHECK_LOGIN'
            };
            console.log('[HFModelConfig] 发送登录检查消息到扩展（获取模型信息）:', message);

            sendMessageToExtension(message, function (response) {
                console.log('[HFModelConfig] 收到登录检查响应:', response);

                if (checkBtn) {
                    checkBtn.disabled = false;
                    var btnText = document.getElementById('hf-login-check-btn-text');
                    if (btnText) {
                        checkBtn.innerHTML = '<i class="mdi mdi-check-circle"></i> <span id="hf-login-check-btn-text">' + btnText.textContent + '</span>';
                    } else {
                        checkBtn.innerHTML = '<i class="mdi mdi-check-circle"></i> 我已登录，继续';
                    }
                }

                if (!response) {
                    safeToast('error', '扩展未响应，请确保扩展已安装并启用');
                    return;
                }

                if (response.success && response.loggedIn) {
                    // 登录成功，重新获取模型信息
                    handleLoginSuccessForModelInfo(modelId);
                } else {
                    // 仍未登录
                    safeToast('error', '检测到尚未登录，请先完成 HuggingFace 登录');
                }
            });
        }

        // 下载进度状态
        var downloadState = {
            isDownloading: false,
            currentModelId: null,
            progress: 0,
            downloaded: 0,
            total: 0,
            currentFile: '',
            // 单个文件进度
            currentFileProgress: 0,
            currentFileDownloaded: 0,
            currentFileTotal: 0,
            // 下载速度计算
            speedHistory: [], // 存储最近的速度记录 [{time, downloaded}]
            lastUpdateTime: null,
            lastDownloaded: 0,
            currentSpeed: 0, // MB/s
            fileSpeedHistory: [], // 单个文件的速度记录
            fileLastUpdateTime: null,
            fileLastDownloaded: 0,
            currentFileSpeed: 0 // MB/s
        };

        // 监听扩展消息（通过 window.postMessage 或 chrome.runtime.onMessageExternal）
        // 由于配置页面在 localhost，需要通过 content script 转发或使用 onMessageExternal
        if (typeof chrome !== 'undefined' && chrome.runtime) {
            // 尝试使用 onMessageExternal（如果支持）
            if (chrome.runtime.onMessageExternal) {
                chrome.runtime.onMessageExternal.addListener(function (message, sender, sendResponse) {
                    handleExtensionMessage(message);
                    return true;
                });
            }
            
            // 也监听 window.postMessage（content script 可能通过这种方式转发）
            window.addEventListener('message', function (event) {
                if (event.source !== window) return;
                
                // 处理 Port 下载消息
                if (event.data && event.data.type === 'AUTOLEADAGENT_DOWNLOAD_MESSAGE') {
                    handlePortDownloadMessage(event.data.data);
                    return;
                }
                
                // 只处理来自扩展的消息
                if (event.data && event.data.type && event.data.type.startsWith('HF_')) {
                    handleExtensionMessage(event.data);
                }
            });
        }

        /**
         * 处理扩展消息
         */
        function handleExtensionMessage(message) {
            if (message.type === 'HF_DOWNLOAD_PROGRESS') {
                updateDownloadProgress(message);
            } else if (message.type === 'HF_LOGIN_OK') {
                handleLoginSuccess(message.modelId);
            } else if (message.type === 'HF_LOGIN_OK_FOR_MODEL_INFO') {
                // 登录成功，重新获取模型信息
                handleLoginSuccessForModelInfo(message.modelId);
            } else if (message.type === 'HF_FILE_DOWNLOADED') {
                // 文件下载完成，已通过 handleFileDataChunk 保存到本地文件系统
            }
        }

        /**
         * 处理来自 Port 连接的下载消息
         */
        function handlePortDownloadMessage(message) {
            console.log('[HFModelConfig] 收到 Port 下载消息:', message);
            
            if (!message || !message.type) {
                return;
            }

            switch (message.type) {
                case 'download-started':
                    console.log('[HFModelConfig] 下载已开始:', message.modelId);
                    // 显示下载模态框（如果还没有显示）
                    if (downloadState.currentModelId === message.modelId && !downloadState.isDownloading) {
                        downloadState.isDownloading = true;
                        showDownloadModal(message.modelId);
                    }
                    break;

                case 'download-progress':
                    // 转换为标准格式并更新进度
                    updateDownloadProgress({
                        type: 'HF_DOWNLOAD_PROGRESS',
                        modelId: message.modelId,
                        filename: message.filename,
                        downloaded: message.downloaded,
                        total: message.total,
                        progress: message.progress
                    });
                    break;

                case 'download-complete':
                    console.log('[HFModelConfig] 下载完成:', message.modelId);
                    downloadState.isDownloading = false;
                    
                    // 清理超时
                    if (window.__downloadTimeout) {
                        clearTimeout(window.__downloadTimeout);
                        window.__downloadTimeout = null;
                    }
                    
                    // 保存模型元数据（收集所有已下载的文件信息）
                    var modelId = message.modelId;
                    console.log('[HFModelConfig] 下载完成，开始保存模型元数据:', modelId);
                    
                    // 延迟执行，确保所有文件都已保存（包括大文件）
                    setTimeout(function() {
                        if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.collectModelFiles) {
                            LocalFileStorage.collectModelFiles(modelId).then(function(files) {
                                if (files && files.length > 0) {
                                    console.log('[HFModelConfig] 收集到', files.length, '个文件，元数据已在保存文件时自动更新');
                                    // 验证保存是否成功
                                    return LocalFileStorage.hasModelFiles(modelId).then(function(exists) {
                                        console.log('[HFModelConfig] 验证模型保存状态:', modelId, exists ? '已保存' : '保存失败');
                                        if (!exists) {
                                            console.warn('[HFModelConfig] 模型文件验证失败，可能文件不完整');
                                        }
                                        return Promise.resolve();
                                    });
                                } else {
                                    // 使用重试机制，因为大文件保存可能需要更长时间
                                    var retryCount = 0;
                                    var maxRetries = 5;
                                    var retryDelay = 2000;
                                    
                                    function retryCollect() {
                                        retryCount++;
                                        if (retryCount > maxRetries) {
                                            console.error('[HFModelConfig] 多次重试后仍未找到文件，保存可能失败:', modelId);
                                            console.warn('[HFModelConfig] 提示：如果文件确实已下载，可能需要手动刷新页面');
                                            return;
                                        }
                                        
                                        console.warn('[HFModelConfig] 未找到文件（尝试 ' + retryCount + '/' + maxRetries + '），等待更长时间...');
                                        
                                        setTimeout(function() {
                                            if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.collectModelFiles) {
                                                LocalFileStorage.collectModelFiles(modelId).then(function(files) {
                                                    if (files && files.length > 0) {
                                                        console.log('[HFModelConfig] 延迟后收集到', files.length, '个文件');
                                                        if (searchInput && taskSelect) {
                                                            searchModels();
                                                        }
                                                    } else {
                                                        // 继续重试
                                                        retryCollect();
                                                    }
                                                }).catch(function(error) {
                                                    console.error('[HFModelConfig] 收集文件失败（重试 ' + retryCount + '）:', error);
                                                    if (retryCount < maxRetries) {
                                                        retryCollect();
                                                    }
                                                });
                                            }
                                        }, retryDelay * retryCount); // 每次重试延迟时间递增
                                    }
                                    
                                    // 开始重试
                                    retryCollect();
                                }
                            }).then(function() {
                                // 进行完整性检查
                                return checkModelIntegrity(modelId);
                            }).then(function(integrityResult) {
                                // 显示完整性检查结果
                                if (integrityResult && !integrityResult.complete) {
                                    showIntegrityWarning(modelId, integrityResult);
                                }
                                // 刷新模型列表以显示"已下载"标记
                                if (searchInput && taskSelect) {
                                    searchModels();
                                }
                            }).catch(function(error) {
                                console.error('[HFModelConfig] 收集文件或完整性检查失败:', error);
                            });
                        } else {
                            console.warn('[HFModelConfig] LocalFileStorage 不可用');
                        }
                    }, 2000); // 延迟2秒确保所有文件都已保存（包括大文件）
                    
                    // 延迟隐藏模态框，让用户看到 100%
                    setTimeout(function() {
                        hideDownloadModal();
                    }, 1000);
                    
                    // 触发 resolve
                    if (window.__pendingDownloadResolve) {
                        window.__pendingDownloadResolve({
                            success: true,
                            modelId: message.modelId,
                            downloadedFiles: message.downloadedFiles,
                            totalSize: message.totalSize,
                            downloadedSize: message.downloadedSize
                        });
                        window.__pendingDownloadResolve = null;
                        window.__pendingDownloadReject = null;
                    }
                    
                    safeToast('success', '模型下载完成: ' + modelId);
                    break;

                case 'download-file-data':
                    // 接收文件数据块，保存到本地文件系统
                    handleFileDataChunk(message);
                    break;

                case 'download-file-complete':
                    // 文件下载完成，更新元数据
                    handleFileDownloadComplete(message.modelId, message.filename, message.size);
                    break;

                case 'download-file-error':
                    // 文件保存失败
                    console.error('[HFModelConfig] 文件保存失败:', message.filename, message.error);
                    var errorMsg = message.error || '未知错误';
                    var fileSizeMB = message.size ? (message.size / 1024 / 1024).toFixed(2) : '未知';
                    if (errorMsg.includes('QuotaExceededError') || errorMsg.includes('quota') || errorMsg.includes('存储空间')) {
                        safeToast('error', '文件过大无法保存（' + fileSizeMB + 'MB）。建议：1. 清理浏览器缓存；2. 使用较小的模型；3. 使用 Chrome Built-in AI');
                    } else {
                        safeToast('error', '保存文件失败: ' + errorMsg);
                    }
                    break;

                case 'download-error':
                    console.error('[HFModelConfig] 下载失败:', message.error);
                    downloadState.isDownloading = false;
                    hideDownloadModal();
                    
                    // 清理超时
                    if (window.__downloadTimeout) {
                        clearTimeout(window.__downloadTimeout);
                        window.__downloadTimeout = null;
                    }
                    
                    // 触发 reject
                    if (window.__pendingDownloadReject) {
                        window.__pendingDownloadReject(new Error(message.error || '下载失败'));
                        window.__pendingDownloadReject = null;
                        window.__pendingDownloadResolve = null;
                    }
                    
                    safeToast('error', '模型下载失败: ' + (message.error || '未知错误'));
                    break;

                case 'download-need-login':
                    console.log('[HFModelConfig] 需要登录:', message.modelId);
                    showLoginModal(message.loginTabId, message.modelId);
                    window.__pendingDownloadModelId = message.modelId;
                    // 等待登录成功后再继续（通过 handleLoginSuccess）
                    break;

                case 'download-disconnected':
                    console.warn('[HFModelConfig] Port 连接断开:', message.modelId);
                    if (downloadState.isDownloading && downloadState.currentModelId === message.modelId) {
                        downloadState.isDownloading = false;
                        hideDownloadModal();
                        
                        if (window.__pendingDownloadReject) {
                            window.__pendingDownloadReject(new Error('下载连接断开，请重试'));
                            window.__pendingDownloadReject = null;
                            window.__pendingDownloadResolve = null;
                        }
                    }
                    break;

                default:
                    console.log('[HFModelConfig] 未知的 Port 消息类型:', message.type);
            }
        }

        /**
         * 检测扩展是否可用（异步）
         */
        function checkExtensionAvailable() {
            return new Promise(function (resolve) {
                // 如果扩展已经就绪（收到 AUTOLEADAGENT_READY 消息），直接返回可用
                if (extensionReady) {
                    console.log('[HFModelConfig] 扩展已就绪，跳过 ping 检测');
                    resolve({ available: true, version: extensionVersion || '1.0.0' });
                    return;
                }
                
                if (typeof chrome === 'undefined' || !chrome.runtime) {
                    resolve({ available: false, error: 'Chrome Extension API 不可用' });
                    return;
                }
                
                // 如果扩展未就绪，先等待一小段时间看是否收到 AUTOLEADAGENT_READY 消息
                // 因为 content script 可能在页面加载后才注入
                var resolved = false;
                var readyCheckTimeout = null;
                var pingTimeout = null;
                var readyCheckInterval = null;
                
                function cleanup() {
                    if (readyCheckTimeout) clearTimeout(readyCheckTimeout);
                    if (pingTimeout) clearTimeout(pingTimeout);
                    if (readyCheckInterval) clearInterval(readyCheckInterval);
                }
                
                function doResolve(result) {
                    if (resolved) return;
                    resolved = true;
                    cleanup();
                    resolve(result);
                }
                
                // 监听就绪消息（在等待期间）
                var readyHandler = function (event) {
                    if (event.source !== window) return;
                    if (event.data && event.data.type === 'AUTOLEADAGENT_READY') {
                        extensionReady = true;
                        extensionVersion = event.data.version;
                        console.log('[HFModelConfig] 在等待期间收到扩展就绪消息，版本:', extensionVersion);
                        window.removeEventListener('message', readyHandler);
                        doResolve({ available: true, version: extensionVersion || '1.0.0' });
                    }
                };
                window.addEventListener('message', readyHandler);
                
                // 等待1秒看是否收到就绪消息
                readyCheckTimeout = setTimeout(function () {
                    window.removeEventListener('message', readyHandler);
                    
                    if (resolved) return;
                    
                    // 如果仍未就绪，尝试 ping 扩展
                    console.log('[HFModelConfig] 等待就绪消息超时，尝试 ping 扩展');
                    
                    pingTimeout = setTimeout(function () {
                        if (resolved) return;
                        console.warn('[HFModelConfig] ping 超时，扩展可能不可用');
                        // ping 超时说明扩展可能真的不可用，但为了兼容性，仍然返回可用（带警告）
                        // 实际功能调用时会再次验证
                        doResolve({ 
                            available: true, 
                            version: 'unknown', 
                            warning: 'ping 超时，扩展可能不可用，请检查扩展是否已安装并启用',
                            needsCheck: true
                        });
                    }, 5000); // ping 5秒超时
                    
                    sendMessageToExtension({ action: 'ping' }, function (response) {
                        if (resolved) return;
                        
                        if (pingTimeout) {
                            clearTimeout(pingTimeout);
                            pingTimeout = null;
                        }
                        
                        if (!response) {
                            console.warn('[HFModelConfig] 扩展未响应 ping');
                            doResolve({ 
                                available: true, 
                                version: 'unknown', 
                                warning: '扩展未响应 ping，请检查扩展是否已安装并启用',
                                needsCheck: true
                            });
                            return;
                        }
                        
                        if (response.success) {
                            console.log('[HFModelConfig] 扩展可用，版本:', response.version);
                            doResolve({ available: true, version: response.version });
                        } else {
                            console.warn('[HFModelConfig] 扩展 ping 失败:', response.error);
                            doResolve({ 
                                available: true, 
                                version: 'unknown', 
                                warning: '扩展 ping 失败: ' + (response.error || '未知错误'),
                                needsCheck: true
                            });
                        }
                    });
                }, 1000); // 等待1秒看是否收到就绪消息
            });
        }

        /**
         * 确保模型已下载（前端下载，保存到本地文件系统）
         */
        function ensureModelDownloaded(modelId) {
            return new Promise(function (resolve, reject) {
                if (!modelId) {
                    reject(new Error('模型ID不能为空'));
                    return;
                }

                // 首先检查模型是否已下载
                console.log('[HFModelConfig] 检查模型是否已下载:', modelId);
                checkModelExists(modelId).then(function (exists) {
                    if (exists) {
                        console.log('[HFModelConfig] 模型已存在，跳过下载:', modelId);
                        safeToast('success', '模型已下载，无需重复下载');
                        resolve({ success: true, cached: true, modelId: modelId });
                        return;
                    }

                    console.log('[HFModelConfig] 模型不存在，开始下载到本地文件系统:', modelId);

                    // 显示下载模态框
                    downloadState.isDownloading = true;
                    downloadState.currentModelId = modelId;
                    downloadState.progress = 0;
                    downloadState.downloaded = 0;
                    downloadState.total = 0;
                    downloadState.currentFile = '';
                    downloadState.currentFileProgress = 0;
                    downloadState.currentFileDownloaded = 0;
                    downloadState.currentFileTotal = 0;
                    downloadState.speedHistory = [];
                    downloadState.lastUpdateTime = null;
                    downloadState.lastDownloaded = 0;
                    downloadState.currentSpeed = 0;
                    downloadState.fileSpeedHistory = [];
                    downloadState.fileLastUpdateTime = null;
                    downloadState.fileLastDownloaded = 0;
                    downloadState.currentFileSpeed = 0;
                    window.__lastProgressUpdate = null;
                    showDownloadModal(modelId);

                    // 1. 获取模型文件列表
                    var encodedModelId = modelId.split('/').map(encodeURIComponent).join('/');
                    var modelInfoUrl = 'https://huggingface.co/api/models/' + encodedModelId;
                    
                    console.log('[HFModelConfig] 获取模型信息:', modelInfoUrl);
                    
                    // 先获取模型信息（包含siblings）
                    fetch(modelInfoUrl, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                    .then(function(res) {
                        if (!res.ok) {
                            throw new Error('获取模型信息失败，状态码: ' + res.status);
                        }
                        return res.json();
                    })
                    .then(function(modelInfo) {
                        // 尝试从siblings端点获取文件列表，如果失败则使用modelInfo中的siblings
                        var siblings = modelInfo.siblings || [];
                        
                        // 尝试从siblings端点获取（更详细的信息）
                        var siblingsUrl = 'https://huggingface.co/api/models/' + encodedModelId + '/siblings';
                        return fetch(siblingsUrl, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                        .then(function(res) {
                            if (res.ok) {
                                return res.json().then(function(siblingsData) {
                                    return Array.isArray(siblingsData) ? siblingsData : siblings;
                                });
                            } else {
                                // siblings端点失败，使用modelInfo中的siblings
                                console.warn('[HFModelConfig] siblings端点返回', res.status, '，使用modelInfo中的siblings');
                                return siblings;
                            }
                        })
                        .catch(function(error) {
                            // fetch失败，使用modelInfo中的siblings
                            console.warn('[HFModelConfig] 获取siblings端点失败:', error.message, '，使用modelInfo中的siblings');
                            return siblings;
                        });
                    })
                    .then(function(siblings) {
                        if (!Array.isArray(siblings) || siblings.length === 0) {
                            throw new Error('文件列表为空或格式错误');
                        }

                        // 筛选需要下载的文件
                        var importantFiles = siblings.filter(function(file) {
                            var name = file.rfilename || file.filename || '';
                            if (!name) return false;
                            
                            return name.endsWith('.safetensors') ||
                                   name.endsWith('.bin') ||
                                   name.endsWith('.json') ||
                                   name === 'tokenizer.json' ||
                                   name === 'config.json' ||
                                   name.startsWith('tokenizer_config.json');
                        });

                        if (importantFiles.length === 0) {
                            throw new Error('未找到需要下载的文件');
                        }

                        console.log('[HFModelConfig] 需要下载的文件数量:', importantFiles.length);
                        
                        // 列出所有需要下载的文件
                        var fileList = importantFiles.map(function(f) {
                            var name = f.rfilename || f.filename || '';
                            var isCritical = name.endsWith('.safetensors') || name.endsWith('.bin');
                            return name + (isCritical ? ' [关键]' : '');
                        });
                        console.log('[HFModelConfig] 需要下载的文件列表:', fileList.join(', '));

                        // 计算总大小（如果文件没有size字段，需要从实际下载时获取）
                        var totalSize = 0;
                        var filesWithSize = 0;
                        importantFiles.forEach(function(file) {
                            var fileSize = parseInt(file.size || 0, 10);
                            if (fileSize > 0) {
                                totalSize += fileSize;
                                filesWithSize++;
                            }
                        });
                        
                        // 如果有些文件没有size信息，先设置为0，后续从实际下载中更新
                        if (filesWithSize < importantFiles.length) {
                            console.warn('[HFModelConfig] 部分文件没有大小信息，将在下载时更新总大小');
                        }
                        
                        downloadState.total = totalSize;
                        console.log('[HFModelConfig] 计算的总大小:', totalSize, '字节 (', (totalSize / 1024 / 1024).toFixed(2), 'MB)', '文件数:', importantFiles.length, '有大小信息的:', filesWithSize);

                        // 2. 检查文件是否已存在，然后下载需要的文件
                        var downloadPromises = [];
                        var downloadedCount = 0;
                        var totalDownloaded = 0;
                        var skippedCount = 0;

                        // 判断文件是否为关键文件（模型权重文件）
                        function isCriticalFile(filename) {
                            return filename.endsWith('.safetensors') || filename.endsWith('.bin');
                        }

                        // 检查文件是否已存在（从本地文件系统）
                        function checkFileExists(modelId, filename) {
                            if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.hasModelFile) {
                                return LocalFileStorage.hasModelFile(modelId, filename)
                                    .then(function(exists) {
                                        if (exists) {
                                            // 获取文件大小信息
                                            if (LocalFileStorage.getModelMetadataInfo) {
                                                return LocalFileStorage.getModelMetadataInfo(modelId).then(function(metadata) {
                                            if (metadata && metadata.files) {
                                                var fileMeta = metadata.files.find(function(f) {
                                                    return f.filename === filename;
                                                });
                                                if (fileMeta) {
                                                    console.log('[HFModelConfig] 文件存在检查:', filename, '存在，大小:', (fileMeta.size / 1024 / 1024).toFixed(2), ' MB');
                                                } else {
                                                    console.log('[HFModelConfig] 文件存在检查:', filename, '存在');
                                                }
                                            } else {
                                                console.log('[HFModelConfig] 文件存在检查:', filename, '存在');
                                            }
                                                    return exists;
                                                }).catch(function(err) {
                                                    console.warn('[HFModelConfig] 获取文件元数据失败:', err);
                                                    return exists;
                                                });
                                            } else {
                                                console.log('[HFModelConfig] 文件存在检查:', filename, exists ? '存在' : '不存在');
                                                return exists;
                                            }
                                        } else {
                                            console.log('[HFModelConfig] 文件存在检查:', filename, '不存在');
                                            return false;
                                        }
                                    })
                                    .catch(function(err) {
                                        console.warn('[HFModelConfig] 检查文件是否存在失败:', filename, err);
                                        return false; // 检查失败时假设文件不存在，继续下载
                                    });
                            } else {
                                console.warn('[HFModelConfig] LocalFileStorage 不可用，假设文件不存在');
                                return Promise.resolve(false);
                            }
                        }

                        // 带重试的下载函数
                        function downloadFileWithRetry(fileInfo, maxRetries, skipIfExists) {
                            maxRetries = maxRetries || 3;
                            skipIfExists = skipIfExists !== false; // 默认跳过已存在的文件
                            var attempt = 0;
                            var heartbeatInterval = null; // 心跳日志定时器（提升到外部作用域）
                            
                            // 清理心跳日志的函数
                            function clearHeartbeat() {
                                if (heartbeatInterval) {
                                    clearInterval(heartbeatInterval);
                                    heartbeatInterval = null;
                                }
                            }
                            
                            // 先检查文件是否已存在
                            if (skipIfExists) {
                                return checkFileExists(modelId, fileInfo.filename).then(function(exists) {
                                    if (exists) {
                                        var isCritical = isCriticalFile(fileInfo.filename);
                                        console.log('[HFModelConfig] 文件已存在，跳过下载:', fileInfo.filename, isCritical ? '[关键文件]' : '');
                                        skippedCount++;
                                        // 更新进度（假设文件大小已知）
                                        var fileSize = fileInfo.size && parseInt(fileInfo.size, 10) > 0 ? parseInt(fileInfo.size, 10) : 0;
                                        if (fileSize > 0) {
                                            totalDownloaded += fileSize;
                                            downloadState.downloaded = totalDownloaded;
                                        }
                                        
                                        // 更新进度显示
                                        var progressPercent = downloadState.total > 0 
                                            ? Math.min(100, Math.round((totalDownloaded / downloadState.total) * 100)) 
                                            : 0;
                                        downloadState.progress = progressPercent;
                                        
                                        updateDownloadProgress({
                                            modelId: modelId,
                                            progress: progressPercent,
                                            downloaded: totalDownloaded,
                                            total: downloadState.total,
                                            filename: fileInfo.filename + ' (已存在，跳过)'
                                        });
                                        
                                        return { success: true, data: { success: true, message: '文件已存在' }, filename: fileInfo.filename, isCritical: isCritical, skipped: true };
                                    }
                                    
                                    // 文件不存在，继续下载
                                    console.log('[HFModelConfig] 文件不存在，开始下载:', fileInfo.filename, isCriticalFile(fileInfo.filename) ? '[关键文件]' : '');
                                    return attemptDownload();
                                });
                            }
                            
                            function attemptDownload() {
                                attempt++;
                                var fileUrl = 'https://huggingface.co/' + encodedModelId + '/resolve/main/' + encodeURIComponent(fileInfo.filename);
                                
                                console.log('[HFModelConfig] 开始下载文件 (尝试 ' + attempt + '/' + maxRetries + '):', fileInfo.filename);
                                
                                // 重置单个文件下载状态
                                downloadState.fileSpeedHistory = [];
                                downloadState.fileLastUpdateTime = null;
                                downloadState.fileLastDownloaded = 0;
                                downloadState.currentFileSpeed = 0;
                                
                                // 创建超时Promise（大文件给更长时间，小文件给较短时间）
                                var isLargeFile = isCriticalFile(fileInfo.filename);
                                var timeoutMs = isLargeFile ? 600000 : 60000; // 大文件10分钟，小文件1分钟
                                
                                // 检查文件是否已存在（断点续传）
                                var resumeFrom = 0;
                                var currentFilename = fileInfo.filename; // 保存文件名，避免变量名冲突
                                return checkFileExists(modelId, currentFilename).then(function(exists) {
                                    if (exists) {
                                        // 文件已存在，获取已下载的大小（从本地文件系统）
                                        if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.getModelMetadataInfo) {
                                            return LocalFileStorage.getModelMetadataInfo(modelId).then(function(metadata) {
                                                if (metadata && metadata.files) {
                                                    var fileMeta = metadata.files.find(function(f) {
                                                        return f.filename === currentFilename;
                                                    });
                                                    if (fileMeta && fileMeta.size > 0) {
                                                        resumeFrom = fileMeta.size;
                                                        console.log('[HFModelConfig] 检测到已下载的文件，将从 ' + resumeFrom + ' 字节处继续下载（断点续传）:', currentFilename);
                                                    }
                                                }
                                                return downloadWithResume();
                                            }).catch(function(err) {
                                                console.warn('[HFModelConfig] 获取本地文件大小失败:', err);
                                                return downloadWithResume();
                                            });
                                        } else {
                                            return downloadWithResume();
                                        }
                                    } else {
                                        return downloadWithResume();
                                    }
                                });
                                
                                function downloadWithResume() {
                                    // 构建请求头，如果从断点续传，添加Range头
                                    var headers = {};
                                    if (resumeFrom > 0) {
                                        headers['Range'] = 'bytes=' + resumeFrom + '-';
                                        console.log('[HFModelConfig] 使用Range请求从 ' + resumeFrom + ' 字节处继续下载');
                                    }
                                    
                                    var downloadPromise = fetch(fileUrl, { headers: headers })
                                        .then(function(response) {
                                            // 206 Partial Content 表示支持断点续传
                                            if (response.status === 206) {
                                                console.log('[HFModelConfig] 服务器支持断点续传，从 ' + resumeFrom + ' 字节处继续下载');
                                            } else if (response.status === 200 && resumeFrom > 0) {
                                                // 如果服务器不支持Range请求，返回200，需要重新下载
                                                console.warn('[HFModelConfig] 服务器不支持断点续传，将重新下载整个文件');
                                                resumeFrom = 0; // 重置为从头开始
                                            } else if (!response.ok) {
                                                throw new Error('下载文件失败: ' + fileInfo.filename + ' (HTTP ' + response.status + ')');
                                            }
                                            
                                            // 获取Content-Length（如果可用）
                                            var contentLength = response.headers.get('Content-Length');
                                            var expectedSize = contentLength ? parseInt(contentLength, 10) : 0;
                                            
                                            // 如果是断点续传，实际文件大小 = 已下载大小 + 剩余大小
                                            if (resumeFrom > 0 && expectedSize > 0) {
                                                expectedSize = resumeFrom + expectedSize;
                                                console.log('[HFModelConfig] 断点续传：已下载 ' + resumeFrom + ' 字节，剩余 ' + (expectedSize - resumeFrom) + ' 字节，总大小: ' + expectedSize + ' 字节');
                                            }
                                            
                                            // 初始化单个文件进度
                                            downloadState.currentFile = fileInfo.filename;
                                            downloadState.currentFileTotal = expectedSize;
                                            downloadState.currentFileDownloaded = resumeFrom; // 从已下载的位置开始
                                            downloadState.currentFileProgress = expectedSize > 0 ? (resumeFrom / expectedSize * 100) : 0;
                                        
                                        if (expectedSize > 0) {
                                            console.log('[HFModelConfig] 文件大小:', fileInfo.filename, (expectedSize / 1024 / 1024).toFixed(2), 'MB');
                                        } else {
                                            console.log('[HFModelConfig] 文件大小未知:', fileInfo.filename, '开始下载...');
                                        }
                                        
                                        // 对于大文件，显示提示并添加心跳日志
                                        if (isLargeFile && expectedSize > 50 * 1024 * 1024) {
                                            console.log('[HFModelConfig] 大文件下载中，请耐心等待...', fileInfo.filename, (expectedSize / 1024 / 1024).toFixed(2), 'MB');
                                            updateDownloadProgress({
                                                modelId: modelId,
                                                progress: downloadState.progress,
                                                downloaded: downloadState.downloaded,
                                                total: downloadState.total,
                                                filename: fileInfo.filename + ' (下载中，请等待...)'
                                            });
                                            
                                            // 每30秒输出一次心跳日志
                                            var heartbeatCount = 0;
                                            clearHeartbeat(); // 清除之前的（如果有）
                                            heartbeatInterval = setInterval(function() {
                                                heartbeatCount++;
                                                var elapsedSeconds = heartbeatCount * 30;
                                                console.log('[HFModelConfig] 大文件下载进行中...', fileInfo.filename, '已等待', elapsedSeconds, '秒');
                                                updateDownloadProgress({
                                                    modelId: modelId,
                                                    progress: downloadState.progress,
                                                    downloaded: downloadState.downloaded,
                                                    total: downloadState.total,
                                                    filename: fileInfo.filename + ' (下载中，已等待 ' + elapsedSeconds + ' 秒...)'
                                                });
                                            }, 30000);
                                        }
                                        
                                        // 使用流式下载来避免 ERR_CONTENT_LENGTH_MISMATCH 错误
                                        // 即使 Content-Length 不匹配，也能保存已下载的数据
                                        if (!response.body) {
                                            throw new Error('响应体不可用: ' + fileInfo.filename);
                                        }
                                        
                                        var reader = response.body.getReader();
                                        var chunks = [];
                                        var downloadedBytes = resumeFrom; // 从断点处开始计算
                                        
                                        function readChunk() {
                                            return reader.read().then(function(result) {
                                                if (result.done) {
                                                    // 所有数据读取完成
                                                    clearHeartbeat();
                                                    
                                                    // 合并所有 chunks
                                                    var totalLength = chunks.reduce(function(sum, chunk) {
                                                        return sum + chunk.length;
                                                    }, 0);
                                                    
                                                    var arrayBuffer = new ArrayBuffer(totalLength);
                                                    var uint8Array = new Uint8Array(arrayBuffer);
                                                    var offset = 0;
                                                    
                                                    chunks.forEach(function(chunk) {
                                                        uint8Array.set(chunk, offset);
                                                        offset += chunk.length;
                                                    });
                                                    
                                                    console.log('[HFModelConfig] 文件下载完成，开始处理:', fileInfo.filename, (arrayBuffer.byteLength / 1024 / 1024).toFixed(2), 'MB');
                                                    var actualSize = arrayBuffer.byteLength;
                                                    
                                                    // 检查Content-Length是否匹配（允许一定误差，对于大文件允许更大的误差）
                                                    if (expectedSize > 0) {
                                                        var sizeDiff = Math.abs(actualSize - expectedSize);
                                                        var allowedDiff = Math.max(1024, expectedSize * 0.01); // 允许1%的误差或至少1KB
                                                        
                                                        if (sizeDiff > allowedDiff) {
                                                            console.warn('[HFModelConfig] 文件大小不匹配:', fileInfo.filename, '期望:', expectedSize, '实际:', actualSize, '差异:', sizeDiff);
                                                            // 对于大文件，Content-Length可能不准确，继续处理
                                                            // 但如果差异太大（超过10%），可能是真的下载不完整
                                                            if (sizeDiff > expectedSize * 0.1 && actualSize < expectedSize) {
                                                                throw new Error('文件下载不完整: ' + fileInfo.filename + ' (期望: ' + expectedSize + ', 实际: ' + actualSize + ')');
                                                            }
                                                        }
                                                    }
                                                    
                                                    // 如果文件大小为0，可能是空文件或下载失败
                                                    if (actualSize === 0 && isCriticalFile(fileInfo.filename)) {
                                                        throw new Error('关键文件大小为0: ' + fileInfo.filename);
                                                    }
                                                    
                                                    // 如果总大小为0或当前文件没有大小信息，更新总大小
                                                    if (downloadState.total === 0 || !fileInfo.size || fileInfo.size === 0) {
                                                        // 重新计算总大小：使用已知的文件大小 + 当前文件的实际大小
                                                        var newTotal = 0;
                                                        importantFiles.forEach(function(f) {
                                                            var fname = f.rfilename || f.filename || '';
                                                            if (fname === fileInfo.filename) {
                                                                // 当前文件，使用实际大小
                                                                newTotal += actualSize;
                                                            } else if (f.size && parseInt(f.size, 10) > 0) {
                                                                // 其他文件，使用API提供的大小
                                                                newTotal += parseInt(f.size, 10);
                                                            }
                                                        });
                                                        
                                                        if (newTotal > downloadState.total) {
                                                            downloadState.total = newTotal;
                                                            console.log('[HFModelConfig] 更新总大小:', newTotal, '字节 (', (newTotal / 1024 / 1024).toFixed(2), 'MB)');
                                                        }
                                                    }
                                                    
                                                    // 保存到本地文件系统
                                                    var isResume = resumeFrom > 0;
                                                    console.log('[HFModelConfig] 开始保存文件到本地文件系统:', fileInfo.filename, (actualSize / 1024 / 1024).toFixed(2), 'MB', isResume ? '(断点续传)' : '');
                                                    
                                                    if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.saveModelFile) {
                                                        return LocalFileStorage.saveModelFile(modelId, fileInfo.filename, arrayBuffer)
                                                            .then(function() {
                                                                console.log('[HFModelConfig] 文件已保存到本地文件系统:', fileInfo.filename);
                                                                // 更新元数据
                                                                if (LocalFileStorage.updateModelMetadata) {
                                                                    LocalFileStorage.updateModelMetadata(modelId, fileInfo.filename, actualSize);
                                                                }
                                                                // 返回实际总大小（已下载 + 新下载）
                                                                var totalSize = isResume ? (resumeFrom + actualSize) : actualSize;
                                                                return { data: { success: true, message: '文件已保存到本地' }, size: totalSize };
                                                            })
                                                            .catch(function(error) {
                                                                console.error('[HFModelConfig] 保存文件到本地文件系统失败:', error);
                                                                var errorMsg = error.message || error.toString();
                                                                // 如果是权限相关错误，提供友好的提示
                                                                if (errorMsg.includes('需要先选择') || errorMsg.includes('user gesture')) {
                                                                    throw new Error('需要先选择文件保存位置。请重新点击"保存"按钮，然后在弹出的对话框中选择保存目录。');
                                                                }
                                                                throw new Error('保存文件失败: ' + errorMsg);
                                                            });
                                                    } else {
                                                        throw new Error('LocalFileStorage 不可用，无法保存文件');
                                                    }
                                                } else {
                                                    // 还有更多数据，继续读取
                                                    chunks.push(result.value);
                                                    downloadedBytes += result.value.length;
                                                    
                                                    // 更新总下载进度（只计算新下载的部分）
                                                    totalDownloaded += result.value.length;
                                                    downloadState.downloaded = totalDownloaded;
                                                    
                                                    // 如果总大小还是0，使用已下载的大小作为临时总大小
                                                    if (downloadState.total === 0) {
                                                        downloadState.total = totalDownloaded;
                                                    }
                                                    
                                                    // 计算总进度百分比
                                                    var progressPercent = downloadState.total > 0 
                                                        ? Math.min(100, Math.round((totalDownloaded / downloadState.total) * 100)) 
                                                        : 0;
                                                    downloadState.progress = progressPercent;
                                                    
                                                    // 计算单个文件进度（考虑断点续传）
                                                    var fileProgressPercent = expectedSize > 0 
                                                        ? Math.min(100, Math.round((downloadedBytes / expectedSize) * 100)) 
                                                        : 0;
                                                    
                                                    // 实时更新进度显示（每1MB更新一次，或每500ms更新一次）
                                                    var shouldUpdate = false;
                                                    var now = Date.now();
                                                    if (!window.__lastProgressUpdate) {
                                                        window.__lastProgressUpdate = now;
                                                        shouldUpdate = true;
                                                    } else if (now - window.__lastProgressUpdate > 500) {
                                                        shouldUpdate = true;
                                                        window.__lastProgressUpdate = now;
                                                    } else if (downloadedBytes % (1024 * 1024) < result.value.length) {
                                                        // 每下载1MB更新一次
                                                        shouldUpdate = true;
                                                    }
                                                    
                                                    if (shouldUpdate) {
                                                        updateDownloadProgress({
                                                            modelId: modelId,
                                                            progress: progressPercent,
                                                            downloaded: totalDownloaded,
                                                            total: downloadState.total,
                                                            filename: fileInfo.filename,
                                                            fileProgress: fileProgressPercent,
                                                            fileDownloaded: downloadedBytes,
                                                            fileTotal: expectedSize
                                                        });
                                                    }
                                                    
                                                    // 对于大文件，定期输出进度日志
                                                    if (isLargeFile && expectedSize > 0 && downloadedBytes % (10 * 1024 * 1024) < result.value.length) {
                                                        console.log('[HFModelConfig] 下载进度:', fileInfo.filename, fileProgressPercent + '%', '(' + (downloadedBytes / 1024 / 1024).toFixed(2) + 'MB / ' + (expectedSize / 1024 / 1024).toFixed(2) + 'MB)');
                                                    }
                                                    
                                                    return readChunk();
                                                }
                                            }).catch(function(error) {
                                                clearHeartbeat();
                                                // 如果已经下载了部分数据，尝试保存
                                                if (chunks.length > 0 && downloadedBytes > 0) {
                                                    console.warn('[HFModelConfig] 下载过程中出错，但已下载部分数据:', fileInfo.filename, downloadedBytes, '字节', error.message);
                                                    console.warn('[HFModelConfig] 尝试保存已下载的数据...');
                                                    
                                                    // 合并已下载的 chunks
                                                    var totalLength = chunks.reduce(function(sum, chunk) {
                                                        return sum + chunk.length;
                                                    }, 0);
                                                    
                                                    var arrayBuffer = new ArrayBuffer(totalLength);
                                                    var uint8Array = new Uint8Array(arrayBuffer);
                                                    var offset = 0;
                                                    
                                                    chunks.forEach(function(chunk) {
                                                        uint8Array.set(chunk, offset);
                                                        offset += chunk.length;
                                                    });
                                                    
                                                    // 尝试保存部分数据到本地文件系统（但会标记为不完整）
                                                    if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.saveModelFile) {
                                                        return LocalFileStorage.saveModelFile(modelId, fileInfo.filename, arrayBuffer)
                                                            .then(function() {
                                                                var totalDownloadedBytes = resumeFrom > 0 ? (resumeFrom + totalLength) : totalLength;
                                                                console.warn('[HFModelConfig] 部分数据已保存到本地，但下载不完整:', fileInfo.filename, '已下载:', totalDownloadedBytes, '字节 (断点续传: ' + resumeFrom + ' + 新下载: ' + totalLength + ')');
                                                                // 更新元数据
                                                                if (LocalFileStorage.updateModelMetadata) {
                                                                    LocalFileStorage.updateModelMetadata(modelId, fileInfo.filename, totalDownloadedBytes);
                                                                }
                                                                throw new Error('文件下载不完整: ' + fileInfo.filename + ' (已下载: ' + totalDownloadedBytes + ' 字节，期望: ' + expectedSize + ' 字节)');
                                                            })
                                                            .catch(function(saveError) {
                                                                console.error('[HFModelConfig] 保存部分数据失败:', saveError);
                                                                throw error; // 保存失败，抛出原始错误
                                                            });
                                                    } else {
                                                        throw error; // LocalFileStorage 不可用，抛出原始错误
                                                    }
                                                } else {
                                                    throw error; // 没有下载任何数据，直接抛出错误
                                                }
                                            });
                                        }
                                        
                                        // 开始读取数据流
                                        return readChunk();
                                    });
                                    
                                    // 创建超时Promise
                                    var timeoutPromise = new Promise(function(resolve, reject) {
                                        var timeoutId = setTimeout(function() {
                                            clearHeartbeat(); // 超时时清除心跳
                                            reject(new Error('下载超时: ' + fileInfo.filename + ' (超过 ' + (timeoutMs / 1000) + ' 秒)'));
                                        }, timeoutMs);
                                        // 返回一个 Promise，当下载完成时清除超时
                                        downloadPromise.finally(function() {
                                            clearTimeout(timeoutId);
                                        });
                                    });
                                    
                                    // 使用 Promise.race 实现超时
                                    return Promise.race([downloadPromise, timeoutPromise]);
                                }
                                
                                // 返回下载Promise并处理结果
                                return downloadWithResume()
                                    .then(function(result) {
                                        var data = result.data;
                                        var actualSize = result.size;
                                        
                                        if (!data || !data.success) {
                                            throw new Error('保存文件失败: ' + fileInfo.filename + ' - ' + (data && data.message || '未知错误'));
                                        }
                                        
                                        downloadedCount++;
                                        totalDownloaded += actualSize;
                                        downloadState.downloaded = totalDownloaded;
                                        
                                        // 如果总大小还是0，使用已下载的大小作为临时总大小
                                        if (downloadState.total === 0) {
                                            downloadState.total = totalDownloaded;
                                        }
                                        
                                        // 计算进度百分比
                                        var progressPercent = downloadState.total > 0 
                                            ? Math.min(100, Math.round((totalDownloaded / downloadState.total) * 100)) 
                                            : 0;
                                        downloadState.progress = progressPercent;
                                        
                                        // 更新进度显示（文件下载完成）
                                        updateDownloadProgress({
                                            modelId: modelId,
                                            progress: progressPercent,
                                            downloaded: totalDownloaded,
                                            total: downloadState.total,
                                            filename: fileInfo.filename,
                                            fileProgress: 100,
                                            fileDownloaded: actualSize,
                                            fileTotal: actualSize
                                        });
                                        
                                        console.log('[HFModelConfig] 文件下载并保存成功:', fileInfo.filename, 
                                            '(' + downloadedCount + '/' + importantFiles.length + ')', 
                                            '大小:', (actualSize / 1024 / 1024).toFixed(2), 'MB',
                                            '总进度:', progressPercent + '%', 
                                            '(' + (totalDownloaded / 1024 / 1024).toFixed(2) + 'MB / ' + (downloadState.total / 1024 / 1024).toFixed(2) + 'MB)');
                                        
                                        return { success: true, data: data, filename: fileInfo.filename, isCritical: isCriticalFile(fileInfo.filename) };
                                    })
                                    .catch(function(error) {
                                        // 清除心跳日志
                                        clearHeartbeat();
                                        
                                        // 如果是关键文件且还有重试次数，则重试
                                        if (isCriticalFile(fileInfo.filename) && attempt < maxRetries) {
                                            console.warn('[HFModelConfig] 关键文件下载失败，重试中 (' + attempt + '/' + maxRetries + '):', fileInfo.filename, error.message);
                                            return new Promise(function(resolve) {
                                                setTimeout(function() {
                                                    resolve(attemptDownload());
                                                }, 2000 * attempt); // 递增延迟
                                            });
                                        }
                                        
                                        console.error('[HFModelConfig] 文件下载或保存失败:', fileInfo.filename, error);
                                        return { success: false, error: error, filename: fileInfo.filename, isCritical: isCriticalFile(fileInfo.filename) };
                                    });
                            }
                            
                            return attemptDownload();
                        }

                        importantFiles.forEach(function(file) {
                            var filename = file.rfilename || file.filename || '';
                            if (!filename) return;

                            var downloadPromise = downloadFileWithRetry({ filename: filename, size: file.size || 0 }, 3, true);
                            downloadPromises.push(downloadPromise);
                        });

                        // 使用 allSettled 允许部分文件失败
                        return Promise.allSettled(downloadPromises);
                    })
                    .then(function(results) {
                        // 处理 allSettled 的结果
                        var successfulFiles = [];
                        var failedFiles = [];
                        var criticalFilesFailed = [];
                        var criticalFilesSuccess = [];
                        
                        var skippedFiles = [];
                        var skippedCriticalFiles = [];
                        results.forEach(function(result, index) {
                            if (result.status === 'fulfilled') {
                                var fileResult = result.value;
                                if (fileResult && fileResult.success) {
                                    if (fileResult.skipped) {
                                        skippedFiles.push(fileResult.filename);
                                        if (fileResult.isCritical) {
                                            skippedCriticalFiles.push(fileResult.filename);
                                            criticalFilesSuccess.push(fileResult.filename); // 跳过的关键文件也算成功
                                        }
                                    } else {
                                        successfulFiles.push(fileResult);
                                        if (fileResult.isCritical) {
                                            criticalFilesSuccess.push(fileResult.filename);
                                        }
                                    }
                                } else {
                                    failedFiles.push({ filename: fileResult ? fileResult.filename : '未知', error: fileResult ? (fileResult.error && fileResult.error.message ? fileResult.error.message : fileResult.error) : '未知错误' });
                                    if (fileResult && fileResult.isCritical) {
                                        criticalFilesFailed.push(fileResult.filename);
                                    }
                                }
                            } else {
                                // rejected 状态
                                var filename = importantFiles[index] ? (importantFiles[index].rfilename || importantFiles[index].filename || '未知') : '未知';
                                var errorMsg = result.reason ? (result.reason.message || result.reason.toString() || '未知错误') : '未知错误';
                                failedFiles.push({ filename: filename, error: errorMsg });
                                if (isCriticalFile(filename)) {
                                    criticalFilesFailed.push(filename);
                                }
                            }
                        });
                        
                        var successCount = successfulFiles.length;
                        var failCount = failedFiles.length;
                        var totalCount = results.length;
                        
                        console.log('[HFModelConfig] 文件下载完成 - 成功:', successCount, '跳过:', skippedFiles.length, '失败:', failCount, '总计:', totalCount);
                        console.log('[HFModelConfig] 关键文件状态 - 成功/跳过:', criticalFilesSuccess.length, '失败:', criticalFilesFailed.length);
                        
                        if (skippedFiles.length > 0) {
                            console.log('[HFModelConfig] 跳过的文件:', skippedFiles.join(', '));
                            if (skippedCriticalFiles.length > 0) {
                                console.log('[HFModelConfig] 跳过的关键文件:', skippedCriticalFiles.join(', '));
                            }
                        }
                        
                        if (successCount > 0) {
                            console.log('[HFModelConfig] 成功下载的文件:', successfulFiles.map(function(f) { return f.filename; }).join(', '));
                        }
                        
                        if (failCount > 0) {
                            console.warn('[HFModelConfig] 部分文件下载失败:', failedFiles.map(function(f) { return f.filename + ' (' + f.error + ')'; }).join(', '));
                        }
                        
                        // 检查关键文件是否都成功（包括跳过的）
                        if (criticalFilesFailed.length > 0) {
                            console.error('[HFModelConfig] 关键文件下载失败:', criticalFilesFailed.join(', '));
                            downloadState.isDownloading = false;
                            hideDownloadModal();
                            var errorMsg = '关键文件下载失败: ' + criticalFilesFailed.join(', ') + '。请重试。';
                            safeToast('error', errorMsg);
                            reject(new Error(errorMsg));
                            return;
                        }
                        
                        // 检查是否有任何关键文件（成功或跳过）
                        if (criticalFilesSuccess.length === 0) {
                            console.error('[HFModelConfig] 没有找到任何关键文件（.safetensors 或 .bin）');
                            downloadState.isDownloading = false;
                            hideDownloadModal();
                            var errorMsg = '未找到模型权重文件（.safetensors 或 .bin），无法完成下载。';
                            safeToast('error', errorMsg);
                            reject(new Error(errorMsg));
                            return;
                        }
                        
                        // 如果关键文件都成功（包括跳过的），即使其他文件失败也继续
                        if (criticalFilesSuccess.length > 0 || successCount > 0) {
                            downloadState.isDownloading = false;
                            hideDownloadModal();
                            
                            if (failCount > 0) {
                                // 显示失败文件列表并提供重新下载选项
                                var failedFileNames = failedFiles.map(function(f) { return f.filename; });
                                var message = '模型下载完成，但有 ' + failCount + ' 个文件下载失败：\n' + failedFileNames.join('\n');
                                
                                // 使用 confirm 询问用户是否要重新下载
                                console.warn('[HFModelConfig]', message);
                                safeToast('warning', '有 ' + failCount + ' 个文件下载失败，请查看控制台了解详情');
                                
                                // 延迟显示确认对话框，让用户先看到提示
                                setTimeout(function() {
                                    if (confirm('有 ' + failCount + ' 个文件下载失败：\n\n' + failedFileNames.slice(0, 5).join('\n') + (failedFileNames.length > 5 ? '\n... 等共 ' + failCount + ' 个文件' : '') + '\n\n是否要重新下载这些失败的文件？')) {
                                        // 创建重新下载失败文件的函数
                                        console.log('[HFModelConfig] 开始重新下载失败的文件:', failedFileNames.join(', '));
                                        
                                        // 显示下载模态框
                                        showDownloadModal(modelId);
                                        downloadState.isDownloading = true;
                                        downloadState.downloaded = 0;
                                        downloadState.total = 0;
                                        downloadState.progress = 0;
                                        
                                        var failedFileInfos = failedFiles.map(function(f) {
                                            var originalFile = importantFiles.find(function(file) {
                                                return (file.rfilename || file.filename) === f.filename;
                                            });
                                            return {
                                                filename: f.filename,
                                                size: originalFile ? (originalFile.size || 0) : 0
                                            };
                                        });
                                        
                                        // 重新下载失败的文件（不跳过已存在的文件，强制重新下载）
                                        var retryPromises = failedFileInfos.map(function(fileInfo) {
                                            return downloadFileWithRetry(fileInfo, 3, false); // false = 不跳过已存在的文件
                                        });
                                        
                                        Promise.allSettled(retryPromises).then(function(retryResults) {
                                            var retrySuccess = 0;
                                            var retryFailed = 0;
                                            var retryFailedList = [];
                                            
                                            retryResults.forEach(function(result, index) {
                                                if (result.status === 'fulfilled' && result.value && result.value.success) {
                                                    retrySuccess++;
                                                } else {
                                                    retryFailed++;
                                                    retryFailedList.push(failedFileNames[index]);
                                                }
                                            });
                                            
                                            downloadState.isDownloading = false;
                                            hideDownloadModal();
                                            
                                            if (retryFailed > 0) {
                                                safeToast('warning', '重新下载完成：成功 ' + retrySuccess + ' 个，失败 ' + retryFailed + ' 个');
                                                console.warn('[HFModelConfig] 重新下载后仍失败的文件:', retryFailedList.join(', '));
                                            } else {
                                                safeToast('success', '所有失败文件已重新下载成功');
                                            }
                                            
                                            // 重新检查模型缓存状态
                                            setTimeout(function() {
                                                checkModelExists(modelId).then(function(exists) {
                                                    if (exists && selectedModelId === modelId && detailStatus) {
                                                        detailStatus.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check-circle me-1"></i>已下载</span>';
                                                    }
                                                });
                                            }, 2000);
                                        }).catch(function(err) {
                                            downloadState.isDownloading = false;
                                            hideDownloadModal();
                                            console.error('[HFModelConfig] 重新下载失败文件时出错:', err);
                                            safeToast('error', '重新下载失败：' + (err.message || '未知错误'));
                                        });
                                    }
                                }, 1000);
                            } else {
                                safeToast('success', '模型下载成功' + (skippedFiles.length > 0 ? '（已跳过 ' + skippedFiles.length + ' 个已存在的文件）' : ''));
                            }
                            
                            // 等待一小段时间让文件系统同步，然后重新检查模型缓存状态
                            console.log('[HFModelConfig] 等待文件系统同步，然后验证模型缓存状态...');
                            
                            // 立即更新状态为"检查中"
                            if (selectedModelId === modelId && detailStatus) {
                                detailStatus.innerHTML = '<span class="badge bg-info"><i class="mdi mdi-loading mdi-spin me-1"></i>验证中...</span>';
                            }
                            
                            // 多次尝试检查，因为文件系统可能需要时间同步
                            var checkAttempts = 0;
                            var maxCheckAttempts = 5;
                            
                            function checkModelCacheStatus() {
                                checkAttempts++;
                                console.log('[HFModelConfig] 验证模型缓存状态 (尝试 ' + checkAttempts + '/' + maxCheckAttempts + ')...');
                                
                                checkModelExists(modelId).then(function(exists) {
                                    if (exists) {
                                        console.log('[HFModelConfig] 模型缓存验证成功');
                                        
                                        // 如果当前显示的模型详情就是刚下载的模型，更新显示状态
                                        if (selectedModelId === modelId && detailStatus) {
                                            detailStatus.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check-circle me-1"></i>已下载</span>';
                                        }
                                        
                                        // 刷新模型列表以更新状态
                                        if (searchInput && taskSelect) {
                                            console.log('[HFModelConfig] 刷新模型列表以更新下载状态');
                                            searchModels();
                                        }
                                        
                                        resolve({ 
                                            success: true, 
                                            modelId: modelId,
                                            fileCount: successCount,
                                            totalCount: totalCount,
                                            failedCount: failCount,
                                            cached: true
                                        });
                                    } else {
                                        // 如果还没达到最大尝试次数，继续等待并重试
                                        if (checkAttempts < maxCheckAttempts) {
                                            console.warn('[HFModelConfig] 模型缓存验证失败 (尝试 ' + checkAttempts + '/' + maxCheckAttempts + ')，等待后重试...');
                                            setTimeout(checkModelCacheStatus, 2000 * checkAttempts); // 递增延迟
                                            return;
                                        }
                                        
                                        console.warn('[HFModelConfig] 模型缓存验证失败（已尝试 ' + maxCheckAttempts + ' 次），但文件已保存');
                                        
                                        // 即使验证失败，也更新状态为"部分下载"或"验证中"
                                        if (selectedModelId === modelId && detailStatus) {
                                            if (failCount > 0) {
                                                detailStatus.innerHTML = '<span class="badge bg-warning"><i class="mdi mdi-alert-circle me-1"></i>部分下载（' + failCount + ' 个文件失败）</span>';
                                            } else {
                                                detailStatus.innerHTML = '<span class="badge bg-secondary"><i class="mdi mdi-help-circle me-1"></i>验证中...</span>';
                                            }
                                        }
                                        
                                        // 刷新模型列表
                                        if (searchInput && taskSelect) {
                                            searchModels();
                                        }
                                        
                                        // 即使验证失败，也继续执行，因为文件已经保存了
                                        resolve({ 
                                            success: true, 
                                            modelId: modelId,
                                            fileCount: successCount,
                                            totalCount: totalCount,
                                            failedCount: failCount,
                                            cached: false
                                        });
                                    }
                                }).catch(function(err) {
                                    console.error('[HFModelConfig] 验证模型缓存时出错:', err);
                                    
                                    // 如果还没达到最大尝试次数，继续等待并重试
                                    if (checkAttempts < maxCheckAttempts) {
                                        console.warn('[HFModelConfig] 验证出错，等待后重试 (尝试 ' + checkAttempts + '/' + maxCheckAttempts + ')...');
                                        setTimeout(checkModelCacheStatus, 2000 * checkAttempts);
                                        return;
                                    }
                                    
                                    // 验证出错也继续执行
                                    if (selectedModelId === modelId && detailStatus) {
                                        detailStatus.innerHTML = '<span class="badge bg-secondary"><i class="mdi mdi-help-circle me-1"></i>状态未知</span>';
                                    }
                                    
                                    resolve({ 
                                        success: true, 
                                        modelId: modelId,
                                        fileCount: successCount,
                                        totalCount: totalCount,
                                        failedCount: failCount,
                                        cached: false
                                    });
                                });
                            }
                            
                            // 开始第一次检查（延迟2秒）
                            setTimeout(checkModelCacheStatus, 2000);
                        } else {
                            // 所有文件都失败
                            downloadState.isDownloading = false;
                            hideDownloadModal();
                            var errorMsg = '所有文件下载失败';
                            safeToast('error', errorMsg);
                            reject(new Error(errorMsg));
                        }
                    })
                    .catch(function(error) {
                        downloadState.isDownloading = false;
                        hideDownloadModal();
                        console.error('[HFModelConfig] 模型下载失败:', error);
                        var errorMsg = error.message || '模型下载失败';
                        safeToast('error', errorMsg);
                        reject(new Error(errorMsg));
                    });
                }).catch(function (err) {
                    console.error('[HFModelConfig] 检查模型是否存在失败:', err);
                    reject(new Error('检查模型状态失败: ' + err.message));
                });
            });
        }

        // 导出 ensureModelDownloaded 到全局，供其他模块使用
        if (typeof window !== 'undefined') {
            window.ensureModelDownloaded = ensureModelDownloaded;
        }

        /**
         * 处理登录成功
         */
        function handleLoginSuccess(modelId) {
            console.log('[HFModelConfig] 登录成功，继续下载模型:', modelId);
            hideLoginModal();

            if (window.__pendingDownloadModelId === modelId) {
                // 再次检查模型是否已下载（可能在登录期间已下载）
                checkModelExists(modelId).then(function (exists) {
                    if (exists) {
                        console.log('[HFModelConfig] 登录后检查发现模型已存在，跳过下载:', modelId);
                        safeToast('success', '模型已下载，无需重复下载');
                        if (window.__pendingDownloadResolve) {
                            window.__pendingDownloadResolve({ success: true, cached: true, modelId: modelId });
                        }
                        // 清理
                        window.__pendingDownloadModelId = null;
                        window.__pendingDownloadResolve = null;
                        window.__pendingDownloadReject = null;
                        return;
                    }

                    // 重新开始下载
                    downloadState.isDownloading = true;
                    downloadState.currentModelId = modelId;
                    showDownloadModal(modelId);

                var message = {
                    type: 'HF_DOWNLOAD_MODEL',
                    modelId: modelId
                };
                console.log('[HFModelConfig] 登录成功后重新发送下载消息:', message);

                sendMessageToExtension(message, function (response) {
                    console.log('[HFModelConfig] 收到扩展响应:', response);
                    
                    if (!response) {
                        downloadState.isDownloading = false;
                        hideDownloadModal();
                        if (window.__pendingDownloadReject) {
                            window.__pendingDownloadReject(new Error('扩展未响应'));
                        }
                        return;
                    }

                    if (response.success) {
                        downloadState.isDownloading = false;
                        hideDownloadModal();
                        if (window.__pendingDownloadResolve) {
                            window.__pendingDownloadResolve(response);
                        }
                    } else {
                        downloadState.isDownloading = false;
                        hideDownloadModal();
                        if (window.__pendingDownloadReject) {
                            window.__pendingDownloadReject(new Error(response.error || '下载失败'));
                        }
                    }

                    // 清理
                    window.__pendingDownloadModelId = null;
                    window.__pendingDownloadResolve = null;
                    window.__pendingDownloadReject = null;
                });
                }).catch(function (err) {
                    console.error('[HFModelConfig] 检查模型是否存在失败:', err);
                    // 如果检查失败，继续下载
                    downloadState.isDownloading = true;
                    downloadState.currentModelId = modelId;
                    showDownloadModal(modelId);
                    
                    var message = {
                        type: 'HF_DOWNLOAD_MODEL',
                        modelId: modelId
                    };
                    console.log('[HFModelConfig] 登录成功后重新发送下载消息:', message);

                    sendMessageToExtension(message, function (response) {
                        console.log('[HFModelConfig] 收到扩展响应:', response);
                        
                        if (!response) {
                            downloadState.isDownloading = false;
                            hideDownloadModal();
                            if (window.__pendingDownloadReject) {
                                window.__pendingDownloadReject(new Error('扩展未响应'));
                            }
                            return;
                        }

                        if (response.success) {
                            downloadState.isDownloading = false;
                            hideDownloadModal();
                            if (window.__pendingDownloadResolve) {
                                window.__pendingDownloadResolve(response);
                            }
                        } else {
                            downloadState.isDownloading = false;
                            hideDownloadModal();
                            if (window.__pendingDownloadReject) {
                                window.__pendingDownloadReject(new Error(response.error || '下载失败'));
                            }
                        }

                        // 清理
                        window.__pendingDownloadModelId = null;
                        window.__pendingDownloadResolve = null;
                        window.__pendingDownloadReject = null;
                    });
                });
            }
        }

        /**
         * 计算下载速度
         */
        function calculateSpeed(currentTime, currentDownloaded, lastTime, lastDownloaded) {
            if (!lastTime || currentTime <= lastTime) {
                return 0;
            }
            var timeDiff = (currentTime - lastTime) / 1000; // 秒
            var downloadedDiff = currentDownloaded - lastDownloaded; // 字节
            if (timeDiff <= 0 || downloadedDiff <= 0) {
                return 0;
            }
            var speedMBps = (downloadedDiff / 1024 / 1024) / timeDiff; // MB/s
            return speedMBps;
        }

        /**
         * 格式化剩余时间
         */
        function formatETA(remainingBytes, speedMBps) {
            if (!speedMBps || speedMBps <= 0) {
                return '--';
            }
            var remainingMB = remainingBytes / 1024 / 1024;
            var seconds = remainingMB / speedMBps;
            if (seconds < 60) {
                return Math.ceil(seconds) + '秒';
            } else if (seconds < 3600) {
                var minutes = Math.floor(seconds / 60);
                var secs = Math.ceil(seconds % 60);
                return minutes + '分' + secs + '秒';
            } else {
                var hours = Math.floor(seconds / 3600);
                var minutes = Math.floor((seconds % 3600) / 60);
                return hours + '小时' + minutes + '分钟';
            }
        }

        /**
         * 更新下载进度
         */
        function updateDownloadProgress(message) {
            if (message.modelId !== downloadState.currentModelId) {
                return;
            }

            // 检查是否是错误（progress === -1）
            if (message.progress === -1 || message.error) {
                console.error('[HFModelConfig] 下载失败:', message.error || '未知错误');
                downloadState.isDownloading = false;
                hideDownloadModal();
                
                // 清理超时
                if (window.__downloadTimeout) {
                    clearTimeout(window.__downloadTimeout);
                    window.__downloadTimeout = null;
                }
                
                // 如果有待处理的 reject，调用它
                if (window.__pendingDownloadReject) {
                    window.__pendingDownloadReject(new Error(message.error || '下载失败'));
                    window.__pendingDownloadReject = null;
                }
                
                safeToast('error', '模型下载失败: ' + (message.error || '未知错误'));
                return;
            }

            var currentTime = Date.now();
            var currentDownloaded = message.downloaded || 0;
            var currentTotal = message.total || 0;
            var currentProgress = message.progress || 0;

            // 计算总下载速度
            if (downloadState.lastUpdateTime && downloadState.lastDownloaded !== undefined) {
                var speed = calculateSpeed(currentTime, currentDownloaded, downloadState.lastUpdateTime, downloadState.lastDownloaded);
                if (speed > 0) {
                    downloadState.speedHistory.push({ time: currentTime, speed: speed });
                    // 只保留最近10秒的数据
                    downloadState.speedHistory = downloadState.speedHistory.filter(function(item) {
                        return currentTime - item.time < 10000;
                    });
                    // 计算平均速度
                    if (downloadState.speedHistory.length > 0) {
                        var totalSpeed = downloadState.speedHistory.reduce(function(sum, item) { return sum + item.speed; }, 0);
                        downloadState.currentSpeed = totalSpeed / downloadState.speedHistory.length;
                    }
                }
            }
            downloadState.lastUpdateTime = currentTime;
            downloadState.lastDownloaded = currentDownloaded;

            downloadState.progress = currentProgress;
            downloadState.downloaded = currentDownloaded;
            downloadState.total = currentTotal;
            downloadState.currentFile = message.filename || '';

            // 更新单个文件进度（如果提供了）
            if (message.fileProgress !== undefined) {
                downloadState.currentFileProgress = message.fileProgress;
            }
            if (message.fileDownloaded !== undefined) {
                var fileDownloaded = message.fileDownloaded || 0;
                var fileTotal = message.fileTotal || 0;
                
                // 计算单个文件下载速度
                if (downloadState.fileLastUpdateTime && downloadState.fileLastDownloaded !== undefined) {
                    var fileSpeed = calculateSpeed(currentTime, fileDownloaded, downloadState.fileLastUpdateTime, downloadState.fileLastDownloaded);
                    if (fileSpeed > 0) {
                        downloadState.fileSpeedHistory.push({ time: currentTime, speed: fileSpeed });
                        // 只保留最近5秒的数据
                        downloadState.fileSpeedHistory = downloadState.fileSpeedHistory.filter(function(item) {
                            return currentTime - item.time < 5000;
                        });
                        // 计算平均速度
                        if (downloadState.fileSpeedHistory.length > 0) {
                            var totalFileSpeed = downloadState.fileSpeedHistory.reduce(function(sum, item) { return sum + item.speed; }, 0);
                            downloadState.currentFileSpeed = totalFileSpeed / downloadState.fileSpeedHistory.length;
                        }
                    }
                }
                downloadState.fileLastUpdateTime = currentTime;
                downloadState.fileLastDownloaded = fileDownloaded;
                
                downloadState.currentFileDownloaded = fileDownloaded;
                downloadState.currentFileTotal = fileTotal;
                if (fileTotal > 0) {
                    downloadState.currentFileProgress = Math.min(100, Math.max(0, (fileDownloaded / fileTotal) * 100));
                }
            }

            // 更新总进度条
            var progressBar = document.getElementById('hf-download-progress-bar');
            var progressText = document.getElementById('hf-download-progress-text');
            var speedText = document.getElementById('hf-download-speed');

            if (progressBar) {
                var progressPercent = Math.min(100, Math.max(0, downloadState.progress));
                progressBar.style.width = progressPercent + '%';
                progressBar.setAttribute('aria-valuenow', progressPercent);
                progressBar.textContent = progressPercent.toFixed(1) + '%';
            }

            if (progressText) {
                var downloadedMB = downloadState.downloaded > 0 ? (downloadState.downloaded / 1024 / 1024).toFixed(2) : '0.00';
                var totalMB = downloadState.total > 0 ? (downloadState.total / 1024 / 1024).toFixed(2) : '0.00';
                var progressPercent = downloadState.total > 0 ? downloadState.progress.toFixed(1) : '0.0';
                progressText.textContent = downloadedMB + ' MB / ' + totalMB + ' MB (' + progressPercent + '%)';
            }

            if (speedText) {
                if (downloadState.currentSpeed > 0) {
                    var speedStr = downloadState.currentSpeed >= 1 
                        ? downloadState.currentSpeed.toFixed(2) + ' MB/s'
                        : (downloadState.currentSpeed * 1024).toFixed(2) + ' KB/s';
                    speedText.textContent = '速度: ' + speedStr;
                } else {
                    speedText.textContent = '速度: 计算中...';
                }
            }

            // 更新单个文件进度
            var progressFile = document.getElementById('hf-download-progress-file');
            var fileProgressBar = document.getElementById('hf-download-file-progress-bar');
            var fileProgressText = document.getElementById('hf-download-file-progress-text');
            var fileSpeedText = document.getElementById('hf-download-file-speed');
            var fileEtaText = document.getElementById('hf-download-file-eta');

            if (progressFile) {
                progressFile.textContent = downloadState.currentFile || '准备下载...';
            }

            if (fileProgressBar) {
                var fileProgressPercent = Math.min(100, Math.max(0, downloadState.currentFileProgress));
                fileProgressBar.style.width = fileProgressPercent + '%';
                fileProgressBar.setAttribute('aria-valuenow', fileProgressPercent);
                fileProgressBar.textContent = fileProgressPercent.toFixed(1) + '%';
            }

            if (fileProgressText) {
                var fileDownloadedMB = downloadState.currentFileDownloaded > 0 ? (downloadState.currentFileDownloaded / 1024 / 1024).toFixed(2) : '0.00';
                var fileTotalMB = downloadState.currentFileTotal > 0 ? (downloadState.currentFileTotal / 1024 / 1024).toFixed(2) : '0.00';
                fileProgressText.textContent = fileDownloadedMB + ' MB / ' + fileTotalMB + ' MB';
            }

            if (fileSpeedText) {
                if (downloadState.currentFileSpeed > 0) {
                    var fileSpeedStr = downloadState.currentFileSpeed >= 1 
                        ? downloadState.currentFileSpeed.toFixed(2) + ' MB/s'
                        : (downloadState.currentFileSpeed * 1024).toFixed(2) + ' KB/s';
                    fileSpeedText.textContent = '速度: ' + fileSpeedStr;
                } else {
                    fileSpeedText.textContent = '速度: --';
                }
            }

            if (fileEtaText) {
                if (downloadState.currentFileSpeed > 0 && downloadState.currentFileTotal > downloadState.currentFileDownloaded) {
                    var remainingBytes = downloadState.currentFileTotal - downloadState.currentFileDownloaded;
                    var eta = formatETA(remainingBytes, downloadState.currentFileSpeed);
                    fileEtaText.textContent = '剩余时间: ' + eta;
                } else {
                    fileEtaText.textContent = '剩余时间: --';
                }
            }
            
            // 如果进度达到 100%，下载完成
            if (downloadState.progress >= 100 && downloadState.isDownloading) {
                console.log('[HFModelConfig] 模型下载完成（进度 100%）');
                downloadState.isDownloading = false;
                
                // 清理超时
                if (window.__downloadTimeout) {
                    clearTimeout(window.__downloadTimeout);
                    window.__downloadTimeout = null;
                }
                
                // 延迟一下再隐藏模态框，让用户看到 100%
                setTimeout(function() {
                    hideDownloadModal();
                    
                    // 刷新模型列表以显示"已下载"标记
                    if (searchInput && taskSelect) {
                        searchModels();
                    }
                    
                    // 如果有待处理的 resolve，调用它
                    if (window.__pendingDownloadResolve) {
                        window.__pendingDownloadResolve({
                            success: true,
                            modelId: message.modelId,
                            downloaded: message.downloaded,
                            total: message.total
                        });
                        window.__pendingDownloadResolve = null;
                    }
                }, 1000);
            }
        }

        /**
         * 为列表中的模型加载大小信息（不显示详情）
         * @param {string} modelId 模型ID
         * @param {HTMLElement} tdSize 大小单元格元素
         */
        function loadModelInfoForSize(modelId, tdSize) {
            if (!modelId || !tdSize) return;
            
            // 检查扩展是否可用
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                tdSize.textContent = '未知';
                tdSize.removeAttribute('data-calculating');
                return;
            }

            // 通过扩展获取模型信息
            var message = {
                type: 'HF_GET_MODEL_INFO',
                modelId: modelId
            };

            sendMessageToExtension(message, function (response) {
                if (!response || !response.success || !response.data) {
                    tdSize.textContent = '未知';
                    tdSize.removeAttribute('data-calculating');
                    return;
                }

                var info = response.data;
                
                // 如果 API 提供了大小，直接使用
                if (info.estimated_size_mb && info.estimated_size_mb > 0) {
                    tdSize.textContent = formatFileSizeFromMB(info.estimated_size_mb);
                    tdSize.setAttribute('data-size-mb', info.estimated_size_mb);
                    tdSize.removeAttribute('data-calculating');
                    return;
                } else if (info.estimated_size && info.estimated_size > 0) {
                    tdSize.textContent = formatFileSize(info.estimated_size);
                    tdSize.setAttribute('data-size-mb', (info.estimated_size / 1024 / 1024).toFixed(2));
                    tdSize.removeAttribute('data-calculating');
                    return;
                }

                // 如果没有大小信息，尝试从 siblings 计算
                if (info.siblings && Array.isArray(info.siblings) && info.siblings.length > 0) {
                    calculateModelSizeFromSiblings(modelId, info.siblings).then(function(totalSize) {
                        if (totalSize > 0) {
                            tdSize.textContent = formatFileSize(totalSize);
                            tdSize.setAttribute('data-size-mb', (totalSize / 1024 / 1024).toFixed(2));
                        } else {
                            tdSize.textContent = '未知';
                        }
                        tdSize.removeAttribute('data-calculating');
                    }).catch(function(error) {
                        console.error('[HFModelConfig] 计算模型大小失败:', modelId, error);
                        tdSize.textContent = '未知';
                        tdSize.removeAttribute('data-calculating');
                    });
                } else {
                    tdSize.textContent = '未知';
                    tdSize.removeAttribute('data-calculating');
                }
            });
        }

        /**
         * 从 siblings 计算模型大小（通过 HEAD 请求获取文件大小）
         * @param {string} modelId 模型ID
         * @param {Array} siblings 文件列表
         * @returns {Promise<number>} 总大小（字节）
         */
        function calculateModelSizeFromSiblings(modelId, siblings) {
            return new Promise(function(resolve, reject) {
                if (!siblings || !Array.isArray(siblings) || siblings.length === 0) {
                    resolve(0);
                    return;
                }

                // 筛选重要文件（模型权重、tokenizer等）
                var importantFiles = siblings.filter(function(file) {
                    var name = file.rfilename || file.filename || '';
                    return name.endsWith('.safetensors') ||
                           name.endsWith('.bin') ||
                           name.endsWith('.json') ||
                           name === 'tokenizer.json' ||
                           name === 'config.json' ||
                           name.startsWith('tokenizer_config.json');
                });

                if (importantFiles.length === 0) {
                    resolve(0);
                    return;
                }

                console.log('[HFModelConfig] 开始计算模型大小，需要获取', importantFiles.length, '个文件的大小');

                var totalSize = 0;
                var completed = 0;
                var errors = 0;

                // 如果 siblings 中已经有 size 字段，直接使用
                var hasSizeInSiblings = importantFiles.some(function(file) {
                    return file.size && file.size > 0;
                });

                if (hasSizeInSiblings) {
                    importantFiles.forEach(function(file) {
                        if (file.size) {
                            totalSize += parseInt(file.size, 10) || 0;
                        }
                    });
                    console.log('[HFModelConfig] 从 siblings 中获取到总大小:', formatFileSize(totalSize));
                    resolve(totalSize);
                    return;
                }

                // 需要通过 HEAD 请求获取文件大小
                function encodeModelId(id) {
                    return id.split('/').map(function(part) {
                        return encodeURIComponent(part);
                    }).join('/');
                }

                // 通过扩展获取文件大小（避免 CORS 限制）
                importantFiles.forEach(function(file) {
                    var filename = file.rfilename || file.filename;
                    if (!filename) {
                        completed++;
                        if (completed === importantFiles.length) {
                            resolve(totalSize);
                        }
                        return;
                    }

                    // 通过扩展获取文件大小
                    sendMessageToExtension({
                        type: 'HF_GET_FILE_SIZE',
                        modelId: modelId,
                        filename: filename
                    }, function(response) {
                        if (response && response.success) {
                            if (response.size > 0) {
                                totalSize += response.size;
                                console.log('[HFModelConfig] 获取文件大小:', filename, formatFileSize(response.size));
                            } else {
                                // 文件大小为 0，可能是空文件或获取失败，但不计入错误
                                console.log('[HFModelConfig] 获取文件大小:', filename, '0 MB (文件可能为空)');
                            }
                        } else {
                            // 获取失败
                            var errorMsg = '无响应';
                            if (response) {
                                if (response.error) {
                                    errorMsg = response.error;
                                } else if (response.message) {
                                    errorMsg = response.message;
                                } else {
                                    errorMsg = '获取失败';
                                }
                            }
                            console.warn('[HFModelConfig] 获取文件大小失败:', filename, '-', errorMsg);
                            errors++;
                        }
                        
                        completed++;
                        if (completed === importantFiles.length) {
                            if (totalSize > 0) {
                                console.log('[HFModelConfig] 模型总大小计算完成:', formatFileSize(totalSize), '（成功:', (importantFiles.length - errors), '/', importantFiles.length, '）');
                                resolve(totalSize);
                            } else {
                                console.warn('[HFModelConfig] 无法获取模型大小（所有文件获取失败）');
                                resolve(0);
                            }
                        }
                    });
                });
            });
        }

        /**
         * 检查模型是否已存在（通过本地文件系统）
         */
        function checkModelExists(modelId) {
            return new Promise(function (resolve) {
                if (!modelId) {
                    resolve(false);
                    return;
                }

                console.log('[HFModelConfig] 开始检查模型是否已缓存:', modelId);
                
                // 使用本地文件系统检查
                if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.hasModelFiles) {
                    LocalFileStorage.hasModelFiles(modelId)
                        .then(function(exists) {
                            if (exists) {
                                console.log('[HFModelConfig] 模型已缓存:', modelId);
                                // 进一步检查完整性
                                return checkModelIntegrity(modelId).then(function(integrityResult) {
                                    if (integrityResult && integrityResult.complete) {
                                        resolve(true);
                                    } else {
                                        console.warn('[HFModelConfig] 模型已缓存但完整性检查失败:', modelId, integrityResult);
                                        if (integrityResult && (!integrityResult.complete || integrityResult.missing_files.length > 0 || integrityResult.incomplete_files.length > 0)) {
                                            showIntegrityWarning(modelId, integrityResult);
                                        }
                                        resolve(false);
                                    }
                                }).catch(function(err) {
                                    console.error('[HFModelConfig] 完整性检查失败:', err);
                                    resolve(false);
                                });
                            } else {
                                console.log('[HFModelConfig] 模型未缓存:', modelId);
                                resolve(false);
                            }
                        })
                        .catch(function(error) {
                            console.error('[HFModelConfig] 检查模型缓存失败:', error);
                            // 检查失败时返回false，允许继续下载
                            resolve(false);
                        });
                } else {
                    console.warn('[HFModelConfig] LocalFileStorage 不可用，假设模型未缓存');
                    resolve(false);
                }
            });
        }

        /**
         * 检查模型完整性（从本地文件系统）
         * @param {string} modelId 模型ID
         * @returns {Promise<Object>} 完整性检查结果
         */
        function checkModelIntegrity(modelId) {
            return new Promise(function (resolve, reject) {
                if (!modelId) {
                    reject(new Error('模型ID不能为空'));
                    return;
                }

                console.log('[HFModelConfig] 开始检查模型完整性（本地文件系统）:', modelId);
                
                if (typeof LocalFileStorage === 'undefined' || !LocalFileStorage.getModelMetadataInfo || !LocalFileStorage.getFileSize || !LocalFileStorage.checkFileIntegrity) {
                    reject(new Error('LocalFileStorage 不可用，无法检查模型完整性'));
                    return;
                }

                // 1. 从 Hugging Face API 获取应该下载的文件列表
                var encodedModelId = encodeURIComponent(modelId);
                var siblingsUrl = 'https://huggingface.co/api/models/' + encodedModelId + '/siblings';
                
                fetch(siblingsUrl, {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'User-Agent': 'WelineFramework-AutoLeadAgent/1.0'
                    }
                })
                .then(function(res) {
                    if (!res.ok) {
                        throw new Error('获取模型文件列表失败，状态码: ' + res.status);
                    }
                    return res.json();
                })
                .then(function(siblings) {
                    if (!Array.isArray(siblings)) {
                        throw new Error('文件列表格式错误');
                    }

                    // 2. 筛选需要检查的文件（与下载逻辑保持一致）
                    var requiredFiles = [];
                    siblings.forEach(function(file) {
                        var name = file.rfilename || file.filename || '';
                        if (!name) return;
                        
                        // 只检查重要文件
                        if (name.endsWith('.safetensors') ||
                            name.endsWith('.bin') ||
                            name.endsWith('.json') ||
                            name === 'tokenizer.json' ||
                            name === 'config.json' ||
                            name.startsWith('tokenizer_config.json')) {
                            requiredFiles.push({
                                filename: name,
                                expected_size: parseInt(file.size || 0, 10)
                            });
                        }
                    });

                    console.log('[HFModelConfig] 需要检查的文件数:', requiredFiles.length);

                    // 3. 检查本地文件系统中的文件
                    var missingFiles = [];
                    var incompleteFiles = [];
                    var completeFiles = [];
                    
                    // 获取本地元数据
                    return LocalFileStorage.getModelMetadataInfo(modelId).then(function(metadata) {
                        var localFiles = metadata ? (metadata.files || []) : [];
                        var localFilesMap = {};
                        localFiles.forEach(function(f) {
                            localFilesMap[f.filename] = f.size || 0;
                        });

                        // 检查每个必需文件
                        var checkPromises = requiredFiles.map(function(requiredFile) {
                            var filename = requiredFile.filename;
                            var expectedSize = requiredFile.expected_size;
                            
                            return LocalFileStorage.hasModelFile(modelId, filename).then(function(exists) {
                                if (!exists) {
                                    missingFiles.push({
                                        filename: filename,
                                        expected_size: expectedSize,
                                        downloaded_size: 0
                                    });
                                    return;
                                }

                                // 文件存在，检查大小
                                return LocalFileStorage.getFileSize(modelId, filename).then(function(actualSize) {
                                    if (expectedSize > 0 && actualSize !== expectedSize) {
                                        incompleteFiles.push({
                                            filename: filename,
                                            expected_size: expectedSize,
                                            downloaded_size: actualSize
                                        });
                                    } else {
                                        completeFiles.push({
                                            filename: filename,
                                            expected_size: expectedSize,
                                            downloaded_size: actualSize
                                        });
                                    }
                                });
                            });
                        });

                        return Promise.all(checkPromises).then(function() {
                            var isComplete = missingFiles.length === 0 && incompleteFiles.length === 0;
                            
                            var result = {
                                success: true,
                                complete: isComplete,
                                message: isComplete ? '模型文件完整' : '模型文件不完整',
                                data: {
                                    model_id: modelId,
                                    missing_files: missingFiles,
                                    incomplete_files: incompleteFiles,
                                    complete_files: completeFiles,
                                    total_required: requiredFiles.length,
                                    total_missing: missingFiles.length,
                                    total_incomplete: incompleteFiles.length,
                                    total_complete: completeFiles.length
                                }
                            };

                            console.log('[HFModelConfig] 模型完整性检查结果:', modelId, result);
                            resolve(result);
                        });
                    });
                })
                .catch(function(error) {
                    console.error('[HFModelConfig] 检查模型完整性异常:', error);
                    reject(error);
                });
            });
        }

        /**
         * 显示完整性警告（未完成的文件）
         * @param {string} modelId 模型ID
         * @param {Object} integrityResult 完整性检查结果
         */
        function showIntegrityWarning(modelId, integrityResult) {
            var data = integrityResult.data || {};
            var missingFiles = data.missing_files || [];
            var incompleteFiles = data.incomplete_files || [];
            
            if (missingFiles.length === 0 && incompleteFiles.length === 0) {
                return; // 没有未完成的文件，不需要提示
            }

            var message = '模型下载不完整！\n\n';
            
            if (missingFiles.length > 0) {
                message += '缺失文件 (' + missingFiles.length + ' 个):\n';
                missingFiles.forEach(function(file, index) {
                    if (index < 10) { // 最多显示10个
                        message += '  • ' + file.filename;
                        if (file.expected_size_mb > 0) {
                            message += ' (' + file.expected_size_mb + ' MB)';
                        }
                        message += '\n';
                    }
                });
                if (missingFiles.length > 10) {
                    message += '  ... 还有 ' + (missingFiles.length - 10) + ' 个文件\n';
                }
                message += '\n';
            }
            
            if (incompleteFiles.length > 0) {
                message += '不完整文件 (' + incompleteFiles.length + ' 个):\n';
                incompleteFiles.forEach(function(file, index) {
                    if (index < 10) { // 最多显示10个
                        message += '  • ' + file.filename;
                        message += ' (已下载: ' + file.actual_size_mb + ' MB / 预期: ' + file.expected_size_mb + ' MB, ' + file.progress_percent + '%)';
                        message += '\n';
                    }
                });
                if (incompleteFiles.length > 10) {
                    message += '  ... 还有 ' + (incompleteFiles.length - 10) + ' 个文件\n';
                }
                message += '\n';
            }
            
            message += '建议：重新下载模型以确保所有文件完整。';
            
            // 使用更友好的提示方式
            console.warn('[HFModelConfig] ' + message);
            safeToast('warning', message, 10000); // 显示10秒
        }

        /**
         * 已废弃：LocalFileStorage 会自动更新元数据，不再需要此函数
         */
        function updateModelMetadataFromFiles_DEPRECATED(modelId, filename, size) {
            // LocalFileStorage.saveModelFile 会自动调用 updateModelMetadata
            // 此函数已不再需要
            console.log('[HFModelConfig] updateModelMetadataFromFiles 已废弃，LocalFileStorage 会自动处理元数据');
        }
        
        /**
         * 已废弃：LocalFileStorage 会自动更新元数据，不再需要此函数
         */
        function saveModelMetadataOnly_DEPRECATED(modelId, files) {
            // LocalFileStorage 会自动管理元数据
            // 此函数已不再需要
            console.log('[HFModelConfig] saveModelMetadataOnly 已废弃，LocalFileStorage 会自动处理元数据');
            return Promise.resolve();
        }
        

        // 文件数据块缓存（用于大文件分块传输）
        var fileChunksCache = new Map();

        /**
         * 处理文件数据块（用于大文件分块传输）
         */
        function handleFileDataChunk(message) {
            const { modelId, filename, size, chunkIndex, totalChunks, data, isComplete } = message;
            
            const cacheKey = `${modelId}/${filename}`;
            
            if (!fileChunksCache.has(cacheKey)) {
                fileChunksCache.set(cacheKey, {
                    modelId: modelId,
                    filename: filename,
                    size: size,
                    chunks: [],
                    receivedSize: 0
                });
            }
            
            const fileInfo = fileChunksCache.get(cacheKey);
            
            // 存储数据块
            if (chunkIndex !== undefined && totalChunks !== undefined) {
                // 分块传输
                fileInfo.chunks[chunkIndex] = data;
            } else {
                // 单块传输
                fileInfo.chunks = [data];
            }
            
            fileInfo.receivedSize += data.byteLength;
            
            // 如果所有块都已接收，合并并保存
            if (isComplete) {
                console.log('[HFModelConfig] 文件数据接收完成，准备保存到本地文件系统:', filename, (size / 1024 / 1024).toFixed(2), 'MB');
                
                // 合并所有块
                let arrayBuffer;
                if (fileInfo.chunks.length === 1) {
                    arrayBuffer = fileInfo.chunks[0];
                } else {
                    const totalLength = fileInfo.chunks.reduce((sum, chunk) => sum + chunk.byteLength, 0);
                    arrayBuffer = new ArrayBuffer(totalLength);
                    const uint8Array = new Uint8Array(arrayBuffer);
                    let offset = 0;
                    for (const chunk of fileInfo.chunks) {
                        uint8Array.set(new Uint8Array(chunk), offset);
                        offset += chunk.byteLength;
                    }
                }
                
                // 保存到本地文件系统
                if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.saveModelFile) {
                    LocalFileStorage.saveModelFile(modelId, filename, arrayBuffer)
                        .then(function() {
                            console.log('[HFModelConfig] 文件已保存到本地文件系统:', filename);
                            // 更新元数据
                            LocalFileStorage.updateModelMetadata(modelId, filename, size);
                            // 清理缓存
                            fileChunksCache.delete(cacheKey);
                        })
                        .catch(function(error) {
                            console.error('[HFModelConfig] 保存文件到本地文件系统失败:', error);
                            safeToast('error', '保存文件失败: ' + (error.message || error.toString()));
                            // 清理缓存
                            fileChunksCache.delete(cacheKey);
                        });
                } else {
                    console.error('[HFModelConfig] LocalFileStorage 不可用');
                    safeToast('error', '本地文件系统存储不可用，请确保已加载 local-file-storage.js');
                    fileChunksCache.delete(cacheKey);
                }
            }
        }

        /**
         * 处理文件下载完成
         */
        function handleFileDownloadComplete(modelId, filename, size) {
            console.log('[HFModelConfig] 文件下载完成:', modelId, filename, size);
            // 元数据已在 handleFileDataChunk 中更新，这里只需要记录日志
        }


        /**
         * 显示下载模态框
         */
        function showDownloadModal(modelId) {
            var modal = document.getElementById('hf-download-modal');
            if (modal) {
                // 移除 aria-hidden 属性（Bootstrap 会在显示时自动处理）
                modal.removeAttribute('aria-hidden');
                
                var bsModal;
                // 兼容 Bootstrap 4 和 5
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    if (typeof bootstrap.Modal.getOrCreateInstance === 'function') {
                        // Bootstrap 5.1.0+
                        bsModal = bootstrap.Modal.getOrCreateInstance(modal, {
                            backdrop: 'static',
                            keyboard: false
                        });
                    } else {
                        // Bootstrap 4 或 Bootstrap 5.0.x
                        bsModal = bootstrap.Modal.getInstance(modal);
                        if (!bsModal) {
                            bsModal = new bootstrap.Modal(modal, {
                                backdrop: 'static',
                                keyboard: false
                            });
                        }
                    }
                } else {
                    console.error('[HFModelConfig] Bootstrap Modal 不可用');
                    return;
                }
                
                // 在显示前确保移除 aria-hidden
                modal.addEventListener('show.bs.modal', function () {
                    modal.removeAttribute('aria-hidden');
                }, { once: true });
                
                // 显示后确保 aria-hidden 被移除
                modal.addEventListener('shown.bs.modal', function () {
                    modal.removeAttribute('aria-hidden');
                }, { once: true });
                
                bsModal.show();
            }
        }

        /**
         * 隐藏下载模态框
         */
        function hideDownloadModal() {
            var modal = document.getElementById('hf-download-modal');
            if (modal) {
                var bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    // 在隐藏前设置 aria-hidden
                    modal.addEventListener('hide.bs.modal', function () {
                        modal.setAttribute('aria-hidden', 'true');
                    }, { once: true });
                    
                    // 隐藏后确保 aria-hidden 被设置
                    modal.addEventListener('hidden.bs.modal', function () {
                        modal.setAttribute('aria-hidden', 'true');
                    }, { once: true });
                    
                    bsModal.hide();
                } else {
                    // 如果没有实例，手动隐藏
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('modal-open');
                    var backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
            }
        }

        /**
         * 显示登录提示模态框（用于下载模型）
         */
        function showLoginModal(loginTabId, modelId) {
            var modal = document.getElementById('hf-login-modal');
            if (modal) {
                // 确保 aria-hidden 正确设置
                modal.setAttribute('aria-hidden', 'false');
                modal.removeAttribute('aria-hidden');
                
                var bsModal = new bootstrap.Modal(modal, {
                    backdrop: 'static',
                    keyboard: false
                });
                bsModal.show();
                
                // 监听模态框显示事件，确保 aria-hidden 正确
                modal.addEventListener('shown.bs.modal', function () {
                    modal.setAttribute('aria-hidden', 'false');
                }, { once: true });

                // 更新按钮文字
                var btnText = document.getElementById('hf-login-check-btn-text');
                if (btnText) {
                    btnText.textContent = '我已登录，继续下载';
                }

                // 绑定手动检查登录按钮
                var checkBtn = document.getElementById('hf-login-check-btn');
                if (checkBtn) {
                    checkBtn.onclick = function () {
                        checkLoginAndContinue(modelId);
                    };
                }
            }
        }

        /**
         * 手动检查登录并继续下载
         */
        function checkLoginAndContinue(modelId) {
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                safeToast('error', '浏览器扩展未安装或不可用');
                return;
            }

            var checkBtn = document.getElementById('hf-login-check-btn');
            if (checkBtn) {
                checkBtn.disabled = true;
                checkBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 检查中...';
            }

            var message = {
                type: 'HF_CHECK_LOGIN'
            };
            console.log('[HFModelConfig] 发送登录检查消息到扩展:', message);

            sendMessageToExtension(message, function (response) {
                console.log('[HFModelConfig] 收到登录检查响应:', response);

                if (checkBtn) {
                    checkBtn.disabled = false;
                    var btnText = document.getElementById('hf-login-check-btn-text');
                    if (btnText) {
                        checkBtn.innerHTML = '<i class="mdi mdi-check-circle"></i> <span id="hf-login-check-btn-text">' + btnText.textContent + '</span>';
                    } else {
                        checkBtn.innerHTML = '<i class="mdi mdi-check-circle"></i> 我已登录，继续';
                    }
                }

                if (!response) {
                    safeToast('error', '扩展未响应，请确保扩展已安装并启用');
                    return;
                }

                if (response.success && response.loggedIn) {
                    // 登录成功，继续下载
                    handleLoginSuccess(modelId);
                } else {
                    // 仍未登录
                    safeToast('error', '检测到尚未登录，请先完成 HuggingFace 登录');
                }
            });
        }

        /**
         * 隐藏登录提示模态框
         */
        function hideLoginModal() {
            var modal = document.getElementById('hf-login-modal');
            if (modal) {
                var bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                } else {
                    // 如果没有实例，手动隐藏
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('modal-open');
                    var backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
                
                // 监听模态框隐藏事件，确保 aria-hidden 正确
                modal.addEventListener('hidden.bs.modal', function () {
                    modal.setAttribute('aria-hidden', 'true');
                }, { once: true });
            }
        }

        /**
         * 显示网络错误提示模态框
         */
        function showNetworkErrorModal(errorMessage, errorDetails) {
            var modal = document.getElementById('hf-network-error-modal');
            if (!modal) {
                console.warn('[HFModelConfig] 网络错误模态框不存在');
                return;
            }

            // 设置错误详情（如果有）
            var detailsEl = document.getElementById('hf-network-error-details');
            var messageEl = document.getElementById('hf-network-error-message');
            if (detailsEl && messageEl) {
                if (errorDetails) {
                    detailsEl.style.display = 'block';
                    messageEl.textContent = errorDetails;
                } else {
                    detailsEl.style.display = 'none';
                }
            }

            // 确保 aria-hidden 正确设置
            modal.setAttribute('aria-hidden', 'false');
            modal.removeAttribute('aria-hidden');

            var bsModal = new bootstrap.Modal(modal, {
                backdrop: 'static',
                keyboard: false
            });
            bsModal.show();

            // 监听模态框显示事件，确保 aria-hidden 正确
            modal.addEventListener('shown.bs.modal', function () {
                modal.setAttribute('aria-hidden', 'false');
            }, { once: true });

            // 绑定重试按钮
            var retryBtn = document.getElementById('hf-network-error-retry-btn');
            if (retryBtn) {
                // 移除之前的监听器（如果有）
                var newRetryBtn = retryBtn.cloneNode(true);
                retryBtn.parentNode.replaceChild(newRetryBtn, retryBtn);
                
                newRetryBtn.addEventListener('click', function () {
                    hideNetworkErrorModal();
                    // 显示加载状态
                    if (listBody) {
                        listBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted small">正在重试...</td></tr>';
                    }
                    // 延迟一下再重新搜索，给用户反馈
                    setTimeout(function () {
                        searchModels();
                    }, 300);
                });
            }
        }

        /**
         * 隐藏网络错误提示模态框
         */
        function hideNetworkErrorModal() {
            var modal = document.getElementById('hf-network-error-modal');
            if (modal) {
                var bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                } else {
                    // 如果没有实例，手动隐藏
                    modal.style.display = 'none';
                    modal.classList.remove('show');
                    modal.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('modal-open');
                    var backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }

                // 监听模态框隐藏事件，确保 aria-hidden 正确
                modal.addEventListener('hidden.bs.modal', function () {
                    modal.setAttribute('aria-hidden', 'true');
                }, { once: true });
            }
        }

        /**
         * 显示保存成功提示模态框
         */
        // showSaveSuccessModal 函数已废弃，不再使用弹窗
        // 现在直接使用 safeToast 提示并自动刷新页面

        function saveModelConfig() {
            var modelId = selectedModelId || currentModelId;
            if (!modelId) {
                safeToast('error', '请先在列表中选择一个模型');
                return;
            }

            var enabled = enabledInput ? enabledInput.checked : false;
            var cache = cacheInput ? parseInt(cacheInput.value || '0', 10) : cacheSize;
            if (isNaN(cache)) {
                cache = cacheSize;
            }

            if (cache < 100 || cache > 10240) {
                safeToast('error', '缓存大小必须在 100-10240 MB 之间');
                return;
            }

            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin"></i> 保存中...';
            }

            console.log('[HFModelConfig] 开始保存配置，将下载模型到本地文件系统（如果未下载）:', modelId);
            
            // 更新状态为"下载中"
            if (detailStatus) {
                detailStatus.innerHTML = '<span class="badge bg-info"><i class="mdi mdi-download me-1"></i>下载中...</span>';
            }
            
            // 先请求文件系统权限（在用户手势上下文中）
            var permissionPromise = Promise.resolve();
            if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.requestModelDirectoryPermission) {
                permissionPromise = LocalFileStorage.requestModelDirectoryPermission(modelId)
                    .then(function() {
                        console.log('[HFModelConfig] 文件系统权限已获取');
                        safeToast('success', '请选择项目目录（建议选择包含 pub/models 的目录）', 5000);
                    })
                    .catch(function(error) {
                        console.warn('[HFModelConfig] 获取文件系统权限失败:', error);
                        var errorMsg = error.message || '获取文件系统权限失败';
                        if (errorMsg.includes('用户取消')) {
                            safeToast('info', '已取消选择保存位置，下载将使用浏览器默认下载');
                        } else if (errorMsg.includes('用户交互') || errorMsg.includes('user gesture')) {
                            safeToast('warning', '需要选择文件保存位置。请重新点击"保存"按钮，然后在弹出的对话框中选择项目目录（建议选择包含 pub/models 的目录）。', 8000);
                            throw error; // 重新抛出错误，阻止后续下载
                        } else {
                            safeToast('warning', errorMsg + '。下载将使用浏览器默认下载。');
                        }
                    });
            }
            
            // 等待权限获取完成后再开始下载
            permissionPromise
                .then(function() {
                    // 先确保模型已下载（保存到本地文件系统）
                    return ensureModelDownloaded(modelId);
                })
                .then(function (downloadResult) {
                    console.log('[HFModelConfig] 模型下载完成（已保存到本地文件系统），开始保存配置', downloadResult);
                    
                    // 如果下载成功，立即更新状态
                    if (downloadResult && downloadResult.cached) {
                        if (detailStatus) {
                            detailStatus.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check-circle me-1"></i>已下载</span>';
                        }
                    } else if (downloadResult && downloadResult.failedCount > 0) {
                        if (detailStatus) {
                            detailStatus.innerHTML = '<span class="badge bg-warning"><i class="mdi mdi-alert-circle me-1"></i>部分下载（' + downloadResult.failedCount + ' 个文件失败）</span>';
                        }
                    }
                    
                    // 模型下载完成，保存配置
                    // 后端路由为 /config/save-model-config
                    var url = buildUrl('save-model-config');
                    var formData = new FormData();
                    formData.append('model_id', modelId);
                    formData.append('enabled', enabled ? '1' : '0');
                    formData.append('cache_size', String(cache));

                    return fetch(url, {
                        method: 'POST',
                        body: formData
                    });
                })
                .then(function (res) {
                    return res.json();
                })
                .then(function (data) {
                    if (!data || data.success !== true) {
                        // 保存失败，抛出错误以触发 catch 块，不刷新页面
                        var errorMsg = (data && data.message) || '模型配置保存失败';
                        throw new Error(errorMsg);
                    }

                    currentModelId = modelId;
                    cacheSize = cache;
                    if (badge) {
                        badge.textContent = currentModelId;
                    }
                    if (card) {
                        card.setAttribute('data-current-model-id', currentModelId);
                        card.setAttribute('data-enabled', enabled ? '1' : '0');
                        card.setAttribute('data-cache-size', String(cacheSize));
                    }
                    // 更新缓存大小输入框的值
                    if (cacheInput) {
                        cacheInput.value = cacheSize;
                    }
                    if (detailName && !detailName.textContent) {
                        detailName.textContent = currentModelId;
                    }

                    // 显示成功提示，然后刷新页面
                    safeToast('success', data.message || '模型配置保存成功');
                    // 延迟刷新页面，让用户看到提示
                    setTimeout(function() {
                        window.location.reload();
                    }, 1500);
                })
                .catch(function (err) {
                    console.error('[HFModelConfig] saveModelConfig error', err);
                    var errorMsg = err.message || '模型配置保存失败，请稍后重试';
                    safeToast('error', errorMsg);
                })
                .finally(function () {
                    if (saveBtn) {
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = '<i class="mdi mdi-content-save"></i> 保存为当前模型';
                    }
                });
        }

        if (searchBtn) {
            searchBtn.addEventListener('click', searchModels);
        }
        if (searchInput) {
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    searchModels();
                }
            });
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                var id = currentModelId || selectedModelId;
                if (!id) {
                    safeToast('error', '当前尚未配置模型，请先选择并保存模型');
                    return;
                }
                loadModelInfo(id);
            });
        }

        // 智能体测试功能
        var testBtn = document.getElementById('hf_agent_test_btn');
        var testInput = document.getElementById('hf_agent_test_input');
        var testResultDiv = document.getElementById('hf_agent_test_result');
        var testLoadingDiv = document.getElementById('hf_agent_test_loading');
        var testInputDisplay = document.getElementById('hf_agent_test_input_display');
        var testOutputDiv = document.getElementById('hf_agent_test_output');
        var testStepsDiv = document.getElementById('hf_agent_test_steps');
        var testStepsContent = document.getElementById('hf_agent_test_steps_content');
        var testErrorDiv = document.getElementById('hf_agent_test_error');
        var testTimeBadge = document.getElementById('hf_agent_test_time');
        var testClearBtn = document.getElementById('hf_agent_test_clear_btn');

        // 更新测试按钮状态
        function updateTestButtonState() {
            var modelId = currentModelId || selectedModelId;
            var enabled = enabledInput ? enabledInput.checked : false;
            if (testBtn) {
                testBtn.disabled = !modelId || !enabled;
                if (testBtn.disabled) {
                    testBtn.title = !modelId ? '请先选择并保存模型' : '请先启用模型';
                } else {
                    testBtn.title = '';
                }
            }
        }

        // 监听模型选择和启用状态变化
        if (enabledInput) {
            enabledInput.addEventListener('change', updateTestButtonState);
        }
        updateTestButtonState();

        // 测试按钮点击事件
        if (testBtn) {
            testBtn.addEventListener('click', function() {
                var modelId = currentModelId || selectedModelId;
                if (!modelId) {
                    safeToast('error', '请先选择并保存模型');
                    return;
                }

                var input = testInput ? testInput.value.trim() : '';
                if (!input) {
                    safeToast('error', '请输入测试指令');
                    return;
                }

                // 检查模型是否已下载（Chrome Built-in AI 不需要下载）
                var isChromeAI = typeof window !== 'undefined' && 
                                 window.ai && 
                                 typeof window.ai.createTextSession === 'function';
                
                // 执行测试的函数
                function doTest() {
                    // 显示加载状态
                    if (testLoadingDiv) {
                        testLoadingDiv.style.display = 'block';
                    }
                    if (testResultDiv) {
                        testResultDiv.style.display = 'none';
                    }
                    if (testErrorDiv) {
                        testErrorDiv.style.display = 'none';
                    }
                    if (testStepsDiv) {
                        testStepsDiv.style.display = 'none';
                    }
                    if (testBtn) {
                        testBtn.disabled = true;
                        testBtn.innerHTML = '<i class="mdi mdi-loading mdi-spin me-1"></i> 测试中...';
                    }

                    var startTime = Date.now();
                    var steps = [];

                    // 调用智能体进行测试
                    testAgent(modelId, input, function(step) {
                    // 进度回调：记录执行步骤
                    steps.push(step);
                    if (testStepsContent) {
                        var stepHtml = '<div class="mb-1"><small>';
                        if (step.phase) {
                            stepHtml += '<span class="badge bg-info me-1">' + step.phase + '</span>';
                        }
                        if (step.decision && step.decision.tool) {
                            stepHtml += '调用工具: ' + step.decision.tool;
                        }
                        if (step.result) {
                            stepHtml += ' - 结果: ' + (typeof step.result === 'string' ? step.result.substring(0, 100) : JSON.stringify(step.result).substring(0, 100));
                        }
                        stepHtml += '</small></div>';
                        testStepsContent.innerHTML += stepHtml;
                    }
                })
                    .then(function(result) {
                        var endTime = Date.now();
                        var duration = ((endTime - startTime) / 1000).toFixed(2);

                        // 隐藏加载状态
                        if (testLoadingDiv) {
                            testLoadingDiv.style.display = 'none';
                        }

                        // 显示结果
                        if (testResultDiv) {
                            testResultDiv.style.display = 'block';
                        }
                        if (testInputDisplay) {
                            testInputDisplay.textContent = input;
                        }
                        if (testOutputDiv) {
                            var output = result.response || result.output || '智能体处理完成';
                            testOutputDiv.innerHTML = '<pre class="mb-0" style="white-space: pre-wrap; word-wrap: break-word;">' + 
                                htmlspecialchars(output) + '</pre>';
                        }
                        if (testStepsDiv && steps.length > 0) {
                            testStepsDiv.style.display = 'block';
                        }
                        if (testTimeBadge) {
                            testTimeBadge.textContent = duration + 's';
                        }
                        if (testErrorDiv) {
                            testErrorDiv.style.display = 'none';
                        }
                    })
                    .catch(function(error) {
                        var endTime = Date.now();
                        var duration = ((endTime - startTime) / 1000).toFixed(2);

                        // 隐藏加载状态
                        if (testLoadingDiv) {
                            testLoadingDiv.style.display = 'none';
                        }

                        // 显示错误
                        if (testResultDiv) {
                            testResultDiv.style.display = 'block';
                        }
                        if (testInputDisplay) {
                            testInputDisplay.textContent = input;
                        }
                        if (testOutputDiv) {
                            testOutputDiv.innerHTML = '<p class="text-muted mb-0">测试失败，请查看错误信息</p>';
                        }
                        if (testErrorDiv) {
                            // 将错误信息中的换行符转换为 HTML 换行，并转义 HTML 特殊字符
                            var errorMsg = (error.message || String(error))
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/\n/g, '<br>');
                            testErrorDiv.innerHTML = errorMsg;
                            testErrorDiv.style.display = 'block';
                        }
                        if (testTimeBadge) {
                            testTimeBadge.textContent = duration + 's';
                        }

                        // 错误信息已在界面上显示，不需要额外的 toast 提示
                        console.error('[HFModelConfig] 智能体测试失败:', error);
                    })
                    .finally(function() {
                        if (testBtn) {
                            testBtn.disabled = false;
                            testBtn.innerHTML = '<i class="mdi mdi-play-circle me-1"></i> 开始测试';
                        }
                    });
                }
                
                // 检查模型下载状态
                if (!isChromeAI) {
                    // 对于 WebLLM 模型，需要检查是否已下载
                    checkModelExists(modelId).then(function(exists) {
                        if (!exists) {
                            var errorMsg = '模型未下载，无法进行测试。\n\n' +
                                         '请先下载模型：\n' +
                                         '1. 点击"保存为当前模型"按钮来下载模型\n' +
                                         '2. 或使用 Chrome Built-in AI（如果可用）\n\n' +
                                         '当前模型：' + modelId;
                            console.warn('[HFModelConfig] 模型未下载:', modelId);
                            
                            // 显示错误提示
                            if (testErrorDiv) {
                                testErrorDiv.innerHTML = errorMsg.replace(/\n/g, '<br>');
                                testErrorDiv.style.display = 'block';
                            }
                            if (testResultDiv) {
                                testResultDiv.style.display = 'block';
                            }
                            safeToast('error', '模型未下载，请先下载模型');
                            return;
                        }
                        
                        // 模型已下载，继续测试
                        console.log('[HFModelConfig] 模型已下载，继续测试');
                        doTest();
                    }).catch(function(error) {
                        console.error('[HFModelConfig] 检查模型是否存在失败:', error);
                        // 检查失败时，仍然尝试继续（可能是检查功能不可用，或者使用 Chrome AI）
                        console.warn('[HFModelConfig] 检查失败，尝试继续测试（可能使用 Chrome AI）');
                        doTest();
                    });
                } else {
                    // Chrome AI 可用，直接继续测试（不需要下载）
                    console.log('[HFModelConfig] Chrome Built-in AI 可用，跳过模型下载检查');
                    doTest();
                }
            });
        }

        // 清空结果按钮
        if (testClearBtn) {
            testClearBtn.addEventListener('click', function() {
                if (testResultDiv) {
                    testResultDiv.style.display = 'none';
                }
                if (testInput) {
                    testInput.value = '';
                }
                if (testErrorDiv) {
                    testErrorDiv.style.display = 'none';
                }
                if (testStepsContent) {
                    testStepsContent.innerHTML = '';
                }
            });
        }

        // 智能体测试函数
        function testAgent(modelId, input, onProgress) {
            return new Promise(function(resolve, reject) {
                console.log('[HFModelConfig] 开始智能体测试:', modelId, input);

                onProgress = onProgress || function() {};

                // 检查智能体是否可用
                if (typeof ReActAgent === 'undefined') {
                    reject(new Error('智能体功能不可用，请确保已加载 react-agent.js'));
                    return;
                }

                // 获取配置
                var card = document.getElementById('hf-model-config-card');
                var cacheSize = card ? parseInt(card.getAttribute('data-cache-size') || '10240', 10) : 10240;

                // 初始化 ModelInference（智能体需要）
                if (typeof ModelInference === 'undefined') {
                    reject(new Error('模型推理功能不可用，请确保已加载 model-inference.js'));
                    return;
                }

                try {
                    // 初始化 ModelInference
                    ModelInference.init({
                        modelId: modelId,
                        cacheSize: cacheSize
                    });

                    // 初始化 ReActAgent
                    ReActAgent.init({
                        maxIterations: 10 // 测试时限制迭代次数
                    });

                    console.log('[HFModelConfig] 智能体已初始化，开始处理输入...');

                    // 创建一个简化的测试场景
                    // 使用智能体的 think 方法来分析用户输入并生成决策
                    var testProfile = {
                        name: '测试客户',
                        description: input,
                        language: 'zh'
                    };

                    var currentState = {
                        task: input,
                        iteration: 0,
                        foundCustomers: []
                    };

                    // 调用智能体的 think 方法
                    ReActAgent.think(currentState, testProfile, [])
                        .then(function(decision) {
                            console.log('[HFModelConfig] 智能体决策:', decision);
                            
                            onProgress({
                                phase: 'think',
                                decision: decision
                            });

                            // 如果决策是完成，直接返回
                            if (decision.action === 'complete' || decision.action === 'finish') {
                                resolve({
                                    response: decision.reason || '智能体已完成任务',
                                    decision: decision
                                });
                                return;
                            }

                            // 如果有工具调用，尝试执行（如果 MCP 可用）
                            if (decision.tool && typeof MCPClient !== 'undefined') {
                                // 尝试执行工具（测试环境可能无法真正执行）
                                onProgress({
                                    phase: 'act',
                                    decision: decision
                                });

                                resolve({
                                    response: '智能体决定调用工具: ' + decision.tool + '，参数: ' + JSON.stringify(decision.arguments || {}),
                                    decision: decision,
                                    note: '注意：测试环境可能无法真正执行工具调用，实际执行需要 MCP 连接'
                                });
                            } else {
                                // 没有工具调用，返回决策结果
                                resolve({
                                    response: decision.reason || '智能体已分析完成',
                                    decision: decision
                                });
                            }
                        })
                        .catch(function(err) {
                            console.error('[HFModelConfig] 智能体测试失败:', err);
                            
                            // 检查是否是模型文件不存在的错误
                            var errorMsg = err.message || String(err);
                            if (errorMsg.includes('Could not locate file') || 
                                errorMsg.includes('locate file') ||
                                errorMsg.includes('MODEL_FILE_NOT_FOUND') ||
                                errorMsg.includes('模型文件不存在') ||
                                errorMsg.includes('文件在模型仓库中不存在') ||
                                errorMsg.includes('加载失败')) {
                                // 提供更友好的错误信息，建议换模型
                                var friendlyMsg = '模型加载失败，建议更换模型\n\n';
                                friendlyMsg += '推荐使用以下模型：\n';
                                friendlyMsg += '• Qwen/Qwen2.5-1.5B-Instruct（推荐，小模型，速度快）\n';
                                friendlyMsg += '• Qwen/Qwen3-0.6B（超小模型，适合测试）\n';
                                friendlyMsg += '• Qwen/Qwen2.5-3B-Instruct（中等模型）\n\n';
                                friendlyMsg += '提示：请在模型列表中选择上述模型重新测试。';
                                
                                // 显示 Toast 提示
                                safeToast('error', friendlyMsg, 15000);
                                
                                reject(new Error(friendlyMsg));
                            } else {
                                // 其他错误也显示提示
                                var generalError = '智能体处理失败: ' + errorMsg + '\n\n';
                                generalError += '如果问题持续，建议尝试更换模型。';
                                safeToast('error', generalError, 10000);
                                reject(new Error(generalError));
                            }
                        });
                } catch (err) {
                    console.error('[HFModelConfig] 智能体初始化失败:', err);
                    reject(new Error('智能体初始化失败: ' + (err.message || String(err))));
                }
            });
        }

        // HTML 转义辅助函数
        function htmlspecialchars(str) {
            if (typeof str !== 'string') {
                str = String(str);
            }
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        if (saveBtn) {
            saveBtn.addEventListener('click', saveModelConfig);
        }

        // 页面加载时先检测扩展，然后加载模型列表
        console.log('[HFModelConfig] 页面加载完成，开始初始化');
        
        // 如果扩展已经就绪，直接搜索模型（不等待 ping）
        if (extensionReady) {
            console.log('[HFModelConfig] 扩展已就绪，直接搜索模型');
            searchModels();
            if (currentModelId) {
                loadModelInfo(currentModelId);
            }
        } else {
            // 扩展未就绪，等待就绪消息或尝试检测
            var initTimeout = setTimeout(function () {
                // 如果3秒内没有收到扩展就绪消息，直接尝试搜索（扩展可能已经就绪但消息还没到达）
                console.log('[HFModelConfig] 等待扩展就绪超时，直接尝试搜索模型');
                searchModels();
                if (currentModelId) {
                    loadModelInfo(currentModelId);
                }
            }, 3000);
            
            // 监听扩展就绪消息
            var readyHandler = function (event) {
                if (event.source !== window) return;
                if (event.data && event.data.type === 'AUTOLEADAGENT_READY') {
                    clearTimeout(initTimeout);
                    window.removeEventListener('message', readyHandler);
                    console.log('[HFModelConfig] 扩展已就绪，开始搜索模型');
                    searchModels();
                    if (currentModelId) {
                        loadModelInfo(currentModelId);
                    }
                }
            };
            window.addEventListener('message', readyHandler);
            
            // 同时尝试检测扩展（但不阻塞搜索）
            checkExtensionAvailable().then(function (extCheck) {
                if (extCheck.available) {
                    console.log('[HFModelConfig] 扩展检测成功，版本:', extCheck.version);
                } else {
                    console.warn('[HFModelConfig] 扩展检测警告:', extCheck.error, '（但可能通过 content script 仍然可用）');
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindHFModelConfig);
    } else {
        bindHFModelConfig();
    }

})();


