<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Model\WarmupUrl;
use Weline\Cdn\Service\WarmupRunner;
use Weline\Framework\Manager\ObjectManager;

/**
 * WarmupRunner服务单元测试
 */
class WarmupRunnerTest extends TestCase
{
    private WarmupRunner $warmupRunner;
    private ObjectManager $objectManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objectManager = ObjectManager::getInstance();
        $this->warmupRunner = new WarmupRunner($this->objectManager);
    }

    /**
     * 测试：服务实例化
     */
    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(WarmupRunner::class, $this->warmupRunner);
    }

    /**
     * 测试：执行预热任务（无URL）
     */
    public function testRunWithNoUrls(): void
    {
        // 注意：实际测试需要数据库支持，这里主要验证方法存在
        $result = $this->warmupRunner->run(10);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('processed', $result);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('fail', $result);
        $this->assertIsInt($result['processed']);
        $this->assertIsInt($result['success']);
        $this->assertIsInt($result['fail']);
    }

    /**
     * 测试：执行预热任务（限制数量）
     */
    public function testRunWithLimit(): void
    {
        // 测试limit参数
        $result = $this->warmupRunner->run(5);
        
        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(5, $result['processed']);
    }

    /**
     * 测试：执行预热任务（默认限制）
     */
    public function testRunWithDefaultLimit(): void
    {
        $result = $this->warmupRunner->run();
        
        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(50, $result['processed']); // 默认限制50
    }

    /**
     * 测试：预热URL处理逻辑（需要mock）
     */
    public function testWarmupUrlProcessing(): void
    {
        // 这个方法需要mock HTTP请求，在实际测试中可能需要使用集成测试
        $this->markTestSkipped('需要mock cURL或使用集成测试');
    }

    /**
     * 测试：间隔时间检查（需要mock）
     */
    public function testIntervalCheck(): void
    {
        // 验证间隔时间检查逻辑
        // 实际测试需要mock Domain和WarmupUrl模型
        $this->markTestSkipped('需要mock Domain和WarmupUrl模型');
    }

    /**
     * 测试：统计信息更新（需要mock）
     */
    public function testStatisticsUpdate(): void
    {
        // 验证成功/失败统计更新
        // 实际测试需要mock数据库操作
        $this->markTestSkipped('需要mock数据库操作');
    }
}

