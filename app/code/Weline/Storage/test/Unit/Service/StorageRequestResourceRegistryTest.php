<?php

declare(strict_types=1);

namespace Weline\Storage\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestResourceInterface;
use Weline\Storage\Api\Data\StorageObjectReference;
use Weline\Storage\Api\Data\StorageObjectStat;
use Weline\Storage\Api\StorageReadHandle;
use Weline\Storage\Api\StorageWriteHandle;
use Weline\Storage\Service\StorageRequestResourceRegistry;

final class StorageRequestResourceRegistryTest extends TestCase
{
    public function testReadHandleIsExplicitlyClosedAndReleased(): void
    {
        $registry = new StorageRequestResourceRegistry();
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'streamed');
        rewind($stream);

        $handle = new StorageReadHandle($stream, $registry);
        self::assertSame(1, $registry->activeCount());
        self::assertSame('streamed', $handle->read(64));
        $handle->close();

        self::assertTrue($handle->isClosed());
        self::assertSame(0, $registry->activeCount());
    }

    public function testRequestCleanupAbortsIncompleteWriteInsteadOfCompletingIt(): void
    {
        $registry = new StorageRequestResourceRegistry();
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        $completed = 0;
        $aborted = 0;
        $handle = new StorageWriteHandle(
            $stream,
            static function () use (&$completed): StorageObjectStat {
                ++$completed;
                return new StorageObjectStat(
                    new StorageObjectReference('local::filesystem::media', 'incomplete.bin'),
                    0,
                );
            },
            static function () use (&$aborted): void {
                ++$aborted;
            },
            $registry,
        );
        $handle->write('partial');

        $registry->closeAll();

        self::assertTrue($handle->isClosed());
        self::assertTrue($handle->wasAborted());
        self::assertSame(0, $completed);
        self::assertSame(1, $aborted);
        self::assertSame(0, $registry->activeCount());
    }

    public function testCleanupContinuesAfterOneResourceFailsAndReportsAggregateDebt(): void
    {
        $registry = new StorageRequestResourceRegistry();
        $closed = [];
        $resource = static function (string $name, bool $fail = false) use (&$closed): RequestResourceInterface {
            return new class($name, $fail, $closed) implements RequestResourceInterface {
                private bool $closed = false;
                /** @var list<string> */
                private array $closedLog;

                /** @param list<string> $closed */
                public function __construct(
                    private readonly string $name,
                    private readonly bool $fail,
                    array &$closed,
                ) {
                    $this->closedLog =& $closed;
                }

                public function resourceKind(): string
                {
                    return 'storage.test';
                }

                public function close(): void
                {
                    if ($this->closed) {
                        return;
                    }
                    $this->closed = true;
                    $this->closedLog[] = $this->name;
                    if ($this->fail) {
                        throw new \RuntimeException('synthetic_cleanup_failure');
                    }
                }

                public function isClosed(): bool
                {
                    return $this->closed;
                }
            };
        };
        $first = $resource('first');
        $failing = $resource('failing', true);
        $last = $resource('last');
        $registry->register($first);
        $registry->register($failing);
        $registry->register($last);

        try {
            $registry->closeAll();
            self::fail('Cleanup debt must fail the request reset boundary.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('1', $exception->getMessage());
        }

        self::assertSame(['last', 'failing', 'first'], $closed);
        self::assertSame(0, $registry->activeCount());
        self::assertTrue($first->isClosed());
        self::assertTrue($failing->isClosed());
        self::assertTrue($last->isClosed());
    }
}
