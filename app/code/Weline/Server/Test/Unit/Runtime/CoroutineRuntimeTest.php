<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\EventLoop\EventLoopInterface;
use Weline\Server\Runtime\CoroutineRuntime;
use Weline\Server\Scheduler\FiberScheduler;

final class CoroutineRuntimeTest extends TestCase
{
    public function testWaitDelegatesToLoopWithDefaultTimeout(): void
    {
        $loop = new class implements EventLoopInterface {
            public array $captured = [];

            public function wait(array &$read, array &$write, array &$except, int $timeoutSec, int $timeoutUsec): int|false
            {
                $this->captured = [
                    'timeout_sec' => $timeoutSec,
                    'timeout_usec' => $timeoutUsec,
                ];
                return 0;
            }

            public function backend(): string
            {
                return 'select';
            }
        };

        $runtime = new CoroutineRuntime($loop, new FiberScheduler());
        $read = [];
        $write = [];
        $except = [];
        $result = $runtime->wait($read, $write, $except, 123456);

        self::assertSame(0, $result);
        self::assertSame(0, $loop->captured['timeout_sec']);
        self::assertSame(123456, $loop->captured['timeout_usec']);
        self::assertSame('select', $runtime->getLoopBackend());
    }

    public function testReadyFiberTimerReturnsImmediatelyForResume(): void
    {
        $loop = new class implements EventLoopInterface {
            public array $captured = [];

            public function wait(array &$read, array &$write, array &$except, int $timeoutSec, int $timeoutUsec): int|false
            {
                $this->captured = [
                    'timeout_sec' => $timeoutSec,
                    'timeout_usec' => $timeoutUsec,
                ];
                return 0;
            }

            public function backend(): string
            {
                return 'select';
            }
        };

        $scheduler = new FiberScheduler();
        $fiber = new \Fiber(static function (): void {
            \Fiber::suspend();
        });
        $fiber->start();
        $scheduler->addYieldTimer($fiber);
        \usleep(100);

        $runtime = new CoroutineRuntime($loop, $scheduler);
        $read = [];
        $write = [];
        $except = [];
        $runtime->wait($read, $write, $except, 100000);

        self::assertSame(0, $loop->captured['timeout_sec']);
        self::assertSame(0, $loop->captured['timeout_usec']);
    }

    private static function guardTimingLoop(FiberScheduler $scheduler): CoroutineRuntime
    {
        return new CoroutineRuntime(new class implements EventLoopInterface {
            public function wait(array &$read, array &$write, array &$except, int $timeoutSec, int $timeoutUsec): int|false
            {
                return \stream_select($read, $write, $except, $timeoutSec, $timeoutUsec);
            }
            public function backend(): string { return 'select'; }
        }, $scheduler);
    }

    private static function guardTimingPair(): array
    {
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        foreach ($pair as $stream) {
            \stream_set_blocking($stream, false);
        }
        return $pair;
    }

    private static function guardTimingIoSpans(array $spans): array
    {
        return \array_values(\array_filter($spans,
            static fn(array $span): bool => $span['name'] === 'runtime.io.await_socket'));
    }

    public function testGuardTimingStaysWithTheWaitingFiberAndPreservesTheFirstPair(): void
    {
        $trace = \Weline\Framework\Runtime\RequestLifecycleTrace::class;
        $system = \Weline\Framework\Runtime\SchedulerSystem::class;
        $context = \Weline\Framework\Runtime\RequestContext::class;
        $server = $_SERVER;
        $get = $_GET;
        [$reader, $writer] = self::guardTimingPair();
        try {
            $_SERVER['REQUEST_URI'] = '/guard-timing-fixture?wls_trace=1';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET['wls_trace'] = '1';
            $context::init();
            $context::set('server', $_SERVER);
            $context::set('get', $_GET);
            $context::isInitialized();
            $trace::reset();
            self::assertTrue($trace::isEnabled());
            $scheduler = new FiberScheduler();
            $runtime = self::guardTimingLoop($scheduler);
            $register = new \ReflectionMethod(\Weline\Server\Observer\SchedulerWaitObserver::class, 'registerIoWaiter');
            $carriers = [];
            $system::enableScheduler();
            $system::enableIoWait();
            (new \ReflectionProperty($system, 'waitDispatcher'))->setValue(null,
                static function (string $type, array $params) use ($scheduler, $register, &$carriers): void {
                    $carriers[] = $params['io_timing'] ?? null;
                    $register->invoke(null, $scheduler, $params['fiber'], $params, $type === 'io_writable');
                }
            );
            $fiber = new \Fiber(static function () use ($reader, $trace, $system): array {
                $trace::reset();
                $ready = $system::awaitReadable($reader, 0.5);
                $bytes = \fread($reader, 4);
                $first = self::guardTimingIoSpans($trace::getSpans())[0];
                $timeout = $system::awaitReadable($reader, 0.02);
                return [$ready, $bytes, $timeout, $first, $trace::getSpans()];
            });
            $fiber->start();
            self::assertTrue($fiber->isSuspended());
            $calls = 0;
            $guardStart = $guardEnd = null;
            self::assertFalse($scheduler->observePendingIoGuard(
                static function () use (&$calls, &$guardStart, &$guardEnd): bool {
                    ++$calls;
                    $guardStart = \hrtime(true);
                    \usleep(20000);
                    $guardEnd = \hrtime(true);
                    return false;
                }
            ));
            $firstPair = [$carriers[0]->guard_start_ns ?? null, $carriers[0]->guard_end_ns ?? null];
            self::assertTrue($scheduler->observePendingIoGuard( static function () use (&$calls): bool {
                ++$calls;
                return true;
            }));
            $secondPair = [$carriers[0]->guard_start_ns ?? null, $carriers[0]->guard_end_ns ?? null];
            self::assertSame(4, \fwrite($writer, 'ping'));
            $read = $write = $except = [];
            self::assertSame(1, $runtime->wait($read, $write, $except, 100000));
            $scheduler->tick();
            self::assertTrue($fiber->isSuspended());
            $read = $write = $except = [];
            self::assertSame(0, $runtime->wait($read, $write, $except, 100000));
            \usleep(2000);
            $scheduler->tick();
            self::assertTrue($fiber->isTerminated());
            [$ready, $bytes, $timeout, $alreadyEmitted, $spans] = $fiber->getReturn();
            $awaits = self::guardTimingIoSpans($spans);
            self::assertTrue($ready);
            self::assertSame('ping', $bytes);
            self::assertFalse($timeout);
            self::assertSame(2, $calls);
            self::assertCount(2, $awaits);
            self::assertSame($alreadyEmitted, $awaits[0]);
            self::assertSame([], self::guardTimingIoSpans($trace::getSpans()));
            self::assertSame($firstPair, $secondPair, 'Repeated guard must not replace the first pair.');
            self::assertSame('ready', $awaits[0]['meta']['io_resolution']);
            self::assertSame('timeout', $awaits[1]['meta']['io_resolution']);
            foreach (['io_guard_start_monotonic_us', 'io_guard_end_monotonic_us'] as $key) {
                self::assertArrayHasKey($key, $awaits[0]['meta']);
                self::assertIsInt($awaits[0]['meta'][$key]);
                self::assertArrayHasKey($key, $awaits[1]['meta']);
                self::assertNull($awaits[1]['meta'][$key], 'The second wait did not traverse a guard.');
            }
            $meta = $awaits[0]['meta'];
            self::assertSame(\intdiv($firstPair[0], 1000), $meta['io_guard_start_monotonic_us']);
            self::assertSame(\intdiv($firstPair[1], 1000), $meta['io_guard_end_monotonic_us']);
            self::assertGreaterThanOrEqual($meta['io_registered_monotonic_us'], $meta['io_guard_start_monotonic_us']);
            self::assertLessThanOrEqual(\intdiv($guardStart, 1000), $meta['io_guard_start_monotonic_us']);
            self::assertGreaterThanOrEqual(\intdiv($guardEnd, 1000), $meta['io_guard_end_monotonic_us']);
            self::assertLessThanOrEqual($meta['io_collect_seen_monotonic_us'], $meta['io_guard_end_monotonic_us']);
            self::assertGreaterThanOrEqual(15000, $meta['io_guard_end_monotonic_us'] - $meta['io_guard_start_monotonic_us']);
            self::assertArrayNotHasKey('runtime.io.await_socket', $trace::getAggregateSummary()['phases']);
        } finally {
            $system::disableScheduler();
            $trace::reset();
            $_SERVER = $server;
            $_GET = $get;
            \fclose($reader);
            \fclose($writer);
        }
    }

    public function testGuardTimingExceptionDoesNotClaimCollectedResolvedNewOrReregisteredWaiters(): void
    {
        $scheduler = new FiberScheduler();
        $runtime = self::guardTimingLoop($scheduler);
        $pairs = [];
        $fibers = [];
        $timings = [];
        $add = static function (string $name) use ($scheduler, &$pairs, &$fibers, &$timings): void {
            $pairs[$name] = self::guardTimingPair();
            $reader = $pairs[$name][0];
            $timings[$name] = new \stdClass();
            $fibers[$name] = new \Fiber(static function () use ($reader): array {
                $ready = \Fiber::suspend();
                return [$ready, \fread($reader, 4)];
            });
            $fibers[$name]->start();
            $scheduler->addReadableWaiter($fibers[$name], $reader, 0.5, $timings[$name]);
        };
        try {
            $add('collected');
            $read = $write = [];
            $ioTimings = [];
            $scheduler->collectIoWaitStreams($read, $write, $ioTimings);
            self::assertSame([$pairs['collected'][0]], $read);
            $add('resolved');
            self::assertSame(4, \fwrite($pairs['resolved'][1], 'ping'));
            $read = [$pairs['resolved'][0]];
            $write = $except = [];
            self::assertSame(1, \stream_select($read, $write, $except, 0, 100000));
            $scheduler->markIoReady($read, []);
            $add('eligible');
            $add('reregistered');
            $oldRegistration = $timings['reregistered']->registered_ns;
            $expected = new \RuntimeException('guard timing fixture');
            $caught = null;
            $calls = 0;
            $insideStart = $insideEnd = null;
            try {
                $scheduler->observePendingIoGuard( static function () use (
                    $scheduler, $add, &$timings, &$fibers, &$pairs, $expected,
                    &$calls, &$insideStart, &$insideEnd
                ): bool {
                    ++$calls;
                    $insideStart = \hrtime(true);
                    $add('new');
                    // Prove attach resets prior observation fields on a fresh registration.
                    $timings['reregistered']->guard_start_ns = 1;
                    $timings['reregistered']->guard_end_ns = 2;
                    \usleep(1000);
                    $scheduler->addReadableWaiter($fibers['reregistered'], $pairs['reregistered'][0], 0.5, $timings['reregistered']);
                    \usleep(1000);
                    $insideEnd = \hrtime(true);
                    throw $expected;
                });
            } catch (\Throwable $error) {
                $caught = $error;
            }
            // Capture observation state before the real wait/tick resumes all five Fibers.
            $observed = [];
            foreach ($timings as $name => $timing) {
                $observed[$name] = \get_object_vars($timing);
            }
            foreach ($pairs as $name => [$reader, $writer]) {
                if ($name !== 'resolved') {
                    self::assertSame(4, \fwrite($writer, 'ping'));
                }
            }
            $read = $write = $except = [];
            self::assertSame(4, $runtime->wait($read, $write, $except, 100000));
            $scheduler->tick();
            self::assertSame($expected, $caught);
            self::assertSame(1, $calls);
            foreach ($fibers as $fiber) {
                self::assertTrue($fiber->isTerminated());
                self::assertSame([true, 'ping'], $fiber->getReturn());
            }
            self::assertGreaterThan($oldRegistration, $observed['reregistered']['registered_ns']);
            foreach (['eligible', 'collected', 'resolved', 'new', 'reregistered'] as $name) {
                self::assertArrayHasKey('guard_start_ns', $observed[$name], $name);
                self::assertArrayHasKey('guard_end_ns', $observed[$name], $name);
            }
            self::assertIsInt($observed['eligible']['guard_start_ns']);
            self::assertIsInt($observed['eligible']['guard_end_ns']);
            self::assertLessThanOrEqual($insideStart, $observed['eligible']['guard_start_ns']);
            self::assertGreaterThanOrEqual($insideEnd, $observed['eligible']['guard_end_ns']);
            foreach (['collected', 'resolved', 'new', 'reregistered'] as $name) {
                self::assertNull($observed[$name]['guard_start_ns'], $name);
                self::assertNull($observed[$name]['guard_end_ns'], $name);
            }
        } finally {
            foreach ($pairs as [$reader, $writer]) {
                \fclose($reader);
                \fclose($writer);
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testGuardTimingWithoutCarrierPreservesCallbackWithoutLoadingTrace(): void
    {
        $trace = 'Weline\\Framework\\Runtime\\RequestLifecycleTrace';
        self::assertFalse(\class_exists($trace, false));
        $autoloads = [];
        $loader = static function (string $class) use (&$autoloads): void { $autoloads[] = $class; };
        \spl_autoload_register($loader, true, true);
        [$reader, $writer] = self::guardTimingPair();
        try {
            $scheduler = new FiberScheduler();
            $fiber = new \Fiber(static fn() => \Fiber::suspend());
            $fiber->start();
            $scheduler->addReadableWaiter($fiber, $reader, 0.5);
            $calls = 0;
            self::assertFalse($scheduler->observePendingIoGuard( static function () use (&$calls): bool {
                ++$calls;
                return false;
            }));
            $expected = new \RuntimeException('untraced guard fixture');
            $caught = null;
            try {
                $scheduler->observePendingIoGuard( static function () use (&$calls, $expected): bool {
                    ++$calls;
                    throw $expected;
                });
            } catch (\Throwable $error) {
                $caught = $error;
            }
            self::assertSame($expected, $caught);
            self::assertSame(2, $calls);
            self::assertFalse(\class_exists($trace, false));
            self::assertNotContains($trace, $autoloads);
            $waiters = (new \ReflectionProperty($scheduler, 'ioWaiters'))->getValue($scheduler);
            self::assertCount(1, $waiters);
            self::assertArrayNotHasKey('io_timing', \array_values($waiters)[0]);
            self::assertSame(4, \fwrite($writer, 'ping'));
            $read = $write = $except = [];
            self::assertSame(1, self::guardTimingLoop($scheduler)->wait($read, $write, $except, 100000));
            $scheduler->tick();
            self::assertTrue($fiber->isTerminated());
            self::assertTrue($fiber->getReturn());
            self::assertTrue(\method_exists($scheduler, 'observePendingIoGuard'), 'Missing guard timing helper.');
        } finally {
            \spl_autoload_unregister($loader);
            \fclose($reader);
            \fclose($writer);
        }
    }
}

