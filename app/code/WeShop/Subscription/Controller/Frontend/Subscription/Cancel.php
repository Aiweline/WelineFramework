<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Frontend\Subscription;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Subscription\Service\SubscriptionService;

class Cancel extends BaseController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    public function postIndex(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            if ($this->request->isAjax()) {
                return $this->fetchJson([
                    'code' => 401,
                    'msg' => __('Please login first.'),
                    'data' => ['redirect_url' => $this->getUrl('customer/account/login')],
                ]);
            }

            $this->getMessageManager()->addError(__('Please login first.'));
            $this->redirect('customer/account/login');
            return '';
        }

        $id = (int) (
            $this->request->body('id')
            ?? $this->request->getPost('id')
            ?? $this->request->getParam('id')
            ?? 0
        );
        if ($id <= 0) {
            return $this->errorResponse(__('Subscription ID is required.'));
        }

        $reason = trim((string) (
            $this->request->body('reason')
            ?? $this->request->getPost('reason')
            ?? $this->request->getParam('reason')
            ?? ''
        ));

        try {
            $this->subscriptionService->cancelSubscription($id, $customerId, $reason);
        } catch (\Throwable $exception) {
            return $this->errorResponse(__('Unable to cancel this subscription right now.'));
        }

        if ($this->request->isAjax()) {
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('Subscription cancelled.'),
            ]);
        }

        $this->getMessageManager()->addSuccess(__('Subscription cancelled.'));
        $this->redirect('subscription');
        return '';
    }

    protected function errorResponse(string $message): string
    {
        if ($this->request->isAjax()) {
            return $this->fetchJson([
                'code' => 400,
                'msg' => $message,
            ]);
        }

        $this->getMessageManager()->addError($message);
        $this->redirect('subscription');
        return '';
    }
}
