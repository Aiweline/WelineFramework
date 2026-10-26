<?php

declare(strict_types=1);

namespace Weline\Affiliate\Controller\Frontend\Affiliate;

use Weline\Framework\App\Controller\FrontendController;

/**
 * @Extra type=fpc enabled=false
 */
class Index extends FrontendController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    protected ?string $layoutType = 'account';

    public function index(): string
    {
        $customerId = (int) ($this->session->getUserId() ?? 0);
        $centerUrl = $this->getUrl('customer/account/index') . '#affiliate';
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(\__('Please log in to continue.'));
            $this->redirect($this->getUrl(self::LOGIN_ROUTE, ['redirect_url' => $centerUrl]));
            return '';
        }

        $this->redirect($centerUrl);
        return '';
    }
}
