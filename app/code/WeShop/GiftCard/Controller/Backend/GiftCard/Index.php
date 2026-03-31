<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Controller\Backend\GiftCard;

use WeShop\GiftCard\Service\GiftCardAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly GiftCardAdminPageDataService $giftCardAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $editingId = (int) $this->request->getParam('id', 0);
        $giftCardIndexUrl = $this->_url->getBackendUrlPath('*/backend/gift-card');

        $this->assign(array_merge(
            [
                'title' => (string) __('Gift Card Management'),
                'giftCardIndexUrl' => $giftCardIndexUrl,
                'giftCardSaveUrl' => $this->_url->getBackendUrlPath('*/backend/gift-card/save'),
            ],
            $this->giftCardAdminPageDataService->getPageData($page, $pageSize, [
                'customer_id' => $this->request->getParam('customer_id', ''),
                'card_number' => $this->request->getParam('card_number', ''),
                'status' => $this->request->getParam('status', ''),
            ], $editingId)
        ));

        return (string) $this->fetchBase('WeShop_GiftCard::backend/templates/gift-card/index.phtml');
    }
}
