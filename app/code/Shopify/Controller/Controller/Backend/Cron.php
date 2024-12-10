<?php

namespace Shopify\Controller\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

class Cron extends BackendController
{
    function listing()
    {
        return $this->fetch();
    }
    function index()
    {
        return $this->fetch();
    }
}