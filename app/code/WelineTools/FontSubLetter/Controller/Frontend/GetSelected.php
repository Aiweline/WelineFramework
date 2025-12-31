<?php

namespace WelineTools\FontSubLetter\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

class GetSelected extends FrontendController
{
    public function index()
    {
        $session = ObjectManager::getInstance(Session::class);
        $remembered = $session->getData('remembered_chars') ?? [
            'selectedChars' => [],
            'customChars' => ''
        ];

        return $this->fetchJson([
            'code' => 200,
            'data' => $remembered
        ]);
    }
}
