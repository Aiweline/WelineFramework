<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Api\OrderFacadeInterface;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\ResumeRequest;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Shell browser return entry: OAuth Connect.complete or Provider resumePayment.
 */
final class PaymentBrowserReturnDispatcher
{
    public function __construct(
        private readonly PaymentConnectDispatcher $connectDispatcher,
        private readonly PaymentMethodManager $methodManager,
        private readonly PaymentBrowserReturnLandingOrchestrator $landingOrchestrator,
        private readonly PaymentBrowserReturnContextResolver $returnContext,
        private readonly PaymentShellCallbackUrlCatalog $urlCatalog,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function dispatch(array $params): array
    {
        $oauthCode = trim((string) ($params['code'] ?? ''));
        $oauthState = trim((string) ($params['state'] ?? ''));
        if ($oauthCode !== '' && $oauthState !== '') {
            return $this->dispatchOAuth($params);
        }

        $context = $this->returnContext->resolve($params);
        if ($context !== null && $context['method_code'] !== '' && $context['transaction_no'] !== '') {
            return $this->dispatchResume($params, $context);
        }

        if ($this->isProductionLive()) {
            return [
                'redirect_path' => '/',
                'redirect_params' => [],
                'absolute' => false,
            ];
        }

        return [
            'render' => true,
            'template' => 'browser-return',
            'status' => 'ready',
            'title' => (string) __('支付浏览器回跳入口'),
            'message' => (string) __(
                '【开发环境提示】此地址是支付模块按支付码区分的浏览器回跳入口，已就绪。正常支付或 OAuth 授权会自动带回 shell_token / transaction_no / 网关参数；直接打开不会完成支付。生产环境空参访问会跳转首页。'
            ),
        ];
    }

    private function isProductionLive(): bool
    {
        $systemEnv = strtolower(trim((string) Env::get('system.env', '')));
        if ($systemEnv === 'production' || $systemEnv === 'prod') {
            return true;
        }

        $deploy = strtolower(trim((string) Env::get('deploy', '')));
        if ($deploy === '') {
            $deploy = strtolower(trim((string) Env::get('system.deploy', '')));
        }

        return $deploy === 'production' || $deploy === 'prod';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function dispatchOAuth(array $params): array
    {
        try {
            $completed = $this->connectDispatcher->complete($params);
            $result = $completed['result'];
            MessageManager::success(
                (string) ($result['message'] ?? __('支付方式授权已完成，凭据已写入当前配置。')),
                (string) ($result['message_title'] ?? __('授权完成')),
            );
            $connect = $completed['connect'];
            $scopeContext = $this->scopeContextFromResult($result);

            return [
                'redirect_path' => $connect->connectConfigUrl(
                    \is_string($result['scope'] ?? null) ? (string) $result['scope'] : null,
                    $scopeContext,
                ),
                'redirect_params' => [],
                'absolute' => true,
            ];
        } catch (\Throwable $exception) {
            MessageManager::add_error($exception->getMessage());
            $fallback = $this->connectDispatcher->findConnectByState(trim((string) ($params['state'] ?? '')));
            if ($fallback !== null) {
                return [
                    'redirect_path' => $fallback->connectConfigUrl(null, []),
                    'redirect_params' => [],
                    'absolute' => true,
                ];
            }

            return $this->statusRedirect([]);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param array{
     *   method_code:string,
     *   transaction_no:string,
     *   target_scope:string,
     *   gateway_token:string,
     *   source:string
     * } $context
     * @return array<string, mixed>
     */
    private function dispatchResume(array $params, array $context): array
    {
        $transaction = $this->loadTransaction($context);
        if ($transaction === null || !$transaction->getId()) {
            MessageManager::add_error((string) __('Payment transaction number is required.'));

            return $this->statusRedirect([
                'transaction_no' => $context['transaction_no'],
            ]);
        }

        if ($transaction->isSuccess()) {
            return $this->landingOrchestrator->decide($transaction);
        }

        $methodCode = trim((string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE));
        if ($methodCode === '') {
            $methodCode = $context['method_code'];
        }

        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $methodCode);
        if (!$paymentMethod->getId()) {
            MessageManager::add_error((string) __('支付方式不存在'));

            return $this->statusRedirect([
                'transaction_no' => (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
            ]);
        }

        $requestData = $transaction->getRequestData();
        $scope = (new PaymentScopeConfigService())->resolveScope($requestData);
        $provider = $this->methodManager->getProviderInstance($paymentMethod, array_replace($requestData, $scope));
        if ($provider === null) {
            MessageManager::add_error((string) __('支付 Provider 实例不可用。'));

            return $this->statusRedirect([
                'transaction_no' => (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
            ]);
        }

        $providerReference = $context['gateway_token'] !== ''
            ? $context['gateway_token']
            : $context['transaction_no'];
        $responseData = $transaction->getResponseData();
        $storedProviderRef = trim((string) ($responseData[PaymentResult::FIELD_PROVIDER_REFERENCE] ?? ''));
        if ($providerReference === $context['transaction_no'] && $storedProviderRef !== '') {
            $providerReference = $storedProviderRef;
        }

        $runtimeConfig = $this->methodManager->getRuntimeConfig($paymentMethod, $scope);
        $internalRef = (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO);
        $express = !empty($requestData['express_checkout'])
            || !empty(($requestData['metadata']['express_checkout'] ?? null))
            || ExpressCheckoutOrchestrator::isExpressAwaitingConfirm($requestData);
        $alreadyAwaiting = ExpressCheckoutOrchestrator::isExpressAwaitingConfirm($requestData);

        // 支付商侧订单快照（首次回跳 prepare-only 时写入 response_data）必须一并交给 provider。
        // 否则 provider 读不到「支付商侧当前金额」，在金额已变更（Express 回跳重报价改运费）
        // 时会判定无需 patch，直接按支付商侧旧金额 capture，导致「实扣 ≠ 订单总额」。
        $providerPayload = \is_array($responseData['payload'] ?? null) ? $responseData['payload'] : [];

        $resumeContext = array_replace($requestData, [
            'runtime_config' => $runtimeConfig,
            'scope' => $scope['scope'],
            'environment' => $scope['environment'],
            'browser_return_params' => $params,
            'browser_return_context' => $context,
            'payload' => $providerPayload,
        ]);
        if ($express && !$transaction->isSuccess()) {
            // First browser return: prepare only (get profile, no capture).
            if (!$alreadyAwaiting || empty($params['express_confirm_capture'])) {
                $resumeContext['express_prepare_only'] = true;
                $resumeContext['express_checkout'] = true;
            }
        }

        $result = $provider->resumePayment(ResumeRequest::fromArray([
            PaymentOperationRequest::FIELD_INTENT_CODE => $internalRef,
            PaymentOperationRequest::FIELD_ATTEMPT_CODE => $internalRef . '-1',
            PaymentOperationRequest::FIELD_METHOD_CODE => $methodCode,
            PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => $providerReference,
            PaymentOperationRequest::FIELD_SCOPE => $scope['scope'],
            PaymentOperationRequest::FIELD_AMOUNT_MINOR => (int) round(((float) $transaction->getData(PaymentTransaction::schema_fields_AMOUNT)) * 100),
            PaymentOperationRequest::FIELD_CURRENCY_CODE => (string) $transaction->getData(PaymentTransaction::schema_fields_CURRENCY),
            PaymentOperationRequest::FIELD_CONTEXT => $resumeContext,
        ]));

        $responsePayload = $result->getData();
        $awaiting = $express && (
            $result->getStatus() === PaymentResult::STATUS_PROCESSING
            && (
                !empty($result->getPayload()['express_awaiting_confirm'])
                || !empty($resumeContext['express_prepare_only'])
            )
        );

        $nextRequest = $requestData;
        if ($awaiting) {
            $meta = is_array($nextRequest['metadata'] ?? null) ? $nextRequest['metadata'] : [];
            $meta[ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM] = 1;
            $meta['express_checkout'] = true;
            $nextRequest['metadata'] = $meta;
            $nextRequest['express_checkout'] = true;
            $nextRequest[ExpressCheckoutOrchestrator::META_AWAITING_CONFIRM] = 1;
            $transaction->setRequestData($nextRequest);
        }

        $transaction->setResponseData(array_replace($transaction->getResponseData(), $responsePayload))
            ->setData(
                PaymentTransaction::schema_fields_STATUS,
                $result->getStatus() === PaymentResult::STATUS_PAID
                    ? PaymentTransaction::STATUS_SUCCESS
                    : PaymentTransaction::STATUS_PROCESSING,
            );
        if ($result->getStatus() === PaymentResult::STATUS_PAID) {
            $transaction->setData(PaymentTransaction::schema_fields_PAID_AT, date('Y-m-d H:i:s'));
        }
        $transaction->save();
        if ($result->getStatus() === PaymentResult::STATUS_PAID) {
            $this->ensureCaptureReader($transaction);
        }

        try {
            /** @var \Weline\Payment\Api\PaymentExpressFacadeInterface $expressFacade */
            $expressFacade = $this->objectManager->getInstance(\Weline\Payment\Api\PaymentExpressFacadeInterface::class);
            $expressFacade->applyExpressProfileFromPaymentResult($result->getData(), array_replace($nextRequest, [
                'method_code' => $methodCode,
                'transaction_no' => $internalRef,
                'order_id' => (string) $transaction->getData(PaymentTransaction::schema_fields_ORDER_ID),
                'express_checkout' => true,
            ]));
        } catch (\Throwable) {
            // Address import is best-effort.
        }

        if ($awaiting) {
            return $this->landingOrchestrator->decideExpressReview($transaction);
        }

        if ($result->getStatus() !== PaymentResult::STATUS_PAID) {
            $redirectReference = trim((string) (
                $result->getPayload()['capture_id']
                ?? $result->getProviderReference()
                ?? $internalRef
            ));

            return $this->statusRedirect([
                'transaction_no' => $redirectReference !== '' ? $redirectReference : $internalRef,
            ]);
        }

        $this->notifyOrderPaidFromTransaction($transaction);
        $this->markCheckoutRecoveryPaid($transaction);

        return $this->landingOrchestrator->decide($transaction);
    }

    /**
     * Symmetry with PaymentBrowserCancelDispatcher::markCheckoutRecoveryFailed —
     * capture success must lock checkout recovery to paid / not recoverable.
     */
    private function markCheckoutRecoveryPaid(PaymentTransaction $transaction): void
    {
        $requestData = $transaction->getRequestData();
        if (!is_array($requestData)) {
            $requestData = [];
        }
        $quoteToken = trim((string)($requestData['checkout_token'] ?? $requestData['quote_token'] ?? ''));
        if ($quoteToken === '') {
            $landingParams = is_array($requestData['browser_landing_params'] ?? null)
                ? $requestData['browser_landing_params']
                : [];
            $quoteToken = trim((string)($landingParams['checkout_token'] ?? $landingParams['quote_token'] ?? ''));
        }
        if ($quoteToken === '') {
            return;
        }

        try {
            $recovery = $this->objectManager->getInstance(
                \Weline\Checkout\Service\CheckoutPaymentRecoveryStateService::class
            );
            if (!is_object($recovery) || !method_exists($recovery, 'markPaid')) {
                return;
            }
            $methodCode = trim((string)$transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE));
            $recovery->markPaid($quoteToken, null, [
                'payment_method' => $methodCode,
                'method_code' => $methodCode,
                'transactions' => [[
                    'order_uuid' => trim((string)$transaction->getData(PaymentTransaction::schema_fields_ORDER_ID)),
                    'transaction_id' => (int)$transaction->getId(),
                    'transaction_no' => trim((string)$transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO)),
                    'method_code' => $methodCode,
                    'status' => PaymentTransaction::STATUS_SUCCESS,
                ]],
            ]);
        } catch (\Throwable) {
        }
    }

    private function ensureCaptureReader(PaymentTransaction $transaction): void
    {
        try {
            /** @var PaymentCaptureReaderEnsureService $ensure */
            $ensure = $this->objectManager->getInstance(PaymentCaptureReaderEnsureService::class);
            $ensure->ensureFromTransaction($transaction);
        } catch (\Throwable) {
            // Reader 补写失败不得阻断已捕获支付。
        }
    }

    private function notifyOrderPaidFromTransaction(PaymentTransaction $transaction): void
    {
        $orderUuid = trim((string) $transaction->getData(PaymentTransaction::schema_fields_ORDER_ID));
        if ($orderUuid === '') {
            return;
        }

        $transactionNo = (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO);
        $purpose = $this->hangPurposeFromTransaction($transaction);

        try {
            if ($purpose === 'deposit' || $purpose === 'balance') {
                $this->reconcileB2bHang($orderUuid, $purpose, $transactionNo !== '' ? $transactionNo : ('txn_' . $orderUuid));
            }
            // Deposit hang must NOT mark the Order fully paid.
            if ($purpose === 'deposit') {
                $this->markOrderPaymentPartialFromBrowser($orderUuid);

                return;
            }

            /** @var OrderFacadeInterface $orders */
            $orders = $this->objectManager->getInstance(OrderFacadeInterface::class);
            $orders->notifyOrderPaid($orderUuid, [
                'payment_method' => (string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE),
                'payment_transaction_id' => (int) $transaction->getId(),
                'payment_transaction_no' => $transactionNo,
                'purpose' => $purpose !== '' ? $purpose : 'full',
            ]);
        } catch (\Throwable) {
            // Capture already succeeded; leave order for reconciliation rather than
            // failing the browser return path and risking a double-charge retry.
        }
    }

    private function hangPurposeFromTransaction(PaymentTransaction $transaction): string
    {
        $request = $transaction->getRequestData();
        if (!is_array($request)) {
            return '';
        }
        $meta = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];
        $purpose = strtolower(trim((string) ($meta['purpose'] ?? $meta['hang_purpose'] ?? '')));
        if ($purpose === '') {
            $purpose = strtolower(trim((string) ($request['purpose'] ?? $request['hang_purpose'] ?? '')));
        }

        return in_array($purpose, ['deposit', 'balance', 'full'], true) ? $purpose : '';
    }

    private function reconcileB2bHang(string $orderUuid, string $purpose, string $intentCode): void
    {
        if (!interface_exists(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class)) {
            return;
        }
        try {
            $bridge = $this->objectManager->getInstance(\Weline\B2B\Api\B2BHangPaymentBridgeInterface::class);
            if (!$bridge instanceof \Weline\B2B\Api\B2BHangPaymentBridgeInterface) {
                return;
            }
            $bridge->reconcilePaymentSuccess($orderUuid, $purpose, $intentCode);
        } catch (\Throwable) {
            // Soft-fail: Order paid notify may still run for balance/full.
        }
    }

    private function markOrderPaymentPartialFromBrowser(string $orderUuid): void
    {
        $orderUuid = trim($orderUuid);
        if ($orderUuid === '' || !interface_exists(\Weline\Order\Api\OrderFacadeInterface::class)) {
            return;
        }
        try {
            $orders = $this->objectManager->getInstance(\Weline\Order\Api\OrderFacadeInterface::class);
            if (!$orders instanceof \Weline\Order\Api\OrderFacadeInterface) {
                return;
            }
            $orders->mergeTypePayload($orderUuid, [
                'payment_status' => 'partial',
                'hang_status' => 'awaiting_merchant_approval',
            ]);
        } catch (\Throwable) {
            // Soft-fail partial projection.
        }
    }

    /**
     * @param array{
     *   method_code:string,
     *   transaction_no:string,
     *   target_scope:string,
     *   gateway_token:string,
     *   source:string
     * } $context
     */
    private function loadTransaction(array $context): ?PaymentTransaction
    {
        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $context['transaction_no']);
        if ($transaction->getId()) {
            return $transaction;
        }

        if ($context['gateway_token'] !== '' && $context['gateway_token'] !== $context['transaction_no']) {
            $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
            $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $context['gateway_token']);
            if ($transaction->getId()) {
                return $transaction;
            }
        }

        if ($context['method_code'] === '') {
            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function statusRedirect(array $params): array
    {
        return [
            'redirect_path' => $this->urlCatalog->transactionStatusPath(),
            'redirect_params' => $params,
            'absolute' => false,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{scope:?string,website_code:string,store_code:string,channel_code:string,locale:string}
     */
    private function scopeContextFromResult(array $result): array
    {
        return [
            'scope' => \is_string($result['scope'] ?? null) ? (string) $result['scope'] : null,
            'website_code' => \is_string($result['website_code'] ?? null) ? (string) $result['website_code'] : '',
            'store_code' => \is_string($result['store_code'] ?? null) ? (string) $result['store_code'] : '',
            'channel_code' => \is_string($result['channel_code'] ?? null) ? (string) $result['channel_code'] : '',
            'locale' => \is_string($result['locale'] ?? null) ? (string) $result['locale'] : '',
        ];
    }
}
