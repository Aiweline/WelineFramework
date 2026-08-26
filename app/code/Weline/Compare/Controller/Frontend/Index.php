<?php

declare(strict_types=1);

namespace Weline\Compare\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;

/**
 * @Cdn cache=false description="商品对比页不边缘缓存"
 */
final class Index extends FrontendController
{
    public function index(): string
    {
        $this->layoutType = 'default';
        $this->request->setGet('page_type', 'compare');
        $this->request->setGet('theme_public_route', 'compare');
        $this->request->setGet('theme_page_title', (string)__('商品对比'));
        $this->assign('page_title', (string)__('商品对比'));

        return (string)$this->fetch('Weline_Compare::templates/frontend/compare/index.phtml');
    }
}
