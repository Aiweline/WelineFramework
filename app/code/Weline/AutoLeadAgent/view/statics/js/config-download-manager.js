/**
 * AutoLeadAgent 配置页 - 下载管理器
 */

var ConfigDownloadManager = (function () {
    'use strict';

    var downloadState = {
        isDownloading: false,
        currentModelId: null,
        totalSize: 0,
        downloadedSize: 0,
        currentFile: '',
        currentFileSize: 0,
        currentFileDownloaded: 0,
        currentFileProgress: 0,
        progress: 0,
        cancelled: false,
        downloadPort: null, // 用于取消下载的 Port 连接
        startTime: null, // 下载开始时间
        lastDownloadedSize: 0, // 上次记录的下载大小
        lastUpdateTime: null // 上次更新时间
    };

    /**
     * 取消下载
     */
    function cancelDownload() {
        if (!downloadState.isDownloading) return;

        console.log('[DownloadManager] 用户取消下载');
        downloadState.cancelled = true;
        downloadState.isDownloading = false;

        // 发送取消消息到 content script
        if (downloadState.downloadPort) {
            try {
                downloadState.downloadPort.postMessage({ type: 'cancel-download' });
            } catch (e) {
                console.warn('[DownloadManager] 发送取消消息失败:', e);
            }
        }

        // 发送取消消息到 content script（备用方式）
        window.postMessage({
            type: 'AUTOLEADAGENT_REQUEST',
            action: 'HF_CANCEL_DOWNLOAD',
            payload: { modelId: downloadState.currentModelId },
            requestId: 'cancel_' + Date.now()
        }, '*');
    }

    /**
     * 开始下载模型
     */
    function startDownload(modelId, onProgress) {
        return new Promise(async function (resolve, reject) {
            if (downloadState.isDownloading) {
                reject(new Error('已有下载任务正在运行'));
                return;
            }

            console.log('[DownloadManager] 开始下载模型:', modelId);

            // 检查是否有配置的缓存目录
            var hasCachedDir = false;
            if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.getCachedDirectoryHandle) {
                try {
                    const cachedHandle = await LocalFileStorage.getCachedDirectoryHandle();
                    hasCachedDir = !!cachedHandle;
                    console.log('[DownloadManager] 缓存目录状态:', hasCachedDir ? '已配置' : '未配置');
                } catch (error) {
                    console.warn('[DownloadManager] 检查缓存目录失败:', error);
                }
            }

            if (!hasCachedDir) {
                console.warn('[DownloadManager] 未配置缓存目录，下载时可能需要选择目录');
            }

            downloadState.isDownloading = true;
            downloadState.currentModelId = modelId;
            downloadState.downloadedSize = 0;
            downloadState.currentFileSize = 0;
            downloadState.currentFileDownloaded = 0;
            downloadState.currentFileProgress = 0;
            downloadState.cancelled = false;
            downloadState.downloadPort = null;
            downloadState.progress = 0;
            downloadState.totalSize = 0;
            downloadState.currentFile = '';
            downloadState.downloadSpeed = 0;
            downloadState.lastUpdateTime = Date.now();
            downloadState.lastDownloadedSize = 0;

            // 初始化时立即触发一次进度回调
            if (onProgress) {
                console.log('[DownloadManager] 初始化进度回调');
                onProgress(downloadState);
            }

            // 用于存储 cleanup 回调
            var cleanupCallback = null;

            // 设置监听器处理来自 content script 的下载消息
            var messageHandler = function (event) {
                if (event.source !== window) return;
                
                // 调试：记录所有消息
                if (event.data && (event.data.type === 'AUTOLEADAGENT_DOWNLOAD_MESSAGE' || event.data.type === 'AUTOLEADAGENT_RESPONSE')) {
                    console.log('[DownloadManager] 收到消息:', JSON.stringify(event.data).substring(0, 500));
                }
                
                // 处理下载专用消息
                if (event.data && event.data.type === 'AUTOLEADAGENT_DOWNLOAD_MESSAGE') {
                    // 检查是否已取消
                    if (downloadState.cancelled) {
                        if (cleanupCallback) cleanupCallback();
                        reject(new Error('下载已取消'));
                        return;
                    }

                    handleDownloadMessage(event.data.data, modelId, onProgress, resolve, reject, function () {
                        window.removeEventListener('message', messageHandler);
                        cleanupCallback = null;
                    });
                }
                
                // 兼容处理：AUTOLEADAGENT_RESPONSE 也可能包含下载进度
                if (event.data && event.data.type === 'AUTOLEADAGENT_RESPONSE' && event.data.action === 'HF_DOWNLOAD_MODEL') {
                    var responseData = event.data.data || event.data.payload || {};
                    
                    // 如果响应中包含进度信息
                    if (responseData.progress !== undefined || responseData.downloaded !== undefined) {
                        downloadState.progress = responseData.progress || 0;
                        downloadState.downloadedSize = responseData.downloaded || downloadState.downloadedSize;
                        downloadState.totalSize = responseData.total || downloadState.totalSize;
                        downloadState.currentFile = responseData.filename || responseData.currentFile || downloadState.currentFile;
                        
                        console.log('[DownloadManager] RESPONSE 进度:', downloadState.progress, '%');
                        if (onProgress) onProgress(downloadState);
                    }
                    
                    // 如果下载完成
                    if (responseData.success === true && responseData.complete === true) {
                        console.log('[DownloadManager] RESPONSE 下载完成');
                        downloadState.isDownloading = false;
                        downloadState.progress = 100;
                        if (onProgress) onProgress(downloadState);
                        window.removeEventListener('message', messageHandler);
                        resolve({ success: true, modelId: modelId });
                    }
                    
                    // 如果下载失败
                    if (responseData.success === false || responseData.error) {
                        console.error('[DownloadManager] RESPONSE 下载失败:', responseData.error || responseData.message);
                        downloadState.isDownloading = false;
                        window.removeEventListener('message', messageHandler);
                        reject(new Error(responseData.error || responseData.message || '下载失败'));
                    }
                }
            };
            window.addEventListener('message', messageHandler);

            // 发送模型加载请求（加载过程中会自动下载模型）
            // 使用 MODEL_LOAD_REQUEST 类型，background 会处理并在 offscreen 中下载+加载模型
            var downloadRequestId = 'download_' + Date.now();
            console.log('[DownloadManager] 发送 MODEL_LOAD_REQUEST, requestId:', downloadRequestId);
            window.postMessage({
                type: 'MODEL_LOAD_REQUEST',
                modelId: modelId,
                requestId: downloadRequestId
            }, '*');
            
            // 同时监听模型加载进度事件（从 background 通过 content script 转发）
            var progressHandler = function(event) {
                if (event.source !== window) return;
                if (!event.data) return;
                
                // content script 转发的进度消息: AUTOLEADAGENT_MODEL_LOAD_PROGRESS
                if (event.data.type === 'AUTOLEADAGENT_MODEL_LOAD_PROGRESS') {
                    var payload = event.data.payload || {};
                    console.log('[DownloadManager] 收到加载进度:', JSON.stringify(payload));
                    
                    // 更新下载状态
                    if (payload.progress !== undefined) {
                        downloadState.progress = payload.progress;
                    }
                    if (payload.file) {
                        downloadState.currentFile = payload.file;
                    }
                    if (payload.loaded !== undefined && payload.total !== undefined) {
                        downloadState.downloadedSize = payload.loaded;
                        downloadState.totalSize = payload.total;
                        if (payload.total > 0) {
                            downloadState.progress = (payload.loaded / payload.total) * 100;
                        }
                    }
                    if (payload.status === 'download') {
                        downloadState.currentFile = payload.name || payload.file || '';
                    }
                    
                    if (onProgress) onProgress(downloadState);
                }
                
                // content script 转发的模型事件: AUTOLEADAGENT_MODEL_EVENT
                if (event.data.type === 'AUTOLEADAGENT_MODEL_EVENT') {
                    console.log('[DownloadManager] 收到模型事件:', event.data.event, event.data.data);
                    
                    if (event.data.event === 'loaded') {
                        // 模型加载完成
                        console.log('[DownloadManager] 模型加载完成');
                        clearTimeout(downloadTimeout);
                        downloadState.isDownloading = false;
                        downloadState.progress = 100;
                        if (onProgress) onProgress(downloadState);
                        window.removeEventListener('message', progressHandler);
                        window.removeEventListener('message', messageHandler);
                        resolve({ success: true, modelId: modelId });
                    } else if (event.data.event === 'load_error') {
                        console.error('[DownloadManager] 模型加载失败:', event.data.data);
                        clearTimeout(downloadTimeout);
                        downloadState.isDownloading = false;
                        window.removeEventListener('message', progressHandler);
                        window.removeEventListener('message', messageHandler);
                        reject(new Error(event.data.data && event.data.data.error || '模型加载失败'));
                    } else if (event.data.event === 'loading') {
                        console.log('[DownloadManager] 模型正在加载...');
                    }
                }
                
                // MODEL_LOAD_RESPONSE 是 MODEL_LOAD_REQUEST 的直接响应
                if (event.data.type === 'MODEL_LOAD_RESPONSE') {
                    console.log('[DownloadManager] 收到加载响应:', event.data);
                    if (event.data.error) {
                        console.error('[DownloadManager] 加载错误:', event.data.error);
                        // 不在这里 reject，等待 MODEL_EVENT 的 load_error
                    }
                }
            };
            window.addEventListener('message', progressHandler);
            
            // 设置超时（5分钟）
            var downloadTimeout = setTimeout(function() {
                if (downloadState.isDownloading) {
                    console.warn('[DownloadManager] 下载超时');
                    downloadState.isDownloading = false;
                    window.removeEventListener('message', progressHandler);
                    window.removeEventListener('message', messageHandler);
                    reject(new Error('下载超时，请检查网络连接'));
                }
            }, 300000);
        });
    }

    /**
     * 统一处理下载消息
     */
    async function handleDownloadMessage(message, modelId, onProgress, resolve, reject, cleanup) {
        if (message.modelId && message.modelId !== modelId) return;

        // 检查是否已取消
        if (downloadState.cancelled) {
            if (cleanup) cleanup();
            reject(new Error('下载已取消'));
            return;
        }

        switch (message.type) {
            case 'download-started':
                console.log('[DownloadManager] 下载已开始');
                break;

            case 'download-file-start':
                downloadState.currentFile = message.filename;
                downloadState.currentFileSize = message.size || 0;
                downloadState.currentFileDownloaded = 0;
                downloadState.currentFileProgress = 0;
                console.log('[DownloadManager] 开始下载文件:', message.filename, '大小:', message.size);
                if (onProgress) onProgress(downloadState);
                break;

            case 'download-progress':
                downloadState.totalSize = message.total || downloadState.totalSize;
                downloadState.downloadedSize = message.downloaded || downloadState.downloadedSize;
                downloadState.currentFile = message.filename || downloadState.currentFile;
                downloadState.progress = message.progress || 0;

                // 计算下载速度
                var currentTime = Date.now();
                var timeDiff = (currentTime - downloadState.lastUpdateTime) / 1000; // 秒

                // 初始化下载速度（如果是第一次更新）
                if (!downloadState.downloadSpeed) {
                    downloadState.downloadSpeed = 0;
                }

                // 计算速度（只要时间差大于 0.1 秒）
                if (timeDiff > 0.1 && downloadState.lastUpdateTime !== null) {
                    var sizeDiff = downloadState.downloadedSize - downloadState.lastDownloadedSize;
                    if (sizeDiff > 0) {
                        var newSpeed = sizeDiff / timeDiff; // bytes per second
                        // 使用平滑处理，避免速度跳动过大
                        downloadState.downloadSpeed = downloadState.downloadSpeed * 0.7 + newSpeed * 0.3;
                    }
                }

                downloadState.lastUpdateTime = currentTime;
                downloadState.lastDownloadedSize = downloadState.downloadedSize;

                // 更新当前文件进度
                if (message.fileSize) {
                    downloadState.currentFileSize = message.fileSize;
                }
                if (message.fileDownloaded !== undefined) {
                    downloadState.currentFileDownloaded = message.fileDownloaded;
                    if (downloadState.currentFileSize > 0) {
                        downloadState.currentFileProgress = (downloadState.currentFileDownloaded / downloadState.currentFileSize) * 100;
                    }
                }

                if (onProgress) onProgress(downloadState);
                break;

            case 'download-file-chunk':
            case 'download-file-data':
                // 流式保存文件块
                console.log('[DownloadManager] 收到文件块:', message.filename, '偏移:', message.offset, '大小:', message.data ? message.data.byteLength : 0, '完成:', message.isComplete);
                try {
                    if (message.data && message.data.byteLength > 0) {
                        // 兼容两种消息格式
                        const offset = message.offset !== undefined ? message.offset : 0;
                        const isComplete = message.isComplete !== undefined ? message.isComplete : (message.size > 0 && message.data.byteLength >= message.size);

                        // 保存到 LocalFileStorage
                        if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.saveModelFileChunk) {
                            await LocalFileStorage.saveModelFileChunk(
                                message.modelId,
                                message.filename,
                                message.data, // ArrayBuffer
                                offset,
                                isComplete
                            );
                        }

                        // 同时保存到 ModelStorage（用于备份和兼容）
                        if (isComplete && typeof ModelStorage !== 'undefined' && ModelStorage.saveModelFile) {
                            try {
                                await ModelStorage.saveModelFile(message.modelId, message.filename, message.data);
                                console.log('[DownloadManager] 文件已同步到 ModelStorage:', message.filename);
                            } catch (msError) {
                                console.warn('[DownloadManager] ModelStorage 保存失败:', msError);
                            }
                        }
                    }
                } catch (e) {
                    console.error('[DownloadManager] 保存文件块失败:', e);
                    if (!downloadState.cancelled) {
                        downloadState.isDownloading = false;
                        if (cleanup) cleanup();
                        reject(e);
                    }
                }
                break;

            case 'download-file-complete':
                console.log('[DownloadManager] 文件下载完成:', message.filename);
                downloadState.currentFileProgress = 100;
                if (onProgress) onProgress(downloadState);

                // 确保文件已完整保存（强制标记为完成）
                try {
                    if (message.data && message.data.byteLength > 0) {
                        // 如果消息中包含完整文件数据，保存它
                        if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.saveModelFile) {
                            await LocalFileStorage.saveModelFile(message.modelId, message.filename, message.data);
                            console.log('[DownloadManager] 完整文件已保存到 LocalFileStorage:', message.filename);
                        }
                        if (typeof ModelStorage !== 'undefined' && ModelStorage.saveModelFile) {
                            await ModelStorage.saveModelFile(message.modelId, message.filename, message.data);
                            console.log('[DownloadManager] 完整文件已保存到 ModelStorage:', message.filename);
                        }
                    }
                } catch (saveError) {
                    console.warn('[DownloadManager] 保存完整文件失败:', saveError);
                }
                break;

            case 'download-complete':
                downloadState.isDownloading = false;
                downloadState.progress = 100;
                if (onProgress) onProgress(downloadState);

                // 验证文件是否已正确保存
                console.log('[DownloadManager] 下载完成，验证缓存...');
                try {
                    if (typeof LocalFileStorage !== 'undefined') {
                        const stats = await LocalFileStorage.getStorageStats();
                        console.log('[DownloadManager] 缓存统计:', stats);

                        // 检查模型是否已缓存
                        const hasFiles = await LocalFileStorage.hasModelFiles(downloadState.currentModelId);
                        if (hasFiles) {
                            console.log('[DownloadManager] 模型已成功缓存到 IndexedDB');
                        } else {
                            console.warn('[DownloadManager] 警告：模型可能未完整缓存');
                        }

                        // 显示存储配额信息
                        const quota = await LocalFileStorage.getStorageQuota();
                        console.log('[DownloadManager] 存储配额:', (quota.usage / 1024 / 1024).toFixed(2), 'MB /', (quota.quota / 1024 / 1024 / 1024).toFixed(2), 'GB');
                    }
                } catch (verifyError) {
                    console.warn('[DownloadManager] 缓存验证失败:', verifyError);
                }

                // 下载完成后，更新模型列表中的下载状态
                if (typeof document !== 'undefined') {
                    var listBody = document.getElementById('hf_model_list_body');
                    if (listBody) {
                        var rows = listBody.querySelectorAll('.hf-model-row');
                        rows.forEach(function (row) {
                            var rowModelId = row.getAttribute('data-model-id');
                            if (rowModelId === downloadState.currentModelId) {
                                // 找到对应的模型行，更新状态
                                var actionCell = row.querySelector('td:last-child');
                                if (actionCell) {
                                    actionCell.innerHTML = '<span class="badge bg-success"><i class="mdi mdi-check"></i> 已下载</span>';
                                }
                            }
                        });
                    }

                    // 触发模型列表刷新事件
                    var refreshEvent = new CustomEvent('model-download-complete', {
                        detail: { modelId: downloadState.currentModelId }
                    });
                    window.dispatchEvent(refreshEvent);
                }

                ConfigUtils.safeToast('success', '模型下载完成，已缓存到本地');
                if (cleanup) cleanup();
                resolve(message);
                break;

            case 'download-error':
                downloadState.isDownloading = false;
                ConfigUtils.safeToast('error', '模型下载失败: ' + message.error);
                if (cleanup) cleanup();
                reject(new Error(message.error));
                break;

            case 'download-disconnected':
                if (downloadState.isDownloading && !downloadState.cancelled) {
                    downloadState.isDownloading = false;
                    if (cleanup) cleanup();
                    reject(new Error('下载连接已断开'));
                }
                break;
        }
    }

    return {
        startDownload: startDownload,
        cancelDownload: cancelDownload,
        getState: function () { return downloadState; }
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigDownloadManager = ConfigDownloadManager;
}
