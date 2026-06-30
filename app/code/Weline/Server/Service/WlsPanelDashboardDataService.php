<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Model\AttackLog;
use Weline\Server\Model\ReverseProxy;
use Weline\Server\Service\Contract\ServerInstanceInfo;

class WlsPanelDashboardDataService
{
    public function __construct(
        private readonly ReverseProxy $reverseProxy,
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
        $gateway = $this->collectGateway();
        $security = $this->collectSecurity();
        $runtime = $this->collectRuntime();
        $registeredProjects = $this->collectRegisteredProjects();
        $projects = $this->buildProjects($gateway['rules'], $registeredProjects['projects']);

        return [
            'metrics' => [
                'managed_projects' => \count($projects),
                'gateway_rules' => (int)$gateway['total'],
                'security_events' => (int)$security['events_7d'],
            ],
            'projects' => $projects,
            'gateway' => $gateway,
            'security' => $security,
            'runtime' => $runtime,
            'errors' => \array_values(\array_filter([
                $gateway['error'] ?? '',
                $security['error'] ?? '',
                $runtime['error'] ?? '',
                $registeredProjects['error'] ?? '',
            ])),
        ];
    }

    /**
     * @return array{total:int,active:int,inactive:int,rules:array<int,array<string,mixed>>,error:string}
     */
    private function collectGateway(): array
    {
        try {
            $rules = $this->reverseProxy->getAllRules();
        } catch (\Throwable $throwable) {
            return [
                'total' => 0,
                'active' => 0,
                'inactive' => 0,
                'rules' => [],
                'error' => $throwable->getMessage(),
            ];
        }

        $active = 0;
        foreach ($rules as $rule) {
            if ((string)($rule[ReverseProxy::schema_fields_STATUS] ?? '') === ReverseProxy::STATUS_ACTIVE) {
                $active++;
            }
        }

        return [
            'total' => \count($rules),
            'active' => $active,
            'inactive' => \max(0, \count($rules) - $active),
            'rules' => \array_values($rules),
            'error' => '',
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
     * @param array<int, array<string, mixed>> $gatewayRules
     * @param array<int, array<string, mixed>> $registeredProjects
     * @return array<int, array<string, mixed>>
     */
    private function buildProjects(array $gatewayRules, array $registeredProjects): array
    {
        $projects = [
            [
                'type' => 'current',
                'name' => (string)__('Current Project'),
                'domain' => $this->resolveCurrentHost(),
                'status' => (string)__('Local'),
                'path_label' => (string)__('Path'),
                'path' => \defined('BP') ? BP : \dirname(__DIR__, 5),
                'backend' => '',
                'admin' => '',
                'panel' => '',
                'php' => '',
                'db' => '#database-profile',
            ],
        ];

        $registeredDomains = [];
        foreach ($registeredProjects as $project) {
            $card = $this->projectRegistry->projectToCard($project);
            $domain = \strtolower(\trim((string)($card['domain'] ?? '')));
            if ($domain !== '') {
                $registeredDomains[$domain] = true;
            }
            $projects[] = $card;
        }

        foreach ($gatewayRules as $rule) {
            $domain = \trim((string)($rule[ReverseProxy::schema_fields_DOMAIN] ?? ''));
            if ($domain === '') {
                continue;
            }
            if (isset($registeredDomains[\strtolower($domain)])) {
                continue;
            }

            $target = $this->buildBackendTarget($rule);
            $status = (string)($rule[ReverseProxy::schema_fields_STATUS] ?? ReverseProxy::STATUS_INACTIVE);
            $description = \trim((string)($rule[ReverseProxy::schema_fields_DESCRIPTION] ?? ''));

            $projects[] = [
                'type' => 'gateway',
                'name' => $description !== '' ? $description : $domain,
                'domain' => $domain,
                'status' => $status === ReverseProxy::STATUS_ACTIVE ? (string)__('Active') : (string)__('Inactive'),
                'path_label' => (string)__('Upstream'),
                'path' => $target,
                'backend' => $target,
                'admin' => $target,
                'panel' => $target,
                'php' => '',
                'db' => '#database-profile',
            ];
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

    /**
     * @param array<string, mixed> $rule
     */
    private function buildBackendTarget(array $rule): string
    {
        $host = \trim((string)($rule[ReverseProxy::schema_fields_BACKEND_HOST] ?? ''));
        $port = (int)($rule[ReverseProxy::schema_fields_BACKEND_PORT] ?? 0);
        $scheme = (bool)($rule[ReverseProxy::schema_fields_BACKEND_SSL] ?? false) ? 'https' : 'http';

        if ($host === '') {
            return '';
        }

        return $scheme . '://' . $host . ($port > 0 ? ':' . $port : '');
    }
}
