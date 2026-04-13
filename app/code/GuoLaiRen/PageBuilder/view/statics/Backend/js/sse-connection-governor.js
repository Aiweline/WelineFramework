/**
 * P1 SSE单连接治理 - 前端流控制器
 * 
 * 解决重复SSE订阅问题，确保同一标签页、同一public_id只保留一个工作区stream-sse连接
 */

class SseConnectionGovernor {
    constructor(options = {}) {
        this.options = {
            maxConnectionsPerTab: 1,
            maxConnectionsPerSession: 3,
            connectionTimeout: 30000, // 30秒
            heartbeatInterval: 20000, // 20秒
            autoReconnectDelay: 5000, // 5秒
            enableConnectionPooling: true,
            enableDuplicateDetection: true,
            enableHealthMonitoring: true,
            ...options
        };
        
        this.connections = new Map();
        this.connectionHistory = [];
        this.activeOperations = new Map();
        this.connectionPool = new Map();
        this.healthStats = {
            totalConnections: 0,
            activeConnections: 0,
            duplicateConnections: 0,
            failedConnections: 0,
            successfulConnections: 0,
            reconnections: 0,
            startTime: Date.now()
        };
        
        this.heartbeatTimer = null;
        this.cleanupTimer = null;
        
        this.initializeGovernor();
    }
    
    initializeGovernor() {
        // 设置全局连接管理器
        if (typeof window !== 'undefined') {
            window.pbAiSseGovernor = this;
            
            // 监听页面生命周期事件
            this.setupLifecycleListeners();
            
            // 启动健康监控
            if (this.options.enableHealthMonitoring) {
                this.startHealthMonitoring();
            }
            
            // 启动清理定时器
            this.startCleanupTimer();
        }
        
        console.info('[SSE Governor] P1单连接治理器已初始化');
    }
    
    setupLifecycleListeners() {
        // 页面可见性变化
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.pauseBackgroundConnections();
            } else {
                this.resumeForegroundConnections();
            }
        });
        
        // 页面卸载
        window.addEventListener('beforeunload', () => {
            this.cleanupAllConnections();
        });
        
        // 页面关闭
        window.addEventListener('unload', () => {
            this.cleanupAllConnections();
        });
    }
    
    // 连接治理核心方法
    requestConnection(connectionId, url, options = {}) {
        const connectionKey = this.generateConnectionKey(url, options);
        const existingConnection = this.connections.get(connectionKey);
        
        this.log('debug', '连接请求', {
            connectionId,
            connectionKey,
            url: this.sanitizeUrl(url),
            hasExisting: !!existingConnection,
            activeConnections: this.connections.size
        });
        
        // 检查重复连接
        if (this.options.enableDuplicateDetection && existingConnection) {
            const duplicateCheck = this.handleDuplicateConnection(connectionId, existingConnection, url, options);
            if (!duplicateCheck.allowed) {
                this.healthStats.duplicateConnections++;
                return {
                    success: false,
                    reason: 'duplicate_connection',
                    message: duplicateCheck.message,
                    existingConnectionId: existingConnection.id
                };
            }
        }
        
        // 检查连接限制
        const limitCheck = this.checkConnectionLimits(connectionKey, url, options);
        if (!limitCheck.allowed) {
            return {
                success: false,
                reason: 'connection_limit_exceeded',
                message: limitCheck.message
            };
        }
        
        // 创建新连接
        return this.createConnection(connectionId, connectionKey, url, options);
    }
    
    handleDuplicateConnection(newConnectionId, existingConnection, url, options) {
        const now = Date.now();
        const connectionAge = now - existingConnection.startTime;
        const isHealthy = this.isConnectionHealthy(existingConnection);
        
        this.log('warn', '检测到重复连接', {
            newConnectionId,
            existingConnectionId: existingConnection.id,
            connectionAge,
            isHealthy,
            url: this.sanitizeUrl(url)
        });
        
        // 如果现有连接健康，拒绝新连接
        if (isHealthy && connectionAge < this.options.connectionTimeout) {
            return {
                allowed: false,
                message: '已存在健康的SSE连接，拒绝重复连接'
            };
        }
        
        // 如果现有连接不健康，允许替换
        if (!isHealthy) {
            this.closeConnection(existingConnection.connectionKey, 'replaced_by_duplicate');
            return {
                allowed: true,
                message: '现有连接不健康，允许新连接替换'
            };
        }
        
        // 如果连接超时，允许新连接
        if (connectionAge >= this.options.connectionTimeout) {
            this.closeConnection(existingConnection.connectionKey, 'timeout_replacement');
            return {
                allowed: true,
                message: '现有连接已超时，允许新连接'
            };
        }
        
        // 默认拒绝
        return {
            allowed: false,
            message: '默认拒绝重复连接'
        };
    }
    
    checkConnectionLimits(connectionKey, url, options) {
        const tabConnections = this.getConnectionsForTab();
        const sessionConnections = this.getConnectionsForSession(url);
        
        // 检查标签页连接限制
        if (tabConnections.length >= this.options.maxConnectionsPerTab) {
            this.log('warn', '标签页连接数超限', {
                current: tabConnections.length,
                limit: this.options.maxConnectionsPerTab,
                url: this.sanitizeUrl(url)
            });
            
            return {
                allowed: false,
                message: `标签页连接数超限（${tabConnections.length}/${this.options.maxConnectionsPerTab}）`
            };
        }
        
        // 检查会话连接限制
        if (sessionConnections.length >= this.options.maxConnectionsPerSession) {
            this.log('warn', '会话连接数超限', {
                current: sessionConnections.length,
                limit: this.options.maxConnectionsPerSession,
                url: this.sanitizeUrl(url)
            });
            
            return {
                allowed: false,
                message: `会话连接数超限（${sessionConnections.length}/${this.options.maxConnectionsPerSession}）`
            };
        }
        
        return { allowed: true };
    }
    
    createConnection(connectionId, connectionKey, url, options) {
        const connection = {
            id: connectionId,
            connectionKey,
            url: this.sanitizeUrl(url),
            originalUrl: url,
            startTime: Date.now(),
            lastActivity: Date.now(),
            messageCount: 0,
            errorCount: 0,
            status: 'connecting',
            options,
            eventSource: null,
            abortController: null,
            metadata: {
                tabId: this.getTabId(),
                sessionId: this.extractSessionId(url),
                publicId: this.extractPublicId(url),
                userAgent: navigator.userAgent,
                startOptions: options
            }
        };
        
        this.connections.set(connectionKey, connection);
        this.healthStats.totalConnections++;
        this.healthStats.activeConnections++;
        
        this.log('info', '创建新连接', {
            connectionId,
            connectionKey,
            url: connection.url,
            metadata: connection.metadata
        });
        
        return {
            success: true,
            connectionId,
            connectionKey,
            connection
        };
    }
    
    // 连接健康监控
    isConnectionHealthy(connection) {
        const now = Date.now();
        const timeSinceLastActivity = now - connection.lastActivity;
        const connectionAge = now - connection.startTime;
        
        // 检查是否超时
        if (connectionAge > this.options.connectionTimeout) {
            return false;
        }
        
        // 检查是否长时间无活动
        if (timeSinceLastActivity > this.options.heartbeatInterval * 2) {
            return false;
        }
        
        // 检查错误率
        if (connection.messageCount > 0) {
            const errorRate = connection.errorCount / connection.messageCount;
            if (errorRate > 0.1) { // 错误率超过10%
                return false;
            }
        }
        
        // 检查连接状态
        if (connection.status === 'error' || connection.status === 'closed') {
            return false;
        }
        
        return true;
    }
    
    // 连接池管理
    getConnectionFromPool(connectionKey) {
        if (!this.options.enableConnectionPooling) {
            return null;
        }
        
        const pooledConnection = this.connectionPool.get(connectionKey);
        if (pooledConnection && this.isConnectionHealthy(pooledConnection)) {
            this.log('debug', '从连接池获取连接', {
                connectionKey,
                connectionId: pooledConnection.id
            });
            return pooledConnection;
        }
        
        return null;
    }
    
    addConnectionToPool(connection) {
        if (!this.options.enableConnectionPooling) {
            return;
        }
        
        if (this.isConnectionHealthy(connection)) {
            this.connectionPool.set(connection.connectionKey, connection);
            this.log('debug', '添加连接到连接池', {
                connectionKey: connection.connectionKey,
                connectionId: connection.id
            });
        }
    }
    
    // 连接生命周期管理
    closeConnection(connectionKey, reason = 'unknown') {
        const connection = this.connections.get(connectionKey);
        if (!connection) {
            return false;
        }
        
        this.log('info', '关闭连接', {
            connectionKey,
            connectionId: connection.id,
            reason,
            duration: Date.now() - connection.startTime,
            messageCount: connection.messageCount
        });
        
        // 关闭EventSource
        if (connection.eventSource) {
            try {
                connection.eventSource.close();
            } catch (e) {
                this.log('error', '关闭EventSource失败', {
                    connectionId: connection.id,
                    error: e.message
                });
            }
        }
        
        // 中止控制器
        if (connection.abortController) {
            try {
                connection.abortController.abort();
            } catch (e) {
                // 忽略中止错误
            }
        }
        
        // 更新统计
        connection.status = 'closed';
        this.healthStats.activeConnections--;
        
        // 记录历史
        this.recordConnectionHistory(connection, reason);
        
        // 从活动连接中移除
        this.connections.delete(connectionKey);
        
        return true;
    }
    
    pauseBackgroundConnections() {
        const backgroundConnections = Array.from(this.connections.values())
            .filter(conn => conn.metadata.tabId === this.getTabId() && conn.status === 'connected');
        
        backgroundConnections.forEach(connection => {
            this.log('info', '暂停后台连接', {
                connectionId: connection.id,
                url: connection.url
            });
            
            connection.status = 'paused';
            if (connection.eventSource) {
                connection.eventSource.close();
            }
        });
    }
    
    resumeForegroundConnections() {
        const pausedConnections = Array.from(this.connections.values())
            .filter(conn => conn.metadata.tabId === this.getTabId() && conn.status === 'paused');
        
        pausedConnections.forEach(connection => {
            this.log('info', '恢复前台连接', {
                connectionId: connection.id,
                url: connection.url
            });
            
            // 触发重连
            this.triggerReconnection(connection);
        });
    }
    
    triggerReconnection(connection) {
        this.healthStats.reconnections++;
        
        this.log('info', '触发重连', {
            connectionId: connection.id,
            url: connection.url,
            delay: this.options.autoReconnectDelay
        });
        
        setTimeout(() => {
            if (this.connections.has(connection.connectionKey)) {
                this.closeConnection(connection.connectionKey, 'reconnection_triggered');
            }
        }, this.options.autoReconnectDelay);
    }
    
    // 健康监控
    startHealthMonitoring() {
        this.heartbeatTimer = setInterval(() => {
            this.performHealthCheck();
        }, this.options.heartbeatInterval);
        
        this.log('info', '健康监控已启动', {
            interval: this.options.heartbeatInterval
        });
    }
    
    performHealthCheck() {
        const now = Date.now();
        const unhealthyConnections = [];
        
        this.connections.forEach((connection, key) => {
            if (!this.isConnectionHealthy(connection)) {
                unhealthyConnections.push({ key, connection });
            }
            
            // 更新连接统计
            connection.lastActivity = now;
        });
        
        // 关闭不健康的连接
        unhealthyConnections.forEach(({ key, connection }) => {
            this.log('warn', '健康检查发现不健康连接', {
                connectionId: connection.id,
                reason: 'health_check_failed',
                age: now - connection.startTime,
                lastActivity: now - connection.lastActivity
            });
            
            this.closeConnection(key, 'health_check_failed');
        });
        
        this.log('debug', '健康检查完成', {
            totalConnections: this.connections.size,
            unhealthyConnections: unhealthyConnections.length,
            activeConnections: this.healthStats.activeConnections
        });
    }
    
    // 清理定时器
    startCleanupTimer() {
        this.cleanupTimer = setInterval(() => {
            this.performCleanup();
        }, 60000); // 每分钟清理一次
    }
    
    performCleanup() {
        const now = Date.now();
        let cleanedConnections = 0;
        let cleanedPoolConnections = 0;
        
        // 清理超时的连接池
        this.connectionPool.forEach((connection, key) => {
            if (now - connection.lastActivity > this.options.connectionTimeout * 2) {
                this.connectionPool.delete(key);
                cleanedPoolConnections++;
            }
        });
        
        // 清理历史记录
        const maxHistoryAge = 3600000; // 1小时
        this.connectionHistory = this.connectionHistory.filter(record => 
            now - record.timestamp < maxHistoryAge
        );
        
        this.log('info', '清理完成', {
            cleanedPoolConnections,
            remainingPoolConnections: this.connectionPool.size,
            remainingHistory: this.connectionHistory.length,
            totalConnections: this.connections.size
        });
    }
    
    // 工具方法
    generateConnectionKey(url, options = {}) {
        try {
            const urlObj = new URL(url);
            const baseUrl = urlObj.origin + urlObj.pathname;
            const publicId = urlObj.searchParams.get('public_id') || '';
            const tabToken = urlObj.searchParams.get('tab_token') || '';
            const executionToken = urlObj.searchParams.get('execution_token') || '';
            
            // 根据连接类型生成不同的键
            const isOperationSse = baseUrl.includes('operation-sse');
            const isStreamSse = baseUrl.includes('stream-sse');
            
            if (isOperationSse) {
                return `operation_${publicId}_${executionToken}_${this.getTabId()}`;
            } else if (isStreamSse) {
                return `stream_${publicId}_${tabToken}_${this.getTabId()}`;
            } else {
                return `other_${baseUrl}_${publicId}_${this.getTabId()}`;
            }
        } catch (e) {
            return `fallback_${url.slice(0, 50)}_${this.getTabId()}`;
        }
    }
    
    getTabId() {
        try {
            // 使用sessionStorage来标识标签页
            let tabId = sessionStorage.getItem('pb_ai_sse_tab_id');
            if (!tabId) {
                tabId = 'tab_' + Date.now() + '_' + Math.random().toString(36).slice(2);
                sessionStorage.setItem('pb_ai_sse_tab_id', tabId);
            }
            return tabId;
        } catch (e) {
            return 'fallback_tab';
        }
    }
    
    extractSessionId(url) {
        try {
            const urlObj = new URL(url);
            return urlObj.searchParams.get('session_id') || '';
        } catch (e) {
            return '';
        }
    }
    
    extractPublicId(url) {
        try {
            const urlObj = new URL(url);
            return urlObj.searchParams.get('public_id') || '';
        } catch (e) {
            return '';
        }
    }
    
    sanitizeUrl(url) {
        try {
            const urlObj = new URL(url);
            // 移除敏感参数
            ['token', 'key', 'secret', 'password'].forEach(param => {
                urlObj.searchParams.delete(param);
            });
            return urlObj.toString();
        } catch (e) {
            return String(url).replace(/[?&](token|key|secret|password)=[^&]*/g, '');
        }
    }
    
    getConnectionsForTab() {
        const tabId = this.getTabId();
        return Array.from(this.connections.values())
            .filter(conn => conn.metadata.tabId === tabId);
    }
    
    getConnectionsForSession(url) {
        const sessionId = this.extractSessionId(url);
        const publicId = this.extractPublicId(url);
        
        return Array.from(this.connections.values())
            .filter(conn => 
                conn.metadata.sessionId === sessionId || 
                conn.metadata.publicId === publicId
            );
    }
    
    recordConnectionHistory(connection, reason) {
        this.connectionHistory.push({
            timestamp: Date.now(),
            connectionId: connection.id,
            connectionKey: connection.connectionKey,
            url: connection.url,
            duration: Date.now() - connection.startTime,
            messageCount: connection.messageCount,
            reason,
            metadata: connection.metadata
        });
        
        // 限制历史记录大小
        if (this.connectionHistory.length > 1000) {
            this.connectionHistory = this.connectionHistory.slice(-500);
        }
    }
    
    // 日志方法
    log(level, message, context = {}) {
        if (typeof console !== 'undefined') {
            const logMethod = console[level] || console.log;
            const enhancedContext = {
                ...context,
                timestamp: new Date().toISOString(),
                governorStats: this.getStats()
            };
            
            if (Object.keys(enhancedContext).length > 0) {
                logMethod(`[SSE Governor] ${message}`, enhancedContext);
            } else {
                logMethod(`[SSE Governor] ${message}`);
            }
        }
    }
    
    // 统计和报告
    getStats() {
        const now = Date.now();
        return {
            ...this.healthStats,
            uptime: now - this.healthStats.startTime,
            activeConnections: this.connections.size,
            connectionPoolSize: this.connectionPool.size,
            recentConnections: this.connectionHistory.slice(-10),
            connectionHealth: this.getConnectionHealthSummary()
        };
    }
    
    getConnectionHealthSummary() {
        const connections = Array.from(this.connections.values());
        const healthyConnections = connections.filter(conn => this.isConnectionHealthy(conn));
        
        return {
            total: connections.length,
            healthy: healthyConnections.length,
            unhealthy: connections.length - healthyConnections.length,
            healthRate: connections.length > 0 ? (healthyConnections.length / connections.length * 100).toFixed(2) + '%' : '0%'
        };
    }
    
    // 清理方法
    cleanupAllConnections() {
        this.log('info', '清理所有连接');
        
        // 关闭所有活动连接
        this.connections.forEach((connection, key) => {
            this.closeConnection(key, 'cleanup_all');
        });
        
        // 清理连接池
        this.connectionPool.clear();
        
        // 停止定时器
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
        
        if (this.cleanupTimer) {
            clearInterval(this.cleanupTimer);
            this.cleanupTimer = null;
        }
        
        this.log('info', '清理完成', {
            remainingConnections: this.connections.size,
            finalStats: this.getStats()
        });
    }
}

// 创建全局实例
if (typeof window !== 'undefined') {
    window.pbAiSseConnectionGovernor = new SseConnectionGovernor({
        maxConnectionsPerTab: 1,
        maxConnectionsPerSession: 2,
        enableDetailedLogging: true,
        enableDuplicateDetection: true,
        enableHealthMonitoring: true
    });
    
    console.info('[SSE Governor] P1单连接治理器已就绪');
}

export { SseConnectionGovernor };