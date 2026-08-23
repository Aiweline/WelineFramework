<?php

declare(strict_types=1);

namespace Weline\Admin\Service;

final class FastMenuRenderService extends MenuRenderService
{
    public function renderMenu(array $menus): string
    {
        return parent::renderMenu($menus);
    }

    public function renderSubMenu(array $submenus): string
    {
        return parent::renderSubMenu($submenus);
    }
}
