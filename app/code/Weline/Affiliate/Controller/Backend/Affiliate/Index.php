<?php

declare(strict_types=1);

namespace Weline\Affiliate\Controller\Backend\Affiliate;

use Weline\Affiliate\Service\AffiliateAdminPageDataService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('Weline_Affiliate::commerce:affiliate:programs', '万能分销', 'mdi mdi-account-tie-outline', 'Manage affiliate records', 'Weline_Backend::marketing_group')]
class Index extends BaseController
{
    public function __construct(
        private readonly AffiliateAdminPageDataService $affiliateAdminPageDataService
    ) {
    }

    #[Acl('Weline_Affiliate::commerce:affiliate:programs_index', 'View affiliates', 'mdi mdi-account-search-outline', 'View affiliate management page')]
    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $editingId = (int) $this->request->getParam('id', 0);
        $filters = [
            'customer_id' => $this->request->getParam('customer_id', ''),
            'referral_code' => $this->request->getParam('referral_code', ''),
            'status' => $this->request->getParam('status', ''),
            'website_id' => $this->request->getParam('website_id', ''),
            'store_code' => $this->request->getParam('store_code', ''),
            'channel_code' => $this->request->getParam('channel_code', ''),
        ];
        $formScope = [
            'website_id' => $this->request->getParam('form_website_id', ''),
            'store_code' => $this->request->getParam('form_store_code', ''),
            'channel_code' => $this->request->getParam('form_channel_code', ''),
        ];
        $formWebsiteId = max(0, (int) $formScope['website_id']);

        $this->assign(array_merge(
            [
                'title' => (string) \__('Affiliate Management'),
                // Controllers should use the internal URL builder ($this->_url), not a non-existent $this->getBackendUrl().
                'affiliateIndexUrl' => $this->_url->getBackendUrl('*/backend/affiliate'),
                'affiliateSaveUrl' => $this->_url->getBackendUrl('*/backend/affiliate/save'),
                'createScopeReady' => $editingId > 0 || $formWebsiteId > 0,
            ],
            $this->affiliateAdminPageDataService->getPageData($page, $pageSize, $filters, $editingId, $formScope)
        ));

        return (string) $this->fetch();
    }
}
