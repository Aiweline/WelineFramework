<?php

declare(strict_types=1);

namespace WelineTools\PsdParser\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

/**
 * @DESC | PSD 在线解析器后台入口
 */
class Index extends BackendController
{
    public function index(): string
    {
        $this->assign('title', __('PSD 在线快速解析器'));
        return $this->fetch();
    }
}
