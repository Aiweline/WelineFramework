<?php

declare(strict_types=1);

namespace WeShop\Order\Controller\Order;

class Cancel extends \WeShop\Order\Controller\Frontend\Order\Cancel
{
    public function index(): string
    {
        return parent::postIndex();
    }

    public function post(): string
    {
        return parent::postIndex();
    }
}
