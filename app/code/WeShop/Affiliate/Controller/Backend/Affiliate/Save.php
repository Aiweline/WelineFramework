<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Controller\Backend\Affiliate;

use WeShop\Affiliate\Service\AffiliateService;
use Weline\Admin\Controller\BaseController;

class Save extends BaseController
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    public function post(): string
    {
        $backUrl = (string) $this->request->getParam('back_url', $this->getBackendUrl('*/backend/affiliate'));

        try {
            $affiliate = $this->affiliateService->saveAffiliate([
                'affiliate_id' => $this->request->getParam('affiliate_id', 0),
                'customer_id' => $this->request->getParam('customer_id', 0),
                'commission_rate' => $this->request->getParam('commission_rate', 0),
                'status' => $this->request->getParam('status', AffiliateService::STATUS_ACTIVE),
            ]);

            $this->getMessageManager()->addSuccess(__('Affiliate saved.'));
            $this->redirect($this->getBackendUrl('*/backend/affiliate', ['id' => $affiliate->getId()]));
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Affiliate save failed.'));
            $this->redirect($backUrl);
            return '';
        }
    }

    public function index(): string
    {
        return $this->post();
    }
}
