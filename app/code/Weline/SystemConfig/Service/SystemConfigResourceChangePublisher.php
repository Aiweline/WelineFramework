<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

final class SystemConfigResourceChangePublisher
{
    public function __construct(
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly NamespacePath $namespacePath,
        private readonly ConfigFieldCacheNamespaceResolver $namespaceResolver,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $changes
     * @param list<array{pool:string,keys:list<string>}> $cacheOps
     * @param list<string> $extraNamespaces already-normalized paths
     * @param list<string> $requestedNamespaces raw request cache_namespaces
     */
    public function publish(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $changes,
        string $entry,
        array $cacheOps = [],
        array $extraNamespaces = [],
        array $requestedNamespaces = [],
    ): ?ResourceChange {
        $records = array_values(array_filter(array_map(
            fn(array $change): ?array => $this->sanitizeChange($change),
            $changes,
        )));
        if ($records === []) {
            return null;
        }

        $identity = implode('|', [$module, $area, $scope, $locale]);
        $resourceId = strlen($identity) <= 191 ? $identity : 'sha256:' . hash('sha256', $identity);
        $changedFields = array_values(array_unique(array_map(
            static fn(array $record): string => (string)$record['key'],
            $records,
        )));
        sort($changedFields, SORT_STRING);
        $before = $this->snapshot($module, $area, $scope, $locale, $records, 'before');
        $after = $this->snapshot($module, $area, $scope, $locale, $records, 'after');
        $revision = $this->revisions->next('system_config', $resourceId);

        $declaredMeta = $this->declaredBindingMetaForFields($module, $area, $changedFields);
        $fieldNamespaces = $this->namespaceResolver->resolve(
            $declaredMeta['namespaces'],
            $requestedNamespaces,
            true,
            $changedFields,
            $declaredMeta['bind_key_prefixes'],
        );
        $namespaces = array_values(array_unique(array_merge(
            $this->impactNamespaces($module, $scope, $identity, $changedFields),
            $extraNamespaces,
            $fieldNamespaces,
        )));
        sort($namespaces, SORT_STRING);

        $impact = [
            'namespaces' => $namespaces,
            'urls' => $this->impactUrls($module, $changedFields),
        ];
        if ($cacheOps !== []) {
            $impact['cache_ops'] = $cacheOps;
        }

        $change = $this->changes->create(
            resourceType: 'system_config',
            resourceId: $resourceId,
            action: 'upsert',
            revision: $revision,
            websiteId: 0,
            websiteCode: 'default',
            before: $before,
            after: $after,
            changedFields: $changedFields,
            impact: $impact,
            origin: ['entry' => $entry],
        );
        w_changed($change);
        return $change;
    }

    /**
     * Resolve impact namespaces without publishing (for pre-bump cache_ops decoration).
     *
     * @param list<string> $changedFields
     * @param list<string> $extraNamespaces
     * @param list<string> $requestedNamespaces
     * @return list<string>
     */
    public function resolveNamespaces(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $changedFields,
        array $extraNamespaces = [],
        array $requestedNamespaces = [],
    ): array {
        $identity = implode('|', [$module, $area, $scope, $locale]);
        $declaredMeta = $this->declaredBindingMetaForFields($module, $area, $changedFields);
        $fieldNamespaces = $this->namespaceResolver->resolve(
            $declaredMeta['namespaces'],
            $requestedNamespaces,
            true,
            $changedFields,
            $declaredMeta['bind_key_prefixes'],
        );
        $namespaces = array_values(array_unique(array_merge(
            $this->impactNamespaces($module, $scope, $identity, $changedFields),
            $extraNamespaces,
            $fieldNamespaces,
        )));
        sort($namespaces, SORT_STRING);
        return $namespaces;
    }

    /**
     * @param list<string> $changedFields
     * @return array{namespaces:list<string>,bind_key_prefixes:list<string>}
     */
    private function declaredBindingMetaForFields(string $module, string $area, array $changedFields): array
    {
        if ($changedFields === []) {
            return ['namespaces' => [], 'bind_key_prefixes' => []];
        }
        /** @var SystemConfigTemplateService $templates */
        $templates = ObjectManager::getInstance(SystemConfigTemplateService::class);
        $declared = [];
        $bindPrefixes = [];
        foreach ($templates->getTemplates($module, $area) as $template) {
            foreach (($template['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = (string)($field['key'] ?? '');
                if ($key === '' || !in_array($key, $changedFields, true)) {
                    continue;
                }
                $raw = (string)($field['cache-namespaces'] ?? $field['cache_namespaces'] ?? '');
                $prefixAttr = (string)($field['cache-namespace-prefix'] ?? $field['cache_namespace_prefix'] ?? '');
                if ($prefixAttr !== '') {
                    $raw = trim($raw . ' ' . $prefixAttr);
                }
                if ($raw !== '') {
                    $declared[] = $raw;
                }
                $bind = (string)($field['cache-bind-key-prefixes'] ?? $field['cache_bind_key_prefixes'] ?? '');
                if ($bind !== '') {
                    $bindPrefixes[] = $bind;
                }
            }
        }
        return [
            'namespaces' => $declared,
            'bind_key_prefixes' => $bindPrefixes,
        ];
    }

    /** @param list<string> $changedFields @return list<string> */
    private function impactNamespaces(
        string $module,
        string $scope,
        string $identity,
        array $changedFields = [],
    ): array {
        $namespaces = [
            $this->namespacePath->global('system-config', [hash('sha256', $identity)]),
            $this->namespacePath->global('storefront', ['config']),
        ];
        $websiteCode = trim((string)(explode('.', strtolower($scope), 2)[0] ?? ''));
        if ($websiteCode !== '' && $websiteCode !== 'default') {
            $namespaces[] = $this->namespacePath->website($websiteCode, ['config']);
        }

        $dimension = match ($module) {
            'Weline_Theme' => 'theme',
            'Weline_Currency' => 'price',
            default => null,
        };
        if ($module === 'Weline_Customer' && $this->hasSocialLoginFields($changedFields)) {
            $dimension = 'auth';
        }
        if ($module === 'Weline_Captcha') {
            $dimension = 'captcha';
        }
        if ($dimension !== null) {
            $namespaces[] = $this->namespacePath->global('storefront', [$dimension]);
            if ($websiteCode !== '' && $websiteCode !== 'default') {
                $namespaces[] = $this->namespacePath->website($websiteCode, [$dimension]);
            }
        }

        $namespaces = array_values(array_unique($namespaces));
        sort($namespaces, SORT_STRING);
        return $namespaces;
    }

    /** @param list<string> $changedFields @return list<string> */
    private function impactUrls(string $module, array $changedFields): array
    {
        if ($module !== 'Weline_Customer' || !$this->hasSocialLoginFields($changedFields)) {
            return [];
        }

        $urls = [
            '/customer/account/login',
            '/customer/account/register',
        ];
        sort($urls, SORT_STRING);

        return $urls;
    }

    /** @param list<string> $changedFields */
    private function hasSocialLoginFields(array $changedFields): bool
    {
        foreach ($changedFields as $field) {
            $field = strtolower(trim((string)$field));
            if ($field !== '' && str_starts_with($field, 'customer/social_login/')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $change */
    private function sanitizeChange(array $change): ?array
    {
        $key = trim((string)($change['key'] ?? ''));
        if ($key === '') {
            return null;
        }
        return [
            'key' => $key,
            'operation' => (string)($change['operation'] ?? ''),
            'before' => $this->sanitizeRow(is_array($change['old_row'] ?? null) ? $change['old_row'] : null),
            'after' => $this->sanitizeRow(is_array($change['new_row'] ?? null) ? $change['new_row'] : null),
        ];
    }

    /** @param array<string,mixed>|null $row */
    private function sanitizeRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $value = $row[SystemConfig::schema_fields_VALUE] ?? null;
        return [
            'version' => (int)($row[SystemConfig::schema_fields_VERSION] ?? 0),
            'value_type' => (string)($row[SystemConfig::schema_fields_VALUE_TYPE] ?? ''),
            'is_sensitive' => (int)($row[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0) === 1,
            'is_active' => (int)($row[SystemConfig::schema_fields_IS_ACTIVE] ?? 0) === 1,
            'value_sha256' => hash('sha256', is_scalar($value) || $value === null
                ? (string)$value
                : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function snapshot(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $records,
        string $side,
    ): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'key' => $record['key'],
                'operation' => $record['operation'],
                'value' => $record[$side],
            ];
        }
        return [
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'rows' => $rows,
        ];
    }
}
