<?php

declare(strict_types=1);

namespace WeShop\Filters\Controller;

class Options extends \WeShop\Filters\Controller\Frontend\Ajax
{
    public function index(): string
    {
        return parent::options();
    }
}
