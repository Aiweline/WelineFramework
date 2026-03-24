<?php

declare(strict_types=1);

namespace WeShop\Checkout\Controller;

class Success extends \WeShop\Checkout\Controller\Frontend\Checkout\Success
{
    public function index(): string
    {
        return parent::index();
    }
}
