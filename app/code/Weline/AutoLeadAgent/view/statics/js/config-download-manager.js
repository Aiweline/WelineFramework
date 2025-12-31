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
        downloadPort: null // 用于取消下载的 Port 连接
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
        return new Promise(function (resolve, reject) {
            if (downloadState.isDownloading) {
                reject(new Error('已有下载任务正在运行'));
                return;
            }

            console.log('[DownloadManager] 开始下载模型:', modelId);
            downloadState.isDownloading = true;
            downloadState.currentModelId = modelId;
            downloadState.downloadedSize = 0;
            downloadState.currentFileSize = 0;
            downloadState.currentFileDownloaded = 0;
            downloadState.currentFileProgress = 0;
            downloadState.cancelled = false;
            downloadState.downloadPort = null;

            // 设置监听器处理来自 content script 的下载消息
            var messageHandler = function(event) {
                if (event.source !== window) return;
                if (event.data && event.data.type === 'AUTOLEADAGENT_DOWNLOAD_MESSAGE') {
                    // 检查是否已取消
                    if (downloadState.cancelled) {
                        if (cleanup) cleanup();
                        reject(new Error('下载已取消'));
                        return;
                    }
                    
                    handleDownloadMessage(event.data.data, modelId, onProgress, resolve, reject, function() {
                        window.removeEventListener('message', messageHandler);
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
                // 流式保存文件块
                try {
                    if (typeof LocalFileStorage !== 'undefined' && LocalFileStorage.saveModelFileChunk) {
                        await LocalFileStorage.saveModelFileChunk(
                            message.modelId,
                            message.filename,
                            message.data, // ArrayBuffer
                            message.offset,
                            message.isComplete
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
        getState: function() { return downloadState; }
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigDownloadManager = ConfigDownloadManager;
}
