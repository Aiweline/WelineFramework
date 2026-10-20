<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

if (\getenv('WLS_OOM_PROBE') === '1') {
    require \dirname(__DIR__, 3) . '/Service/WorkerMemoryExhaustionResponder.php';
    $out = (string)\getenv('WLS_OOM_OUT');
    $fp = \fopen($out, 'wb');
    \Weline\Server\Service\WorkerMemoryExhaustionResponder::arm();
    \Weline\Server\Service\WorkerMemoryExhaustionResponder::attach($fp);
    $block = \str_repeat('x', 1024 * 1024);
    while (true) {
        $block .= $block;
    }
}

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerMemoryExhaustionResponder;

class WorkerMemoryExhaustionResponderTest extends TestCase
{
    public function testPayloadMatchesAdmissionMemoryPressure(): void
    {
        $payload = WorkerMemoryExhaustionResponder::payload();
        self::assertStringContainsString('HTTP/1.1 503 Service Unavailable', $payload);
        self::assertStringContainsString('X-WLS-Admission: memory_pressure', $payload);
        self::assertStringContainsString('Retry-After: 2', $payload);
        self::assertStringContainsString('Connection: close', $payload);
    }

    public function testMemoryExhaustionWrites503BeforeExit(): void
    {
        $out = \tempnam(\sys_get_temp_dir(), 'wls-oom-503-');
        self::assertIsString($out);
        $cmd = [
            \PHP_BINARY,
            '-d',
            'memory_limit=32M',
            __FILE__,
        ];
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = \proc_open($cmd, $spec, $pipes, null, [
            'WLS_OOM_PROBE' => '1',
            'WLS_OOM_OUT' => $out,
        ]);
        self::assertIsResource($proc);
        \fclose($pipes[0]);
        \stream_get_contents($pipes[1]);
        \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);
        $body = (string)\file_get_contents($out);
        @\unlink($out);
        self::assertNotSame(0, $exit);
        self::assertStringStartsWith('HTTP/1.1 503 Service Unavailable', $body);
        self::assertStringContainsString('X-WLS-Admission: memory_pressure', $body);
    }
}
