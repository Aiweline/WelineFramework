<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Php;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Php\CurlStreamPump;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\SchedulerSystem;

final class FiberTaskRunnerStreamingTest extends TestCase
{
    /** @var array<int, resource> */
    private array $fixtureProcs = [];

    /** @var list<string> */
    private array $fixtureScriptPaths = [];

    protected function setUp(): void
    {
        SchedulerSystem::disableScheduler();
        WelineEnv::getInstance()->reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureProcs as $proc) {
            if (\is_resource($proc)) {
                @\proc_terminate($proc);
                @\proc_close($proc);
            }
        }
        $this->fixtureProcs = [];

        foreach ($this->fixtureScriptPaths as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }
        $this->fixtureScriptPaths = [];

        SchedulerSystem::disableScheduler();
        WelineEnv::getInstance()->reset();
    }

    public function testCurrentPumpIsAvailableInsideRunAndNullOutside(): void
    {
        self::assertNull(FiberTaskRunner::currentPump());

        $runner = new FiberTaskRunner(defaultConcurrency: 2);
        $results = $runner->run([
            'a' => static function (): bool {
                return FiberTaskRunner::currentPump() instanceof CurlStreamPump;
            },
            'b' => static function (): bool {
                FiberTaskRunner::yield();
                return FiberTaskRunner::currentPump() instanceof CurlStreamPump;
            },
        ]);

        self::assertTrue($results['a']);
        self::assertTrue($results['b']);
        self::assertNull(FiberTaskRunner::currentPump());
    }

    public function testCurrentPumpRemainsNullWhenRunningSequentially(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 1);
        $results = $runner->run([
            'a' => static function (): ?CurlStreamPump {
                return FiberTaskRunner::currentPump();
            },
        ]);

        self::assertNull($results['a'], 'Sequential path should not activate a pump.');
        self::assertNull(FiberTaskRunner::currentPump());
    }

    public function testParallelStreamingFibersFinishCloseToMaxTaskDuration(): void
    {
        $delayMs = 60;
        $portA = $this->startFixtureServer(['a1', 'a2', 'a3'], delayMs: $delayMs);
        $portB = $this->startFixtureServer(['b1', 'b2', 'b3'], delayMs: $delayMs);
        $portC = $this->startFixtureServer(['c1', 'c2', 'c3'], delayMs: $delayMs);

        $runner = new FiberTaskRunner(defaultConcurrency: 3);

        $tasks = [
            'a' => $this->makeFiberStreamingTask($portA),
            'b' => $this->makeFiberStreamingTask($portB),
            'c' => $this->makeFiberStreamingTask($portC),
        ];

        $start = \microtime(true);
        $results = $runner->run($tasks);
        $elapsed = \microtime(true) - $start;

        self::assertSame(
            [
                'a' => 'a1a2a3',
                'b' => 'b1b2b3',
                'c' => 'c1c2c3',
            ],
            $results
        );

        // 每个 fixture server 串行 3 chunk × 60ms ≈ 180ms。
        // 三个 Fiber 串行 = 540ms+；真正并行应当 ~250ms 左右。CI/Windows 留 0.45s 余量。
        self::assertLessThan(
            0.45,
            $elapsed,
            \sprintf('Streaming fibers must beat sequential sum (elapsed=%.3fs)', $elapsed)
        );
    }

    public function testFiberExceptionInsideStreamingTaskIsRethrown(): void
    {
        $runner = new FiberTaskRunner(defaultConcurrency: 2);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stream-blew-up');

        $runner->run([
            'bad' => static function (): void {
                $pump = FiberTaskRunner::currentPump();
                self::assertInstanceOf(CurlStreamPump::class, $pump);
                throw new \RuntimeException('stream-blew-up');
            },
            'other' => static function (): string {
                FiberTaskRunner::yield();
                return 'other';
            },
        ]);
    }

    private function makeFiberStreamingTask(int $port): \Closure
    {
        return static function () use ($port): string {
            $pump = FiberTaskRunner::currentPump();
            if (!$pump instanceof CurlStreamPump) {
                throw new \RuntimeException('Pump must be available inside FiberTaskRunner.');
            }

            $ch = \curl_init("http://127.0.0.1:{$port}/");
            \curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, 5);
            \curl_setopt($ch, \CURLOPT_TIMEOUT, 5);

            $hid = $pump->register($ch);
            try {
                $body = '';
                while (($chunk = $pump->awaitChunk($hid)) !== null) {
                    $body .= $chunk;
                }
                return $body;
            } finally {
                $pump->finalize($hid);
            }
        };
    }

    /**
     * @param list<string> $chunks
     */
    private function startFixtureServer(array $chunks, int $delayMs): int
    {
        $port = $this->findFreePort();
        $scriptPath = $this->writeFixtureScript();

        $cmd = [PHP_BINARY, $scriptPath, (string)$port, (string)$delayMs];
        foreach ($chunks as $chunk) {
            $cmd[] = $chunk;
        }

        $proc = \proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        if (!\is_resource($proc)) {
            self::fail('Failed to spawn fixture server.');
        }
        $this->fixtureProcs[] = $proc;

        \stream_set_blocking($pipes[1], true);
        $deadline = \microtime(true) + 2.5;
        while (\microtime(true) < $deadline) {
            $line = \fgets($pipes[1]);
            if ($line !== false && \trim($line) === 'READY') {
                return $port;
            }
            \usleep(10_000);
        }

        self::fail("Fixture server failed to signal READY on port {$port}.");
    }

    private function findFreePort(): int
    {
        $sock = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!\is_resource($sock)) {
            self::fail("Could not allocate free port: {$errstr}");
        }
        $name = \stream_socket_get_name($sock, false);
        \fclose($sock);
        $pos = \strrpos((string)$name, ':');
        if ($pos === false) {
            self::fail('Could not parse free port name.');
        }

        return (int)\substr((string)$name, $pos + 1);
    }

    private function writeFixtureScript(): string
    {
        $script = <<<'PHP'
<?php
$port = (int)($argv[1] ?? 0);
$delayMs = (int)($argv[2] ?? 0);
$chunks = array_slice($argv, 3);
if ($port <= 0 || $chunks === []) {
    fwrite(STDERR, "bad args\n");
    exit(2);
}

$sock = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
if (!is_resource($sock)) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(3);
}
fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$conn = @stream_socket_accept($sock, 8);
if (!is_resource($conn)) {
    fclose($sock);
    exit(4);
}
stream_set_timeout($conn, 3);
while (($line = fgets($conn)) !== false) {
    if ($line === "\r\n" || $line === "\n") {
        break;
    }
}

$body = implode('', $chunks);
$head = "HTTP/1.1 200 OK\r\n"
    . "Content-Length: " . strlen($body) . "\r\n"
    . "Content-Type: text/plain\r\n"
    . "Connection: close\r\n"
    . "\r\n";
fwrite($conn, $head);
fflush($conn);

foreach ($chunks as $chunk) {
    fwrite($conn, $chunk);
    fflush($conn);
    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
}

fclose($conn);
fclose($sock);
exit(0);
PHP;

        $path = \tempnam(\sys_get_temp_dir(), 'curlpump_fix_');
        if ($path === false) {
            self::fail('tempnam failed for streaming fixture script.');
        }
        \file_put_contents($path, $script);
        $this->fixtureScriptPaths[] = $path;

        return $path;
    }
}
