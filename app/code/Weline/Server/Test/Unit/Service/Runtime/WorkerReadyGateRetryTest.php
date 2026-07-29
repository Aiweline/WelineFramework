<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Exception\DatabaseRetryTimeoutException;
use Weline\Server\Service\Runtime\WorkerReadyGateRetry;

final class WorkerReadyGateRetryTest extends TestCase
{
    public function testRetriesStructuredDatabaseContentionAndReturnsResult(): void
    {
        $attempts = 0;
        $delays = [];
        $result = WorkerReadyGateRetry::run(
            static function () use (&$attempts): string {
                $attempts++;
                if ($attempts < 3) {
                    throw new DatabaseRetryTimeoutException(
                        'sqlite',
                        'cooperative_wait_unavailable',
                        1,
                        150,
                        0.1,
                        false,
                    );
                }
                return 'ready';
            },
            3,
            null,
            static function (int $delay) use (&$delays): void {
                $delays[] = $delay;
            },
        );

        self::assertSame('ready', $result);
        self::assertSame(3, $attempts);
        self::assertSame([130_000, 230_000], $delays);
    }

    public function testStructuralFailureIsNotRetried(): void
    {
        $attempts = 0;

        try {
            WorkerReadyGateRetry::run(
                static function () use (&$attempts): never {
                    $attempts++;
                    throw new \LogicException('invalid warmup contract');
                },
                1,
                null,
                static function (): void {
                    self::fail('Structural failures must not wait or retry.');
                },
            );
            self::fail('Expected structural warmup failure.');
        } catch (\LogicException $exception) {
            self::assertSame('invalid warmup contract', $exception->getMessage());
        }

        self::assertSame(1, $attempts);
    }
}
