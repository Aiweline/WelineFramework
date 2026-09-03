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
        $result = $provider->resumePayment(ResumeRequest::fromArray([
            PaymentOperationRequest::FIELD_INTENT_CODE => $internalRef,
            PaymentOperationRequest::FIELD_ATTEMPT_CODE => $internalRef . '-1',
            PaymentOperationRequest::FIELD_METHOD_CODE => $methodCode,
            PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => $providerReference,
            PaymentOperationRequest::FIELD_SCOPE => $scope['scope'],
            PaymentOperationRequest::FIELD_AMOUNT_MINOR => (int) round(((float) $transaction->getData(PaymentTransaction::schema_fields_AMOUNT)) * 100),
            PaymentOperationRequest::FIELD_CURRENCY_CODE => (string) $transaction->getData(PaymentTransaction::schema_fields_CURRENCY),
            PaymentOperationRequest::FIELD_CONTEXT => array_replace($requestData, [
                'runtime_config' => $runtimeConfig,
                'scope' => $scope['scope'],
                'environment' => $scope['environment'],
                'browser_return_params' => $params,
                'browser_return_context' => $context,
            ]),
        ]));

        $transaction->setResponseData(array_replace($transaction->getResponseData(), $result->getData()))
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

        return $this->landingOrchestrator->decide($transaction);
    }

    private function notifyOrderPaidFromTransaction(PaymentTransaction $transaction): void
    {
        $orderUuid = trim((string) $transaction->getData(PaymentTransaction::schema_fields_ORDER_ID));
        if ($orderUuid === '') {
            return;
        }

        try {
            /** @var OrderFacadeInterface $orders */
            $orders = $this->objectManager->getInstance(OrderFacadeInterface::class);
            $orders->notifyOrderPaid($orderUuid, [
                'payment_method' => (string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE),
                'payment_transaction_id' => (int) $transaction->getId(),
                'payment_transaction_no' => (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
            ]);
        } catch (\Throwable) {
            // Capture already succeeded; leave order for reconciliation rather than
            // failing the browser return path and risking a double-charge retry.
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
