<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Shared\Client;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Exception\AtomicWriteOutcomeUnknownException;
use Weline\Server\Service\MemoryStateFacade;
use Weline\Server\Session\Server\SessionProtocol;
use Weline\Server\Shared\Client\SharedStateClient;
use Weline\Server\Shared\Connection\PooledConnection;
use Weline\Server\Shared\Contract\ConnectionPoolInterface;
use Weline\Server\Shared\Contract\DeadlinePooledConnectionInterface;
use Weline\Server\Shared\Contract\PooledConnectionInterface;

final class SharedStateAtomicWriteBudgetTest extends TestCase
{
    private function delayedReply(array $options, int $delayUs, string $command = 'cas'): array
    {
        $listener = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener);
        $address = \stream_socket_get_name($listener, false);
        $port = (int)\substr(\strrchr($address, ':'), 1);
        $pid = \pcntl_fork();
        if ($pid === 0) {
            $socket = \stream_socket_accept($listener, 1);
            \fclose($listener);
            if (!$socket || !\is_string(\fgets($socket))) {
                exit(2);
            }
            \usleep($delayUs);
            @\fwrite($socket, SessionProtocol::encodeSuccess());
            \fclose($socket);
            exit(0);
        }
        self::assertGreaterThan(0, $pid);
        \fclose($listener);
        $socket = \stream_socket_client('tcp://' . $address, $errno, $error, 1);
        \stream_set_blocking($socket, false);
        $connection = new PooledConnection('127.0.0.1', $port, .2, $options['timeout'], '', false, null, false);
        (new \ReflectionProperty($connection, 'socket'))->setValue($connection, $socket);
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('acquire')->willReturn($connection);
        $client = new SharedStateClient('127.0.0.1', $port, $options + ['min_idle' => 0, 'pool_profile' => 'isolated_budget']);
        (new \ReflectionProperty($client, 'pool'))->setValue($client, $pool);
        $response = null;
        $exception = null;
        try {
            $response = $client->request($command, ['ns' => 'synthetic', 'sid' => 'synthetic', 'key' => 'counter', 'val' => 1]);
        } catch (\Throwable $error) {
            $exception = $error;
        } finally {
            $connection->close();
            \pcntl_waitpid($pid, $status);
        }
        self::assertSame(0, \pcntl_wexitstatus($status));

        return [$response, $exception];
    }

    public function testConfiguredTwoSecondEnvelopeReceivesOriginalAckAfter150ms(): void
    {
        [$response, $error] = $this->delayedReply(['timeout' => 2.0, 'acquire_timeout' => .2], 150000);
        self::assertNull($error);
        self::assertTrue(SessionProtocol::isSuccess($response));
    }

    public function testExplicitShortAtomicBudgetStillReportsUnknownWithoutResend(): void
    {
        [$response, $error] = $this->delayedReply(['timeout' => 2.0, 'atomic_write_timeout' => .04], 80000);
        self::assertNull($response);
        self::assertInstanceOf(AtomicWriteOutcomeUnknownException::class, $error);
    }

    public function testWlsReadBudgetDoesNotShrinkExistingAtomicWindow(): void
    {
        [$response, $error] = $this->delayedReply(['timeout' => .05], 80000);
        self::assertNull($error);
        self::assertTrue(SessionProtocol::isSuccess($response));
    }

    public function testOrdinaryGetAndSetDoNotUseAtomicWriteBudget(): void
    {
        foreach (['get', 'set'] as $command) {
            [$response, $error] = $this->delayedReply(['timeout' => .05, 'atomic_write_timeout' => 2.0], 80000, $command);
            self::assertNull($response);
            self::assertNull($error);
        }
    }

    public function testFacadePassesExplicitOptionAndPreservesReadTimeout(): void
    {
        $reflection = new \ReflectionClass(MemoryStateFacade::class);
        $facade = $reflection->newInstanceWithoutConstructor();
        $options = $reflection->getMethod('buildServiceOptions')->invoke($facade, [
            'timeout' => .05, 'atomic_write_timeout' => .4,
            'token_file_name' => 'synthetic.token', 'token_authority_instance' => 'synthetic',
        ]);
        self::assertSame(.4, $options['atomic_write_timeout']);
        self::assertSame(.05, $options['timeout']);
    }

    public function testAcquireSendAndReadConsumeOneAbsoluteDeadline(): void
    {
        $connection = $this->createMock(DeadlinePooledConnectionInterface::class);
        $sendDeadline = null;
        $connection->expects(self::once())->method('sendUntil')->willReturnCallback(
            static function (string $payload, float $deadline) use (&$sendDeadline): bool {
                $sendDeadline = $deadline;
                \usleep(30000);
                return true;
            },
        );
        $connection->expects(self::once())->method('readUntil')->willReturnCallback(
            static function (float $deadline) use (&$sendDeadline): array {
                self::assertSame($sendDeadline, $deadline);
                self::assertLessThan(.09, $deadline - \hrtime(true) / 1e9);
                return ['ok' => true];
            },
        );
        $connection->expects(self::never())->method('send');
        $connection->expects(self::never())->method('read');
        $pool = $this->createMock(ConnectionPoolInterface::class);
        $pool->expects(self::once())->method('acquire')->willReturnCallback(
            static function (float $timeout) use ($connection): PooledConnectionInterface {
                self::assertLessThanOrEqual(.12, $timeout);
                \usleep(20000);
                return $connection;
            },
        );
        $pool->expects(self::once())->method('release');
        $client = new SharedStateClient('127.0.0.1', 47399, [
            'timeout' => 2.0, 'atomic_write_timeout' => .12, 'acquire_timeout' => 1.0,
            'min_idle' => 0, 'pool_profile' => 'isolated_deadline',
        ]);
        (new \ReflectionProperty($client, 'pool'))->setValue($client, $pool);
        self::assertTrue(SessionProtocol::isSuccess($client->request('cas', ['val' => \str_repeat('x', 10000)])));
    }
}
