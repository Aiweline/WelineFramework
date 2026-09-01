<?php

declare(strict_types=1);

namespace Weline\Affiliate\Controller\Frontend\Affiliate;

use Weline\Framework\App\Controller\FrontendController;

class Index extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    protected ?string $layoutType = 'account';

    public function index(): string
    {
        $customerId = (int) ($this->session->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(\__('Please log in to continue.'));
            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        $this->redirect($this->getUrl('customer/account/index') . '#affiliate');
        return '';
    }
}
