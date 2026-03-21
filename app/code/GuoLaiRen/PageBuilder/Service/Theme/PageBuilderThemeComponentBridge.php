<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 将 PageBuilder 布局中的组件 code 解析为 Weline 主题目录中的部件定义（含 DB 虚拟部件）
 */

namespace GuoLaiRen\PageBuilder\Service\Theme;

use GuoLaiRen\PageBuilder\Service\Layout\LayoutConfigNormalizer;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeComponentCatalog;

class PageBuilderThemeComponentBridge
{
    public function __construct(
        private readonly ThemeComponentCatalog $themeComponentCatalog,
        private readonly LayoutConfigNormalizer $layoutConfigNormalizer,
        private readonly WelineTheme $welineTheme,
    ) {
    }

    /**
     * 在指定 Weline 主题层上解析部件定义（虚拟部件需 source_type=virtual 且挂在该 theme_id 下）
     */
    public function resolveDefinition(string $componentCode, int $themeId, string $area = 'frontend'): ?ThemeComponentDefinition
    {
        if ($themeId <= 0 || trim($componentCode) === '') {
            return null;
        }
        $area = strtolower($area) === 'backend' ? 'backend' : 'frontend';

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            return null;
        }

        $norm = $this->layoutConfigNormalizer->normalizeComponentCode($componentCode);
        if ($norm === '') {
            return null;
        }

        foreach ([$componentCode, $norm] as $tryCode) {
            $tryCode = trim($tryCode);
            if ($tryCode === '') {
                continue;
            }
            $found = $this->themeComponentCatalog->find('Weline_Theme', 'theme_component', $tryCode, $area, $theme);
            if ($found !== null) {
                return $found;
            }
        }

        $definitions = $this->themeComponentCatalog->getDefinitions($area, $theme);
        foreach ($definitions as $def) {
            if (!$def instanceof ThemeComponentDefinition) {
                continue;
            }
            if ($def->module !== 'Weline_Theme' || $def->type !== 'theme_component') {
                continue;
            }
            if ($def->code === $norm || $def->code === $componentCode) {
                return $def;
            }
            if (str_contains($def->code, '/')) {
                $base = basename(str_replace('\\', '/', $def->code));
                if ($base === $norm || $base === $componentCode) {
                    return $def;
                }
            }
        }

        return null;
    }
}
