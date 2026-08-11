<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayBootstrapDeadlineContractTest extends TestCase
{
    private string $posix;
    private string $windows;
    private string $controller;

    protected function setUp(): void
    {
        $server = \dirname(__DIR__, 5);
        $native = $server . '/Service/Edge/Gateway/Native';
        $this->posix = (string)\file_get_contents(
            $native . '/posix/wls_gateway_broker.c',
        );
        $this->windows = (string)\file_get_contents(
            $native . '/windows/wls_gateway_broker.c',
        );
        $this->controller = (string)\file_get_contents(
            $server . '/bin/wls_gateway_controller.php',
        );
    }

    public function testBootstrapWireBudgetContainsTheControllerBudgetOnBothPlatforms(): void
    {
        self::assertMatchesRegularExpression(
            '/#define WLS_CONTROLLER_IO_TIMEOUT_SECONDS\s+90\b/',
            $this->posix,
        );
        self::assertMatchesRegularExpression(
            '/#define WLS_MAINTENANCE_CONTROLLER_IO_TIMEOUT_SECONDS\s+\\\\\s*'
                . 'WLS_CONTROLLER_IO_TIMEOUT_SECONDS/',
            $this->posix,
        );
        self::assertMatchesRegularExpression(
            '/#define WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS\s+90000U\b/',
            $this->windows,
        );
        self::assertMatchesRegularExpression(
            '/#define WLS_MAINTENANCE_CONTROLLER_IO_TIMEOUT_MS\s+\\\\\s*'
                . 'WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS/',
            $this->windows,
        );
        self::assertMatchesRegularExpression(
            '/private const BOOTSTRAP_RECOVERY_BUDGET_SECONDS = 85\.0;/',
            $this->controller,
        );
    }

    public function testActionCapCoversTheRouteQuotaAndFailsDeterministically(): void
    {
        self::assertMatchesRegularExpression(
            '/private const MAX_TOTAL_ROUTES = 2048;/',
            $this->controller,
        );
        self::assertStringContainsString(
            "\\strlen(\$line) > 65_536",
            $this->controller,
        );
        foreach ([$this->posix, $this->windows] as $source) {
            self::assertMatchesRegularExpression(
                '/#define WLS_BOOTSTRAP_MAX_ACTIONS\s+4096U\b/',
                $source,
            );
            self::assertStringContainsString(
                '#define WLS_BOOTSTRAP_ACTION_LIMIT_EXCEEDED 3',
                $source,
            );
            $bootstrap = $this->functionBody(
                $source,
                'static int wls_bootstrap_once(',
                'static int wls_bootstrap_lock_until(',
            );
            self::assertStringContainsString(
                'action_count >= WLS_BOOTSTRAP_MAX_ACTIONS',
                $bootstrap,
            );
            self::assertStringContainsString(
                'result = WLS_BOOTSTRAP_ACTION_LIMIT_EXCEEDED;',
                $bootstrap,
            );
        }
    }

    public function testPosixBootstrapUsesOneDeadlineAcrossLockAndEveryWireStep(): void
    {
        $serialized = $this->functionBody(
            $this->posix,
            'static int wls_bootstrap_once_serialized(',
            'static int wls_bootstrap_health_record(',
        );
        $bootstrap = $this->functionBody(
            $this->posix,
            'static int wls_bootstrap_once(',
            'static int wls_bootstrap_lock_until(',
        );
        $read = $this->functionBody(
            $this->posix,
            'static ssize_t wls_bootstrap_read_line_until(',
            'static int wls_bootstrap_write_all_until(',
        );
        $write = $this->functionBody(
            $this->posix,
            'static int wls_bootstrap_write_all_until(',
            'static int wls_authenticate_controller_until(',
        );

        $this->assertNeedlesInOrder($serialized, [
            'wls_monotonic_milliseconds(&now_ms)',
            'absolute_deadline_ms = now_ms + timeout_ms;',
            'wls_bootstrap_lock_until(absolute_deadline_ms)',
            'wls_bootstrap_once(',
            'absolute_deadline_ms,',
            'pthread_mutex_unlock(&wls_bootstrap_mutex)',
        ]);
        self::assertStringContainsString('absolute_deadline_ms', $bootstrap);
        self::assertStringContainsString('wls_connect_controller_until(', $bootstrap);
        self::assertSame(3, \substr_count(
            $bootstrap,
            'wls_bootstrap_write_all_until(',
        ));
        self::assertStringContainsString('wls_bootstrap_read_line_until(', $bootstrap);
        self::assertStringNotContainsString('wls_write_all(', $bootstrap);
        self::assertStringContainsString('MSG_PEEK', $read);
        self::assertStringContainsString(
            'wls_bootstrap_socket_timeout_until(fd, deadline_ms)',
            $read,
        );
        self::assertStringContainsString('wls_bootstrap_read_exact_until(', $read);
        self::assertStringContainsString(
            'wls_bootstrap_socket_timeout_until(fd, deadline_ms)',
            $write,
        );
    }

    public function testWindowsBootstrapUsesOneDeadlineAndStopFenceAcrossWireSteps(): void
    {
        $serialized = $this->functionBody(
            $this->windows,
            'static int wls_bootstrap_once_serialized(',
            'static int wls_bootstrap_controller(',
        );
        $bootstrap = $this->functionBody(
            $this->windows,
            'static int wls_bootstrap_once(',
            'static int wls_bootstrap_lock_until(',
        );
        $deadlineIo = $this->functionBody(
            $this->windows,
            'static int wls_bootstrap_socket_timeout_until(',
            'static void wls_hex_encode_fixed(',
        );

        $this->assertNeedlesInOrder($serialized, [
            'wls_protocol_monotonic_milliseconds(&now_ms)',
            'absolute_deadline_ms = now_ms + (ULONGLONG)io_timeout_ms;',
            'wls_bootstrap_lock_until(absolute_deadline_ms, stop_event)',
            'wls_bootstrap_once(',
            'absolute_deadline_ms,',
            'stop_event,',
            'ReleaseSRWLockExclusive(&wls_bootstrap_lock)',
        ]);
        self::assertStringContainsString('wls_connect_controller_until(', $bootstrap);
        self::assertSame(3, \substr_count(
            $bootstrap,
            'wls_bootstrap_socket_write_all_until(',
        ));
        self::assertStringContainsString(
            'wls_bootstrap_socket_read_line_until(',
            $bootstrap,
        );
        self::assertStringNotContainsString('wls_socket_write_all(', $bootstrap);
        self::assertStringContainsString(
            'WaitForSingleObject(stop_event, 0U)',
            $deadlineIo,
        );
        self::assertGreaterThanOrEqual(
            3,
            \substr_count($deadlineIo, 'wls_bootstrap_socket_timeout_until('),
        );
    }

    private function functionBody(
        string $source,
        string $startNeedle,
        string $endNeedle,
    ): string {
        $start = \strpos($source, $startNeedle);
        self::assertIsInt($start, 'Missing function start: ' . $startNeedle);
        $end = \strpos($source, $endNeedle, $start + \strlen($startNeedle));
        self::assertIsInt($end, 'Missing function end: ' . $endNeedle);
        return \substr($source, $start, $end - $start);
    }

    /** @param list<string> $needles */
    private function assertNeedlesInOrder(string $source, array $needles): void
    {
        $offset = 0;
        foreach ($needles as $needle) {
            $position = \strpos($source, $needle, $offset);
            self::assertIsInt($position, 'Missing ordered contract: ' . $needle);
            $offset = $position + \strlen($needle);
        }
    }
}
