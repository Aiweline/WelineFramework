<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Frontend\Guide;

use Weline\Framework\App\Controller\FrontendController;

/** Storefront returns / exchange policy page (footer help link target). */
final class Returns extends FrontendController
{
    public function index(): string
    {
        $title = (string) __('退换政策');

        $this->layoutType = 'default';
        $this->request->setGet('page_type', 'returns_policy');
        $this->request->setGet('theme_public_route', 'guide/returns');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);

        return (string) $this->fetch('Weline_Shipping::templates/frontend/guide/returns.phtml');
    }
}
