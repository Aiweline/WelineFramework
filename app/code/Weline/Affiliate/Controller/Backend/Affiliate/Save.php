<?php

declare(strict_types=1);

namespace Weline\Affiliate\Controller\Backend\Affiliate;

use Weline\Affiliate\Service\AffiliateService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('Weline_Affiliate::commerce:affiliate:programs_actions', 'Affiliate actions', 'mdi mdi-account-edit-outline', 'Create and update affiliate records', 'Weline_Affiliate::commerce:affiliate:programs')]
class Save extends BaseController
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    #[Acl('Weline_Affiliate::commerce:affiliate:programs_save_post', 'Save affiliate', 'mdi mdi-content-save', 'Save affiliate data')]
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
                'website_id' => $this->request->getParam('website_id', 0),
                'store_code' => $this->request->getParam('store_code', ''),
                'channel_code' => $this->request->getParam('channel_code', ''),
                'commission_rate' => $this->request->getParam('commission_rate', 0.0),
                'status' => $this->request->getParam('status', AffiliateService::STATUS_ACTIVE),
            ]);

            $this->getMessageManager()->addSuccess(\__('Affiliate saved.'));
            if ((int) $affiliate->getId() > 0) {
                $backUrl = str_replace('{id}', (string) $affiliate->getId(), $backUrl);
            }
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: \__('Affiliate save failed.'));
        }

        $this->redirect($backUrl);
        return '';
    }

    #[Acl('Weline_Affiliate::commerce:affiliate:programs_save_index', 'Open affiliate save route', 'mdi mdi-content-save-outline', 'Open affiliate save route')]
    public function index(): string
    {
        return $this->post();
    }
}
