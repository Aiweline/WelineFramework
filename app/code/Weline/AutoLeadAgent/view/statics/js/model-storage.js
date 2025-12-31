/**
 * 模型存储管理
 * 使用 IndexedDB 存储模型文件和元数据
 */

var ModelStorage = (function () {
    'use strict';

    const DB_NAME = 'AutoLeadAgent_ModelStorage';
    const DB_VERSION = 2; // 升级版本以支持文件存储
    const STORE_MODELS = 'models';
    const STORE_METADATA = 'metadata';

    var db = null;
    var cacheSizeLimit = 1024 * 1024 * 1024; // 默认 1GB

    /**
     * 初始化数据库
     * @returns {Promise<IDBDatabase>} 数据库对象
     */
    function initDB() {
        return new Promise((resolve, reject) => {
            if (db) {
                resolve(db);
                return;
            }

            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onerror = function() {
                reject(new Error('Failed to open IndexedDB'));
            };

            request.onsuccess = function(event) {
                db = event.target.result;
                resolve(db);
            };

            request.onupgradeneeded = function(event) {
                const database = event.target.result;

                // 创建模型文件存储（存储模型元数据和文件列表）
                if (!database.objectStoreNames.contains(STORE_MODELS)) {
                    const modelStore = database.createObjectStore(STORE_MODELS, { keyPath: 'modelId' });
                    modelStore.createIndex('version', 'version', { unique: false });
                    modelStore.createIndex('size', 'size', { unique: false });
                    modelStore.createIndex('lastAccessed', 'lastAccessed', { unique: false });
                    modelStore.createIndex('createdAt', 'createdAt', { unique: false });
                }

                // 创建模型文件存储（存储单个文件数据）
                if (!database.objectStoreNames.contains('modelFiles')) {
                    const fileStore = database.createObjectStore('modelFiles', { keyPath: ['modelId', 'filename'] });
                    fileStore.createIndex('modelId', 'modelId', { unique: false });
                    fileStore.createIndex('size', 'size', { unique: false });
                }

                // 创建元数据存储
                if (!database.objectStoreNames.contains(STORE_METADATA)) {
                    const metadataStore = database.createObjectStore(STORE_METADATA, { keyPath: 'key' });
                }
            };
        });
    }

    /**
     * 设置缓存大小限制
     * @param {number} sizeMB 大小（MB）
     */
    function setCacheSizeLimit(sizeMB) {
        cacheSizeLimit = sizeMB * 1024 * 1024;
    }

    /**
     * 保存模型文件
     * @param {string} modelId 模型ID
     * @param {ArrayBuffer|Blob} data 模型数据
     * @param {string} version 版本号
     * @returns {Promise<void>}
     */
    async function saveModel(modelId, data, version) {
        try {
            const database = await initDB();
            const transaction = database.transaction([STORE_MODELS], 'readwrite');
            const store = transaction.objectStore(STORE_MODELS);

            const size = data instanceof Blob ? data.size : data.byteLength;
            const modelData = {
                modelId: modelId,
                data: data,
                version: version || '1.0.0',
                size: size,
                lastAccessed: Date.now(),
                createdAt: Date.now(),
            };

            await new Promise((resolve, reject) => {
                const request = store.put(modelData);
                request.onsuccess = () => resolve();
                request.onerror = () => reject(request.error);
            });

            // 检查并清理缓存
            await cleanupCache();

            console.log('[ModelStorage] Model saved:', modelId, 'Size:', (size / 1024 / 1024).toFixed(2), 'MB');
        } catch (error) {
            console.error('[ModelStorage] Failed to save model:', error);
            throw error;
        }
    }

    /**
     * 保存模型文件（单个文件）
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @param {ArrayBuffer} data 文件数据
     * @returns {Promise<void>}
     */
    async function saveModelFile(modelId, filename, data) {
        try {
            const fileSize = data.byteLength || data.size || 0;
            const fileSizeMB = fileSize / 1024 / 1024;
            
            // 对于超大文件（>100MB），使用 Blob 存储（更高效）
            let dataToStore = data;
            if (fileSize > 100 * 1024 * 1024 && data instanceof ArrayBuffer) {
                console.log('[ModelStorage] 大文件，转换为 Blob 存储:', filename, fileSizeMB.toFixed(2), 'MB');
                dataToStore = new Blob([data], { type: 'application/octet-stream' });
            }
            
            const database = await initDB();
            const transaction = database.transaction(['modelFiles'], 'readwrite');
            const store = transaction.objectStore('modelFiles');

            const fileData = {
                modelId: modelId,
                filename: filename,
                data: dataToStore,
                size: fileSize,
                savedAt: Date.now()
            };

            await new Promise((resolve, reject) => {
                let resolved = false;
                
                const request = store.put(fileData);
                request.onsuccess = () => {
                    // 等待事务完成以确保数据已持久化
                    transaction.oncomplete = () => {
                        if (!resolved) {
                            resolved = true;
                            console.log('[ModelStorage] Model file saved and committed:', modelId, filename, 'Size:', fileSizeMB.toFixed(2), 'MB');
                            resolve();
                        }
                    };
                    transaction.onerror = () => {
                        if (!resolved) {
                            resolved = true;
                            const error = transaction.error || request.error;
                            console.error('[ModelStorage] Transaction error:', error);
                            
                            // 检查是否是配额错误
                            if (error && (error.name === 'QuotaExceededError' || error.message && error.message.includes('quota'))) {
                                reject(new Error('存储空间不足，无法保存文件。建议：1. 清理浏览器缓存；2. 或使用较小的模型；3. 或使用 Chrome Built-in AI'));
                            } else {
                                reject(error);
                            }
                        }
                    };
                };
                request.onerror = () => {
                    if (!resolved) {
                        resolved = true;
                        const error = request.error;
                        console.error('[ModelStorage] Save error:', error);
                        
                        // 检查是否是配额错误
                        if (error && (error.name === 'QuotaExceededError' || error.message && error.message.includes('quota'))) {
                            reject(new Error('存储空间不足，无法保存文件。建议：1. 清理浏览器缓存；2. 或使用较小的模型；3. 或使用 Chrome Built-in AI'));
                        } else {
                            reject(error);
                        }
                    }
                };
            });
        } catch (error) {
            console.error('[ModelStorage] Failed to save model file:', error);
            throw error;
        }
    }

    /**
     * 保存模型文件列表（批量保存）
     * @param {string} modelId 模型ID
     * @param {Array} files 文件列表 [{filename, data, size}, ...]
     * @returns {Promise<void>}
     */
    async function saveModelFiles(modelId, files) {
        try {
            const database = await initDB();
            const transaction = database.transaction(['modelFiles', STORE_MODELS], 'readwrite');
            const fileStore = transaction.objectStore('modelFiles');
            const modelStore = transaction.objectStore(STORE_MODELS);

            let totalSize = 0;
            const fileList = [];

            // 保存所有文件
            for (const file of files) {
                const fileData = {
                    modelId: modelId,
                    filename: file.filename,
                    data: file.data,
                    size: file.size || file.data.byteLength,
                    savedAt: Date.now()
                };

                await new Promise((resolve, reject) => {
                    const request = fileStore.put(fileData);
                    request.onsuccess = () => resolve();
                    request.onerror = () => reject(request.error);
                });

                totalSize += fileData.size;
                fileList.push({
                    filename: file.filename,
                    size: fileData.size
                });

                console.log('[ModelStorage] Saved file:', file.filename, 'Size:', (fileData.size / 1024 / 1024).toFixed(2), 'MB');
            }

            // 保存模型元数据
            const modelData = {
                modelId: modelId,
                files: fileList,
                totalSize: totalSize,
                fileCount: fileList.length,
                lastAccessed: Date.now(),
                createdAt: Date.now()
            };

            await new Promise((resolve, reject) => {
                const request = modelStore.put(modelData);
                request.onsuccess = () => resolve();
                request.onerror = () => reject(request.error);
            });

            // 检查并清理缓存
            await cleanupCache();

            console.log('[ModelStorage] Model files saved:', modelId, 'Total size:', (totalSize / 1024 / 1024).toFixed(2), 'MB', 'Files:', fileList.length);
        } catch (error) {
            console.error('[ModelStorage] Failed to save model files:', error);
            throw error;
        }
    }

    /**
     * 检查模型是否已下载（检查所有必需文件是否存在）
     * @param {string} modelId 模型ID
     * @returns {Promise<boolean>} 是否已下载
     */
    async function hasModelFiles(modelId) {
        try {
            const database = await initDB();
            // 需要同时访问 models 和 modelFiles 两个对象存储
            const transaction = database.transaction([STORE_MODELS, 'modelFiles'], 'readonly');
            const store = transaction.objectStore(STORE_MODELS);

            const modelData = await new Promise((resolve, reject) => {
                const request = store.get(modelId);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });

            if (!modelData || !modelData.files || modelData.files.length === 0) {
                console.log('[ModelStorage] 模型数据不存在或没有文件列表:', modelId);
                return false;
            }

            // 检查所有文件是否都存在
            const fileStore = transaction.objectStore('modelFiles');
            let allFilesExist = true;
            
            for (const file of modelData.files) {
                const fileExists = await new Promise((resolve, reject) => {
                    const request = fileStore.get([modelId, file.filename]);
                    request.onsuccess = () => resolve(request.result !== undefined);
                    request.onerror = () => reject(request.error);
                });

                if (!fileExists) {
                    console.log('[ModelStorage] 文件不存在:', modelId, file.filename);
                    allFilesExist = false;
                    break;
                }
            }

            console.log('[ModelStorage] 模型文件检查结果:', modelId, allFilesExist, '文件数量:', modelData.files.length);
            return allFilesExist;
        } catch (error) {
            console.error('[ModelStorage] Failed to check model files:', error);
            return false;
        }
    }

    /**
     * 获取模型文件
     * @param {string} modelId 模型ID
     * @param {string} filename 文件名
     * @returns {Promise<ArrayBuffer|null>} 文件数据
     */
    async function getModelFile(modelId, filename) {
        try {
            const database = await initDB();
            const transaction = database.transaction(['modelFiles'], 'readonly');
            const store = transaction.objectStore('modelFiles');

            const fileData = await new Promise((resolve, reject) => {
                const request = store.get([modelId, filename]);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });

            return fileData ? fileData.data : null;
        } catch (error) {
            console.error('[ModelStorage] Failed to get model file:', error);
            return null;
        }
    }

    /**
     * 获取模型文件
     * @param {string} modelId 模型ID
     * @returns {Promise<ArrayBuffer|Blob|null>} 模型数据
     */
    async function getModel(modelId) {
        try {
            const database = await initDB();
            const transaction = database.transaction([STORE_MODELS], 'readonly');
            const store = transaction.objectStore(STORE_MODELS);

            const modelData = await new Promise((resolve, reject) => {
                const request = store.get(modelId);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });

            if (!modelData) {
                return null;
            }

            // 更新最后访问时间
            modelData.lastAccessed = Date.now();
            await new Promise((resolve, reject) => {
                const transaction = database.transaction([STORE_MODELS], 'readwrite');
                const store = transaction.objectStore(STORE_MODELS);
                const request = store.put(modelData);
                request.onsuccess = () => resolve();
                request.onerror = () => reject(request.error);
            });

            return modelData.data;
        } catch (error) {
            console.error('[ModelStorage] Failed to get model:', error);
            throw error;
        }
    }

    /**
     * 检查模型是否存在
     * @param {string} modelId 模型ID
     * @returns {Promise<boolean>} 是否存在
     */
    async function hasModel(modelId) {
        try {
            const model = await getModel(modelId);
            return model !== null;
        } catch (error) {
            return false;
        }
    }

    /**
     * 删除模型
     * @param {string} modelId 模型ID
     * @returns {Promise<void>}
     */
    /**
     * 删除模型（包括所有文件和元数据）
     * @param {string} modelId 模型ID
     * @returns {Promise<void>}
     */
    async function deleteModel(modelId) {
        try {
            const database = await initDB();
            
            // 先删除所有文件
            const fileTransaction = database.transaction(['modelFiles'], 'readwrite');
            const fileStore = fileTransaction.objectStore('modelFiles');
            const fileIndex = fileStore.index('modelId');
            
            // 获取该模型的所有文件
            const files = await new Promise((resolve, reject) => {
                const files = [];
                const request = fileIndex.openCursor(IDBKeyRange.only(modelId));
                request.onsuccess = (event) => {
                    const cursor = event.target.result;
                    if (cursor) {
                        files.push(cursor.value);
                        cursor.continue();
                    } else {
                        resolve(files);
                    }
                };
                request.onerror = () => reject(request.error);
            });
            
            // 删除所有文件
            let deletedSize = 0;
            for (const file of files) {
                await new Promise((resolve, reject) => {
                    const request = fileStore.delete([modelId, file.filename]);
                    request.onsuccess = () => {
                        deletedSize += file.size || 0;
                        resolve();
                    };
                    request.onerror = () => reject(request.error);
                });
            }
            
            // 等待文件删除事务完成
            await new Promise((resolve, reject) => {
                fileTransaction.oncomplete = () => resolve();
                fileTransaction.onerror = () => reject(fileTransaction.error);
            });
            
            // 删除模型元数据
            const modelTransaction = database.transaction([STORE_MODELS], 'readwrite');
            const modelStore = modelTransaction.objectStore(STORE_MODELS);
            
            await new Promise((resolve, reject) => {
                const request = modelStore.delete(modelId);
                request.onsuccess = () => resolve();
                request.onerror = () => reject(request.error);
            });
            
            // 等待模型删除事务完成
            await new Promise((resolve, reject) => {
                modelTransaction.oncomplete = () => resolve();
                modelTransaction.onerror = () => reject(modelTransaction.error);
            });

            console.log('[ModelStorage] Model deleted:', modelId, 'Files:', files.length, 'Size:', (deletedSize / 1024 / 1024).toFixed(2), 'MB');
            return {
                modelId: modelId,
                deletedFiles: files.length,
                deletedSize: deletedSize
            };
        } catch (error) {
            console.error('[ModelStorage] Failed to delete model:', error);
            throw error;
        }
    }

    /**
     * 获取模型元数据
     * @param {string} modelId 模型ID
     * @returns {Promise<Object|null>} 元数据
     */
    async function getModelMetadata(modelId) {
        try {
            const database = await initDB();
            const transaction = database.transaction([STORE_MODELS], 'readonly');
            const store = transaction.objectStore(STORE_MODELS);

            const modelData = await new Promise((resolve, reject) => {
                const request = store.get(modelId);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });

            if (!modelData) {
                return null;
            }

            return {
                modelId: modelData.modelId,
                version: modelData.version,
                size: modelData.size,
                sizeMB: (modelData.size / 1024 / 1024).toFixed(2),
                lastAccessed: modelData.lastAccessed,
                createdAt: modelData.createdAt,
            };
        } catch (error) {
            console.error('[ModelStorage] Failed to get metadata:', error);
            return null;
        }
    }

    /**
     * 获取所有模型列表
     * @returns {Promise<Array>} 模型列表
     */
    async function listModels() {
        try {
            const database = await initDB();
            const transaction = database.transaction([STORE_MODELS], 'readonly');
            const store = transaction.objectStore(STORE_MODELS);

            const models = await new Promise((resolve, reject) => {
                const request = store.getAll();
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });

            return models.map(model => ({
                modelId: model.modelId,
                version: model.version,
                size: model.size,
                sizeMB: (model.size / 1024 / 1024).toFixed(2),
                lastAccessed: model.lastAccessed,
                createdAt: model.createdAt,
            }));
        } catch (error) {
            console.error('[ModelStorage] Failed to list models:', error);
            return [];
        }
    }

    /**
     * 获取总缓存大小
     * @returns {Promise<number>} 总大小（字节）
     */
    async function getTotalCacheSize() {
        try {
            const models = await listModels();
            return models.reduce((total, model) => total + model.size, 0);
        } catch (error) {
            console.error('[ModelStorage] Failed to get cache size:', error);
            return 0;
        }
    }

    /**
     * 清理缓存（LRU策略）
     * @returns {Promise<number>} 清理的字节数
     */
    async function cleanupCache() {
        try {
            const totalSize = await getTotalCacheSize();
            
            if (totalSize <= cacheSizeLimit) {
                return 0;
            }

            const database = await initDB();
            const transaction = database.transaction([STORE_MODELS], 'readwrite');
            const store = transaction.objectStore(STORE_MODELS);
            const index = store.index('lastAccessed');

            // 按最后访问时间排序（升序，最久未访问的在前面）
            const models = await new Promise((resolve, reject) => {
                const request = index.getAll();
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });

            models.sort((a, b) => a.lastAccessed - b.lastAccessed);

            let cleanedSize = 0;
            let currentSize = totalSize;

            // 删除最久未访问的模型，直到缓存大小在限制内
            for (const model of models) {
                if (currentSize <= cacheSizeLimit) {
                    break;
                }

                await new Promise((resolve, reject) => {
                    const request = store.delete(model.modelId);
                    request.onsuccess = () => resolve();
                    request.onerror = () => reject(request.error);
                });

                cleanedSize += model.size;
                currentSize -= model.size;
                console.log('[ModelStorage] Cleaned model:', model.modelId);
            }

            console.log('[ModelStorage] Cache cleanup completed, freed:', (cleanedSize / 1024 / 1024).toFixed(2), 'MB');
            return cleanedSize;
        } catch (error) {
            console.error('[ModelStorage] Failed to cleanup cache:', error);
            return 0;
        }
    }

    /**
     * 清空所有缓存
     * @returns {Promise<void>}
     */
    async function clearAll() {
        try {
            const database = await initDB();
            const transaction = database.transaction([STORE_MODELS], 'readwrite');
            const store = transaction.objectStore(STORE_MODELS);

            await new Promise((resolve, reject) => {
                const request = store.clear();
                request.onsuccess = () => resolve();
                request.onerror = () => reject(request.error);
            });

            console.log('[ModelStorage] All cache cleared');
        } catch (error) {
            console.error('[ModelStorage] Failed to clear cache:', error);
            throw error;
        }
    }

    /**
     * 保存元数据
     * @param {string} key 键
     * @param {*} value 值
     * @returns {Promise<void>}
     */
    async function saveMetadata(key, value) {
        try {
            const database = await initDB();
            const transaction = database.transaction([STORE_METADATA], 'readwrite');
            const store = transaction.objectStore(STORE_METADATA);

            await new Promise((resolve, reject) => {
                const request = store.put({ key: key, value: value, updatedAt: Date.now() });
                request.onsuccess = () => resolve();
                request.onerror = () => reject(request.error);
            });
        } catch (error) {
            console.error('[ModelStorage] Failed to save metadata:', error);
            throw error;
        }
    }

    /**
     * 获取元数据
     * @param {string} key 键
     * @returns {Promise<*>} 值
     */
    async function getMetadata(key) {
        try {
            const database = await initDB();
            const transaction = database.transaction([STORE_METADATA], 'readonly');
            const store = transaction.objectStore(STORE_METADATA);

            const result = await new Promise((resolve, reject) => {
                const request = store.get(key);
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });

            return result ? result.value : null;
        } catch (error) {
            console.error('[ModelStorage] Failed to get metadata:', error);
            return null;
        }
    }

    /**
     * 从 modelFiles 对象存储中收集指定模型的所有文件
     * @param {string} modelId 模型ID
     * @returns {Promise<Array>} 文件列表 [{filename, size}, ...]
     */
    async function collectModelFiles(modelId) {
        try {
            const database = await initDB();
            const transaction = database.transaction(['modelFiles'], 'readonly');
            const store = transaction.objectStore('modelFiles');
            const index = store.index('modelId');
            
            const files = [];
            return new Promise((resolve, reject) => {
                const request = index.openCursor(IDBKeyRange.only(modelId));
                request.onsuccess = (event) => {
                    const cursor = event.target.result;
                    if (cursor) {
                        const fileData = cursor.value;
                        files.push({
                            filename: fileData.filename,
                            size: fileData.size || fileData.data.byteLength
                        });
                        cursor.continue();
                    } else {
                        console.log('[ModelStorage] 收集到文件:', modelId, files.length, '个文件');
                        resolve(files);
                    }
                };
                request.onerror = () => reject(request.error);
            });
        } catch (error) {
            console.error('[ModelStorage] Failed to collect model files:', error);
            throw error;
        }
    }

    // 导出公共 API
    return {
        initDB: initDB,
        setCacheSizeLimit: setCacheSizeLimit,
        saveModel: saveModel,
        getModel: getModel,
        hasModel: hasModel,
        deleteModel: deleteModel,
        getModelMetadata: getModelMetadata,
        listModels: listModels,
        getTotalCacheSize: getTotalCacheSize,
        cleanupCache: cleanupCache,
        clearAll: clearAll,
        saveMetadata: saveMetadata,
        getMetadata: getMetadata,
        // 新增的文件操作 API
        saveModelFile: saveModelFile,
        saveModelFiles: saveModelFiles,
        hasModelFiles: hasModelFiles,
        getModelFile: getModelFile,
        collectModelFiles: collectModelFiles,
    };

})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ModelStorage = ModelStorage;
}

