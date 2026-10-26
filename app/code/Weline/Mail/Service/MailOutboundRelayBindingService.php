<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;
use Weline\Smtp\Model\SmtpService;
use Weline\Smtp\Service\SmtpServiceCatalogService;

/**
 * 外投 smarthost 的共享登记与域名默认绑定。
 *
 * - 手填外投自动登记为共享外部 Smtp 服务，其他邮箱可直接在下拉中选择；
 * - 域名绑定外投后，新建同域账号自动继承；已有无绑定账号同步补齐。
 */
final class MailOutboundRelayBindingService
{
    /**
     * 按 host + port + username 复用外部 Smtp 服务；没有则登记为共享服务。
     *
     * @return array{success: bool, message: string, smtp_service_id: int, created: bool}
     */
    public function ensureSharedSmtpService(
        string $host,
        int $port,
        string $username,
        string $password,
        string $tls = 'starttls'
    ): array {
        $host = strtolower(trim($host));
        if ($host === '') {
            return ['success' => false, 'message' => (string)__('请填写外投 SMTP 主机'), 'smtp_service_id' => 0, 'created' => false];
        }
        if ($port < 1 || $port > 65535 || $port === 25) {
            $port = 587;
        }
        if (!in_array($tls, ['starttls', 'smtps', 'none'], true)) {
            $tls = 'starttls';
        }
        $username = trim($username);

        /** @var SmtpService $model */
        $model = ObjectManager::getInstance(SmtpService::class);
        $rows = $model->clear()
            ->where(SmtpService::schema_fields_MODE, SmtpService::MODE_EXTERNAL)
            ->select()
            ->fetchArray();
        $existingId = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowHost = strtolower(trim((string)($row[SmtpService::schema_fields_SMTP_HOSTNAME]
                ?? $row['smtp_hostname']
                ?? '')));
            $rowPort = (int)($row[SmtpService::schema_fields_PORT] ?? $row['port'] ?? 0);
            $rowUser = trim((string)($row[SmtpService::schema_fields_USERNAME] ?? $row['username'] ?? ''));
            if ($rowHost !== $host || $rowPort !== $port || strcasecmp($rowUser, $username) !== 0) {
                continue;
            }
            $existingId = (int)($row[SmtpService::schema_fields_ID] ?? $row['smtp_service_id'] ?? 0);
            break;
        }

        if ($existingId > 0) {
            /** @var SmtpService $svc */
            $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($existingId);
            if ($svc->getId()) {
                $svc->setData(SmtpService::schema_fields_STATUS, 'active')
                    ->setData(SmtpService::schema_fields_TLS, $tls)
                    ->setData(SmtpService::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
                if (trim($password) !== '') {
                    try {
                        /** @var MailOutboundRelaySecretService $secrets */
                        $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                        $svc->setData(SmtpService::schema_fields_PASSWORD_SEALED, $secrets->seal($password));
                    } catch (\Throwable $e) {
                        return [
                            'success' => false,
                            'message' => (string)__('共享 Smtp 密码密封失败：%{1}', [$e->getMessage()]),
                            'smtp_service_id' => $existingId,
                            'created' => false,
                        ];
                    }
                }
                $svc->save();
                $message = trim((string)$svc->getData(SmtpService::schema_fields_PASSWORD_SEALED)) === ''
                    ? (string)__('已更新共享 Smtp 账户；缺少密码，请到 Smtp Tab 补齐')
                    : (string)__('已更新共享 Smtp 账户');

                return [
                    'success' => true,
                    'message' => $message,
                    'smtp_service_id' => $existingId,
                    'created' => false,
                ];
            }
        }

        if ($username !== '' && trim($password) === '') {
            return [
                'success' => false,
                'message' => (string)__('缺少外投密码，未登记共享 Smtp 账户'),
                'smtp_service_id' => 0,
                'created' => false,
            ];
        }

        /** @var SmtpServiceCatalogService $catalog */
        $catalog = ObjectManager::getInstance(SmtpServiceCatalogService::class);
        $created = $catalog->createExternalService(
            (string)__('外投共享 · %{1}', [$host]),
            $host,
            $port,
            $username,
            $password,
            $tls
        );
        $serviceId = (int)($created['smtp_service_id'] ?? 0);

        return [
            'success' => $serviceId > 0,
            'message' => $serviceId > 0
                ? (trim($password) === ''
                    ? (string)__('已登记为共享 Smtp 账户，但缺少密码，请到 Smtp Tab 补齐')
                    : (string)__('已登记为共享 Smtp 账户，其他邮箱可直接选择'))
                : (string)($created['message'] ?? __('共享 Smtp 账户登记失败')),
            'smtp_service_id' => $serviceId,
            'created' => $serviceId > 0,
        ];
    }

    /**
     * 将账号当前外投写入域名默认绑定，并补齐同域尚无绑定的账号。
     *
     * @param array{host: string, port: int, username: string, tls: string, sealed: string} $relay
     * @return array{success: bool, message: string, bound: bool, accounts: int}
     */
    public function syncDomainBinding(MailAccount $account, array $relay): array
    {
        $domainId = (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID);
        if ($domainId <= 0) {
            return ['success' => false, 'message' => (string)__('账号未关联邮箱域名'), 'bound' => false, 'accounts' => 0];
        }
        $host = strtolower(trim((string)($relay['host'] ?? '')));
        if ($host === '') {
            return ['success' => false, 'message' => (string)__('外投主机为空，未绑定域名默认'), 'bound' => false, 'accounts' => 0];
        }
        $port = (int)($relay['port'] ?? 587);
        if ($port < 1 || $port > 65535 || $port === 25) {
            $port = 587;
        }
        $username = trim((string)($relay['username'] ?? ''));
        $tls = strtolower(trim((string)($relay['tls'] ?? 'starttls')));
        if (!in_array($tls, ['starttls', 'smtps', 'none'], true)) {
            $tls = 'starttls';
        }
        $sealed = trim((string)($relay['sealed'] ?? ''));
        if ($username !== '' && $sealed === '') {
            return [
                'success' => false,
                'message' => (string)__('缺少外投密码，未绑定域名默认（请填写密码或选择 Smtp 目录）'),
                'bound' => false,
                'accounts' => 0,
            ];
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return ['success' => false, 'message' => (string)__('邮箱域名不存在'), 'bound' => false, 'accounts' => 0];
        }
        $now = date('Y-m-d H:i:s');
        $domain->setData(MailDomain::schema_fields_OUTBOUND_RELAY_HOST, $host)
            ->setData(MailDomain::schema_fields_OUTBOUND_RELAY_PORT, $port)
            ->setData(MailDomain::schema_fields_OUTBOUND_RELAY_USERNAME, $username !== '' ? $username : null)
            ->setData(MailDomain::schema_fields_OUTBOUND_RELAY_TLS, $tls)
            ->setData(MailDomain::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED, $sealed !== '' ? $sealed : null)
            ->setData(MailDomain::schema_fields_UPDATED_AT, $now)
            ->save();

        $applied = $this->applyDomainDefaultToDomainAccounts($domainId, $now);

        return [
            'success' => true,
            'message' => $applied > 0
                ? (string)__('已设为域名默认外投，并同步 %{1} 个同域账号', [(string)$applied])
                : (string)__('已设为域名默认外投，新建同域账号将自动继承'),
            'bound' => true,
            'accounts' => $applied,
        ];
    }

    /**
     * 新建账号时继承域名默认外投；账号已有绑定则不覆盖。
     */
    public function applyDomainDefaultToAccount(MailAccount $account): bool
    {
        $domainId = (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID);
        if ($domainId <= 0 || (int)$account->getId() <= 0) {
            return false;
        }
        if (trim((string)$account->getData(MailAccount::schema_fields_OUTBOUND_RELAY_HOST)) !== '') {
            return false;
        }
        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return false;
        }
        $host = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_HOST)));
        if ($host === '') {
            return false;
        }
        $username = trim((string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_USERNAME));
        $sealed = trim((string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED));
        if ($username !== '' && $sealed === '') {
            return false;
        }
        $this->copyDomainRelayToAccount($account, $domain);
        $account->save();

        return true;
    }

    /**
     * 补齐域名下尚无外投绑定的活跃账号（不覆盖账号自己的绑定）。
     */
    private function applyDomainDefaultToDomainAccounts(int $domainId, string $now): int
    {
        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return 0;
        }
        /** @var MailAccount $model */
        $model = ObjectManager::getInstance(MailAccount::class);
        $rows = $model->clear()
            ->where(MailAccount::schema_fields_DOMAIN_ID, $domainId)
            ->where(MailAccount::schema_fields_STATUS, 'active')
            ->select()
            ->fetchArray();
        $applied = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $accountId = (int)($row[MailAccount::schema_fields_ID] ?? $row['account_id'] ?? 0);
            if ($accountId <= 0) {
                continue;
            }
            $host = trim((string)($row[MailAccount::schema_fields_OUTBOUND_RELAY_HOST]
                ?? $row['outbound_relay_host']
                ?? ''));
            if ($host !== '') {
                continue;
            }
            /** @var MailAccount $account */
            $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
            if (!$account->getId()) {
                continue;
            }
            $this->copyDomainRelayToAccount($account, $domain);
            $account->setData(MailAccount::schema_fields_UPDATED_AT, $now)->save();
            $applied++;
        }

        return $applied;
    }

    private function copyDomainRelayToAccount(MailAccount $account, MailDomain $domain): void
    {
        $account->setData(
            MailAccount::schema_fields_OUTBOUND_RELAY_ENABLED,
            1
        )->setData(
            MailAccount::schema_fields_OUTBOUND_RELAY_HOST,
            (string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_HOST)
        )->setData(
            MailAccount::schema_fields_OUTBOUND_RELAY_PORT,
            (int)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_PORT)
        )->setData(
            MailAccount::schema_fields_OUTBOUND_RELAY_USERNAME,
            (string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_USERNAME) ?: null
        )->setData(
            MailAccount::schema_fields_OUTBOUND_RELAY_TLS,
            (string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_TLS) ?: 'starttls'
        )->setData(
            MailAccount::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED,
            (string)$domain->getData(MailDomain::schema_fields_OUTBOUND_RELAY_PASSWORD_SEALED) ?: null
        );
    }
}
