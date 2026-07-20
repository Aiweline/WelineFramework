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
        $selection = $this->resolveSelection($strategy);
        $topology = $selection instanceof RuntimeSelection
            ? $selection->requestedTopology->value . ' -> ' . $selection->effectiveTopology->value
            : 'unknown';
        $reason = $selection?->reason ?? '';
        $listener = $selection?->listenerMode ?? 'unknown';
        $eventLoop = $selection?->eventLoopDriver ?? 'unknown';

        $lines = [
            'WLS runtime strategy: ' . ($strategy['runtime_strategy'] ?? 'auto') . ' (' . ($strategy['status'] ?? 'degraded') . ')',
            'Topology: ' . $topology . ($reason !== '' ? ' - ' . $reason : ''),
            'Listener: ' . $listener,
            'Event loop: ' . $eventLoop . ' - ' . ($strategy['event_loop_reason'] ?? ''),
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
        $normalized = $strategy;
        if (($normalized['runtime_selection'] ?? null) instanceof RuntimeSelection) {
            $normalized['runtime_selection'] = $normalized['runtime_selection']->toArray();
        }

        return [
            'status' => $normalized['status'] ?? 'diagnostic',
            'strategy' => $normalized,
            'profile' => $profile->toArray(),
            'recommendations' => $profile->findings(),
        ];
    }

    /**
     * @param array<string, mixed> $strategy
     */
    private function resolveSelection(array $strategy): ?RuntimeSelection
    {
        $selection = $strategy['runtime_selection'] ?? null;
        if ($selection instanceof RuntimeSelection) {
            return $selection;
        }
        if (\is_array($selection)) {
            return RuntimeSelection::fromArray($selection);
        }

        return null;
    }
}
