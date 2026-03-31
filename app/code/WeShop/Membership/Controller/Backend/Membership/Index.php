<?php

declare(strict_types=1);

namespace WeShop\Membership\Controller\Backend\Membership;

use WeShop\Membership\Service\MembershipAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly MembershipAdminPageDataService $membershipAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = min(100, max(1, (int) $this->request->getParam('page_size', 20)));
        $editingId = (int) $this->request->getParam('id', 0);
        $membershipIndexUrl = $this->_url->getBackendUrl('*/backend/membership');

        $this->assign(array_merge(
            [
                'title' => (string) __('Membership Management'),
                'membershipIndexUrl' => $membershipIndexUrl,
                'membershipSaveUrl' => $this->_url->getBackendUrl('*/backend/membership/save'),
            ],
            $this->membershipAdminPageDataService->getPageData($page, $pageSize, [
                'customer_id' => $this->request->getParam('customer_id', ''),
                'level' => $this->request->getParam('level', ''),
            ], $editingId)
        ));

        return (string) $this->fetchBase('WeShop_Membership::backend/templates/membership/index.phtml');
    }
}
