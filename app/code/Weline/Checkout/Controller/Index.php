<?php

declare(strict_types=1);

namespace Weline\Checkout\Controller;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    private const LOGIN_PATH = '/customer/account/login';
    private const CHECKOUT_PATH = '/checkout';

    public function index(): string
    {
        if (!$this->isLoggedIn()) {
            return $this->redirect(self::LOGIN_PATH, ['redirect_url' => self::CHECKOUT_PATH]);
        }

        $this->assign('page_title', __('结账'));
        $this->layoutType = 'checkout';

        return $this->fetch('Weline_Checkout::frontend/checkout/index.phtml');
    }
}
