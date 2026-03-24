<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller;

class Cancel extends \WeShop\Subscription\Controller\Frontend\Subscription\Cancel
{
    public function postIndex(): string
    {
        return parent::postIndex();
    }
}
