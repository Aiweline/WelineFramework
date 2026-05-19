<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Controller\Frontend\Wishlist;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Wishlist\Service\WishlistPageDataService;

class Index extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    protected ?string $layoutType = 'account';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly WishlistPageDataService $wishlistPageDataService
    ) {
    }

    public function index(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            $this->getMessageManager()->addError(__('请先登录。'));

            $this->redirect(self::LOGIN_ROUTE);
            return '';
        }

        foreach ($this->wishlistPageDataService->build($customerId) as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch();
    }
}
