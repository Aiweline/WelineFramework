<?php

declare(strict_types=1);

namespace Weline\Frontend\Integration\Ai;

use Weline\Ai\Api\Billing\BillingAccountProviderInterface;
use Weline\Ai\Api\Billing\BillingDebitResult;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Frontend\Model\FrontendUser;

final class FrontendBillingAccountProvider implements BillingAccountProviderInterface
{
    public function __construct(
        private readonly FrontendUser $frontendUser,
        private readonly ConnectionFactory $connectionFactory,
    ) {
    }

    public function debit(int $accountId, float $amount): BillingDebitResult
    {
        $user = $this->loadForUpdate($accountId);
        if (!$user->getId()) {
            return BillingDebitResult::accountMissing();
        }

        $balanceBefore = (float)($user->getData(FrontendUser::schema_fields_BALANCE) ?? 0.0);
        if ($balanceBefore < $amount) {
            return BillingDebitResult::insufficient($balanceBefore);
        }

        $balanceAfter = $balanceBefore - $amount;
        $totalConsumption = (float)($user->getData(FrontendUser::schema_fields_TOTAL_CONSUMPTION) ?? 0.0);
        $user->setData(FrontendUser::schema_fields_BALANCE, $balanceAfter);
        $user->setData(FrontendUser::schema_fields_TOTAL_CONSUMPTION, $totalConsumption + $amount);
        $user->save();

        return BillingDebitResult::debited($balanceBefore, $balanceAfter);
    }

    public function hasSufficientBalance(int $accountId, float $requiredAmount = 0.0): bool
    {
        $user = $this->loadAccount($accountId, false);
        if (!$user->getId()) {
            return false;
        }

        return (float)($user->getData(FrontendUser::schema_fields_BALANCE) ?? 0.0) >= $requiredAmount;
    }

    private function loadForUpdate(int $accountId): FrontendUser
    {
        return $this->loadAccount($accountId, true);
    }

    private function loadAccount(int $accountId, bool $forUpdate): FrontendUser
    {
        $connector = $this->connectionFactory->getConnector();
        $driver = strtolower($this->connectionFactory->getConfigProvider()->getDbType());
        $lockClause = $forUpdate && in_array($driver, ['mysql', 'pgsql', 'postgres', 'postgresql'], true)
            ? ' FOR UPDATE'
            : '';
        $table = $connector->quoteTable(FrontendUser::schema_table);
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = %d LIMIT 1%s',
            $table,
            FrontendUser::schema_fields_ID,
            $accountId,
            $lockClause,
        );
        $rows = $connector->query($sql)->fetchArray();

        /** @var FrontendUser $user */
        $user = (clone $this->frontendUser)->clearData();
        if (isset($rows[0]) && is_array($rows[0])) {
            $user->setData($rows[0]);
        }

        return $user;
    }
}
