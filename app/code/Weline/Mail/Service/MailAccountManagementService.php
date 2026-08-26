<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;

final class MailAccountManagementService
{
    public function createAccount(
        int $domainId,
        string $email,
        string $localPart,
        string $displayName,
        int $customerId,
        int $quotaMb,
        string $password
    ): array {
        try {
            /** @var MailDomain $domain */
            $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
            if (!$domain->getId()) {
                return $this->failure(__('邮箱域名不存在'), 404);
            }
            $domainName = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME)));
            $email = strtolower(trim($email));
            $localPart = strtolower(trim($localPart));
            if ($email === '' && $localPart !== '') {
                $email = $localPart . '@' . $domainName;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)
                || $domainName === ''
                || !str_ends_with($email, '@' . $domainName)
            ) {
                return $this->failure(__('邮箱地址必须属于所选域名'));
            }

            /** @var MailAccount $model */
            $model = ObjectManager::getInstance(MailAccount::class);
            $existing = $model->clear()->where(MailAccount::schema_fields_EMAIL, $email)->find()->fetch();
            if ($existing->getId()) {
                return $this->failure(__('邮箱账号已存在'), 409);
            }

            $accountService = ObjectManager::getInstance(MailCustomerAccountService::class);
            $isFake = $accountService->isFakeDomain($domain);
            if (!$isFake) {
                if ((string)$domain->getData(MailDomain::schema_fields_STATUS) !== 'active') {
                    return $this->failure(__('请先启用邮箱域名'));
                }
                ObjectManager::getInstance(StalwartManagementAdapter::class)
                    ->provisionAccount($email, $password, $quotaMb);
            }

            $now = date('Y-m-d H:i:s');
            $model->clear()
                ->setData(MailAccount::schema_fields_DOMAIN_ID, $domainId)
                ->setData(MailAccount::schema_fields_CUSTOMER_ID, max(0, $customerId))
                ->setData(MailAccount::schema_fields_EMAIL, $email)
                ->setData(MailAccount::schema_fields_DISPLAY_NAME, trim($displayName))
                ->setData(MailAccount::schema_fields_QUOTA_MB, max(128, $quotaMb))
                ->setData(MailAccount::schema_fields_STATUS, 'active')
                ->setData(MailAccount::schema_fields_CREATED_AT, $now)
                ->setData(MailAccount::schema_fields_UPDATED_AT, $now)
                ->save();

            return ['success' => true, 'message' => __('邮箱账号已开通'), 'account_id' => (int)$model->getId()];
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return $this->failure($exception->getMessage());
        } catch (\Throwable) {
            return $this->failure(__('邮箱账号开通失败，请检查 Stalwart 管理连接'), 500);
        }
    }

    public function setStatus(int $accountId, string $status): array
    {
        $status = strtolower(trim($status));
        if ($accountId <= 0 || !in_array($status, ['active', 'pending', 'suspended'], true)) {
            return $this->failure(__('邮箱账号状态参数无效'));
        }

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId()) {
            return $this->failure(__('邮箱账号不存在'), 404);
        }

        if ($status === 'active') {
            $domain = ObjectManager::getInstance(MailDomain::class)
                ->clear()
                ->load((int)$account->getData(MailAccount::schema_fields_DOMAIN_ID));
            $isFake = $domain->getId()
                && ObjectManager::getInstance(MailCustomerAccountService::class)->isFakeDomain($domain);
            if (!$isFake) {
                try {
                    if (!ObjectManager::getInstance(StalwartManagementAdapter::class)->accountExists(
                        (string)$account->getData(MailAccount::schema_fields_EMAIL)
                    )) {
                        return $this->failure(__('请先通过“开通/重置密码”在 Stalwart 中开通该账号'));
                    }
                } catch (\Throwable) {
                    return $this->failure(__('无法确认 Stalwart 账号状态，请检查管理连接'));
                }
            }
        }

        $account->setData(MailAccount::schema_fields_STATUS, $status)
            ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return ['success' => true, 'message' => __('邮箱账号状态已更新')];
    }

    public function provisionOrResetPassword(int $accountId, string $password): array
    {
        try {
            /** @var MailAccount $account */
            $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
            if (!$account->getId()) {
                return $this->failure(__('邮箱账号不存在'), 404);
            }
            /** @var MailDomain $domain */
            $domain = ObjectManager::getInstance(MailDomain::class)
                ->clear()
                ->load((int)$account->getData(MailAccount::schema_fields_DOMAIN_ID));
            if (!$domain->getId()) {
                return $this->failure(__('邮箱域名不存在'), 404);
            }
            if (ObjectManager::getInstance(MailCustomerAccountService::class)->isFakeDomain($domain)) {
                return $this->failure(__('Fake 邮箱不需要 Stalwart 密码'));
            }

            ObjectManager::getInstance(StalwartManagementAdapter::class)->provisionOrResetAccount(
                (string)$account->getData(MailAccount::schema_fields_EMAIL),
                $password,
                max(128, (int)$account->getData(MailAccount::schema_fields_QUOTA_MB))
            );
            $account->setData(MailAccount::schema_fields_STATUS, 'active')
                ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();

            return ['success' => true, 'message' => __('邮箱已在 Stalwart 中开通，密码已更新')];
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return $this->failure($exception->getMessage());
        } catch (\Throwable) {
            return $this->failure(__('邮箱开通或密码重置失败，请检查 Stalwart 管理连接'), 500);
        }
    }

    public function sendAs(
        int $accountId,
        string|array $to,
        string $subject,
        string $body
    ): array {
        return ObjectManager::getInstance(MailSmtpAccountService::class)
            ->sendViaAuthorizedAccount($accountId, $to, $subject, $body);
    }

    private function failure(mixed $message, int $code = 422): array
    {
        return ['success' => false, 'message' => $message, 'code' => $code];
    }
}
