<?php

declare(strict_types=1);

namespace WeShop\RecentlyViewed\Controller;

class Remove extends \WeShop\RecentlyViewed\Controller\Frontend\RecentlyViewed\Remove
{
    public function index(): string
    {
        return parent::index();
    }

    public function post(): string
    {
        return parent::post();
    }
}
