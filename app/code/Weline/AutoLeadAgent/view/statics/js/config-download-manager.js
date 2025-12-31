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
        progress: 0
    };

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

            // 检查扩展 ID
            if (typeof chrome === 'undefined' || !chrome.runtime) {
                downloadState.isDownloading = false;
                reject(new Error('浏览器扩展不可用'));
                return;
            }

            // 建立 Port 连接
            var port = chrome.runtime.connect({ name: 'autoleadagent-download' });

            port.onMessage.addListener(async function (message) {
                if (message.modelId !== modelId) return;

                switch (message.type) {
                    case 'download-progress':
                        downloadState.totalSize = message.total;
                        downloadState.downloadedSize = message.downloaded;
                        downloadState.currentFile = message.filename;
                        downloadState.progress = message.progress;
                        if (onProgress) onProgress(downloadState);
                        break;

                    case 'download-file-chunk':
                        // 处理文件块
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
                            ConfigUtils.safeToast('error', '保存文件块失败: ' + e.message);
                        }
                        break;

                    case 'download-complete':
                        downloadState.isDownloading = false;
                        ConfigUtils.safeToast('success', '模型下载完成');
                        port.disconnect();
                        resolve(message);
                        break;

                    case 'download-error':
                        downloadState.isDownloading = false;
                        ConfigUtils.safeToast('error', '模型下载失败: ' + message.error);
                        port.disconnect();
                        reject(new Error(message.error));
                        break;
                }
            });

            port.onDisconnect.addListener(function() {
                if (downloadState.isDownloading) {
                    downloadState.isDownloading = false;
                    reject(new Error('下载连接意外断开'));
                }
            });

            // 发送下载指令
            port.postMessage({
                type: 'HF_DOWNLOAD_MODEL',
                modelId: modelId
            });
        });
    }

    return {
        startDownload: startDownload,
        getState: function() { return downloadState; }
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigDownloadManager = ConfigDownloadManager;
}
