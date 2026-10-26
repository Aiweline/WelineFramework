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
            'health_port' => 9981,
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
        // 参数必须按位置传给内层 bash；拼进 -c "…" 字符串会把 -n/--dispatcher 打坏（systemd exit 2）
        self::assertStringContainsString(
            '/bin/bash -c \'cd "$0" || exit 1; exec "$@"\'',
            $plan['script_body'],
        );
        self::assertStringNotContainsString('bin/w \"$@\"" _', $plan['script_body']);
        self::assertStringContainsString('Must probe WLS', $plan['health_script_body']);
        self::assertStringContainsString('has_wls_marker', $plan['health_script_body']);
        // 端口必须来自解析后的启动配置，不得再写死 9510（曾导致 health 永远探空端口）
        self::assertStringContainsString('PROBE_PORT=9981', $plan['health_script_body']);
        self::assertStringNotContainsString('9510', $plan['health_script_body']);
        self::assertStringNotContainsString('probe "http://127.0.0.1/"', $plan['health_script_body']);
        // 自愈必须交给 systemd 拉起服务单元，不能在本 oneshot 单元的 cgroup 里 exec 启动脚本
        self::assertStringContainsString(
            "SERVICE_UNIT='weline-wls-pabcdef01-default.service'",
            $plan['health_script_body'],
        );
        self::assertStringContainsString('systemctl start "$SERVICE_UNIT"', $plan['health_script_body']);
        self::assertStringContainsString('exec "$START" start', $plan['health_script_body']);
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

    public function testPlanRejectsMissingHealthPort(): void
    {
        $planner = new LinuxSystemdAutostartPlanner();
        $spec = [
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
        ];

        foreach ([null, 0, 70000] as $invalidPort) {
            $candidate = $spec;
            if ($invalidPort !== null) {
                $candidate['health_port'] = $invalidPort;
            }
            try {
                $planner->plan($candidate);
                self::fail('health_port must be required (invalid: ' . var_export($invalidPort, true) . ')');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFingerprintCoversHealthPort(): void
    {
        $base = [
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
        ];
        $planner = new LinuxSystemdAutostartPlanner();
        $a = $planner->plan($base + ['health_port' => 9981]);
        $b = $planner->plan($base + ['health_port' => 9982]);
        $c = $planner->plan($base + ['health_port' => 9981]);

        self::assertNotSame($a['fingerprint'], $b['fingerprint'], 'health_port must affect fingerprint');
        self::assertSame($a['fingerprint'], $c['fingerprint'], 'fingerprint must be idempotent');
    }

    public function testInstallerSkipsNonLinuxAndDisabled(): void
    {
        $darwin = new LinuxSystemdAutostartInstaller(osFamily: 'Darwin');
        self::assertSame('skipped', $darwin->ensure([], 'default', true)['status']);

        $linux = new LinuxSystemdAutostartInstaller(osFamily: 'Linux');
        self::assertSame('skipped', $linux->ensure([], 'default', false)['status']);
    }
}
