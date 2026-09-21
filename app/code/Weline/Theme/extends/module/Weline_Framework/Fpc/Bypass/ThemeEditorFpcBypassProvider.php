<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Fpc\Bypass;

use Weline\Framework\Http\Fpc\FpcBypassRuleProviderInterface;

/**
 * 主题编辑器 / 真实预览：请求参数与预览 Cookie 命中则 bypass 公共 FPC。
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
                'id' => 'theme.preview_token_cookie',
                'match' => [
                    'cookie_name_regex' => '/(?:^|;\\s*)weline_preview_token(?:_w\\d+)?=/i',
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
        ];
    }
}
