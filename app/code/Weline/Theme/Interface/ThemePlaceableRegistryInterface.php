<?php

declare(strict_types=1);

namespace Weline\Theme\Interface;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\WelineTheme;

interface ThemePlaceableRegistryInterface
{
    public function getAvailableList(?string $pageType = null, ?array $filterOptions = null, ?WelineTheme $theme = null, string $area = 'frontend'): array;

    public function find(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): ?ThemeComponentDefinition;

    public function getParamDefinitions(string $module, string $type, string $code, ?WelineTheme $theme = null, string $area = 'frontend'): array;

    public function renderPreview(string $module, string $type, string $code, array $config = [], ?WelineTheme $theme = null, string $area = 'frontend'): string;
}
