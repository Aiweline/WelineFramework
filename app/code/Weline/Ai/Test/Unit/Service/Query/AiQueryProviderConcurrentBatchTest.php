<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Extends\Module\Weline_Framework\Query\AiQueryProvider;
use Weline\Ai\Service\AiService;
use Weline\Ai\Service\Provider\OpenAiProvider;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\SchedulerSystem;

/**
 * 集成基线：通过 AiQueryProvider::generateStreamBatch 跑 N 路 OpenAI 兼容 SSE，
 * 断言 FiberTaskRunner + CurlStreamPump 真并发，总耗时 < 75% 串行 sum。
 */
final class AiQueryProviderConcurrentBatchTest extends TestCase
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

    public function testGenerateStreamBatchExecutesFourSseStreamsInTrueParallel(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $taskCount = 4;
        $perChunkDelayMs = 30;
        $chunksPerTask = 5;

        // 路由表：prompt => SSE fixture URL
        $routes = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $port = $this->startSseFixture(
                texts: \array_map(static fn(int $n): string => "task{$i}-c{$n}", \range(0, $chunksPerTask - 1)),
                delayMs: $perChunkDelayMs,
            );
            $routes["prompt-{$i}"] = "http://127.0.0.1:{$port}/v1/chat/completions";
        }

        $aiService = $this->getMockBuilder(AiService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateStream'])
            ->getMock();
        $aiService->method('generateStream')
            ->willReturnCallback(static function (
                string $prompt,
                callable $callback,
                ?string $modelCode = null,
                ?string $scenarioCode = null,
                ?string $locale = null,
                array $params = []
            ) use ($routes): void {
                $url = $routes[$prompt] ?? null;
                if ($url === null) {
                    throw new \RuntimeException("No fixture for prompt {$prompt}");
                }

                $provider = new OpenAiProvider();
                $callStreamApi = new \ReflectionMethod(OpenAiProvider::class, 'callStreamApi');
                $callStreamApi->setAccessible(true);
                $callStreamApi->invoke(
                    $provider,
                    $url,
                    'test-key',
                    ['model' => 'gpt-test', 'stream' => true, 'messages' => []],
                    $callback,
                    [],
                    30
                );
            });

        $provider = new AiQueryProvider($aiService);

        /** @var array<string, list<string>> $received */
        $received = [];
        $tasks = [];
        for ($i = 0; $i < $taskCount; $i++) {
            $key = "task-{$i}";
            $promptKey = "prompt-{$i}";
            $tasks[$key] = [
                'prompt' => $promptKey,
                'on_chunk' => static function (string $chunk) use (&$received, $key): bool {
                    $received[$key][] = $chunk;
                    return true;
                },
            ];
        }

        $start = \microtime(true);
        $events = $provider->execute('generateStreamBatch', [
            'tasks' => $tasks,
            'concurrency' => $taskCount,
        ]);
        $elapsedMs = (\microtime(true) - $start) * 1000.0;

        self::assertCount($taskCount, $events);
        foreach ($events as $key => $event) {
            self::assertSame(
                'fulfilled',
                $event['status'],
                \sprintf(
                    'task %s expected fulfilled, got %s (error: %s)',
                    (string)$key,
                    $event['status'] ?? 'n/a',
                    isset($event['error']) ? $event['error']->getMessage() : 'n/a'
                )
            );
        }

        // 每路 chunk 顺序与内容
        for ($i = 0; $i < $taskCount; $i++) {
            $key = "task-{$i}";
            self::assertSame(
                \array_map(static fn(int $n): string => "task{$i}-c{$n}", \range(0, $chunksPerTask - 1)),
                $received[$key] ?? [],
                "task {$key} chunks mismatch"
            );
        }

        // 串行 sum = 4 × 5 × 30ms = 600ms；75% 阈值 = 450ms。
        $serialSumMs = $taskCount * $chunksPerTask * $perChunkDelayMs;
        self::assertLessThan(
            $serialSumMs * 0.75,
            $elapsedMs,
            \sprintf(
                'generateStreamBatch took %.1fms (>= %.1fms, 75%% of serial sum %dms)。pump 不像在并发推进。',
                $elapsedMs,
                $serialSumMs * 0.75,
                $serialSumMs
            )
        );
    }

    public function testGenerateStreamBatchInvokesOnEventForEachSettledTaskWithFailures(): void
    {
        if (!\class_exists(\Fiber::class)) {
            self::markTestSkipped('PHP Fibers not available');
        }

        $port = $this->startSseFixture(['ok-chunk'], delayMs: 5);
        $url = "http://127.0.0.1:{$port}/v1/chat/completions";

        $aiService = $this->getMockBuilder(AiService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['generateStream'])
            ->getMock();
        $aiService->method('generateStream')
            ->willReturnCallback(static function (
                string $prompt,
                callable $callback,
                ?string $modelCode = null,
                ?string $scenarioCode = null,
                ?string $locale = null,
                array $params = []
            ) use ($url): void {
                if ($prompt === 'fail') {
                    throw new \RuntimeException('vendor outage');
                }

                $provider = new OpenAiProvider();
                $callStreamApi = new \ReflectionMethod(OpenAiProvider::class, 'callStreamApi');
                $callStreamApi->setAccessible(true);
                $callStreamApi->invoke(
                    $provider,
                    $url,
                    'test-key',
                    ['model' => 'gpt-test', 'stream' => true, 'messages' => []],
                    $callback,
                    [],
                    30
                );
            });

        /** @var array<string|int, string> $observedEvents */
        $observedEvents = [];
        $events = (new AiQueryProvider($aiService))->execute('generateStreamBatch', [
            'tasks' => [
                'good' => [
                    'prompt' => 'good',
                    'on_chunk' => static fn(): bool => true,
                ],
                'bad' => [
                    'prompt' => 'fail',
                    'on_chunk' => static fn(): bool => true,
                ],
            ],
            'concurrency' => 2,
            'on_event' => static function (string|int $key, array $event) use (&$observedEvents): void {
                $observedEvents[$key] = $event['status'] ?? 'n/a';
            },
        ]);

        self::assertSame('fulfilled', $events['good']['status']);
        self::assertSame('rejected', $events['bad']['status']);
        self::assertInstanceOf(\RuntimeException::class, $events['bad']['error']);
        self::assertSame('vendor outage', $events['bad']['error']->getMessage());

        // 完成顺序无保证：bad 立即抛、good 走 SSE 几毫秒；只断言两个 key 都被通知且状态正确。
        self::assertCount(2, $observedEvents);
        self::assertSame('fulfilled', $observedEvents['good']);
        self::assertSame('rejected', $observedEvents['bad']);
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
