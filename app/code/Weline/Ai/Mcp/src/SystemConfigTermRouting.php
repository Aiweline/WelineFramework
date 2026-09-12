<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Single lexicon so MCP retrieval / role routing maps configuration vocabulary
 * to framework Weline_SystemConfig (统一配置) and &lt;w:config:embed&gt; (嵌入配置).
 */
final class SystemConfigTermRouting
{
    public const AUTHORITATIVE_DOC = 'app/code/Weline/SystemConfig/doc/README.md';

    public const EMBED_DOC = 'app/code/Weline/SystemConfig/doc/config-embed标签使用指南.md';

    public const HARD_RULE_ID = 'systemconfig_unified_config_terms';

    /**
     * Path-intent needles (lowercased task haystack).
     *
     * @return list<string>
     */
    public static function pathIntentNeedles(): array
    {
        return [
            '统一配置中心',
            '统一配置',
            '系统配置',
            '嵌入配置',
            '配置嵌入',
            'config:embed',
            'config:field',
            'w:config',
            'systemconfig',
            'weline_systemconfig',
            'system_config',
            'system configuration',
            '配置',
        ];
    }

    /**
     * Path fragments boosted when any path-intent needle hits.
     *
     * @return list<string>
     */
    public static function pathIntentPaths(): array
    {
        return [
            '/weline/systemconfig/',
            'systemconfig',
            'weline_systemconfig',
            'config-embed',
            'config:embed',
            'system_config',
        ];
    }

    /**
     * Query-expansion map: needle (as appears in task text) => retrieval terms.
     * Longer / more specific needles should be listed first for readability;
     * expandTask applies every matching needle.
     *
     * @return array<string, string>
     */
    public static function queryExpansions(): array
    {
        $core = 'Weline_SystemConfig SystemConfig 统一配置中心 统一配置 系统配置 '
            . '嵌入配置 配置嵌入 config:embed config:field w:config '
            . 'system configuration center system_config';

        return [
            '统一配置中心' => $core,
            '统一配置' => $core,
            '系统配置' => $core,
            '嵌入配置' => $core,
            '配置嵌入' => $core,
            'config:embed' => $core,
            'config:field' => $core,
            'w:config' => $core,
            'systemconfig' => $core,
            'weline_systemconfig' => $core,
            'system_config' => $core,
            '配置' => $core . ' config configuration scope',
        ];
    }

    /**
     * Needles that pull the configuration context role set.
     *
     * @return list<string>
     */
    public static function contextRoleNeedles(): array
    {
        return [
            '配置',
            '统一配置',
            '统一配置中心',
            '系统配置',
            '嵌入配置',
            '配置嵌入',
            'config:embed',
            'config:field',
            'w:config',
            'systemconfig',
            'weline_systemconfig',
            'system_config',
            'scope',
            '站点',
            'website',
            'locale',
            'provider',
        ];
    }

    /**
     * @return list<string>
     */
    public static function contextRoles(): array
    {
        return ['entrypoint', 'view_template', 'provider_query', 'configuration', 'service'];
    }

    public static function matchesConfigIntent(string $task): bool
    {
        $haystack = mb_strtolower($task, 'UTF-8');
        foreach (self::pathIntentNeedles() as $needle) {
            if ($needle !== '' && str_contains($haystack, mb_strtolower($needle, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append SystemConfig retrieval terms for every matching config needle.
     */
    public static function expandTask(string $task): string
    {
        $expanded = [$task];
        $haystack = mb_strtolower($task, 'UTF-8');
        foreach (self::queryExpansions() as $needle => $terms) {
            $needleLower = mb_strtolower($needle, 'UTF-8');
            if ($needleLower !== '' && str_contains($haystack, $needleLower)) {
                $expanded[] = $terms;
            }
        }

        return implode(' ', array_values(array_unique($expanded)));
    }
}