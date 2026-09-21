<?php

declare(strict_types=1);

namespace Weline\Shipping\Controller\Frontend\Guide;

use Weline\Framework\App\Controller\FrontendController;

/**
 * 退换政策（店面指南）。
 *
 * @Extra type=fpc enabled=true ttl=1800 namespaces=website/default/theme public_path_patterns=/guide/returns
 */
final class Returns extends FrontendController
{
    public function index(): string
    {
        $title = (string) __('退换政策');

        $this->layoutType = 'guide.default';
        $this->request->setGet('page_type', 'guide');
        $this->request->setGet('layout_type', 'guide');
        $this->request->setGet('layout_option', 'default');
        $this->request->setGet('theme_page_title', $title);
        $this->assign('page_title', $title);
        $this->assign('title', $title);

        return (string) $this->fetch('Weline_Shipping::templates/frontend/guide/returns.phtml');
    }
}
