<?php

declare(strict_types=1);

namespace WeShop\B2B\Service;

use WeShop\B2B\Model\Account;
use Weline\Framework\Manager\ObjectManager;

class AccountService
{
    public function getAccountForCustomer(int $customerId): ?Account
    {
        if ($customerId <= 0) {
            return null;
        }

        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class);
        $account->clear()
            ->where(Account::schema_fields_CUSTOMER_ID, $customerId)
            ->limit(1);

        $rows = $account->select()->fetchArray();
        if (!\is_array($rows) || $rows === []) {
            return null;
        }

        $first = $rows[0];
        if (!\is_array($first)) {
            return null;
        }

        $accountId = (int) ($first[Account::schema_fields_ID] ?? $first['account_id'] ?? 0);
        if ($accountId <= 0) {
            return null;
        }

        $account->clear()->load($accountId);

        return $account->getId() ? $account : null;
    }

    public function getOrCreateAccount(int $customerId, ?int $paymentTermId = null): Account
    {
        if ($customerId <= 0) {
            throw new \InvalidArgumentException((string) __('Customer ID is required.'));
        }

        $existing = $this->getAccountForCustomer($customerId);
        if ($existing !== null) {
            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        /** @var Account $account */
        $account = ObjectManager::getInstance(Account::class);
        $account->clearData()
            ->setData(Account::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(Account::schema_fields_PAYMENT_TERM_ID, $paymentTermId)
            ->setData(Account::schema_fields_ACCOUNT_BALANCE, '0.00')
            ->setData(Account::schema_fields_CREDIT_PERIOD_DAYS, 0)
            ->setData(Account::schema_fields_AUTO_APPROVE_LIMIT, '0.00')
            ->setData(Account::schema_fields_CREATED_AT, $now)
            ->setData(Account::schema_fields_UPDATED_AT, $now)
            ->save();

        return $account;
    }

    public function adjustBalance(int $customerId, float $delta): Account
    {
        $account = $this->getOrCreateAccount($customerId);
        $balance = round((float) ($account->getData(Account::schema_fields_ACCOUNT_BALANCE) ?? 0) + $delta, 2);
        $account->setData(Account::schema_fields_ACCOUNT_BALANCE, $balance)
            ->setData(Account::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return $account;
    }

    public function saveAccountSettings(int $customerId, array $data): Account
    {
        $account = $this->getOrCreateAccount($customerId);
        if (isset($data['payment_term_id'])) {
            $account->setData(Account::schema_fields_PAYMENT_TERM_ID, (int) $data['payment_term_id'] ?: null);
        }
        if (isset($data['credit_period_days'])) {
            $account->setData(Account::schema_fields_CREDIT_PERIOD_DAYS, max(0, (int) $data['credit_period_days']));
        }
        if (isset($data['auto_approve_limit'])) {
            $account->setData(Account::schema_fields_AUTO_APPROVE_LIMIT, round(max(0.0, (float) $data['auto_approve_limit']), 2));
        }
        $account->setData(Account::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))->save();

        return $account;
    }
}
