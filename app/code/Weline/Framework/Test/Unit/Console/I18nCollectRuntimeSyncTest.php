<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

// Install only from setUp in the isolated child, never during suite discovery.
final class I18nCollectCacheTestBoundary
{
    public static function install(): void
    {
        function w_cache(string $pool): object
        {
            return new class {
                public function clear(): bool
                {
                    \Weline\Framework\Test\Unit\Console\I18nCollectRuntimeSyncTest::$events[] = 'local-clear';
                    return true;
                }
            };
        }
    }
}

namespace Weline\Framework\Console\Console\I18n;

final class I18nCollectCopyTestBoundary
{
    public static function install(): void
    {
        function __(string $text, array $params = []): string
        {
            foreach ($params as $index => $value) {
                $text = \str_replace('%{' . ($index + 1) . '}', (string)$value, $text);
            }
            return $text;
        }
    }
}

namespace Weline\Framework\Test\Unit\Console;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Console\Console\I18n\Collect;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Phrase\DictionaryCompiler;
use Weline\Framework\Runtime\RuntimeControlBroadcasterInterface;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class I18nCollectRuntimeSyncTest extends TestCase
{
    public static array $events = [];

    protected function setUp(): void
    {
        self::$events = [];
        \Weline\Framework\Phrase\I18nCollectCacheTestBoundary::install();
        \Weline\Framework\Console\Console\I18n\I18nCollectCopyTestBoundary::install();
    }

    public function testCollectionRefreshesWorkersAfterWritingTheDictionary(): void
    {
        $compiler = $this->compiler();
        $broadcaster = $this->createMock(RuntimeControlBroadcasterInterface::class);
        $broadcaster->expects(self::never())->method('cacheClear');
        $broadcaster->expects(self::once())->method('cacheClearAndWait')
            ->with(null, 12.0)
            ->willReturnCallback(static function (): array {
                self::$events[] = 'workers-completed';
                return ['success' => true, 'completed' => true, 'message' => 'cleared'];
            });

        $output = $this->runCommand($compiler, $broadcaster);

        self::assertSame(['compiled', 'local-clear', 'workers-completed'], self::$events);
        self::assertStringContainsString('翻译缓存清理成功！', $output);
    }

    public function testQueuedWorkerRefreshDoesNotReportCompletedTranslationClear(): void
    {
        $broadcaster = $this->createMock(RuntimeControlBroadcasterInterface::class);
        $broadcaster->expects(self::once())->method('cacheClearAndWait')
            ->willReturn(['success' => true, 'completed' => false, 'message' => 'workers still pending']);

        $output = $this->runCommand($this->compiler(), $broadcaster);

        self::assertStringContainsString('workers still pending', $output);
        self::assertStringNotContainsString('翻译缓存清理成功！', $output);
        self::assertStringContainsString('语言包收集成功！', $output);
    }

    public function testWorkerTransportFailureKeepsCollectionAndReportsTheCause(): void
    {
        $broadcaster = $this->createMock(RuntimeControlBroadcasterInterface::class);
        $broadcaster->expects(self::once())->method('cacheClearAndWait')
            ->willThrowException(new \RuntimeException('control connection unavailable'));

        $output = $this->runCommand($this->compiler(), $broadcaster);

        self::assertStringContainsString('control connection unavailable', $output);
        self::assertStringNotContainsString('翻译缓存清理成功！', $output);
        self::assertStringContainsString('语言包收集成功！', $output);
    }

    public function testCompilationFailureDoesNotRefreshWorkers(): void
    {
        $compiler = $this->createMock(DictionaryCompiler::class);
        $compiler->expects(self::once())->method('compile')
            ->willThrowException(new \RuntimeException('dictionary write failed'));
        $broadcaster = $this->createMock(RuntimeControlBroadcasterInterface::class);
        $broadcaster->expects(self::never())->method('cacheClearAndWait');

        $output = $this->runCommand($compiler, $broadcaster);

        self::assertSame([], self::$events);
        self::assertStringContainsString('dictionary write failed', $output);
    }

    private function compiler(): DictionaryCompiler
    {
        $compiler = $this->createMock(DictionaryCompiler::class);
        $compiler->expects(self::once())->method('compile')
            ->with('Weline_Product', false)
            ->willReturnCallback(static function (): array {
                self::$events[] = 'compiled';
                return [];
            });
        return $compiler;
    }

    private function runCommand(
        DictionaryCompiler $compiler,
        RuntimeControlBroadcasterInterface $broadcaster,
    ): string {
        $command = new Collect($compiler, new Printing(), $broadcaster);
        \ob_start();
        try {
            $command->execute(['module' => 'Weline_Product']);
            return (string)\ob_get_contents();
        } finally {
            \ob_end_clean();
        }
    }
}
