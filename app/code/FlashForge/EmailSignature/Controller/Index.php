<?php

namespace FlashForge\EmailSignature\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function index()
    {
        return $this->fetch();
    }
}