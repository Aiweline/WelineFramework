<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Backend\Review;

use WeShop\Review\Service\ReviewAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class Index extends BaseController
{
    public function __construct(
        private readonly ReviewAdminPageDataService $reviewAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $page = max(1, (int) $this->request->getParam('page', 1));
        $pageSize = max(1, (int) $this->request->getParam('page_size', 20));
        $reviewIndexUrl = $this->_url->getBackendUrl('*/backend/review');

        $this->assign(array_merge(
            [
                'title' => (string) __('Review Management'),
                'reviewIndexUrl' => $reviewIndexUrl,
                'reviewViewUrl' => $this->_url->getBackendUrl('*/backend/review/view'),
                'reviewApproveUrl' => $this->_url->getBackendUrl('*/backend/review/approve'),
                'reviewRejectUrl' => $this->_url->getBackendUrl('*/backend/review/reject'),
                'reviewDeleteUrl' => $this->_url->getBackendUrl('*/backend/review/delete'),
                'reviewConfigUrl' => $this->_url->getBackendUrl('*/backend/review/config'),
            ],
            $this->reviewAdminPageDataService->getListData($page, $pageSize, [
                'product_id' => $this->request->getParam('product_id', ''),
                'customer_id' => $this->request->getParam('customer_id', ''),
                'status' => $this->request->getParam('status', ''),
                'rating' => $this->request->getParam('rating', ''),
            ])
        ));

        return (string) $this->fetchBase('WeShop_Review::templates/Backend/Review/Index/index.phtml');
    }
}
