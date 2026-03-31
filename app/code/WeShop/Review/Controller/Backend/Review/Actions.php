<?php

declare(strict_types=1);

namespace WeShop\Review\Controller\Backend\Review;

use WeShop\Review\Service\ReviewService;
use Weline\Admin\Controller\BaseController;
use Weline\Framework\Http\Response;

class Actions extends BaseController
{
    public function __construct(
        private readonly ReviewService $reviewService
    ) {
    }

    /**
     * 审核通过评价
     */
    public function approve(): Response
    {
        $reviewId = (int) $this->request->getParam('id', 0);

        if ($reviewId <= 0) {
            return $this->jsonError(__('Invalid review ID'), 400);
        }

        try {
            $this->reviewService->approveReview($reviewId, \WeShop\Review\Model\Review::STATUS_APPROVED);
            return $this->jsonSuccess(__('Review approved successfully'));
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 拒绝评价
     */
    public function reject(): Response
    {
        $reviewId = (int) $this->request->getParam('id', 0);

        if ($reviewId <= 0) {
            return $this->jsonError(__('Invalid review ID'), 400);
        }

        try {
            $this->reviewService->approveReview($reviewId, \WeShop\Review\Model\Review::STATUS_REJECTED);
            return $this->jsonSuccess(__('Review rejected successfully'));
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }

    /**
     * 删除评价
     */
    public function delete(): Response
    {
        $reviewId = (int) $this->request->getParam('id', 0);

        if ($reviewId <= 0) {
            return $this->jsonError(__('Invalid review ID'), 400);
        }

        try {
            /** @var \WeShop\Review\Model\Review $reviewModel */
            $reviewModel = \Weline\Framework\Manager\ObjectManager::getInstance(\WeShop\Review\Model\Review::class);
            $reviewModel->load($reviewId);

            if (!$reviewModel->getId()) {
                return $this->jsonError(__('Review not found'), 404);
            }

            $reviewModel->delete();
            return $this->jsonSuccess(__('Review deleted successfully'));
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage());
        }
    }
}
