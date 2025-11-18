/**
 * Weline API Token Storage
 * 使用IndexedDB安全存储API令牌和相关信息
 */
(function (window) {
    'use strict';

    const DB_NAME = 'WelineApiTokenDB';
    const DB_VERSION = 1;
    const STORE_NAME = 'tokens';

    /**
     * IndexedDB Token存储管理器
     */
    class TokenStorage {
        constructor() {
            this.db = null;
            this.initPromise = null;
        }

        /**
         * 初始化数据库
         * @returns {Promise<IDBDatabase>}
         */
        async init() {
            if (this.db) {
                return this.db;
            }
            if (this.initPromise) {
                return this.initPromise;
            }

            this.initPromise = new Promise((resolve, reject) => {
                if (!window.indexedDB) {
                    reject(new Error('IndexedDB is not supported'));
                    return;
                }

                const request = indexedDB.open(DB_NAME, DB_VERSION);

                request.onerror = () => {
                    reject(new Error('Failed to open IndexedDB'));
                };

                request.onsuccess = () => {
                    this.db = request.result;
                    resolve(this.db);
                };

                request.onupgradeneeded = (event) => {
                    const db = event.target.result;
                    if (!db.objectStoreNames.contains(STORE_NAME)) {
                        const objectStore = db.createObjectStore(STORE_NAME, { keyPath: 'key' });
                        objectStore.createIndex('type', 'type', { unique: false });
                        objectStore.createIndex('expiresAt', 'expiresAt', { unique: false });
                    }
                };
            });

            return this.initPromise;
        }

        /**
         * 保存令牌数据
         * @param {string} key 存储键
         * @param {string} token 访问令牌
         * @param {string} refreshToken 刷新令牌
         * @param {number} expiresIn 过期时间（秒）
         * @param {object} user 用户信息
         * @param {string} type 令牌类型（frontendApi|backendApi）
         * @returns {Promise<void>}
         */
        async saveToken(key, token, refreshToken, expiresIn, user, type) {
            await this.init();
            const expiresAt = expiresIn ? Date.now() + (expiresIn * 1000) : null;

            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([STORE_NAME], 'readwrite');
                const objectStore = transaction.objectStore(STORE_NAME);
                const data = {
                    key: key,
                    token: token,
                    refreshToken: refreshToken || null,
                    expiresAt: expiresAt,
                    user: user || null,
                    type: type,
                    createdAt: Date.now(),
                    updatedAt: Date.now()
                };

                const request = objectStore.put(data);

                request.onsuccess = () => {
                    resolve();
                };

                request.onerror = () => {
                    reject(new Error('Failed to save token'));
                };
            });
        }

        /**
         * 获取令牌数据
         * @param {string} key 存储键
         * @returns {Promise<object|null>}
         */
        async getToken(key) {
            await this.init();

            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([STORE_NAME], 'readonly');
                const objectStore = transaction.objectStore(STORE_NAME);
                const request = objectStore.get(key);

                request.onsuccess = () => {
                    const result = request.result;
                    if (!result) {
                        resolve(null);
                        return;
                    }

                    // 检查是否过期
                    if (result.expiresAt && result.expiresAt < Date.now()) {
                        // 已过期，删除并返回null
                        this.deleteToken(key).catch(() => {});
                        resolve(null);
                        return;
                    }

                    resolve(result);
                };

                request.onerror = () => {
                    reject(new Error('Failed to get token'));
                };
            });
        }

        /**
         * 删除令牌数据
         * @param {string} key 存储键
         * @returns {Promise<void>}
         */
        async deleteToken(key) {
            await this.init();

            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([STORE_NAME], 'readwrite');
                const objectStore = transaction.objectStore(STORE_NAME);
                const request = objectStore.delete(key);

                request.onsuccess = () => {
                    resolve();
                };

                request.onerror = () => {
                    reject(new Error('Failed to delete token'));
                };
            });
        }

        /**
         * 更新令牌（刷新时使用）
         * @param {string} key 存储键
         * @param {string} token 新的访问令牌
         * @param {number} expiresIn 新的过期时间（秒）
         * @returns {Promise<void>}
         */
        async updateToken(key, token, expiresIn) {
            await this.init();

            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([STORE_NAME], 'readwrite');
                const objectStore = transaction.objectStore(STORE_NAME);
                const getRequest = objectStore.get(key);

                getRequest.onsuccess = () => {
                    const data = getRequest.result;
                    if (!data) {
                        reject(new Error('Token not found'));
                        return;
                    }

                    data.token = token;
                    data.updatedAt = Date.now();
                    if (expiresIn) {
                        data.expiresAt = Date.now() + (expiresIn * 1000);
                    }

                    const putRequest = objectStore.put(data);
                    putRequest.onsuccess = () => {
                        resolve();
                    };
                    putRequest.onerror = () => {
                        reject(new Error('Failed to update token'));
                    };
                };

                getRequest.onerror = () => {
                    reject(new Error('Failed to get token for update'));
                };
            });
        }

        /**
         * 检查令牌是否过期
         * @param {string} key 存储键
         * @returns {Promise<boolean>} true表示未过期，false表示已过期或不存在
         */
        async isTokenValid(key) {
            const tokenData = await this.getToken(key);
            if (!tokenData) {
                return false;
            }

            if (!tokenData.expiresAt) {
                return true; // 没有过期时间，认为有效
            }

            return tokenData.expiresAt > Date.now();
        }

        /**
         * 获取所有令牌（用于清理过期令牌）
         * @returns {Promise<Array>}
         */
        async getAllTokens() {
            await this.init();

            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([STORE_NAME], 'readonly');
                const objectStore = transaction.objectStore(STORE_NAME);
                const request = objectStore.getAll();

                request.onsuccess = () => {
                    resolve(request.result || []);
                };

                request.onerror = () => {
                    reject(new Error('Failed to get all tokens'));
                };
            });
        }

        /**
         * 清理过期令牌
         * @returns {Promise<number>} 清理的令牌数量
         */
        async cleanExpiredTokens() {
            const tokens = await this.getAllTokens();
            const now = Date.now();
            let cleaned = 0;

            for (const token of tokens) {
                if (token.expiresAt && token.expiresAt < now) {
                    await this.deleteToken(token.key).catch(() => {});
                    cleaned++;
                }
            }

            return cleaned;
        }
    }

    // 创建单例
    const tokenStorage = new TokenStorage();

    // 导出到全局
    window.WelineTokenStorage = tokenStorage;
})(window);

