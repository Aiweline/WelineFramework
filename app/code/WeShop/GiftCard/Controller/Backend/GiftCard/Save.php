<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Controller\Backend\GiftCard;

use WeShop\GiftCard\Service\GiftCardService;
use Weline\Admin\Controller\BaseController;

class Save extends BaseController
{
    public function __construct(
        private readonly GiftCardService $giftCardService
    ) {
    }

    public function post(): string
    {
        $backUrl = (string) $this->request->getParam('back_url', $this->_url->getBackendUrlPath('*/backend/gift-card'));

        try {
            $giftCard = $this->giftCardService->saveGiftCard([
                'card_id' => $this->request->getParam('card_id', 0),
                'customer_id' => $this->request->getParam('customer_id', 0),
                'card_number' => $this->request->getParam('card_number', ''),
                'amount' => $this->request->getParam('amount', 0),
                'balance' => $this->request->getParam('balance', 0),
                'status' => $this->request->getParam('status', GiftCardService::STATUS_ACTIVE),
                'expires_at' => $this->request->getParam('expires_at', ''),
            ]);

            $this->getMessageManager()->addSuccess(__('Gift card saved.'));
            $this->request->getResponse()->redirect(
                $this->_url->getBackendUrlPath('*/backend/gift-card', ['id' => $giftCard->getId()])
            );
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Gift card save failed.'));
            $this->request->getResponse()->redirect($backUrl);
            return '';
        }
    }

    public function index(): string
    {
        return $this->post();
    }
}
