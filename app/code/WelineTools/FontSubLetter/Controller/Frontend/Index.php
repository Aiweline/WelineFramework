<?php

namespace WelineTools\FontSubLetter\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    /**
     * 主页面
     */
    public function index()
    {
        $this->assign('title', __('在线字体压缩工具'));
        $this->assign('subtitle', __('Font Subset Tool'));
        return $this->fetch('index');
    }
}
