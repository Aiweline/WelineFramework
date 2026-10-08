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
                'id' => 'theme.editor_preview_query',
                'match' => [
                    'query_keys' => [
                        'preview',
                        'visual_editor',
                        'editor_mode',
                        'workspace_preview',
                        'debug_hooks',
                        'no_cache',
                        'nocache',
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
