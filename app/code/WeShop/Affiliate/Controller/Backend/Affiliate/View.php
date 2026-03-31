<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Controller\Backend\Affiliate;

use WeShop\Affiliate\Service\AffiliateAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class View extends BaseController
{
    public function __construct(
        private readonly AffiliateAdminPageDataService $affiliateAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $affiliateId = (int) $this->request->getParam('id', 0);
        if ($affiliateId <= 0) {
            $this->getMessageManager()->addError((string) __('Invalid affiliate ID.'));
            $this->redirect($this->_url->getBackendUrl('*/backend/affiliate'));
            return '';
        }

        $pageData = $this->affiliateAdminPageDataService->getPageData(1, 1, [], $affiliateId);
        $affiliate = $pageData['editingRecord'] ?? null;

        if (!$affiliate || (int) ($affiliate['affiliate_id'] ?? 0) <= 0) {
            $this->getMessageManager()->addError((string) __('Affiliate not found.'));
            $this->redirect($this->_url->getBackendUrl('*/backend/affiliate'));
            return '';
        }

        $this->assign([
            'title' => (string) __('Affiliate Details'),
            'affiliate' => $affiliate,
            'statusOptions' => $pageData['statusOptions'] ?? [],
            'affiliateIndexUrl' => $this->_url->getBackendUrl('*/backend/affiliate'),
            'affiliateSaveUrl' => $this->_url->getBackendUrl('*/backend/affiliate/save'),
        ]);

        return (string) $this->fetchBase('WeShop_Affiliate::backend/templates/affiliate/view.phtml');
    }
}
