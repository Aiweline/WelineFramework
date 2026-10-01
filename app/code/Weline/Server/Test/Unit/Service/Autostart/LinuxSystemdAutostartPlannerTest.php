<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Autostart;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Autostart\LinuxSystemdAutostartInstaller;
use Weline\Server\Service\Autostart\LinuxSystemdAutostartPlanner;

final class LinuxSystemdAutostartPlannerTest extends TestCase
{
    public function testPlanIsIdempotentAndMarked(): void
    {
        $planner = new LinuxSystemdAutostartPlanner();
        $plan = $planner->plan([
            'project_root' => '/www/wwwroot/demo',
            'php_binary' => '/usr/local/bin/php',
            'run_user' => 'weline',
            'instance' => 'default',
            'project_hash' => 'abcdef0123456789',
            'worker_count' => 2,
            'worker_memory_limit' => '512M',
            'php_memory_limit' => '1024M',
            'use_dispatcher' => true,
            'cli_memory_flag' => '1024M',
        ]);

        self::assertSame('weline-wls-pabcdef01-default', $plan['unit_basename']);
        self::assertStringContainsString(LinuxSystemdAutostartPlanner::MARKER . '=1', $plan['service_body']);
        self::assertStringContainsString(
            LinuxSystemdAutostartPlanner::FINGERPRINT_HEADER . '=' . $plan['fingerprint'],
            $plan['service_body'],
        );
        self::assertStringContainsString('--dispatcher', $plan['script_body']);
        self::assertStringContainsString('--worker-memory-limit=512M', $plan['script_body']);
        self::assertStringContainsString('server:shared:start', $plan['script_body']);
        self::assertTrue($planner->isInstallSatisfied(
            [
                $plan['service_unit'] => $plan['service_body'],
                $plan['health_service_unit'] => $plan['health_service_body'],
                $plan['health_timer_unit'] => $plan['health_timer_body'],
            ],
            $plan['fingerprint'],
            true,
            true,
        ));
        self::assertFalse($planner->isInstallSatisfied(
            [
                $plan['service_unit'] => $plan['service_body'],
            ],
            $plan['fingerprint'],
            true,
            false,
        ));
        self::assertFalse($planner->isInstallSatisfied(
            [
                $plan['service_unit'] => $plan['service_body'],
                $plan['health_service_unit'] => $plan['health_service_body'],
                $plan['health_timer_unit'] => $plan['health_timer_body'],
            ],
            'deadbeef',
            true,
            true,
        ));
    }

    public function testInstallerSkipsNonLinuxAndDisabled(): void
    {
        $darwin = new LinuxSystemdAutostartInstaller(osFamily: 'Darwin');
        self::assertSame('skipped', $darwin->ensure([], 'default', true)['status']);

        $linux = new LinuxSystemdAutostartInstaller(osFamily: 'Linux');
        self::assertSame('skipped', $linux->ensure([], 'default', false)['status']);
    }
}
