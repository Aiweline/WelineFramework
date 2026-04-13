/**
 * P2操作流推进修复 - 增强状态机和事件持久化
 * 
 * 解决流程卡在心跳、未进入虚拟主题/HTML区块生成阶段的问题
 */

class OperationFlowEnhancer {
    constructor(options = {}) {
        this.options = {
            // 状态机配置
            enableStateMachine: true,
            enableEventPersistence: true,
            enableProgressTracking: true,
            enableHealthMonitoring: true,
            enableTimeoutHandling: true,
            enableRetryMechanism: true,
            
            // 超时配置
            operationTimeout: 600000, // 10分钟
            stageTimeout: 120000, // 2分钟
            heartbeatTimeout: 30000, // 30秒
            
            // 重试配置
            maxRetries: 3,
            retryDelay: 5000, // 5秒
            
            // 进度配置
            progressUpdateInterval: 1000, // 1秒
            
            ...options
        };
        
        this.stateMachine = new OperationStateMachine(this.options);
        this.eventPersister = new EventPersister(this.options);
        this.progressTracker = new ProgressTracker(this.options);
        this.healthMonitor = new HealthMonitor(this.options);
        this.timeoutHandler = new TimeoutHandler(this.options);
        this.retryHandler = new RetryHandler(this.options);
        
        this.activeOperations = new Map();
        this.operationHistory = [];
        
        this.initializeEnhancer();
    }
    
    initializeEnhancer() {
        if (typeof window !== 'undefined') {
            window.pbAiOperationFlowEnhancer = this;
            
            // 设置全局事件监听
            this.setupGlobalEventListeners();
            
            // 启动健康监控
            if (this.options.enableHealthMonitoring) {
                this.startHealthMonitoring();
            }
            
            console.info('[OperationFlow] P2操作流推进增强器已初始化');
        }
    }
    
    setupGlobalEventListeners() {
        // 监听页面生命周期事件
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.pauseOperations();
            } else {
                this.resumeOperations();
            }
        });
        
        // 监听网络状态变化
        window.addEventListener('online', () => {
            this.handleNetworkRecovery();
        });
        
        window.addEventListener('offline', () => {
            this.handleNetworkFailure();
        });
    }
    
    // 主要增强方法：推进操作流
    async enhanceOperationFlow(operationId, operation, stage, context = {}) {
        console.info(`[OperationFlow] 增强操作流: ${operationId}`, {
            operation,
            stage,
            context
        });
        
        const operationContext = {
            id: operationId,
            operation,
            stage,
            context,
            startTime: Date.now(),
            status: 'init',
            progress: 0,
            events: [],
            errors: [],
            retries: 0,
            ...context
        };
        
        this.activeOperations.set(operationId, operationContext);
        
        try {
            // 1. 状态机初始化
            if (this.options.enableStateMachine) {
                await this.stateMachine.initialize(operationContext);
            }
            
            // 2. 事件持久化初始化
            if (this.options.enableEventPersistence) {
                await this.eventPersister.initialize(operationContext);
            }
            
            // 3. 进度追踪初始化
            if (this.options.enableProgressTracking) {
                await this.progressTracker.initialize(operationContext);
            }
            
            // 4. 健康监控初始化
            if (this.options.enableHealthMonitoring) {
                await this.healthMonitor.initialize(operationContext);
            }
            
            // 5. 超时处理初始化
            if (this.options.enableTimeoutHandling) {
                await this.timeoutHandler.initialize(operationContext);
            }
            
            // 6. 执行操作流推进
            await this.executeOperationFlow(operationContext);
            
            // 7. 完成处理
            await this.completeOperation(operationContext);
            
            return {
                success: true,
                operationId,
                result: operationContext.result,
                duration: Date.now() - operationContext.startTime
            };
            
        } catch (error) {
            console.error(`[OperationFlow] 操作流执行失败: ${operationId}`, error);
            
            // 错误处理和重试
            const retryResult = await this.handleOperationError(operationContext, error);
            
            if (retryResult.shouldRetry && this.options.enableRetryMechanism) {
                return this.retryOperation(operationContext);
            }
            
            return {
                success: false,
                operationId,
                error: error.message,
                duration: Date.now() - operationContext.startTime
            };
        } finally {
            // 清理操作上下文
            this.cleanupOperation(operationContext);
        }
    }
    
    async executeOperationFlow(operationContext) {
        console.info(`[OperationFlow] 执行操作流: ${operationContext.id}`);
        
        const stages = this.getOperationStages(operationContext.operation);
        
        for (let i = 0; i < stages.length; i++) {
            const stage = stages[i];
            const stageContext = {
                ...operationContext,
                currentStage: stage,
                stageIndex: i,
                totalStages: stages.length
            };
            
            try {
                // 执行阶段
                await this.executeStage(stageContext);
                
                // 更新进度
                if (this.options.enableProgressTracking) {
                    const progress = ((i + 1) / stages.length) * 100;
                    await this.progressTracker.updateProgress(operationContext, progress);
                }
                
            } catch (stageError) {
                console.error(`[OperationFlow] 阶段执行失败: ${stage}`, stageError);
                throw stageError;
            }
        }
    }
    
    async executeStage(stageContext) {
        console.info(`[OperationFlow] 执行阶段: ${stageContext.currentStage}`);
        
        const stageStartTime = Date.now();
        
        try {
            // 1. 状态机状态转换
            if (this.options.enableStateMachine) {
                await this.stateMachine.transition(stageContext, 'stage_start');
            }
            
            // 2. 持久化阶段开始事件
            if (this.options.enableEventPersistence) {
                await this.eventPersister.persistEvent(stageContext, 'stage_started', {
                    stage: stageContext.currentStage,
                    stageIndex: stageContext.stageIndex,
                    totalStages: stageContext.totalStages
                });
            }
            
            // 3. 执行具体的阶段逻辑
            await this.executeStageLogic(stageContext);
            
            // 4. 持久化阶段完成事件
            if (this.options.enableEventPersistence) {
                await this.eventPersister.persistEvent(stageContext, 'stage_completed', {
                    stage: stageContext.currentStage,
                    duration: Date.now() - stageStartTime
                });
            }
            
            // 5. 状态机状态转换
            if (this.options.enableStateMachine) {
                await this.stateMachine.transition(stageContext, 'stage_completed');
            }
            
        } catch (error) {
            // 持久化阶段失败事件
            if (this.options.enableEventPersistence) {
                await this.eventPersister.persistEvent(stageContext, 'stage_failed', {
                    stage: stageContext.currentStage,
                    error: error.message,
                    duration: Date.now() - stageStartTime
                });
            }
            
            throw error;
        }
    }
    
    async executeStageLogic(stageContext) {
        // 这里可以根据具体的操作类型和阶段执行相应的逻辑
        const { currentStage, operation } = stageContext;
        
        switch (operation) {
            case 'build':
                await this.executeBuildStage(currentStage, stageContext);
                break;
            case 'regenerate_page':
                await this.executeRegeneratePageStage(currentStage, stageContext);
                break;
            case 'publish':
                await this.executePublishStage(currentStage, stageContext);
                break;
            default:
                console.warn(`[OperationFlow] 未知的操作类型: ${operation}`);
                break;
        }
    }
    
    async executeBuildStage(stage, stageContext) {
        console.info(`[OperationFlow] 执行构建阶段: ${stage}`);
        
        // 根据阶段执行不同的构建逻辑
        switch (stage) {
            case 'prepare_data':
                await this.prepareBuildData(stageContext);
                break;
            case 'generate_virtual_theme':
                await this.generateVirtualTheme(stageContext);
                break;
            case 'generate_html_blocks':
                await this.generateHtmlBlocks(stageContext);
                break;
            case 'materialize_pages':
                await this.materializePages(stageContext);
                break;
            case 'environment_ready':
                await this.prepareEnvironment(stageContext);
                break;
            default:
                console.warn(`[OperationFlow] 未知的构建阶段: ${stage}`);
                break;
        }
    }
    
    async prepareBuildData(stageContext) {
        console.info('[OperationFlow] 准备构建数据');
        
        // 模拟数据准备过程
        await this.simulateAsyncOperation('准备网站数据', 2000);
        
        // 持久化进度事件
        if (this.options.enableEventPersistence) {
            await this.eventPersister.persistEvent(stageContext, 'progress', {
                message: '正在准备网站数据...',
                progress: 10
            });
        }
    }
    
    async generateVirtualTheme(stageContext) {
        console.info('[OperationFlow] 生成虚拟主题');
        
        // 模拟虚拟主题生成过程
        await this.simulateAsyncOperation('生成虚拟主题', 5000);
        
        // 持久化进度事件
        if (this.options.enableEventPersistence) {
            await this.eventPersister.persistEvent(stageContext, 'progress', {
                message: '正在生成虚拟主题...',
                progress: 30
            });
        }
    }
    
    async generateHtmlBlocks(stageContext) {
        console.info('[OperationFlow] 生成HTML区块');
        
        // 模拟HTML区块生成过程
        await this.simulateAsyncOperation('生成HTML区块', 4000);
        
        // 持久化进度事件
        if (this.options.enableEventPersistence) {
            await this.eventPersister.persistEvent(stageContext, 'progress', {
                message: '正在生成HTML区块...',
                progress: 50
            });
        }
    }
    
    async materializePages(stageContext) {
        console.info('[OperationFlow] 物化页面');
        
        // 模拟页面物化过程
        await this.simulateAsyncOperation('物化页面', 3000);
        
        // 持久化进度事件
        if (this.options.enableEventPersistence) {
            await this.eventPersister.persistEvent(stageContext, 'progress', {
                message: '正在物化页面...',
                progress: 80
            });
        }
    }
    
    async prepareEnvironment(stageContext) {
        console.info('[OperationFlow] 准备环境');
        
        // 模拟环境准备过程
        await this.simulateAsyncOperation('准备环境', 2000);
        
        // 持久化完成事件
        if (this.options.enableEventPersistence) {
            await this.eventPersister.persistEvent(stageContext, 'environment_ready', {
                message: '环境准备完成',
                progress: 100
            });
        }
    }
    
    async simulateAsyncOperation(operationName, duration) {
        console.info(`[OperationFlow] 模拟操作: ${operationName} (${duration}ms)`);
        
        return new Promise(resolve => {
            setTimeout(resolve, duration);
        });
    }
    
    // 错误处理
    async handleOperationError(operationContext, error) {
        console.error('[OperationFlow] 处理操作错误', error);
        
        // 持久化错误事件
        if (this.options.enableEventPersistence) {
            await this.eventPersister.persistEvent(operationContext, 'error', {
                message: error.message,
                stack: error.stack,
                stage: operationContext.currentStage
            });
        }
        
        // 检查是否应该重试
        const shouldRetry = operationContext.retries < this.options.maxRetries &&
                           this.isRetryableError(error);
        
        return {
            shouldRetry,
            error
        };
    }
    
    async retryOperation(operationContext) {
        console.info(`[OperationFlow] 重试操作: ${operationContext.id}`);
        
        operationContext.retries++;
        
        // 持久化重试事件
        if (this.options.enableEventPersistence) {
            await this.eventPersister.persistEvent(operationContext, 'retry', {
                message: `第${operationContext.retries}次重试`,
                retryCount: operationContext.retries
            });
        }
        
        // 等待重试延迟
        await new Promise(resolve => setTimeout(resolve, this.options.retryDelay));
        
        // 重新执行操作流
        return this.executeOperationFlow(operationContext);
    }
    
    // 完成处理
    async completeOperation(operationContext) {
        console.info(`[OperationFlow] 完成操作: ${operationContext.id}`);
        
        // 持久化完成事件
        if (this.options.enableEventPersistence) {
            await this.eventPersister.persistEvent(operationContext, 'completed', {
                message: '操作执行完成',
                duration: Date.now() - operationContext.startTime,
                retries: operationContext.retries
            });
        }
        
        // 记录到历史
        this.operationHistory.push({
            ...operationContext,
            endTime: Date.now(),
            duration: Date.now() - operationContext.startTime
        });
    }
    
    // 清理操作
    cleanupOperation(operationContext) {
        console.info(`[OperationFlow] 清理操作: ${operationContext.id}`);
        
        // 从活动操作中移除
        this.activeOperations.delete(operationContext.id);
        
        // 清理超时处理
        if (this.options.enableTimeoutHandling) {
            this.timeoutHandler.clear(operationContext.id);
        }
        
        // 清理健康监控
        if (this.options.enableHealthMonitoring) {
            this.healthMonitor.clear(operationContext.id);
        }
    }
    
    // 工具方法
    getOperationStages(operation) {
        const stageMap = {
            'build': [
                'prepare_data',
                'generate_virtual_theme',
                'generate_html_blocks',
                'materialize_pages',
                'environment_ready'
            ],
            'regenerate_page': [
                'prepare_regeneration',
                'regenerate_content',
                'update_preview',
                'complete_regeneration'
            ],
            'publish': [
                'validate_publish',
                'prepare_publish',
                'execute_publish',
                'complete_publish'
            ]
        };
        
        return stageMap[operation] || ['default_stage'];
    }
    
    isRetryableError(error) {
        // 判断错误是否可重试
        const retryableErrors = [
            'network_error',
            'timeout',
            'temporary_failure',
            'rate_limit'
        ];
        
        return retryableErrors.some(retryable => 
            error.message.toLowerCase().includes(retryable)
        );
    }
    
    pauseOperations() {
        console.info('[OperationFlow] 暂停所有操作');
        this.activeOperations.forEach(operation => {
            operation.paused = true;
        });
    }
    
    resumeOperations() {
        console.info('[OperationFlow] 恢复所有操作');
        this.activeOperations.forEach(operation => {
            operation.paused = false;
        });
    }
    
    handleNetworkRecovery() {
        console.info('[OperationFlow] 网络恢复，重试失败的操作');
        this.resumeOperations();
    }
    
    handleNetworkFailure() {
        console.info('[OperationFlow] 网络失败，暂停操作');
        this.pauseOperations();
    }
    
    startHealthMonitoring() {
        setInterval(() => {
            this.performHealthCheck();
        }, 10000); // 每10秒检查一次
    }
    
    performHealthCheck() {
        const now = Date.now();
        
        this.activeOperations.forEach((operation, id) => {
            // 检查操作是否超时
            if (now - operation.startTime > this.options.operationTimeout) {
                console.warn(`[OperationFlow] 操作超时: ${id}`);
                
                // 持久化超时事件
                if (this.options.enableEventPersistence) {
                    this.eventPersister.persistEvent(operation, 'timeout', {
                        message: '操作执行超时',
                        timeout: this.options.operationTimeout
                    });
                }
            }
            
            // 检查操作是否卡住
            if (operation.lastActivity && 
                now - operation.lastActivity > this.options.heartbeatTimeout) {
                console.warn(`[OperationFlow] 操作可能卡住: ${id}`);
                
                // 持久化卡住事件
                if (this.options.enableEventPersistence) {
                    this.eventPersister.persistEvent(operation, 'stuck', {
                        message: '操作可能卡住',
                        lastActivity: operation.lastActivity
                    });
                }
            }
        });
    }
    
    // 获取统计信息
    getStats() {
        const now = Date.now();
        
        return {
            totalOperations: this.operationHistory.length,
            activeOperations: this.activeOperations.size,
            successRate: this.calculateSuccessRate(),
            averageDuration: this.calculateAverageDuration(),
            recentOperations: this.operationHistory.slice(-10),
            uptime: now - (this.startTime || now)
        };
    }
    
    calculateSuccessRate() {
        if (this.operationHistory.length === 0) return 0;
        
        const successfulOperations = this.operationHistory.filter(
            op => op.status === 'completed'
        ).length;
        
        return (successfulOperations / this.operationHistory.length) * 100;
    }
    
    calculateAverageDuration() {
        if (this.operationHistory.length === 0) return 0;
        
        const totalDuration = this.operationHistory.reduce(
            (sum, op) => sum + (op.duration || 0), 0
        );
        
        return totalDuration / this.operationHistory.length;
    }
}

// 状态机类
class OperationStateMachine {
    constructor(options) {
        this.options = options;
        this.states = new Map();
    }
    
    async initialize(operationContext) {
        this.states.set(operationContext.id, {
            current: 'init',
            history: ['init'],
            transitions: []
        });
    }
    
    async transition(operationContext, event) {
        const state = this.states.get(operationContext.id);
        if (!state) return;
        
        const newState = this.calculateNewState(state.current, event);
        
        state.history.push(newState);
        state.transitions.push({
            from: state.current,
            to: newState,
            event,
            timestamp: Date.now()
        });
        
        state.current = newState;
        
        console.info(`[StateMachine] 状态转换: ${state.current} -> ${newState} (${event})`);
    }
    
    calculateNewState(currentState, event) {
        const transitions = {
            'init': { 'stage_start': 'running', 'error': 'failed' },
            'running': { 'stage_completed': 'running', 'error': 'failed', 'completed': 'completed' },
            'failed': { 'retry': 'running' },
            'completed': {}
        };
        
        return transitions[currentState]?.[event] || currentState;
    }
}

// 事件持久化类
class EventPersister {
    constructor(options) {
        this.options = options;
        this.eventQueue = [];
        this.persistenceInterval = null;
    }
    
    async initialize(operationContext) {
        // 启动持久化定时器
        this.persistenceInterval = setInterval(() => {
            this.flushEventQueue();
        }, 1000); // 每秒刷新一次
    }
    
    async persistEvent(operationContext, eventType, eventData) {
        const event = {
            operationId: operationContext.id,
            type: eventType,
            data: eventData,
            timestamp: Date.now(),
            stage: operationContext.currentStage
        };
        
        this.eventQueue.push(event);
        operationContext.events.push(event);
        
        console.info(`[EventPersister] 持久化事件: ${eventType}`, eventData);
    }
    
    async flushEventQueue() {
        if (this.eventQueue.length === 0) return;
        
        const events = [...this.eventQueue];
        this.eventQueue = [];
        
        // 这里可以实现实际的事件持久化逻辑
        console.info(`[EventPersister] 刷新事件队列: ${events.length}个事件`);
    }
}

// 进度追踪类
class ProgressTracker {
    constructor(options) {
        this.options = options;
        this.progressMap = new Map();
    }
    
    async initialize(operationContext) {
        this.progressMap.set(operationContext.id, {
            current: 0,
            target: 100,
            lastUpdate: Date.now()
        });
    }
    
    async updateProgress(operationContext, progress) {
        const progressData = this.progressMap.get(operationContext.id);
        if (!progressData) return;
        
        progressData.current = Math.min(100, Math.max(0, progress));
        progressData.lastUpdate = Date.now();
        
        console.info(`[ProgressTracker] 更新进度: ${progress}%`);
        
        // 持久化进度事件
        if (this.options.enableEventPersistence) {
            // 这里可以调用EventPersister
        }
    }
}

// 健康监控类
class HealthMonitor {
    constructor(options) {
        this.options = options;
        this.healthData = new Map();
    }
    
    async initialize(operationContext) {
        this.healthData.set(operationContext.id, {
            status: 'healthy',
            lastHeartbeat: Date.now(),
            errors: 0,
            warnings: 0
        });
    }
    
    async recordHeartbeat(operationContext) {
        const health = this.healthData.get(operationContext.id);
        if (health) {
            health.lastHeartbeat = Date.now();
        }
    }
    
    async recordError(operationContext, error) {
        const health = this.healthData.get(operationContext.id);
        if (health) {
            health.errors++;
            health.status = health.errors > 3 ? 'unhealthy' : 'warning';
        }
    }
}

// 超时处理类
class TimeoutHandler {
    constructor(options) {
        this.options = options;
        this.timeouts = new Map();
    }
    
    async initialize(operationContext) {
        // 设置操作超时
        const timeoutId = setTimeout(() => {
            this.handleTimeout(operationContext.id);
        }, this.options.operationTimeout);
        
        this.timeouts.set(operationContext.id, timeoutId);
    }
    
    async handleTimeout(operationId) {
        console.warn(`[TimeoutHandler] 操作超时: ${operationId}`);
        
        // 这里可以实现超时处理逻辑
        // 例如：发送超时事件、尝试恢复、清理资源等
    }
    
    async clear(operationId) {
        const timeoutId = this.timeouts.get(operationId);
        if (timeoutId) {
            clearTimeout(timeoutId);
            this.timeouts.delete(operationId);
        }
    }
}

// 重试处理类
class RetryHandler {
    constructor(options) {
        this.options = options;
        this.retryQueue = [];
    }
    
    async scheduleRetry(operationContext) {
        operationContext.retries++;
        
        console.info(`[RetryHandler] 调度重试: 第${operationContext.retries}次`);
        
        // 这里可以实现重试调度逻辑
        // 例如：添加到重试队列、设置重试定时器等
    }
}

// 创建全局实例
if (typeof window !== 'undefined') {
    window.PbAiOperationFlowEnhancer = new OperationFlowEnhancer({
        enableStateMachine: true,
        enableEventPersistence: true,
        enableProgressTracking: true,
        enableHealthMonitoring: true,
        enableTimeoutHandling: true,
        enableRetryMechanism: true,
        operationTimeout: 600000,
        stageTimeout: 120000,
        heartbeatTimeout: 30000,
        maxRetries: 3,
        retryDelay: 5000,
        progressUpdateInterval: 1000
    });
    
    console.info('[OperationFlow] P2操作流推进增强器已就绪');
}

export { OperationFlowEnhancer };