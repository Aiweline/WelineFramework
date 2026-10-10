<?php
declare(strict_types=1);

namespace Weline\Server\Service\Capacity;

use Weline\Server\Console\Server\Doctor;

/**
 * Pre-scale DB / sidecar budget checks (G6–G7). Fail-closed on unknown PG budget.
 */
final class CapacityBudgetGate
{
    public const DEFAULT_FIBER_MAX_ACTIVE = 12;
    public const DEFAULT_RESERVED_CONNECTIONS = 8;

    /**
     * @param array<string, mixed> $env Full env.php-shaped config
     * @return array{
     *   ok:bool,
     *   reason:?string,
     *   details:array<string, mixed>
     * }
     */
    public static function checkScaleUp(
        string $instanceName,
        int $targetWorkers,
        array $env,
        ?int $pgMaxConnections = null,
        int $reservedConnections = self::DEFAULT_RESERVED_CONNECTIONS,
        ?int $sidecarAcceptBudget = null,
        int $clientsPerWorker = 1,
        ?float $msMemoryHeadroomRatio = null,
        float $minMsMemoryHeadroomRatio = 0.10,
    ): array {
        $targetWorkers = \max(1, $targetWorkers);
        $fiberPool = Doctor::resolveFiberVsPoolFromConfig($instanceName, $env);
        $poolSize = (int)$fiberPool['pool_size'];
        $fiberMaxActive = (int)$fiberPool['fiber_max_active'];

        // Gate DB-A: per-process pool must cover fiber max_active; explicit 0 = reject scale.
        if ($fiberMaxActive === 0) {
            return [
                'ok' => false,
                'reason' => 'db_budget',
                'details' => [
                    'gate' => 'db_pool',
                    'fiber_max_active' => 0,
                    'pool_size' => $poolSize,
                    'message' => 'fiber.max_active=0 is unlimited; refuse scale-up without admission_budget_cap',
                ],
            ];
        }
        if (!$fiberPool['ok'] || $poolSize < $fiberMaxActive) {
            return [
                'ok' => false,
                'reason' => 'db_budget',
                'details' => [
                    'gate' => 'db_pool',
                    'fiber_max_active' => $fiberMaxActive,
                    'pool_size' => $poolSize,
                    'doctor_ok' => (bool)$fiberPool['ok'],
                ],
            ];
        }

        // Gate DB-B: cluster connections ≥ Workers × pool_size (+ reserve).
        $pgMax = $pgMaxConnections;
        if ($pgMax === null) {
            $db = \is_array($env['db']['master'] ?? null) ? $env['db']['master'] : [];
            if (isset($db['max_connections']) && \is_numeric($db['max_connections'])) {
                $pgMax = (int)$db['max_connections'];
            }
        }
        if ($pgMax === null || $pgMax <= 0) {
            return [
                'ok' => false,
                'reason' => 'db_budget',
                'details' => [
                    'gate' => 'db_budget_unknown',
                    'target_workers' => $targetWorkers,
                    'pool_size' => $poolSize,
                    'message' => 'PostgreSQL max_connections unknown; fail-closed',
                ],
            ];
        }
        $reserved = \max(0, $reservedConnections);
        $needed = $targetWorkers * $poolSize;
        if (($pgMax - $reserved) < $needed) {
            return [
                'ok' => false,
                'reason' => 'db_budget',
                'details' => [
                    'gate' => 'db_cluster',
                    'pg_max_connections' => $pgMax,
                    'reserved' => $reserved,
                    'needed' => $needed,
                    'target_workers' => $targetWorkers,
                    'pool_size' => $poolSize,
                ],
            ];
        }

        // Sidecar: Session/Memory are singletons — never scale with Workers.
        // Connection budget: if declared, require accept_budget >= W × clients_per_worker.
        if ($sidecarAcceptBudget !== null && $sidecarAcceptBudget < ($targetWorkers * \max(1, $clientsPerWorker))) {
            return [
                'ok' => false,
                'reason' => 'sidecar_budget',
                'details' => [
                    'gate' => 'sidecar_conn',
                    'sidecar_accept_budget' => $sidecarAcceptBudget,
                    'needed' => $targetWorkers * \max(1, $clientsPerWorker),
                ],
            ];
        }
        if ($msMemoryHeadroomRatio !== null && $msMemoryHeadroomRatio < $minMsMemoryHeadroomRatio) {
            return [
                'ok' => false,
                'reason' => 'sidecar_budget',
                'details' => [
                    'gate' => 'sidecar_mem',
                    'ms_memory_headroom_ratio' => $msMemoryHeadroomRatio,
                    'min' => $minMsMemoryHeadroomRatio,
                ],
            ];
        }

        return [
            'ok' => true,
            'reason' => null,
            'details' => [
                'fiber_max_active' => $fiberMaxActive,
                'pool_size' => $poolSize,
                'pg_max_connections' => $pgMax,
                'reserved' => $reserved,
                'needed' => $needed,
                'target_workers' => $targetWorkers,
            ],
        ];
    }
}
