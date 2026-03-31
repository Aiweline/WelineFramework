<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller\Customer\Account;

class Logout extends \WeShop\Customer\Controller\Frontend\Account\Logout
{
    public function index(): string
    {
        return parent::index();
    }
}
