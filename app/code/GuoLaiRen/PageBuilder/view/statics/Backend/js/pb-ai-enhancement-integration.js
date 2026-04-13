/**
 * PageBuilder AI建站工作台 - P0-P2阻塞修复集成
 * 
 * 整合所有修复功能：
 * - P0: 可观测性增强 (SSE连接追踪与日志)
 * - P1: SSE单连接治理 (前端流控制器)
 * - P2: 操作流推进修复 (状态机和事件持久化)
 */

(function() {
    'use strict';
    
    console.info('[PageBuilder] 开始加载P0-P2阻塞修复集成');
    
    // 全局配置
    const PB_AI_ENHANCEMENT_CONFIG = {
        // P0 可观测性配置
        observability: {
            enabled: true,
            detailedLogging: true,
            maxLogEntries: 1000,
            enableBackendLogging: true,
            enableConsoleLogging: true
        },
        
        // P1 SSE连接治理配置
        connectionGovernance: {
            enabled: true,
            maxConnectionsPerTab: 1,
            maxConnectionsPerSession: 2,
            connectionTimeout: 30000,
            heartbeatInterval: 20000,
            enableDuplicateDetection: true,
            enableHealthMonitoring: true
        },
        
        // P2 操作流推进配置
        operationFlow: {
            enabled: true,
            enableStateMachine: true,
            enableEventPersistence: true,
            enableProgressTracking: true,
            enableHealthMonitoring: true,
            enableTimeoutHandling: true,
            enableRetryMechanism: true,
            operationTimeout: 600000,
            stageTimeout: 120000,
            maxRetries: 3
        },
        
        // 调试配置
        debug: {
            enabled: true,
            enableDebugPanel: true,
            enableExportLogs: true,
            enableHealthReport: true,
            shortcuts: {
                debugPanel: 'Ctrl+Shift+D',
                governanceStatus: 'Ctrl+Shift+G',
                operationFlow: 'Ctrl+Shift+F'
            }
        }
    };
    
    // 全局状态管理
    const PbAiGlobalState = {
        observability: null,
        connectionGovernor: null,
        operationFlowEnhancer: null,
        debugPanel: null,
        
        init() {
            console.info('[PageBuilder] 初始化全局状态');
            
            // 初始化P0可观测性
            if (PB_AI_ENHANCEMENT_CONFIG.observability.enabled) {
                this.initObservability();
            }
            
            // 初始化P1 SSE连接治理
            if (PB_AI_ENHANCEMENT_CONFIG.connectionGovernance.enabled) {
                this.initConnectionGovernance();
            }
            
            // 初始化P2 操作流推进
            if (PB_AI_ENHANCEMENT_CONFIG.operationFlow.enabled) {
                this.initOperationFlow();
            }
            
            // 初始化调试功能
            if (PB_AI_ENHANCEMENT_CONFIG.debug.enabled) {
                this.initDebugFeatures();
            }
            
            console.info('[PageBuilder] 全局状态初始化完成');
        },
        
        initObservability() {
            console.info('[PageBuilder] 初始化P0可观测性');
            
            // 创建全局SSE连接管理器
            if (window.PbAiSseConnectionManager) {
                this.observability = window.PbAiSseConnectionManager;
                console.info('[PageBuilder] P0可观测性已就绪');
            } else {
                console.warn('[PageBuilder] SSE连接管理器未找到');
            }
        },
        
        initConnectionGovernance() {
            console.info('[PageBuilder] 初始化P1 SSE连接治理');
            
            // 创建全局连接治理器
            if (window.pbAiSseConnectionGovernor) {
                this.connectionGovernor = window.pbAiSseConnectionGovernor;
                console.info('[PageBuilder] P1 SSE连接治理已就绪');
            } else {
                console.warn('[PageBuilder] SSE连接治理器未找到');
            }
        },
        
        initOperationFlow() {
            console.info('[PageBuilder] 初始化P2 操作流推进');
            
            // 创建全局操作流增强器
            if (window.PbAiOperationFlowEnhancer) {
                this.operationFlowEnhancer = window.PbAiOperationFlowEnhancer;
                console.info('[PageBuilder] P2 操作流推进已就绪');
            } else {
                console.warn('[PageBuilder] 操作流增强器未找到');
            }
        },
        
        initDebugFeatures() {
            console.info('[PageBuilder] 初始化调试功能');
            
            // 设置全局调试函数
            this.setupDebugFunctions();
            this.setupKeyboardShortcuts();
            
            console.info('[PageBuilder] 调试功能已就绪');
        },
        
        setupDebugFunctions() {
            // 全局调试函数
            window.pbAiGetFullReport = () => {
                return {
                    timestamp: new Date().toISOString(),
                    config: PB_AI_ENHANCEMENT_CONFIG,
                    observability: this.getObservabilityReport(),
                    connectionGovernance: this.getConnectionGovernanceReport(),
                    operationFlow: this.getOperationFlowReport(),
                    system: this.getSystemReport()
                };
            };
            
            window.pbAiExportFullReport = () => {
                const report = window.pbAiGetFullReport();
                const blob = new Blob([JSON.stringify(report, null, 2)], { type: 'application/json' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `pb-ai-enhancement-report-${Date.now()}.json`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                return report;
            };
            
            window.pbAiHealthCheck = () => {
                return {
                    observability: this.checkObservabilityHealth(),
                    connectionGovernance: this.checkConnectionGovernanceHealth(),
                    operationFlow: this.checkOperationFlowHealth(),
                    overall: this.checkOverallHealth()
                };
            };
        },
        
        setupKeyboardShortcuts() {
            document.addEventListener('keydown', (e) => {
                // 调试面板
                if (e.ctrlKey && e.shiftKey && e.key === 'D') {
                    e.preventDefault();
                    this.toggleDebugPanel();
                }
                
                // 治理状态
                if (e.ctrlKey && e.shiftKey && e.key === 'G') {
                    e.preventDefault();
                    this.showGovernanceStatus();
                }
                
                // 操作流状态
                if (e.ctrlKey && e.shiftKey && e.key === 'F') {
                    e.preventDefault();
                    this.showOperationFlowStatus();
                }
                
                // 完整报告
                if (e.ctrlKey && e.shiftKey && e.key === 'R') {
                    e.preventDefault();
                    this.exportFullReport();
                }
            });
        },
        
        // 报告生成方法
        getObservabilityReport() {
            if (!this.observability) return null;
            
            return {
                status: 'active',
                stats: this.observability.getGlobalStats ? this.observability.getGlobalStats() : null,
                loggers: this.observability.loggers ? Object.keys(this.observability.loggers).length : 0
            };
        },
        
        getConnectionGovernanceReport() {
            if (!this.connectionGovernor) return null;
            
            return {
                status: 'active',
                stats: this.connectionGovernor.getStats ? this.connectionGovernor.getStats() : null,
                health: this.connectionGovernor.getConnectionHealthSummary ? 
                       this.connectionGovernor.getConnectionHealthSummary() : null
            };
        },
        
        getOperationFlowReport() {
            if (!this.operationFlowEnhancer) return null;
            
            return {
                status: 'active',
                stats: this.operationFlowEnhancer.getStats ? this.operationFlowEnhancer.getStats() : null,
                activeOperations: this.operationFlowEnhancer.activeOperations ? 
                                this.operationFlowEnhancer.activeOperations.size : 0
            };
        },
        
        getSystemReport() {
            return {
                userAgent: navigator.userAgent,
                platform: navigator.platform,
                language: navigator.language,
                cookieEnabled: navigator.cookieEnabled,
                onLine: navigator.onLine,
                timestamp: new Date().toISOString()
            };
        },
        
        // 健康检查方法
        checkObservabilityHealth() {
            if (!this.observability) return { status: 'disabled', message: '可观测性未启用' };
            
            const stats = this.observability.getGlobalStats ? this.observability.getGlobalStats() : null;
            if (!stats) return { status: 'unknown', message: '无法获取统计信息' };
            
            return {
                status: 'healthy',
                message: '可观测性系统运行正常',
                details: {
                    activeLoggers: stats.activeLoggers || 0,
                    totalConnections: stats.totalConnections || 0,
                    uptime: stats.uptime || 0
                }
            };
        },
        
        checkConnectionGovernanceHealth() {
            if (!this.connectionGovernor) return { status: 'disabled', message: '连接治理未启用' };
            
            const health = this.connectionGovernor.getConnectionHealthSummary ? 
                          this.connectionGovernor.getConnectionHealthSummary() : null;
            if (!health) return { status: 'unknown', message: '无法获取健康信息' };
            
            const isHealthy = health.healthRate && parseFloat(health.healthRate) > 90;
            
            return {
                status: isHealthy ? 'healthy' : 'warning',
                message: isHealthy ? '连接治理系统运行良好' : '连接治理系统存在一些问题',
                details: {
                    healthRate: health.healthRate || '0%',
                    totalConnections: health.total || 0,
                    healthyConnections: health.healthy || 0
                }
            };
        },
        
        checkOperationFlowHealth() {
            if (!this.operationFlowEnhancer) return { status: 'disabled', message: '操作流推进未启用' };
            
            const stats = this.operationFlowEnhancer.getStats ? this.operationFlowEnhancer.getStats() : null;
            if (!stats) return { status: 'unknown', message: '无法获取统计信息' };
            
            const isHealthy = stats.successRate && stats.successRate > 80;
            
            return {
                status: isHealthy ? 'healthy' : 'warning',
                message: isHealthy ? '操作流推进系统运行良好' : '操作流推进系统成功率较低',
                details: {
                    successRate: stats.successRate || 0,
                    totalOperations: stats.totalOperations || 0,
                    activeOperations: stats.activeOperations || 0,
                    averageDuration: stats.averageDuration || 0
                }
            };
        },
        
        checkOverallHealth() {
            const observabilityHealth = this.checkObservabilityHealth();
            const connectionHealth = this.checkConnectionGovernanceHealth();
            const operationHealth = this.checkOperationFlowHealth();
            
            const components = [
                observabilityHealth,
                connectionHealth,
                operationHealth
            ];
            
            const healthyCount = components.filter(c => c.status === 'healthy').length;
            const warningCount = components.filter(c => c.status === 'warning').length;
            const disabledCount = components.filter(c => c.status === 'disabled').length;
            
            let overallStatus = 'healthy';
            let message = '所有系统运行正常';
            
            if (warningCount > 0) {
                overallStatus = 'warning';
                message = `${warningCount}个系统存在警告`;
            }
            
            if (healthyCount === 0 && disabledCount === 0) {
                overallStatus = 'error';
                message = '所有系统都出现问题';
            }
            
            return {
                status: overallStatus,
                message: message,
                components: {
                    healthy: healthyCount,
                    warning: warningCount,
                    disabled: disabledCount,
                    total: components.length
                },
                details: {
                    observability: observabilityHealth,
                    connectionGovernance: connectionHealth,
                    operationFlow: operationHealth
                }
            };
        },
        
        // 调试功能
        toggleDebugPanel() {
            if (window.togglePbAiSseDebugPanel) {
                window.togglePbAiSseDebugPanel();
            } else {
                console.warn('[PageBuilder] 调试面板功能未找到');
            }
        },
        
        showGovernanceStatus() {
            const health = this.checkConnectionGovernanceHealth();
            console.info('[PageBuilder] 连接治理状态:', health);
            
            if (window.BackendToast && window.BackendToast.info) {
                window.BackendToast.info(`连接治理: ${health.status} - ${health.message}`);
            }
        },
        
        showOperationFlowStatus() {
            const health = this.checkOperationFlowHealth();
            console.info('[PageBuilder] 操作流状态:', health);
            
            if (window.BackendToast && window.BackendToast.info) {
                window.BackendToast.info(`操作流: ${health.status} - ${health.message}`);
            }
        },
        
        exportFullReport() {
            const report = window.pbAiGetFullReport();
            console.info('[PageBuilder] 完整报告:', report);
            
            if (window.pbAiExportFullReport) {
                window.pbAiExportFullReport();
            }
        }
    };
    
    // 初始化
    function initializeEnhancements() {
        console.info('[PageBuilder] 开始初始化P0-P2阻塞修复');
        
        // 等待DOM加载完成
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => {
                PbAiGlobalState.init();
            });
        } else {
            PbAiGlobalState.init();
        }
        
        // 页面卸载时清理
        window.addEventListener('beforeunload', () => {
            console.info('[PageBuilder] 页面卸载，清理资源');
            
            if (PbAiGlobalState.connectionGovernor) {
                PbAiGlobalState.connectionGovernor.cleanupAll();
            }
            
            if (PbAiGlobalState.observability) {
                // 清理可观测性资源
            }
        });
        
        console.info('[PageBuilder] P0-P2阻塞修复集成初始化完成');
    }
    
    // 立即初始化
    initializeEnhancements();
    
    // 暴露全局接口
    window.PbAiEnhancementConfig = PB_AI_ENHANCEMENT_CONFIG;
    window.PbAiGlobalState = PbAiGlobalState;
    
    console.info('[PageBuilder] P0-P2阻塞修复集成已加载');
    
})();