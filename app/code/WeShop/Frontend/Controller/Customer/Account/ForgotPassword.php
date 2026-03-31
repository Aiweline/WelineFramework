<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller\Customer\Account;

class ForgotPassword extends \WeShop\Customer\Controller\Frontend\Account\ForgotPassword
{
    public function index(): string
    {
        return parent::index();
    }

    public function postIndex(): string
    {
        return parent::postIndex();
    }

    public function postResetPassword(): string
    {
        return parent::postResetPassword();
    }

    public function postForgotPassword(): string
    {
        return parent::postForgotPassword();
    }
}
