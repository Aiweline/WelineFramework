<?php

declare(strict_types=1);

namespace WelineTools\ImageProcessor\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

/**
 * @DESC | 图片处理工具集后台入口
 */
class Index extends BackendController
{
    public function index(): string
    {
        $this->assign('title', __('图片处理工具集'));
        return $this->fetch();
    }
}
