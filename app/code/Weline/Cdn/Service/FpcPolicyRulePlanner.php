<?php
declare(strict_types=1);
namespace Weline\Cdn\Service;

/** 人工规则保持当前位置；新规则按稳定ref、认领的旧规则按持久public id归属。 */
final class FpcPolicyRulePlanner
{
    private static function content(array $rule): array
    {
        unset($rule['id'],$rule['ref'],$rule['version'],$rule['last_updated'],$rule['_legacy_rule']);
        $rule += ['enabled'=>true,'description'=>'','action_parameters'=>[]];
        return FpcPolicyStateReducer::canonical($rule);
    }

    /** bindings 必须在任何云端写入前持久化；删除的旧id保留历史，不能认领同内容的新人工规则。 */
    public static function plan(array $existing, array $desired, array $bindings = []): array
    {
        $wanted = [];
        foreach ($desired as $rule) { $wanted[$rule['ref']] = $rule; }
        $byId = [];
        foreach ($bindings as $logical => $binding) {
            foreach ($existing as $current) {
                if (($current['id'] ?? null) !== $binding['id']) { continue; }
                if (($current['ref'] ?? $current['id']) !== ($binding['ref'] ?? $binding['id'])) {
                    throw new \RuntimeException('cdn_fpc_owned_rule_identity_changed');
                }
                $byId[$binding['id']] = $logical;
            }
        }
        $existingRefs = array_column($existing, 'ref');
        foreach ($wanted as $logical => $rule) {
            if (isset($bindings[$logical]) || in_array($logical, $existingRefs, true)) { continue; }
            $matches = [];
            foreach ($existing as $current) {
                if (isset($current['id']) && !isset($byId[$current['id']])
                    && !str_starts_with((string)($current['ref'] ?? ''), 'weline_cdn_')
                    && self::content($current) === self::content($rule['_legacy_rule'] ?? $rule)) { $matches[] = $current; }
            }
            if (count($matches) === 1) {
                $current = $matches[0];
                $bindings[$logical] = ['id'=>$current['id'],'ref'=>$current['ref'] ?? null];
                $byId[$current['id']] = $logical;
            }
        }
        $merged = [];
        $seen = [];
        foreach ($existing as $rule) {
            $logical = $byId[$rule['id'] ?? ''] ?? (string)($rule['ref'] ?? '');
            if (!str_starts_with($logical, 'weline_cdn_')) { $merged[] = $rule; continue; }
            if (!isset($wanted[$logical]) || isset($seen[$logical])) { continue; }
            $next = $wanted[$logical];
            unset($next['_legacy_rule']);
            if (isset($rule['id'])) { $next['id'] = $rule['id']; }
            if (isset($byId[$rule['id'] ?? ''])) {
                // 历史规则不改ref；GET的默认ref=id由适配器在更新payload中省略。
                unset($next['ref']);
                if (isset($rule['ref'])) { $next['ref'] = $rule['ref']; }
            }
            foreach (['version','last_updated'] as $field) { if (isset($rule[$field])) { $next[$field] = $rule[$field]; } }
            $merged[] = $next;
            $seen[$logical] = true;
        }
        foreach ($wanted as $logical => $rule) { if (!isset($seen[$logical])) { unset($rule['_legacy_rule']); $merged[] = $rule; } }
        return ['rules'=>$merged,'bindings'=>$bindings];
    }

    public static function merge(array $existing, array $desired, array $bindings = []): array
    {
        return self::plan($existing,$desired,$bindings)['rules'];
    }

    public static function equivalent(array $left, array $right): bool
    {
        $project = static fn(array $rules): array => array_map(static fn(array $rule): array => self::content($rule) + ['id'=>$rule['id']??null,'ref'=>$rule['ref']??null],$rules);
        return $project($left) === $project($right);
    }
}
