<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller;

class Pause extends \WeShop\Subscription\Controller\Frontend\Subscription\Pause
{
    public function postIndex(): string
    {
        return parent::postIndex();
    }

    public function postResume(): string
    {
        return parent::postResume();
    }
}
