<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Output\Cli\Printing;
use Weline\Server\Console\Server\Reload;

final class ReloadCommandDisconnectTest extends TestCase
{
    public function testWaitForCompletionStopsWhenControlSocketEofIsDetected(): void
    {
        $printer = new class extends Printing {
            /** @var string[] */
            public array $warnings = [];

            public function warning(string $data = 'CLI Warning!', string $message = '', string $color = self::WARNING, int $pad_length = 25)
            {
                $this->warnings[] = $data;
            }

            public function success(string $data = 'CLI Success!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
            {
            }

            public function note(string $data = 'CLI Note!', string $message = '', string $color = self::NOTE, int $pad_length = 25)
            {
            }

            public function error($data = 'CLI Error!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
            {
            }
        };

        $reload = new class($printer) extends Reload {
            public function __construct(private readonly Printing $printing)
            {
                $this->printer = $printing;
            }

            public function waitOn($conn, int $totalWorkers, int $waitTimeout): void
            {
                $this->waitForCompletion($conn, $totalWorkers, $waitTimeout);
            }
        };

        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($server, $errstr);
        $serverName = \stream_socket_get_name($server, false);
        self::assertIsString($serverName);

        $client = \stream_socket_client('tcp://' . $serverName, $errno, $errstr, 2);
        self::assertNotFalse($client, $errstr);

        $peer = \stream_socket_accept($server, 2);
        self::assertNotFalse($peer);
        \stream_set_blocking($client, false);

        @\fclose($peer);
        @\fclose($server);

        $startedAt = \microtime(true);
        \ob_start();
        try {
            $reload->waitOn($client, 2, 5);
        } finally {
            \ob_end_clean();
        }
        $elapsed = \microtime(true) - $startedAt;

        self::assertLessThan(1.0, $elapsed);
        self::assertNotEmpty($printer->warnings);
        self::assertStringContainsString('控制连接已断开', $printer->warnings[0]);
    }
}
