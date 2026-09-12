<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Api\Data\CancelRequest;
use Weline\Payment\Api\Data\PaymentOperationRequest;
use Weline\Payment\Api\Data\PaymentResult;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Model\PaymentTransaction;

/**
 * Shell browser cancel entry: shell_token or method_code → Provider.cancelPayment.
 */
final class PaymentBrowserCancelDispatcher
{
    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly PaymentBrowserReturnLandingOrchestrator $landingOrchestrator,
        private readonly PaymentBrowserReturnContextResolver $returnContext,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{redirect_path:string,redirect_params:array<string,mixed>,absolute:bool}
     */
    public function dispatch(array $params): array
    {
        $context = $this->returnContext->resolve($params);
        $methodCode = strtolower(trim((string) ($params[PaymentShellCallbackUrlCatalog::QUERY_METHOD_CODE] ?? '')));
        $transactionNo = trim((string) (
            $params[PaymentShellCallbackUrlCatalog::QUERY_TRANSACTION_NO]
            ?? $params['transaction_no']
            ?? ''
        ));

        if ($context !== null) {
            $methodCode = $context['method_code'] !== '' ? $context['method_code'] : $methodCode;
            $transactionNo = $context['transaction_no'] !== '' ? $context['transaction_no'] : $transactionNo;
        }

        if ($methodCode === '') {
            return $this->landingOrchestrator->decideCancel(
                null,
                PaymentBrowserCallbackRoutes::CANCEL_STATE_DONE,
            );
        }

        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = $this->objectManager->getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $methodCode);
        if (!$paymentMethod->getId()) {
            return $this->landingOrchestrator->decideCancel(
                null,
                PaymentBrowserCallbackRoutes::CANCEL_STATE_DONE,
            );
        }

        $scope = (new PaymentScopeConfigService())->resolveScope($params);
        if ($context !== null && $context['target_scope'] !== '') {
            $scope = (new PaymentScopeConfigService())->resolveScope(['scope' => $context['target_scope']] + $params);
        }

        $provider = $this->methodManager->getProviderInstance($paymentMethod, array_replace($params, $scope));
        if ($provider === null) {
            return $this->landingOrchestrator->decideCancel(
                null,
                PaymentBrowserCallbackRoutes::CANCEL_STATE_DONE,
            );
        }

        $transaction = null;
        $alreadyCancelled = false;
        if ($transactionNo !== '') {
            /** @var PaymentTransaction $transaction */
            $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
            $transaction->load(PaymentTransaction::schema_fields_TRANSACTION_NO, $transactionNo);
            if (!$transaction->getId()) {
                $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
            } else {
                $alreadyCancelled = $transaction->isFailed();
            }
        }

        $gatewayToken = $context !== null
            ? ($context['gateway_token'] ?? '')
            : trim((string) ($params['token'] ?? ''));
        $providerReference = $gatewayToken !== '' ? $gatewayToken : ($transactionNo !== '' ? $transactionNo : null);

        $runtimeConfig = $this->methodManager->getRuntimeConfig($paymentMethod, $scope);
        $result = $provider->cancelPayment(CancelRequest::fromArray([
            PaymentOperationRequest::FIELD_INTENT_CODE => $transactionNo !== '' ? $transactionNo : 'cancel-' . $methodCode,
            PaymentOperationRequest::FIELD_ATTEMPT_CODE => ($transactionNo !== '' ? $transactionNo : 'cancel') . '-1',
            PaymentOperationRequest::FIELD_METHOD_CODE => $methodCode,
            PaymentOperationRequest::FIELD_PROVIDER_CODE => $provider->getProviderCode(),
            PaymentOperationRequest::FIELD_PROVIDER_REFERENCE => $providerReference,
            PaymentOperationRequest::FIELD_SCOPE => $scope['scope'],
            CancelRequest::FIELD_TOKEN => $providerReference,
            PaymentOperationRequest::FIELD_CONTEXT => array_replace($params, [
                'runtime_config' => $runtimeConfig,
                'scope' => $scope['scope'],
                'environment' => $scope['environment'],
                'browser_return_context' => $context,
            ]),
        ]));

        if ($transaction !== null && $transaction->getId()) {
            $transaction->setResponseData(array_replace($transaction->getResponseData(), $result->getData()))
                ->setData(
                    PaymentTransaction::schema_fields_STATUS,
                    $result->getStatus() === PaymentResult::STATUS_PAID
                        ? PaymentTransaction::STATUS_SUCCESS
                        : PaymentTransaction::STATUS_FAILED,
                )
                ->save();

            // Express defer-capture: release unpaid order / inventory reservation on cancel.
            $requestData = $transaction->getRequestData();
            if (
                ExpressCheckoutOrchestrator::isExpressAwaitingConfirm($requestData)
                || !empty($requestData['express_checkout'])
                || !empty(($requestData['metadata']['express_checkout'] ?? null))
            ) {
                try {
                    $flow = $this->objectManager->getInstance(\Weline\Checkout\Service\ExpressCheckoutFlowService::class);
                    if (is_object($flow) && method_exists($flow, 'abandon')) {
                        $flow->abandon(
                            (string) $transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
                            'provider_cancel',
                        );
                    }
                } catch (\Throwable) {
                }
            }
        }

        $cancelState = $alreadyCancelled
            ? PaymentBrowserCallbackRoutes::CANCEL_STATE_ALREADY
            : PaymentBrowserCallbackRoutes::CANCEL_STATE_DONE;

        return $this->landingOrchestrator->decideCancel(
            $transaction !== null && $transaction->getId() ? $transaction : null,
            $cancelState,
        );
    }
}
