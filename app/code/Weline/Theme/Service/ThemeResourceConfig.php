<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Theme\Model\WelineTheme;

/** Resource policy belongs to the runtime Scope and follows SystemConfig inheritance. */
final class ThemeResourceConfig
{
    public function __construct(private readonly SystemConfig $config)
    {
    }

    /** @return array{css_minify:bool,js_minify:bool,css_merge:bool,js_merge:bool,css_merge_start_widget:int,js_merge_start_widget:int} */
    public function resolve(?WelineTheme $theme = null, string $area = 'frontend'): array
    {
        $identity = RequestContext::scopeIdentity() ?? ScopeIdentity::global();
        $production = defined('PROD') && PROD;
        $result = [];
        foreach (['css_minify', 'js_minify', 'css_merge', 'js_merge'] as $key) {
            $mode = $this->config->resolveTypedConfig(
                'resource_files/' . $key, 'Weline_Theme', $area, $identity, 'default', 'auto'
            )->value;
            $result[$key] = match ($mode) {
                'on' => true,
                'off' => false,
                default => $production,
            };
        }
        foreach (['css_merge_start', 'js_merge_start'] as $key) {
            $value = $this->config->resolveTypedConfig(
                'resource_files/' . $key, 'Weline_Theme', $area, $identity, 'default', 6
            )->value;
            $result[$key . '_widget'] = max(1, (int)$value);
        }
        return $result;
    }
}
