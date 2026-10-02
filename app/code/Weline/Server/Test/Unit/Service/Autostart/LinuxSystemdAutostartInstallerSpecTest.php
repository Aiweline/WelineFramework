<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Autostart;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Autostart\LinuxSystemdAutostartInstaller;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\Runtime\RequestedTopology;
use Weline\Server\Service\Runtime\RuntimeSelection;

/**
 * systemd 自启单元必须跟随**已解析的运行时拓扑**，而不是 env 里的
 * `wls.dispatcher` 调优数组（该数组恒非空，曾让自启单元恒定带 --dispatcher）。
 */
final class LinuxSystemdAutostartInstallerSpecTest extends TestCase
{
    public function testDispatcherTuningArrayDoesNotSelectDispatcherTopology(): void
    {
        $spec = $this->buildSpec([
            'worker_count' => 2,
            'worker_memory_limit' => '512M',
            'dispatcher' => [
                'max_accept_per_loop' => 16,
                'worker_connect_select_timeout_sec' => 0.02,
                'homepage_warmup_enabled' => false,
            ],
        ]);

        self::assertFalse($spec['use_dispatcher']);
    }

    public function testResolvedDispatcherSelectionSelectsDispatcherTopology(): void
    {
        $spec = $this->buildSpec([
            'dispatcher' => ['max_accept_per_loop' => 16],
            'runtime_selection' => $this->selection(EffectiveTopology::Dispatcher),
        ]);

        self::assertTrue($spec['use_dispatcher']);
    }

    public function testResolvedDirectSelectionWinsOverLegacyDispatcherFlag(): void
    {
        $spec = $this->buildSpec([
            'dispatcher' => true,
            'runtime_selection' => $this->selection(EffectiveTopology::Direct),
        ]);

        self::assertFalse($spec['use_dispatcher']);
    }

    public function testExplicitCliDispatcherMarkerSelectsDispatcherTopology(): void
    {
        $spec = $this->buildSpec([
            'dispatcher' => ['max_accept_per_loop' => 16],
            '_cli_dispatcher' => true,
        ]);

        self::assertTrue($spec['use_dispatcher']);
    }

    public function testConfiguredRuntimeTopologyDispatcherSelectsDispatcherTopology(): void
    {
        $spec = $this->buildSpec([
            'dispatcher' => ['max_accept_per_loop' => 16],
            'runtime' => ['topology' => 'dispatcher'],
        ]);

        self::assertTrue($spec['use_dispatcher']);
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function buildSpec(array $config): array
    {
        $installer = new LinuxSystemdAutostartInstaller();
        $method = new \ReflectionMethod($installer, 'buildSpec');
        $method->setAccessible(true);

        /** @var array<string,mixed> $spec */
        $spec = $method->invoke($installer, $config, 'default');

        return $spec;
    }

    private function selection(EffectiveTopology $effective): RuntimeSelection
    {
        return new RuntimeSelection(
            requestedTopology: RequestedTopology::Auto,
            effectiveTopology: $effective,
            source: 'test',
            osFamily: 'Linux',
            eventLoopDriver: 'event',
            sslEngine: 'none',
            listenerMode: $effective->isDirect() ? 'shared_fd' : 'single',
            policyCompatible: true,
            reasonCodes: ['test'],
            reason: 'test',
        );
    }
}
