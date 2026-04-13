<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\Env;
use Weline\Framework\Http\WlsRequest;
use Weline\Server\Log\LogConfig;

final class WlsRequestDevLoggingTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::getInstance()->reload();
        LogConfig::bootstrapVerbose(false);
        LogConfig::clearCache();
    }

    public function testDevServerDumpIsDisabledByDefault(): void
    {
        LogConfig::bootstrapVerbose(false);

        self::assertFalse($this->shouldWriteDevServerDump(true));
    }

    public function testDevServerDumpCanBeEnabledByVerboseLogMode(): void
    {
        LogConfig::bootstrapVerbose(true);

        self::assertTrue($this->shouldWriteDevServerDump(true));
    }

    public function testDevServerDumpCanBeEnabledByExplicitDebugFlag(): void
    {
        Env::getInstance()->applyRuntimeConfig([
            'wls' => [
                'debug' => [
                    'request_server_dump' => true,
                ],
            ],
        ]);
        LogConfig::bootstrapVerbose(false);

        self::assertTrue($this->shouldWriteDevServerDump(true));
    }

    private function shouldWriteDevServerDump(bool $devMode): bool
    {
        $method = new \ReflectionMethod(WlsRequest::class, 'shouldWriteDevServerDump');
        $method->setAccessible(true);

        return $method->invoke(null, $devMode);
    }
}
