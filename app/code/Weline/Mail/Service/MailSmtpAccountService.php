<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;

class MailSmtpAccountService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchAccounts(string $query = '', int $limit = 50): array
    {
        /** @var MailAccount $accountModel */
        $accountModel = ObjectManager::getInstance(MailAccount::class);
        $accountQuery = $accountModel->clear()
            ->where(MailAccount::schema_fields_STATUS, 'active')
            ->order(MailAccount::schema_fields_ID, 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->select()
            ->fetch()
            ->getItems();

        $query = strtolower(trim($query));
        $items = [];
        foreach ($accountQuery as $account) {
            $config = $this->buildAccountConfig($account);
            if ($config === null) {
                continue;
            }

            if ($query !== '' && !str_contains(strtolower($config['email'] . ' ' . $config['domain_name'] . ' ' . $config['display_name']), $query)) {
                continue;
            }

            $items[] = $config;
        }

        return $items;
    }

    public function getAccountConfig(int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId() || (string)$account->getData(MailAccount::schema_fields_STATUS) !== 'active') {
            return null;
        }

        return $this->buildAccountConfig($account);
    }

    public function sendViaAccount(int $accountId, string|array $to, string $subject, string $content): array
    {
        $config = $this->getAccountConfig($accountId);
        if ($config === null) {
            return ['success' => false, 'message' => __('请选择已启用的自建邮箱账号')];
        }

        if (empty($config['is_fake'])) {
            return ['success' => false, 'message' => __('真实自建邮箱账号请通过 SMTP 协议发送')];
        }

        /** @var MailFakeMailboxService $fakeMailbox */
        $fakeMailbox = ObjectManager::getInstance(MailFakeMailboxService::class);
        return $fakeMailbox->sendFromAccount($accountId, $to, $subject, $content);
    }

    private function buildAccountConfig(MailAccount $account): ?array
    {
        $domain = $this->loadDomainForAccount($account);
        if (!$domain || (string)$domain->getData(MailDomain::schema_fields_STATUS) !== 'active') {
            return null;
        }

        $domainName = (string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME);
        $hostname = (string)$domain->getData(MailDomain::schema_fields_HOSTNAME);
        $engine = (string)$domain->getData(MailDomain::schema_fields_ENGINE);
        $email = (string)$account->getData(MailAccount::schema_fields_EMAIL);

        /** @var StalwartEngineAdapter $stalwart */
        $stalwart = ObjectManager::getInstance(StalwartEngineAdapter::class);
        $clientSettings = $stalwart->clientSettings($domainName, $hostname);
        $smtp = $clientSettings['smtp'] ?? [];

        /** @var MailCustomerAccountService $accountService */
        $accountService = ObjectManager::getInstance(MailCustomerAccountService::class);
        $isFake = $accountService->isFakeDomain($domain);

        return [
            'account_id' => (int)$account->getId(),
            'email' => $email,
            'display_name' => (string)($account->getData(MailAccount::schema_fields_DISPLAY_NAME) ?: $email),
            'domain_id' => (int)$domain->getId(),
            'domain_name' => $domainName,
            'hostname' => $hostname,
            'engine' => $engine,
            'is_fake' => $isFake,
            'smtp_host' => (string)($smtp['host'] ?? $hostname),
            'smtp_port' => (string)($smtp['port'] ?? 587),
            'smtp_secure' => $this->normalizeSecure((string)($smtp['security'] ?? 'STARTTLS')),
            'smtp_auth' => !empty($smtp['auth']) ? '1' : '0',
            'label' => $email . ' · ' . $domainName . ' · ' . ($isFake ? 'fake' : $engine),
        ];
    }

    private function loadDomainForAccount(MailAccount $account): ?MailDomain
    {
        $domainId = (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID);
        if ($domainId <= 0) {
            return null;
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        return $domain->getId() ? $domain : null;
    }

    private function normalizeSecure(string $security): string
    {
        $value = strtolower(trim($security));
        return match ($value) {
            'ssl', 'smtps' => 'ssl',
            'tls', 'starttls' => 'tls',
            'none', '' => 'none',
            default => 'tls',
        };
    }
}
