<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Backend\Review;

use WeShop\Review\Service\ReviewAdminPageDataService;
use Weline\Admin\Controller\BaseController;

class View extends BaseController
{
    public function __construct(
        private readonly ReviewAdminPageDataService $reviewAdminPageDataService
    ) {
    }

    public function index(): string
    {
        $reviewId = (int) $this->request->getParam('id', 0);

        if ($reviewId <= 0) {
            $this->redirect($this->_url->getBackendUrl('*/backend/review'));
            return '';
        }

        try {
            $data = $this->reviewAdminPageDataService->getDetailData($reviewId);
            $this->assign(array_merge(
                [
                    'title' => (string) __('Review Detail'),
                    'reviewIndexUrl' => $this->_url->getBackendUrl('*/backend/review'),
                    'reviewApproveUrl' => $this->_url->getBackendUrl('*/backend/review/approve'),
                    'reviewRejectUrl' => $this->_url->getBackendUrl('*/backend/review/reject'),
                    'reviewDeleteUrl' => $this->_url->getBackendUrl('*/backend/review/delete'),
                ],
                $data
            ));

            return (string) $this->fetchBase('WeShop_Review::templates/Backend/Review/View/index.phtml');
        } catch (\InvalidArgumentException $e) {
            $this->redirect($this->_url->getBackendUrl('*/backend/review'));
            return '';
        }
    }
}
