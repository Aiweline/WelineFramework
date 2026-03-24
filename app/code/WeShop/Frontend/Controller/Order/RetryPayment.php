<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller\Order;

class RetryPayment extends \WeShop\Order\Controller\Frontend\Order\RetryPayment
{
    public function index(): string
    {
        return parent::index();
    }
}
