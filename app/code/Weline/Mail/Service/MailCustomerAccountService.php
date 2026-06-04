<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;

class MailCustomerAccountService
{
    public function isServiceEnabled(): bool
    {
        if ($this->getActiveDomains() === []) {
            return false;
        }

        /** @var StalwartEngineAdapter $engine */
        $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);
        $environment = $engine->checkEnvironment();

        return !empty($environment['ok']);
    }

    /**
     * @return MailDomain[]
     */
    public function getActiveDomains(): array
    {
        /** @var MailDomain $domainModel */
        $domainModel = ObjectManager::getInstance(MailDomain::class);

        return $domainModel->clear()
            ->where(MailDomain::schema_fields_STATUS, 'active')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * @return MailAccount[]
     */
    public function getAccountsForCustomer(int $customerId): array
    {
        if ($customerId <= 0) {
            return [];
        }

        /** @var MailAccount $accountModel */
        $accountModel = ObjectManager::getInstance(MailAccount::class);

        return $accountModel->clear()
            ->where(MailAccount::schema_fields_CUSTOMER_ID, $customerId)
            ->select()
            ->fetch()
            ->getItems();
    }

    public function apply(int $customerId, int $domainId, string $localPart, string $displayName = ''): array
    {
        if ($customerId <= 0) {
            return ['success' => false, 'message' => __('请先登录')];
        }

        if (!$this->isServiceEnabled()) {
            return ['success' => false, 'message' => __('邮箱服务尚未开启，请等待站点管理员完成邮件服务环境配置。')];
        }

        $localPart = strtolower(trim($localPart));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,62}$/', $localPart)) {
            return ['success' => false, 'message' => __('邮箱账号只能使用小写字母、数字、点、下划线和短横线，长度为 2 到 63 位。')];
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId() || (string)$domain->getData(MailDomain::schema_fields_STATUS) !== 'active') {
            return ['success' => false, 'message' => __('请选择已开启的邮箱域名')];
        }

        $domainName = (string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME);
        $email = $localPart . '@' . $domainName;

        /** @var MailAccount $accountModel */
        $accountModel = ObjectManager::getInstance(MailAccount::class);
        $existing = $accountModel->clear()->where(MailAccount::schema_fields_EMAIL, $email)->find()->fetch();
        if ($existing->getId()) {
            $ownerId = (int)$existing->getData(MailAccount::schema_fields_CUSTOMER_ID);
            return [
                'success' => false,
                'message' => $ownerId === $customerId ? __('您已经申请过该邮箱账号') : __('该邮箱账号已被占用'),
            ];
        }

        $now = date('Y-m-d H:i:s');
        $accountModel->clear()
            ->setData(MailAccount::schema_fields_DOMAIN_ID, $domainId)
            ->setData(MailAccount::schema_fields_CUSTOMER_ID, $customerId)
            ->setData(MailAccount::schema_fields_EMAIL, $email)
            ->setData(MailAccount::schema_fields_DISPLAY_NAME, trim($displayName))
            ->setData(MailAccount::schema_fields_QUOTA_MB, (int)$domain->getData(MailDomain::schema_fields_DEFAULT_QUOTA_MB))
            ->setData(MailAccount::schema_fields_STATUS, 'pending')
            ->setData(MailAccount::schema_fields_CREATED_AT, $now)
            ->setData(MailAccount::schema_fields_UPDATED_AT, $now)
            ->save();

        return ['success' => true, 'message' => __('邮箱账号申请已提交，管理员同步邮件服务后即可使用。')];
    }

    public function updateStatus(int $customerId, int $accountId, string $status): array
    {
        if (!in_array($status, ['pending', 'suspended'], true)) {
            return ['success' => false, 'message' => __('不支持的邮箱账号状态')];
        }

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId() || (int)$account->getData(MailAccount::schema_fields_CUSTOMER_ID) !== $customerId) {
            return ['success' => false, 'message' => __('邮箱账号不存在')];
        }

        $account->setData(MailAccount::schema_fields_STATUS, $status)
            ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return ['success' => true, 'message' => $status === 'suspended' ? __('邮箱账号已暂停') : __('邮箱账号已恢复为待同步状态')];
    }
}
