<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Controller\Backend\Affiliate;

use WeShop\Affiliate\Service\AffiliateService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('WeShop_Affiliate::affiliate_management_delete', 'Affiliate delete actions', 'mdi mdi-account-remove-outline', 'Delete affiliate records', 'WeShop_Affiliate::affiliate_management')]
class Delete extends BaseController
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    #[Acl('WeShop_Affiliate::affiliate_management_delete_get', 'Open affiliate delete route', 'mdi mdi-account-remove-outline', 'Open affiliate delete route')]
    public function get(): string
    {
        return $this->post();
    }

    #[Acl('WeShop_Affiliate::affiliate_management_delete_post', 'Delete affiliate', 'mdi mdi-delete-outline', 'Delete affiliate data')]
    public function post(): string
    {
        $affiliateId = (int) $this->request->getParam('id', 0);
        $backUrl = (string) $this->request->getParam('back_url', $this->_url->getBackendUrl('*/backend/affiliate'));

        if ($affiliateId <= 0) {
            $this->getMessageManager()->addError((string) __('Invalid affiliate ID.'));
            $this->redirect($backUrl);
            return '';
        }

        try {
            $affiliate = $this->affiliateService->getAffiliateRecord($affiliateId);
            if (!$affiliate) {
                $this->getMessageManager()->addError((string) __('Affiliate not found.'));
                $this->redirect($backUrl);
                return '';
            }

            $affiliate->delete();
            $this->getMessageManager()->addSuccess((string) __('Affiliate deleted successfully.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage() ?: (string) __('Failed to delete affiliate.'));
        }

        $this->redirect($backUrl);
        return '';
    }
}
