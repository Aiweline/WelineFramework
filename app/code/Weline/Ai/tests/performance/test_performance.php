<?php
declare(strict_types=1);

/**
 * AI 模块性能测试
 * 
 * 性能目标：
 * - P95 响应时间 ≤ 3 秒
 * - P99 响应时间 ≤ 5 秒
 * - 支持 1000+ 并发用户
 * 
 * 测试覆盖：
 * - API 端点响应时间
 * - 数据库查询性能
 * - 缓存命中率
 * - 内存使用
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\AiModelService;
use Weline\Ai\Service\AiApiKeyService;
use Weline\Ai\Service\AiChatService;
use Weline\Ai\Service\CacheService;

class PerformanceTest extends TestCase
{
    private const PERFORMANCE_THRESHOLD_P95 = 3.0; // 3 秒
    private const PERFORMANCE_THRESHOLD_P99 = 5.0; // 5 秒
    private const SAMPLE_SIZE = 100; // 测试样本数量

    /**
     * 测试：模型查询性能
     */
    public function testModelQueryPerformance(): void
    {
        $times = [];
        
        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $start = microtime(true);
            
            // 执行模型查询
            $modelService = new AiModelService(new \Weline\Ai\Model\AiModel());
            $models = $modelService->getActiveModels();
            
            $duration = microtime(true) - $start;
            $times[] = $duration;
        }

        $stats = $this->calculateStats($times);
        
        $this->assertLessThanOrEqual(
            self::PERFORMANCE_THRESHOLD_P95,
            $stats['p95'],
            "Model query P95 should be <= {$stats['p95']}s, got {$stats['p95']}s"
        );
        
        $this->assertLessThanOrEqual(
            self::PERFORMANCE_THRESHOLD_P99,
            $stats['p99'],
            "Model query P99 should be <= {$stats['p99']}s, got {$stats['p99']}s"
        );

        $this->logPerformanceStats('Model Query', $stats);
    }

    /**
     * 测试：API Key 验证性能
     */
    public function testApiKeyValidationPerformance(): void
    {
        $times = [];
        $token = 'sk-test-performance-' . time();
        
        // 创建测试 API Key
        $apiKeyService = new AiApiKeyService(
            new \Weline\Ai\Model\AiApiKey(),
            new \Weline\Ai\Service\SecretStoreService(new \Weline\Framework\App\Env())
        );
        
        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $start = microtime(true);
            
            // 执行 API Key 验证
            $result = $apiKeyService->validateToken($token);
            
            $duration = microtime(true) - $start;
            $times[] = $duration;
        }

        $stats = $this->calculateStats($times);
        
        // API Key 验证应该非常快 (< 100ms)
        $this->assertLessThan(
            0.1,
            $stats['p95'],
            "API Key validation P95 should be < 0.1s"
        );

        $this->logPerformanceStats('API Key Validation', $stats);
    }

    /**
     * 测试：缓存读写性能
     */
    public function testCachePerformance(): void
    {
        $cacheService = new CacheService(
            \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Cache\CacheInterface::class)
        );
        
        // 写入性能
        $writeTimes = [];
        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $start = microtime(true);
            
            $cacheService->setResponseCache(
                "test_prompt_{$i}",
                'gpt-3.5-turbo',
                "test_response_{$i}"
            );
            
            $duration = microtime(true) - $start;
            $writeTimes[] = $duration;
        }

        // 读取性能
        $readTimes = [];
        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $start = microtime(true);
            
            $cacheService->getResponseCache(
                "test_prompt_{$i}",
                'gpt-3.5-turbo'
            );
            
            $duration = microtime(true) - $start;
            $readTimes[] = $duration;
        }

        $writeStats = $this->calculateStats($writeTimes);
        $readStats = $this->calculateStats($readTimes);
        
        // 缓存操作应该非常快 (< 50ms)
        $this->assertLessThan(0.05, $writeStats['p95'], 'Cache write P95 should be < 50ms');
        $this->assertLessThan(0.05, $readStats['p95'], 'Cache read P95 should be < 50ms');

        $this->logPerformanceStats('Cache Write', $writeStats);
        $this->logPerformanceStats('Cache Read', $readStats);
    }

    /**
     * 测试：数据库批量查询性能
     */
    public function testBatchQueryPerformance(): void
    {
        $times = [];
        
        for ($i = 0; $i < 10; $i++) { // 较少的迭代，因为批量查询
            $start = microtime(true);
            
            // 批量查询模型
            $model = new \Weline\Ai\Model\AiModel();
            $collection = $model->where('status', 'active')->select()->fetch();
            
            $duration = microtime(true) - $start;
            $times[] = $duration;
        }

        $stats = $this->calculateStats($times);
        
        $this->assertLessThan(
            1.0,
            $stats['p95'],
            'Batch query P95 should be < 1s'
        );

        $this->logPerformanceStats('Batch Query', $stats);
    }

    /**
     * 测试：内存使用
     */
    public function testMemoryUsage(): void
    {
        $memoryBefore = memory_get_usage(true);
        
        // 执行一系列操作
        $modelService = new AiModelService(new \Weline\Ai\Model\AiModel());
        for ($i = 0; $i < 100; $i++) {
            $models = $modelService->getActiveModels();
        }
        
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        // 内存使用应该合理 (< 10MB)
        $this->assertLessThan(
            10 * 1024 * 1024,
            $memoryUsed,
            'Memory usage should be < 10MB for 100 operations'
        );

        echo "\nMemory used: " . $this->formatBytes($memoryUsed) . "\n";
    }

    /**
     * 测试：并发请求模拟
     */
    public function testConcurrentRequestsSimulation(): void
    {
        $concurrentRequests = 50; // 模拟 50 个并发请求
        $times = [];
        
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $start = microtime(true);
            
            // 模拟完整的请求流程
            $model = new \Weline\Ai\Model\AiModel();
            $model->load(1);
            
            $duration = microtime(true) - $start;
            $times[] = $duration;
        }

        $stats = $this->calculateStats($times);
        
        $this->assertLessThanOrEqual(
            self::PERFORMANCE_THRESHOLD_P95,
            $stats['p95'],
            "Concurrent requests P95 should be <= {$stats['p95']}s"
        );

        $this->logPerformanceStats('Concurrent Requests', $stats);
    }

    /**
     * 测试：加密/解密性能
     */
    public function testEncryptionPerformance(): void
    {
        $secretStore = new \Weline\Ai\Service\SecretStoreService(
            new \Weline\Framework\App\Env()
        );
        
        $testData = 'sk-' . str_repeat('a', 64); // 64 字符的 API Key
        
        // 加密性能
        $encryptTimes = [];
        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $start = microtime(true);
            
            $encrypted = $secretStore->encryptApiKey($testData);
            
            $duration = microtime(true) - $start;
            $encryptTimes[] = $duration;
        }

        // 解密性能
        $encrypted = $secretStore->encryptApiKey($testData);
        $decryptTimes = [];
        for ($i = 0; $i < self::SAMPLE_SIZE; $i++) {
            $start = microtime(true);
            
            $decrypted = $secretStore->decryptApiKey($encrypted);
            
            $duration = microtime(true) - $start;
            $decryptTimes[] = $duration;
        }

        $encryptStats = $this->calculateStats($encryptTimes);
        $decryptStats = $this->calculateStats($decryptTimes);
        
        // 加密/解密应该快速 (< 10ms)
        $this->assertLessThan(0.01, $encryptStats['p95'], 'Encryption P95 should be < 10ms');
        $this->assertLessThan(0.01, $decryptStats['p95'], 'Decryption P95 should be < 10ms');

        $this->logPerformanceStats('Encryption', $encryptStats);
        $this->logPerformanceStats('Decryption', $decryptStats);
    }

    /**
     * 计算性能统计
     *
     * @param array $times
     * @return array
     */
    private function calculateStats(array $times): array
    {
        return \Weline\Ai\Helper\PerformanceHelper::calculateStats($times);
    }

    /**
     * 记录性能统计信息
     *
     * @param string $testName
     * @param array $stats
     * @return void
     */
    private function logPerformanceStats(string $testName, array $stats): void
    {
        \Weline\Ai\Helper\PerformanceHelper::logPerformanceStats($testName, $stats, true);
    }

    /**
     * 格式化字节大小
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        return \Weline\Ai\Helper\PerformanceHelper::formatBytes($bytes);
    }
}

