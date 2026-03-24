<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller;

class PlaceOrder extends \WeShop\Checkout\Controller\Frontend\Checkout\PlaceOrder
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
