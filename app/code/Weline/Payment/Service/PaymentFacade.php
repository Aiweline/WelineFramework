<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Payment\Api\Data\PaymentTransactionRecord;
use Weline\Payment\Api\PaymentFacadeInterface;
use Weline\Payment\Model\PaymentTransaction;

final class PaymentFacade implements PaymentFacadeInterface
{
    public function __construct(
        private readonly PaymentMethodManager $methodManager,
        private readonly PaymentService $paymentService,
    ) {
    }

    public function tryCreatePayment(string $methodCode, array $context = []): ?PaymentTransactionRecord
    {
        $methodCode = strtolower(trim($methodCode));
        if ($methodCode === '') {
            return null;
        }

        $method = $this->methodManager->getMethodByCode($methodCode);
        if ($method === null || !$this->methodManager->isMethodActiveForScope($method, $context)) {
            return null;
        }

        $transaction = $this->paymentService->createPayment($methodCode, $context);

        return new PaymentTransactionRecord(
            id: (int)$transaction->getId(),
            transactionNumber: (string)$transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
            methodCode: (string)$transaction->getData(PaymentTransaction::schema_fields_METHOD_CODE),
            status: (string)$transaction->getData(PaymentTransaction::schema_fields_STATUS),
            response: $transaction->getResponseData(),
        );
    }
}
