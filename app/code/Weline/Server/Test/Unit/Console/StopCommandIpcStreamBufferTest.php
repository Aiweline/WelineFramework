<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\IPC\ControlMessage;

final class StopCommandIpcStreamBufferTest extends TestCase
{
    public function testExtractCompleteIpcLinesPreservesTrailingFragment(): void
    {
        $stop = $this->createStopProbe();
        $message = ControlMessage::commandResult(true, [], '阶段5完成: 全部 2 个子进程已退出');
        $mid = (int) \floor(\strlen($message) / 2);

        $buffer = \substr($message, 0, $mid);
        self::assertSame([], $stop->extractLines($buffer));
        self::assertSame(\substr($message, 0, $mid), $buffer);

        $buffer .= \substr($message, $mid);
        $lines = $stop->extractLines($buffer);

        self::assertCount(1, $lines);
        self::assertSame('', $buffer);
    }

    public function testProcessStopProgressLineMarksMasterExitAfterStageFiveCompletion(): void
    {
        $stop = $this->createStopProbe();
        $lastProgress = '';
        $exitedPids = [];
        $totalInstances = 0;
        $observedStopStage = 0;
        $childrenFullyExited = false;
        $masterAboutToExit = false;

        $stop->processLine(
            ControlMessage::commandResult(true, [], '阶段5完成: 全部 2 个子进程已退出'),
            $lastProgress,
            $exitedPids,
            $totalInstances,
            $observedStopStage,
            $childrenFullyExited,
            $masterAboutToExit
        );

        self::assertSame(5, $observedStopStage);
        self::assertTrue($childrenFullyExited);
        self::assertTrue($masterAboutToExit);
        self::assertSame('阶段5完成: 全部 2 个子进程已退出', $lastProgress);
    }

    public function testFlushTrailingIpcBufferLinesReturnsLastLineWithoutNewline(): void
    {
        $stop = $this->createStopProbe();
        $buffer = ControlMessage::commandResult(true, [], '阶段6/6: 关闭 IPC 服务器');

        $lines = $stop->flushLines($buffer);

        self::assertCount(1, $lines);
        self::assertSame('', $buffer);
    }

    /**
     * @return object{extractLines: callable, flushLines: callable, processLine: callable}
     */
    private function createStopProbe(): object
    {
        return new class extends Stop {
            protected function ipcProgress(string $message): void
            {
                unset($message);
            }

            public function extractLines(string &$buffer): array
            {
                return $this->extractCompleteIpcLines($buffer);
            }

            public function flushLines(string &$buffer): array
            {
                return $this->flushTrailingIpcBufferLines($buffer);
            }

            public function processLine(
                string $line,
                string &$lastProgress,
                array &$exitedPids,
                int &$totalInstances,
                int &$observedStopStage,
                bool &$childrenFullyExited,
                bool &$masterAboutToExit
            ): void {
                $this->processStopProgressLine(
                    $line,
                    $lastProgress,
                    $exitedPids,
                    $totalInstances,
                    $observedStopStage,
                    $childrenFullyExited,
                    $masterAboutToExit
                );
            }
        };
    }
}
