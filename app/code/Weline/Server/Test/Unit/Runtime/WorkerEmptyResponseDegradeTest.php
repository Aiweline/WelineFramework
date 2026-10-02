<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

/**
 * 非预期空响应必须降级为 503，而不是 200 空体。
 *
 * 背景：渲染失败（输出捕获触顶、连接池耗尽等）时 worker 会记录
 * [UnexpectedEmptyResponse] 但仍然回 200 空体。空页会被搜索引擎收录，
 * 用户侧只看到白屏，且 200 让上游无法按「可重试」处理。
 */
final class WorkerEmptyResponseDegradeTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function workerEntries(): array
    {
        $root = \dirname(__DIR__, 7);

        return [
            $root . '/app/code/Weline/Server/bin/worker.php',
            $root . '/app/code/Weline/Server/bin/worker_ssl.php',
        ];
    }

    public function testBothWorkerEntriesDegradeUnexpectedEmptyBodies(): void
    {
        foreach ($this->workerEntries() as $path) {
            self::assertFileExists($path);
            $source = (string)\file_get_contents($path);
            $label = \basename($path);

            // 定位空响应日志之后的降级块
            $logPos = \strpos($source, "'[UnexpectedEmptyResponse] method='");
            self::assertIsInt($logPos, "{$label} 应保留空响应日志");

            $guardPos = \strpos($source, "if (\$responseBody === '' && !\$isExpectedEmptyResponse) {", $logPos);
            self::assertIsInt($guardPos, "{$label} 必须在空响应日志之后降级响应");

            $degrade = \substr($source, $guardPos, 1200);
            self::assertStringContainsString(
                '$response->setHttpResponseCode(503);',
                $degrade,
                "{$label} 空响应必须降级为 503",
            );
            self::assertStringContainsString("'Retry-After', '5'", $degrade, "{$label} 必须给出 Retry-After");
            self::assertStringContainsString(
                "'X-WLS-Degrade', 'empty-response'",
                $degrade,
                "{$label} 必须携带降级标记头，便于线上取证",
            );
        }
    }

    public function testHeadAndRedirectAndStreamResponsesAreStillAllowedToBeEmpty(): void
    {
        foreach ($this->workerEntries() as $path) {
            $source = (string)\file_get_contents($path);
            $label = \basename($path);

            // 既有预期空体判定不得被削弱
            self::assertStringContainsString(
                "\$isExpectedEmptyResponse = \\strtoupper(\$method) === 'HEAD'",
                $source,
                "{$label} 必须保留 HEAD 豁免",
            );
            self::assertStringContainsString("\\in_array(\$statusCode, [204, 205, 304], true)", $source);
            self::assertStringContainsString("\\str_contains(\$responseContentType, 'text/event-stream')", $source);
        }
    }
}
