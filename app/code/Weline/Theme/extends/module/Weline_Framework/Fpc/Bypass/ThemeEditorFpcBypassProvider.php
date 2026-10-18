<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Fpc\Bypass;

use Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface;

/**
 * 主题编辑器 / 真实预览：query、env、`/~preview/` path 命中则 bypass 公共 FPC。
 * Cookie 不再作为预览身份，故不登记 cookie 旁路规则。
 */
final class ThemeEditorFpcBypassProvider implements FpcBypassRuleProviderInterface
{
    public function rules(): array
    {
        return [
            [
                // UC-FPC-Pierce / Extra UC-05 (also Framework PierceStampFpcBypassProvider).
                'id' => 'framework.fpc_pierce_stamp',
                'match' => [
                    'env_flags' => ['fpc_pierce_stamp'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                'id' => 'theme.editor_preview_query',
                'match' => [
                    // Open-source short names stay; without pierce stamp they must not bypass.
                    'require_pierce_stamp' => true,
                    'query_keys' => [
                        'preview',
                        'visual_editor',
                        'editor_mode',
                        'workspace_preview',
                        'debug_hooks',
                        'no_cache',
                        'nocache',
                        '_wb_nc',
                        'wls_tpl_perf',
                        'weline_preview_token',
                    ],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                'id' => 'theme.editor_mode_env',
                'match' => [
                    'env_flags' => ['editor_mode'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
            [
                'id' => 'theme.live_preview_path_prefix',
                'match' => [
                    'uri_path_prefixes' => ['/~preview/'],
                ],
                'effect' => 'bypass_serve_and_publish',
            ],
        ];
    }
}
