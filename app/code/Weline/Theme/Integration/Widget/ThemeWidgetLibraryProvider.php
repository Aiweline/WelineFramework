<?php

declare(strict_types=1);

namespace Weline\Theme\Integration\Widget;

use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Widget\Api\WidgetLibraryProviderInterface;

final class ThemeWidgetLibraryProvider implements WidgetLibraryProviderInterface
{
    public function __construct(
        private readonly ThemePlaceableRegistry $placeableRegistry,
    ) {
    }

    public function supports(string $module, string $code, string $area = 'frontend'): bool
    {
        return $module === 'Weline_Theme' && str_contains($code, '/');
    }

    public function getAvailableList(?string $pageType = null, ?array $filterOptions = null, string $area = 'frontend'): array
    {
        return $this->placeableRegistry->getAvailableList($pageType, $filterOptions, null, $area);
    }

    public function getParamDefinitions(string $module, string $code, string $area = 'frontend'): array
    {
        return $this->placeableRegistry->getParamDefinitions($module, 'theme_component', $code, null, $area);
    }

    public function renderPreview(string $module, string $code, array $config = [], string $area = 'frontend'): string
    {
        return $this->placeableRegistry->renderPreview($module, 'theme_component', $code, $config, null, $area);
    }
}
