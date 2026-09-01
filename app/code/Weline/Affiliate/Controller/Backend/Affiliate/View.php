<?php

declare(strict_types=1);

namespace Weline\Affiliate\Controller\Backend\Affiliate;

use Weline\Affiliate\Service\AffiliateAdminPageDataService;
use Weline\Affiliate\Service\AffiliateService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Acl\Acl;

#[Acl('Weline_Affiliate::commerce:affiliate:programs_view', 'Affiliate view actions', 'mdi mdi-eye-outline', 'View affiliate details', 'Weline_Affiliate::commerce:affiliate:programs')]
class View extends BaseController
{
    public function __construct(
        private readonly AffiliateAdminPageDataService $affiliateAdminPageDataService,
        private readonly AffiliateService $affiliateService
    ) {
    }

    #[Acl('Weline_Affiliate::commerce:affiliate:programs_view_index', 'View affiliate detail', 'mdi mdi-eye', 'View affiliate detail page')]
    public function index(): string
    {
        $affiliateId = (int) $this->request->getParam('id', 0);
        if ($affiliateId <= 0) {
            $this->getMessageManager()->addError((string) \__('Invalid affiliate ID.'));
            $this->redirect($this->_url->getBackendUrl('*/backend/affiliate'));
            return '';
        }

        $pageData = $this->affiliateAdminPageDataService->getPageData(1, 1, [], $affiliateId);
        $affiliate = $pageData['editingRecord'] ?? null;

        if (!$affiliate || (int) ($affiliate['affiliate_id'] ?? 0) <= 0) {
            $this->getMessageManager()->addError((string) \__('Affiliate not found.'));
            $this->redirect($this->_url->getBackendUrl('*/backend/affiliate'));
            return '';
        }

        $this->assign([
            'title' => (string) \__('Affiliate Details'),
            'affiliate' => $affiliate,
            'analytics' => $this->affiliateService->buildAdminAnalytics($affiliateId),
            'statusOptions' => $pageData['statusOptions'] ?? [],
            'affiliateIndexUrl' => $this->_url->getBackendUrl('*/backend/affiliate'),
            'affiliateSaveUrl' => $this->_url->getBackendUrl('*/backend/affiliate/save'),
        ]);

        return (string) $this->fetch();
    }
}
