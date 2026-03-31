<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Controller\Backend\GiftCard;

use WeShop\GiftCard\Service\GiftCardService;
use Weline\Admin\Controller\BaseController;

class Delete extends BaseController
{
    public function __construct(
        private readonly GiftCardService $giftCardService
    ) {
    }

    public function delete(): string
    {
        $cardId = (int) $this->request->getParam('id', 0);
        $backUrl = (string) $this->request->getParam('back_url', $this->_url->getBackendUrlPath('*/backend/gift-card'));

        try {
            $this->giftCardService->deleteGiftCard($cardId);
            $this->getMessageManager()->addSuccess(__('Gift card deleted successfully.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Failed to delete gift card.'));
        }

        $this->request->getResponse()->redirect($backUrl);
        return '';
    }

    public function index(): string
    {
        return $this->delete();
    }
}
