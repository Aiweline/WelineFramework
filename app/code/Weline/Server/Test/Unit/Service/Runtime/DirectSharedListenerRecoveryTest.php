<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\DirectSharedListener;

final class DirectSharedListenerRecoveryTest extends TestCase
{
    public function testMasterCanRecoverItsExactInheritedListenerAfterLosingObjectReference(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX inherited descriptor contract');
        }

        $listener = \stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($listener, $error);
        $endpoint = \stream_socket_get_name($listener, false);
        self::assertIsString($endpoint);
        $port = (int)\substr($endpoint, (int)\strrpos($endpoint, ':') + 1);
        $script = <<<'PHP'
define('BP', getcwd() . DIRECTORY_SEPARATOR);
require 'app/bootstrap.php';
$port = (int)$argv[1];
$listener = new \Weline\Server\Service\Runtime\DirectSharedListener();
$wrongEndpointRecovered = $listener->recoverInheritedMasterListener('127.0.0.1', $port + 1, str_repeat('a', 32));
echo json_encode([
    'wrong_endpoint_recovered' => $wrongEndpointRecovered,
    'recovered' => $listener->recoverInheritedMasterListener('127.0.0.1', $port, str_repeat('a', 32)),
    'matches' => $listener->matches('127.0.0.1', $port),
]);
PHP;
        $process = \proc_open(
            [PHP_BINARY, '-r', $script, (string)$port],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], DirectSharedListener::INHERITED_FD => $listener],
            $pipes,
            BP,
        );
        self::assertIsResource($process);
        try {
            \fclose($pipes[0]);
            $output = \stream_get_contents($pipes[1]);
            $errors = \stream_get_contents($pipes[2]);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            self::assertSame(0, \proc_close($process), $errors);
            self::assertSame([
                'wrong_endpoint_recovered' => false,
                'recovered' => true,
                'matches' => true,
            ], \json_decode((string)$output, true));
        } finally {
            \fclose($listener);
        }
    }
}
