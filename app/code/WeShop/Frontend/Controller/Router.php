<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller;

use Weline\Framework\Router\RouterInterface;

class Router implements RouterInterface
{
    public static function process(string &$path, array &$rule): void
    {
        // WeShop must not decide the framework homepage from Theme state.
        // The default root entry is owned by Weline_Frontend and Weline_Theme.
    }
}
