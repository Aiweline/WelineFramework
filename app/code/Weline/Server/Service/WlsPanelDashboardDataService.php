<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Model\AttackLog;
use Weline\Server\Service\Contract\ServerInstanceInfo;

class WlsPanelDashboardDataService
{
    public function __construct(
        private readonly AttackLog $attackLog,
        private readonly ServerInstanceManager $instanceManager,
        private readonly WlsPanelProjectRegistryService $projectRegistry
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardData(): array
    {
        $security = $this->collectSecurity();
        $runtime = $this->collectRuntime();
        $registeredProjects = $this->collectRegisteredProjects();
        $projects = $this->buildProjects($registeredProjects['projects']);

        return [
            'metrics' => [
                'managed_projects' => \count($projects),
                'security_events' => (int)$security['events_7d'],
            ],
            'projects' => $projects,
            'security' => $security,
            'runtime' => $runtime,
            'errors' => \array_values(\array_filter([
                $security['error'] ?? '',
                $runtime['error'] ?? '',
                $registeredProjects['error'] ?? '',
            ])),
        ];
    }

    /**
     * @return array{events_7d:int,blocked_7d:int,critical_7d:int,error:string}
     */
    private function collectSecurity(): array
    {
        try {
            $stats = $this->attackLog->getStatistics('', 7);
        } catch (\Throwable $throwable) {
            return [
                'events_7d' => 0,
                'blocked_7d' => 0,
                'critical_7d' => 0,
                'error' => $throwable->getMessage(),
            ];
        }

        $severity = \is_array($stats['by_severity'] ?? null) ? $stats['by_severity'] : [];

        return [
            'events_7d' => (int)($stats['total_attacks'] ?? 0),
            'blocked_7d' => (int)($stats['blocked_attacks'] ?? 0),
            'critical_7d' => (int)($severity[AttackLog::SEVERITY_CRITICAL] ?? 0),
            'error' => '',
        ];
    }

    /**
     * @return array{instances:int,running_instances:int,workers:int,dispatchers:int,ports:array<int,int>,error:string}
     */
    private function collectRuntime(): array
    {
        try {
            $instances = $this->instanceManager->getAllPersistedInstanceInfo();
        } catch (\Throwable $throwable) {
            return [
                'instances' => 0,
                'running_instances' => 0,
                'workers' => 0,
                'dispatchers' => 0,
                'ports' => [],
                'error' => $throwable->getMessage(),
            ];
        }

        $runningInstances = 0;
        $workers = 0;
        $dispatchers = 0;
        $ports = [];

        foreach ($instances as $instance) {
            if (!$instance instanceof ServerInstanceInfo) {
                continue;
            }

            $stats = $this->instanceManager->getRuntimeStatsForInstance($instance);
            if ((bool)($stats['instance_running'] ?? false)) {
                $runningInstances++;
            }
            $workers += (int)($stats['workers'] ?? 0);
            $dispatchers += (int)($stats['dispatchers'] ?? 0);
            foreach (($stats['ports'] ?? []) as $port) {
                $port = (int)$port;
                if ($port > 0) {
                    $ports[$port] = $port;
                }
            }
        }

        \sort($ports);

        return [
            'instances' => \count($instances),
            'running_instances' => $runningInstances,
            'workers' => $workers,
            'dispatchers' => $dispatchers,
            'ports' => \array_values($ports),
            'error' => '',
        ];
    }

    /**
     * @return array{projects:array<int,array<string,mixed>>,error:string}
     */
    private function collectRegisteredProjects(): array
    {
        try {
            return [
                'projects' => $this->projectRegistry->getProjects(),
                'error' => '',
            ];
        } catch (\Throwable $throwable) {
            return [
                'projects' => [],
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $registeredProjects
     * @return array<int, array<string, mixed>>
     */
    private function buildProjects(array $registeredProjects): array
    {
        $projects = [
            [
                'type' => 'current',
                'name' => (string)__('Current Project'),
                'domain' => $this->resolveCurrentHost(),
                'status' => (string)__('Local'),
                'path_label' => (string)__('Path'),
                'path' => \defined('BP') ? BP : \dirname(__DIR__, 5),
                'admin' => '',
                'panel' => '',
                'php' => '',
                'db' => '#database-profile',
            ],
        ];

        foreach ($registeredProjects as $project) {
            $card = $this->projectRegistry->projectToCard($project);
            $projects[] = $card;
        }

        return $projects;
    }

    private function resolveCurrentHost(): string
    {
        $host = \trim((string)(\function_exists('w_env') ? \w_env('server.http_host', '') : ''));
        if ($host === '') {
            $host = \trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        }

        return $host !== '' ? $host : 'localhost';
    }
}
