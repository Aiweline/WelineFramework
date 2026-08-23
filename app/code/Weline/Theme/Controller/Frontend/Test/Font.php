<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\Test;

use Weline\Framework\App\Controller\FrontendController;

/**
 * theme:font 验收页：默认模块 / 显式模块 / chars 字符子集。
 */
class Font extends FrontendController
{
    public function getIndex()
    {
        $this->assign('title', __('theme:font 字符子集测试'));

        return $this->fetch('Weline_Theme::templates/frontend/test/font.phtml');
    }
}
