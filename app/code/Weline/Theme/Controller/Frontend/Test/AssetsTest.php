<?php

declare(strict_types=1);

namespace Weline\Theme\Controller\Frontend\Test;

use Weline\Framework\App\Controller\FrontendController;

/**
 * 前台资源/字体标签验收（复用已注册路由 theme/frontend/test/assets-test）。
 */
class AssetsTest extends FrontendController
{
    /**
     * theme:font / theme:css / theme:js 验收页（含 chars 字符子集）。
     */
    public function getIndex()
    {
        $this->assign('title', __('theme:font 字符子集测试'));

        return $this->fetch('Weline_Theme::templates/frontend/test/font.phtml');
    }
}
