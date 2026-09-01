<?php

declare(strict_types=1);

namespace Weline\Marketing\Service;

use Weline\Marketing\Model\Rule\Rule;

/**
 * Detects and explains Marketing rules owned by other modules (external deal sync).
 */
final class ExternalManagedRuleOwnership
{
    public const MARK_FLAG = 'external_managed';

    /**
     * @param array<string, mixed>|Rule $rule
     * @return array{
     *   managed:bool,
     *   source_module:string,
     *   source_type:string,
     *   source_id:string,
     *   source_key:string,
     *   module_label:string,
     *   hint:string
     * }
     */
    public function describe(array|Rule $rule): array
    {
        $data = $rule instanceof Rule ? $rule->getData() : $rule;
        $parsed = $this->parse($data);
        $managed = $parsed !== null;
        $module = (string)($parsed['source_module'] ?? '');
        $label = $this->moduleLabel($module);

        return [
            'managed' => $managed,
            'source_module' => $module,
            'source_type' => (string)($parsed['source_type'] ?? ''),
            'source_id' => (string)($parsed['source_id'] ?? ''),
            'source_key' => (string)($parsed['source_key'] ?? ''),
            'module_label' => $label,
            'hint' => $managed ? $this->buildHint($label) : '',
        ];
    }

    /** @param array<string, mixed>|Rule $rule */
    public function isExternallyManaged(array|Rule $rule): bool
    {
        $data = $rule instanceof Rule ? $rule->getData() : $rule;

        return $this->parse($data) !== null;
    }

    private function buildHint(string $label): string
    {
        return (string)\__('此折扣由「%{1}」活动自动同步，请到该模块管理活动以禁用或调整；折扣模块不可删除。', [$label]);
    }

    /** @param array<string, mixed>|Rule $rule */
    public function deletionDeniedMessage(array|Rule $rule): string
    {
        return $this->mutationDeniedMessage($rule, 'delete');
    }

    /** @param array<string, mixed>|Rule $rule */
    public function mutationDeniedMessage(array|Rule $rule, string $action = 'delete'): string
    {
        $meta = $this->describe($rule);
        if (!$meta['managed']) {
            return $action === 'save'
                ? (string)\__('无法保存该规则')
                : (string)\__('无法删除该规则');
        }

        if ($action === 'save') {
            return (string)\__('系统同步折扣不可在折扣模块修改。请到「%{1}」管理对应活动以禁用或调整。', [$meta['module_label']]);
        }

        return (string)\__('系统同步折扣不可在折扣模块删除。请到「%{1}」管理对应活动以禁用或调整。', [$meta['module_label']]);
    }

    public function moduleLabel(string $moduleCode): string
    {
        $moduleCode = trim($moduleCode);
        if ($moduleCode === '') {
            return (string)\__('来源业务模块');
        }

        return match ($moduleCode) {
            'Weline_Promotion' => (string)\__('促销活动'),
            default => $moduleCode,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array{source_module:string,source_type:string,source_id:string,source_key:string}|null
     */
    public function parse(array $data): ?array
    {
        $fromDescription = $this->parseDescription((string)($data[Rule::schema_fields_DESCRIPTION] ?? $data['description'] ?? ''));
        if ($fromDescription !== null) {
            return $fromDescription;
        }

        return $this->parseActions($data);
    }

    /**
     * @return array{source_module:string,source_type:string,source_id:string,source_key:string}|null
     */
    public function parseDescription(string $description): ?array
    {
        if ($description === '' || !str_contains($description, '[weline:')) {
            return null;
        }

        if (!preg_match(
            '/\[weline:(?:external_managed=1;)?source=([^;\]]*);module=([^;\]]*);id=([^;\]]*);key=([^\]]*)\]/',
            $description,
            $m,
        )) {
            // legacy / alternate: source=type only without module=
            if (!preg_match('/\[weline:source=([^;\]]*)/', $description, $legacy)) {
                return null;
            }

            return [
                'source_module' => '',
                'source_type' => trim((string)$legacy[1]),
                'source_id' => '',
                'source_key' => '',
            ];
        }

        return [
            'source_type' => trim((string)$m[1]),
            'source_module' => trim((string)$m[2]),
            'source_id' => trim((string)$m[3]),
            'source_key' => trim((string)$m[4]),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{source_module:string,source_type:string,source_id:string,source_key:string}|null
     */
    private function parseActions(array $data): ?array
    {
        $raw = $data[Rule::schema_fields_ACTIONS_SERIALIZED] ?? $data['actions_serialized'] ?? null;
        $actions = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $actions = is_array($decoded) ? $decoded : null;
        } elseif (is_array($raw)) {
            $actions = $raw;
        } elseif (isset($data['actions']) && is_array($data['actions'])) {
            $actions = $data['actions'];
        }
        if (!is_array($actions)) {
            return null;
        }

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $managed = $action[self::MARK_FLAG] ?? $action['source_module'] ?? null;
            $module = trim((string)($action['source_module'] ?? ''));
            if ($managed === null || ($module === '' && empty($action[self::MARK_FLAG]))) {
                continue;
            }
            if ($module === '' && empty($action['source_type'])) {
                continue;
            }

            return [
                'source_module' => $module,
                'source_type' => trim((string)($action['source_type'] ?? '')),
                'source_id' => trim((string)($action['source_id'] ?? '')),
                'source_key' => trim((string)($action['source_key'] ?? '')),
            ];
        }

        return null;
    }
}
