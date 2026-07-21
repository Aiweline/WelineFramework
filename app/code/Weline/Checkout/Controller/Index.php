<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    public function index(): string
    {
        // 默认允许匿名结账：未登录也直接渲染结账页，身份由 CheckoutIdentityService 处理。
        $this->assign('page_title', __('结账'));
        $this->layoutType = 'checkout';

        return $this->fetch('Weline_Checkout::frontend/checkout/index.phtml');
    }
}
