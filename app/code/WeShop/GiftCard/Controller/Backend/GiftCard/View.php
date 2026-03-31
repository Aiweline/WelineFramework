<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Controller\Backend\GiftCard;

use WeShop\GiftCard\Service\GiftCardAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class View extends BaseController
{
    public function __construct(
        private readonly GiftCardAdminPageDataService $giftCardAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $cardId = (int) $this->request->getParam('id', 0);
        if ($cardId <= 0) {
            $this->getMessageManager()->addError(__('Invalid gift card ID.'));
            $this->request->getResponse()->redirect($this->_url->getBackendUrlPath('*/backend/gift-card'));
            return '';
        }

        $pageData = $this->giftCardAdminPageDataService->getPageData(1, 1, [], $cardId);
        $giftCard = $pageData['editingRecord'] ?? null;

        if (!$giftCard || (int) ($giftCard['card_id'] ?? 0) <= 0) {
            $this->getMessageManager()->addError(__('Gift card not found.'));
            $this->request->getResponse()->redirect($this->_url->getBackendUrlPath('*/backend/gift-card'));
            return '';
        }

        $this->assign([
            'title' => (string) __('Gift Card Details'),
            'giftCard' => $giftCard,
            'statusOptions' => $pageData['statusOptions'] ?? [],
            'giftCardIndexUrl' => $this->_url->getBackendUrlPath('*/backend/gift-card'),
            'giftCardSaveUrl' => $this->_url->getBackendUrlPath('*/backend/gift-card/save'),
            'giftCardDeleteUrl' => $this->_url->getBackendUrlPath('*/backend/gift-card/delete'),
        ]);

        return (string) $this->fetchBase('WeShop_GiftCard::backend/templates/gift-card/view.phtml');
    }
}
