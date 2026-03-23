<?php

declare(strict_types=1);

namespace WeShop\RMA\Controller\Frontend\RMA;

use WeShop\Customer\Api\CustomerContextInterface;
use WeShop\Frontend\Controller\BaseController;
use WeShop\Order\Model\Order;
use WeShop\Order\Service\OrderService;
use WeShop\RMA\Model\Rma;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Http\Url;

class Create extends BaseController
{
    private const LOGIN_ROUTE = 'customer/account/login';

    public function __construct(
        private readonly CustomerContextInterface $customerContext,
        private readonly OrderService $orderService,
        private readonly RmaService $rmaService,
        private readonly Url $url
    ) {
    }

    public function index(): string
    {
        if (strtoupper((string) $this->request->getMethod()) === 'POST') {
            return $this->post();
        }

        $orderId = $this->readOrderId();
        if ($orderId <= 0) {
            $this->getMessageManager()->addError(__('Order ID is required.'));
            $this->redirect('rma');
            return '';
        }

        $this->redirect('rma?order_id=' . $orderId);
        return '';
    }

    public function post(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->handleGuestCreate();
        }

        $orderId = $this->readOrderId();
        $reason = trim($this->readString('reason'));
        $description = trim($this->readString('description'));
        $type = trim($this->readString('type'));
        if ($type === '') {
            $type = 'return';
        }

        if ($orderId <= 0 || $reason === '') {
            $message = (string) __('Order and reason are required.');
            if ($this->shouldReturnJson()) {
                return $this->fetchJson(['success' => false, 'message' => $message]);
            }

            $this->getMessageManager()->addError($message);
            $this->redirect('rma');
            return '';
        }

        $order = $this->orderService->getOrder($orderId);
        if (!$order || (int) ($order->getData(Order::schema_fields_customer_id) ?? 0) !== $customerId) {
            $message = (string) __('You do not have access to this order.');
            if ($this->shouldReturnJson()) {
                return $this->fetchJson(['success' => false, 'message' => $message]);
            }

            $this->getMessageManager()->addError($message);
            $this->redirect('rma');
            return '';
        }

        $this->rmaService->createRma([
            Rma::schema_fields_ORDER_ID => $orderId,
            Rma::schema_fields_CUSTOMER_ID => $customerId,
            Rma::schema_fields_REASON => $reason,
            Rma::schema_fields_DESCRIPTION => $this->buildDescription($type, $description),
            Rma::schema_fields_STATUS => RmaService::STATUS_PENDING,
        ]);

        $successMessage = (string) __('Your return request has been submitted.');
        if ($this->shouldReturnJson()) {
            return $this->fetchJson([
                'success' => true,
                'message' => $successMessage,
                'data' => ['redirect_url' => $this->url->getUrl('rma?order_id=' . $orderId)],
            ]);
        }

        $this->getMessageManager()->addSuccess($successMessage);
        $this->redirect('rma?order_id=' . $orderId);
        return '';
    }

    protected function shouldReturnJson(): bool
    {
        return $this->request->isAjax() || strtoupper((string) $this->request->getMethod()) === 'POST';
    }

    protected function readOrderId(): int
    {
        return (int) (
            $this->request->body('order_id')
            ?? $this->request->getPost('order_id')
            ?? $this->request->getParam('order_id')
            ?? 0
        );
    }

    protected function readString(string $key): string
    {
        return (string) (
            $this->request->body($key)
            ?? $this->request->getPost($key)
            ?? $this->request->getParam($key)
            ?? ''
        );
    }

    protected function buildDescription(string $type, string $description): string
    {
        if ($type === '') {
            return $description;
        }

        return trim('[' . $type . '] ' . $description);
    }

    protected function handleGuestCreate(): string
    {
        $message = (string) __('Please log in to continue.');
        if ($this->shouldReturnJson()) {
            return $this->fetchJson([
                'success' => false,
                'message' => $message,
                'data' => ['redirect_url' => $this->url->getUrl(self::LOGIN_ROUTE)],
            ]);
        }

        $this->getMessageManager()->addError($message);
        $this->redirect(self::LOGIN_ROUTE);
        return '';
    }
}
