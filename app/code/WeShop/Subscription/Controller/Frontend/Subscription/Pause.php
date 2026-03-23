<?php

declare(strict_types=1);

namespace WeShop\Subscription\Controller\Frontend\Subscription;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Subscription\Service\SubscriptionService;

class Pause extends BaseController
{
    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionService $subscriptionService
    ) {
    }

    public function postIndex(): string
    {
        return $this->handlePause();
    }

    public function postResume(): string
    {
        return $this->handleResume();
    }

    protected function handlePause(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->unauthorizedResponse();
        }

        $id = $this->readId();
        if ($id <= 0) {
            return $this->errorResponse(__('Subscription ID is required.'));
        }

        try {
            $this->subscriptionService->pauseSubscription($id, $customerId);
        } catch (\Throwable $exception) {
            return $this->errorResponse((string) __('Unable to pause this subscription right now.'));
        }

        return $this->successResponse(__('Subscription paused.'));
    }

    protected function handleResume(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->unauthorizedResponse();
        }

        $id = $this->readId();
        if ($id <= 0) {
            return $this->errorResponse(__('Subscription ID is required.'));
        }

        try {
            $this->subscriptionService->resumeSubscription($id, $customerId);
        } catch (\Throwable $exception) {
            return $this->errorResponse((string) __('Unable to resume this subscription right now.'));
        }

        return $this->successResponse(__('Subscription resumed.'));
    }

    protected function readId(): int
    {
        return (int) (
            $this->request->body('id')
            ?? $this->request->getPost('id')
            ?? $this->request->getParam('id')
            ?? 0
        );
    }

    protected function unauthorizedResponse(): string
    {
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

    protected function successResponse(string $message): string
    {
        if ($this->request->isAjax()) {
            return $this->fetchJson([
                'code' => 200,
                'msg' => $message,
            ]);
        }

        $this->getMessageManager()->addSuccess($message);
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
