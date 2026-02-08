<?php

declare(strict_types=1);

namespace WelineTools\PsdParser\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;

/**
 * @DESC | PSD 在线解析器前端入口
 */
class Index extends FrontendController
{
    public function index(): string
    {
        $this->assign('title', __('PSD 在线快速解析器'));
        return $this->fetch();
    }
}
