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
}
