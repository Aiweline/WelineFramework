<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\App\Exception;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Model\SystemConfigVersion;

class SystemConfigCenterService
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly SystemConfigTemplateService $templateService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function enrichTreeWithValues(array $tree, ?string $scope = null, ?string $locale = null): array
    {
        $scope = $this->systemConfig->normalizeScope($scope);
        $locale = $this->systemConfig->normalizeLocale($locale);

        foreach (($tree['modules'] ?? []) as $moduleIndex => $moduleRow) {
            if (!is_array($moduleRow)) {
                continue;
            }
            $moduleName = (string)($moduleRow['module'] ?? '');
            $moduleVersions = [];
            foreach (($moduleRow['areas'] ?? []) as $areaIndex => $areaRow) {
                if (!is_array($areaRow)) {
                    continue;
                }
                $area = (string)($areaRow['area'] ?? SystemConfig::area_BACKEND);
                foreach (($areaRow['templates'] ?? []) as $templateIndex => $template) {
                    if (!is_array($template)) {
                        continue;
                    }
                    foreach (($template['fields'] ?? []) as $fieldIndex => $field) {
                        if (!is_array($field)) {
                            continue;
                        }
                        $key = (string)($field['key'] ?? '');
                        if ($key === '') {
                            continue;
                        }
                        $resolved = $this->systemConfig->resolveConfig(
                            key: $key,
                            module: $moduleName,
                            area: $area,
                            scope: $scope,
                            locale: $locale,
                            default: $field['default'] ?? null
                        );
                        $currentRow = $this->systemConfig->getScopedConfigRow($key, $moduleName, $area, $scope, $locale);
                        $source = is_array($resolved['source'] ?? null) ? $resolved['source'] : null;
                        $isSensitive = $this->isSensitiveField($field)
                            || (bool)($source['is_sensitive'] ?? false)
                            || (int)($currentRow[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0) === 1;

                        $field['resolved'] = $resolved;
                        $field['current_row'] = $this->systemConfig->maskSensitiveRow($currentRow);
                        $field['base_version'] = (int)($currentRow[SystemConfig::schema_fields_VERSION] ?? 0);
                        $field['has_override'] = $currentRow !== null;
                        $field['effective_value'] = $isSensitive ? '***' : $this->stringifyValue($resolved['value'] ?? ($field['default'] ?? ''));
                        $field['effective_source'] = $source;
                        $field['is_sensitive'] = $isSensitive;

                        $tree['modules'][$moduleIndex]['areas'][$areaIndex]['templates'][$templateIndex]['fields'][$fieldIndex] = $field;
                    }
                }

                if ($moduleName !== '') {
                    $versions = $this->systemConfig->getConfigVersions($moduleName, $area, $scope, $locale, 5);
                    foreach ($versions as $versionIndex => $version) {
                        if (!is_array($version)) {
                            continue;
                        }
                        $versions[$versionIndex]['rollback_precheck'] = $this->precheckTemplateConfigRollback(
                            (int)($version[SystemConfigVersion::schema_fields_ID] ?? 0),
                            ['module' => $moduleName, 'area' => $area, 'scope' => $scope, 'locale' => $locale]
                        );
                        $moduleVersions[] = $versions[$versionIndex];
                    }
                }
            }

            if ($moduleVersions !== []) {
                usort($moduleVersions, static function (array $left, array $right): int {
                    return (int)($right[SystemConfigVersion::schema_fields_ID] ?? 0)
                        <=> (int)($left[SystemConfigVersion::schema_fields_ID] ?? 0);
                });
                $tree['modules'][$moduleIndex]['versions'] = array_slice($moduleVersions, 0, 5);
            } elseif ($moduleName !== '') {
                $versions = $this->systemConfig->getConfigVersions($moduleName, SystemConfig::area_BACKEND, $scope, $locale, 5);
                foreach ($versions as $versionIndex => $version) {
                    if (!is_array($version)) {
                        continue;
                    }
                    $versions[$versionIndex]['rollback_precheck'] = $this->precheckTemplateConfigRollback(
                        (int)($version[SystemConfigVersion::schema_fields_ID] ?? 0),
                        ['module' => $moduleName, 'area' => SystemConfig::area_BACKEND, 'scope' => $scope, 'locale' => $locale]
                    );
                }
                $tree['modules'][$moduleIndex]['versions'] = $versions;
            }
        }

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFieldObject(
        string $module,
        string $area,
        string $key,
        ?string $code = null,
        ?string $scope = null,
        ?string $locale = null,
        mixed $default = null
    ): array {
        $definition = $this->findFieldDefinition($module, $area, $key, $code);

        return $this->buildFieldObject(
            $module,
            $area,
            $key,
            is_array($definition['field'] ?? null) ? $definition['field'] : null,
            is_array($definition['template'] ?? null) ? $definition['template'] : null,
            $scope,
            $locale,
            $default
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getFieldObjects(
        string $module,
        string $area,
        ?string $code = null,
        ?string $scope = null,
        ?string $locale = null
    ): array {
        $definitions = $this->getFieldDefinitions($module, $area, $code);
        $fields = [];

        foreach ($definitions as $key => $definition) {
            $fields[$key] = $this->buildFieldObject(
                $module,
                $area,
                $key,
                is_array($definition['field'] ?? null) ? $definition['field'] : null,
                is_array($definition['template'] ?? null) ? $definition['template'] : null,
                $scope,
                $locale
            );
        }

        foreach ($this->systemConfig->getConfigMapByModule($module, $area, $scope, $locale) as $key => $value) {
            $key = (string)$key;
            if ($key === '' || isset($fields[$key])) {
                continue;
            }
            $fields[$key] = $this->buildFieldObject($module, $area, $key, null, null, $scope, $locale, $value);
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $inheritKeys
     * @param array<string, mixed> $baseVersions
     * @return array<string, mixed>
     * @throws Exception
     */
    public function saveTemplateConfig(
        string $module,
        string $area,
        string $code,
        array $values,
        array $inheritKeys = [],
        array $baseVersions = [],
        ?string $scope = null,
        ?string $locale = null,
        array $options = []
    ): array {
        $template = $this->requireTemplate($module, $area, $code);
        $fields = $this->fieldsByKey($template);
        $scope = $this->systemConfig->normalizeScope($scope);
        $locale = $this->systemConfig->normalizeLocale($locale);

        $unknownKeys = array_values(array_diff(
            array_unique(array_merge(array_keys($values), array_map('strval', $inheritKeys))),
            array_keys($fields)
        ));
        if ($unknownKeys !== []) {
            return [
                'success' => false,
                'status' => 'invalid_field',
                'invalid_keys' => $unknownKeys,
                'message' => (string)__('存在未在配置模板声明的字段。'),
            ];
        }

        $normalizedValues = [];
        $normalizedInheritKeys = [];
        $normalizedBaseVersions = [];
        $valueTypes = [];
        $fieldMetadata = [];
        $sensitiveKeys = [];
        $errors = [];

        foreach ($fields as $key => $field) {
            $scopeError = $this->validateFieldScope($field, $scope);
            if ($scopeError !== '') {
                if (array_key_exists($key, $values) || in_array($key, $inheritKeys, true)) {
                    $errors[$key] = $scopeError;
                }
                continue;
            }

            if (array_key_exists($key, $baseVersions)) {
                $normalizedBaseVersions[$key] = (int)$baseVersions[$key];
            }

            if (in_array($key, $inheritKeys, true)) {
                $normalizedInheritKeys[] = $key;
                $normalizedValues[$key] = null;
                continue;
            }

            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = $this->normalizeFieldValue($values[$key], $field);
            $validationError = $this->validateFieldValue($value, $field);
            if ($validationError !== '') {
                $errors[$key] = $validationError;
                continue;
            }

            $normalizedValues[$key] = $value;
            $valueTypes[$key] = $this->fieldValueType($field);
            $fieldMetadata[$key] = [
                'template_code' => (string)($template['code'] ?? $code),
                'group' => (string)($field['group'] ?? ''),
                'label' => (string)($field['label'] ?? $key),
                'field_type' => (string)($field['type'] ?? 'text'),
                'value_type' => $valueTypes[$key],
            ];
            if ($this->isSensitiveField($field)) {
                $sensitiveKeys[] = $key;
            }
        }

        if ($errors !== []) {
            return [
                'success' => false,
                'status' => 'validation_failed',
                'errors' => $errors,
                'message' => (string)__('配置校验失败，当前配置未改变。'),
            ];
        }

        if ($normalizedValues === [] && $normalizedInheritKeys === []) {
            return [
                'success' => false,
                'status' => 'empty',
                'message' => (string)__('没有需要保存的配置。'),
            ];
        }

        $saveOptions = array_merge($options, [
            'inherit_keys' => $normalizedInheritKeys,
            'base_versions' => $normalizedBaseVersions,
            'value_types' => $valueTypes,
            'field_metadata' => $fieldMetadata,
            'sensitive_keys' => $sensitiveKeys,
            'operation' => 'template_save',
            'metadata' => array_merge(
                is_array($options['metadata'] ?? null) ? $options['metadata'] : [],
                [
                    'template_code' => (string)($template['code'] ?? $code),
                    'template_title' => (string)($template['title'] ?? $code),
                    'template_field_keys' => array_keys($fields),
                ]
            ),
        ]);

        $result = $this->systemConfig->saveScopeConfig(
            module: $module,
            area: $area,
            values: $normalizedValues,
            scope: $scope,
            locale: $locale,
            options: $saveOptions
        );

        if (!empty($result['version_id'])) {
            $result['rollback_precheck'] = $this->precheckTemplateConfigRollback((int)$result['version_id'], [
                'module' => $module,
                'area' => $area,
                'code' => $code,
                'scope' => $scope,
                'locale' => $locale,
            ]);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function precheckTemplateConfigRollback(int $versionId, array $context = []): array
    {
        if ($versionId <= 0) {
            return ['rollbackable' => false, 'status' => 'invalid_version', 'blockers' => ['invalid_version']];
        }

        $detail = $this->systemConfig->getConfigVersionDetail($versionId);
        if ($detail === null) {
            return ['rollbackable' => false, 'status' => 'not_found', 'version_id' => $versionId, 'blockers' => ['not_found']];
        }

        $module = (string)($detail[SystemConfigVersion::schema_fields_MODULE] ?? '');
        $area = (string)($detail[SystemConfigVersion::schema_fields_AREA] ?? SystemConfig::area_BACKEND);
        $scope = $this->systemConfig->normalizeScope((string)($detail[SystemConfigVersion::schema_fields_SCOPE] ?? SystemConfig::SCOPE_GLOBAL));
        $locale = $this->systemConfig->normalizeLocale((string)($detail[SystemConfigVersion::schema_fields_LOCALE] ?? SystemConfig::LOCALE_DEFAULT));
        $metadata = is_array($detail['metadata_data'] ?? null) ? $detail['metadata_data'] : [];
        $code = (string)($context['code'] ?? ($metadata['template_code'] ?? ''));

        $blockers = [];
        $conflicts = [];
        $expectedRestore = [];

        if ((string)($detail[SystemConfigVersion::schema_fields_STATUS] ?? '') !== SystemConfigVersion::STATUS_APPLIED) {
            $blockers[] = 'version_not_applied';
        }

        foreach (['module' => $module, 'area' => $area, 'scope' => $scope, 'locale' => $locale] as $key => $expected) {
            if (!array_key_exists($key, $context) || (string)$context[$key] === '') {
                continue;
            }
            $actual = $key === 'scope'
                ? $this->systemConfig->normalizeScope((string)$context[$key])
                : ($key === 'locale' ? $this->systemConfig->normalizeLocale((string)$context[$key]) : (string)$context[$key]);
            if ($actual !== $expected) {
                $blockers[] = 'context_' . $key . '_mismatch';
            }
        }

        $fields = [];
        if ($code !== '') {
            $template = $this->templateService->getTemplateMeta($module, $area, $code);
            if ($template === null) {
                $blockers[] = 'template_not_found';
            } else {
                $fields = $this->fieldsByKey($template);
            }
        } elseif (is_array($metadata['template_field_keys'] ?? null)) {
            $fields = array_fill_keys(array_map('strval', $metadata['template_field_keys']), ['historical' => true]);
        }

        $changes = is_array($detail['changes'] ?? null) ? $detail['changes'] : [];
        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }
            $key = (string)($change['key'] ?? '');
            if ($key === '') {
                continue;
            }
            if ($fields !== [] && !array_key_exists($key, $fields)) {
                $blockers[] = 'field_not_declared:' . $key;
                continue;
            }

            $newRow = is_array($change['new_row'] ?? null) ? $change['new_row'] : null;
            $oldRow = is_array($change['old_row'] ?? null) ? $change['old_row'] : null;
            $currentRow = $this->systemConfig->getScopedConfigRow($key, $module, $area, $scope, $locale);
            $expectedVersion = (int)($newRow[SystemConfig::schema_fields_VERSION] ?? 0);
            $currentVersion = (int)($currentRow[SystemConfig::schema_fields_VERSION] ?? 0);

            if ($newRow === null && $currentRow !== null) {
                $conflicts[] = [
                    'key' => $key,
                    'expected_version' => 0,
                    'current_version' => $currentVersion,
                    'reason' => 'row_recreated_after_inherit',
                ];
                continue;
            }
            if ($expectedVersion > 0 && $currentVersion !== $expectedVersion) {
                $conflicts[] = [
                    'key' => $key,
                    'expected_version' => $expectedVersion,
                    'current_version' => $currentVersion,
                    'reason' => 'version_changed',
                ];
                continue;
            }

            $expectedRestore[] = [
                'key' => $key,
                'action' => $oldRow === null ? 'delete_override' : 'restore_value',
                'restore_row' => $this->systemConfig->maskSensitiveRow($oldRow),
            ];
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'rollbackable' => $blockers === [] && $conflicts === [],
            'status' => $blockers === [] && $conflicts === [] ? 'ready' : 'blocked',
            'version_id' => $versionId,
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'template_code' => $code,
            'blockers' => $blockers,
            'conflicts' => $conflicts,
            'expected_restore' => $expectedRestore,
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    public function rollbackTemplateConfigVersion(int $versionId, array $context = []): array
    {
        $precheck = $this->precheckTemplateConfigRollback($versionId, $context);
        if (empty($precheck['rollbackable'])) {
            return [
                'success' => false,
                'status' => 'precheck_failed',
                'version_id' => $versionId,
                'precheck' => $precheck,
            ];
        }

        $result = $this->systemConfig->rollbackScopeConfigVersion($versionId, $context);
        $result['precheck'] = $precheck;

        return $result;
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     */
    private function requireTemplate(string $module, string $area, string $code): array
    {
        $template = $this->templateService->getTemplateMeta($module, $area, $code);
        if ($template === null) {
            throw new Exception((string)__('配置模板不存在或未通过 Extends 注册。'));
        }

        return $template;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fieldsByKey(array $template): array
    {
        $fields = [];
        foreach (($template['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = (string)($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $fields[$key] = $field;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findFieldDefinition(string $module, string $area, string $key, ?string $code = null): ?array
    {
        $definitions = $this->getFieldDefinitions($module, $area, $code);

        return $definitions[$key] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getFieldDefinitions(string $module, string $area, ?string $code = null): array
    {
        $definitions = [];
        $templates = [];
        $code = trim((string)$code);

        if ($code !== '') {
            $template = $this->templateService->getTemplateMeta($module, $area, $code);
            if ($template !== null) {
                $templates[] = $template;
            }
        } else {
            foreach ($this->templateService->getTemplates($module, $area) as $summary) {
                $template = $this->templateService->getTemplateMeta(
                    (string)($summary['module'] ?? $module),
                    (string)($summary['area'] ?? $area),
                    (string)($summary['code'] ?? '')
                );
                if ($template !== null) {
                    $templates[] = $template;
                }
            }
        }

        foreach ($templates as $template) {
            foreach ($this->fieldsByKey($template) as $key => $field) {
                if (isset($definitions[$key])) {
                    continue;
                }
                $definitions[$key] = [
                    'field' => $field,
                    'template' => $template,
                ];
            }
        }

        return $definitions;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFieldObject(
        string $module,
        string $area,
        string $key,
        ?array $field,
        ?array $template,
        ?string $scope = null,
        ?string $locale = null,
        mixed $default = null
    ): array {
        $scope = $this->systemConfig->normalizeScope($scope);
        $locale = $this->systemConfig->normalizeLocale($locale);
        $fieldFound = $field !== null;
        $resolvedDefault = $fieldFound && array_key_exists('default', $field)
            ? $this->normalizeFieldValue($field['default'], $field)
            : $default;
        $resolved = $this->systemConfig->resolveConfig($key, $module, $area, $scope, $locale, $resolvedDefault);
        $currentRow = $this->systemConfig->getScopedConfigRow($key, $module, $area, $scope, $locale);
        $source = is_array($resolved['source'] ?? null) ? $resolved['source'] : null;
        $isSensitive = ($fieldFound && $this->isSensitiveField($field))
            || (bool)($source['is_sensitive'] ?? false)
            || (int)($currentRow[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0) === 1;
        $options = $fieldFound ? $this->projectOptions((string)($field['options'] ?? '')) : ['options' => [], 'options_source' => ''];

        return [
            'key' => $key,
            'value' => $resolved['value'] ?? $resolvedDefault,
            'display_value' => $isSensitive ? '***' : $this->stringifyValue($resolved['value'] ?? $resolvedDefault),
            'label' => $fieldFound ? (string)__((string)($field['label'] ?? $key)) : '',
            'description' => $fieldFound ? (string)__((string)($field['description'] ?? '')) : '',
            'type' => $fieldFound ? (string)($field['type'] ?? 'text') : '',
            'value_type' => $fieldFound ? $this->fieldValueType($field) : (string)($source['value_type'] ?? SystemConfig::VALUE_TYPE_STRING),
            'default' => $fieldFound ? ($field['default'] ?? null) : $default,
            'group' => $fieldFound ? (string)($field['group'] ?? '') : '',
            'scope' => $fieldFound ? (string)($field['scope'] ?? 'global') : '',
            'options' => $options['options'],
            'options_source' => $options['options_source'],
            'field_found' => $fieldFound,
            'value_found' => (bool)($resolved['found'] ?? false),
            'has_override' => $currentRow !== null,
            'base_version' => (int)($currentRow[SystemConfig::schema_fields_VERSION] ?? 0),
            'is_sensitive' => $isSensitive,
            'source' => $source,
            'template' => [
                'module' => (string)($template['module'] ?? $module),
                'area' => (string)($template['area'] ?? $area),
                'code' => (string)($template['code'] ?? ''),
            ],
        ];
    }

    private function validateFieldScope(array $field, string $scope): string
    {
        $allowed = $this->parseList((string)($field['scope'] ?? 'global,website,store'));
        if ($allowed === []) {
            $allowed = ['global'];
        }
        $level = $this->scopeLevel($scope);
        if (!in_array($level, $allowed, true)) {
            return (string)__('当前字段不允许保存到所选 scope。');
        }

        return '';
    }

    private function scopeLevel(string $scope): string
    {
        [$website, $store, $extra] = explode('.', $this->systemConfig->normalizeScope($scope)) + ['default', 'default', 'default'];
        if ($website === 'default' && $store === 'default' && $extra === 'default') {
            return 'global';
        }
        if ($store === 'default' && $extra === 'default') {
            return 'website';
        }

        return 'store';
    }

    private function normalizeFieldValue(mixed $value, array $field): mixed
    {
        $valueType = $this->fieldValueType($field);
        if (is_array($value)) {
            $value = array_values($value);
        }

        return match ($valueType) {
            SystemConfig::VALUE_TYPE_BOOL => in_array((string)$value, ['1', 'true', 'on', 'yes'], true),
            SystemConfig::VALUE_TYPE_INT => (int)$value,
            SystemConfig::VALUE_TYPE_FLOAT => (float)$value,
            SystemConfig::VALUE_TYPE_JSON => is_string($value) ? (json_decode($value, true) ?? $value) : $value,
            SystemConfig::VALUE_TYPE_NULL => null,
            default => is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    private function validateFieldValue(mixed $value, array $field): string
    {
        $validation = $this->parseList((string)($field['validation'] ?? ''));
        $required = in_array('required', $validation, true)
            || in_array((string)($field['required'] ?? ''), ['1', 'true', 'required'], true);
        if ($required && (is_string($value) ? trim($value) === '' : $value === null)) {
            return (string)__('此字段不能为空。');
        }

        if (in_array('in_options', $validation, true)) {
            $options = $this->parseLiteralOptions((string)($field['options'] ?? ''));
            if ($options !== [] && !array_key_exists((string)$value, $options)) {
                return (string)__('字段值不在允许选项内。');
            }
        }

        return '';
    }

    private function fieldValueType(array $field): string
    {
        $valueType = (string)($field['value-type'] ?? ($field['value_type'] ?? ''));
        if ($valueType !== '') {
            return $valueType;
        }

        return match ((string)($field['type'] ?? 'text')) {
            'switch', 'checkbox', 'boolean' => SystemConfig::VALUE_TYPE_BOOL,
            'number', 'int', 'integer' => SystemConfig::VALUE_TYPE_INT,
            default => SystemConfig::VALUE_TYPE_STRING,
        };
    }

    private function isSensitiveField(array $field): bool
    {
        return in_array((string)($field['sensitive'] ?? ''), ['1', 'true', 'yes'], true)
            || in_array($this->fieldValueType($field), ['encrypted', 'secret_ref'], true);
    }

    /**
     * @return list<string>
     */
    private function parseList(string $value): array
    {
        $value = str_replace(['|', ';'], ',', $value);

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $item): bool => $item !== ''));
    }

    /**
     * @return array<string, string>
     */
    private function parseLiteralOptions(string $options): array
    {
        $options = trim($options);
        if ($options === '' || str_starts_with($options, '$')) {
            return [];
        }

        $result = [];
        foreach ($this->parseList($options) as $option) {
            if (str_contains($option, ':')) {
                [$value, $label] = explode(':', $option, 2);
                $result[trim($value)] = trim($label);
            } else {
                $result[$option] = $option;
            }
        }

        return $result;
    }

    /**
     * @return array{options: array<string, string>, options_source: string}
     */
    private function projectOptions(string $options): array
    {
        $options = trim($options);
        $projected = [];
        foreach ($this->parseLiteralOptions($options) as $value => $label) {
            $projected[(string)$value] = (string)__($label);
        }

        return [
            'options' => $projected,
            'options_source' => $options,
        ];
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }
}
