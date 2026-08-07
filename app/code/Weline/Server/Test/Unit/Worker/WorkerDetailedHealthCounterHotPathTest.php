<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Worker;

use PHPUnit\Framework\TestCase;

final class WorkerDetailedHealthCounterHotPathTest extends TestCase
{
    /**
     * @dataProvider productionWorkerHotPathLogs
     * @param list<string> $perRequestMessages
     */
    public function testDefaultRequestPathDoesNotConstructOrEmitInfoLogs(
        string $script,
        array $perRequestMessages,
    ): void {
        $source = $this->source($script);

        self::assertStringContainsString(
            "\\define('WLS_WORKER_HOT_PATH_LOGS_ENABLED', ",
            $source,
        );
        foreach ($perRequestMessages as $message) {
            $this->assertEveryInfoMessageIsHotPathGuarded($source, $message);
        }

        if ($script === 'worker.php') {
            $requestLogStart = \strpos(
                $source,
                'if ($isFrontend && !isset($requestLogged[$connId])) {',
            );
            self::assertIsInt($requestLogStart);
            $requestLogEnd = \strpos($source, '$frame = wlsParseHttpRequestFrame(', $requestLogStart);
            self::assertIsInt($requestLogEnd);
            self::assertStringNotContainsString(
                '\\fflush(',
                \substr($source, $requestLogStart, $requestLogEnd - $requestLogStart),
                'WlsLogger already flushes an enabled stdout sink.',
            );
        }
    }

    /**
     * @dataProvider productionWorkers
     */
    public function testNormalRequestsDoNotEagerlyScanDetailedHealthCounters(
        string $script,
        bool $expectsHttp2Counter,
    ): void {
        $source = $this->source($script);
        $calls = $this->handleRequestCallArguments($source);

        self::assertNotEmpty($calls, 'Expected at least one handleRequest() call in ' . $script);
        foreach ($calls as $arguments) {
            self::assertStringNotContainsString(
                'wlsDrainConnectionCounters(',
                $arguments,
                'Normal request dispatch must not scan all long-lived connections.',
            );
            self::assertStringNotContainsString(
                'wlsHttp2LiveConnectionCount(',
                $arguments,
                'Normal request dispatch must not scan all HTTP/2 connections.',
            );
        }

        $handlerOffset = \strpos($source, 'function handleRequest(');
        self::assertIsInt($handlerOffset);
        $handler = \substr($source, $handlerOffset);
        $detailBranchOffset = \strpos($handler, 'if ($wantsDetail) {');
        $drainCounterOffset = \strpos(
            $handler,
            '$drainCounters = wlsDrainConnectionCounters(',
        );

        self::assertIsInt($detailBranchOffset);
        self::assertIsInt($drainCounterOffset);
        self::assertGreaterThan(
            $detailBranchOffset,
            $drainCounterOffset,
            'Long-lived counters must be resolved only inside detailed health.',
        );

        $http2CounterOffset = \strpos(
            $handler,
            '$http2ConnectionCount = wlsHttp2LiveConnectionCount(',
        );
        if ($expectsHttp2Counter) {
            self::assertIsInt($http2CounterOffset);
            self::assertGreaterThan(
                $detailBranchOffset,
                $http2CounterOffset,
                'HTTP/2 counters must be resolved only inside detailed health.',
            );
        } else {
            self::assertFalse($http2CounterOffset);
        }
    }

    /**
     * @dataProvider productionWorkers
     */
    public function testSuspendedNormalFibersOnlyScanForPendingLongLivedTransition(
        string $script,
        bool $_expectsHttp2Counter,
    ): void {
        $source = $this->source($script);
        $suspendedLogOffset = \strpos($source, '请求进入 Fiber 异步模式');
        self::assertIsInt($suspendedLogOffset);
        $counterOffset = \strpos(
            $source,
            'wlsDrainConnectionCounters(',
            $suspendedLogOffset,
        );
        self::assertIsInt($counterOffset);
        $segment = \substr(
            $source,
            $suspendedLogOffset,
            $counterOffset - $suspendedLogOffset,
        );

        $decisionOffset = \strpos(
            $segment,
            '$shouldSampleLongLivedSaturation = $isLongLived',
        );
        self::assertIsInt(
            $decisionOffset,
            'Suspended normal Fibers must not trigger an O(n) long-lived scan.',
        );
        self::assertStringContainsString(
            '$longLivedSaturationReported && !$longLivedSaturationCleared',
            $segment,
            'A pending saturation-clear transition must retain an explicit sampling path.',
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!?\$shouldSampleLongLivedSaturation\s*(?:\|\||&&)/',
            $segment,
            'The counter call must be control-dependent on the sampling decision.',
        );
    }

    /**
     * @return iterable<string,array{string,bool}>
     */
    public static function productionWorkers(): iterable
    {
        yield 'plain HTTP' => ['worker.php', false];
        yield 'TLS HTTP/1.1, HTTP/2 and HTTP/3' => ['worker_ssl.php', true];
    }

    /**
     * @return iterable<string,array{string,list<string>}>
     */
    public static function productionWorkerHotPathLogs(): iterable
    {
        yield 'plain HTTP' => ['worker.php', [
            '→ {$method} {$uri}',
            '静态文件缓存:',
            '准备进入框架处理:',
            '开始创建 WlsRequest 对象',
            'WlsRequest 对象创建成功:',
            '调用 runtime->handle()',
            'runtime->handle() 完成',
            '返回已格式化的 HTTP 响应',
            '请求进入 Fiber 异步模式',
        ]];
        yield 'TLS HTTP/1.1, HTTP/2 and HTTP/3' => ['worker_ssl.php', [
            '静态文件缓存:',
            '准备进入框架处理:',
            '请求进入 Fiber 异步模式',
        ]];
    }

    private function assertEveryInfoMessageIsHotPathGuarded(string $source, string $message): void
    {
        $offset = 0;
        $matches = 0;
        while (($messageOffset = \strpos($source, $message, $offset)) !== false) {
            ++$matches;
            $prefix = \substr($source, 0, $messageOffset);
            $callOffset = \strrpos($prefix, 'WlsLogger::info_(');
            self::assertIsInt($callOffset, 'Expected INFO call for marker: ' . $message);
            $guardOffset = \strrpos(
                \substr($source, 0, $callOffset),
                'if (WLS_WORKER_HOT_PATH_LOGS_ENABLED) {',
            );
            self::assertIsInt($guardOffset, 'Missing hot-path guard for marker: ' . $message);
            $between = \substr($source, $guardOffset, $callOffset - $guardOffset);
            self::assertLessThan(
                240,
                \strlen($between),
                'Hot-path guard is not local to INFO marker: ' . $message,
            );
            self::assertStringNotContainsString(
                '}',
                $between,
                'INFO marker escaped its hot-path guard: ' . $message,
            );
            $offset = $messageOffset + \strlen($message);
        }

        self::assertGreaterThan(0, $matches, 'Missing expected request log marker: ' . $message);
    }

    /**
     * @return list<string>
     */
    private function handleRequestCallArguments(string $source): array
    {
        $tokens = \token_get_all($source);
        $calls = [];

        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== T_STRING || $token[1] !== 'handleRequest') {
                continue;
            }

            $previous = $this->previousSignificantToken($tokens, $index);
            if (\is_array($previous) && $previous[0] === T_FUNCTION) {
                continue;
            }

            $openIndex = $this->nextSignificantTokenIndex($tokens, $index + 1);
            if ($openIndex === null || $tokens[$openIndex] !== '(') {
                continue;
            }

            $depth = 0;
            $arguments = '';
            for ($cursor = $openIndex; $cursor < \count($tokens); ++$cursor) {
                $part = $tokens[$cursor];
                $text = \is_array($part) ? $part[1] : $part;
                if ($text === '(') {
                    ++$depth;
                } elseif ($text === ')') {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                }
                $arguments .= $text;
            }
            $calls[] = $arguments;
        }

        return $calls;
    }

    /**
     * @param list<array{int,string,int}|string> $tokens
     * @return array{int,string,int}|string|null
     */
    private function previousSignificantToken(array $tokens, int $index): array|string|null
    {
        for ($cursor = $index - 1; $cursor >= 0; --$cursor) {
            $token = $tokens[$cursor];
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $token;
        }

        return null;
    }

    /**
     * @param list<array{int,string,int}|string> $tokens
     */
    private function nextSignificantTokenIndex(array $tokens, int $index): ?int
    {
        for ($cursor = $index, $count = \count($tokens); $cursor < $count; ++$cursor) {
            $token = $tokens[$cursor];
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $cursor;
        }

        return null;
    }

    private function source(string $script): string
    {
        $path = \dirname(__DIR__, 3) . '/bin/' . $script;
        $source = \file_get_contents($path);
        self::assertIsString($source, 'Unable to read production worker: ' . $path);

        return $source;
    }
}
