<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller\Order;

class Cancel extends \WeShop\Order\Controller\Frontend\Order\Cancel
{
    public function index(): string
    {
        $this->redirect('weshop/order/list');
        return '';
    }

    public function post(): string
    {
        return parent::postIndex();
    }

    public function postIndex(): string
    {
        return parent::postIndex();
    }
}
