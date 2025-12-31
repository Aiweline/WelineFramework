<?php

namespace WelineTools\FontSubLetter\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

class ClearSelected extends FrontendController
{
    public function index()
    {
        $session = ObjectManager::getInstance(Session::class);
        $session->unsetData('remembered_chars');

        return $this->fetchJson([
            'code' => 200,
            'msg' => '已清理记住的字符'
        ]);
    }
}
