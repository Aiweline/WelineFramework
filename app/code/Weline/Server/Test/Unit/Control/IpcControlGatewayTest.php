<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\IpcControlGateway;

final class IpcControlGatewayTest extends TestCase
{
    public function testReloadAsyncAndCacheClearDelegateToCommand(): void
    {
        $gateway = new class extends IpcControlGateway {
            public array $calls = [];

            public function command(
                string $instanceName,
                string $action,
                string $reloadType = '',
                array $payload = [],
                float $timeout = 6.0
            ): array {
                $this->calls[] = [$instanceName, $action, $reloadType, $payload, $timeout];

                return ['success' => true, 'message' => 'ok', 'data' => []];
            }
        };

        $gateway->reloadAsync('blue', ControlMessage::RELOAD_TYPE_FORCE, 2.5);
        $gateway->cacheClear('blue', 1.5);

        $this->assertSame(
            ['blue', ControlMessage::ACTION_RELOAD, ControlMessage::RELOAD_TYPE_FORCE, [], 2.5],
            $gateway->calls[0]
        );
        $this->assertSame(
            ['blue', ControlMessage::ACTION_CACHE_CLEAR, '', [], 1.5],
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
}
