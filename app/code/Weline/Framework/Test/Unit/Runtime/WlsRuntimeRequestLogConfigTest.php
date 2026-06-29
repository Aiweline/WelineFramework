<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Runtime\WlsRuntime;
use Weline\Server\Log\LogConfig;

final class WlsRuntimeRequestLogConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::getInstance()->reload();
        LogConfig::bootstrapVerbose(false);
        LogConfig::clearCache();
    }

    public function testRequestLogDefaultsToDisabledWhenVerboseLoggingIsOff(): void
    {
        LogConfig::bootstrapVerbose(false);

        self::assertFalse($this->getPerformanceConfig()['request_log_enabled']);
    }

    public function testRequestLogDefaultsToEnabledWhenVerboseLoggingIsOn(): void
    {
        LogConfig::bootstrapVerbose(true);

        self::assertTrue($this->getPerformanceConfig()['request_log_enabled']);
    }

    public function testResponseHeadersDefaultToDisabledWhenVerboseLoggingIsOff(): void
    {
        LogConfig::bootstrapVerbose(false);

        self::assertFalse($this->getPerformanceConfig()['response_headers_enabled']);
    }

    public function testResponseHeadersDefaultToEnabledWhenVerboseLoggingIsOn(): void
    {
        LogConfig::bootstrapVerbose(true);

        self::assertTrue($this->getPerformanceConfig()['response_headers_enabled']);
    }

    public function testResponseHeadersCanBeExplicitlyEnabled(): void
    {
        LogConfig::bootstrapVerbose(false);
        Env::getInstance()->applyRuntimeConfig([
            'wls' => [
                'performance' => [
                    'response_headers_enabled' => true,
                ],
            ],
        ]);

        self::assertTrue($this->getPerformanceConfig()['response_headers_enabled']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPerformanceConfig(): array
    {
        $runtime = new WlsRuntime();
        $method = new \ReflectionMethod(WlsRuntime::class, 'getPerformanceConfig');
        $method->setAccessible(true);

        /** @var array<string, mixed> $config */
        $config = $method->invoke($runtime);

        return $config;
    }
}
