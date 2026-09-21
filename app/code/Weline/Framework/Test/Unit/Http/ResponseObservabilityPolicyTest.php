<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\ResponseObservabilityPolicy;

final class ResponseObservabilityPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        ResponseObservabilityPolicy::clearCache();
        Env::getInstance()->reload();
    }

    public function testIdentityDefaultsOnInDevDeploy(): void
    {
        ResponseObservabilityPolicy::clearCache();
        Env::getInstance()->applyRuntimeConfig([
            'deploy' => 'dev',
            'wls' => [
                'debug' => [
                    // 空串=未配置，覆盖本机 env 显式 false，验证 deploy=dev 缺省开
                    'identity_headers' => '',
                ],
            ],
        ]);
        self::assertTrue(ResponseObservabilityPolicy::isDeployDev());
        self::assertTrue(ResponseObservabilityPolicy::identityHeadersEnabled());
    }

    public function testIdentityOffWhenExplicitFalseEvenInDev(): void
    {
        ResponseObservabilityPolicy::clearCache();
        Env::getInstance()->applyRuntimeConfig([
            'deploy' => 'dev',
            'wls' => [
                'debug' => [
                    'identity_headers' => false,
                ],
            ],
        ]);
        self::assertFalse(ResponseObservabilityPolicy::identityHeadersEnabled());
    }

    public function testDynamicObservabilityDefaultsOffOutsideDev(): void
    {
        ResponseObservabilityPolicy::clearCache();
        Env::getInstance()->applyRuntimeConfig([
            'deploy' => 'prod',
            'wls' => [
                'worker' => [],
            ],
        ]);
        // DEV constant may still be true in unit process; explicit false wins.
        Env::getInstance()->applyRuntimeConfig([
            'wls' => [
                'worker' => [
                    'dynamic_observability_headers_enabled' => false,
                ],
            ],
        ]);
        ResponseObservabilityPolicy::clearCache();
        self::assertFalse(ResponseObservabilityPolicy::dynamicObservabilityEnabled());
    }

    public function testPerformanceBreakdownRespectsEnvFlag(): void
    {
        ResponseObservabilityPolicy::clearCache();
        Env::getInstance()->applyRuntimeConfig([
            'wls' => [
                'performance' => [
                    'response_headers_enabled' => false,
                ],
            ],
        ]);
        self::assertFalse(ResponseObservabilityPolicy::performanceBreakdownEnabled());
        self::assertFalse(ResponseObservabilityPolicy::processTimingHeadersEnabled());

        ResponseObservabilityPolicy::clearCache();
        Env::getInstance()->applyRuntimeConfig([
            'wls' => [
                'performance' => [
                    'response_headers_enabled' => true,
                ],
            ],
        ]);
        self::assertTrue(ResponseObservabilityPolicy::performanceBreakdownEnabled());
        self::assertTrue(ResponseObservabilityPolicy::processTimingHeadersEnabled());
    }

    public function testPoweredByFollowsExplicitFlag(): void
    {
        ResponseObservabilityPolicy::clearCache();
        Env::getInstance()->applyRuntimeConfig([
            'deploy' => 'prod',
            'wls' => [
                'debug' => [
                    'powered_by_header' => false,
                ],
            ],
        ]);
        self::assertFalse(ResponseObservabilityPolicy::poweredByHeaderEnabled());
    }
}
