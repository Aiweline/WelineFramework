<?php
declare(strict_types=1);

namespace Weline\Server\Service\Runtime;

final class RuntimeDiagnosticsFormatter
{
    /**
     * @param array<string, mixed> $strategy
     * @return string[]
     */
    public function formatStartupSummary(WlsRuntimeProfile $profile, array $strategy): array
    {
        $lines = [
            'WLS runtime strategy: ' . ($strategy['runtime_strategy'] ?? 'auto') . ' (' . ($strategy['status'] ?? 'degraded') . ')',
            'Topology: ' . ($strategy['topology'] ?? 'unknown') . ' - ' . ($strategy['topology_reason'] ?? ''),
            'Event loop: ' . ($strategy['event_loop_driver'] ?? 'auto') . ' - ' . ($strategy['event_loop_reason'] ?? ''),
            'Workers: ' . ($strategy['worker_count'] ?? '?') . ' - ' . ($strategy['worker_count_reason'] ?? ''),
            'Supervisor: ' . (!empty($strategy['supervisor_enabled']) ? 'enabled' : 'disabled') . ' - ' . ($strategy['supervisor_reason'] ?? ''),
        ];

        foreach (($strategy['warnings'] ?? []) as $warning) {
            if (\is_string($warning) && $warning !== '') {
                $lines[] = 'Warning: ' . $warning;
            }
        }
        foreach ($profile->findings() as $finding) {
            $lines[] = \strtoupper($finding['level']) . ': ' . $finding['message']
                . (isset($finding['action']) ? ' Action: ' . $finding['action'] : '');
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $strategy
     * @return array<string, mixed>
     */
    public function toDiagnosticArray(WlsRuntimeProfile $profile, array $strategy = []): array
    {
        return [
            'status' => $strategy['status'] ?? 'diagnostic',
            'strategy' => $strategy,
            'profile' => $profile->toArray(),
            'recommendations' => $profile->findings(),
        ];
    }
}
