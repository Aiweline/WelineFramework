<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Api\Data\ResumeRequest;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Shell browser return entry: OAuth Connect.complete or Provider resumePayment.
 * Must not hardcode a gateway OAuth service.
 */
final class PaymentBrowserReturnDispatcher
{
    public function __construct(
        private readonly PaymentConnectDispatcher $connectDispatcher,
        private readonly PaymentMethodManager $methodManager,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{redirect_path:string,redirect_params:array<string,mixed>}
     */
    public function dispatch(array $params): array
    {
        $oauthCode = trim((string) ($params['code'] ?? ''));
        $oauthState = trim((string) ($params['state'] ?? ''));
        if ($oauthCode !== '' && $oauthState !== '') {
            return $this->dispatchOAuth($params);
        }

        $token = trim((string) ($params['token'] ?? $params['order_id'] ?? ''));
        if ($token !== '') {
            return $this->dispatchResume($params, $token);
        }

        // 生产：空参 / 无效裸访问直接回首页。开发：停留落地页说明情况（探活用）。
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
                '【开发环境提示】此地址是支付模块统一浏览器回跳入口，已就绪。正常支付或 OAuth 授权会自动带回参数；直接打开不会完成支付。生产环境空参访问会跳转首页。'
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
     * @return array{redirect_path:string,redirect_params:array<string,mixed>}
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

            return [
                'redirect_path' => 'payment/frontend/checkout/return',
                'redirect_params' => [],
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{redirect_path:string,redirect_params:array<string,mixed>}
     */
    private function dispatchResume(array $params, string $token): array
    {
        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $token);
        if (!$transaction->getId()) {
            MessageManager::add_error((string) __('Payment transaction number is required.'));

            return [
                'redirect_path' => 'payment/frontend/checkout/return',
                'redirect_params' => [],
            ];
        }

        if ($transaction->isSuccess()) {
            return [
                'redirect_path' => 'payment/frontend/checkout/return',
                'redirect_params' => ['transaction_no' => $token],
            ];
        }

        $methodCode = trim((string) $transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE));
        if ($methodCode === '') {
            $methodCode = strtolower(trim((string) ($params['method_code'] ?? '')));
        }

        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $methodCode);
        if (!$paymentMethod->getId()) {
            MessageManager::add_error((string) __('支付方式不存在'));

            return [
                'redirect_path' => 'payment/frontend/checkout/return',
                'redirect_params' => [],
            ];
        }

        $requestData = $transaction->getRequestData();
        $scope = (new PaymentScopeConfigService())->resolveScope($requestData);
        $provider = $this->methodManager->getProviderInstance($paymentMethod, array_replace($requestData, $scope));
        if ($provider === null) {
            MessageManager::add_error((string) __('支付 Provider 实例不可用。'));

            return [
                'redirect_path' => 'payment/frontend/checkout/return',
                'redirect_params' => [],
            ];
        }

        $runtimeConfig = $this->methodManager->getRuntimeConfig($paymentMethod, $scope);
        $result = $provider->resumePayment(ResumeRequest::fromArray([
            PaymentOperationRequest::FIELD_INTENT_CODE => $token,
            PaymentOperationRequest::FIELD_ATTEMPT_CODE => $token . '-1',
            PaymentOperationRequest::FIELD_METHOD_CODE => $methodCode,
            PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => $token,
            PaymentOperationRequest::FIELD_SCOPE => $scope['scope'],
            PaymentOperationRequest::FIELD_AMOUNT_MINOR => (int) round(((float) $transaction->getData(PaymentTransaction::schema_fields_AMOUNT)) * 100),
            PaymentOperationRequest::FIELD_CURRENCY_CODE => (string) $transaction->getData(PaymentTransaction::schema_fields_CURRENCY),
            PaymentOperationRequest::FIELD_CONTEXT => array_replace($requestData, [
                'runtime_config' => $runtimeConfig,
                'scope' => $scope['scope'],
                'environment' => $scope['environment'],
            ]),
        ]));

        $transaction->setResponseData(array_replace($transaction->getResponseData(), $result->getData()))
            ->setData(
                PaymentTransaction::schema_fields_STATUS,
                $result->getStatus() === PaymentResult::STATUS_PAID
                    ? PaymentTransaction::STATUS_SUCCESS
                    : PaymentTransaction::STATUS_PROCESSING,
            );
        if ($result->getProviderReference()) {
            $transaction->setData(PaymentTransaction::schema_fields_TRANSACTION_NO, $result->getProviderReference());
        }
        if ($result->getStatus() === PaymentResult::STATUS_PAID) {
            $transaction->setData(PaymentTransaction::schema_fields_PAID_AT, date('Y-m-d H:i:s'));
        }
        $transaction->save();

        $redirectReference = trim((string) ($result->getPayload()['capture_id'] ?? $result->getProviderReference() ?? $token));

        return [
            'redirect_path' => 'payment/frontend/checkout/return',
            'redirect_params' => [
                'transaction_no' => $redirectReference !== '' ? $redirectReference : $token,
            ],
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
