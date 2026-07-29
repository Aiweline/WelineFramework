<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;
use Weline\Server\Service\Runtime\DirectSharedListener;

final class GatewayPortLeaseAllocatorTest extends TestCase
{
    private string $root = '';

    /** @var list<DirectSharedListener> */
    private array $listeners = [];

    protected function setUp(): void
    {
        $base = \PHP_OS_FAMILY === 'Darwin' ? '/tmp' : \sys_get_temp_dir();
        $path = $base . DIRECTORY_SEPARATOR . 'wls-port-lease-'
            . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($path, 0700, true));
        $canonical = \realpath($path);
        self::assertIsString($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->close();
        }
        $this->removeTree($this->root);
    }

    public function testHostLockKeepsProjectsDistinctAndReadyConfirmsLease(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Master-owned inherited listener test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $first = $this->allocator('first', $hostState, $leaseDirectory);
        $second = $this->allocator('second', $hostState, $leaseDirectory);
        $firstListener = $this->listener();
        $secondListener = $this->listener();

        $firstLease = $first->reserveBound(
            'site:gateway-fallback',
            static function (int $port) use ($firstListener): bool {
                $firstListener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        self::assertSame('RESERVED', $firstLease['state']);
        self::assertGreaterThanOrEqual(20000, $firstLease['port']);
        self::assertLessThanOrEqual(29999, $firstLease['port']);
        self::assertTrue($firstListener->matches('127.0.0.1', (int)$firstLease['port']));
        self::assertArrayHasKey(DirectSharedListener::INHERITED_FD, $firstListener->descriptorMap());

        $secondLease = $second->reserveBound(
            'site:gateway-fallback',
            static function (int $port) use ($secondListener): bool {
                $secondListener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        self::assertNotSame($firstLease['port'], $secondLease['port']);

        $confirmed = $first->confirm(
            'site:gateway-fallback',
            (int)$firstLease['port'],
            \getmypid(),
            'launch-one',
        );
        self::assertSame('ACTIVE', $confirmed['state']);
        self::assertSame(\getmypid(), $confirmed['worker_pid']);
        self::assertSame('launch-one', $confirmed['launch_id']);
        self::assertSame(
            $confirmed,
            $first->confirm(
                'site:gateway-fallback',
                (int)$firstLease['port'],
                \getmypid(),
                'launch-one',
            ),
        );
        $pooled = $first->confirm(
            'site:gateway-fallback',
            (int)$firstLease['port'],
            \getmypid(),
            'launch-two',
        );
        self::assertCount(2, $pooled['workers']);
        self::assertSame(
            ['launch-one', 'launch-two'],
            \array_column($pooled['workers'], 'launch_id'),
        );

        $draining = $first->markDraining(
            'site:gateway-fallback',
            (int)$firstLease['port'],
        );
        self::assertSame('DRAINING', $draining['state']);
        self::assertSame('DRAINING', $first->status('site:gateway-fallback')['state'] ?? null);
        $first->release('site:gateway-fallback', (int)$firstLease['port']);
        $first->release('site:gateway-fallback', (int)$firstLease['port']);
        $released = $first->status('site:gateway-fallback');
        self::assertSame('RELEASED', $released['state'] ?? null);
        self::assertSame(0, $released['worker_pid'] ?? null);
        self::assertSame([], $released['workers'] ?? null);

        $second->cancelReservation(
            'site:gateway-fallback',
            (int)$secondLease['port'],
        );
        self::assertSame('RELEASED', $second->status('site:gateway-fallback')['state'] ?? null);
    }

    public function testActiveLiveLeaseCannotBeReallocatedBySameIdentity(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Master-owned inherited listener test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $allocator = $this->allocator(
            'single',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
        );
        $listener = $this->listener();
        $lease = $allocator->reserveBound(
            'site:gateway-fallback',
            static function (int $port) use ($listener): bool {
                $listener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $allocator->confirm(
            'site:gateway-fallback',
            (int)$lease['port'],
            \getmypid(),
            'launch-live',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already owns a live port lease');
        $allocator->reserveBound('site:gateway-fallback', static fn (int $port): bool => true);
    }

    private function allocator(
        string $name,
        string $hostState,
        string $leaseDirectory,
    ): GatewayPortLeaseAllocator {
        $project = $this->root . DIRECTORY_SEPARATOR . $name;
        self::assertTrue(\mkdir(
            $project . DIRECTORY_SEPARATOR . 'app/etc',
            0700,
            true,
        ));
        $identity = new ProjectIdentityStore(
            $project,
            $hostState,
            $this->root . DIRECTORY_SEPARATOR . 'missing-legacy.json',
        );
        return new GatewayPortLeaseAllocator($identity, $leaseDirectory);
    }

    private function listener(): DirectSharedListener
    {
        $listener = new DirectSharedListener();
        $this->listeners[] = $listener;
        return $listener;
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
