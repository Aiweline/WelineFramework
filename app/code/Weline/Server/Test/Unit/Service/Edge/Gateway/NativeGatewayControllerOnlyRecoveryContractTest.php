<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class NativeGatewayControllerOnlyRecoveryContractTest extends TestCase
{
    private string $posixBroker;
    private string $windowsBroker;
    private string $posixLauncher;
    private string $windowsLauncher;

    protected function setUp(): void
    {
        $native = \dirname(__DIR__, 5)
            . '/Service/Edge/Gateway/Native';
        $this->posixBroker = (string)\file_get_contents(
            $native . '/posix/wls_gateway_broker.c',
        );
        $this->windowsBroker = (string)\file_get_contents(
            $native . '/windows/wls_gateway_broker.c',
        );
        $this->posixLauncher = (string)\file_get_contents(
            $native . '/posix/wls_gateway_launcher.c',
        );
        $this->windowsLauncher = (string)\file_get_contents(
            $native . '/windows/wls_gateway_launcher.c',
        );
    }

    public function testControllerOnlyBootstrapKeepsFiveSecondDataPlaneFenceOnBothPlatforms(): void
    {
        self::assertMatchesRegularExpression(
            '/#define WLS_CONTROLLER_LIVENESS_INTERVAL_MILLISECONDS\s+5000ULL\b/',
            $this->posixBroker,
        );
        self::assertMatchesRegularExpression(
            '/#define WLS_CONTROLLER_LIVENESS_INTERVAL_MS\s+5000ULL\b/',
            $this->windowsBroker,
        );

        $posixBootstrap = $this->functionBody(
            $this->posixBroker,
            'static int wls_bootstrap_controller(',
            'static void wls_destroy_bootstrap_maintenance_context(',
        );
        self::assertGreaterThanOrEqual(3, \substr_count(
            $posixBootstrap,
            'wls_owned_nginx_generation_alive(',
        ));
        self::assertStringContainsString('if (slice > 1000000U)', $posixBootstrap);
        self::assertStringContainsString(
            'return WLS_CONTROLLER_WAIT_DATA_PLANE_DOWN;',
            $posixBootstrap,
        );
        self::assertMatchesRegularExpression(
            '/bootstrap_maintenance_context,\s*WLS_CONTROLLER_IO_TIMEOUT_SECONDS,\s*'
                . 'NULL,\s*NULL,\s*NULL/s',
            $this->posixBroker,
        );
        self::assertMatchesRegularExpression(
            '/bootstrap_maintenance_context,\s*\(int\)\(\s*'
                . 'WLS_CONTROLLER_LIVENESS_INTERVAL_MILLISECONDS\s*\/\s*1000ULL\s*\),\s*'
                . 'home,\s*active_slot,\s*runtime_generation/s',
            $this->posixBroker,
        );

        $windowsBootstrap = $this->functionBody(
            $this->windowsBroker,
            'static int wls_bootstrap_controller(',
            'static DWORD WINAPI wls_bootstrap_maintenance_thread(',
        );
        self::assertGreaterThanOrEqual(2, \substr_count(
            $windowsBootstrap,
            'WaitForSingleObject(nginx_process, 0U)',
        ));
        self::assertStringContainsString(
            'HANDLE wait_handles[2] = {stop_event, nginx_process};',
            $windowsBootstrap,
        );
        self::assertStringContainsString(
            'WaitForMultipleObjects(',
            $windowsBootstrap,
        );
        self::assertStringContainsString(
            'return WLS_CONTROLLER_WAIT_STOPPED;',
            $windowsBootstrap,
        );
        self::assertStringContainsString(
            'return WLS_CONTROLLER_WAIT_DATA_PLANE_DOWN;',
            $windowsBootstrap,
        );
        self::assertMatchesRegularExpression(
            '/&bootstrap_maintenance_context,\s*WLS_ADMIN_CONTROLLER_IO_TIMEOUT_MS,\s*NULL/s',
            $this->windowsBroker,
        );
        self::assertMatchesRegularExpression(
            '/&bootstrap_maintenance_context,\s*\(DWORD\)'
                . 'WLS_CONTROLLER_LIVENESS_INTERVAL_MS,\s*nginx_process/s',
            $this->windowsBroker,
        );
    }

    public function testPosixDataPlaneDownSkipsNormalZeroExitAndRequestsFullTreeRestart(): void
    {
        self::assertStringContainsString(
            '#define WLS_DATA_PLANE_DOWN_EXIT 79',
            $this->posixBroker,
        );
        self::assertStringContainsString(
            '#define WLS_SERVICE_TREE_RESTART 79',
            $this->posixLauncher,
        );
        self::assertGreaterThanOrEqual(
            3,
            \substr_count($this->posixBroker, 'controller_restarted = -2;'),
        );
        self::assertStringContainsString(
            "if (controller_restarted == -2) {\n                    goto cleanup;\n                }",
            $this->posixBroker,
        );
        $dataPlaneJump = \strpos(
            $this->posixBroker,
            'if (controller_restarted == -2)',
        );
        $normalExit = \strpos(
            $this->posixBroker,
            'exit_code = wls_bootstrap_maintenance_failed ? 1 : 0;',
        );
        $cleanup = \strpos($this->posixBroker, "cleanup:\n", $normalExit ?: 0);
        self::assertIsInt($dataPlaneJump);
        self::assertIsInt($normalExit);
        self::assertIsInt($cleanup);
        self::assertLessThan($normalExit, $dataPlaneJump);
        self::assertLessThan($cleanup, $normalExit);
        self::assertStringContainsString(
            'wls_classify_broker_exit(WLS_SERVICE_TREE_RESTART, 1)',
            $this->posixLauncher,
        );
        self::assertMatchesRegularExpression(
            '/wls_classify_broker_exit\(\s*WLS_SERVICE_TREE_RESTART,\s*0,\s*1,\s*0\s*\)'
                . '\s*!=\s*WLS_SERVICE_TREE_RESTART/s',
            $this->windowsLauncher,
        );
    }

    public function testWindowsControllerReadyWaitUsesOneAbsoluteDeadlineAndAllCancellationHandles(): void
    {
        $wait = $this->functionBody(
            $this->windowsBroker,
            'static int wls_wait_for_controller(',
            'static int wls_retire_controller_handle_bounded(',
        );
        self::assertStringContainsString('HANDLE wait_handles[3];', $wait);
        self::assertStringContainsString(
            'started_ms > ULLONG_MAX - 45000ULL',
            $wait,
        );
        self::assertSame(1, \substr_count(
            $wait,
            'deadline_ms = started_ms + 45000ULL;',
        ));
        self::assertStringContainsString(
            'WLS_CONTROLLER_IO_TIMEOUT_MS',
            $wait,
        );
        self::assertSame(2, \substr_count(
            $wait,
            'WaitForMultipleObjects(',
        ));
        self::assertStringContainsString(
            'wait_handles[0] = controller;',
            $wait,
        );
        self::assertStringContainsString(
            'if (stop_event != NULL) wait_handles[wait_count++] = stop_event;',
            $wait,
        );
        self::assertStringContainsString(
            'if (nginx_process != NULL) wait_handles[wait_count++] = nginx_process;',
            $wait,
        );
        self::assertStringContainsString(
            'return WLS_CONTROLLER_WAIT_STOPPED;',
            $wait,
        );
        self::assertStringContainsString(
            'return WLS_CONTROLLER_WAIT_DATA_PLANE_DOWN;',
            $wait,
        );
        self::assertMatchesRegularExpression(
            '/controller_process,\s*home,\s*fencing,\s*stop_event,\s*NULL/s',
            $this->windowsBroker,
        );
        self::assertMatchesRegularExpression(
            '/controller_process,\s*home,\s*fencing,\s*stop_event,\s*nginx_process/s',
            $this->windowsBroker,
        );
    }

    public function testWindowsRecoveryRetainsVerifiedHandlesAndRequiresStableReadyWindow(): void
    {
        $reopen = $this->functionBody(
            $this->windowsBroker,
            'static int wls_reopen_owned_nginx(',
            'static HANDLE wls_start_controller(',
        );
        $this->assertNeedlesInOrder($reopen, [
            'verified_process = wls_open_owned_nginx(',
            'if (verified_process == NULL || verified_pid != nginx_pid)',
            'CloseHandle(*current_process);',
            '*current_process = verified_process;',
        ]);

        $retire = $this->functionBody(
            $this->windowsBroker,
            'static int wls_retire_controller_handle_bounded(',
            'static void wls_wake_pipe(',
        );
        $this->assertNeedlesInOrder($retire, [
            'TerminateProcess(*controller, exit_code)',
            'WaitForSingleObject(*controller, 5000U)',
            'if (wait != WAIT_OBJECT_0) return 1;',
            'CloseHandle(*controller);',
            '*controller = NULL;',
        ]);
        self::assertStringContainsString(
            'wls_retire_controller_handle_bounded(',
            $this->windowsBroker,
        );
        self::assertStringContainsString(
            'controller_ready_since_ms = 0ULL;',
            $this->windowsBroker,
        );
        self::assertStringContainsString(
            'controller_ready_since_ms = GetTickCount64();',
            $this->windowsBroker,
        );
        self::assertStringContainsString(
            'probe_now_ms - controller_ready_since_ms',
            $this->windowsBroker,
        );
        self::assertStringContainsString(
            '>= WLS_CONTROLLER_RESTART_STABLE_MS',
            $this->windowsBroker,
        );
        self::assertStringNotContainsString(
            'controller_last_restart_ms',
            $this->windowsBroker,
        );
        $ready = \strpos(
            $this->windowsBroker,
            'controller_ready_since_ms = GetTickCount64();',
        );
        $restarted = \strpos(
            $this->windowsBroker,
            'controller_restarted = 1;',
            $ready ?: 0,
        );
        self::assertIsInt($ready);
        self::assertIsInt($restarted);
        self::assertStringNotContainsString(
            'controller_restart_streak = 0U;',
            \substr($this->windowsBroker, $ready, $restarted - $ready),
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
