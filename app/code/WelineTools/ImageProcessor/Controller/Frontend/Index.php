<?php

declare(strict_types=1);

namespace WelineTools\ImageProcessor\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;

/**
 * @DESC | 图片处理工具集前端入口
 */
class Index extends FrontendController
{
    public function index(): string
    {
        $this->assign('title', __('图片处理工具集'));
        return $this->fetch();
    }
}
