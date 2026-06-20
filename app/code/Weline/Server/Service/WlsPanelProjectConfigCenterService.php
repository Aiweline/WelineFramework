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
     * @return array{items: array<int, array<string, mixed>>, count: int, installed_operation_count: int, missing_operation_count: int}
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

        return [
            'items' => $items,
            'count' => \count($items),
            'installed_operation_count' => (int)($operationCapabilities['installed_count'] ?? 0),
            'missing_operation_count' => (int)($operationCapabilities['missing_count'] ?? 0),
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

        return [
            'key' => $this->projectKey($project),
            'project' => $project,
            'security_scope' => $this->resolveSecurityScope($project, $domain),
            'security_policy_ready' => $this->isSecurityPolicyReady($project, $domain),
            'safe_context_label' => $this->buildSafeContextLabel($project, $domain),
            'type_label' => $this->resolveTypeLabel($type),
            'identity' => $domain !== '' ? $domain : (string)__('Local project'),
            'path_ready' => $path !== '',
            'admin_ready' => $adminUrl !== '' || $type === 'current',
            'panel_ready' => $panelUrl !== '' || $type === 'current',
            'gateway_ready' => $gatewayEnabled,
            'gateway_label' => $gatewayEnabled ? (string)__('Gateway enabled') : (string)__('Gateway disabled'),
            'operations' => $this->buildProjectOperations($project, $operationMap),
        ];
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
