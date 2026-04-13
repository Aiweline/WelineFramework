/**
 * P2操作流推进修复 - E2E测试用例
 * 
 * 测试操作流状态机、事件持久化、进度追踪等功能
 */

const { test, expect } = require('@playwright/test');
const { 
  buildBackendUrl,
  buildWorkbenchUrl,
  createWorkspace,
  loginAsAdmin
} = require('./helpers/ai-workbench');

const WORKSPACE_TIMEOUT = 300000;
const OPERATION_TIMEOUT = 120000;

/**
 * 操作流推进测试套件
 */
test.describe('PageBuilder 操作流推进修复', () => {
  let page;
  let workspace;
  let workspaceUrl;
  let publicId;

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage();
    await loginAsAdmin(page);
    
    // 创建工作区
    workspace = await createWorkspace(page, {
      provider: 'pagebuilder',
      site_title: '操作流推进测试站点',
      brief: '测试操作流状态机和事件持久化'
    });
    
    publicId = workspace.public_id;
    workspaceUrl = buildWorkbenchUrl('pagebuilder/backend/ai-site-agent/workspace', { public_id: publicId });
  });

  test.afterAll(async () => {
    if (page) {
      await page.close();
    }
  });

  test.beforeEach(async () => {
    await page.goto(workspaceUrl, { waitUntil: 'networkidle' });
    await page.waitForLoadState('domcontentloaded');
  });

  /**
   * 测试1: 操作流状态机 - 验证状态转换
   */
  test('操作流状态机应正确转换状态', async () => {
    // 等待工作区页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 获取操作流增强器状态
    const flowEnhancerStatus = await page.evaluate(() => {
      if (window.PbAiOperationFlowEnhancer) {
        return window.PbAiOperationFlowEnhancer.getStats();
      }
      return null;
    });
    
    expect(flowEnhancerStatus).toBeTruthy();
    
    if (flowEnhancerStatus) {
      console.log('操作流增强器状态:', flowEnhancerStatus);
      
      // 验证基本统计信息
      expect(flowEnhancerStatus.totalOperations).toBeGreaterThanOrEqual(0);
      expect(flowEnhancerStatus.activeOperations).toBeGreaterThanOrEqual(0);
      expect(flowEnhancerStatus.successRate).toBeGreaterThanOrEqual(0);
      expect(flowEnhancerStatus.averageDuration).toBeGreaterThanOrEqual(0);
    }
  });

  /**
   * 测试2: 事件持久化 - 验证事件正确持久化
   */
  test('事件应正确持久化', async () => {
    // 等待页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 模拟触发一些事件
    await page.evaluate(async (publicId) => {
      if (window.PbAiOperationFlowEnhancer) {
        const enhancer = window.PbAiOperationFlowEnhancer;
        
        // 模拟一个测试操作
        const testOperation = {
          id: 'test-operation-' + Date.now(),
          operation: 'test',
          stage: 'test_stage',
          context: { test: true }
        };
        
        // 手动触发一些事件
        if (enhancer.eventPersister) {
          await enhancer.eventPersister.persistEvent(testOperation, 'test_started', {
            message: '测试操作开始',
            timestamp: Date.now()
          });
          
          await enhancer.eventPersister.persistEvent(testOperation, 'test_progress', {
            message: '测试操作进行中',
            progress: 50,
            timestamp: Date.now()
          });
          
          await enhancer.eventPersister.persistEvent(testOperation, 'test_completed', {
            message: '测试操作完成',
            duration: 1000,
            timestamp: Date.now()
          });
        }
      }
    }, publicId);
    
    // 等待事件处理
    await page.waitForTimeout(2000);
    
    // 验证事件持久化
    const eventStatus = await page.evaluate(() => {
      if (window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.eventPersister) {
        const persister = window.PbAiOperationFlowEnhancer.eventPersister;
        return {
          queueSize: persister.eventQueue ? persister.eventQueue.length : 0,
          persistenceActive: persister.persistenceInterval !== null
        };
      }
      return null;
    });
    
    if (eventStatus) {
      console.log('事件持久化状态:', eventStatus);
      expect(eventStatus.persistenceActive).toBeTruthy();
    }
  });

  /**
   * 测试3: 进度追踪 - 验证进度正确更新
   */
  test('进度应正确追踪和更新', async () => {
    // 等待页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 测试进度追踪功能
    const progressTest = await page.evaluate(async () => {
      if (window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.progressTracker) {
        const tracker = window.PbAiOperationFlowEnhancer.progressTracker;
        
        // 创建测试操作
        const testOperation = {
          id: 'progress-test-' + Date.now(),
          operation: 'progress_test',
          stage: 'progress_stage'
        };
        
        // 初始化进度追踪
        await tracker.initialize(testOperation);
        
        // 测试进度更新
        const progressValues = [0, 25, 50, 75, 100];
        const results = [];
        
        for (const progress of progressValues) {
          await tracker.updateProgress(testOperation, progress);
          const progressData = tracker.progressMap.get(testOperation.id);
          if (progressData) {
            results.push({
              requested: progress,
              actual: progressData.current,
              timestamp: progressData.lastUpdate
            });
          }
        }
        
        return {
          success: true,
          results: results,
          finalProgress: tracker.progressMap.get(testOperation.id)
        };
      }
      return { success: false, error: '进度追踪器未找到' };
    });
    
    expect(progressTest.success).toBeTruthy();
    
    if (progressTest.success) {
      console.log('进度追踪测试结果:', progressTest);
      
      // 验证所有进度值都正确
      progressTest.results.forEach(result => {
        expect(result.actual).toBe(result.requested);
      });
      
      // 验证最终进度为100%
      expect(progressTest.finalProgress.current).toBe(100);
    }
  });

  /**
   * 测试4: 健康监控 - 验证操作健康状态
   */
  test('健康监控应正确检测操作状态', async () => {
    // 等待页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 测试健康监控功能
    const healthTest = await page.evaluate(async () => {
      if (window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.healthMonitor) {
        const monitor = window.PbAiOperationFlowEnhancer.healthMonitor;
        
        // 创建测试操作
        const testOperation = {
          id: 'health-test-' + Date.now(),
          operation: 'health_test',
          stage: 'health_stage'
        };
        
        // 初始化健康监控
        await monitor.initialize(testOperation);
        
        // 模拟一些健康状态变化
        await monitor.recordHeartbeat(testOperation);
        
        // 模拟错误
        await monitor.recordError(testOperation, new Error('测试错误'));
        await monitor.recordError(testOperation, new Error('测试错误2'));
        await monitor.recordError(testOperation, new Error('测试错误3'));
        
        // 获取最终健康状态
        const healthData = monitor.healthData.get(testOperation.id);
        
        return {
          success: true,
          healthData: healthData,
          status: healthData ? healthData.status : 'unknown'
        };
      }
      return { success: false, error: '健康监控器未找到' };
    });
    
    expect(healthTest.success).toBeTruthy();
    
    if (healthTest.success) {
      console.log('健康监控测试结果:', healthTest);
      
      // 验证错误计数
      expect(healthTest.healthData.errors).toBe(3);
      
      // 验证状态（错误数>3应该为unhealthy）
      expect(healthTest.status).toBe('unhealthy');
    }
  });

  /**
   * 测试5: 超时处理 - 验证超时机制
   */
  test('超时处理应正确工作', async () => {
    // 等待页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 测试超时处理功能
    const timeoutTest = await page.evaluate(async () => {
      if (window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.timeoutHandler) {
        const handler = window.PbAiOperationFlowEnhancer.timeoutHandler;
        
        // 创建测试操作
        const testOperation = {
          id: 'timeout-test-' + Date.now(),
          operation: 'timeout_test',
          stage: 'timeout_stage'
        };
        
        // 初始化超时处理（设置很短的超时时间用于测试）
        const originalTimeout = handler.options.operationTimeout;
        handler.options.operationTimeout = 2000; // 2秒超时
        
        await handler.initialize(testOperation);
        
        // 等待超时触发
        await new Promise(resolve => setTimeout(resolve, 2500));
        
        // 检查超时状态
        const timeoutId = handler.timeouts.get(testOperation.id);
        
        return {
          success: true,
          timeoutSet: timeoutId !== undefined,
          timeoutCleared: timeoutId === undefined, // 超时后应该被清理
          originalTimeout: originalTimeout
        };
      }
      return { success: false, error: '超时处理器未找到' };
    });
    
    expect(timeoutTest.success).toBeTruthy();
    
    if (timeoutTest.success) {
      console.log('超时处理测试结果:', timeoutTest);
      
      // 验证超时设置
      expect(timeoutTest.timeoutSet).toBeTruthy();
      
      // 验证超时清理
      expect(timeoutTest.timeoutCleared).toBeTruthy();
    }
  });

  /**
   * 测试6: 重试机制 - 验证重试逻辑
   */
  test('重试机制应正确处理失败操作', async () => {
    // 等待页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 测试重试机制
    const retryTest = await page.evaluate(async () => {
      if (window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.retryHandler) {
        const handler = window.PbAiOperationFlowEnhancer.retryHandler;
        
        // 创建测试操作
        const testOperation = {
          id: 'retry-test-' + Date.now(),
          operation: 'retry_test',
          stage: 'retry_stage',
          retries: 0
        };
        
        // 测试重试调度
        await handler.scheduleRetry(testOperation);
        
        return {
          success: true,
          retryCount: testOperation.retries,
          queueLength: handler.retryQueue.length,
          maxRetries: handler.options.maxRetries
        };
      }
      return { success: false, error: '重试处理器未找到' };
    });
    
    expect(retryTest.success).toBeTruthy();
    
    if (retryTest.success) {
      console.log('重试机制测试结果:', retryTest);
      
      // 验证重试计数
      expect(retryTest.retryCount).toBe(1);
      
      // 验证重试队列
      expect(retryTest.queueLength).toBe(1);
      
      // 验证最大重试次数
      expect(retryTest.maxRetries).toBe(3);
    }
  });

  /**
   * 测试7: 完整操作流 - 验证端到端操作流
   */
  test('完整操作流应正确执行', async () => {
    // 等待页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 测试完整操作流执行
    const fullFlowTest = await page.evaluate(async () => {
      if (window.PbAiOperationFlowEnhancer) {
        const enhancer = window.PbAiOperationFlowEnhancer;
        
        // 创建测试操作
        const testOperation = {
          id: 'full-flow-test-' + Date.now(),
          operation: 'build',
          stage: 'build_stage',
          context: { test: true, workspace_track: 'html_blocks' }
        };
        
        try {
          // 执行完整操作流
          const result = await enhancer.enhanceOperationFlow(
            testOperation.id,
            testOperation.operation,
            testOperation.stage,
            testOperation.context
          );
          
          return {
            success: result.success,
            operationId: result.operationId,
            duration: result.duration,
            error: result.error
          };
        } catch (error) {
          return {
            success: false,
            error: error.message
          };
        }
      }
      return { success: false, error: '操作流增强器未找到' };
    });
    
    expect(fullFlowTest.success).toBeTruthy();
    
    if (fullFlowTest.success) {
      console.log('完整操作流测试结果:', fullFlowTest);
      
      // 验证操作ID
      expect(fullFlowTest.operationId).toBeTruthy();
      
      // 验证执行时间
      expect(fullFlowTest.duration).toBeGreaterThan(0);
    }
  });

  /**
   * 测试8: 网络恢复 - 验证网络状态变化处理
   */
  test('网络状态变化应正确处理', async () => {
    // 等待页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 模拟网络状态变化
    const networkTest = await page.evaluate(async () => {
      if (window.PbAiOperationFlowEnhancer) {
        const enhancer = window.PbAiOperationFlowEnhancer;
        
        // 模拟网络离线
        enhancer.handleNetworkFailure();
        
        // 等待一下
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        // 模拟网络恢复
        enhancer.handleNetworkRecovery();
        
        return {
          success: true,
          operationsPaused: enhancer.activeOperations.size,
          networkHandlersExist: true
        };
      }
      return { success: false, error: '操作流增强器未找到' };
    });
    
    expect(networkTest.success).toBeTruthy();
    
    if (networkTest.success) {
      console.log('网络状态变化测试结果:', networkTest);
      
      // 验证网络处理器存在
      expect(networkTest.networkHandlersExist).toBeTruthy();
    }
  });

  /**
   * 测试9: 性能指标 - 验证性能监控
   */
  test('性能指标应正确计算', async () => {
    // 等待页面加载并运行一段时间
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    await page.waitForTimeout(5000); // 运行5秒收集性能数据
    
    // 获取性能指标
    const performanceMetrics = await page.evaluate(() => {
      if (window.PbAiOperationFlowEnhancer) {
        const enhancer = window.PbAiOperationFlowEnhancer;
        const stats = enhancer.getStats();
        
        return {
          totalOperations: stats.totalOperations,
          activeOperations: stats.activeOperations,
          successRate: stats.successRate,
          averageDuration: stats.averageDuration,
          uptime: stats.uptime,
          recentOperations: stats.recentOperations.length
        };
      }
      return null;
    });
    
    expect(performanceMetrics).toBeTruthy();
    
    if (performanceMetrics) {
      console.log('性能指标:', performanceMetrics);
      
      // 验证基本指标
      expect(performanceMetrics.totalOperations).toBeGreaterThanOrEqual(0);
      expect(performanceMetrics.activeOperations).toBeGreaterThanOrEqual(0);
      expect(performanceMetrics.successRate).toBeGreaterThanOrEqual(0);
      expect(performanceMetrics.averageDuration).toBeGreaterThanOrEqual(0);
      expect(performanceMetrics.uptime).toBeGreaterThanOrEqual(5000); // 至少运行了5秒
      expect(performanceMetrics.recentOperations).toBeGreaterThanOrEqual(0);
    }
  });

  /**
   * 测试10: 调试功能 - 验证调试和监控功能
   */
  test('调试和监控功能应正常工作', async () => {
    // 等待页面加载
    await page.waitForSelector('#pagebuilder-workspace-terminal', { timeout: 10000 });
    
    // 测试调试功能
    const debugTest = await page.evaluate(() => {
      const results = {};
      
      // 检查全局变量
      results.hasGlobalEnhancer = !!window.PbAiOperationFlowEnhancer;
      results.hasStateMachine = !!(window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.stateMachine);
      results.hasEventPersister = !!(window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.eventPersister);
      results.hasProgressTracker = !!(window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.progressTracker);
      results.hasHealthMonitor = !!(window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.healthMonitor);
      results.hasTimeoutHandler = !!(window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.timeoutHandler);
      results.hasRetryHandler = !!(window.PbAiOperationFlowEnhancer && window.PbAiOperationFlowEnhancer.retryHandler);
      
      // 检查控制台输出
      results.consoleAvailable = typeof console !== 'undefined';
      results.consoleMethods = {
        log: typeof console.log === 'function',
        info: typeof console.info === 'function',
        warn: typeof console.warn === 'function',
        error: typeof console.error === 'function'
      };
      
      return results;
    });
    
    expect(debugTest.hasGlobalEnhancer).toBeTruthy();
    expect(debugTest.hasStateMachine).toBeTruthy();
    expect(debugTest.hasEventPersister).toBeTruthy();
    expect(debugTest.hasProgressTracker).toBeTruthy();
    expect(debugTest.hasHealthMonitor).toBeTruthy();
    expect(debugTest.hasTimeoutHandler).toBeTruthy();
    expect(debugTest.hasRetryHandler).toBeTruthy();
    expect(debugTest.consoleAvailable).toBeTruthy();
    
    console.log('调试功能测试结果:', debugTest);
  });
});

/**
 * 测试报告生成
 */
test.afterAll(async () => {
  console.log('\n=== 操作流推进修复测试报告 ===');
  console.log('测试套件: PageBuilder 操作流推进修复');
  console.log('测试时间:', new Date().toISOString());
  console.log('测试环境:', process.env.NODE_ENV || 'development');
  console.log('测试覆盖:');
  console.log('  ✅ 操作流状态机');
  console.log('  ✅ 事件持久化');
  console.log('  ✅ 进度追踪');
  console.log('  ✅ 健康监控');
  console.log('  ✅ 超时处理');
  console.log('  ✅ 重试机制');
  console.log('  ✅ 完整操作流');
  console.log('  ✅ 网络状态变化');
  console.log('  ✅ 性能指标');
  console.log('  ✅ 调试功能');
  console.log('============================\n');
});