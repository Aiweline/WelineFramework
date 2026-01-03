/**
 * 本地文件系统存储管理
 * 使用 File System Access API 或下载到本地文件系统
 * 模型文件持久化存储在 IndexedDB 中，下次打开页面可直接使用
 */

var LocalFileStorage = (function () {
    'use strict';

    // IndexedDB 数据库名称和版本
    const DB_NAME = 'AutoLeadAgentModels';
    const DB_VERSION = 1;

    // 存储模型目录句柄（使用 File System Access API，仅内存中）
    var modelDirectories = new Map();

    // 存储缓存的目录句柄（仅内存中，用于同一会话）
    var cachedDirectoryHandle = null;

    // 存储当前正在写入的文件流
    var activeWritables = new Map();

    // 存储父目录句柄（用于记住用户选择的项目目录）
    var parentDirectoryHandle = null;

    // 存储 pub/models 目录句柄（用于下次默认打开）
    var pubModelsDirectoryHandle = null;

    // 存储模型元数据（使用 localStorage）
    const METADATA_KEY = 'autoleadagent_model_metadata';

    // 存储目录路径映射（使用 localStorage）
    const DIRECTORY_PATH_KEY = 'autoleadagent_model_directory_path';

    /**
     * 打开 IndexedDB 数据库
     * @returns {Promise<IDBDatabase>}
     */
    function openModelDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;

                // 创建模型文件存储
                if (!db.objectStoreNames.contains('modelFiles')) {
                    const fileStore = db.createObjectStore('modelFiles', { keyPath: 'id' });
                    fileStore.createIndex('modelId', 'modelId', { unique: false });
                    fileStore.createIndex('filename', 'filename', { unique: false });
                }

                // 创建模型元数据存储
                if (!db.objectStoreNames.contains('modelMetadata')) {
                    db.createObjectStore('modelMetadata', { keyPath: 'modelId' });
                }
            };
        });
    }

    /**
     * 保存文件到 IndexedDB
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {ArrayBuffer} data 文件数据
     * @returns {Promise<void>}
     */
    async function saveFileToIndexedDB(modelId, filename, data) {
        const db = await openModelDB();
        const id = `${modelId}/${filename}`;

        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['modelFiles'], 'readwrite');
            const store = transaction.objectStore('modelFiles');

            const record = {
                id: id,
                modelId: modelId,
                filename: filename,
                data: data,
                size: data ? data.byteLength : 0,
                savedAt: Date.now()
            };

            const request = store.put(record);
            request.onsuccess = () => {
                console.log('[LocalFileStorage] 文件已保存到 IndexedDB:', filename);
                resolve();
            };
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * 从 IndexedDB 读取文件
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<ArrayBuffer|null>}
     */
    async function getFileFromIndexedDB(modelId, filename) {
        const db = await openModelDB();
        const id = `${modelId}/${filename}`;

        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['modelFiles'], 'readonly');
            const store = transaction.objectStore('modelFiles');
            const request = store.get(id);

            request.onsuccess = () => {
                if (request.result) {
                    resolve(request.result.data);
                } else {
                    resolve(null);
                }
            };
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * 检查文件是否在 IndexedDB 中存在
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<boolean>}
     */
    async function hasFileInIndexedDB(modelId, filename) {
        const db = await openModelDB();
        const id = `${modelId}/${filename}`;

        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['modelFiles'], 'readonly');
            const store = transaction.objectStore('modelFiles');
            const request = store.get(id);

            request.onsuccess = () => {
                resolve(!!request.result);
            };
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * 获取模型的所有文件
     * @param {string} modelId 模型ID
     * @returns {Promise<Array<{filename: string, size: number}>>}
     */
    async function getModelFilesFromIndexedDB(modelId) {
        const db = await openModelDB();

        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['modelFiles'], 'readonly');
            const store = transaction.objectStore('modelFiles');
            const index = store.index('modelId');
            const request = index.getAll(modelId);

            request.onsuccess = () => {
                const files = request.result.map(r => ({
                    filename: r.filename,
                    size: r.size
                }));
                resolve(files);
            };
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * 删除模型的所有文件
     * @param {string} modelId 模型ID
     * @returns {Promise<number>} 删除的文件数量
     */
    async function deleteModelFilesFromIndexedDB(modelId) {
        const db = await openModelDB();

        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['modelFiles'], 'readwrite');
            const store = transaction.objectStore('modelFiles');
            const index = store.index('modelId');
            const request = index.getAllKeys(modelId);

            request.onsuccess = () => {
                const keys = request.result;
                let deletedCount = 0;

                keys.forEach(key => {
                    const deleteRequest = store.delete(key);
                    deleteRequest.onsuccess = () => {
                        deletedCount++;
                    };
                });

                transaction.oncomplete = () => {
                    console.log('[LocalFileStorage] 已从 IndexedDB 删除', deletedCount, '个文件');
                    resolve(deletedCount);
                };
            };
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * 保存目录路径到 localStorage
     * @param {string} directoryPath 目录路径
     */
    function saveDirectoryPath(directoryPath) {
        try {
            localStorage.setItem(DIRECTORY_PATH_KEY, directoryPath);
            console.log('[LocalFileStorage] 目录路径已保存:', directoryPath);
        } catch (error) {
            console.warn('[LocalFileStorage] 保存目录路径失败:', error);
        }
    }

    /**
     * 获取保存的目录路径
     * @returns {string|null}
     */
    function getDirectoryPath() {
        try {
            return localStorage.getItem(DIRECTORY_PATH_KEY);
        } catch (error) {
            return null;
        }
    }

    /**
     * 清理模型ID，使其可以作为文件系统目录名
     * @param {string} modelId 原始模型ID（如 "sentence-transformers/all-MiniLM-L6-v2"）
     * @returns {string} 清理后的目录名（如 "sentence-transformers_all-MiniLM-L6-v2"）
     */
    function sanitizeModelIdForPath(modelId) {
        if (!modelId) return '';
        // 替换文件系统不允许的字符：/ \ : * ? " < > |
        // 将 / 替换为 _，其他非法字符也替换为 _
        return modelId.replace(/[\/\\:*?"<>|]/g, '_');
    }

    /**
     * 检查是否支持 File System Access API
     */
    function supportsFileSystemAccess() {
        return 'showDirectoryPicker' in window;
    }

    /**
     * 检查文件系统权限
     * @param {string} modelId 模型ID
     * @returns {Promise<{granted: boolean, needsPermission: boolean, message?: string}>} 权限状态
     */
    async function checkFileSystemPermission(modelId) {
        if (!supportsFileSystemAccess()) {
            return {
                granted: false,
                needsPermission: false,
                message: '浏览器不支持 File System Access API。请使用 Chrome 86+ 或 Edge 86+ 浏览器。'
            };
        }

        // 如果已缓存目录句柄，检查权限
        if (modelDirectories.has(modelId)) {
            try {
                const directoryHandle = modelDirectories.get(modelId);
                // 尝试访问目录以验证权限
                await directoryHandle.getFileHandle('__permission_check__', { create: false }).catch(() => {
                    // 文件不存在是正常的，说明权限有效
                });
                return {
                    granted: true,
                    needsPermission: false
                };
            } catch (error) {
                // 权限可能已失效，需要重新获取
                modelDirectories.delete(modelId);
                return {
                    granted: false,
                    needsPermission: true,
                    message: '文件系统权限已失效，需要重新选择目录'
                };
            }
        }

        return {
            granted: false,
            needsPermission: true,
            message: '需要选择文件系统目录以保存模型文件'
        };
    }

    /**
     * 获取或创建模型目录
     * @param {string} modelId 模型ID
     * @param {boolean} requestPermission 如果需要权限，是否请求用户选择目录
     * @returns {Promise<FileSystemDirectoryHandle>} 目录句柄
     */
    async function getModelDirectory(modelId, requestPermission) {
        requestPermission = requestPermission !== false; // 默认请求权限

        // 如果已缓存，直接返回
        if (modelDirectories.has(modelId)) {
            try {
                const directoryHandle = modelDirectories.get(modelId);
                // 验证权限是否仍然有效
                await directoryHandle.getFileHandle('__permission_check__', { create: false }).catch(() => {
                    // 文件不存在是正常的，说明权限有效
                });
                return directoryHandle;
            } catch (error) {
                // 权限可能已失效，清除缓存
                console.warn('[LocalFileStorage] 目录句柄权限已失效，需要重新选择:', error);
                modelDirectories.delete(modelId);
            }
        }

        // 检查是否支持 File System Access API
        if (!supportsFileSystemAccess()) {
            throw new Error('浏览器不支持 File System Access API。请使用 Chrome 86+ 或 Edge 86+ 浏览器。');
        }

        // 首先检查是否有配置的缓存目录句柄（内存中）
        if (cachedDirectoryHandle) {
            console.log('[LocalFileStorage] 使用配置的缓存目录（内存中）:', cachedDirectoryHandle.name);
            try {
                // 验证句柄是否仍然有效
                await cachedDirectoryHandle.getDirectoryHandle('__permission_check__', { create: false }).catch(() => {
                    // 目录不存在是正常的，说明句柄有效
                });

                // 在配置的缓存目录中创建模型子目录
                const sanitizedModelId = sanitizeModelIdForPath(modelId);
                const modelDirHandle = await cachedDirectoryHandle.getDirectoryHandle(sanitizedModelId, { create: true });

                // 缓存目录句柄
                modelDirectories.set(modelId, modelDirHandle);

                // 保存目录句柄（使用 Storage API）
                try {
                    await navigator.storage.persist();
                } catch (error) {
                    console.warn('[LocalFileStorage] 无法持久化存储权限:', error);
                }

                return modelDirHandle;
            } catch (error) {
                console.warn('[LocalFileStorage] 使用配置的缓存目录失败:', error);
                // 句柄可能已失效，清除内存中的句柄
                cachedDirectoryHandle = null;
                console.log('[LocalFileStorage] 已清除失效的目录句柄');
                // 继续执行后续逻辑，让用户重新选择目录
            }
        }

        // 如果不需要请求权限，但也没有缓存的句柄，抛出友好的错误
        if (!requestPermission) {
            throw new Error('需要先选择文件保存位置。请在点击"保存"按钮时选择保存目录。');
        }

        try {
            // File System Access API 不支持持久化目录句柄
            // 每次都需要用户重新选择目录
            // 这是浏览器的安全限制

            // 提示用户选择或创建目录
            // 优先使用之前保存的 pub/models 目录句柄作为起始位置
            let directoryHandle;
            let startInOption = null; // 默认让浏览器决定，优先使用保存的句柄

            // 如果之前保存了 pub/models 目录句柄，优先使用它作为起始位置
            if (pubModelsDirectoryHandle) {
                try {
                    // 验证句柄是否仍然有效（尝试访问目录）
                    await pubModelsDirectoryHandle.getDirectoryHandle('__test__', { create: false }).catch(() => {
                        // 文件不存在是正常的，说明句柄有效
                    });
                    // 使用保存的 pub/models 目录句柄作为起始位置
                    startInOption = pubModelsDirectoryHandle;
                    console.log('[LocalFileStorage] 使用之前保存的 pub/models 目录作为起始位置');
                } catch (error) {
                    // 句柄已失效，清除缓存
                    console.warn('[LocalFileStorage] pub/models 目录句柄已失效，重新选择:', error);
                    pubModelsDirectoryHandle = null;
                }
            }

            // 如果之前保存了父目录句柄，也尝试使用它
            if (!startInOption) {
                if (parentDirectoryHandle) {
                    try {
                        // 验证句柄是否仍然有效
                        await parentDirectoryHandle.getDirectoryHandle('__test__', { create: false }).catch(() => {
                            // 文件不存在是正常的，说明句柄有效
                        });
                        startInOption = parentDirectoryHandle;
                        console.log('[LocalFileStorage] 使用之前保存的父目录作为起始位置');
                    } catch (error) {
                        // 句柄已失效，清除缓存
                        console.warn('[LocalFileStorage] 父目录句柄已失效，重新选择:', error);
                        parentDirectoryHandle = null;
                    }
                }
            }

            // 如果没有保存的句柄，使用 'documents' 作为默认起始位置
            // 用户可以在对话框中选择包含 pub/models 的项目目录
            if (!startInOption) {
                startInOption = 'documents';
            }

            // 打开文件选择对话框
            directoryHandle = await window.showDirectoryPicker({
                mode: 'readwrite',
                startIn: startInOption
            });

            // 记住父目录句柄
            parentDirectoryHandle = directoryHandle;

            // 在选择的目录中查找或创建 pub/models 目录
            let modelsDirHandle;
            try {
                // 首先尝试查找 pub/models 目录
                const pubDirHandle = await directoryHandle.getDirectoryHandle('pub', { create: false });
                modelsDirHandle = await pubDirHandle.getDirectoryHandle('models', { create: true });
                // 保存 pub/models 目录句柄，下次默认打开这个目录
                pubModelsDirectoryHandle = modelsDirHandle;
                // 也保存父目录句柄（包含 pub 的目录），以便后续使用
                parentDirectoryHandle = directoryHandle;
                console.log('[LocalFileStorage] 找到 pub/models 目录，已保存句柄，下次将默认打开此目录');
            } catch (error) {
                // 如果 pub 目录不存在，检查用户是否直接选择了 pub/models 目录
                try {
                    // 检查当前目录是否是 models 目录（可能是用户直接选择了 pub/models）
                    const currentDirName = directoryHandle.name;
                    if (currentDirName === 'models') {
                        // 用户可能直接选择了 models 目录（可能是 pub/models）
                        modelsDirHandle = directoryHandle;
                        // 如果当前就是 models 目录，直接使用并保存
                        pubModelsDirectoryHandle = modelsDirHandle;
                        // 也保存父目录句柄
                        parentDirectoryHandle = directoryHandle;
                        console.log('[LocalFileStorage] 用户直接选择了 models 目录，已保存句柄，下次将默认打开此目录');
                    } else {
                        // 在选择的目录下创建 models 目录
                        modelsDirHandle = await directoryHandle.getDirectoryHandle('models', { create: true });
                        console.log('[LocalFileStorage] 在选择的目录下创建了 models 目录');
                    }
                } catch (e) {
                    // 如果都失败，直接在选择的目录下创建模型子目录
                    modelsDirHandle = directoryHandle;
                    console.warn('[LocalFileStorage] 无法创建 models 目录，直接在选择的目录下创建模型子目录');
                }
            }

            // 在 models 目录中创建模型子目录
            // 清理模型ID，将特殊字符（如 /）替换为安全的字符
            const sanitizedModelId = sanitizeModelIdForPath(modelId);
            const modelDirHandle = await modelsDirHandle.getDirectoryHandle(sanitizedModelId, { create: true });

            // 缓存目录句柄（使用原始 modelId 作为键，以便后续查找）
            modelDirectories.set(modelId, modelDirHandle);

            // 同时更新 cachedDirectoryHandle（用于 getCachedDirectoryHandle 检查）
            cachedDirectoryHandle = modelsDirHandle;
            console.log('[LocalFileStorage] 已更新 cachedDirectoryHandle:', modelsDirHandle.name);

            // 保存目录句柄（使用 Storage API）
            try {
                await navigator.storage.persist();
            } catch (error) {
                console.warn('[LocalFileStorage] 无法持久化存储权限:', error);
            }

            return modelDirHandle;
        } catch (error) {
            if (error.name === 'AbortError') {
                throw new Error('用户取消了目录选择');
            }
            if (error.name === 'SecurityError' || (error.message && error.message.includes('user gesture'))) {
                throw new Error('需要用户交互才能选择文件保存位置。请点击"保存"按钮后，在弹出的对话框中选择保存目录。');
            }
            throw error;
        }
    }

    /**
     * 保存模型文件块（用于流式下载）
     * 优先存储到 IndexedDB 以实现持久化
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名（可能包含子目录，如 "1_Pooling/config.json"）
     * @param {ArrayBuffer} data 数据块
     * @param {number} offset 偏移量
     * @param {boolean} isComplete 是否是最后一块
     */
    async function saveModelFileChunk(modelId, filename, data, offset, isComplete) {
        try {
            const cacheKey = `${modelId}/${filename}`;

            console.log('[LocalFileStorage] 保存文件块:', modelId, filename, '偏移:', offset, '大小:', data ? data.byteLength : 0, '完成:', isComplete);

            // 检查是否已经有缓存的数据
            var cachedData = null;
            var cachedBuffers = null;

            if (!activeWritables.has(cacheKey)) {
                // 检查 IndexedDB 中是否已有部分数据
                cachedData = await getFileFromIndexedDB(modelId, filename);
                if (cachedData && cachedData.byteLength > 0) {
                    console.log('[LocalFileStorage] 从 IndexedDB 读取已存在的文件数据，大小:', cachedData.byteLength);
                    cachedBuffers = [cachedData];
                }
            }

            // 合并数据块
            if (data && data.byteLength > 0) {
                if (cachedBuffers) {
                    // 合并现有数据和新数据
                    const newData = new Uint8Array(offset + data.byteLength);
                    newData.set(new Uint8Array(cachedData), 0);
                    newData.set(new Uint8Array(data), offset);
                    cachedData = newData.buffer;
                    cachedBuffers = [cachedData];
                    console.log('[LocalFileStorage] 合并数据后大小:', cachedData.byteLength);
                } else if (offset === 0) {
                    // 新文件，直接使用
                    cachedData = data;
                    cachedBuffers = [data];
                } else {
                    // 偏移不为0但没有缓存数据，这可能是第一个块
                    cachedData = data;
                    cachedBuffers = [data];
                }
            }

            // 如果是最后一块，保存到 IndexedDB
            if (isComplete && cachedData) {
                await saveFileToIndexedDB(modelId, filename, cachedData);
                console.log('[LocalFileStorage] 文件已保存到 IndexedDB:', filename, '大小:', cachedData.byteLength);

                // 更新元数据
                await updateModelMetadata(modelId, filename, cachedData.byteLength);

                // 清除内存缓存
                activeWritables.delete(cacheKey);
            } else if (cachedData) {
                // 临时存储到内存（下次调用会继续合并）
                // 使用 Map 存储 ArrayBuffer
                if (!cachedBuffers) {
                    cachedBuffers = [cachedData];
                }
                activeWritables.set(cacheKey, {
                    buffers: cachedBuffers,
                    totalSize: cachedData.byteLength
                });
            }

        } catch (error) {
            console.error('[LocalFileStorage] 保存文件块失败:', modelId, filename, error);
            const cacheKey = `${modelId}/${filename}`;
            activeWritables.delete(cacheKey);
            throw error;
        }
    }

    /**
     * 保存模型文件到本地文件系统
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {ArrayBuffer|Blob} data 文件数据
     * @returns {Promise<void>}
     */
    async function saveModelFile(modelId, filename, data) {
        try {
            const fileSize = data instanceof Blob ? data.size : data.byteLength;
            console.log('[LocalFileStorage] 保存文件到本地:', modelId, filename, (fileSize / 1024 / 1024).toFixed(2), 'MB');

            if (supportsFileSystemAccess()) {
                // 检查权限
                const permission = await checkFileSystemPermission(modelId);
                if (!permission.granted && permission.needsPermission) {
                    // 需要请求权限，但此时可能不在用户手势上下文中
                    console.log('[LocalFileStorage] 需要文件系统权限，尝试获取目录句柄...');
                }

                // 尝试获取目录句柄（如果已缓存则直接使用，否则会抛出友好的错误）
                let directoryHandle;
                try {
                    directoryHandle = await getModelDirectory(modelId, false);
                } catch (error) {
                    // 如果获取失败，说明需要用户先选择目录
                    var errorMsg = error.message || error.toString();
                    if (errorMsg.includes('需要先选择') || errorMsg.includes('需要用户交互') || errorMsg.includes('user gesture')) {
                        throw new Error('需要先选择文件保存位置。请重新点击"保存"按钮，然后在弹出的对话框中选择保存目录。');
                    }
                    throw error;
                }
                const fileHandle = await directoryHandle.getFileHandle(filename, { create: true });
                const writable = await fileHandle.createWritable();

                if (data instanceof Blob) {
                    await writable.write(data);
                } else {
                    await writable.write(data);
                }

                await writable.close();

                console.log('[LocalFileStorage] 文件已保存:', filename);

                // 验证文件大小
                const savedFile = await fileHandle.getFile();
                if (savedFile.size !== fileSize) {
                    console.warn('[LocalFileStorage] 文件大小不匹配: 期望', fileSize, '实际', savedFile.size);
                }

                // 更新元数据
                await updateModelMetadata(modelId, filename, fileSize);
            } else {
                // 降级方案：下载文件到本地
                const blob = data instanceof Blob ? data : new Blob([data], { type: 'application/octet-stream' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `${modelId}/${filename}`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                console.log('[LocalFileStorage] 文件已下载到本地:', filename);

                // 更新元数据
                await updateModelMetadata(modelId, filename, fileSize);
            }
        } catch (error) {
            console.error('[LocalFileStorage] 保存文件失败:', error);
            throw error;
        }
    }

    /**
     * 从本地文件系统读取模型文件
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<ArrayBuffer|null>} 文件数据
     */
    async function getModelFile(modelId, filename) {
        try {
            // 首先尝试从 IndexedDB 读取（持久化存储）
            console.log('[LocalFileStorage] 尝试从 IndexedDB 读取文件:', modelId, filename);
            const indexedDBData = await getFileFromIndexedDB(modelId, filename);
            if (indexedDBData && indexedDBData.byteLength > 0) {
                console.log('[LocalFileStorage] 从 IndexedDB 读取成功:', filename, (indexedDBData.byteLength / 1024 / 1024).toFixed(2), 'MB');
                return indexedDBData;
            }

            // 如果 IndexedDB 中没有，尝试从文件系统读取
            console.log('[LocalFileStorage] IndexedDB 中没有文件，尝试从文件系统读取:', filename);

            if (supportsFileSystemAccess()) {
                // 检查权限
                const permission = await checkFileSystemPermission(modelId);
                if (!permission.granted && permission.needsPermission) {
                    throw new Error('文件系统权限未授予。请先选择目录以授予权限。');
                }

                const directoryHandle = await getModelDirectory(modelId, false);
                if (!directoryHandle) {
                    throw new Error('无法获取模型目录句柄');
                }

                const fileHandle = await directoryHandle.getFileHandle(filename, { create: false });
                const file = await fileHandle.getFile();

                // 验证文件大小
                if (file.size === 0) {
                    console.warn('[LocalFileStorage] 文件大小为0:', filename);
                }

                const arrayBuffer = await file.arrayBuffer();
                console.log('[LocalFileStorage] 从文件系统读取成功:', filename, (arrayBuffer.byteLength / 1024 / 1024).toFixed(2), 'MB');
                return arrayBuffer;
            } else {
                // 降级方案：提示用户选择文件
                throw new Error('浏览器不支持 File System Access API，无法自动读取文件。请使用 Chrome 86+ 或 Edge 86+ 浏览器。');
            }
        } catch (error) {
            if (error.name === 'NotFoundError') {
                console.log('[LocalFileStorage] 文件不存在:', filename);
                return null;
            }
            if (error.name === 'NotAllowedError' || error.message.includes('权限')) {
                console.error('[LocalFileStorage] 文件系统权限错误:', error.message);
                throw new Error('文件系统权限被拒绝。请重新选择目录并授予权限。');
            }
            console.error('[LocalFileStorage] 读取文件失败:', error);
            throw error;
        }
    }

    /**
     * 检查模型文件是否存在
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<boolean>} 是否存在
     */
    async function hasModelFile(modelId, filename) {
        try {
            if (supportsFileSystemAccess()) {
                const directoryHandle = await getModelDirectory(modelId);
                try {
                    await directoryHandle.getFileHandle(filename, { create: false });
                    return true;
                } catch (error) {
                    if (error.name === 'NotFoundError') {
                        return false;
                    }
                    throw error;
                }
            } else {
                // 降级方案：检查元数据
                const metadata = getModelMetadata();
                const modelData = metadata[modelId];
                if (!modelData || !modelData.files) {
                    return false;
                }
                return modelData.files.some(file => file.filename === filename);
            }
        } catch (error) {
            console.error('[LocalFileStorage] 检查文件是否存在失败:', error);
            return false;
        }
    }

    /**
     * 检查模型是否已下载（检查所有必需文件是否存在）
     * @param {string} modelId 模型ID
     * @returns {Promise<boolean>} 是否已下载
     */
    async function hasModelFiles(modelId) {
        try {
            const metadata = getModelMetadata();
            const modelData = metadata[modelId];

            if (!modelData || !modelData.files || modelData.files.length === 0) {
                return false;
            }

            // 检查所有文件是否都存在
            for (const file of modelData.files) {
                const exists = await hasModelFile(modelId, file.filename);
                if (!exists) {
                    console.log('[LocalFileStorage] 文件不存在:', modelId, file.filename);
                    return false;
                }
            }

            return true;
        } catch (error) {
            console.error('[LocalFileStorage] 检查模型文件失败:', error);
            return false;
        }
    }

    /**
     * 获取模型元数据
     * @returns {Object} 元数据对象
     */
    function getModelMetadata() {
        try {
            const metadataStr = localStorage.getItem(METADATA_KEY);
            return metadataStr ? JSON.parse(metadataStr) : {};
        } catch (error) {
            console.error('[LocalFileStorage] 获取元数据失败:', error);
            return {};
        }
    }

    /**
     * 保存模型元数据
     * @param {Object} metadata 元数据对象
     */
    function saveModelMetadata(metadata) {
        try {
            localStorage.setItem(METADATA_KEY, JSON.stringify(metadata));
        } catch (error) {
            console.error('[LocalFileStorage] 保存元数据失败:', error);
            throw error;
        }
    }

    /**
     * 更新模型元数据（添加或更新文件信息）
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {number} size 文件大小
     */
    async function updateModelMetadata(modelId, filename, size) {
        const metadata = getModelMetadata();

        if (!metadata[modelId]) {
            metadata[modelId] = {
                modelId: modelId,
                files: [],
                totalSize: 0,
                fileCount: 0,
                createdAt: Date.now(),
                lastAccessed: Date.now()
            };
        }

        const modelData = metadata[modelId];

        // 检查文件是否已存在
        const existingFileIndex = modelData.files.findIndex(file => file.filename === filename);

        if (existingFileIndex >= 0) {
            // 更新现有文件
            const oldSize = modelData.files[existingFileIndex].size;
            modelData.files[existingFileIndex].size = size;
            modelData.files[existingFileIndex].updatedAt = Date.now();
            modelData.totalSize = modelData.totalSize - oldSize + size;
        } else {
            // 添加新文件
            modelData.files.push({
                filename: filename,
                size: size,
                savedAt: Date.now()
            });
            modelData.totalSize += size;
            modelData.fileCount = modelData.files.length;
        }

        modelData.lastAccessed = Date.now();

        saveModelMetadata(metadata);

        console.log('[LocalFileStorage] 元数据已更新:', modelId, '文件数:', modelData.fileCount, '总大小:', (modelData.totalSize / 1024 / 1024).toFixed(2), 'MB');
    }

    /**
     * 收集模型的所有文件
     * @param {string} modelId 模型ID
     * @returns {Promise<Array>} 文件列表 [{filename, size}, ...]
     */
    async function collectModelFiles(modelId) {
        // 首先尝试从 IndexedDB 获取文件列表
        try {
            const indexedDBFiles = await getModelFilesFromIndexedDB(modelId);
            if (indexedDBFiles && indexedDBFiles.length > 0) {
                console.log('[LocalFileStorage] 从 IndexedDB 获取文件列表:', indexedDBFiles.length, '个文件');
                return indexedDBFiles;
            }
        } catch (error) {
            console.warn('[LocalFileStorage] 从 IndexedDB 获取文件列表失败:', error);
        }

        // 降级到从元数据获取
        const metadata = getModelMetadata();
        const modelData = metadata[modelId];

        if (!modelData || !modelData.files) {
            return [];
        }

        return modelData.files.map(file => ({
            filename: file.filename,
            size: file.size
        }));
    }

    /**
     * 删除模型（包括所有文件）
     * @param {string} modelId 模型ID
     * @returns {Promise<{deletedFiles: number, deletedSize: number}>}
     */
    async function deleteModel(modelId) {
        try {
            const metadata = getModelMetadata();
            const modelData = metadata[modelId];

            if (!modelData) {
                return { deletedFiles: 0, deletedSize: 0 };
            }

            let deletedFiles = 0;
            let deletedSize = 0;

            // 首先从 IndexedDB 删除文件（持久化存储）
            try {
                const indexedDBFiles = await getModelFilesFromIndexedDB(modelId);
                if (indexedDBFiles && indexedDBFiles.length > 0) {
                    const deletedFromIndexedDB = await deleteModelFilesFromIndexedDB(modelId);
                    deletedFiles = deletedFromIndexedDB;
                    deletedSize = indexedDBFiles.reduce((sum, f) => sum + f.size, 0);
                    console.log('[LocalFileStorage] 已从 IndexedDB 删除', deletedFromIndexedDB, '个文件');
                }
            } catch (error) {
                console.warn('[LocalFileStorage] 从 IndexedDB 删除文件失败:', error);
            }

            // 尝试从文件系统删除
            if (supportsFileSystemAccess()) {
                try {
                    const directoryHandle = await getModelDirectory(modelId);

                    // 删除所有文件
                    for (const file of modelData.files) {
                        try {
                            await directoryHandle.removeEntry(file.filename);
                            deletedFiles++;
                            deletedSize += file.size;
                        } catch (error) {
                            console.warn('[LocalFileStorage] 删除文件失败:', file.filename, error);
                        }
                    }

                    // 尝试删除目录（如果为空）
                    try {
                        const parentHandle = await directoryHandle.getParent();
                        await parentHandle.removeEntry(modelId);
                    } catch (error) {
                        // 目录可能不为空或无法删除，忽略错误
                        console.warn('[LocalFileStorage] 删除目录失败:', error);
                    }
                } catch (error) {
                    console.warn('[LocalFileStorage] 无法访问目录，可能已被删除:', error);
                }
            }

            // 从元数据中删除
            delete metadata[modelId];
            saveModelMetadata(metadata);

            // 清除缓存的目录句柄
            modelDirectories.delete(modelId);

            console.log('[LocalFileStorage] 模型已删除:', modelId, '文件数:', deletedFiles, '总大小:', (deletedSize / 1024 / 1024).toFixed(2), 'MB');

            return {
                deletedFiles: deletedFiles,
                deletedSize: deletedSize
            };
        } catch (error) {
            console.error('[LocalFileStorage] 删除模型失败:', error);
            throw error;
        }
    }

    /**
     * 获取模型元数据信息
     * @param {string} modelId 模型ID
     * @returns {Promise<Object|null>} 元数据
     */
    async function getModelMetadataInfo(modelId) {
        const metadata = getModelMetadata();
        const modelData = metadata[modelId];

        if (!modelData) {
            return null;
        }

        return {
            modelId: modelData.modelId,
            files: modelData.files || [],
            totalSize: modelData.totalSize || 0,
            fileCount: modelData.fileCount || 0,
            createdAt: modelData.createdAt,
            lastAccessed: modelData.lastAccessed
        };
    }

    /**
     * 获取文件大小
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<number>} 文件大小（字节），如果文件不存在返回0
     */
    async function getFileSize(modelId, filename) {
        try {
            if (supportsFileSystemAccess()) {
                const directoryHandle = await getModelDirectory(modelId);
                try {
                    const fileHandle = await directoryHandle.getFileHandle(filename, { create: false });
                    const file = await fileHandle.getFile();
                    return file.size;
                } catch (error) {
                    if (error.name === 'NotFoundError') {
                        return 0;
                    }
                    throw error;
                }
            } else {
                // 降级方案：从元数据获取
                const metadata = getModelMetadata();
                const modelData = metadata[modelId];
                if (modelData && modelData.files) {
                    const fileInfo = modelData.files.find(f => f.filename === filename);
                    return fileInfo ? (fileInfo.size || 0) : 0;
                }
                return 0;
            }
        } catch (error) {
            console.error('[LocalFileStorage] 获取文件大小失败:', error);
            return 0;
        }
    }

    /**
     * 检查文件完整性
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {number} expectedSize 预期文件大小（字节）
     * @returns {Promise<{complete: boolean, actualSize: number, expectedSize: number, match: boolean}>} 完整性检查结果
     */
    async function checkFileIntegrity(modelId, filename, expectedSize) {
        try {
            const actualSize = await getFileSize(modelId, filename);
            const match = expectedSize > 0 && actualSize === expectedSize;

            return {
                complete: actualSize > 0 && (expectedSize === 0 || match),
                actualSize: actualSize,
                expectedSize: expectedSize,
                match: match
            };
        } catch (error) {
            console.error('[LocalFileStorage] 检查文件完整性失败:', error);
            return {
                complete: false,
                actualSize: 0,
                expectedSize: expectedSize,
                match: false
            };
        }
    }

    /**
     * 列出所有已下载的模型
     * @returns {Promise<Array>} 模型列表
     */
    async function listModels() {
        const metadata = getModelMetadata();
        const models = [];

        for (const modelId in metadata) {
            const modelData = metadata[modelId];
            models.push({
                modelId: modelData.modelId,
                totalSize: modelData.totalSize || 0,
                fileCount: modelData.fileCount || 0,
                createdAt: modelData.createdAt,
                lastAccessed: modelData.lastAccessed
            });
        }

        return models;
    }

    /**
     * 检查模型是否已下载（通过元数据快速检查，不需要文件系统权限）
     * @param {string} modelId 模型ID
     * @returns {Object} 检查结果 { downloaded: boolean, fileCount: number, totalSize: number }
     */
    function checkModelDownloadedByMetadata(modelId) {
        if (!modelId) {
            return { downloaded: false, fileCount: 0, totalSize: 0 };
        }

        const metadata = getModelMetadata();
        // 尝试原始 modelId 和清理后的 modelId
        const sanitizedId = sanitizeModelIdForPath(modelId);
        const modelData = metadata[modelId] || metadata[sanitizedId];

        if (!modelData || !modelData.files || modelData.files.length === 0) {
            return { downloaded: false, fileCount: 0, totalSize: 0 };
        }

        // 检查是否有关键文件（config.json 或 model.safetensors/pytorch_model.bin）
        const hasConfigFile = modelData.files.some(f =>
            f.filename === 'config.json' ||
            f.filename.endsWith('/config.json')
        );
        const hasModelFile = modelData.files.some(f =>
            f.filename.includes('.safetensors') ||
            f.filename.includes('.bin') ||
            f.filename.includes('.onnx')
        );

        return {
            downloaded: hasConfigFile && hasModelFile,
            fileCount: modelData.fileCount || modelData.files.length,
            totalSize: modelData.totalSize || 0,
            files: modelData.files
        };
    }

    /**
     * 保存选择的目录路径（用于提示用户下次选择同一目录）
     * @param {string} directoryPath 目录路径描述
     */
    function saveSelectedDirectoryPath(directoryPath) {
        try {
            localStorage.setItem('autoleadagent_model_directory_path', directoryPath);
        } catch (e) {
            console.warn('[LocalFileStorage] 保存目录路径失败:', e);
        }
    }

    /**
     * 获取保存的目录路径
     * @returns {string|null} 目录路径
     */
    function getSelectedDirectoryPath() {
        try {
            return localStorage.getItem('autoleadagent_model_directory_path');
        } catch (e) {
            return null;
        }
    }

    /**
     * 检查是否有有效的目录权限（用于判断是否需要重新选择目录）
     * @param {string} modelId 模型ID
     * @returns {boolean} 是否有权限
     */
    function hasValidDirectoryPermission(modelId) {
        const sanitizedId = sanitizeModelIdForPath(modelId);
        return modelDirectories.has(modelId) || modelDirectories.has(sanitizedId);
    }

    /**
     * 获取缓存的目录句柄（从内存）
     * 优先返回内存中的句柄，如果没有则返回 null
     * @returns {FileSystemDirectoryHandle|null} 目录句柄
     */
    async function getCachedDirectoryHandle() {
        // 首先返回内存中的句柄
        if (cachedDirectoryHandle) {
            return cachedDirectoryHandle;
        }

        // 由于 FileSystemDirectoryHandle 不能被序列化存储到 IndexedDB，
        // 这个函数现在总是返回 null
        // 目录句柄需要在每次会话中重新选择
        console.log('[LocalFileStorage] 注意：目录句柄不能持久化存储，请重新选择目录');
        return null;
    }

    /**
     * 设置缓存的目录句柄（仅内存中）
     * 用于在同一会话中保存目录句柄
     * @param {FileSystemDirectoryHandle} handle 目录句柄
     */
    function setCachedDirectoryHandle(handle) {
        if (handle) {
            cachedDirectoryHandle = handle;
            console.log('[LocalFileStorage] 目录句柄已保存到内存:', handle.name);
        }
    }

    /**
     * 打开缓存目录数据库
     * @returns {Promise<IDBDatabase>} 数据库实例
     */
    async function openCacheDirDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open('AutoLeadAgentCacheDir', 1);

            request.onerror = () => reject(request.error);
            request.onsuccess = () => resolve(request.result);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains('cacheDir')) {
                    db.createObjectStore('cacheDir');
                }
            };
        });
    }

    /**
     * 预先请求文件系统权限（必须在用户手势上下文中调用）
     * @param {string} modelId 模型ID
     * @returns {Promise<boolean>} 是否成功获取权限
     */
    async function requestModelDirectoryPermission(modelId) {
        return await getModelDirectory(modelId, true).then(() => true).catch((error) => {
            throw error;
        });
    }

    // 导出公共 API
    return {
        supportsFileSystemAccess: supportsFileSystemAccess,
        checkFileSystemPermission: checkFileSystemPermission,
        requestModelDirectoryPermission: requestModelDirectoryPermission,
        saveModelFile: saveModelFile,
        saveModelFileChunk: saveModelFileChunk,
        getModelFile: getModelFile,
        hasModelFile: hasModelFile,
        hasModelFiles: hasModelFiles,
        deleteModel: deleteModel,
        getModelMetadata: getModelMetadata,
        getModelMetadataInfo: getModelMetadataInfo,
        listModels: listModels,
        collectModelFiles: collectModelFiles,
        updateModelMetadata: updateModelMetadata,
        getFileSize: getFileSize,
        checkFileIntegrity: checkFileIntegrity,
        checkModelDownloadedByMetadata: checkModelDownloadedByMetadata,
        saveSelectedDirectoryPath: saveSelectedDirectoryPath,
        getSelectedDirectoryPath: getSelectedDirectoryPath,
        hasValidDirectoryPermission: hasValidDirectoryPermission,
        sanitizeModelIdForPath: sanitizeModelIdForPath,
        getCachedDirectoryHandle: getCachedDirectoryHandle,
        setCachedDirectoryHandle: setCachedDirectoryHandle,
        cachedDirectoryHandle: cachedDirectoryHandle
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.LocalFileStorage = LocalFileStorage;
}

