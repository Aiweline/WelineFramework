<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Log\Error;

use PHPUnit\Framework\TestCase;

/**
 * Worker 请求路径必须挂上 ErrorBootstrap 请求上下文，Fatal 日志才能带 URI。
 */
final class WorkerFatalRequestContextContractTest extends TestCase
{
    /**
     * @dataProvider workerScriptProvider
     */
    public function testWorkerUpdatesAndClearsErrorBootstrapRequestContext(string $relativePath): void
    {
        $path = \dirname(__DIR__, 4) . '/' . $relativePath;
        self::assertFileExists($path);
        $source = (string)\file_get_contents($path);
        self::assertStringContainsString('ErrorBootstrap::updateRequestContext', $source);
        self::assertStringContainsString('ErrorBootstrap::clearRequestContext', $source);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function workerScriptProvider(): array
    {
        return [
            'http' => ['bin/worker.php'],
            'ssl' => ['bin/worker_ssl.php'],
            'ssl_event' => ['bin/worker_ssl_event.php'],
        ];
    }
}
