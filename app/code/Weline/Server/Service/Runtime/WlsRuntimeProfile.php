<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final class WlsRuntimeProfile
{
    /**
     * @param array<string, mixed> $data
     * @param array<int, array{level:string,code:string,message:string,action?:string}> $findings
     */
    public function __construct(
        private readonly array $data,
        private readonly array $findings = []
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function osFamily(): string
    {
        return (string) $this->get('os_family', PHP_OS_FAMILY);
    }

    public function isWindows(): bool
    {
        return $this->osFamily() === 'Windows';
    }

    public function isLinux(): bool
    {
        return $this->osFamily() === 'Linux';
    }

    public function isDarwin(): bool
    {
        return $this->osFamily() === 'Darwin';
    }

    public function cpuCores(): int
    {
        return \max(1, (int) $this->get('cpu_cores', 4));
    }

    public function physicalCpuCores(): int
    {
        $physical = \max(1, (int)$this->get('cpu_physical_cores', $this->cpuCores()));
        return \min($this->cpuCores(), $physical);
    }

    public function performanceCpuCores(): int
    {
        $performance = \max(1, (int)$this->get('cpu_performance_cores', $this->physicalCpuCores()));
        return \min($this->physicalCpuCores(), $performance);
    }

    public function cpuTopologySource(): string
    {
        $source = \trim((string)$this->get('cpu_topology_source', ''));
        return $source !== '' ? $source : 'logical_cpu';
    }

    public function memoryMb(): ?int
    {
        $memory = $this->get('memory_mb');
        return \is_int($memory) && $memory > 0 ? $memory : null;
    }

    public function hasExtension(string $name): bool
    {
        $extensions = $this->get('extensions', []);
        return \is_array($extensions) && !empty($extensions[$name]);
    }

    public function hasFunction(string $name): bool
    {
        $functions = $this->get('functions', []);
        return \is_array($functions) && !empty($functions[$name]);
    }

    public function hasWindowsTool(string $name): bool
    {
        $tools = $this->get('windows_tools', []);
        return \is_array($tools) && !empty($tools[$name]);
    }

    public function supportsReusePort(): bool
    {
        return (bool) $this->get('supports_reuse_port', false);
    }

    public function supportsDirectListener(): bool
    {
        return (bool)$this->get('supports_direct_listener', $this->supportsReusePort());
    }

    public function directListenerMode(): string
    {
        $mode = \strtolower(\trim((string)$this->get('direct_listener_mode', '')));
        if (\in_array($mode, ['reuseport', 'shared_fd'], true)) {
            return $mode;
        }
        if ($this->isDarwin()) {
            return 'shared_fd';
        }

        return $this->supportsReusePort() ? 'reuseport' : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function directListenerProbe(): array
    {
        $probe = $this->get('direct_listener_probe', []);

        return \is_array($probe) ? $probe : [];
    }

    /**
     * @return array{supported?:bool,host?:string,family?:string,reason?:string,error_code?:int}
     */
    public function reusePortProbe(): array
    {
        $probe = $this->get('reuse_port_probe', []);

        return \is_array($probe) ? $probe : [];
    }

    public function canUseEventLoop(): bool
    {
        return $this->hasExtension('event')
            && (bool) $this->get('event_classes_available', false);
    }

    public function canControlProcesses(): bool
    {
        return $this->hasFunction('proc_open') || $this->hasFunction('exec') || $this->hasFunction('popen');
    }

    /**
     * @return array<int, array{level:string,code:string,message:string,action?:string}>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data + ['findings' => $this->findings];
    }
}
