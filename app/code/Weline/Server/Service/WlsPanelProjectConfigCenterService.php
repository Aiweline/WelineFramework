<?php
declare(strict_types=1);

namespace Weline\Server\Service;

class WlsPanelProjectConfigCenterService
{
    private const OPERATION_KEYS = [
        'php-profile',
        'database-profile',
        'file-manager',
        'deploy',
    ];

    /**
     * @param array<int, array<string, mixed>> $projects
     * @param array<string, mixed> $operationCapabilities
     * @return array<string, mixed>
     */
    public function build(array $projects, array $operationCapabilities): array
    {
        $operationMap = $this->buildOperationMap($operationCapabilities);
        $items = [];

        foreach ($projects as $project) {
            if (!\is_array($project)) {
                continue;
            }
            $items[] = $this->buildProjectItem($project, $operationMap);
        }

        $readinessSummary = $this->buildReadinessSummary($items);

        return [
            'items' => $items,
            'count' => \count($items),
            'installed_operation_count' => (int)($operationCapabilities['installed_count'] ?? 0),
            'missing_operation_count' => (int)($operationCapabilities['missing_count'] ?? 0),
            'readiness_summary' => $readinessSummary,
            'ready_project_count' => (int)$readinessSummary['ready_project_count'],
            'attention_project_count' => (int)$readinessSummary['attention_project_count'],
            'blocked_project_count' => (int)$readinessSummary['blocked_project_count'],
        ];
    }

    /**
     * @param array<string, mixed> $operationCapabilities
     * @return array<string, array<string, mixed>>
     */
    private function buildOperationMap(array $operationCapabilities): array
    {
        $map = [];
        $items = \is_array($operationCapabilities['items'] ?? null)
            ? \array_values($operationCapabilities['items'])
            : [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $key = \trim((string)($item['key'] ?? ''));
            if ($key !== '') {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, array<string, mixed>> $operationMap
     * @return array<string, mixed>
     */
    private function buildProjectItem(array $project, array $operationMap): array
    {
        $type = \trim((string)($project['type'] ?? 'project'));
        $domain = $this->normalizeDomain((string)($project['domain'] ?? ''));
        $path = \trim((string)($project['path'] ?? ''));
        $adminUrl = \trim((string)($project['admin'] ?? ''));
        $panelUrl = \trim((string)($project['panel'] ?? ''));
        $gatewayEnabled = (int)($project['gateway_enabled'] ?? ($type === 'gateway' ? 1 : 0)) === 1;
        $securityPolicyReady = $this->isSecurityPolicyReady($project, $domain);
        $pathReady = $path !== '';
        $adminReady = $adminUrl !== '' || $type === 'current';
        $panelReady = $panelUrl !== '' || $type === 'current';
        $operations = $this->buildProjectOperations($project, $operationMap);

        return [
            'key' => $this->projectKey($project),
            'project' => $project,
            'security_scope' => $this->resolveSecurityScope($project, $domain),
            'security_policy_ready' => $securityPolicyReady,
            'safe_context_label' => $this->buildSafeContextLabel($project, $domain),
            'type_label' => $this->resolveTypeLabel($type),
            'identity' => $domain !== '' ? $domain : (string)__('Local project'),
            'path_ready' => $pathReady,
            'admin_ready' => $adminReady,
            'panel_ready' => $panelReady,
            'gateway_ready' => $gatewayEnabled,
            'gateway_label' => $gatewayEnabled ? (string)__('Gateway enabled') : (string)__('Gateway disabled'),
            'operations' => $operations,
            'readiness' => $this->buildProjectReadiness(
                $adminReady,
                $panelReady,
                $pathReady,
                $securityPolicyReady,
                $gatewayEnabled,
                $operations
            ),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function buildReadinessSummary(array $items): array
    {
        $readyProjectCount = 0;
        $attentionProjectCount = 0;
        $blockedProjectCount = 0;
        $readyOperationCount = 0;
        $totalOperationCount = 0;

        foreach ($items as $item) {
            $readiness = \is_array($item['readiness'] ?? null) ? $item['readiness'] : [];
            $state = (string)($readiness['state'] ?? 'attention');
            if ($state === 'ready') {
                $readyProjectCount++;
            } elseif ($state === 'blocked') {
                $blockedProjectCount++;
            } else {
                $attentionProjectCount++;
            }

            $readyOperationCount += (int)($readiness['ready_operation_count'] ?? 0);
            $totalOperationCount += (int)($readiness['total_operation_count'] ?? 0);
        }

        $state = 'ready';
        if ($blockedProjectCount > 0) {
            $state = 'blocked';
        } elseif ($attentionProjectCount > 0) {
            $state = 'attention';
        }

        return [
            'state' => $state,
            'label' => $this->readinessStateLabel($state),
            'summary' => $this->readinessSummaryText($state, $readyProjectCount, \count($items)),
            'ready_project_count' => $readyProjectCount,
            'attention_project_count' => $attentionProjectCount,
            'blocked_project_count' => $blockedProjectCount,
            'ready_operation_count' => $readyOperationCount,
            'total_operation_count' => $totalOperationCount,
            'missing_operation_count' => \max(0, $totalOperationCount - $readyOperationCount),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     * @return array<string, mixed>
     */
    private function buildProjectReadiness(
        bool $adminReady,
        bool $panelReady,
        bool $pathReady,
        bool $securityPolicyReady,
        bool $gatewayEnabled,
        array $operations
    ): array {
        $coreReadyCount = $this->countTrue([$adminReady, $panelReady, $pathReady, $securityPolicyReady]);
        $coreTotalCount = 4;
        $operationReadyCount = $this->countReadyOperations($operations);
        $operationTotalCount = \count($operations);
        $missingOperationLabels = $this->missingOperationLabels($operations);
        $coreMissingCount = \max(0, $coreTotalCount - $coreReadyCount);
        $missingOperationCount = \max(0, $operationTotalCount - $operationReadyCount);

        $state = 'ready';
        if ($coreMissingCount > 1 || (!$adminReady && !$panelReady)) {
            $state = 'blocked';
        } elseif ($coreMissingCount > 0 || $missingOperationCount > 0) {
            $state = 'attention';
        }

        return [
            'state' => $state,
            'label' => $this->readinessStateLabel($state),
            'summary' => $this->projectReadinessSummary($state, $coreMissingCount, $missingOperationCount),
            'score_label' => (string)__('%{1} / %{2} checks ready', [
                $coreReadyCount + $operationReadyCount,
                $coreTotalCount + $operationTotalCount,
            ]),
            'core_ready_count' => $coreReadyCount,
            'core_total_count' => $coreTotalCount,
            'ready_operation_count' => $operationReadyCount,
            'total_operation_count' => $operationTotalCount,
            'missing_operation_count' => $missingOperationCount,
            'missing_operation_labels' => $missingOperationLabels,
            'checks' => [
                [
                    'key' => 'core-links',
                    'state' => $coreReadyCount === $coreTotalCount ? 'ready' : 'attention',
                    'label' => (string)__('Core Links'),
                    'value' => (string)__('%{1} / %{2}', [$coreReadyCount, $coreTotalCount]),
                    'summary' => $coreReadyCount === $coreTotalCount
                        ? (string)__('Admin, panel, path, and security scope are ready.')
                        : (string)__('Some core project context still needs setup.'),
                ],
                [
                    'key' => 'operation-slots',
                    'state' => $missingOperationCount === 0 ? 'ready' : 'attention',
                    'label' => (string)__('Operation Slots'),
                    'value' => (string)__('%{1} / %{2}', [$operationReadyCount, $operationTotalCount]),
                    'summary' => $missingOperationCount === 0
                        ? (string)__('All WLS operation plugins are installed.')
                        : (string)__('%{1} WLS operation plugins need installation.', [$missingOperationCount]),
                ],
                [
                    'key' => 'security-scope',
                    'state' => $securityPolicyReady ? 'ready' : 'attention',
                    'label' => (string)__('Security Scope'),
                    'value' => $securityPolicyReady ? (string)__('Ready') : (string)__('Review'),
                    'summary' => $securityPolicyReady
                        ? (string)__('Policy and attack-log links can be scoped safely.')
                        : (string)__('Security links need project identity first.'),
                ],
                [
                    'key' => 'gateway-mode',
                    'state' => $gatewayEnabled ? 'ready' : 'neutral',
                    'label' => (string)__('Gateway Mode'),
                    'value' => $gatewayEnabled ? (string)__('Enabled') : (string)__('Off'),
                    'summary' => $gatewayEnabled
                        ? (string)__('Gateway route management is enabled.')
                        : (string)__('Gateway route management can be enabled from the panel.'),
                ],
            ],
        ];
    }

    /**
     * @param array<int, bool> $values
     */
    private function countTrue(array $values): int
    {
        $count = 0;
        foreach ($values as $value) {
            if ($value) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     */
    private function countReadyOperations(array $operations): int
    {
        $count = 0;
        foreach ($operations as $operation) {
            if (!empty($operation['installed'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $operations
     * @return array<int, string>
     */
    private function missingOperationLabels(array $operations): array
    {
        $labels = [];
        foreach ($operations as $operation) {
            if (!empty($operation['installed'])) {
                continue;
            }

            $label = \trim((string)($operation['label'] ?? ''));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function readinessStateLabel(string $state): string
    {
        return match ($state) {
            'ready' => (string)__('Ready'),
            'blocked' => (string)__('Needs setup'),
            default => (string)__('Needs review'),
        };
    }

    private function readinessSummaryText(string $state, int $readyProjects, int $totalProjects): string
    {
        return match ($state) {
            'ready' => (string)__('All managed projects are ready for panel operations.'),
            'blocked' => (string)__('Some projects need core context before operations are safe.'),
            default => (string)__('%{1} of %{2} projects are fully ready.', [$readyProjects, $totalProjects]),
        };
    }

    private function projectReadinessSummary(string $state, int $coreMissingCount, int $missingOperationCount): string
    {
        if ($state === 'ready') {
            return (string)__('All core links and WLS operation plugins are ready.');
        }

        if ($state === 'blocked') {
            return (string)__('%{1} core checks need setup before this project is fully manageable.', [$coreMissingCount]);
        }

        if ($missingOperationCount > 0) {
            return (string)__('%{1} WLS operation plugins need installation.', [$missingOperationCount]);
        }

        return (string)__('Project context needs review before it is fully ready.');
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, array<string, mixed>> $operationMap
     * @return array<int, array<string, mixed>>
     */
    private function buildProjectOperations(array $project, array $operationMap): array
    {
        $operations = [];
        foreach (self::OPERATION_KEYS as $key) {
            $capability = $operationMap[$key] ?? [];
            $operations[] = [
                'key' => $key,
                'label' => $this->operationLabel($key),
                'summary' => $this->operationSummary($key, $project),
                'installed' => !empty($capability['installed']),
                'required_tag' => (string)($capability['required_tag'] ?? ''),
                'status_label' => !empty($capability['installed'])
                    ? (string)__('Ready')
                    : (string)__('Plugin required'),
                'target_label' => !empty($capability['installed'])
                    ? (string)__('Scoped editor')
                    : (string)__('Marketplace'),
                'action_label' => !empty($capability['installed'])
                    ? $this->operationActionLabel($key)
                    : (string)__('Install plugin'),
            ];
        }

        return $operations;
    }

    /**
     * @param array<string, mixed> $project
     */
    private function projectKey(array $project): string
    {
        $id = (int)($project['id'] ?? 0);
        if ($id > 0) {
            return 'project:' . $id;
        }

        $domain = $this->normalizeDomain((string)($project['domain'] ?? ''));
        if ($domain !== '') {
            return 'domain:' . $domain;
        }

        $type = \trim((string)($project['type'] ?? 'project'));
        return $type !== '' ? $type : 'project';
    }

    /**
     * @param array<string, mixed> $project
     */
    private function resolveSecurityScope(array $project, string $domain): string
    {
        $type = \trim((string)($project['type'] ?? 'project'));
        $id = (int)($project['id'] ?? 0);

        return match ($type) {
            'current' => 'current',
            'registered' => $id > 0 ? 'project:' . $id : ($domain !== '' ? 'domain:' . $domain : 'all'),
            default => $domain !== '' ? 'domain:' . $domain : 'all',
        };
    }

    private function resolveTypeLabel(string $type): string
    {
        return match ($type) {
            'current' => (string)__('Current project'),
            'registered' => (string)__('Registered project'),
            'gateway' => (string)__('Gateway route'),
            default => (string)__('Managed project'),
        };
    }

    private function operationLabel(string $key): string
    {
        return match ($key) {
            'php-profile' => (string)__('PHP Config'),
            'database-profile' => (string)__('Database Config'),
            'file-manager' => (string)__('Files'),
            'deploy' => (string)__('Deploy'),
            default => $key,
        };
    }

    private function operationActionLabel(string $key): string
    {
        return match ($key) {
            'php-profile' => (string)__('Configure PHP'),
            'database-profile' => (string)__('Configure Database'),
            'file-manager' => (string)__('Open Files'),
            'deploy' => (string)__('Configure Deploy'),
            default => (string)__('Open editor'),
        };
    }

    /**
     * @param array<string, mixed> $project
     */
    private function operationSummary(string $key, array $project): string
    {
        return match ($key) {
            'php-profile' => \trim((string)($project['php_label'] ?? '')) ?: (string)__('PHP profile not selected'),
            'database-profile' => \trim((string)($project['db_label'] ?? '')) ?: (string)__('Database profile not selected'),
            'file-manager' => \trim((string)($project['path'] ?? '')) !== ''
                ? (string)__('Project path ready')
                : (string)__('Project path missing'),
            'deploy' => \trim((string)($project['domain'] ?? '')) !== ''
                ? (string)__('Project release context ready')
                : (string)__('Project release context missing'),
            default => '',
        };
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        $domain = \preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = \explode('/', $domain, 2)[0] ?? $domain;
        return \trim($domain);
    }

    /**
     * @param array<string, mixed> $project
     */
    private function isSecurityPolicyReady(array $project, string $domain): bool
    {
        $type = \trim((string)($project['type'] ?? 'project'));
        $id = (int)($project['id'] ?? 0);

        return $domain !== '' || $type === 'current' || $id > 0;
    }

    /**
     * @param array<string, mixed> $project
     */
    private function buildSafeContextLabel(array $project, string $domain): string
    {
        $id = (int)($project['id'] ?? 0);
        $type = \trim((string)($project['type'] ?? 'project'));
        $parts = [];

        if ($id > 0) {
            $parts[] = 'project_id=' . $id;
        }
        if ($domain !== '') {
            $parts[] = 'domain=' . $domain;
        }
        if ($type !== '') {
            $parts[] = 'type=' . $type;
        }

        return $parts !== [] ? \implode(' / ', $parts) : (string)__('local context');
    }
}
