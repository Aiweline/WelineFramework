<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayEmergencyRevocationClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayWindowsNamedPipeTransportException;

final class GatewayClientTimeoutPolicyTest extends TestCase
{
    public function testClientsRejectInvalidDefaultTimeoutBudgets(): void
    {
        foreach ([0.0, -1.0, INF, NAN] as $timeout) {
            try {
                new GatewayClient(timeoutSeconds: $timeout);
                self::fail('GatewayClient accepted an invalid timeout.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            try {
                new GatewayEmergencyRevocationClient(timeoutSeconds: $timeout);
                self::fail('GatewayEmergencyRevocationClient accepted an invalid timeout.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNullableDeadlineIsMaterializedOnceBeforeTransportAndPagination(): void
    {
        $clientSource = \file_get_contents(
            \dirname(__DIR__, 5) . '/Service/Edge/Gateway/GatewayClient.php',
        );
        $emergencySource = \file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayEmergencyRevocationClient.php',
        );

        self::assertIsString($clientSource);
        self::assertIsString($emergencySource);
        $start = \strpos($clientSource, 'private function requestWithChannel(');
        $end = \strpos($clientSource, "/**\n     * @param array<string,mixed> \$payload", (int)$start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $requestWithChannel = \substr($clientSource, $start, $end - $start);
        self::assertStringContainsString(
            '$deadlineMonotonic ??= (\hrtime(true) / 1_000_000_000)',
            $requestWithChannel,
        );
        self::assertStringContainsString(
            'requestSingleWithChannel(',
            $requestWithChannel,
        );
        self::assertStringContainsString(
            'collectPaginatedResponse(',
            $requestWithChannel,
        );
        self::assertSame(
            3,
            \substr_count($requestWithChannel, '$deadlineMonotonic,'),
        );
        $revokeStart = \strpos($emergencySource, 'public function revoke(');
        $revokeEnd = \strpos(
            $emergencySource,
            'private function remainingDeadlineSeconds(',
            (int)$revokeStart,
        );
        self::assertIsInt($revokeStart);
        self::assertIsInt($revokeEnd);
        $revoke = \substr($emergencySource, $revokeStart, $revokeEnd - $revokeStart);
        self::assertStringContainsString(
            '$deadlineMonotonic ??= (\hrtime(true) / 1_000_000_000)',
            $revoke,
        );
        self::assertStringContainsString(
            'remainingDeadlineSeconds($deadlineMonotonic)',
            $revoke,
        );
    }

    public function testRepairResponseCoversThePublicationProbeWindow(): void
    {
        $client = new GatewayClient(timeoutSeconds: 2.0);

        foreach (['repair', 'revoke', 'transfer', 'upgrade'] as $operation) {
            self::assertSame(
                90.0,
                $this->responseTimeout($client, 'admin', $operation),
                $operation,
            );
        }
        self::assertSame(2.0, $this->responseTimeout($client, 'admin', 'status'));
        foreach (['register', 'renew', 'drain', 'unregister'] as $operation) {
            self::assertSame(
                90.0,
                $this->responseTimeout($client, 'project', $operation),
                $operation,
            );
        }
        self::assertSame(2.0, $this->responseTimeout($client, 'project', 'heartbeat'));
        self::assertSame(2.0, $this->responseTimeout($client, 'project', 'own-status'));
    }

    public function testWindowsBrokerCoversTheAdminPublicationResponseWindow(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/windows/wls_gateway_broker.c',
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            '#define WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS 90000U',
            $source,
        );
        self::assertStringContainsString(
            '#define WLS_PROJECT_CONTROLLER_IO_TIMEOUT_MS 90000U',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/wls_connect_controller\(\s*channel->controller_port,\s*'
                . 'wcscmp\(channel->channel, L"admin"\) == 0\s*'
                . '\? WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS\s*'
                . ': WLS_PROJECT_CONTROLLER_IO_TIMEOUT_MS\s*,\s*'
                . 'channel->fencing\s*\)/s',
            $source,
        );
    }

    public function testCompleteAcknowledgementIsValidatedBeforeAnyFurtherDeadlineGate(): void
    {
        foreach ([
            'GatewayClient.php'
                => 'throw new \\RuntimeException(self::UNPROVEN_RESPONSE_ERROR);',
            'GatewayEmergencyRevocationClient.php'
                => 'Native gateway guardian returned no complete revocation acknowledgement.',
        ] as $file => $validationMessage) {
            $source = \file_get_contents(
                \dirname(__DIR__, 5) . '/Service/Edge/Gateway/' . $file,
            );
            self::assertIsString($source);
            $read = \strpos($source, '$line = @\\fgets');
            $validation = \strpos($source, $validationMessage, (int)$read);
            self::assertIsInt($read, $file);
            self::assertIsInt($validation, $file);

            $commitBoundary = \substr($source, $read, $validation - $read);
            self::assertStringNotContainsString(
                'remainingDeadlineSeconds($deadlineMonotonic)',
                $commitBoundary,
                $file . ' must authenticate a complete acknowledgement even when the caller deadline crossed after the read.',
            );
        }
    }

    public function testCurrentUnprovenResponseFailureRemainsIdempotentlyRetryable(): void
    {
        $method = new ReflectionMethod(
            GatewayHostManager::class,
            'publicationStatusTransportFailureRetryable',
        );
        $manager = new GatewayHostManager();

        self::assertTrue($method->invoke(
            $manager,
            new \RuntimeException(GatewayClient::UNPROVEN_RESPONSE_ERROR),
        ));
        foreach (['admin', 'project'] as $channel) {
            self::assertTrue($method->invoke(
                $manager,
                new \RuntimeException(
                    'WLS Gateway ' . $channel
                        . ' endpoint unavailable: native named-pipe transport did not return a frame.',
                ),
            ));
        }
        self::assertFalse($method->invoke(
            $manager,
            new \RuntimeException('WLS Gateway returned an invalid protocol response.'),
        ));
    }

    public function testWindowsClientsShareTheNativeNamedPipeTransport(): void
    {
        $root = \dirname(__DIR__, 5) . '/Service/Edge/Gateway/';
        foreach (['GatewayClient.php', 'GatewayEmergencyRevocationClient.php'] as $file) {
            $source = \file_get_contents($root . $file);
            self::assertIsString($source);
            self::assertStringContainsString(
                'GatewayWindowsNamedPipeTransport $windowsPipeTransport',
                $source,
                $file,
            );
            self::assertStringContainsString(
                '$this->windowsPipeTransport->exchange(',
                $source,
                $file,
            );
            self::assertStringContainsString(
                'catch (GatewayWindowsNamedPipeTransportException $exception)',
                $source,
                $file,
            );
            self::assertStringNotContainsString(
                "@\\fopen(\$endpoint['address'], 'r+b')",
                $source,
                $file,
            );
        }

        $transport = \file_get_contents(
            $root . 'GatewayWindowsNamedPipeTransport.php',
        );
        self::assertIsString($transport);
        self::assertStringContainsString(
            'GatewayBoundedCommandRunner::exchangeWindowsNamedPipe(',
            $transport,
        );
        self::assertStringNotContainsString('FFI', $transport);
        self::assertStringNotContainsString('PowerShell', $transport);
        self::assertStringNotContainsString('cmd.exe', $transport);

        $runner = \file_get_contents($root . 'GatewayBoundedCommandRunner.php');
        self::assertIsString($runner);
        self::assertStringContainsString(
            'if ($exchangeCode === 72)',
            $runner,
        );
        self::assertStringContainsString(
            'GatewayWindowsNamedPipeTransportException(',
            $runner,
        );
    }

    public function testNamedPipeAvailabilityClassificationDoesNotCollapseIntegrityFailures(): void
    {
        self::assertTrue((new GatewayWindowsNamedPipeTransportException(
            'endpoint unavailable',
            true,
        ))->retryable());
        self::assertFalse((new GatewayWindowsNamedPipeTransportException(
            'local integrity proof failed',
            false,
        ))->retryable());
    }

    public function testNativeNamedPipeTransportUsesOneStrictCallerDeadline(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayBoundedCommandRunner.php',
        );
        self::assertIsString($source);
        $start = \strpos($source, 'public static function exchangeWindowsNamedPipe(');
        $end = \strpos($source, "/**\n     * Launch only", (int)$start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $exchange = \substr($source, $start, $end - $start);
        self::assertStringContainsString(
            'private const WINDOWS_PIPE_MAX_FRAME_BYTES = 4_194_304;',
            $source,
        );
        self::assertStringContainsString(
            '$helperWatchdog = $deadlineMonotonic',
            $exchange,
        );
        self::assertStringContainsString(
            '$deadlineMonotonic,',
            $exchange,
        );
        self::assertStringNotContainsString(
            'WINDOWS_OUTER_GRACE_SECONDS',
            $exchange,
        );
        self::assertStringContainsString(
            "['bypass_shell' => true, 'blocking_pipes' => false]",
            $source,
        );
    }

    public function testNativeHelperBoundsAndCancelsEveryPipeIoPhase(): void
    {
        $root = \dirname(__DIR__, 5) . '/Service/Edge/Gateway/Native/';
        $source = \file_get_contents($root . 'windows/wls_bounded_command.c');
        $cmake = \file_get_contents($root . 'CMakeLists.txt');
        self::assertIsString($source);
        self::assertIsString($cmake);

        foreach ([
            '#define WLS_PIPE_MAX_FRAME_BYTES ((SIZE_T)4194304U)',
            '#define WLS_PIPE_MAX_CONNECT_ATTEMPTS ((DWORD)4096U)',
            '#define WLS_PIPE_MAX_READ_OPERATIONS ((DWORD)8192U)',
            '#define WLS_PIPE_MAX_WRITE_OPERATIONS ((DWORD)8192U)',
            'WLS_PIPE_ADMIN_PATH',
            'WLS_PIPE_PROJECT_PATH',
            'WaitNamedPipeW(',
            'FILE_FLAG_OVERLAPPED',
            'CancelIoEx(',
            'GetTickCount64()',
            'CREATE_NEW',
            'FILE_FLAG_OPEN_REPARSE_POINT',
            'wls_pipe_directory_has_only_request(',
            'wls-named-pipe-exchange-result/1',
            '--pipe-deadline-self-test',
            'GetProcAddress(kernel, "CancelIoEx")',
            'CreateNamedPipeW(',
            'wls_pipe_overlapped_io_until(',
            '|| !timed_out',
            '|| !abandoned',
        ] as $contract) {
            self::assertStringContainsString($contract, $source, $contract);
        }
        self::assertMatchesRegularExpression(
            '/add_executable\(wls-bounded-command\s+windows\/wls_bounded_command\.c\)/',
            $cmake,
        );
        self::assertStringContainsString(
            'target_compile_options(wls-bounded-command PRIVATE /W4 /WX /sdl)',
            $cmake,
        );
        self::assertStringContainsString(
            'WLS_NAMED_PIPE_DEADLINE_TRANSPORT=1',
            $cmake,
        );
        self::assertStringContainsString(
            '#error "wls-bounded-command must include the native named-pipe deadline transport"',
            $source,
        );
    }

    public function testNativeHelperBoundsTransactionRequestInputByExchangeDeadline(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/windows/wls_bounded_command.c',
        );
        self::assertIsString($source);
        $start = \strpos($source, 'static int wls_pipe_exchange(');
        $end = \strpos($source, 'static DWORD WINAPI wls_capture_thread(', (int)$start);
        self::assertIsInt($start);
        self::assertIsInt($end);
        $exchange = \substr($source, $start, $end - $start);

        self::assertStringContainsString('FILE_FLAG_OVERLAPPED', $exchange);
        self::assertMatchesRegularExpression(
            '/wls_pipe_overlapped_io_until\(\s+request,\s+FALSE,\s+'
                . 'request_bytes \+ offset,\s+chunk,\s+&received,\s+'
                . 'deadline,\s+&timed_out,\s+&abandoned/s',
            $exchange,
        );
        self::assertStringNotContainsString(
            'if (!ReadFile(\n                    request,',
            $exchange,
        );
    }

    public function testNativeResultPublicationCannotCommitAfterItsAbsoluteDeadline(): void
    {
        $source = \file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/Native/windows/wls_bounded_command.c',
        );
        self::assertIsString($source);
        $start = \strpos($source, 'static BOOL wls_pipe_publish_result(');
        $end = \strpos(
            $source,
            'static void wls_pipe_delete_failed_transaction_files(',
            (int)$start,
        );
        self::assertIsInt($start);
        self::assertIsInt($end);
        $publish = \substr($source, $start, $end - $start);

        foreach ([
            'ULONGLONG deadline',
            'BOOL *timed_out',
            'response.bin.tmp',
            'result.json.tmp',
            'wls_pipe_remaining_milliseconds(deadline, &remaining)',
            'DeleteFileW(manifest_path)',
        ] as $required) {
            self::assertStringContainsString($required, $publish, $required);
        }
        self::assertMatchesRegularExpression(
            '/MoveFileExW\(\s+response_temp_path,\s+response_path,\s+'
                . 'MOVEFILE_WRITE_THROUGH\s*\)/s',
            $publish,
        );
        self::assertMatchesRegularExpression(
            '/MoveFileExW\(\s+manifest_temp_path,\s+manifest_path,\s+'
                . 'MOVEFILE_WRITE_THROUGH\s*\)/s',
            $publish,
        );

        $cleanup = \substr($source, $end, (int)\strpos(
            $source,
            'static int wls_pipe_exchange(',
            (int)$end,
        ) - $end);
        foreach ([
            'L"request.bin"',
            'L"response.bin.tmp"',
            'L"response.bin"',
            'L"result.json.tmp"',
            'L"result.json"',
        ] as $leaf) {
            self::assertStringContainsString($leaf, $cleanup, $leaf);
        }
        foreach ([
            'synchronous FlushFileBuffers and MoveFileExW calls are not cancellable',
            'parent watchdog contains the standalone helper process',
        ] as $contract) {
            self::assertStringContainsString($contract, $publish);
        }
        $runner = \file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayBoundedCommandRunner.php',
        );
        self::assertIsString($runner);
        self::assertStringContainsString(
            'does not claim that synchronous Windows filesystem calls are cancellable',
            $runner,
        );
    }

    private function responseTimeout(
        GatewayClient $client,
        string $channel,
        string $operation,
    ): float {
        $method = new ReflectionMethod(GatewayClient::class, 'responseTimeoutSeconds');
        return (float)$method->invoke($client, $channel, $operation);
    }
}
