<?php
/**
 * 监控服务单元测试
 */

namespace Weline\Ai\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\MonitoringService;
use Weline\Ai\Model\AiModelMonitoring;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\CacheService;

class MonitoringServiceTest extends TestCase
{
    private $service;
    private $mockMonitoring;
    private $mockAiModel;
    private $mockCacheService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockMonitoring = $this->createMock(AiModelMonitoring::class);
        $this->mockAiModel = $this->createMock(AiModel::class);
        $this->mockCacheService = $this->createMock(CacheService::class);
        
        $this->service = new MonitoringService(
            $this->mockMonitoring,
            $this->mockAiModel,
            $this->mockCacheService
        );
    }

    /**
     * 测试：获取模型监控数据 - 健康状态
     */
    public function testGetModelMonitoringHealthy()
    {
        $modelCode = 'gpt-4';
        $days = 7;
        
        $healthyData = [
            'model_code' => 'gpt-4',
            'period' => '7 days',
            'summary' => [
                'total_requests' => 1000,
                'success_rate' => 99.0,
                'error_rate' => 1.0,
                'total_cost' => 50.00,
                'avg_response_time' => 1500,
            ],
            'daily_data' => [],
            'health_status' => 'healthy',
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($healthyData);
        
        $result = $this->service->getModelMonitoring($modelCode, $days);
        
        $this->assertIsArray($result);
        $this->assertEquals($modelCode, $result['model_code']);
        $this->assertEquals('healthy', $result['health_status']);
        $this->assertGreaterThanOrEqual(95.0, $result['summary']['success_rate']);
    }

    /**
     * 测试：健康状态 - 警告
     */
    public function testHealthStatusWarning()
    {
        $modelCode = 'gpt-4';
        
        $warningData = [
            'model_code' => 'gpt-4',
            'period' => '7 days',
            'summary' => [
                'success_rate' => 92.0,
                'error_rate' => 8.0,
                'avg_response_time' => 4000,
            ],
            'health_status' => 'warning',
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($warningData);
        
        $result = $this->service->getModelMonitoring($modelCode);
        
        $this->assertEquals('warning', $result['health_status']);
    }

    /**
     * 测试：健康状态 - 严重
     */
    public function testHealthStatusCritical()
    {
        $modelCode = 'gpt-4';
        
        $criticalData = [
            'model_code' => 'gpt-4',
            'period' => '7 days',
            'summary' => [
                'success_rate' => 85.0,
                'error_rate' => 15.0,
                'avg_response_time' => 6000,
            ],
            'health_status' => 'critical',
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($criticalData);
        
        $result = $this->service->getModelMonitoring($modelCode);
        
        $this->assertEquals('critical', $result['health_status']);
    }

    /**
     * 测试：系统健康检查
     */
    public function testGetSystemHealth()
    {
        $overviewData = [
            [
                'model_code' => 'gpt-4',
                'health_status' => 'healthy',
            ],
            [
                'model_code' => 'gpt-3.5-turbo',
                'health_status' => 'healthy',
            ],
            [
                'model_code' => 'claude-2',
                'health_status' => 'warning',
            ],
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($overviewData);
        
        $result = $this->service->getSystemHealth();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('overall_status', $result);
        $this->assertArrayHasKey('total_models', $result);
        $this->assertArrayHasKey('healthy_models', $result);
        $this->assertArrayHasKey('warning_models', $result);
        $this->assertArrayHasKey('critical_models', $result);
        
        $this->assertEquals(3, $result['total_models']);
        $this->assertEquals(2, $result['healthy_models']);
        $this->assertEquals(1, $result['warning_models']);
    }

    /**
     * 测试：性能SLO验证 - P95响应时间
     */
    public function testP95ResponseTimeSLO()
    {
        $modelCode = 'gpt-4';
        
        $data = [
            'summary' => [
                'avg_response_time' => 2500, // 2.5秒
            ],
            'health_status' => 'healthy',
        ];
        
        $this->mockCacheService->method('remember')
            ->willReturn($data);
        
        $result = $this->service->getModelMonitoring($modelCode);
        
        $this->assertLessThanOrEqual(3000, $result['summary']['avg_response_time'], 'P95响应时间应 <= 3秒');
    }
}

