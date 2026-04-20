/**
 * P0 可观测性增强：SSE连接追踪与日志
 *
 * 提供详细的SSE连接状态追踪，帮助诊断重复订阅、连接泄露等问题
 */

class EnhancedSseLogger {
    constructor(options = {}) {
        this.componentId = options.componentId || 'unknown';
        this.publicId = options.publicId || 'unknown';
        this.tabToken = options.tabToken || this.generateTabToken();
        this.enableDetailedLogging = options.enableDetailedLogging ?? true;
        this.maxLogEntries = options.maxLogEntries || 1000;
        this.logEntries = [];
        this.connectionStats = {
            connectAttempts: 0,
            successfulConnections: 0,
            failedConnections: 0,
            reconnections: 0,
            duplicateConnections: 0,
            messagesReceived: 0,
            errorsReceived: 0,
            startTime: Date.now(),
            lastActivityTime: Date.now()
        };
        this.activeConnections = new Map();
        this.eventIdTracking = new Set();

        this.initializeLogging();
    }

    generateTabToken() {
        try {
            const storageKey = 'pb_ai_sse_tab_token_' + this.componentId;
            let existing = sessionStorage.getItem(storageKey);
            if (existing && existing.length <= 64) {
                return existing;
            }
            const token = 't' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);
            sessionStorage.setItem(storageKey, token);
            return token;
        } catch (e) {
            return 't' + Date.now().toString(36);
        }
    }

    initializeLogging() {
        if (this.enableDetailedLogging && typeof window !== 'undefined') {
            window.pbAiSseLogs = window.pbAiSseLogs || {};
            window.pbAiSseLogs[this.componentId] = this;
        }
    }

    log(level, message, context = {}) {
        const timestamp = new Date().toISOString();
        const entry = {
            timestamp,
            level,
            message,
            context: {
                componentId: this.componentId,
                publicId: this.publicId,
                tabToken: this.tabToken,
                connectionStats: { ...this.connectionStats },
                ...context
            }
        };

        this.logEntries.push(entry);
        if (this.logEntries.length > this.maxLogEntries) {
            this.logEntries.shift();
        }

        // 控制台输出
        if (this.enableDetailedLogging) {
            const consoleMethod = this.getConsoleMethod(level);
            const logMessage = `[SSE:${this.componentId}] ${message}`;
            const logContext = {
                tabToken: this.tabToken,
                stats: this.connectionStats,
                ...context
            };

            if (Object.keys(logContext).length > 0) {
                consoleMethod(logMessage, logContext);
            } else {
                consoleMethod(logMessage);
            }
        }

        // 发送到后端日志（如果配置允许）
        this.sendToBackend(level, message, entry.context);
    }

    getConsoleMethod(level) {
        const methods = {
            debug: console.debug,
            info: console.info,
            warn: console.warn,
            error: console.error
        };
        return methods[level] || console.log;
    }

    sendToBackend(level, message, context) {
        // 只发送重要日志到后端，避免过多网络请求
        if (['error', 'warn'].includes(level)) {
            try {
                if (typeof window !== 'undefined' && window.fetch) {
                    // 使用Beacon API确保页面卸载时也能发送
                    const logData = {
                        level,
                        message,
                        context,
                        timestamp: new Date().toISOString()
                    };

                    if (navigator.sendBeacon) {
                        navigator.sendBeacon('/api/sse-logs', JSON.stringify(logData));
                    }
                }
            } catch (e) {
                // 忽略日志发送错误
            }
        }
    }

    // 连接相关日志
    logConnectionAttempt(url, params = {}) {
        this.connectionStats.connectAttempts++;
        this.connectionStats.lastActivityTime = Date.now();

        this.log('info', 'SSE连接尝试', {
            event: 'connection_attempt',
            url: this.sanitizeUrl(url),
            params: this.sanitizeParams(params),
            attemptNumber: this.connectionStats.connectAttempts
        });
    }

    logConnectionSuccess(url, eventSource) {
        this.connectionStats.successfulConnections++;
        this.connectionStats.lastActivityTime = Date.now();

        const connectionId = this.generateConnectionId();
        this.activeConnections.set(connectionId, {
            eventSource,
            url: this.sanitizeUrl(url),
            startTime: Date.now(),
            messageCount: 0
        });

        this.log('info', 'SSE连接成功', {
            event: 'connection_success',
            connectionId,
            url: this.sanitizeUrl(url),
            activeConnections: this.activeConnections.size
        });

        return connectionId;
    }

    logConnectionError(url, error, readyState) {
        this.connectionStats.failedConnections++;
        this.connectionStats.lastActivityTime = Date.now();

        this.log('error', 'SSE连接错误', {
            event: 'connection_error',
            url: this.sanitizeUrl(url),
            error: error?.message || String(error),
            readyState,
            totalFailures: this.connectionStats.failedConnections
        });
    }

    logConnectionClosed(url, connectionId, reason = '') {
        if (connectionId && this.activeConnections.has(connectionId)) {
            const connection = this.activeConnections.get(connectionId);
            const duration = Date.now() - connection.startTime;

            this.activeConnections.delete(connectionId);

            this.log('info', 'SSE连接关闭', {
                event: 'connection_closed',
                connectionId,
                url: this.sanitizeUrl(url),
                duration,
                reason,
                messagesReceived: connection.messageCount,
                remainingConnections: this.activeConnections.size
            });
        }
    }

    logMessageReceived(connectionId, eventType, data, eventId = null) {
        this.connectionStats.messagesReceived++;
        this.connectionStats.lastActivityTime = Date.now();

        if (eventId) {
            this.eventIdTracking.add(eventId);
        }

        if (connectionId && this.activeConnections.has(connectionId)) {
            const connection = this.activeConnections.get(connectionId);
            connection.messageCount++;
        }

        // 检测重复消息
        const isDuplicate = this.checkDuplicateMessage(eventType, data, eventId);

        this.log('debug', 'SSE消息接收', {
            event: 'message_received',
            connectionId,
            eventType,
            eventId,
            isDuplicate,
            totalMessages: this.connectionStats.messagesReceived,
            uniqueEventIds: this.eventIdTracking.size
        });
    }

    logDuplicateConnectionDetected(url, existingConnectionId) {
        this.connectionStats.duplicateConnections++;

        this.log('warn', '检测到重复SSE连接', {
            event: 'duplicate_connection',
            url: this.sanitizeUrl(url),
            existingConnectionId,
            totalDuplicates: this.connectionStats.duplicateConnections,
            activeConnections: this.activeConnections.size
        });
    }

    logReconnectionAttempt(url, attemptNumber, delay) {
        this.connectionStats.reconnections++;

        this.log('info', 'SSE重连尝试', {
            event: 'reconnection_attempt',
            url: this.sanitizeUrl(url),
            attemptNumber,
            delay,
            totalReconnections: this.connectionStats.reconnections
        });
    }

    // 辅助方法
    generateConnectionId() {
        return 'conn_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
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

    sanitizeParams(params) {
        const sanitized = { ...params };
        ['token', 'key', 'secret', 'password'].forEach(key => {
            if (sanitized[key]) {
                sanitized[key] = '[REDACTED]';
            }
        });
        return sanitized;
    }

    checkDuplicateMessage(eventType, data, eventId) {
        if (!eventId) {
            // 基于内容指纹检测重复
            const fingerprint = this.generateMessageFingerprint(eventType, data);
            if (this.recentMessageFingerprints.has(fingerprint)) {
                return true;
            }
            this.recentMessageFingerprints = this.recentMessageFingerprints || new Set();
            this.recentMessageFingerprints.add(fingerprint);

            // 清理旧指纹
            setTimeout(() => {
                if (this.recentMessageFingerprints) {
                    this.recentMessageFingerprints.delete(fingerprint);
                }
            }, 5000);

            return false;
        }

        return this.eventIdTracking.has(eventId);
    }

    generateMessageFingerprint(eventType, data) {
        try {
            const content = JSON.stringify({ eventType, data });
            return eventType + '_' + this.simpleHash(content);
        } catch (e) {
            return eventType + '_' + String(data).slice(0, 50);
        }
    }

    simpleHash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // 转换为32位整数
        }
        return Math.abs(hash).toString(36);
    }

    // 获取统计信息
    getStats() {
        const uptime = Date.now() - this.connectionStats.startTime;
        return {
            ...this.connectionStats,
            uptime,
            activeConnections: this.activeConnections.size,
            uniqueEventIds: this.eventIdTracking.size,
            logEntryCount: this.logEntries.length
        };
    }

    // 导出日志用于调试
    exportLogs() {
        return {
            componentId: this.componentId,
            publicId: this.publicId,
            tabToken: this.tabToken,
            stats: this.getStats(),
            logs: this.logEntries,
            exportTime: new Date().toISOString()
        };
    }

    // 清理资源
    cleanup() {
        this.activeConnections.forEach((connection, connectionId) => {
            if (connection.eventSource) {
                try {
                    connection.eventSource.close();
                } catch (e) {
                    // 忽略关闭错误
                }
            }
        });
        this.activeConnections.clear();
        this.eventIdTracking.clear();

        this.log('info', 'SSE日志器清理完成', {
            event: 'cleanup_completed',
            finalStats: this.getStats()
        });
    }
}

// 全局SSE连接管理器
class GlobalSseConnectionManager {
    constructor() {
        this.loggers = new Map();
        this.globalStats = {
            totalLoggers: 0,
            totalConnections: 0,
            totalMessages: 0,
            startTime: Date.now()
        };
    }

    createLogger(componentId, options = {}) {
        const logger = new EnhancedSseLogger({
            componentId,
            ...options
        });

        this.loggers.set(componentId, logger);
        this.globalStats.totalLoggers++;

        return logger;
    }

    getLogger(componentId) {
        return this.loggers.get(componentId);
    }

    removeLogger(componentId) {
        const logger = this.loggers.get(componentId);
        if (logger) {
            logger.cleanup();
            this.loggers.delete(componentId);

            // 更新全局统计
            this.globalStats.totalConnections -= logger.connectionStats.successfulConnections;
            this.globalStats.totalMessages -= logger.connectionStats.messagesReceived;
        }
    }

    getGlobalStats() {
        const activeConnections = Array.from(this.loggers.values())
            .reduce((sum, logger) => sum + logger.activeConnections.size, 0);

        return {
            ...this.globalStats,
            activeLoggers: this.loggers.size,
            activeConnections,
            uptime: Date.now() - this.globalStats.startTime
        };
    }

    exportAllLogs() {
        const exportData = {
            globalStats: this.getGlobalStats(),
            loggers: {},
            exportTime: new Date().toISOString()
        };

        this.loggers.forEach((logger, componentId) => {
            exportData.loggers[componentId] = logger.exportLogs();
        });

        return exportData;
    }

    cleanupAll() {
        this.loggers.forEach((logger, componentId) => {
            this.removeLogger(componentId);
        });

        this.loggers.clear();
        this.globalStats = {
            totalLoggers: 0,
            totalConnections: 0,
            totalMessages: 0,
            startTime: Date.now()
        };
    }
}

// 创建全局管理器实例
window.PbAiSseConnectionManager = window.PbAiSseConnectionManager || new GlobalSseConnectionManager();

// 导出类供外部使用
window.EnhancedSseLogger = EnhancedSseLogger;
window.GlobalSseConnectionManager = GlobalSseConnectionManager;

export { EnhancedSseLogger, GlobalSseConnectionManager };
