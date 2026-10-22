<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\ThemeUpdate;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlight;
use Weline\Theme\Service\ThemeUpdate\ThemeUpdateFlightRunner;

/**
 * 当前这次还没进入下一步时，后面的变更只占一个版本号。
 */
final class ThemeUpdateFlightTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/tu-flight-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->root === '' || !\is_dir($this->root)) {
            return;
        }
        foreach ((array)\glob($this->root . '/*') as $file) {
            if (\is_string($file) && \is_file($file)) {
                \unlink($file);
            }
        }
        \rmdir($this->root);
    }

    public function testLaterArrivalSupersedesHolderWithoutTakingTheBuild(): void
    {
        $alive = static fn (int $pid): bool => $pid === 10 || $pid === 11;
        $holder = new ThemeUpdateFlight($this->root, 10, $alive);
        $later = new ThemeUpdateFlight($this->root, 11, $alive);

        $first = $holder->arrive('theme-3');
        self::assertTrue($holder->tryHold('theme-3'));
        $second = $later->arrive('theme-3');

        self::assertGreaterThan($first, $second);
        self::assertFalse($holder->isLatest('theme-3', $first));
        self::assertFalse($later->tryHold('theme-3'));

        $holder->release('theme-3');
        self::assertTrue($later->tryHold('theme-3'));
    }

    public function testDeadHolderCanBeReplaced(): void
    {
        $dead = new ThemeUpdateFlight($this->root, 10, static fn (int $pid): bool => false);
        $dead->arrive('theme-3');
        self::assertTrue($dead->tryHold('theme-3'));

        $live = new ThemeUpdateFlight($this->root, 11, static fn (int $pid): bool => $pid === 11);
        $live->arrive('theme-3');
        self::assertTrue($live->tryHold('theme-3'));
    }

    public function testQuietRunCommitsOnceAndReleases(): void
    {
        $flight = new ThemeUpdateFlight($this->root, 10, static fn (int $pid): bool => $pid === 10);
        $runner = new ThemeUpdateFlightRunner($flight);
        $calls = 0;
        $result = $runner->execute('theme-3', function (string $batchId) use (&$calls): array {
            $calls++;
            self::assertNotSame('', $batchId);

            return ['built' => true];
        });

        self::assertSame(1, $calls);
        self::assertTrue($result['built']);

        $other = new ThemeUpdateFlight($this->root, 11, static fn (int $pid): bool => $pid === 11 || $pid === 10);
        self::assertTrue($other->tryHold('theme-3'));
    }

    public function testBacklogStaysOneVersionUntilCurrentStepFinishes(): void
    {
        $alive = static fn (int $pid): bool => $pid === 10 || $pid === 11;
        $flight = new ThemeUpdateFlight($this->root, 10, $alive);
        $runner = new ThemeUpdateFlightRunner($flight);
        $tails = [];
        $active = '';
        $first = $runner->execute('theme-3', function (string $batchId) use (&$tails, &$active, $flight): array {
            $active = $batchId;
            for ($i = 0; $i < 100; $i++) {
                $tails[] = $flight->ensureTail('theme-3');
            }

            return ['batch_id' => $batchId];
        });

        self::assertSame($active, $first['batch_id'] ?? '');
        self::assertCount(1, \array_unique($tails));
        $tail = $tails[0];
        self::assertNotSame($active, $tail);
        self::assertSame($active, $flight->activeBatchId('theme-3'));
        self::assertSame($tail, $flight->tailBatchId('theme-3'));

        $waiter = new ThemeUpdateFlightRunner(new ThemeUpdateFlight($this->root, 11, $alive));
        self::assertTrue($flight->tryHold('theme-3'));
        for ($i = 0; $i < 100; $i++) {
            $result = $waiter->execute('theme-3', function (): array {
                self::fail('未处理完的版本不能再开一份构建');
            });
            self::assertSame('coalesced', $result['skipped'] ?? '');
            self::assertSame($tail, $result['batch_id'] ?? '');
        }
        $flight->release('theme-3');
        self::assertSame($tail, $flight->tailBatchId('theme-3'));

        $flight->finishProcessing('theme-3');
        self::assertSame('', $flight->activeBatchId('theme-3'));
        self::assertSame($tail, $flight->tailBatchId('theme-3'));

        $newer = '';
        $second = $runner->execute('theme-3', function (string $batchId) use ($tail, $flight, &$newer): array {
            self::assertSame($tail, $batchId);
            $newer = $flight->ensureTail('theme-3');
            self::assertNotSame($tail, $newer);

            return ['batch_id' => $batchId];
        });

        self::assertSame($tail, $second['batch_id'] ?? '');
        self::assertSame($newer, $flight->tailBatchId('theme-3'));
        self::assertSame($newer, $flight->ensureTail('theme-3'));
    }

    public function testOverlappingRunnerDoesNotStartASecondBuild(): void
    {
        $alive = static fn (int $pid): bool => $pid === 10 || $pid === 11;
        $holder = new ThemeUpdateFlight($this->root, 10, $alive);
        $holder->arrive('origin-watch');
        self::assertTrue($holder->tryHold('origin-watch'));

        $calls = 0;
        $runner = new ThemeUpdateFlightRunner(new ThemeUpdateFlight($this->root, 11, $alive));
        $result = $runner->execute('origin-watch', function (): array {
            self::fail('占用中不能再开一份');
        });

        self::assertSame(0, $calls);
        self::assertSame('coalesced', $result['skipped'] ?? '');
        self::assertSame('queued', $holder->tailBatchId('origin-watch'));
        self::assertSame('queued', $runner->execute('origin-watch', static fn (): array => [])['batch_id'] ?? '');
    }
}
