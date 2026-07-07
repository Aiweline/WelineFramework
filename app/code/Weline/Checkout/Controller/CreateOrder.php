<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Checkout\Controller\Frontend\Checkout as FrontendCheckout;

class CreateOrder extends FrontendCheckout
{
    public function index(): string
    {
        return $this->createOrder();
    }
}
