<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Php;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Php\CurlStreamPump;
use Weline\Framework\Runtime\SchedulerSystem;

final class CurlStreamPumpTest extends TestCase
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

    public function testRegisterAndAwaitChunkReturnsFullBodyForSingleHandle(): void
    {
        $port = $this->startFixtureServer(['hello', ' ', 'world'], delayMs: 5);

        $pump = new CurlStreamPump();
        $handleId = $pump->register($this->makeHandle($port));

        $body = '';
        while (($chunk = $pump->awaitChunk($handleId)) !== null) {
            $body .= $chunk;
        }
        $info = $pump->finalize($handleId);

        self::assertSame('hello world', $body);
        self::assertTrue($info['ok'], 'curl handle should report ok=true');
        self::assertSame(0, $info['errno']);
    }

    public function testParallelHandlesProgressDuringSingleAwait(): void
    {
        $delayMs = 60;
        $portA = $this->startFixtureServer(['a1', 'a2', 'a3'], delayMs: $delayMs);
        $portB = $this->startFixtureServer(['b1', 'b2', 'b3'], delayMs: $delayMs);
        $portC = $this->startFixtureServer(['c1', 'c2', 'c3'], delayMs: $delayMs);

        $pump = new CurlStreamPump();
        $hA = $pump->register($this->makeHandle($portA));
        $hB = $pump->register($this->makeHandle($portB));
        $hC = $pump->register($this->makeHandle($portC));

        $start = \microtime(true);
        $bodies = [];
        foreach (['a' => $hA, 'b' => $hB, 'c' => $hC] as $key => $hid) {
            $body = '';
            while (($chunk = $pump->awaitChunk($hid)) !== null) {
                $body .= $chunk;
            }
            $bodies[$key] = $body;
            $pump->finalize($hid);
        }
        $elapsed = \microtime(true) - $start;

        self::assertSame('a1a2a3', $bodies['a']);
        self::assertSame('b1b2b3', $bodies['b']);
        self::assertSame('c1c2c3', $bodies['c']);

        // 每个 fixture pause delayMs * 3 ≈ 180ms。串行 ≈ 540ms；并行应当 ~250ms。CI 余量 0.45s。
        self::assertLessThan(
            0.45,
            $elapsed,
            \sprintf('Parallel handles must finish well under sequential sum (elapsed=%.3fs)', $elapsed)
        );
    }

    public function testTickReportsProgressOnlyWhileMultiActive(): void
    {
        $port = $this->startFixtureServer(['x'], delayMs: 5);

        $pump = new CurlStreamPump();
        $hid = $pump->register($this->makeHandle($port));

        $sawAnyProgress = false;
        $deadline = \microtime(true) + 2.0;
        while (\microtime(true) < $deadline) {
            if ($pump->tick(0.05)) {
                $sawAnyProgress = true;
            }
            if ($pump->isComplete($hid)) {
                break;
            }
        }
        self::assertTrue($sawAnyProgress, 'tick() must report progress while handle is active');
        self::assertTrue($pump->isComplete($hid));

        $body = '';
        while (($chunk = $pump->awaitChunk($hid)) !== null) {
            $body .= $chunk;
        }
        self::assertSame('x', $body);
        self::assertTrue($pump->isDrained($hid));

        $pump->finalize($hid);
        self::assertFalse($pump->tick(0.01));
    }

    public function testFinalizeReportsErrorWhenConnectionRefused(): void
    {
        $port = $this->findFreePort(); // never bind a server here
        $pump = new CurlStreamPump();
        $hid = $pump->register($this->makeHandle($port, timeout: 2));

        $body = '';
        while (($chunk = $pump->awaitChunk($hid)) !== null) {
            $body .= $chunk;
        }
        $info = $pump->finalize($hid);

        self::assertSame('', $body);
        self::assertFalse($info['ok'], 'connection refused must surface as ok=false');
        self::assertNotSame(0, $info['errno']);
        self::assertNotSame('', $info['error']);
    }

    public function testRegisteringSameHandleTwiceThrows(): void
    {
        $pump = new CurlStreamPump();
        $ch = \curl_init('http://127.0.0.1:1');
        $pump->register($ch);
        $this->expectException(\InvalidArgumentException::class);
        $pump->register($ch);
    }

    public function testAwaitChunkOnUnknownHandleThrows(): void
    {
        $pump = new CurlStreamPump();
        $this->expectException(\InvalidArgumentException::class);
        $pump->awaitChunk(99999);
    }

    private function makeHandle(int $port, int $timeout = 5): \CurlHandle
    {
        $ch = \curl_init("http://127.0.0.1:{$port}/");
        \curl_setopt($ch, \CURLOPT_CONNECTTIMEOUT, $timeout);
        \curl_setopt($ch, \CURLOPT_TIMEOUT, $timeout);
        \curl_setopt($ch, \CURLOPT_HTTPHEADER, ['Accept: */*']);

        return $ch;
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
            self::fail('Failed to spawn curl-stream fixture server.');
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
            self::fail('tempnam failed for curl-stream fixture script.');
        }
        \file_put_contents($path, $script);
        $this->fixtureScriptPaths[] = $path;

        return $path;
    }
}
