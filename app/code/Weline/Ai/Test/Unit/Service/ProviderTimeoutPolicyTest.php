<?php
declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\ProviderTimeoutPolicy;

class ProviderTimeoutPolicyTest extends TestCase
{
    public function testImageGenerationTimeoutFloorsConfiguredGenericTimeout(): void
    {
        $this->assertSame(
            ProviderTimeoutPolicy::MIN_CONFIGURED_IMAGE_GENERATION_TIMEOUT,
            ProviderTimeoutPolicy::resolveImageGenerationTimeout([], ['timeout' => 1])
        );
    }

    public function testImageGenerationTimeoutHonorsExplicitRequestTimeout(): void
    {
        $this->assertSame(1, ProviderTimeoutPolicy::resolveImageGenerationTimeout(['timeout' => 1], ['timeout' => 900]));
    }

    public function testImageGenerationTimeoutCanBeDisabled(): void
    {
        $this->assertSame(0, ProviderTimeoutPolicy::resolveImageGenerationTimeout(['disable_ai_timeout' => true], []));
    }

    public function testExecutionLimitKeepsExistingUnlimitedRuntime(): void
    {
        $this->assertNull(ProviderTimeoutPolicy::resolveExecutionTimeLimit(60, '0'));
    }

    public function testExecutionLimitAddsBufferWhenRuntimeIsLimited(): void
    {
        $this->assertSame(70, ProviderTimeoutPolicy::resolveExecutionTimeLimit(60, '30'));
    }

    public function testLowSpeedTimeScalesWithLongRequestTimeout(): void
    {
        $this->assertSame(30, ProviderTimeoutPolicy::resolveLowSpeedTime(60));
        $this->assertSame(45, ProviderTimeoutPolicy::resolveLowSpeedTime(180));
        $this->assertSame(120, ProviderTimeoutPolicy::resolveLowSpeedTime(900));
        $this->assertSame(ProviderTimeoutPolicy::DEFAULT_LOW_SPEED_TIME, ProviderTimeoutPolicy::resolveLowSpeedTime(0));
    }
}
