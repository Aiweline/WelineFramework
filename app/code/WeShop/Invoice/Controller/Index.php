<?php

declare(strict_types=1);

namespace WeShop\Invoice\Controller;

class Index extends \WeShop\Invoice\Controller\Frontend\Invoice\Index
{
    public function index(): string
    {
        return parent::index();
    }
}
