<?php
declare(strict_types=1);

namespace Weline\Server\Test\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Contract\HealthCheckResult;

class HealthCheckResultTest extends TestCase
{
    public function testHealthy(): void
    {
        $result = HealthCheckResult::healthy('All good');

        $this->assertEquals(HealthCheckResult::STATUS_HEALTHY, $result->status);
        $this->assertEquals('All good', $result->message);
        $this->assertTrue($result->isHealthy());
    }

    public function testHealthyDefault(): void
    {
        $result = HealthCheckResult::healthy();

        $this->assertEquals(HealthCheckResult::STATUS_HEALTHY, $result->status);
        $this->assertEquals('OK', $result->message);
        $this->assertTrue($result->isHealthy());
    }

    public function testUnhealthy(): void
    {
        $result = HealthCheckResult::unhealthy('Connection refused');

        $this->assertEquals(HealthCheckResult::STATUS_UNHEALTHY, $result->status);
        $this->assertEquals('Connection refused', $result->message);
        $this->assertFalse($result->isHealthy());
    }

    public function testDegraded(): void
    {
        $result = HealthCheckResult::degraded('High latency');

        $this->assertEquals(HealthCheckResult::STATUS_DEGRADED, $result->status);
        $this->assertEquals('High latency', $result->message);
        $this->assertFalse($result->isHealthy());
    }

    public function testUnknown(): void
    {
        $result = HealthCheckResult::unknown('Unable to check');

        $this->assertEquals(HealthCheckResult::STATUS_UNKNOWN, $result->status);
        $this->assertEquals('Unable to check', $result->message);
        $this->assertFalse($result->isHealthy());
    }

    public function testWithDetails(): void
    {
        $details = ['cpu' => 0.5, 'memory' => 1024];
        $result = new HealthCheckResult(
            status: HealthCheckResult::STATUS_HEALTHY,
            message: 'OK',
            details: $details,
        );

        $this->assertEquals($details, $result->details);
    }
}
