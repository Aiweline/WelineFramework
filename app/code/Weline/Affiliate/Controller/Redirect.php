<?php

declare(strict_types=1);

namespace Weline\Affiliate\Controller;

class Redirect extends \Weline\Affiliate\Controller\Frontend\Affiliate\Redirect
{
    public function index(): string
    {
        return parent::index();
    }
}
