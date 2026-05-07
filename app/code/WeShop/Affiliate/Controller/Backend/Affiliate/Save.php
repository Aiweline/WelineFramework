<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Controller\Backend\Affiliate;

use WeShop\Affiliate\Service\AffiliateService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Affiliate::affiliate_management_actions', 'Affiliate actions', 'mdi mdi-account-edit-outline', 'Create and update affiliate records', 'WeShop_Affiliate::affiliate_management')]
class Save extends BaseController
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    #[Acl('WeShop_Affiliate::affiliate_management_save_post', 'Save affiliate', 'mdi mdi-content-save', 'Save affiliate data')]
    public function post(): string
    {
        $defaultBackUrl = $this->_url->getBackendUrl('*/backend/affiliate');
        $backUrl = (string) $this->request->getParam('back_url', $defaultBackUrl);
        if (trim($backUrl) === '') {
            $backUrl = $defaultBackUrl;
        }

        try {
            $affiliate = $this->affiliateService->saveAffiliate([
                'affiliate_id' => $this->request->getParam('affiliate_id', 0),
                'customer_id' => $this->request->getParam('customer_id', 0),
                'commission_rate' => $this->request->getParam('commission_rate', 0),
                'status' => $this->request->getParam('status', AffiliateService::STATUS_ACTIVE),
            ]);

            $this->getMessageManager()->addSuccess(__('Affiliate saved.'));
            if ((int) $affiliate->getId() > 0) {
                $backUrl = str_replace('{id}', (string) $affiliate->getId(), $backUrl);
            }
            $this->redirect($backUrl);
            return '';
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: __('Affiliate save failed.'));
            $this->redirect($backUrl);
            return '';
        }
    }

    #[Acl('WeShop_Affiliate::affiliate_management_save_index', 'Open affiliate save route', 'mdi mdi-content-save-outline', 'Open affiliate save route')]
    public function index(): string
    {
        return $this->post();
    }
}
