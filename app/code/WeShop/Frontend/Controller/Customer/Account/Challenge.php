<?php

declare(strict_types=1);

namespace WeShop\Frontend\Controller\Customer\Account;

class Challenge extends \WeShop\Customer\Controller\Frontend\Account\Challenge
{
    public function index(): string
    {
        return parent::index();
    }

    public function postIndex(): array|string
    {
        return parent::postIndex();
    }
}
