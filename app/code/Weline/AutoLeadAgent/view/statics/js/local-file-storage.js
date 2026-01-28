/**
 * 本地文件存储管理
 * 使用 IndexedDB 存储模型文件，无需本地文件系统权限
 * 模型文件持久化存储在 IndexedDB 中，下次打开页面可直接使用
 */

var LocalFileStorage = (function () {
    'use strict';

    // IndexedDB 数据库名称和版本
    const DB_NAME = 'AutoLeadAgentModels';
    const DB_VERSION = 2;

    // 存储当前正在写入的文件流（用于分块写入）
    var activeWritables = new Map();

    // 存储模型元数据（使用 localStorage 作为快速索引）
    const METADATA_KEY = 'autoleadagent_model_metadata';

    // 数据库实例缓存
    var dbInstance = null;

    /**
     * 打开 IndexedDB 数据库
     * @returns {Promise<IDBDatabase>}
     */
    async function openModelDB() {
        if (dbInstance) {
            return dbInstance;
        }

        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onerror = () => {
                console.error('[LocalFileStorage] IndexedDB 打开失败:', request.error);
                reject(request.error);
            };

            request.onsuccess = () => {
                dbInstance = request.result;
                // 监听数据库关闭事件
                dbInstance.onclose = () => {
                    dbInstance = null;
                };
                resolve(dbInstance);
            };

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
     * @param {ArrayBuffer|Blob} data 文件数据
     * @returns {Promise<void>}
     */
    async function saveFileToIndexedDB(modelId, filename, data) {
        const db = await openModelDB();
        const id = `${modelId}/${filename}`;

        // 如果是 Blob，转换为 ArrayBuffer
        let arrayBuffer = data;
        if (data instanceof Blob) {
            arrayBuffer = await data.arrayBuffer();
        }

        const fileSize = arrayBuffer ? arrayBuffer.byteLength : 0;
        const fileSizeMB = (fileSize / 1024 / 1024).toFixed(2);

        return new Promise((resolve, reject) => {
            const transaction = db.transaction(['modelFiles'], 'readwrite');
            const store = transaction.objectStore('modelFiles');

            const record = {
                id: id,
                modelId: modelId,
                filename: filename,
                data: arrayBuffer,
                size: fileSize,
                savedAt: Date.now()
            };

            const request = store.put(record);

            // 监听事务完成以确保数据持久化
            transaction.oncomplete = () => {
                console.log('[LocalFileStorage] 文件已持久化到 IndexedDB:', filename, fileSizeMB, 'MB');
                resolve();
            };

            transaction.onerror = (event) => {
                const error = transaction.error || event.target.error;
                console.error('[LocalFileStorage] IndexedDB 事务失败:', error);

                // 检查是否是配额问题
                if (error && (error.name === 'QuotaExceededError' || (error.message && error.message.includes('quota')))) {
                    reject(new Error('存储空间不足，无法保存文件 ' + filename + '。请清理浏览器缓存或使用较小的模型。'));
                } else {
                    reject(error);
                }
            };

            request.onerror = (event) => {
                const error = request.error || event.target.error;
                console.error('[LocalFileStorage] 保存文件到 IndexedDB 失败:', error);
                // 不在这里 reject，让 transaction.onerror 处理
            };
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
                if (request.result && request.result.data) {
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
                resolve(!!(request.result && request.result.data));
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
                const files = (request.result || []).map(r => ({
                    filename: r.filename,
                    size: r.size || 0
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
                const keys = request.result || [];
                let deletedCount = 0;

                if (keys.length === 0) {
                    resolve(0);
                    return;
                }

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

                transaction.onerror = () => {
                    reject(transaction.error);
                };
            };
            request.onerror = () => reject(request.error);
        });
    }

    /**
     * 清理模型ID，使其可以作为存储键
     * @param {string} modelId 原始模型ID（如 "sentence-transformers/all-MiniLM-L6-v2"）
     * @returns {string} 清理后的ID
     */
    function sanitizeModelIdForPath(modelId) {
        if (!modelId) return '';
        // 保持原始格式，IndexedDB 支持任意字符串作为键
        return modelId;
    }

    /**
     * 检查是否支持 IndexedDB
     */
    function supportsFileSystemAccess() {
        // 改为检查 IndexedDB 支持
        return typeof indexedDB !== 'undefined';
    }

    /**
     * 验证文件是否已正确保存到 IndexedDB
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {number} expectedSize 预期大小（可选）
     * @returns {Promise<{saved: boolean, size: number, verified: boolean}>}
     */
    async function verifyFileSaved(modelId, filename, expectedSize) {
        try {
            const data = await getFileFromIndexedDB(modelId, filename);
            if (!data) {
                return { saved: false, size: 0, verified: false };
            }

            const actualSize = data.byteLength || 0;
            const verified = expectedSize ? actualSize === expectedSize : actualSize > 0;

            console.log('[LocalFileStorage] 文件验证:', filename, 'saved:', !!data, 'size:', actualSize, 'verified:', verified);

            return {
                saved: true,
                size: actualSize,
                verified: verified
            };
        } catch (error) {
            console.error('[LocalFileStorage] 文件验证失败:', error);
            return { saved: false, size: 0, verified: false };
        }
    }

    /**
     * 获取存储统计信息
     * @returns {Promise<{totalSize: number, fileCount: number, models: Array}>}
     */
    async function getStorageStats() {
        try {
            const db = await openModelDB();
            const transaction = db.transaction(['modelFiles'], 'readonly');
            const store = transaction.objectStore('modelFiles');

            return new Promise((resolve, reject) => {
                const request = store.getAll();
                request.onsuccess = () => {
                    const records = request.result || [];
                    const modelMap = new Map();

                    let totalSize = 0;
                    for (const record of records) {
                        totalSize += record.size || 0;
                        const modelId = record.modelId;
                        if (!modelMap.has(modelId)) {
                            modelMap.set(modelId, { files: [], totalSize: 0 });
                        }
                        const model = modelMap.get(modelId);
                        model.files.push({
                            filename: record.filename,
                            size: record.size || 0
                        });
                        model.totalSize += record.size || 0;
                    }

                    const models = [];
                    for (const [modelId, data] of modelMap) {
                        models.push({
                            modelId: modelId,
                            fileCount: data.files.length,
                            totalSize: data.totalSize,
                            files: data.files
                        });
                    }

                    resolve({
                        totalSize: totalSize,
                        fileCount: records.length,
                        models: models
                    });
                };
                request.onerror = () => reject(request.error);
            });
        } catch (error) {
            console.error('[LocalFileStorage] 获取存储统计失败:', error);
            return { totalSize: 0, fileCount: 0, models: [] };
        }
    }

    /**
     * 请求持久化存储权限（防止浏览器清理缓存）
     * @returns {Promise<boolean>}
     */
    async function requestPersistentStorage() {
        if (navigator.storage && navigator.storage.persist) {
            try {
                const isPersisted = await navigator.storage.persist();
                console.log('[LocalFileStorage] 持久化存储:', isPersisted ? '已授权' : '未授权');
                return isPersisted;
            } catch (error) {
                console.warn('[LocalFileStorage] 请求持久化存储失败:', error);
                return false;
            }
        }
        return false;
    }

    /**
     * 检查存储是否已持久化
     * @returns {Promise<boolean>}
     */
    async function isStoragePersisted() {
        if (navigator.storage && navigator.storage.persisted) {
            try {
                return await navigator.storage.persisted();
            } catch (error) {
                console.warn('[LocalFileStorage] 检查持久化状态失败:', error);
                return false;
            }
        }
        return false;
    }

    /**
     * 获取存储配额信息
     * @returns {Promise<{usage: number, quota: number, percentUsed: number}>}
     */
    async function getStorageQuota() {
        if (navigator.storage && navigator.storage.estimate) {
            try {
                const estimate = await navigator.storage.estimate();
                return {
                    usage: estimate.usage || 0,
                    quota: estimate.quota || 0,
                    percentUsed: estimate.quota > 0 ? ((estimate.usage / estimate.quota) * 100).toFixed(2) : 0
                };
            } catch (error) {
                console.warn('[LocalFileStorage] 获取存储配额失败:', error);
                return { usage: 0, quota: 0, percentUsed: 0 };
            }
        }
        return { usage: 0, quota: 0, percentUsed: 0 };
    }

    /**
     * 检查存储权限（IndexedDB 无需特殊权限）
     * @param {string} modelId 模型ID
     * @returns {Promise<{granted: boolean, needsPermission: boolean, message?: string}>} 权限状态
     */
    async function checkFileSystemPermission(modelId) {
        if (!supportsFileSystemAccess()) {
            return {
                granted: false,
                needsPermission: false,
                message: '浏览器不支持 IndexedDB。请使用现代浏览器。'
            };
        }

        // IndexedDB 不需要用户权限
        return {
            granted: true,
            needsPermission: false
        };
    }

    /**
     * 保存模型文件块（用于流式下载）
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {ArrayBuffer} data 数据块
     * @param {number} offset 偏移量
     * @param {boolean} isComplete 是否是最后一块
     */
    async function saveModelFileChunk(modelId, filename, data, offset, isComplete) {
        try {
            const cacheKey = `${modelId}/${filename}`;

            console.log('[LocalFileStorage] 保存文件块:', filename, '偏移:', offset, '大小:', data ? data.byteLength : 0, '完成:', isComplete);

            // 获取或创建缓存
            let cached = activeWritables.get(cacheKey);
            if (!cached) {
                // 检查 IndexedDB 中是否已有部分数据
                const existingData = await getFileFromIndexedDB(modelId, filename);
                if (existingData && existingData.byteLength > 0 && offset > 0) {
                    cached = {
                        buffer: existingData,
                        totalSize: existingData.byteLength
                    };
                    console.log('[LocalFileStorage] 从 IndexedDB 恢复已有数据，大小:', existingData.byteLength);
                } else {
                    cached = {
                        buffer: null,
                        totalSize: 0
                    };
                }
            }

            // 合并数据块
            if (data && data.byteLength > 0) {
                if (cached.buffer && offset > 0) {
                    // 合并现有数据和新数据
                    const newSize = Math.max(cached.buffer.byteLength, offset + data.byteLength);
                    const newBuffer = new Uint8Array(newSize);
                    newBuffer.set(new Uint8Array(cached.buffer), 0);
                    newBuffer.set(new Uint8Array(data), offset);
                    cached.buffer = newBuffer.buffer;
                    cached.totalSize = newSize;
                } else {
                    // 新文件或从头开始
                    cached.buffer = data;
                    cached.totalSize = data.byteLength;
                }
            }

            // 如果是最后一块，保存到 IndexedDB
            if (isComplete && cached.buffer) {
                await saveFileToIndexedDB(modelId, filename, cached.buffer);
                console.log('[LocalFileStorage] 文件已完整保存:', filename, '大小:', cached.totalSize);

                // 更新元数据
                await updateModelMetadata(modelId, filename, cached.totalSize);

                // 清除内存缓存
                activeWritables.delete(cacheKey);
            } else {
                // 临时存储到内存
                activeWritables.set(cacheKey, cached);
            }

        } catch (error) {
            console.error('[LocalFileStorage] 保存文件块失败:', filename, error);
            const cacheKey = `${modelId}/${filename}`;
            activeWritables.delete(cacheKey);
            throw error;
        }
    }

    /**
     * 保存模型文件
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {ArrayBuffer|Blob} data 文件数据
     * @returns {Promise<void>}
     */
    async function saveModelFile(modelId, filename, data) {
        try {
            const fileSize = data instanceof Blob ? data.size : data.byteLength;
            console.log('[LocalFileStorage] 保存文件:', filename, (fileSize / 1024 / 1024).toFixed(2), 'MB');

            // 直接保存到 IndexedDB
            await saveFileToIndexedDB(modelId, filename, data);

            // 更新元数据
            await updateModelMetadata(modelId, filename, fileSize);

            console.log('[LocalFileStorage] 文件保存完成:', filename);
        } catch (error) {
            console.error('[LocalFileStorage] 保存文件失败:', error);
            throw error;
        }
    }

    /**
     * 读取模型文件
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<ArrayBuffer|null>} 文件数据
     */
    async function getModelFile(modelId, filename) {
        try {
            console.log('[LocalFileStorage] 读取文件:', modelId, filename);
            const data = await getFileFromIndexedDB(modelId, filename);

            if (data && data.byteLength > 0) {
                console.log('[LocalFileStorage] 文件读取成功:', filename, (data.byteLength / 1024 / 1024).toFixed(2), 'MB');
                return data;
            }

            console.log('[LocalFileStorage] 文件不存在:', filename);
            return null;
        } catch (error) {
            console.error('[LocalFileStorage] 读取文件失败:', error);
            return null;
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
            return await hasFileInIndexedDB(modelId, filename);
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
            const files = await getModelFilesFromIndexedDB(modelId);
            if (!files || files.length === 0) {
                return false;
            }

            // 检查是否有关键文件
            const hasConfig = files.some(f => f.filename === 'config.json' || f.filename.endsWith('/config.json'));
            const hasModel = files.some(f =>
                f.filename.includes('.safetensors') ||
                f.filename.includes('.bin') ||
                f.filename.includes('.onnx')
            );

            return hasConfig || hasModel;
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
        }
    }

    /**
     * 更新模型元数据
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
            const oldSize = modelData.files[existingFileIndex].size || 0;
            modelData.files[existingFileIndex].size = size;
            modelData.files[existingFileIndex].updatedAt = Date.now();
            modelData.totalSize = (modelData.totalSize || 0) - oldSize + size;
        } else {
            // 添加新文件
            modelData.files.push({
                filename: filename,
                size: size,
                savedAt: Date.now()
            });
            modelData.totalSize = (modelData.totalSize || 0) + size;
            modelData.fileCount = modelData.files.length;
        }

        modelData.lastAccessed = Date.now();

        saveModelMetadata(metadata);

        console.log('[LocalFileStorage] 元数据已更新:', modelId, '文件数:', modelData.fileCount, '总大小:', ((modelData.totalSize || 0) / 1024 / 1024).toFixed(2), 'MB');
    }

    /**
     * 收集模型的所有文件
     * @param {string} modelId 模型ID
     * @returns {Promise<Array>} 文件列表
     */
    async function collectModelFiles(modelId) {
        try {
            const files = await getModelFilesFromIndexedDB(modelId);
            if (files && files.length > 0) {
                console.log('[LocalFileStorage] 获取模型文件列表:', files.length, '个文件');
                return files;
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
            size: file.size || 0
        }));
    }

    /**
     * 删除模型
     * @param {string} modelId 模型ID
     * @returns {Promise<{deletedFiles: number, deletedSize: number}>}
     */
    async function deleteModel(modelId) {
        try {
            const metadata = getModelMetadata();
            const modelData = metadata[modelId];

            let deletedFiles = 0;
            let deletedSize = 0;

            // 从 IndexedDB 删除文件
            try {
                const files = await getModelFilesFromIndexedDB(modelId);
                if (files && files.length > 0) {
                    deletedSize = files.reduce((sum, f) => sum + (f.size || 0), 0);
                    deletedFiles = await deleteModelFilesFromIndexedDB(modelId);
                }
            } catch (error) {
                console.warn('[LocalFileStorage] 从 IndexedDB 删除文件失败:', error);
            }

            // 从元数据中删除
            if (metadata[modelId]) {
                delete metadata[modelId];
                saveModelMetadata(metadata);
            }

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
     * @returns {Promise<number>} 文件大小（字节）
     */
    async function getFileSize(modelId, filename) {
        try {
            const data = await getFileFromIndexedDB(modelId, filename);
            return data ? data.byteLength : 0;
        } catch (error) {
            console.error('[LocalFileStorage] 获取文件大小失败:', error);
            return 0;
        }
    }

    /**
     * 检查文件完整性
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {number} expectedSize 预期文件大小
     * @returns {Promise<Object>} 完整性检查结果
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
     * 检查模型是否已下载（通过元数据快速检查）
     * @param {string} modelId 模型ID
     * @returns {Object} 检查结果
     */
    function checkModelDownloadedByMetadata(modelId) {
        if (!modelId) {
            return { downloaded: false, fileCount: 0, totalSize: 0 };
        }

        const metadata = getModelMetadata();
        const modelData = metadata[modelId];

        if (!modelData || !modelData.files || modelData.files.length === 0) {
            return { downloaded: false, fileCount: 0, totalSize: 0 };
        }

        // 检查是否有关键文件
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
            downloaded: hasConfigFile || hasModelFile,
            fileCount: modelData.fileCount || modelData.files.length,
            totalSize: modelData.totalSize || 0,
            files: modelData.files
        };
    }

    /**
     * 保存选择的目录路径（兼容性保留）
     */
    function saveSelectedDirectoryPath(directoryPath) {
        // 兼容性保留，实际不再使用
    }

    /**
     * 获取保存的目录路径（兼容性保留）
     */
    function getSelectedDirectoryPath() {
        return null;
    }

    /**
     * 检查是否有有效的目录权限（兼容性保留）
     */
    function hasValidDirectoryPermission(modelId) {
        // IndexedDB 不需要权限
        return true;
    }

    /**
     * 获取缓存的目录句柄（兼容性保留）
     */
    async function getCachedDirectoryHandle() {
        return null;
    }

    /**
     * 设置缓存的目录句柄（兼容性保留）
     */
    function setCachedDirectoryHandle(handle) {
        // 兼容性保留，实际不再使用
    }

    /**
     * 请求模型目录权限（兼容性保留）
     */
    async function requestModelDirectoryPermission(modelId) {
        // IndexedDB 不需要权限
        return true;
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
        cachedDirectoryHandle: null,
        // 新增的存储验证和统计功能
        verifyFileSaved: verifyFileSaved,
        getStorageStats: getStorageStats,
        requestPersistentStorage: requestPersistentStorage,
        isStoragePersisted: isStoragePersisted,
        getStorageQuota: getStorageQuota
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.LocalFileStorage = LocalFileStorage;
}
