<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Controller\Backend\Affiliate;

use WeShop\Affiliate\Service\AffiliateAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly AffiliateAdminPageDataService $affiliateAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $editingId = (int) $this->request->getParam('id', 0);
        $filters = [
            'customer_id' => $this->request->getParam('customer_id', ''),
            'referral_code' => $this->request->getParam('referral_code', ''),
            'status' => $this->request->getParam('status', ''),
        ];

        $this->assign(array_merge(
            [
                'title' => (string) __('Affiliate Management'),
                // Controllers should use the internal URL builder ($this->_url), not a non-existent $this->getBackendUrl().
                'affiliateIndexUrl' => $this->_url->getBackendUrl('*/backend/affiliate'),
                'affiliateSaveUrl' => $this->_url->getBackendUrl('*/backend/affiliate/save'),
            ],
            $this->affiliateAdminPageDataService->getPageData($page, $pageSize, $filters, $editingId)
        ));

        return (string) $this->fetchBase('WeShop_Affiliate::backend/templates/affiliate/index.phtml');
    }
}
