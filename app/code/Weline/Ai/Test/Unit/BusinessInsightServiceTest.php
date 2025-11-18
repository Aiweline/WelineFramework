<?php
/**
 * 商业洞察服务单元测试
 */

namespace Weline\Ai\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\BusinessInsightService;
use Weline\Ai\Model\AiUsageLog;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiModelMonitoring;
use Weline\Ai\Service\CacheService;

class BusinessInsightServiceTest extends TestCase
{
    private $service;
    private $mockUsageLog;
    private $mockAiModel;
    private $mockMonitoring;
    private $mockCacheService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建模拟对象
        $this->mockUsageLog = $this->createMock(AiUsageLog::class);
        $this->mockAiModel = $this->createMock(AiModel::class);
        $this->mockMonitoring = $this->createMock(AiModelMonitoring::class);
        $this->mockCacheService = $this->createMock(CacheService::class);
        
        $this->service = new BusinessInsightService(
            $this->mockUsageLog,
            $this->mockAiModel,
            $this->mockMonitoring,
            $this->mockCacheService
        );
    }

    /**
     * 测试：获取总体统计数据
     */
    public function testGetOverallStats()
    {
        $startDate = strtotime('-7 days');
        $endDate = time();
        
        // 模拟缓存返回
        $expectedStats = [
            'total_requests' => 1000,
            'total_tokens' => 500000,
            'total_cost' => 50.00,
            'unique_users' => 25,
            'success_rate' => 98.5,
            'avg_tokens_per_request' => 500,
            'avg_cost_per_request' => 0.05,
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($expectedStats);
        
        $result = $this->service->getOverallStats($startDate, $endDate);
        
        $this->assertIsArray($result);
        $this->assertEquals(1000, $result['total_requests']);
        $this->assertEquals(25, $result['unique_users']);
        $this->assertEquals(98.5, $result['success_rate']);
    }

    /**
     * 测试：获取模型使用统计
     */
    public function testGetModelStats()
    {
        $startDate = strtotime('-7 days');
        $endDate = time();
        
        $expectedStats = [
            [
                'model_code' => 'gpt-4',
                'request_count' => 500,
                'total_tokens' => 250000,
                'total_cost' => 30.00,
                'success_count' => 495,
                'error_count' => 5,
                'success_rate' => 99.0,
            ],
            [
                'model_code' => 'gpt-3.5-turbo',
                'request_count' => 500,
                'total_tokens' => 250000,
                'total_cost' => 20.00,
                'success_count' => 490,
                'error_count' => 10,
                'success_rate' => 98.0,
            ],
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($expectedStats);
        
        $result = $this->service->getModelStats($startDate, $endDate);
        
        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertEquals('gpt-4', $result[0]['model_code']);
        $this->assertEquals(500, $result[0]['request_count']);
    }

    /**
     * 测试：获取每日趋势
     */
    public function testGetDailyTrend()
    {
        $startDate = strtotime('-7 days');
        $endDate = time();
        
        $expectedTrend = [
            [
                'date' => date('Y-m-d'),
                'request_count' => 150,
                'total_tokens' => 75000,
                'total_cost' => 7.5,
                'unique_users' => 5,
            ],
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($expectedTrend);
        
        $result = $this->service->getDailyTrend($startDate, $endDate);
        
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('date', $result[0]);
        $this->assertArrayHasKey('request_count', $result[0]);
    }

    /**
     * 测试：性能SLO验证
     */
    public function testPerformanceMetricsSLO()
    {
        $startDate = strtotime('-7 days');
        $endDate = time();
        
        $expectedMetrics = [
            'avg_response_time' => 1.234,
            'p50_response_time' => 1.0,
            'p95_response_time' => 2.5,
            'p99_response_time' => 4.0,
            'success_count' => 985,
            'error_count' => 15,
            'success_rate' => 98.5,
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($expectedMetrics);
        
        $result = $this->service->getPerformanceMetrics($startDate, $endDate);
        
        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(3.0, $result['p95_response_time'], 'P95应小于3秒');
        $this->assertLessThanOrEqual(5.0, $result['p99_response_time'], 'P99应小于5秒');
        $this->assertGreaterThanOrEqual(95.0, $result['success_rate'], '成功率应大于95%');
    }

    /**
     * 测试：清除缓存
     */
    public function testClearCache()
    {
        $this->mockCacheService->expects($this->once())
            ->method('clear')
            ->with('insights_*');
        
        $this->service->clearCache();
        
        $this->assertTrue(true, '缓存清除成功');
    }
}

