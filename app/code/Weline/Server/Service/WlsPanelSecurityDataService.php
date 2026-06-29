<?php
declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Model\AttackLog;
use Weline\Server\Security\AttackDetector;

class WlsPanelSecurityDataService
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;
    private const DEFAULT_AUDIT_LIMIT = 20;
    private const MAX_AUDIT_LIMIT = 50;
    private const POLICY_AUDIT_FILE = 'security-policy-audit.jsonl';
    private const DOMAIN_POLICY_REPLACE_LIST_FIELDS = [
        ['path_rate_limits', 'rules'],
        ['ip_whitelist', 'ips'],
        ['protected_paths', 'paths'],
    ];

    public function __construct(
        private readonly AttackLog $attackLog
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSecurityData(string $instance = '', int $limit = 8): array
    {
        return $this->getSecurityDataFromFilters([
            'instance' => $instance,
            'limit' => $limit,
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getSecurityDataFromFilters(array $filters = []): array
    {
        try {
            $normalizedFilters = $this->normalizeFilters($filters);
            $detector = AttackDetector::getInstance();
            $rules = $detector->getRules();
            $instance = (string)$normalizedFilters['instance'];
            $stats = $this->getFilteredStatistics($normalizedFilters, 7);
            $logResult = $this->getFilteredAttacks($normalizedFilters);

            return [
                'stats' => $stats,
                'project_security_summaries' => $this->buildProjectSecuritySummaries($normalizedFilters),
                'recent_attacks' => $this->normalizeRecentAttacks($logResult['items']),
                'attack_pagination' => $logResult['pagination'],
                'filters' => $normalizedFilters,
                'scope_options' => $normalizedFilters['scope_options'],
                'severity_options' => $this->getSeverityOptions(),
                'type_options' => $this->getTypeOptions(),
                'rules' => $rules,
                'rules_json' => (string)\json_encode($rules, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                'rule_editor' => $this->buildRuleEditor($rules),
                'domain_override_editor' => $this->buildDomainOverrideEditor($rules, $normalizedFilters),
                'rule_change_preview' => $this->buildRuleChangePreview($rules, $rules),
                'rule_summary' => $this->buildRuleSummary($rules),
                'policy_audit' => $this->getPolicyAuditHistory($normalizedFilters),
                'policy_audit_filters' => $normalizedFilters['policy_audit_filters'],
                'blocked_ips' => $detector->getBlockedIps(),
                'instance' => $instance,
                'error' => '',
            ];
        } catch (\Throwable $throwable) {
            return [
                'stats' => [],
                'project_security_summaries' => [],
                'recent_attacks' => [],
                'attack_pagination' => $this->emptyPagination(),
                'filters' => $this->normalizeFilters($filters),
                'scope_options' => $this->buildScopeOptions($this->normalizeProjects($filters['projects'] ?? [])),
                'severity_options' => $this->getSeverityOptions(),
                'type_options' => $this->getTypeOptions(),
                'rules' => [],
                'rules_json' => '{}',
                'rule_editor' => $this->buildRuleEditor([]),
                'domain_override_editor' => $this->buildDomainOverrideEditor([], $this->normalizeFilters($filters)),
                'rule_change_preview' => $this->buildRuleChangePreview([], []),
                'rule_summary' => [],
                'policy_audit' => [],
                'policy_audit_filters' => $this->normalizeFilters($filters)['policy_audit_filters'] ?? [],
                'blocked_ips' => [],
                'instance' => (string)($filters['instance'] ?? $filters['security_instance'] ?? ''),
                'error' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function saveRulesJson(string $rulesJson, array $auditContext = []): array
    {
        $rulesJson = \trim($rulesJson);
        if ($rulesJson === '') {
            return [
                'success' => false,
                'message' => (string)__('Security rules JSON cannot be empty.'),
            ];
        }

        $decoded = \json_decode($rulesJson, true);
        if (!\is_array($decoded)) {
            return [
                'success' => false,
                'message' => (string)__('Security rules JSON is invalid.'),
            ];
        }

        try {
            AttackDetector::getInstance()->updateRules($decoded);
            $this->appendPolicyAudit($this->buildPolicyAuditRecord($auditContext, $decoded));

            return [
                'success' => true,
                'message' => (string)__('Security rules saved. WLS workers will reload rules from the update flag.'),
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function saveRulesFromPanel(array $post): array
    {
        $rulesJson = \trim((string)($post['rules_json'] ?? ''));
        if ($rulesJson === '') {
            $rulesJson = (string)\json_encode(AttackDetector::getInstance()->getRules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $decoded = \json_decode($rulesJson, true);
        if (!\is_array($decoded)) {
            return [
                'success' => false,
                'message' => (string)__('Security rules JSON is invalid. Visual fields were not saved.'),
            ];
        }

        $visualRules = $post['visual_rules'] ?? [];
        if (!\is_array($visualRules)) {
            return $this->saveRulesJson($rulesJson, [
                'action' => 'rules_json_saved',
                'source' => 'panel',
                'scope' => (string)($post['security_scope'] ?? 'all'),
                'domain' => (string)($post['security_domain'] ?? ''),
                'changed_sections' => ['rules_json'],
            ]);
        }

        $merged = $this->mergeVisualRules($decoded, $visualRules);
        $changes = $this->buildRuleChangePreview($decoded, $merged);
        $mergedJson = (string)\json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return $this->saveRulesJson($mergedJson, [
            'action' => 'common_rules_saved',
            'source' => 'visual_panel',
            'scope' => (string)($post['security_scope'] ?? 'all'),
            'domain' => (string)($post['security_domain'] ?? ''),
            'changed_sections' => $this->summarizeChangedSections($changes),
            'change_count' => \count($changes),
        ]);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function saveDomainOverrideFromPanel(array $post): array
    {
        $rulesJson = \trim((string)($post['rules_json'] ?? ''));
        if ($rulesJson === '') {
            $rulesJson = (string)\json_encode(AttackDetector::getInstance()->getRules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $decoded = \json_decode($rulesJson, true);
        if (!\is_array($decoded)) {
            return [
                'success' => false,
                'message' => (string)__('Security rules JSON is invalid. Project policy was not saved.'),
            ];
        }

        $input = \is_array($post['domain_override'] ?? null) ? $post['domain_override'] : [];
        $domain = $this->normalizeDomain((string)($post['security_domain'] ?? $input['domain'] ?? ''));
        if ($domain === '') {
            return [
                'success' => false,
                'message' => (string)__('Select one managed project before saving a project security policy.'),
            ];
        }

        if (!\is_array($decoded['domain_overrides'] ?? null)) {
            $decoded['domain_overrides'] = [];
        }
        if (!\is_array($decoded['domain_overrides']['domains'] ?? null)) {
            $decoded['domain_overrides']['domains'] = [];
        }

        if ($this->sanitizeCheckbox($input['remove'] ?? '0')) {
            unset($decoded['domain_overrides']['domains'][$domain]);
            $result = $this->saveRulesJson((string)\json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), [
                'action' => 'domain_policy_removed',
                'source' => 'domain_override_panel',
                'scope' => (string)($post['security_scope'] ?? $input['scope'] ?? ''),
                'domain' => $domain,
                'label' => (string)($input['label'] ?? $domain),
                'changed_sections' => ['domain_overrides'],
                'override_enabled' => false,
            ]);
            if (!empty($result['success'])) {
                $result['message'] = (string)__('Project security policy removed.');
            }

            return $result;
        }

        $decoded['domain_overrides']['enabled'] = true;
        $domainOverrideRules = $this->buildDomainOverrideRulesFromInput($input);
        $decoded['domain_overrides']['domains'][$domain] = [
            'enabled' => $this->sanitizeCheckbox($input['enabled'] ?? '0'),
            'label' => \substr(\trim((string)($input['label'] ?? $domain)), 0, 120),
            'scope' => \trim((string)($post['security_scope'] ?? $input['scope'] ?? '')),
            'updated_at' => \date('Y-m-d H:i:s'),
            'rules' => $domainOverrideRules,
        ];

        $result = $this->saveRulesJson((string)\json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), [
            'action' => 'domain_policy_saved',
            'source' => 'domain_override_panel',
            'scope' => (string)($post['security_scope'] ?? $input['scope'] ?? ''),
            'domain' => $domain,
            'label' => (string)($input['label'] ?? $domain),
            'changed_sections' => \array_keys($domainOverrideRules),
            'override_enabled' => $this->sanitizeCheckbox($input['enabled'] ?? '0'),
        ]);
        if (!empty($result['success'])) {
            $result['message'] = (string)__('Project security policy saved.');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function previewRulesFromPanel(array $post): array
    {
        $rulesJson = \trim((string)($post['rules_json'] ?? ''));
        if ($rulesJson === '') {
            $rulesJson = (string)\json_encode(AttackDetector::getInstance()->getRules(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        $decoded = \json_decode($rulesJson, true);
        if (!\is_array($decoded)) {
            return [
                'success' => false,
                'message' => (string)__('Security rules JSON is invalid. Visual fields were not saved.'),
                'changes' => [],
                'change_count' => 0,
                'merged_json' => '',
            ];
        }

        $visualRules = $post['visual_rules'] ?? [];
        $merged = \is_array($visualRules) ? $this->mergeVisualRules($decoded, $visualRules) : $decoded;
        $changes = $this->buildRuleChangePreview($decoded, $merged);

        return [
            'success' => true,
            'message' => '',
            'changes' => $changes,
            'change_count' => \count($changes),
            'merged_json' => (string)\json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $severityOptions = \array_column($this->getSeverityOptions(), 'value');
        $typeOptions = \array_column($this->getTypeOptions(), 'value');
        $projects = $this->normalizeProjects($filters['projects'] ?? []);
        $scopeOptions = $this->buildScopeOptions($projects);
        $instance = \trim((string)($filters['instance'] ?? $filters['security_instance'] ?? ''));
        $scope = \trim((string)($filters['scope'] ?? $filters['security_scope'] ?? 'all'));
        $ip = \trim((string)($filters['ip'] ?? $filters['security_ip'] ?? ''));
        $severity = \trim((string)($filters['severity'] ?? $filters['security_severity'] ?? ''));
        $type = \trim((string)($filters['type'] ?? $filters['security_type'] ?? ''));
        $blocked = \trim((string)($filters['blocked'] ?? $filters['security_blocked'] ?? ''));
        $page = (int)($filters['page'] ?? $filters['security_page'] ?? 1);
        $limit = (int)($filters['limit'] ?? $filters['security_limit'] ?? self::DEFAULT_LIMIT);

        if (!\in_array($severity, $severityOptions, true)) {
            $severity = '';
        }
        if (!\in_array($type, $typeOptions, true)) {
            $type = '';
        }
        if (!\in_array($blocked, ['0', '1'], true)) {
            $blocked = '';
        }

        $scopeValues = \array_column($scopeOptions, 'value');
        if (!\in_array($scope, $scopeValues, true)) {
            $scope = 'all';
        }
        $domain = $this->resolveScopeDomain($scope, $scopeOptions);
        $policyAuditFilters = $this->normalizePolicyAuditFilters($filters, $domain);

        return [
            'instance' => $instance,
            'scope' => $scope,
            'domain' => $domain,
            'ip' => $ip,
            'severity' => $severity,
            'type' => $type,
            'blocked' => $blocked,
            'page' => \max(1, $page),
            'limit' => \max(1, \min(self::MAX_LIMIT, $limit)),
            'scope_options' => $scopeOptions,
            'policy_audit_filters' => $policyAuditFilters,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizePolicyAuditFilters(array $filters, string $defaultDomain = ''): array
    {
        $domainInput = \trim((string)($filters['policy_audit_domain'] ?? ''));
        $domain = $domainInput === ''
            ? $this->normalizeDomain($defaultDomain)
            : ($domainInput === '*' ? '' : $this->normalizeDomain($domainInput));

        return [
            'action' => $this->normalizeAuditToken((string)($filters['policy_audit_action'] ?? ''), 80),
            'source' => $this->normalizeAuditToken((string)($filters['policy_audit_source'] ?? ''), 80),
            'domain' => $domain,
            'domain_input' => $domainInput === '*' ? '*' : $domain,
            'section' => $this->normalizeAuditToken((string)($filters['policy_audit_section'] ?? ''), 80),
            'keyword' => \mb_substr(\trim((string)($filters['policy_audit_keyword'] ?? '')), 0, 120),
            'limit' => \max(1, \min(self::MAX_AUDIT_LIMIT, (int)($filters['policy_audit_limit'] ?? self::DEFAULT_AUDIT_LIMIT))),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    private function getFilteredAttacks(array $filters): array
    {
        $page = (int)$filters['page'];
        $limit = (int)$filters['limit'];
        $query = $this->applyAttackFilters($this->attackLog->clearQuery(), $filters);
        $total = (int)$query->count();

        $items = [];
        if ($total > 0) {
            $items = $this->applyAttackFilters($this->attackLog->clearQuery(), $filters)
                ->order(AttackLog::schema_fields_CREATED_AT, 'DESC')
                ->pagination($page, $limit)
                ->select()
                ->fetchArray();
        }

        $totalPages = $limit > 0 ? (int)\max(1, \ceil($total / $limit)) : 1;

        return [
            'items' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'has_prev' => $page > 1,
                'has_next' => $page < $totalPages,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyAttackFilters(AttackLog $query, array $filters): AttackLog
    {
        $instance = (string)($filters['instance'] ?? '');
        $domain = (string)($filters['domain'] ?? '');
        $ip = (string)($filters['ip'] ?? '');
        $severity = (string)($filters['severity'] ?? '');
        $type = (string)($filters['type'] ?? '');
        $blocked = (string)($filters['blocked'] ?? '');

        if ($instance !== '') {
            $query->where(AttackLog::schema_fields_INSTANCE, $instance);
        }
        if ($domain !== '') {
            $query->where(AttackLog::schema_fields_DOMAIN, $domain);
        }
        if ($ip !== '') {
            $query->where(AttackLog::schema_fields_IP, $ip);
        }
        if ($severity !== '') {
            $query->where(AttackLog::schema_fields_SEVERITY, $severity);
        }
        if ($type !== '') {
            $query->where(AttackLog::schema_fields_ATTACK_TYPE, $type);
        }
        if ($blocked !== '') {
            $query->where(AttackLog::schema_fields_BLOCKED, $blocked === '1' ? 1 : 0);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function getFilteredStatistics(array $filters, int $days): array
    {
        $instance = (string)($filters['instance'] ?? '');
        $domain = (string)($filters['domain'] ?? '');

        if ($domain === '') {
            return $this->attackLog->getStatistics($instance, $days);
        }

        $cutoffDate = \date('Y-m-d H:i:s', \time() - ($days * 86400));
        $query = $this->attackLog->clearQuery()
            ->where(AttackLog::schema_fields_CREATED_AT, $cutoffDate, '>=')
            ->where(AttackLog::schema_fields_DOMAIN, $domain);

        if ($instance !== '') {
            $query->where(AttackLog::schema_fields_INSTANCE, $instance);
        }

        return $this->summarizeAttackRows($query->select()->fetchArray());
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function summarizeAttackRows(array $rows): array
    {
        $stats = [
            'total_attacks' => \count($rows),
            'blocked_attacks' => 0,
            'by_type' => [],
            'by_severity' => [
                AttackLog::SEVERITY_LOW => 0,
                AttackLog::SEVERITY_MEDIUM => 0,
                AttackLog::SEVERITY_HIGH => 0,
                AttackLog::SEVERITY_CRITICAL => 0,
            ],
            'top_ips' => [],
            'top_domains' => [],
            'cdn_notifications' => 0,
        ];

        $ipCounts = [];
        $domainCounts = [];
        foreach ($rows as $row) {
            if (!empty($row[AttackLog::schema_fields_BLOCKED])) {
                $stats['blocked_attacks']++;
            }

            $type = (string)($row[AttackLog::schema_fields_ATTACK_TYPE] ?? '');
            if ($type !== '') {
                $stats['by_type'][$type] = (int)($stats['by_type'][$type] ?? 0) + 1;
            }

            $severity = (string)($row[AttackLog::schema_fields_SEVERITY] ?? '');
            if (isset($stats['by_severity'][$severity])) {
                $stats['by_severity'][$severity]++;
            }

            $ip = (string)($row[AttackLog::schema_fields_IP] ?? '');
            if ($ip !== '') {
                $ipCounts[$ip] = (int)($ipCounts[$ip] ?? 0) + 1;
            }

            $domain = (string)($row[AttackLog::schema_fields_DOMAIN] ?? '');
            if ($domain !== '') {
                $domainCounts[$domain] = (int)($domainCounts[$domain] ?? 0) + 1;
            }

            if (!empty($row[AttackLog::schema_fields_CDN_NOTIFIED])) {
                $stats['cdn_notifications']++;
            }
        }

        \arsort($ipCounts);
        \arsort($domainCounts);
        $stats['top_ips'] = \array_slice($ipCounts, 0, 10, true);
        $stats['top_domains'] = \array_slice($domainCounts, 0, 10, true);

        return $stats;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildProjectSecuritySummaries(array $filters): array
    {
        $scopeOptions = \is_array($filters['scope_options'] ?? null) ? $filters['scope_options'] : [];
        $instance = (string)($filters['instance'] ?? '');
        $activeScope = (string)($filters['scope'] ?? 'all');
        $items = [];

        foreach ($scopeOptions as $option) {
            if (!\is_array($option)) {
                continue;
            }

            $scope = (string)($option['value'] ?? '');
            if ($scope === '') {
                continue;
            }

            $domain = (string)($option['domain'] ?? '');
            $summaryFilters = [
                'instance' => $instance,
                'domain' => $domain,
            ];
            $stats = $this->getFilteredStatistics($summaryFilters, 7);
            $latest = $this->getLatestAttackForScope($instance, $domain);
            $risk = $this->resolveRiskLevel($stats);
            $topType = $this->resolveTopType(\is_array($stats['by_type'] ?? null) ? $stats['by_type'] : []);

            $items[] = [
                'scope' => $scope,
                'label' => (string)($option['label'] ?? $scope),
                'domain' => $domain,
                'type' => (string)($option['type'] ?? 'project'),
                'active' => $activeScope === $scope,
                'events' => (int)($stats['total_attacks'] ?? 0),
                'blocked' => (int)($stats['blocked_attacks'] ?? 0),
                'critical' => (int)($stats['by_severity'][AttackLog::SEVERITY_CRITICAL] ?? 0),
                'high' => (int)($stats['by_severity'][AttackLog::SEVERITY_HIGH] ?? 0),
                'risk' => $risk,
                'risk_label' => $risk === 'none' ? (string)__('No events') : (string)AttackLog::getSeverityLabel($risk),
                'top_type' => $topType,
                'top_type_label' => $topType !== '' ? (string)AttackLog::getTypeLabel($topType) : (string)__('No attacks'),
                'latest_time' => (string)($latest['time'] ?? ''),
                'latest_ip' => (string)($latest['ip'] ?? ''),
                'latest_uri' => (string)($latest['uri'] ?? ''),
                'latest_blocked' => !empty($latest['blocked']),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function getLatestAttackForScope(string $instance, string $domain): array
    {
        $cutoffDate = \date('Y-m-d H:i:s', \time() - (7 * 86400));
        $query = $this->attackLog->clearQuery()
            ->where(AttackLog::schema_fields_CREATED_AT, $cutoffDate, '>=');

        if ($instance !== '') {
            $query->where(AttackLog::schema_fields_INSTANCE, $instance);
        }
        if ($domain !== '') {
            $query->where(AttackLog::schema_fields_DOMAIN, $domain);
        }

        $rows = $query
            ->order(AttackLog::schema_fields_CREATED_AT, 'DESC')
            ->pagination(1, 1)
            ->select()
            ->fetchArray();
        $items = $this->normalizeRecentAttacks($rows);

        return $items[0] ?? [];
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function resolveRiskLevel(array $stats): string
    {
        $severity = \is_array($stats['by_severity'] ?? null) ? $stats['by_severity'] : [];
        foreach ([AttackLog::SEVERITY_CRITICAL, AttackLog::SEVERITY_HIGH, AttackLog::SEVERITY_MEDIUM, AttackLog::SEVERITY_LOW] as $level) {
            if ((int)($severity[$level] ?? 0) > 0) {
                return $level;
            }
        }

        return 'none';
    }

    /**
     * @param array<string, mixed> $counts
     */
    private function resolveTopType(array $counts): string
    {
        \arsort($counts);
        foreach ($counts as $type => $count) {
            if ((int)$count > 0 && (string)$type !== '') {
                return (string)$type;
            }
        }

        return '';
    }

    /**
     * @param mixed $projects
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProjects(mixed $projects): array
    {
        if (!\is_array($projects)) {
            return [];
        }

        $items = [];
        foreach ($projects as $project) {
            if (\is_array($project)) {
                $items[] = $project;
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $projects
     * @return array<int, array<string, string>>
     */
    private function buildScopeOptions(array $projects): array
    {
        $options = [
            [
                'value' => 'all',
                'label' => (string)__('All managed projects'),
                'domain' => '',
                'type' => 'all',
            ],
        ];
        $seen = ['all' => true];

        foreach ($projects as $project) {
            $domain = $this->normalizeDomain((string)($project['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }

            $type = \trim((string)($project['type'] ?? 'project'));
            $id = (int)($project['id'] ?? 0);
            $value = match ($type) {
                'current' => 'current',
                'registered' => $id > 0 ? 'project:' . $id : 'domain:' . $domain,
                default => 'domain:' . $domain,
            };
            if (isset($seen[$value])) {
                continue;
            }

            $name = \trim((string)($project['name'] ?? ''));
            if ($type === 'current') {
                $label = (string)__('Current project: %{1}', [$domain]);
            } elseif ($name !== '' && \strtolower($name) !== \strtolower($domain)) {
                $label = (string)__('%{1} (%{2})', [$name, $domain]);
            } else {
                $label = $domain;
            }

            $options[] = [
                'value' => $value,
                'label' => $label,
                'domain' => $domain,
                'type' => $type !== '' ? $type : 'project',
            ];
            $seen[$value] = true;
        }

        return $options;
    }

    /**
     * @param array<int, array<string, string>> $scopeOptions
     */
    private function resolveScopeDomain(string $scope, array $scopeOptions): string
    {
        foreach ($scopeOptions as $option) {
            if ((string)($option['value'] ?? '') === $scope) {
                return (string)($option['domain'] ?? '');
            }
        }

        return '';
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\trim($domain));
        $domain = \preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = \explode('/', $domain, 2)[0] ?? $domain;
        if (\str_contains($domain, ':')) {
            $domain = \explode(':', $domain, 2)[0] ?? $domain;
        }
        return \trim($domain);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getSeverityOptions(): array
    {
        return [
            ['value' => AttackLog::SEVERITY_LOW, 'label' => (string)AttackLog::getSeverityLabel(AttackLog::SEVERITY_LOW)],
            ['value' => AttackLog::SEVERITY_MEDIUM, 'label' => (string)AttackLog::getSeverityLabel(AttackLog::SEVERITY_MEDIUM)],
            ['value' => AttackLog::SEVERITY_HIGH, 'label' => (string)AttackLog::getSeverityLabel(AttackLog::SEVERITY_HIGH)],
            ['value' => AttackLog::SEVERITY_CRITICAL, 'label' => (string)AttackLog::getSeverityLabel(AttackLog::SEVERITY_CRITICAL)],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function getTypeOptions(): array
    {
        return [
            ['value' => AttackLog::ATTACK_TYPE_RATE_LIMIT, 'label' => (string)AttackLog::getTypeLabel(AttackLog::ATTACK_TYPE_RATE_LIMIT)],
            ['value' => AttackLog::ATTACK_TYPE_PATH_SCAN, 'label' => (string)AttackLog::getTypeLabel(AttackLog::ATTACK_TYPE_PATH_SCAN)],
            ['value' => AttackLog::ATTACK_TYPE_MALICIOUS, 'label' => (string)AttackLog::getTypeLabel(AttackLog::ATTACK_TYPE_MALICIOUS)],
            ['value' => AttackLog::ATTACK_TYPE_BAD_UA, 'label' => (string)AttackLog::getTypeLabel(AttackLog::ATTACK_TYPE_BAD_UA)],
            ['value' => AttackLog::ATTACK_TYPE_PROTECTED_PATH, 'label' => (string)AttackLog::getTypeLabel(AttackLog::ATTACK_TYPE_PROTECTED_PATH)],
            ['value' => AttackLog::ATTACK_TYPE_BLOCKED, 'label' => (string)AttackLog::getTypeLabel(AttackLog::ATTACK_TYPE_BLOCKED)],
            ['value' => AttackLog::ATTACK_TYPE_DDOS, 'label' => (string)AttackLog::getTypeLabel(AttackLog::ATTACK_TYPE_DDOS)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPagination(): array
    {
        return [
            'total' => 0,
            'page' => 1,
            'limit' => self::DEFAULT_LIMIT,
            'total_pages' => 1,
            'has_prev' => false,
            'has_next' => false,
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function buildRuleEditor(array $rules): array
    {
        return [
            'rate_limit' => [
                'enabled' => $this->ruleEnabled($rules, 'rate_limit', true),
                'window' => $this->ruleInt($rules, 'rate_limit', 'window', 60),
                'max_requests' => $this->ruleInt($rules, 'rate_limit', 'max_requests', 200),
                'block_duration' => $this->ruleInt($rules, 'rate_limit', 'block_duration', 300),
            ],
            'path_rate_limits' => $this->buildPathRateLimitEditor($rules),
            'path_scan' => [
                'enabled' => $this->ruleEnabled($rules, 'path_scan', true),
                'window' => $this->ruleInt($rules, 'path_scan', 'window', 60),
                'max_unique_paths' => $this->ruleInt($rules, 'path_scan', 'max_unique_paths', 50),
                'block_duration' => $this->ruleInt($rules, 'path_scan', 'block_duration', 600),
            ],
            'ssl_handshake_failure' => [
                'enabled' => $this->ruleEnabled($rules, 'ssl_handshake_failure', true),
                'window' => $this->ruleInt($rules, 'ssl_handshake_failure', 'window', 60),
                'max_failures' => $this->ruleInt($rules, 'ssl_handshake_failure', 'max_failures', 30),
                'block_duration' => $this->ruleInt($rules, 'ssl_handshake_failure', 'block_duration', 60),
                'fast_close_threshold' => $this->ruleFloat($rules, 'ssl_handshake_failure', 'fast_close_threshold', 0.2),
            ],
            'unknown_route_ban' => [
                'enabled' => $this->ruleEnabled($rules, 'unknown_route_ban', true),
                'consecutive_count' => $this->ruleInt($rules, 'unknown_route_ban', 'consecutive_count', 5),
                'block_duration' => $this->ruleInt($rules, 'unknown_route_ban', 'block_duration', 300),
                'only_in_spike_mode' => $this->ruleEnabled($rules, 'unknown_route_ban', true, 'only_in_spike_mode'),
            ],
            'ip_whitelist' => [
                'enabled' => $this->ruleEnabled($rules, 'ip_whitelist', true),
                'ips' => \implode("\n", $this->ruleList($rules, 'ip_whitelist', 'ips')),
            ],
            'protected_paths' => [
                'enabled' => $this->ruleEnabled($rules, 'protected_paths', true),
                'paths' => \implode("\n", $this->ruleList($rules, 'protected_paths', 'paths')),
                'block_duration' => $this->ruleInt($rules, 'protected_paths', 'block_duration', 1800),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildDomainOverrideEditor(array $rules, array $filters): array
    {
        $scope = (string)($filters['scope'] ?? 'all');
        $domain = $this->normalizeDomain((string)($filters['domain'] ?? ''));
        $label = $domain;
        $scopeOptions = \is_array($filters['scope_options'] ?? null) ? $filters['scope_options'] : [];
        foreach ($scopeOptions as $option) {
            if (!\is_array($option) || (string)($option['value'] ?? '') !== $scope) {
                continue;
            }

            $label = \trim((string)($option['label'] ?? $domain));
            break;
        }

        $baseRules = $this->rulesWithoutDomainOverrides($rules);
        $override = $this->getDomainOverride($rules, $domain);
        $overrideRules = \is_array($override['rules'] ?? null) ? $override['rules'] : [];
        $effectiveRules = $this->mergeDomainOverrideRules($baseRules, $overrideRules);

        return [
            'available' => $domain !== '',
            'configured' => $override !== [],
            'enabled' => (bool)($override['enabled'] ?? true),
            'scope' => $scope,
            'domain' => $domain,
            'label' => $label !== '' ? $label : $domain,
            'updated_at' => (string)($override['updated_at'] ?? ''),
            'rule_editor' => $this->buildRuleEditor($effectiveRules),
            'saved_rule_keys' => \array_keys($overrideRules),
            'inheritance_summary' => $this->buildDomainPolicyInheritanceSummary($baseRules, $overrideRules, $effectiveRules, $override !== []),
        ];
    }

    /**
     * @param array<string, mixed> $baseRules
     * @param array<string, mixed> $overrideRules
     * @return array<string, mixed>
     */
    private function mergeDomainOverrideRules(array $baseRules, array $overrideRules): array
    {
        $effective = \array_replace_recursive($baseRules, $overrideRules);
        foreach (self::DOMAIN_POLICY_REPLACE_LIST_FIELDS as [$ruleKey, $fieldKey]) {
            if (!\is_array($overrideRules[$ruleKey] ?? null) || !\array_key_exists($fieldKey, $overrideRules[$ruleKey])) {
                continue;
            }

            $list = $overrideRules[$ruleKey][$fieldKey];
            if (\is_array($list)) {
                $effective[$ruleKey][$fieldKey] = \array_values($list);
            }
        }

        return $effective;
    }

    /**
     * @param array<string, mixed> $baseRules
     * @param array<string, mixed> $overrideRules
     * @param array<string, mixed> $effectiveRules
     * @return array<string, mixed>
     */
    private function buildDomainPolicyInheritanceSummary(
        array $baseRules,
        array $overrideRules,
        array $effectiveRules,
        bool $configured
    ): array {
        $fields = [
            ['rate_limit', 'enabled', (string)__('Rate Limit'), (string)__('Enabled')],
            ['rate_limit', 'window', (string)__('Rate Limit'), (string)__('Window Seconds')],
            ['rate_limit', 'max_requests', (string)__('Rate Limit'), (string)__('Max Requests')],
            ['rate_limit', 'block_duration', (string)__('Rate Limit'), (string)__('Block Seconds')],
            ['path_rate_limits', 'enabled', (string)__('Path Rate Limits'), (string)__('Enabled')],
            ['path_rate_limits', 'rules', (string)__('Path Rate Limits'), (string)__('Rules')],
            ['path_scan', 'enabled', (string)__('Path Scan'), (string)__('Enabled')],
            ['path_scan', 'window', (string)__('Path Scan'), (string)__('Window Seconds')],
            ['path_scan', 'max_unique_paths', (string)__('Path Scan'), (string)__('Unique Paths')],
            ['path_scan', 'block_duration', (string)__('Path Scan'), (string)__('Block Seconds')],
            ['ssl_handshake_failure', 'enabled', (string)__('SSL Handshake'), (string)__('Enabled')],
            ['ssl_handshake_failure', 'window', (string)__('SSL Handshake'), (string)__('Window Seconds')],
            ['ssl_handshake_failure', 'max_failures', (string)__('SSL Handshake'), (string)__('Max Failures')],
            ['ssl_handshake_failure', 'block_duration', (string)__('SSL Handshake'), (string)__('Block Seconds')],
            ['ssl_handshake_failure', 'fast_close_threshold', (string)__('SSL Handshake'), (string)__('Fast Close Threshold')],
            ['unknown_route_ban', 'enabled', (string)__('Unknown Route Ban'), (string)__('Enabled')],
            ['unknown_route_ban', 'only_in_spike_mode', (string)__('Unknown Route Ban'), (string)__('Only In Spike Mode')],
            ['unknown_route_ban', 'consecutive_count', (string)__('Unknown Route Ban'), (string)__('Consecutive Count')],
            ['unknown_route_ban', 'block_duration', (string)__('Unknown Route Ban'), (string)__('Block Seconds')],
            ['ip_whitelist', 'enabled', (string)__('IP Whitelist'), (string)__('Enabled')],
            ['ip_whitelist', 'ips', (string)__('IP Whitelist'), (string)__('Whitelisted IPs')],
            ['protected_paths', 'enabled', (string)__('Protected Paths'), (string)__('Enabled')],
            ['protected_paths', 'paths', (string)__('Protected Paths'), (string)__('Paths')],
            ['protected_paths', 'block_duration', (string)__('Protected Paths'), (string)__('Block Seconds')],
        ];

        $rows = [];
        $counts = [
            'inherited' => 0,
            'overridden' => 0,
            'same_as_global' => 0,
        ];

        foreach ($fields as [$ruleKey, $fieldKey, $groupLabel, $fieldLabel]) {
            $baseValue = $this->nestedRuleValue($baseRules, $ruleKey, $fieldKey);
            $effectiveValue = $this->nestedRuleValue($effectiveRules, $ruleKey, $fieldKey);
            $overrideHasField = \is_array($overrideRules[$ruleKey] ?? null) && \array_key_exists($fieldKey, $overrideRules[$ruleKey]);
            $overrideValue = $overrideHasField ? $overrideRules[$ruleKey][$fieldKey] : null;
            $differs = $this->ruleValuesDiffer($baseValue, $effectiveValue);
            $state = 'inherited';
            if ($overrideHasField && $differs) {
                $state = 'overridden';
            } elseif ($overrideHasField) {
                $state = 'same_as_global';
            }

            $counts[$state]++;
            $rows[] = [
                'rule_key' => $ruleKey,
                'field_key' => $fieldKey,
                'group_label' => $groupLabel,
                'field_label' => $fieldLabel,
                'state' => $state,
                'state_label' => $this->domainPolicyInheritanceStateLabel($state),
                'global_value' => $this->formatPolicyInheritanceValue($baseValue),
                'project_value' => $this->formatPolicyInheritanceValue($effectiveValue),
                'override_value' => $overrideHasField ? $this->formatPolicyInheritanceValue($overrideValue) : '',
            ];
        }

        return [
            'configured' => $configured,
            'rows' => $rows,
            'counts' => $counts,
            'total' => \count($rows),
            'summary_label' => (string)__('%{1} overrides / %{2} inherited', [
                (string)$counts['overridden'],
                (string)$counts['inherited'],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function nestedRuleValue(array $rules, string $ruleKey, string $fieldKey): mixed
    {
        $rule = \is_array($rules[$ruleKey] ?? null) ? $rules[$ruleKey] : [];
        return $rule[$fieldKey] ?? null;
    }

    private function ruleValuesDiffer(mixed $left, mixed $right): bool
    {
        return \json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            !== \json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function domainPolicyInheritanceStateLabel(string $state): string
    {
        return match ($state) {
            'overridden' => (string)__('Project override'),
            'same_as_global' => (string)__('Custom equals global'),
            default => (string)__('Inherited'),
        };
    }

    private function formatPolicyInheritanceValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        if (\is_bool($value)) {
            return $value ? (string)__('Enabled') : (string)__('Disabled');
        }
        if (\is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (\is_array($item)) {
                    $path = \trim((string)($item['path'] ?? ''));
                    if ($path !== '') {
                        $items[] = (string)__('%{1} (%{2}/%{3}s)', [
                            $path,
                            (string)(int)($item['max_requests'] ?? 0),
                            (string)(int)($item['window'] ?? 0),
                        ]);
                        continue;
                    }

                    $encoded = \json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (\is_string($encoded) && $encoded !== '') {
                        $items[] = $encoded;
                    }
                    continue;
                }

                $scalar = \trim((string)$item);
                if ($scalar !== '') {
                    $items[] = $scalar;
                }
            }

            $sample = \implode(', ', \array_slice($items, 0, 3));
            if (\count($items) > 3) {
                $sample .= ', ...';
            }

            return $sample !== ''
                ? (string)__('%{1} entries: %{2}', [(string)\count($items), $sample])
                : (string)__('0 entries');
        }

        return (string)$value;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildDomainOverrideRulesFromInput(array $input): array
    {
        $rateLimit = \is_array($input['rate_limit'] ?? null) ? $input['rate_limit'] : [];
        $pathRateLimits = \is_array($input['path_rate_limits'] ?? null) ? $input['path_rate_limits'] : [];
        $pathScan = \is_array($input['path_scan'] ?? null) ? $input['path_scan'] : [];
        $sslHandshake = \is_array($input['ssl_handshake_failure'] ?? null) ? $input['ssl_handshake_failure'] : [];
        $unknownRouteBan = \is_array($input['unknown_route_ban'] ?? null) ? $input['unknown_route_ban'] : [];
        $ipWhitelist = \is_array($input['ip_whitelist'] ?? null) ? $input['ip_whitelist'] : [];
        $protectedPaths = \is_array($input['protected_paths'] ?? null) ? $input['protected_paths'] : [];

        return [
            'rate_limit' => [
                'enabled' => $this->sanitizeCheckbox($rateLimit['enabled'] ?? '0'),
                'window' => $this->sanitizeInt($rateLimit['window'] ?? 60, 1, 86400, 60),
                'max_requests' => $this->sanitizeInt($rateLimit['max_requests'] ?? 200, 1, 1000000, 200),
                'block_duration' => $this->sanitizeInt($rateLimit['block_duration'] ?? 300, 0, 86400, 300),
            ],
            'path_rate_limits' => $this->buildPathRateLimitOverrideRules($pathRateLimits),
            'path_scan' => [
                'enabled' => $this->sanitizeCheckbox($pathScan['enabled'] ?? '0'),
                'window' => $this->sanitizeInt($pathScan['window'] ?? 60, 1, 86400, 60),
                'max_unique_paths' => $this->sanitizeInt($pathScan['max_unique_paths'] ?? 50, 1, 100000, 50),
                'block_duration' => $this->sanitizeInt($pathScan['block_duration'] ?? 600, 0, 86400, 600),
            ],
            'ssl_handshake_failure' => [
                'enabled' => $this->sanitizeCheckbox($sslHandshake['enabled'] ?? '0'),
                'window' => $this->sanitizeInt($sslHandshake['window'] ?? 60, 1, 86400, 60),
                'max_failures' => $this->sanitizeInt($sslHandshake['max_failures'] ?? 30, 1, 100000, 30),
                'block_duration' => $this->sanitizeInt($sslHandshake['block_duration'] ?? 60, 0, 86400, 60),
                'fast_close_threshold' => $this->sanitizeFloat($sslHandshake['fast_close_threshold'] ?? 0.2, 0.01, 10, 0.2),
            ],
            'unknown_route_ban' => [
                'enabled' => $this->sanitizeCheckbox($unknownRouteBan['enabled'] ?? '0'),
                'only_in_spike_mode' => $this->sanitizeCheckbox($unknownRouteBan['only_in_spike_mode'] ?? '0'),
                'consecutive_count' => $this->sanitizeInt($unknownRouteBan['consecutive_count'] ?? 5, 1, 100000, 5),
                'block_duration' => $this->sanitizeInt($unknownRouteBan['block_duration'] ?? 300, 0, 86400, 300),
            ],
            'ip_whitelist' => [
                'enabled' => $this->sanitizeCheckbox($ipWhitelist['enabled'] ?? '0'),
                'ips' => $this->sanitizeList($ipWhitelist['ips'] ?? []),
            ],
            'protected_paths' => [
                'enabled' => $this->sanitizeCheckbox($protectedPaths['enabled'] ?? '0'),
                'paths' => $this->sanitizeList($protectedPaths['paths'] ?? []),
                'block_duration' => $this->sanitizeInt($protectedPaths['block_duration'] ?? 1800, 0, 86400, 1800),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildPathRateLimitOverrideRules(array $input): array
    {
        $rows = \is_array($input['rules'] ?? null) ? $input['rules'] : [];
        $normalizedRows = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $path = $this->sanitizePathRatePath($row['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $normalizedRows[] = [
                'path' => $path,
                'window' => $this->sanitizeInt($row['window'] ?? 60, 1, 86400, 60),
                'max_requests' => $this->sanitizeInt($row['max_requests'] ?? 60, 1, 1000000, 60),
                'block_duration' => $this->sanitizeInt($row['block_duration'] ?? 120, 0, 86400, 120),
                'enabled' => $this->sanitizeCheckbox($row['enabled'] ?? '0'),
            ];

            if (\count($normalizedRows) >= 50) {
                break;
            }
        }

        return [
            'enabled' => $this->sanitizeCheckbox($input['enabled'] ?? '0'),
            'rules' => $normalizedRows,
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function rulesWithoutDomainOverrides(array $rules): array
    {
        unset($rules['domain_overrides']);
        return $rules;
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function getDomainOverride(array $rules, string $domain): array
    {
        if ($domain === '') {
            return [];
        }

        $overrides = \is_array($rules['domain_overrides'] ?? null) ? $rules['domain_overrides'] : [];
        $domains = \is_array($overrides['domains'] ?? null) ? $overrides['domains'] : [];
        $override = \is_array($domains[$domain] ?? null) ? $domains[$domain] : [];

        return $override;
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $visualRules
     * @return array<string, mixed>
     */
    private function mergeVisualRules(array $rules, array $visualRules): array
    {
        $rules['rate_limit'] = $this->mergeIntegerRule($rules, $visualRules, 'rate_limit', [
            'window' => [1, 86400],
            'max_requests' => [1, 1000000],
            'block_duration' => [0, 86400],
        ]);
        $rules['path_rate_limits'] = $this->mergePathRateLimits($rules, $visualRules);

        $rules['path_scan'] = $this->mergeIntegerRule($rules, $visualRules, 'path_scan', [
            'window' => [1, 86400],
            'max_unique_paths' => [1, 100000],
            'block_duration' => [0, 86400],
        ]);

        $rules['ssl_handshake_failure'] = $this->mergeIntegerRule($rules, $visualRules, 'ssl_handshake_failure', [
            'window' => [1, 86400],
            'max_failures' => [1, 100000],
            'block_duration' => [0, 86400],
        ]);
        $sslInput = \is_array($visualRules['ssl_handshake_failure'] ?? null) ? $visualRules['ssl_handshake_failure'] : [];
        $rules['ssl_handshake_failure']['fast_close_threshold'] = $this->sanitizeFloat(
            $sslInput['fast_close_threshold'] ?? ($rules['ssl_handshake_failure']['fast_close_threshold'] ?? 0.2),
            0.01,
            10,
            0.2
        );

        $rules['unknown_route_ban'] = $this->mergeIntegerRule($rules, $visualRules, 'unknown_route_ban', [
            'consecutive_count' => [1, 100000],
            'block_duration' => [0, 86400],
        ]);
        if (\is_array($visualRules['unknown_route_ban'] ?? null)) {
            $unknownInput = $visualRules['unknown_route_ban'];
            $rules['unknown_route_ban']['only_in_spike_mode'] = $this->sanitizeCheckbox($unknownInput['only_in_spike_mode'] ?? '0');
        }

        $rules['ip_whitelist'] = $this->mergeListRule($rules, $visualRules, 'ip_whitelist', 'ips');
        $rules['protected_paths'] = $this->mergeListRule($rules, $visualRules, 'protected_paths', 'paths');
        $protectedInput = \is_array($visualRules['protected_paths'] ?? null) ? $visualRules['protected_paths'] : [];
        $rules['protected_paths']['block_duration'] = $this->sanitizeInt(
            $protectedInput['block_duration'] ?? ($rules['protected_paths']['block_duration'] ?? 1800),
            0,
            86400,
            1800
        );

        return $rules;
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function buildPathRateLimitEditor(array $rules): array
    {
        $rule = \is_array($rules['path_rate_limits'] ?? null) ? $rules['path_rate_limits'] : [];
        $rows = [];
        $configuredRows = \is_array($rule['rules'] ?? null) ? $rule['rules'] : [];

        foreach ($configuredRows as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $path = $this->sanitizePathRatePath($item['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $rows[] = [
                'enabled' => (bool)($item['enabled'] ?? true),
                'path' => $path,
                'window' => $this->sanitizeInt($item['window'] ?? 60, 1, 86400, 60),
                'max_requests' => $this->sanitizeInt($item['max_requests'] ?? 60, 1, 1000000, 60),
                'block_duration' => $this->sanitizeInt($item['block_duration'] ?? 120, 0, 86400, 120),
            ];
        }

        return [
            'enabled' => (bool)($rule['enabled'] ?? true),
            'rules' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $visualRules
     * @return array<string, mixed>
     */
    private function mergePathRateLimits(array $rules, array $visualRules): array
    {
        $rule = \is_array($rules['path_rate_limits'] ?? null) ? $rules['path_rate_limits'] : [];
        if (!\is_array($visualRules['path_rate_limits'] ?? null)) {
            return $rule;
        }

        $input = $visualRules['path_rate_limits'];
        $rule['enabled'] = $this->sanitizeCheckbox($input['enabled'] ?? '0');
        $rows = \is_array($input['rules'] ?? null) ? $input['rules'] : [];
        $normalizedRows = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $path = $this->sanitizePathRatePath($row['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $normalizedRows[] = [
                'path' => $path,
                'window' => $this->sanitizeInt($row['window'] ?? 60, 1, 86400, 60),
                'max_requests' => $this->sanitizeInt($row['max_requests'] ?? 60, 1, 1000000, 60),
                'block_duration' => $this->sanitizeInt($row['block_duration'] ?? 120, 0, 86400, 120),
                'enabled' => $this->sanitizeCheckbox($row['enabled'] ?? '0'),
            ];

            if (\count($normalizedRows) >= 50) {
                break;
            }
        }

        $rule['rules'] = $normalizedRows;
        return $rule;
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $visualRules
     * @param array<string, array{0: int, 1: int}> $fields
     * @return array<string, mixed>
     */
    private function mergeIntegerRule(array $rules, array $visualRules, string $ruleKey, array $fields): array
    {
        $rule = \is_array($rules[$ruleKey] ?? null) ? $rules[$ruleKey] : [];
        if (!\is_array($visualRules[$ruleKey] ?? null)) {
            return $rule;
        }

        $input = $visualRules[$ruleKey];
        $rule['enabled'] = $this->sanitizeCheckbox($input['enabled'] ?? '0');

        foreach ($fields as $field => $bounds) {
            $rule[$field] = $this->sanitizeInt(
                $input[$field] ?? ($rule[$field] ?? $bounds[0]),
                $bounds[0],
                $bounds[1],
                (int)($rule[$field] ?? $bounds[0])
            );
        }

        return $rule;
    }

    /**
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $visualRules
     * @return array<string, mixed>
     */
    private function mergeListRule(array $rules, array $visualRules, string $ruleKey, string $listKey): array
    {
        $rule = \is_array($rules[$ruleKey] ?? null) ? $rules[$ruleKey] : [];
        if (!\is_array($visualRules[$ruleKey] ?? null)) {
            return $rule;
        }

        $input = $visualRules[$ruleKey];
        $rule['enabled'] = $this->sanitizeCheckbox($input['enabled'] ?? '0');
        $rule[$listKey] = $this->sanitizeList($input[$listKey] ?? ($rule[$listKey] ?? []));

        return $rule;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<int, array<string, string>>
     */
    private function buildRuleChangePreview(array $before, array $after): array
    {
        $beforeFlat = $this->flattenRuleValues($before);
        $afterFlat = $this->flattenRuleValues($after);
        $paths = \array_values(\array_unique(\array_merge(\array_keys($beforeFlat), \array_keys($afterFlat))));
        \sort($paths, SORT_NATURAL);

        $changes = [];
        foreach ($paths as $path) {
            $from = $beforeFlat[$path] ?? null;
            $to = $afterFlat[$path] ?? null;
            if (\json_encode($from, JSON_UNESCAPED_UNICODE) === \json_encode($to, JSON_UNESCAPED_UNICODE)) {
                continue;
            }

            $changes[] = [
                'path' => $path,
                'from' => $this->formatPreviewValue($from),
                'to' => $this->formatPreviewValue($to),
            ];

            if (\count($changes) >= 120) {
                break;
            }
        }

        return $changes;
    }

    /**
     * @param array<int, array<string, string>> $changes
     * @return array<int, string>
     */
    private function summarizeChangedSections(array $changes): array
    {
        $sections = [];
        foreach ($changes as $change) {
            $path = \trim((string)($change['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $section = \explode('.', $path, 2)[0] ?? $path;
            if ($section !== '') {
                $sections[$section] = $section;
            }
        }

        return \array_values($sections);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    private function getPolicyAuditHistory(array $filters, ?int $limit = null): array
    {
        $path = $this->policyAuditPath();
        if (!\is_file($path) || !\is_readable($path)) {
            return [];
        }

        $lines = \file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines)) {
            return [];
        }

        $auditFilters = \is_array($filters['policy_audit_filters'] ?? null)
            ? $filters['policy_audit_filters']
            : $this->normalizePolicyAuditFilters($filters, (string)($filters['domain'] ?? ''));
        $limit = \max(1, \min(self::MAX_AUDIT_LIMIT, (int)($auditFilters['limit'] ?? $limit ?? self::DEFAULT_AUDIT_LIMIT)));
        $records = [];
        foreach (\array_reverse(\array_slice($lines, -200)) as $line) {
            $decoded = \json_decode((string)$line, true);
            if (!\is_array($decoded)) {
                continue;
            }

            $record = $this->normalizePolicyAuditRecord($decoded);
            if (!$this->policyAuditRecordMatches($record, $auditFilters)) {
                continue;
            }

            $records[] = $record;
            if (\count($records) >= $limit) {
                break;
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $filters
     */
    private function policyAuditRecordMatches(array $record, array $filters): bool
    {
        $action = (string)($filters['action'] ?? '');
        if ($action !== '' && (string)($record['action'] ?? '') !== $action) {
            return false;
        }

        $source = (string)($filters['source'] ?? '');
        if ($source !== '' && (string)($record['source'] ?? '') !== $source) {
            return false;
        }

        $domain = $this->normalizeDomain((string)($filters['domain'] ?? ''));
        $recordDomain = $this->normalizeDomain((string)($record['domain'] ?? ''));
        if ($domain !== '' && $recordDomain !== '' && $recordDomain !== $domain) {
            return false;
        }

        $section = (string)($filters['section'] ?? '');
        $sections = \is_array($record['changed_sections'] ?? null) ? $record['changed_sections'] : [];
        if ($section !== '' && !\in_array($section, $sections, true)) {
            return false;
        }

        $keyword = \mb_strtolower(\trim((string)($filters['keyword'] ?? '')));
        if ($keyword === '') {
            return true;
        }

        $haystack = \mb_strtolower(\implode(' ', \array_merge([
            (string)($record['action'] ?? ''),
            (string)($record['action_label'] ?? ''),
            (string)($record['source'] ?? ''),
            (string)($record['source_label'] ?? ''),
            (string)($record['scope'] ?? ''),
            (string)($record['domain'] ?? ''),
            (string)($record['domain_label'] ?? ''),
            (string)($record['label'] ?? ''),
        ], \array_map('strval', $sections))));

        return \str_contains($haystack, $keyword);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function buildPolicyAuditRecord(array $context, array $rules): array
    {
        $action = $this->normalizeAuditToken((string)($context['action'] ?? 'rules_json_saved'), 80);
        $source = $this->normalizeAuditToken((string)($context['source'] ?? 'advanced_json'), 80);
        $sections = \is_array($context['changed_sections'] ?? null)
            ? $this->normalizeAuditList($context['changed_sections'])
            : [];
        if ($sections === []) {
            $sections = ['rules_json'];
        }

        return [
            'time' => \date('c'),
            'action' => $action !== '' ? $action : 'rules_json_saved',
            'source' => $source !== '' ? $source : 'advanced_json',
            'scope' => \mb_substr(\trim((string)($context['scope'] ?? 'all')), 0, 120),
            'domain' => $this->normalizeDomain((string)($context['domain'] ?? '')),
            'label' => \mb_substr(\trim((string)($context['label'] ?? '')), 0, 160),
            'changed_sections' => $sections,
            'change_count' => (int)($context['change_count'] ?? \count($sections)),
            'override_enabled' => \array_key_exists('override_enabled', $context) ? (bool)$context['override_enabled'] : null,
            'rule_section_count' => \count($rules),
            'success' => true,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private function appendPolicyAudit(array $record): void
    {
        try {
            $path = $this->policyAuditPath();
            $dir = \dirname($path);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0775, true);
            }

            $line = \json_encode($record, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            if (!\is_string($line)) {
                return;
            }

            \file_put_contents($path, $line . \PHP_EOL, \FILE_APPEND | \LOCK_EX);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizePolicyAuditRecord(array $record): array
    {
        $action = (string)($record['action'] ?? '');
        $source = (string)($record['source'] ?? '');
        $domain = $this->normalizeDomain((string)($record['domain'] ?? ''));
        $sections = \is_array($record['changed_sections'] ?? null)
            ? $this->normalizeAuditList($record['changed_sections'])
            : [];
        $time = (string)($record['time'] ?? '');
        $timestamp = $time !== '' ? \strtotime($time) : false;

        return [
            'time' => $time,
            'time_label' => $timestamp !== false ? \date('Y-m-d H:i:s', (int)$timestamp) : $time,
            'action' => $action,
            'action_label' => $this->policyAuditActionLabel($action),
            'source' => $source,
            'source_label' => $this->policyAuditSourceLabel($source),
            'scope' => (string)($record['scope'] ?? ''),
            'domain' => $domain,
            'domain_label' => $domain !== '' ? $domain : (string)__('Global rules'),
            'label' => (string)($record['label'] ?? ''),
            'changed_sections' => $sections,
            'change_count' => (int)($record['change_count'] ?? \count($sections)),
            'override_enabled' => $record['override_enabled'] ?? null,
            'success' => !empty($record['success']),
        ];
    }

    private function policyAuditActionLabel(string $action): string
    {
        return match ($action) {
            'common_rules_saved' => (string)__('Common rules saved'),
            'domain_policy_saved' => (string)__('Project policy saved'),
            'domain_policy_removed' => (string)__('Project policy removed'),
            'rules_json_saved' => (string)__('Rules JSON saved'),
            default => $action !== '' ? $action : (string)__('Policy updated'),
        };
    }

    private function policyAuditSourceLabel(string $source): string
    {
        return match ($source) {
            'visual_panel' => (string)__('Visual editor'),
            'domain_override_panel' => (string)__('Project policy editor'),
            'advanced_json' => (string)__('Advanced JSON'),
            default => $source !== '' ? $source : (string)__('WLS Panel'),
        };
    }

    private function policyAuditPath(): string
    {
        return \rtrim(BP, '\\/') . \DIRECTORY_SEPARATOR . 'var'
            . \DIRECTORY_SEPARATOR . 'log'
            . \DIRECTORY_SEPARATOR . 'wls'
            . \DIRECTORY_SEPARATOR . self::POLICY_AUDIT_FILE;
    }

    private function normalizeAuditToken(string $value, int $maxLength): string
    {
        $value = \trim($value);
        $value = \preg_replace('/[^a-zA-Z0-9:_\-.]/', '', $value) ?? '';
        return \substr($value, 0, $maxLength);
    }

    /**
     * @param array<mixed> $items
     * @return array<int, string>
     */
    private function normalizeAuditList(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $value = $this->normalizeAuditToken((string)$item, 80);
            if ($value !== '') {
                $result[$value] = $value;
            }
        }

        return \array_values($result);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function flattenRuleValues(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . (string)$key;
            if (\is_array($value) && !$this->isListArray($value)) {
                $flat += $this->flattenRuleValues($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * @param array<mixed> $value
     */
    private function isListArray(array $value): bool
    {
        return $value === [] || \array_keys($value) === \range(0, \count($value) - 1);
    }

    private function formatPreviewValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value)) {
            return (string)$value;
        }

        return (string)\json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function ruleEnabled(array $rules, string $ruleKey, bool $default, string $field = 'enabled'): bool
    {
        $rule = \is_array($rules[$ruleKey] ?? null) ? $rules[$ruleKey] : [];
        return (bool)($rule[$field] ?? $default);
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function ruleInt(array $rules, string $ruleKey, string $field, int $default): int
    {
        $rule = \is_array($rules[$ruleKey] ?? null) ? $rules[$ruleKey] : [];
        return (int)($rule[$field] ?? $default);
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function ruleFloat(array $rules, string $ruleKey, string $field, float $default): float
    {
        $rule = \is_array($rules[$ruleKey] ?? null) ? $rules[$ruleKey] : [];
        return (float)($rule[$field] ?? $default);
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<int, string>
     */
    private function ruleList(array $rules, string $ruleKey, string $field): array
    {
        $rule = \is_array($rules[$ruleKey] ?? null) ? $rules[$ruleKey] : [];
        return $this->sanitizeList($rule[$field] ?? []);
    }

    private function sanitizeCheckbox(mixed $value): bool
    {
        return \in_array((string)$value, ['1', 'true', 'on', 'yes'], true);
    }

    private function sanitizeInt(mixed $value, int $min, int $max, int $default): int
    {
        $value = \is_numeric($value) ? (int)$value : $default;
        return \max($min, \min($max, $value));
    }

    private function sanitizeFloat(mixed $value, float $min, float $max, float $default): float
    {
        $value = \is_numeric($value) ? (float)$value : $default;
        return \max($min, \min($max, $value));
    }

    private function sanitizePathRatePath(mixed $value): string
    {
        $path = \trim((string)$value);
        if ($path === '') {
            return '';
        }

        $parsedPath = \parse_url($path, PHP_URL_PATH);
        if (\is_string($parsedPath) && $parsedPath !== '') {
            $path = $parsedPath;
        }

        $path = \explode('?', $path, 2)[0] ?? $path;
        $path = \trim($path);
        if ($path === '') {
            return '';
        }

        if (!\str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function sanitizeList(mixed $value): array
    {
        if (\is_array($value)) {
            $lines = $value;
        } else {
            $lines = \preg_split('/\R+/', (string)$value) ?: [];
        }

        $items = [];
        foreach ($lines as $line) {
            $item = \trim((string)$line);
            if ($item !== '') {
                $items[$item] = $item;
            }
        }

        return \array_values($items);
    }

    /**
     * @param array<int, array<string, mixed>> $recent
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRecentAttacks(array $recent): array
    {
        $items = [];
        foreach ($recent as $row) {
            $type = (string)($row[AttackLog::schema_fields_ATTACK_TYPE] ?? '');
            $severity = (string)($row[AttackLog::schema_fields_SEVERITY] ?? AttackLog::getSeverityByType($type));
            $createdAt = (string)($row[AttackLog::schema_fields_CREATED_AT] ?? '');
            $createdAtTs = $createdAt !== '' ? \strtotime($createdAt) : false;
            $items[] = [
                'id' => (int)($row[AttackLog::schema_fields_ID] ?? 0),
                'time' => $createdAtTs !== false ? \date('m-d H:i:s', (int)$createdAtTs) : '',
                'type' => $type,
                'type_label' => AttackLog::getTypeLabel($type),
                'severity' => $severity,
                'severity_label' => AttackLog::getSeverityLabel($severity),
                'ip' => (string)($row[AttackLog::schema_fields_IP] ?? ''),
                'domain' => (string)($row[AttackLog::schema_fields_DOMAIN] ?? ''),
                'uri' => (string)($row[AttackLog::schema_fields_URI] ?? ''),
                'reason' => (string)($row[AttackLog::schema_fields_REASON] ?? ''),
                'blocked' => !empty($row[AttackLog::schema_fields_BLOCKED]),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<int, array<string, mixed>>
     */
    private function buildRuleSummary(array $rules): array
    {
        return [
            $this->summarizeBooleanRule($rules, 'rate_limit', (string)__('Rate Limit')),
            $this->summarizeBooleanRule($rules, 'path_scan', (string)__('Path Scan')),
            $this->summarizeBooleanRule($rules, 'ssl_handshake_failure', (string)__('SSL Handshake')),
            $this->summarizeBooleanRule($rules, 'unknown_route_ban', (string)__('Unknown Route Ban')),
            $this->summarizeListRule($rules, 'ip_whitelist', 'ips', (string)__('IP Whitelist')),
            $this->summarizeListRule($rules, 'protected_paths', 'paths', (string)__('Protected Paths')),
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function summarizeBooleanRule(array $rules, string $key, string $label): array
    {
        $rule = \is_array($rules[$key] ?? null) ? $rules[$key] : [];
        return [
            'label' => $label,
            'enabled' => (bool)($rule['enabled'] ?? false),
            'meta' => (string)__('Configured'),
        ];
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function summarizeListRule(array $rules, string $key, string $listKey, string $label): array
    {
        $rule = \is_array($rules[$key] ?? null) ? $rules[$key] : [];
        $items = \is_array($rule[$listKey] ?? null) ? $rule[$listKey] : [];

        return [
            'label' => $label,
            'enabled' => (bool)($rule['enabled'] ?? false),
            'meta' => (string)__('%{1} entries', [(string)\count($items)]),
        ];
    }
}
