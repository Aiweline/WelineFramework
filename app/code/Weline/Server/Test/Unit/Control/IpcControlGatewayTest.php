<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\Timeouts;

final class IpcControlGatewayTest extends TestCase
{
    public function testReloadAsyncAndCacheClearDelegateToAsyncCommand(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $calls = [];

            protected function commandAsync(
                string $instanceName,
                string $action,
                string $reloadType = '',
                array $payload = [],
                float $timeout = 5.0,
                string $acceptedMessage = 'Command queued'
            ): array {
                $this->calls[] = [$instanceName, $action, $reloadType, $payload, $timeout, $acceptedMessage];

                return ['success' => true, 'message' => 'ok', 'data' => []];
            }
        };

        $gateway->reloadAsync('blue', ControlMessage::RELOAD_TYPE_FORCE, 2.5);
        $gateway->cacheClear('blue', 1.5);

        $this->assertSame(
            ['blue', ControlMessage::ACTION_RELOAD, ControlMessage::RELOAD_TYPE_FORCE, [], 2.5, 'Reload initiated'],
            $gateway->calls[0]
        );
        $this->assertSame(
            ['blue', ControlMessage::ACTION_CACHE_CLEAR, '', [], 1.5, 'Cache clear queued'],
            $gateway->calls[1]
        );
    }

    public function testSetMaintenanceModeDelegatesToAsyncCommand(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $calls = [];

            protected function commandAsync(
                string $instanceName,
                string $action,
                string $reloadType = '',
                array $payload = [],
                float $timeout = 5.0,
                string $acceptedMessage = 'Command queued'
            ): array {
                $this->calls[] = [$instanceName, $action, $reloadType, $payload, $timeout, $acceptedMessage];

                return ['success' => true, 'message' => 'ok', 'data' => []];
            }
        };

        $gateway->setMaintenanceMode('blue', true, 2.5);
        $gateway->setMaintenanceMode('blue', false, 3.5);

        $this->assertSame(
            ['blue', ControlMessage::ACTION_MAINTENANCE_ENABLE, '', [], 2.5, 'Maintenance enable queued'],
            $gateway->calls[0]
        );
        $this->assertSame(
            ['blue', ControlMessage::ACTION_MAINTENANCE_DISABLE, '', [], 3.5, 'Maintenance disable queued'],
            $gateway->calls[1]
        );
    }

    public function testReadCommandResultReturnsAcceptedWithoutWaitingForReloadCompletion(): void
    {
        $gateway = new IpcControlGateway();
        $method = new ReflectionMethod(IpcControlGateway::class, 'readCommandResult');
        $method->setAccessible(true);

        $stream = \fopen('php://temp', 'r+');
        $this->assertNotFalse($stream);

        \fwrite(
            $stream,
            ControlMessage::commandResult(true, ['async' => true], 'Reload initiated')
            . ControlMessage::reloadProgress(1, 2, 1, 'draining')
        );
        \rewind($stream);

        /** @var array{success:bool,message:string,data:array} $result */
        $result = $method->invoke($gateway, $stream, 0.2);

        $this->assertTrue($result['success']);
        $this->assertSame('Reload initiated', $result['message']);
        $this->assertSame(['async' => true], $result['data']);

        \fclose($stream);
    }

    public function testExplicitDeadlineDoesNotInheritTwelveSecondConnectFloor(): void
    {
        $method = new ReflectionMethod(
            IpcControlGateway::class,
            'controlConnectTimeout',
        );

        self::assertSame(
            Timeouts::CONTROL_MIN_CONNECT_TIMEOUT_SEC,
            $method->invoke(null, 1.0, null, 100.0),
        );
        self::assertEqualsWithDelta(
            0.25,
            $method->invoke(null, 1.0, 100.25, 100.0),
            0.000001,
        );
        self::assertSame(
            1.0,
            $method->invoke(null, 1.0, 105.0, 100.0),
        );
    }

    public function testExpiredAbsoluteDeadlineReturnsBeforeOpeningConnection(): void
    {
        $errno = 0;
        $error = '';
        $server = \stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $error,
        );
        self::assertIsResource($server, $error);
        $address = \stream_socket_get_name($server, false);
        self::assertIsString($address);
        $separator = \strrpos($address, ':');
        self::assertIsInt($separator);
        $port = (int)\substr($address, $separator + 1);
        self::assertGreaterThan(0, $port);

        try {
            $method = new ReflectionMethod(IpcControlGateway::class, 'sendCommand');
            /** @var array<string,mixed> $result */
            $result = $method->invoke(
                new IpcControlGateway(),
                $port,
                "deadline-test\n",
                1.0,
                false,
                '',
                ControlMessage::monotonicSeconds() - 1.0,
            );

            self::assertFalse($result['success']);
            self::assertTrue($result['timed_out']);
            self::assertSame(
                'deadline_exhausted',
                $result['data']['error_code'] ?? null,
            );
            self::assertFalse(
                @\stream_socket_accept($server, 0.0),
                'An already-expired command must not open a control connection.',
            );
        } finally {
            \fclose($server);
        }
    }

    public function testDeadlineAwareStatusAndMaintenanceFailClosedBeforeEndpointLookup(): void
    {
        $gateway = new IpcControlGateway();
        $deadline = ControlMessage::monotonicSeconds() - 1.0;

        $status = $gateway->getStatusBeforeDeadline(
            'ut-missing-deadline-status',
            1.0,
            $deadline,
        );
        self::assertFalse($status['success']);
        self::assertSame(
            'deadline_exhausted',
            $status['data']['error_code'] ?? null,
        );

        $maintenance = $gateway->setMaintenanceModeBeforeDeadline(
            'ut-missing-deadline-maintenance',
            true,
            6.0,
            $deadline,
        );
        self::assertFalse($maintenance['success']);
        self::assertSame(
            'deadline_exhausted',
            $maintenance['data']['error_code'] ?? null,
        );
        self::assertFalse(
            (bool)($maintenance['data']['accepted'] ?? false),
            'An exhausted absolute deadline must never be converted into an async accepted result.',
        );
    }

    public function testWriteAndReadPhasesShareOneAbsoluteBudget(): void
    {
        $phaseDeadline = new ReflectionMethod(
            IpcControlGateway::class,
            'phaseDeadline',
        );
        $absoluteDeadline = 100.75;

        self::assertSame(
            $absoluteDeadline,
            $phaseDeadline->invoke(null, 1.0, $absoluteDeadline, 100.0),
        );
        self::assertSame(
            $absoluteDeadline,
            $phaseDeadline->invoke(null, 1.0, $absoluteDeadline, 100.6),
            'A later I/O phase must retain the original deadline instead of reopening a one-second budget.',
        );

        $retry = new ReflectionMethod(
            IpcControlGateway::class,
            'boundedRetryMicroseconds',
        );
        self::assertSame(
            25_000,
            $retry->invoke(null, 100_000, 100.025, 100.0),
        );
        self::assertSame(
            0,
            $retry->invoke(null, 100_000, 100.0, 100.0),
        );

        $send = new ReflectionMethod(IpcControlGateway::class, 'sendCommand');
        $lines = \file($send->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $send->getStartLine() - 1,
            $send->getEndLine() - $send->getStartLine() + 1,
        ));
        self::assertMatchesRegularExpression(
            '/writeCommandFully\(\s*\$conn,\s*\$command,\s*\$readTimeout,\s*'
                . '\$deadlineMonotonic,/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/readCommandResult\(\s*\$conn,\s*\$readTimeout,\s*'
                . '\$deadlineMonotonic,/s',
            $source,
        );
    }

    public function testParallelMaintenanceDispatchUsesTheSameAbsoluteDeadline(): void
    {
        $method = new ReflectionMethod(
            IpcControlGateway::class,
            'sendCommandsParallel',
        );
        $lines = \file($method->getFileName());
        self::assertIsArray($lines);
        $source = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        self::assertMatchesRegularExpression(
            '/controlConnectTimeout\(\s*\$readTimeout,\s*'
                . '\$deadlineMonotonic,\s*ControlMessage::monotonicSeconds\(\),/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/writeCommandFully\(\s*\$conn,\s*\$command,\s*'
                . '\$readTimeout,\s*\$deadlineMonotonic,/s',
            $source,
        );
        self::assertStringContainsString(
            '$deadline = $deadlineMonotonic',
            $source,
        );
    }
}
