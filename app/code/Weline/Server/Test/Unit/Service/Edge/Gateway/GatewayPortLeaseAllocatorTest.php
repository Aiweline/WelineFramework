<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;
use Weline\Server\Service\Runtime\DirectSharedListener;
use Weline\Framework\System\Process\Processer;

final class GatewayPortLeaseAllocatorTest extends TestCase
{
    private string $root = '';
    private string $masterProcessName = '';
    private string $originalProcessTitle = '';

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
        $this->masterProcessName = 'wls-port-lease-ut-' . \bin2hex(\random_bytes(4));
        if (\function_exists('cli_get_process_title')) {
            $this->originalProcessTitle = (string)@\cli_get_process_title();
        }
        if (\function_exists('cli_set_process_title')) {
            @\cli_set_process_title($this->masterProcessName);
        }
        Processer::setPid('--name=' . $this->masterProcessName, \getmypid());
    }

    protected function tearDown(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->close();
        }
        Processer::removePidFile('--name=' . $this->masterProcessName);
        if ($this->originalProcessTitle !== '' && \function_exists('cli_set_process_title')) {
            @\cli_set_process_title($this->originalProcessTitle);
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
            'site-gateway-fallback',
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
            'site-gateway-fallback',
            static function (int $port) use ($secondListener): bool {
                $secondListener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        self::assertNotSame($firstLease['port'], $secondLease['port']);

        $launchId = \str_repeat('a', 32);
        $masterLaunchId = \str_repeat('c', 32);
        $first->prepareTransfer(
            'site-gateway-fallback',
            (string)$firstLease['lease_id'],
            '127.0.0.1',
            (int)$firstLease['port'],
            $masterLaunchId,
        );
        $confirmed = $first->confirmTransferred(
            'site-gateway-fallback',
            (int)$firstLease['port'],
            \getmypid(),
            $launchId,
            (string)$firstLease['lease_id'],
            '127.0.0.1',
            $this->masterProcessName,
            $masterLaunchId,
        );
        self::assertSame('ACTIVE', $confirmed['state']);
        self::assertSame(\getmypid(), $confirmed['worker_pid']);
        self::assertSame($launchId, $confirmed['launch_id']);
        self::assertSame(5, $confirmed['schema_version']);

        $draining = $first->markDraining(
            'site-gateway-fallback',
            (int)$firstLease['port'],
            (string)$firstLease['lease_id'],
        );
        self::assertSame('DRAINING', $draining['state']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)$draining['draining_host_boot_id'],
        );
        self::assertIsFloat($draining['draining_monotonic']);
        self::assertGreaterThan(0.0, $draining['draining_monotonic']);
        self::assertSame('DRAINING', $first->status('site-gateway-fallback')['state'] ?? null);
        $firstListener->close();
        $first->release(
            'site-gateway-fallback',
            (int)$firstLease['port'],
            (string)$firstLease['lease_id'],
        );
        $first->release(
            'site-gateway-fallback',
            (int)$firstLease['port'],
            (string)$firstLease['lease_id'],
        );
        $released = $first->status('site-gateway-fallback');
        self::assertSame('RELEASED', $released['state'] ?? null);
        self::assertSame(0, $released['worker_pid'] ?? null);
        self::assertSame([], $released['workers'] ?? null);

        $secondListener->close();
        $second->cancelReservation(
            'site-gateway-fallback',
            (int)$secondLease['port'],
            (string)$secondLease['lease_id'],
        );
        self::assertSame('RELEASED', $second->status('site-gateway-fallback')['state'] ?? null);
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
            'site-gateway-fallback',
            static function (int $port) use ($listener): bool {
                $listener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $masterLaunchId = \str_repeat('c', 32);
        $allocator->prepareTransfer(
            'site-gateway-fallback',
            (string)$lease['lease_id'],
            '127.0.0.1',
            (int)$lease['port'],
            $masterLaunchId,
        );
        $allocator->confirmTransferred(
            'site-gateway-fallback',
            (int)$lease['port'],
            \getmypid(),
            \str_repeat('b', 32),
            (string)$lease['lease_id'],
            '127.0.0.1',
            $this->masterProcessName,
            $masterLaunchId,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already owns a live port lease');
        $allocator->reserveBound('site-gateway-fallback', static fn (int $port): bool => true);
    }

    public function testDrainingObservationQuarantinesUncomparableMonotonicEvidence(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Master-owned inherited listener test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $hostBootId = \str_repeat('c', 64);
        $allocator = $this->allocator(
            'draining-observation',
            $hostState,
            $leaseDirectory,
            $hostBootId,
            static fn (): float => 1000.0,
        );
        $listener = $this->listener();
        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($listener): bool {
                $listener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $masterLaunchId = \str_repeat('e', 32);
        $workerLaunchId = \str_repeat('f', 32);
        $allocator->prepareTransfer(
            'site-gateway-fallback',
            (string)$lease['lease_id'],
            '127.0.0.1',
            (int)$lease['port'],
            $masterLaunchId,
        );
        $allocator->confirmTransferred(
            'site-gateway-fallback',
            (int)$lease['port'],
            \getmypid(),
            $workerLaunchId,
            (string)$lease['lease_id'],
            '127.0.0.1',
            $this->masterProcessName,
            $masterLaunchId,
        );
        $allocator->markDraining(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
        );

        $leaseFile = $leaseDirectory . DIRECTORY_SEPARATOR
            . \substr(\hash(
                'sha256',
                (string)$lease['project_uuid'] . ':site-gateway-fallback',
            ), 0, 24) . '.json';
        $encoded = \file_get_contents($leaseFile);
        self::assertIsString($encoded);
        $durable = \json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($durable);

        $trusted = $allocator->status('site-gateway-fallback');
        self::assertIsArray($trusted);
        self::assertTrue($trusted['draining_time_trusted'] ?? false);
        self::assertSame(1000.0, $trusted['draining_monotonic'] ?? null);
        $publishValidator = new \ReflectionMethod($allocator, 'assertValidLease');

        foreach ([
            'future' => 1001.0,
            'non-finite' => '1e309',
            'malformed' => ['unexpected'],
        ] as $case => $untrustedMonotonic) {
            $corruptedTime = $durable;
            $corruptedTime['draining_monotonic'] = $untrustedMonotonic;
            try {
                $publishValidator->invoke($allocator, $corruptedTime);
                self::fail('Untrusted drain time was accepted for publication: ' . $case);
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'current-boot monotonic fence',
                    $exception->getMessage(),
                    $case,
                );
            }
            $this->writeLeaseFixture($leaseFile, $corruptedTime);

            $observed = $allocator->status('site-gateway-fallback');
            self::assertIsArray($observed, $case);
            self::assertSame('DRAINING', $observed['state'] ?? null, $case);
            self::assertFalse($observed['draining_time_trusted'] ?? true, $case);
            self::assertNull($observed['draining_monotonic'] ?? null, $case);
            self::assertSame($lease['project_uuid'], $observed['project_uuid'] ?? null, $case);
            self::assertSame($lease['lease_id'], $observed['lease_id'] ?? null, $case);
            self::assertSame($lease['port'], $observed['port'] ?? null, $case);
            self::assertSame(\getmypid(), $observed['master_pid'] ?? null, $case);
            self::assertIsArray($allocator->liveServingLease(
                'site-gateway-fallback',
                '127.0.0.1',
                (int)$lease['port'],
                (string)$lease['lease_id'],
                $workerLaunchId,
                \getmypid(),
            ), $case);
        }

        $crossBoot = $durable;
        $crossBoot['host_boot_id'] = \str_repeat('d', 64);
        $crossBoot['draining_host_boot_id'] = \str_repeat('d', 64);
        $crossBoot['draining_monotonic'] = 100.0;
        try {
            $publishValidator->invoke($allocator, $crossBoot);
            self::fail('Cross-boot drain time was accepted for publication.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'monotonic host-boot fence',
                $exception->getMessage(),
            );
        }
        $this->writeLeaseFixture($leaseFile, $crossBoot);
        $observed = $allocator->status('site-gateway-fallback');
        self::assertIsArray($observed);
        self::assertSame('DRAINING', $observed['state'] ?? null);
        self::assertFalse($observed['draining_time_trusted'] ?? true);
        self::assertNull($observed['draining_monotonic'] ?? null);
        self::assertSame($lease['port'], $observed['port'] ?? null);
        self::assertIsArray($allocator->liveServingLease(
            'site-gateway-fallback',
            '127.0.0.1',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            \getmypid(),
        ));
    }

    public function testPidReuseWithDifferentBirthIdentityIsRejected(): void
    {
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $allocator = $this->allocator(
            'pid-reuse',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
        );
        $method = new \ReflectionMethod($allocator, 'processMatchesBirth');
        self::assertFalse($method->invoke(
            $allocator,
            \getmypid(),
            \str_repeat('0', 64),
        ));
    }

    public function testIpv6LoopbackLeaseRetainsExactBindFamily(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Inherited dual-stack listener test is POSIX-only.');
        }
        $probe = @\stream_socket_server('tcp://[::1]:0', $errno, $error);
        if (!\is_resource($probe)) {
            self::markTestSkipped('IPv6 loopback is unavailable: ' . $error);
        }
        @\fclose($probe);
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $allocator = $this->allocator(
            'ipv6',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
        );
        $listener = $this->listener();
        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($listener): bool {
                $listener->acquire('::1', $port);
                return true;
            },
            '::1',
        );
        self::assertSame('::1', $lease['bind_host']);
        self::assertTrue($listener->matches('::1', (int)$lease['port']));
    }

    public function testBinderExceptionDoesNotLeakCandidateReservation(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Inherited listener exception test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $allocator = $this->allocator(
            'binder-exception',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
        );
        $listener = $this->listener();
        $attempts = 0;
        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($listener, &$attempts): bool {
                $attempts++;
                if ($attempts === 1) {
                    $temporary = @\stream_socket_server(
                        'tcp://127.0.0.1:' . $port,
                        $errno,
                        $error,
                    );
                    if (\is_resource($temporary)) {
                        @\fclose($temporary);
                    }
                    throw new \RuntimeException('synthetic binder failure');
                }
                $listener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        self::assertGreaterThanOrEqual(2, $attempts);
        self::assertTrue($listener->matches('127.0.0.1', (int)$lease['port']));
    }

    public function testRetainedReservationReentryReturnsTheExactLease(): void
    {
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $allocator = $this->allocator(
            'retained-reentry',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
        );
        $binds = 0;
        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use (&$binds): mixed {
                $binds++;
                return @\stream_socket_server(
                    'tcp://127.0.0.1:' . $port,
                    $errno,
                    $error,
                );
            },
            '127.0.0.1',
            true,
        );

        $same = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use (&$binds): bool {
                $binds++;
                return false;
            },
            '127.0.0.1',
            true,
        );

        self::assertSame($lease['lease_id'], $same['lease_id']);
        self::assertSame($lease['port'], $same['port']);
        self::assertSame(1, $binds);
        $socket = $allocator->takeRetainedBoundSocket((string)$lease['lease_id']);
        self::assertIsResource($socket);
        @\fclose($socket);
    }

    public function testExternalReservationReentryUsesBinderOwnershipProof(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Direct shared listener fixture is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $allocator = $this->allocator(
            'external-reentry',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
        );
        $listener = $this->listener();
        $binds = 0;
        $binder = static function (int $port) use ($listener, &$binds): bool {
            $binds++;
            $listener->acquire('127.0.0.1', $port);
            return true;
        };
        $lease = $allocator->reserveBound('site-gateway-fallback', $binder);
        $same = $allocator->reserveBound('site-gateway-fallback', $binder);

        self::assertSame($lease['lease_id'], $same['lease_id']);
        self::assertSame($lease['port'], $same['port']);
        self::assertSame(2, $binds);
        self::assertTrue($listener->matches('127.0.0.1', (int)$lease['port']));
    }

    private function allocator(
        string $name,
        string $hostState,
        string $leaseDirectory,
        ?string $hostBootId = null,
        ?\Closure $monotonicClock = null,
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
        return new GatewayPortLeaseAllocator(
            $identity,
            $leaseDirectory,
            $hostBootId,
            $monotonicClock,
        );
    }

    /** @param array<string,mixed> $lease */
    private function writeLeaseFixture(string $file, array $lease): void
    {
        $encoded = \json_encode(
            $lease,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        self::assertIsInt(\file_put_contents($file, $encoded, LOCK_EX));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($file, 0600));
        }
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
