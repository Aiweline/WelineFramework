<?php

namespace Weline\Backend\Console\Menu;

use Weline\Backend\Observer\UpgradeMenu;
use Weline\Framework\Console\CommandInterface;

class Collect implements CommandInterface
{
    function __construct(
        private UpgradeMenu $upgradeMenu
    )
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->upgradeMenu->collectMenus();
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '收集菜单';
    }
}