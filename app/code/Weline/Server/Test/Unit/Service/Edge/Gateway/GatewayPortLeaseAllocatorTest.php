<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Edge\Gateway\GatewayPortLeaseAllocator;
use Weline\Server\Service\Edge\Gateway\ProjectIdentityStore;
use Weline\Server\Service\Runtime\DirectSharedListener;
use Weline\Server\Service\MasterLeaseRuntimeIdentity;
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
        $identity = $this->processIdentity();
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
            $identity['birth'],
            $identity['pid_namespace_id'],
        );
        self::assertSame('ACTIVE', $confirmed['state']);
        self::assertSame(\getmypid(), $confirmed['worker_pid']);
        self::assertSame($launchId, $confirmed['launch_id']);
        self::assertSame(GatewayPortLeaseAllocator::SCHEMA_VERSION, $confirmed['schema_version']);

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
        $identity = $this->processIdentity();
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
            $identity['birth'],
            $identity['pid_namespace_id'],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already owns a live port lease');
        $allocator->reserveBound('site-gateway-fallback', static fn (int $port): bool => true);
    }

    public function testFailedDrainCanCasTheExactLiveLeaseBackToActive(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Master-owned inherited listener test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $allocator = $this->allocator(
            'drain-rollback',
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
        $workerLaunchId = \str_repeat('d', 32);
        $identity = $this->processIdentity();
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
            $identity['birth'],
            $identity['pid_namespace_id'],
        );
        $activeLease = $allocator->status('site-gateway-fallback');
        self::assertIsArray($activeLease);
        $transitionIdentity = $this->transitionIdentity(
            $activeLease,
            $workerLaunchId,
            $masterLaunchId,
            $identity,
        );
        $transitionId = \str_repeat('e', 32);
        $actionDigest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $transitionIdentity,
        );
        $pending = $allocator->beginDrain(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            $transitionId,
            $actionDigest,
            $transitionIdentity,
        );
        self::assertSame('DRAINING', $pending['state']);
        self::assertFalse($pending['drain_acknowledged']);
        self::assertSame($transitionId, $pending['drain_transition_id']);
        self::assertSame(
            GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_PREPARED,
            $pending['listener_phase'],
        );
        self::assertNull($pending['draining_monotonic']);

        $active = $allocator->restoreActiveAfterFailedDrain(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            $transitionId,
            $actionDigest,
            $transitionIdentity,
        );

        self::assertSame('ACTIVE', $active['state']);
        self::assertNull($active['draining_at']);
        self::assertNull($active['draining_timestamp']);
        self::assertNull($active['draining_host_boot_id']);
        self::assertNull($active['draining_monotonic']);
        self::assertTrue($listener->matches('127.0.0.1', (int)$lease['port']));
        self::assertSame('ACTIVE', $allocator->status('site-gateway-fallback')['state'] ?? null);
    }

    public function testDrainClockStartsOnlyAfterExactChildAcknowledgement(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Master-owned inherited listener test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $clock = 100.0;
        $allocator = $this->allocator(
            'drain-ack',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
            null,
            static function () use (&$clock): float {
                return $clock;
            },
        );
        $listener = $this->listener();
        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($listener): bool {
                $listener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $masterLaunchId = \str_repeat('a', 32);
        $workerLaunchId = \str_repeat('b', 32);
        $transitionId = \str_repeat('c', 32);
        $identity = $this->processIdentity();
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
            $identity['birth'],
            $identity['pid_namespace_id'],
        );

        $activeLease = $allocator->status('site-gateway-fallback');
        self::assertIsArray($activeLease);
        $transitionIdentity = $this->transitionIdentity(
            $activeLease,
            $workerLaunchId,
            $masterLaunchId,
            $identity,
        );
        $drainDigest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $transitionIdentity,
        );

        $pending = $allocator->beginDrain(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            $transitionId,
            $drainDigest,
            $transitionIdentity,
        );
        self::assertFalse($pending['drain_acknowledged']);
        self::assertNull($pending['draining_monotonic']);

        $clock = 130.0;
        $acknowledged = $allocator->acknowledgeDrain(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            $transitionId,
            $drainDigest,
            $transitionIdentity,
        );
        self::assertTrue($acknowledged['drain_acknowledged']);
        self::assertSame(130.0, $acknowledged['draining_monotonic']);

        $clock = 140.0;
        $undrainDigest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_UNDRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE,
            $transitionId,
            $drainDigest,
            $transitionIdentity,
        );
        $allocator->prepareUndrain(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            $transitionId,
            $drainDigest,
            $undrainDigest,
            $transitionIdentity,
        );
        $active = $allocator->restoreActiveAfterUndrainAck(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            $transitionId,
            $undrainDigest,
            $transitionIdentity,
        );
        self::assertSame('ACTIVE', $active['state']);
        self::assertFalse($active['drain_acknowledged']);
        self::assertNull($active['drain_transition_id']);
    }

    public function testTransitionCallerCanReconcileCommittedAfterImageAfterPublicationThrows(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Master-owned inherited listener test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $injectPhase = '';
        $allocator = $this->allocator(
            'after-image-transition',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
            null,
            null,
            static function (string $_file, array $afterImage) use (&$injectPhase): void {
                if ($injectPhase !== ''
                    && \hash_equals(
                        $injectPhase,
                        (string)($afterImage['listener_phase'] ?? ''),
                    )
                ) {
                    $injectPhase = '';
                    throw new \RuntimeException('injected after-image publication failure');
                }
            },
        );
        $listener = $this->listener();
        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($listener): bool {
                $listener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $masterLaunchId = \str_repeat('4', 32);
        $workerLaunchId = \str_repeat('5', 32);
        $processIdentity = $this->processIdentity();
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
            $processIdentity['birth'],
            $processIdentity['pid_namespace_id'],
        );
        $active = $allocator->status('site-gateway-fallback');
        self::assertIsArray($active);
        $identity = $this->transitionIdentity(
            $active,
            $workerLaunchId,
            $masterLaunchId,
            $processIdentity,
        );
        $transitionId = \str_repeat('6', 32);
        $drainDigest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $identity,
        );
        $allocator->beginDrain(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            $transitionId,
            $drainDigest,
            $identity,
        );

        $injectPhase = GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_ACKED;
        try {
            $allocator->acknowledgeDrain(
                'site-gateway-fallback',
                (int)$lease['port'],
                (string)$lease['lease_id'],
                $workerLaunchId,
                $transitionId,
                $drainDigest,
                $identity,
            );
            self::fail('The committed-after-image fault was not injected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('after-image', $exception->getMessage());
        }
        $drainAfterImage = $allocator->status('site-gateway-fallback');
        self::assertIsArray($drainAfterImage);
        self::assertSame(
            GatewayPortLeaseAllocator::LISTENER_PHASE_DRAIN_ACKED,
            $drainAfterImage['listener_phase'],
        );

        $undrainDigest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_UNDRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE,
            $transitionId,
            $drainDigest,
            $identity,
        );
        $allocator->prepareUndrain(
            'site-gateway-fallback',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            $transitionId,
            $drainDigest,
            $undrainDigest,
            $identity,
        );
        $injectPhase = GatewayPortLeaseAllocator::LISTENER_PHASE_ACTIVE;
        try {
            $allocator->restoreActiveAfterUndrainAck(
                'site-gateway-fallback',
                (int)$lease['port'],
                (string)$lease['lease_id'],
                $workerLaunchId,
                $transitionId,
                $undrainDigest,
                $identity,
            );
            self::fail('The ACTIVE committed-after-image fault was not injected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('after-image', $exception->getMessage());
        }
        $activeAfterImage = $allocator->status('site-gateway-fallback');
        self::assertIsArray($activeAfterImage);
        self::assertSame('ACTIVE', $activeAfterImage['state']);
        self::assertSame(
            GatewayPortLeaseAllocator::LISTENER_PHASE_ACTIVE,
            $activeAfterImage['listener_phase'],
        );
    }

    public function testConfirmTransferredSucceedsAfterPreparerProcessExits(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Master-owned inherited listener test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $allocator = $this->allocator('handoff-after-cli', $hostState, $leaseDirectory);
        $listener = $this->listener();
        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($listener): bool {
                $listener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $masterLaunchId = \str_repeat('a', 32);
        $identity = $this->processIdentity();
        $allocator->prepareTransfer(
            'site-gateway-fallback',
            (string)$lease['lease_id'],
            '127.0.0.1',
            (int)$lease['port'],
            $masterLaunchId,
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
        // Simulate Start CLI exiting after spawning Master: preparer PID is gone.
        $deadPid = 2147483000;
        $durable['master_pid'] = $deadPid;
        $durable['transfer_intent']['prepared_pid'] = $deadPid;
        $this->writeLeaseFixture($leaseFile, $durable);

        $confirmed = $allocator->confirmTransferred(
            'site-gateway-fallback',
            (int)$lease['port'],
            \getmypid(),
            \str_repeat('b', 32),
            (string)$lease['lease_id'],
            '127.0.0.1',
            $this->masterProcessName,
            $masterLaunchId,
            $identity['birth'],
            $identity['pid_namespace_id'],
        );
        self::assertSame('ACTIVE', $confirmed['state']);
        self::assertSame(\getmypid(), $confirmed['master_pid']);
        self::assertArrayNotHasKey('transfer_intent', $confirmed);
        self::assertSame(\str_repeat('b', 32), $confirmed['launch_id']);
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
        $identity = $this->processIdentity();
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
            $identity['birth'],
            $identity['pid_namespace_id'],
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
        self::assertNull($allocator->liveServingLease(
            'site-gateway-fallback',
            '127.0.0.1',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $workerLaunchId,
            \getmypid(),
        ));
    }

    public function testAnyOwnerObservationFallsBackFromDeadLatestOwner(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Master-owned inherited listener test is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $allocator = $this->allocator(
            'multi-owner-observation',
            $hostState,
            $leaseDirectory,
        );
        $listener = $this->listener();
        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($listener): bool {
                $listener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $masterLaunchId = \str_repeat('8', 32);
        $liveLaunchId = \str_repeat('9', 32);
        $deadLaunchId = \str_repeat('a', 32);
        $identity = $this->processIdentity();
        $allocator->prepareTransfer(
            'site-gateway-fallback',
            (string)$lease['lease_id'],
            '127.0.0.1',
            (int)$lease['port'],
            $masterLaunchId,
        );
        $confirmed = $allocator->confirmTransferred(
            'site-gateway-fallback',
            (int)$lease['port'],
            \getmypid(),
            $liveLaunchId,
            (string)$lease['lease_id'],
            '127.0.0.1',
            $this->masterProcessName,
            $masterLaunchId,
            $identity['birth'],
            $identity['pid_namespace_id'],
        );
        $liveConfirmedTimestamp = (int)$confirmed['confirmed_timestamp'];
        $deadPid = 2_147_483_647;
        $deadConfirmedTimestamp = $liveConfirmedTimestamp + 1;
        $confirmed['worker_pid'] = $deadPid;
        $confirmed['launch_id'] = $deadLaunchId;
        $confirmed['confirmed_at'] = \gmdate(DATE_ATOM, $deadConfirmedTimestamp);
        $confirmed['confirmed_timestamp'] = $deadConfirmedTimestamp;
        $confirmed['workers'][] = [
            'pid' => $deadPid,
            'launch_id' => $deadLaunchId,
            'process_name' => $this->masterProcessName,
            'process_birth' => \str_repeat('b', 64),
            'pid_namespace_id' => $identity['pid_namespace_id'],
            'confirmed_at' => \gmdate(DATE_ATOM, $deadConfirmedTimestamp),
            'confirmed_timestamp' => $deadConfirmedTimestamp,
        ];
        $leaseFile = $leaseDirectory . DIRECTORY_SEPARATOR
            . \substr(\hash(
                'sha256',
                (string)$lease['project_uuid'] . ':site-gateway-fallback',
            ), 0, 24) . '.json';
        $this->writeLeaseFixture($leaseFile, $confirmed);

        $observed = $allocator->liveServingLeaseForAnyOwner(
            'site-gateway-fallback',
            '127.0.0.1',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            \getmypid(),
        );

        self::assertIsArray($observed);
        self::assertSame(\getmypid(), $observed['worker_pid'] ?? null);
        self::assertSame($liveLaunchId, $observed['launch_id'] ?? null);
        self::assertSame(
            $liveConfirmedTimestamp,
            $observed['confirmed_timestamp'] ?? null,
        );
        self::assertNull($allocator->liveServingLease(
            'site-gateway-fallback',
            '127.0.0.1',
            (int)$lease['port'],
            (string)$lease['lease_id'],
            $deadLaunchId,
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
        $identity = $this->processIdentity();
        self::assertFalse($method->invoke(
            $allocator,
            \getmypid(),
            \str_repeat('0', 64),
            $identity['pid_namespace_id'],
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

    public function testResourceBinderMustOwnTheExactDeclaredEndpoint(): void
    {
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $allocator = $this->allocator(
            'resource-endpoint-proof',
            $hostState,
            $hostState . DIRECTORY_SEPARATOR . 'fallback-leases',
        );
        $bindability = new \ReflectionMethod($allocator, 'numericPortIsBindable');
        $exactPort = 0;
        for ($candidate = 20000; $candidate <= 29999; ++$candidate) {
            if ($bindability->invoke($allocator, $candidate) === true) {
                $exactPort = $candidate;
                break;
            }
        }
        self::assertGreaterThanOrEqual(20000, $exactPort);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'The explicitly requested WLS port could not be reserved.',
        );
        $allocator->reserveBound(
            'site-gateway-fallback',
            static function (int $port): mixed {
                return @\stream_socket_server(
                    'tcp://127.0.0.1:0',
                    $errno,
                    $error,
                    \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
                );
            },
            '127.0.0.1',
            true,
            $exactPort,
        );
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

    public function testCrashOrphanedAtomicLeaseCandidateIsRecoveredUnderAllocationLock(): void
    {
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $allocator = $this->allocator(
            'atomic-orphan-recovery',
            $hostState,
            $leaseDirectory,
        );
        self::assertTrue(@\mkdir($leaseDirectory, 0700, true));
        $orphan = $leaseDirectory . DIRECTORY_SEPARATOR
            . \str_repeat('a', 24) . '.json.tmp-' . \str_repeat('b', 24);
        self::assertNotFalse(\file_put_contents($orphan, '{"partial":true}'));
        @\chmod($orphan, 0600);

        $lease = $allocator->reserveBound(
            'site-gateway-fallback',
            static fn (int $port): bool => $port >= 20000,
        );

        self::assertSame('RESERVED', $lease['state']);
        self::assertFileDoesNotExist($orphan);
    }

    public function testRetainedWindowsRecoveryBackupIsCollectedAfterTargetValidation(): void
    {
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $owner = $this->allocator('backup-owner', $hostState, $leaseDirectory);
        $ownerLease = $owner->reserveBound(
            'site-gateway-fallback',
            static fn (int $port): bool => $port >= 20000,
        );
        $ownerFile = $leaseDirectory . DIRECTORY_SEPARATOR
            . \substr(\hash(
                'sha256',
                (string)$ownerLease['project_uuid'] . ':site-gateway-fallback',
            ), 0, 24) . '.json';
        $backup = $ownerFile . '.wls-backup-' . \str_repeat('b', 16);
        self::assertTrue(\copy($ownerFile, $backup));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }

        $newcomer = $this->allocator('backup-newcomer', $hostState, $leaseDirectory);
        $newcomerLease = $newcomer->reserveBound(
            'site-gateway-fallback',
            static fn (int $port): bool => $port >= 20000,
        );

        self::assertSame('RESERVED', $newcomerLease['state']);
        self::assertNotSame($ownerLease['port'], $newcomerLease['port']);
        self::assertFileDoesNotExist($backup);
    }

    public function testWindowsRecoveryBackupIsPreservedWhenTargetIsCorrupt(): void
    {
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $allocator = $this->allocator('backup-corrupt', $hostState, $leaseDirectory);
        self::assertTrue(@\mkdir($leaseDirectory, 0700, true));
        $target = $leaseDirectory . DIRECTORY_SEPARATOR
            . \str_repeat('c', 24) . '.json';
        $backup = $target . '.wls-backup-' . \str_repeat('d', 16);
        self::assertNotFalse(\file_put_contents($target, '{"partial":true}'));
        self::assertNotFalse(\file_put_contents($backup, '{"previous":true}'));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($target, 0600));
            self::assertTrue(\chmod($backup, 0600));
        }

        try {
            $allocator->reserveBound(
                'site-gateway-fallback',
                static fn (int $port): bool => $port >= 20000,
            );
            self::fail('A recovery backup with a corrupt target was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'WLS fallback port lease',
                $exception->getMessage(),
            );
        }
        self::assertFileExists($backup);
    }

    public function testRecoveryBackupBatchRejectsLegacyTargetBeforeDeletingAnyEvidence(): void
    {
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $first = $this->allocator('backup-batch-first', $hostState, $leaseDirectory);
        $second = $this->allocator('backup-batch-second', $hostState, $leaseDirectory);
        $leases = [
            $first->reserveBound(
                'site-gateway-fallback',
                static fn (int $port): bool => $port >= 20000,
            ),
            $second->reserveBound(
                'site-gateway-fallback',
                static fn (int $port): bool => $port >= 20000,
            ),
        ];
        $backups = [];
        foreach ($leases as $index => $lease) {
            $target = $leaseDirectory . DIRECTORY_SEPARATOR
                . \substr(\hash(
                    'sha256',
                    (string)$lease['project_uuid'] . ':site-gateway-fallback',
                ), 0, 24) . '.json';
            $backup = $target . '.wls-backup-'
                . \str_repeat($index === 0 ? 'a' : 'b', 16);
            self::assertTrue(\copy($target, $backup));
            if (\PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(\chmod($backup, 0600));
            }
            $backups[$backup] = $target;
        }

        $orderedBackups = $this->recoveryBackupTraversalOrder($leaseDirectory);
        self::assertCount(2, $orderedBackups);
        $legacyTarget = $backups[$orderedBackups[1]] ?? null;
        self::assertIsString($legacyTarget);
        $encoded = @\file_get_contents($legacyTarget);
        self::assertIsString($encoded);
        $legacy = \json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($legacy);
        $legacy['schema_version'] = 5;
        $this->writeLeaseFixture($legacyTarget, $legacy);

        $newcomer = $this->allocator('backup-batch-newcomer', $hostState, $leaseDirectory);
        try {
            $newcomer->reserveBound(
                'site-gateway-fallback',
                static fn (int $port): bool => $port >= 20000,
            );
            self::fail('A retained recovery backup paired with a legacy lease was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('schema-6', $exception->getMessage());
        }

        foreach (\array_keys($backups) as $backup) {
            self::assertFileExists(
                $backup,
                'No recovery evidence may be deleted before every paired target is accepted.',
            );
        }
    }

    public function testRecoveryBackupBatchIsFullyBoundedBeforeDeletingAnyEvidence(): void
    {
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $first = $this->allocator('backup-size-first', $hostState, $leaseDirectory);
        $second = $this->allocator('backup-size-second', $hostState, $leaseDirectory);
        $leases = [
            $first->reserveBound(
                'site-gateway-fallback',
                static fn (int $port): bool => $port >= 20000,
            ),
            $second->reserveBound(
                'site-gateway-fallback',
                static fn (int $port): bool => $port >= 20000,
            ),
        ];
        $backups = [];
        foreach ($leases as $index => $lease) {
            $target = $leaseDirectory . DIRECTORY_SEPARATOR
                . \substr(\hash(
                    'sha256',
                    (string)$lease['project_uuid'] . ':site-gateway-fallback',
                ), 0, 24) . '.json';
            $backup = $target . '.wls-backup-'
                . \str_repeat($index === 0 ? 'c' : 'd', 16);
            self::assertTrue(\copy($target, $backup));
            if (\PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(\chmod($backup, 0600));
            }
            $backups[] = $backup;
        }

        $orderedBackups = $this->recoveryBackupTraversalOrder($leaseDirectory);
        self::assertCount(2, $orderedBackups);
        self::assertIsInt(\file_put_contents($orderedBackups[1], \str_repeat('x', 65_537)));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($orderedBackups[1], 0600));
        }

        $newcomer = $this->allocator('backup-size-newcomer', $hostState, $leaseDirectory);
        try {
            $newcomer->reserveBound(
                'site-gateway-fallback',
                static fn (int $port): bool => $port >= 20000,
            );
            self::fail('An oversized retained recovery backup was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('size limit', $exception->getMessage());
        }

        foreach ($backups as $backup) {
            self::assertFileExists(
                $backup,
                'Backup collection must not begin until every backup passes its fixed bounds.',
            );
        }
    }

    public function testCapacityIsReservedBeforeBindingAndAllowsOnlyAnOwnReplacement(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('The retained occupied-port capacity fixture is POSIX-only.');
        }
        $hostState = $this->root . DIRECTORY_SEPARATOR . 'host';
        $leaseDirectory = $hostState . DIRECTORY_SEPARATOR . 'fallback-leases';
        $owner = $this->allocator('capacity-owner', $hostState, $leaseDirectory);
        $occupiedListener = $this->listener();
        $seed = $owner->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($occupiedListener): bool {
                $occupiedListener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        $ownerFile = $leaseDirectory . DIRECTORY_SEPARATOR
            . \substr(\hash(
                'sha256',
                (string)$seed['project_uuid'] . ':site-gateway-fallback',
            ), 0, 24) . '.json';
        $encoded = @\file_get_contents($ownerFile);
        self::assertIsString($encoded);
        $legacyRetained = \json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($legacyRetained);
        $legacyRetained['schema_version'] = 5;
        $this->writeLeaseFixture($ownerFile, $legacyRetained);
        $encoded = \json_encode(
            $legacyRetained,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $leaseFiles = 1;
        for ($index = 0; $leaseFiles < 10000; ++$index) {
            $file = $leaseDirectory . DIRECTORY_SEPARATOR
                . \substr(\hash('sha256', 'capacity-fixture-' . $index), 0, 24)
                . '.json';
            if (\hash_equals($ownerFile, $file) || \is_file($file)) {
                continue;
            }
            if (@\file_put_contents($file, $encoded, LOCK_EX) === false) {
                self::fail('Unable to create the fixed-capacity lease fixture.');
            }
            ++$leaseFiles;
        }
        self::assertSame(10000, $this->leaseFileCount($leaseDirectory));

        $newcomer = $this->allocator('capacity-newcomer', $hostState, $leaseDirectory);
        $newcomerBinds = 0;
        $capacityException = null;
        $unexpectedLease = null;
        try {
            $unexpectedLease = $newcomer->reserveBound(
                'site-gateway-fallback',
                static function (int $port) use (&$newcomerBinds): bool {
                    unset($port);
                    ++$newcomerBinds;
                    return true;
                },
            );
        } catch (\RuntimeException $exception) {
            $capacityException = $exception;
        }
        self::assertNull($unexpectedLease, 'A new lease must not publish the 10001st retained entry.');
        self::assertInstanceOf(\RuntimeException::class, $capacityException);
        self::assertStringContainsString(
            'has no capacity for another retained lease',
            $capacityException->getMessage(),
        );
        self::assertSame(0, $newcomerBinds);
        self::assertSame(10000, $this->leaseFileCount($leaseDirectory));

        $replacementListener = $this->listener();
        $replacementBinds = 0;
        $replacement = $owner->reserveBound(
            'site-gateway-fallback',
            static function (int $port) use ($replacementListener, &$replacementBinds): bool {
                ++$replacementBinds;
                $replacementListener->acquire('127.0.0.1', $port);
                return true;
            },
        );
        self::assertSame(1, $replacementBinds);
        self::assertNotSame($seed['lease_id'], $replacement['lease_id']);
        self::assertNotSame($seed['port'], $replacement['port']);
        self::assertSame(10000, $this->leaseFileCount($leaseDirectory));

        $recoverableFile = $leaseDirectory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', 'legacy-overflow-recoverable'), 0, 24)
            . '.json';
        self::assertFileDoesNotExist($recoverableFile);
        $recoverable = $replacement;
        $recoverable['state'] = 'RELEASED';
        $recoverable['worker_pid'] = 0;
        $recoverable['launch_id'] = '';
        $recoverable['workers'] = [];
        $recoverable['released_at'] = \gmdate(DATE_ATOM);
        $recoverable['released_timestamp'] = \time();
        $recoverable['listener_phase'] = GatewayPortLeaseAllocator::LISTENER_PHASE_RELEASED;
        $recoverable['drain_transition_id'] = null;
        $recoverable['drain_acknowledged'] = false;
        $recoverable['draining_at'] = null;
        $recoverable['draining_timestamp'] = null;
        $recoverable['draining_host_boot_id'] = null;
        $recoverable['draining_monotonic'] = null;
        $recoverable['listener_transition_action'] = null;
        $recoverable['listener_transition_digest'] = null;
        $recoverable['drain_action_digest'] = null;
        $recoverable['transition_identity'] = null;
        $this->writeLeaseFixture($recoverableFile, $recoverable);
        self::assertSame(10001, $this->leaseFileCount($leaseDirectory));

        $capacityException = null;
        $unexpectedLease = null;
        try {
            $unexpectedLease = $newcomer->reserveBound(
                'site-gateway-fallback',
                static function (int $port) use (&$newcomerBinds): bool {
                    unset($port);
                    ++$newcomerBinds;
                    return true;
                },
            );
        } catch (\RuntimeException $exception) {
            $capacityException = $exception;
        }
        self::assertNull(
            $unexpectedLease,
            'GC must not turn one recovered overflow slot into a new overflow.',
        );
        self::assertInstanceOf(\RuntimeException::class, $capacityException);
        self::assertStringContainsString(
            'has no capacity for another retained lease',
            $capacityException->getMessage(),
        );
        self::assertSame(0, $newcomerBinds);
        self::assertFileDoesNotExist($recoverableFile);
        self::assertSame(10000, $this->leaseFileCount($leaseDirectory));
    }

    private function allocator(
        string $name,
        string $hostState,
        string $leaseDirectory,
        ?string $hostBootId = null,
        ?\Closure $monotonicClock = null,
        ?\Closure $afterAtomicPublication = null,
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
            null,
            $afterAtomicPublication,
        );
    }

    /**
     * @param array<string,mixed> $lease
     * @param array{birth:string,pid_namespace_id:string} $processIdentity
     * @return array<string,mixed>
     */
    private function transitionIdentity(
        array $lease,
        string $workerLaunchId,
        string $masterLaunchId,
        array $processIdentity,
    ): array {
        return [
            'schema' => 'wls-gateway-fallback-listener/1',
            'project_uuid' => (string)$lease['project_uuid'],
            'wls_instance' => 'site',
            'role' => 'gateway_fallback',
            'slot_id' => 'gateway_fallback#1',
            'service_generation' => 1,
            'service_lease_id' => \str_repeat('1', 32),
            'worker_pid' => (int)$lease['worker_pid'],
            'worker_process_birth' => $processIdentity['birth'],
            'worker_pid_namespace_id' => $processIdentity['pid_namespace_id'],
            'worker_launch_id' => $workerLaunchId,
            'master_pid' => (int)$lease['master_pid'],
            'master_epoch' => 1,
            'master_launch_id' => $masterLaunchId,
            'master_process_birth' => (string)$lease['master_process_birth'],
            'master_pid_namespace_id' => (string)$lease['master_pid_namespace_id'],
            'port' => (int)$lease['port'],
            'host_lease_instance' => (string)$lease['instance'],
            'host_lease_id' => (string)$lease['lease_id'],
            'host_boot_id' => (string)$lease['host_boot_id'],
            'bind_host' => (string)$lease['bind_host'],
            'listener_proof_digest' => \str_repeat('2', 64),
            'listener_transport' => 'posix_inherited_fd',
            'listener_receipt_digest' => \str_repeat('3', 64),
        ];
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

    private function leaseFileCount(string $leaseDirectory): int
    {
        $files = @\glob($leaseDirectory . DIRECTORY_SEPARATOR . '*.json');
        self::assertIsArray($files);
        return \count($files);
    }

    /** @return list<string> */
    private function recoveryBackupTraversalOrder(string $leaseDirectory): array
    {
        $directory = @\opendir($leaseDirectory);
        self::assertIsResource($directory);
        $backups = [];
        try {
            while (($leaf = @\readdir($directory)) !== false) {
                if (\preg_match(
                    '/\A[a-f0-9]{24}\.json\.wls-backup-[a-f0-9]{16}\z/D',
                    $leaf,
                ) === 1) {
                    $backups[] = $leaseDirectory . DIRECTORY_SEPARATOR . $leaf;
                }
            }
        } finally {
            @\closedir($directory);
        }
        return $backups;
    }

    /** @return array{birth:string,pid_namespace_id:string} */
    private function processIdentity(): array
    {
        return (new MasterLeaseRuntimeIdentity())->captureProcessIdentity(\getmypid());
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
