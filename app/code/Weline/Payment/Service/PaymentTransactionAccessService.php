<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Payment\Model\PaymentTransaction;

/**
 * 后台交易访问的最小持久化加载边界。
 */
class PaymentTransactionAccessService
{
    public function __construct(
        private readonly ObjectManager $objectManager,
        private readonly PaymentObjectScopeService $scopeService,
    ) {
    }

    /**
     * @return array{transaction:PaymentTransaction,scope:ScopeIdentity}|null
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        /** @var PaymentTransaction $transaction */
        $transaction = $this->objectManager->getInstance(PaymentTransaction::class);
        $transaction->load($id);
        if (!$transaction->getId()) {
            return null;
        }

        return [
            'transaction' => $transaction,
            'scope' => $this->scopeService->fromPersistedScope(
                (string)$transaction->getData(PaymentTransaction::schema_fields_SCOPE),
            ),
        ];
    }

    public function queryStatus(PaymentTransaction $transaction): void
    {
        /** @var PaymentService $paymentService */
        $paymentService = $this->objectManager->getInstance(PaymentService::class);
        $paymentService->queryPaymentStatus(
            (string)$transaction->getData(PaymentTransaction::schema_fields_TRANSACTION_NO),
        );
    }
}
