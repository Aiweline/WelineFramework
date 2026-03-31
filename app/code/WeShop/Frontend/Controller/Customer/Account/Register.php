<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller\Customer\Account;

class Register extends \WeShop\Customer\Controller\Frontend\Account\Register
{
    public function index(): string
    {
        return parent::index();
    }

    public function postIndex(): string
    {
        return parent::postIndex();
    }

    public function postRegister(): string
    {
        return parent::postRegister();
    }
}
