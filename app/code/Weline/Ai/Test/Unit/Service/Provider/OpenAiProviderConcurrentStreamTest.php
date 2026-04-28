<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Provider;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Provider\OpenAiProvider;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Php\FiberTaskRunner;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * P3 集成冒烟：在 FiberTaskRunner 的 pump 上下文里并发 N 路 SSE 流，证明
 *   - OpenAiProvider::callStreamApi 自动切到 callStreamApiViaPump 分支
 *   - 总耗时显著低于串行 sum（pump-based curl_multi 真并发）
 *   - 每个任务都拿到完整、顺序正确的 chunk
 */
final class OpenAiProviderConcurrentStreamTest extends TestCase
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

    public function testParallelStreamCallsCompleteFasterThanSerialSum(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $taskCount = 4;
        $perChunkDelayMs = 30;
        $chunksPerTask = 5;

        $ports = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $ports[$i] = $this->startSseFixture(
                texts: \array_map(static fn(int $n): string => "task{$i}-c{$n}", \range(0, $chunksPerTask - 1)),
                delayMs: $perChunkDelayMs,
            );
        }

        $provider = new OpenAiProvider();
        /** @var array<int, list<string>> $received */
        $received = [];

        $tasks = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $port = $ports[$i];
            $taskId = $i;
            $tasks[$taskId] = function () use ($provider, $port, $taskId, &$received): bool {
                $url = "http://127.0.0.1:{$port}/v1/chat/completions";
                $apiKey = 'test-key';
                $data = ['model' => 'gpt-test', 'stream' => true, 'messages' => []];
                $proxyInfo = [];
                $timeout = 30;

                $reflector = new \ReflectionMethod(OpenAiProvider::class, 'callStreamApi');
                $reflector->setAccessible(true);
                $reflector->invoke(
                    $provider,
                    $url,
                    $apiKey,
                    $data,
                    function (string $chunk) use (&$received, $taskId): void {
                        $received[$taskId][] = $chunk;
                    },
                    $proxyInfo,
                    $timeout,
                );

                return true;
            };
        }

        $runner = new FiberTaskRunner(defaultConcurrency: $taskCount);

        $start = \microtime(true);
        $events = [];
        foreach ($runner->runEvents($tasks) as $key => $event) {
            $events[$key] = $event;
        }
        $elapsedMs = (\microtime(true) - $start) * 1000.0;

        self::assertCount($taskCount, $events);
        foreach ($events as $taskId => $event) {
            self::assertSame('fulfilled', $event['status'], "task {$taskId} unexpectedly rejected: " . (
                isset($event['error']) ? $event['error']->getMessage() : 'no error payload'
            ));
            self::assertSame(true, $event['result']);
        }

        self::assertCount($taskCount, $received);
        for ($i = 0; $i < $taskCount; $i++) {
            self::assertSame(
                \array_map(static fn(int $n): string => "task{$i}-c{$n}", \range(0, $chunksPerTask - 1)),
                $received[$i],
                "task {$i} received unexpected chunks"
            );
        }

        // 串行下界：每个 task 至少 chunksPerTask*delayMs 串起来 = 5*30=150ms。
        // sum(N) = taskCount*150 = 600ms。pump 真并发下应远小于该值。
        $serialSumMs = $taskCount * $chunksPerTask * $perChunkDelayMs;
        // 留 25% 冗余给 fixture HTTP 启动 + cURL 初始化抖动。
        self::assertLessThan(
            $serialSumMs * 0.75,
            $elapsedMs,
            \sprintf(
                'Concurrent runEvents took %.1fms (>=%.1fms = 75%% of serial sum %dms); pump 不像在并发推进。',
                $elapsedMs,
                $serialSumMs * 0.75,
                $serialSumMs
            )
        );
    }

    /**
     * @param list<string> $texts
     */
    private function startSseFixture(array $texts, int $delayMs): int
    {
        $port = $this->findFreePort();
        $scriptPath = $this->writeSseFixtureScript();

        $cmd = [\PHP_BINARY, $scriptPath, (string)$port, (string)$delayMs];
        foreach ($texts as $text) {
            $cmd[] = $text;
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
            self::fail('Failed to spawn SSE fixture server.');
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

        self::fail("SSE fixture server failed to signal READY on port {$port}.");
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

    private function writeSseFixtureScript(): string
    {
        $script = <<<'PHP'
<?php
$port = (int)($argv[1] ?? 0);
$delayMs = (int)($argv[2] ?? 0);
$texts = array_slice($argv, 3);
if ($port <= 0 || $texts === []) {
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
stream_set_timeout($conn, 5);
while (($line = fgets($conn)) !== false) {
    if ($line === "\r\n" || $line === "\n") {
        break;
    }
}

$head = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: text/event-stream\r\n"
    . "Cache-Control: no-cache\r\n"
    . "Connection: close\r\n"
    . "X-Accel-Buffering: no\r\n"
    . "Transfer-Encoding: chunked\r\n"
    . "\r\n";
fwrite($conn, $head);
fflush($conn);

$writeChunk = static function ($conn, string $payload): void {
    $hex = dechex(strlen($payload));
    fwrite($conn, $hex . "\r\n" . $payload . "\r\n");
    fflush($conn);
};

foreach ($texts as $text) {
    $event = json_encode([
        'choices' => [[
            'delta' => ['content' => $text],
        ]],
    ], JSON_UNESCAPED_UNICODE);
    $writeChunk($conn, "data: {$event}\n\n");
    if ($delayMs > 0) {
        usleep($delayMs * 1000);
    }
}

$writeChunk($conn, "data: [DONE]\n\n");
$writeChunk($conn, '');

fclose($conn);
fclose($sock);
exit(0);
PHP;

        $path = \tempnam(\sys_get_temp_dir(), 'sse_fix_');
        if ($path === false) {
            self::fail('tempnam failed for SSE fixture script.');
        }
        \file_put_contents($path, $script);
        $this->fixtureScriptPaths[] = $path;

        return $path;
    }
}
