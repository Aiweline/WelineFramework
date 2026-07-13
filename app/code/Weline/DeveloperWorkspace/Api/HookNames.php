<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api;

/**
 * Weline_DeveloperWorkspace 对外发布的 Hook 名称契约。
 */
final class HookNames
{
    public const DEVTOOL_PANEL_TABS_AFTER =
        'Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::tabs-after';

    public const DEVTOOL_PANEL_SEARCH_AREAS_AFTER =
        'Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::search-areas-after';

    private function __construct()
    {
    }
}
