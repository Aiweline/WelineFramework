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

            // 用于存储 cleanup 回调
            var cleanupCallback = null;

            // 设置监听器处理来自 content script 的下载消息
            var messageHandler = function (event) {
                if (event.source !== window) return;
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
            };
            window.addEventListener('message', messageHandler);

            // 发送下载请求到 content script
            // content.js 会处理此请求并建立 Port 连接到后台
            window.postMessage({
                type: 'AUTOLEADAGENT_REQUEST',
                action: 'HF_DOWNLOAD_MODEL',
                payload: { modelId: modelId },
                requestId: 'download_' + Date.now()
            }, '*');
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
                    if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.saveModelFileChunk) {
                        // 兼容两种消息格式
                        const offset = message.offset !== undefined ? message.offset : 0;
                        const isComplete = message.isComplete !== undefined ? message.isComplete : (message.size > 0 && message.data && message.data.byteLength >= message.size);

                        await LocalFileStorage.saveModelFileChunk(
                            message.modelId,
                            message.filename,
                            message.data, // ArrayBuffer
                            offset,
                            isComplete
                        );
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
                break;

            case 'download-complete':
                downloadState.isDownloading = false;
                downloadState.progress = 100;
                if (onProgress) onProgress(downloadState);

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

                ConfigUtils.safeToast('success', '模型下载完成');
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
