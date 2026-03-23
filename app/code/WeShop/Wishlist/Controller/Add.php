<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Controller;

class Add extends \WeShop\Wishlist\Controller\Frontend\Wishlist\Add
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
