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

        $orderId = $this->resolveOrderId($this->readOrderId(), $this->readOrderIncrementId());
        if ($orderId <= 0) {
            $this->getMessageManager()->addError(__('请填写订单号。'));
            $this->redirect($this->buildRmaRoute(0, $this->readOrderIncrementId()));
            return '';
        }

        $this->redirect($this->buildRmaRoute($orderId, $this->readOrderIncrementId()));
        return '';
    }

    public function post(): string
    {
        $customerId = (int) ($this->customerContext->getUserId() ?? 0);
        if ($customerId <= 0) {
            return $this->handleGuestCreate();
        }

        $orderIncrementId = $this->readOrderIncrementId();
        $orderId = $this->resolveOrderId($this->readOrderId(), $orderIncrementId);
        $reason = trim($this->readString('reason'));
        $description = trim($this->readString('description'));
        $type = trim($this->readString('type'));
        if ($type === '') {
            $type = 'return';
        }

        if ($orderId <= 0 || $reason === '') {
            $message = (string) __('请填写订单与原因。');
            if ($this->shouldReturnJson()) {
                return $this->fetchJson(['success' => false, 'message' => $message]);
            }

            $this->getMessageManager()->addError($message);
            $this->redirect($this->buildReturnsRoute($orderId, $orderIncrementId, $this->readReturnAnchor(), $this->readReturnUrl()));
            return '';
        }

        $order = $this->orderService->getOrder($orderId);
        if (!$order || (int) ($order->getData(Order::schema_fields_customer_id) ?? 0) !== $customerId) {
            $message = (string) __('您无权操作该订单。');
            if ($this->shouldReturnJson()) {
                return $this->fetchJson(['success' => false, 'message' => $message]);
            }

            $this->getMessageManager()->addError($message);
            $this->redirect($this->buildReturnsRoute($orderId, $orderIncrementId, $this->readReturnAnchor(), $this->readReturnUrl()));
            return '';
        }

        $this->rmaService->createRma([
            Rma::schema_fields_ORDER_ID => $orderId,
            Rma::schema_fields_CUSTOMER_ID => $customerId,
            Rma::schema_fields_REASON => $reason,
            Rma::schema_fields_DESCRIPTION => $this->buildDescription($type, $description),
            Rma::schema_fields_STATUS => RmaService::STATUS_PENDING,
        ]);

        $successMessage = (string) __('退换货申请已提交。');
        $returnAnchor = $this->readReturnAnchor();
        $returnUrl = $this->buildReturnsRoute($orderId, $orderIncrementId, $returnAnchor, $this->readReturnUrl());

        if ($this->shouldReturnJson()) {
            return $this->fetchJson([
                'success' => true,
                'message' => $successMessage,
                'data' => ['redirect_url' => $returnUrl],
            ]);
        }

        $this->getMessageManager()->addSuccess($successMessage);
        $this->redirect($returnUrl);
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

    protected function readOrderIncrementId(): string
    {
        return $this->readScalarString(
            $this->request->body('order_increment_id')
            ?? $this->request->getPost('order_increment_id')
            ?? $this->request->getParam('order_increment_id')
            ?? null
        );
    }

    protected function readReturnAnchor(): string
    {
        return $this->readScalarString(
            $this->request->body('return_anchor')
            ?? $this->request->getPost('return_anchor')
            ?? $this->request->getParam('return_anchor')
            ?? null
        );
    }

    protected function readReturnUrl(): string
    {
        $returnUrl = $this->readScalarString(
            $this->request->body('return_url')
            ?? $this->request->getPost('return_url')
            ?? $this->request->getParam('return_url')
            ?? null
        );

        return $this->normalizeReturnUrl($returnUrl);
    }

    protected function readString(string $key): string
    {
        return $this->readScalarString(
            $this->request->body($key)
            ?? $this->request->getPost($key)
            ?? $this->request->getParam($key)
            ?? null
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
        $message = (string) __('请先登录。');
        $returnUrl = $this->normalizeReturnUrl(
            trim((string) ($this->request->body('return_url') ?? $this->request->getPost('return_url') ?? $this->request->getParam('return_url') ?? ''))
        );
        $loginUrl = $this->url->getUrl(self::LOGIN_ROUTE);
        if ($returnUrl !== '') {
            $loginUrl .= (str_contains($loginUrl, '?') ? '&' : '?') . 'redirect_url=' . rawurlencode($returnUrl);
        }

        if ($this->shouldReturnJson()) {
            return $this->fetchJson([
                'success' => false,
                'message' => $message,
                'data' => ['redirect_url' => $loginUrl],
            ]);
        }

        $this->getMessageManager()->addError($message);
        $this->redirect($loginUrl);
        return '';
    }

    protected function resolveOrderId(int $orderId, string $orderIncrementId): int
    {
        if ($orderId > 0) {
            return $orderId;
        }

        if ($orderIncrementId === '') {
            return 0;
        }

        $order = $this->orderService->getOrderByIncrementId($orderIncrementId);
        return $order ? (int) ($order->getId() ?? 0) : 0;
    }

    protected function buildRmaRoute(int $orderId, string $orderIncrementId): string
    {
        $params = [];
        if ($orderId > 0) {
            $params['order_id'] = $orderId;
        }
        if ($orderIncrementId !== '') {
            $params['order_increment_id'] = $orderIncrementId;
        }

        $query = $params === [] ? '' : '?' . http_build_query($params);

        return 'rma' . $query;
    }

    protected function buildReturnsRoute(int $orderId, string $orderIncrementId, string $returnAnchor, string $returnUrl = ''): string
    {
        $params = [];
        if ($orderId > 0) {
            $params['order_id'] = $orderId;
        }
        if ($orderIncrementId !== '') {
            $params['order_increment_id'] = $orderIncrementId;
        }
        if ($returnAnchor !== '') {
            $params['return_anchor'] = $returnAnchor;
        }
        $normalizedReturnUrl = $this->normalizeReturnUrl($returnUrl);
        if ($normalizedReturnUrl !== '') {
            $params['return_url'] = $normalizedReturnUrl;
        }

        if ($params === []) {
            return '/customer/account/index#returns';
        }

        return '/customer/account/index?' . http_build_query($params) . '#returns';
    }

    protected function normalizeReturnUrl(string $returnUrl): string
    {
        if ($returnUrl === '') {
            return '';
        }

        $normalized = trim($returnUrl);
        if (preg_match('/^https?:\\/\\//i', $normalized) === 1) {
            return '';
        }

        if ($normalized[0] !== '/') {
            $normalized = '/' . $normalized;
        }

        return $normalized;
    }

    protected function readScalarString(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        if (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            return '';
        }

        return trim((string) $value);
    }
}
