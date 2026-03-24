<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Backend\Subscription;

use WeShop\Subscription\Service\SubscriptionAdminPageDataService;
use WeShop\Subscription\Service\SubscriptionService;
use Weline\Admin\Controller\BaseController;

class View extends BaseController
{
    public function __construct(
        private readonly SubscriptionAdminPageDataService $subscriptionAdminPageDataService,
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    public function index(): string
    {
        $id = (int) $this->request->getParam('id', 0);

        if ($id <= 0) {
            $this->getMessageManager()->addWarning(__('Subscription ID is required.'));
            $this->redirect($this->getBackendUrl('*/backend/subscription'));
            return '';
        }

        try {
            $this->assign(array_merge(
                [
                    'title' => (string) __('Subscription Details'),
                ],
                $this->subscriptionAdminPageDataService->getDetailData($id)
            ));
        } catch (\InvalidArgumentException $exception) {
            $this->getMessageManager()->addError($exception->getMessage());
            $this->redirect($this->getBackendUrl('*/backend/subscription'));
            return '';
        }

        return (string) $this->fetchBase('WeShop_Subscription::templates/Backend/Subscription/View/index.phtml');
    }

    public function postCancel(): string
    {
        try {
            $id = (int) $this->request->getPost('id');
            $reason = (string) $this->request->getPost('reason');

            $this->subscriptionService->cancelSubscription($id, 0, $reason);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('Subscription cancelled.'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('Cancel failed: %{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    public function postPause(): string
    {
        try {
            $id = (int) $this->request->getPost('id');

            $this->subscriptionService->pauseSubscription($id);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('Subscription paused.'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('Pause failed: %{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }

    public function postResume(): string
    {
        try {
            $id = (int) $this->request->getPost('id');

            $this->subscriptionService->resumeSubscription($id);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('Subscription resumed.'),
            ]);
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => __('Resume failed: %{error}', ['error' => $e->getMessage()]),
            ]);
        }
    }
}
