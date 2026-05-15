<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

final class FastMenuRenderService extends MenuRenderService
{
    public function renderMenu(array $menus): string
    {
        $html = '';

        foreach ($menus as $menu) {
            if (!isset($menu['is_enable']) || !$menu['is_enable']) {
                continue;
            }

            $html .= $this->renderMenuNode($menu);
        }

        return $html;
    }

    public function renderSubMenu(array $submenus): string
    {
        $html = '';

        foreach ($submenus as $submenu) {
            if (($submenu['type'] ?? '') !== 'menus') {
                continue;
            }

            $html .= $this->renderMenuNode($submenu);
        }

        return $html;
    }

    private function renderMenuNode(array $menu): string
    {
        $sourceId = htmlspecialchars((string)($menu['source_id'] ?? ''));
        $icon = htmlspecialchars((string)($menu['icon'] ?? 'mdi mdi-circle'));
        $title = $this->translateMenuTitle((string)($menu['source_name'] ?? ''), (string)($menu['source_id'] ?? ''));
        $route = (string)($menu['route'] ?? '');
        $nodes = is_array($menu['nodes'] ?? null) ? $menu['nodes'] : [];
        $hasNodes = $nodes !== [];
        $hasMenuUrl = $route !== '';

        if (!$hasMenuUrl) {
            $html = "<li class=\"menu-title\" data-source=\"{$sourceId}\">";
            $html .= "<i class=\"{$icon}\"></i><span>{$title}</span>";
            $html .= '</li>';
            if ($hasNodes) {
                $html .= $this->renderSubMenu($nodes);
            }

            return $html;
        }

        $html = "<li data-source=\"{$sourceId}\">";
        if ($hasNodes) {
            $html .= "<a href=\"javascript: void(0);\" data-source=\"{$sourceId}\" class=\"has-arrow waves-effect\">";
        } else {
            $menuUrl = htmlspecialchars($this->formatMenuUrl($menu));
            $html .= "<a href=\"{$menuUrl}\" data-source=\"{$sourceId}\" class=\"waves-effect\">";
        }

        $html .= "<i class=\"{$icon}\"></i>";
        $html .= "<span>{$title}</span>";
        $html .= '</a>';

        if ($hasNodes) {
            $html .= '<ul class="sub-menu" aria-expanded="false">';
            $html .= $this->renderSubMenu($nodes);
            $html .= '</ul>';
        }

        $html .= '</li>';

        return $html;
    }
}
