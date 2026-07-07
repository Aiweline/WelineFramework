<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Checkout\Controller\Frontend\Checkout as FrontendCheckout;

class ProcessPayment extends FrontendCheckout
{
    public function index(): string
    {
        return $this->processPayment();
    }
}
