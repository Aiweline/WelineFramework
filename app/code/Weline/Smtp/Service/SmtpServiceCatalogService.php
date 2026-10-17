<?php
declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailOutboundRelaySecretService;
use Weline\Smtp\Model\SmtpAccount;
use Weline\Smtp\Model\SmtpService;

/**
 * 多 Smtp 服务目录：self=本机启用入池；external=仅外部填入凭据。
 */
final class SmtpServiceCatalogService
{
    /**
     * @return list<SmtpService>
     */
    public function listServices(): array
    {
        /** @var SmtpService $model */
        $model = ObjectManager::getInstance(SmtpService::class);
        $collection = $model->clear()->order(SmtpService::schema_fields_ID, 'DESC')->select()->fetch();
        $rows = is_object($collection) && method_exists($collection, 'getItems')
            ? $collection->getItems()
            : (is_iterable($collection) ? $collection : []);
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof SmtpService) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{success: bool, message: string, smtp_service_id: int, email?: string, password_once?: string}
     */
    public function createSelfService(
        string $name,
        string $domainInput,
        string $expectedIp = '',
        bool $relayService = false,
    ): array {
        $name = trim($name) !== '' ? trim($name) : (string)__('本机 Smtp 服务');
        /** @var SmtpServiceEnableService $enable */
        $enable = ObjectManager::getInstance(SmtpServiceEnableService::class);
        $parsed = $enable->normalizeDomainInput($domainInput);
        if ($parsed['root'] === '') {
            return ['success' => false, 'message' => (string)__('请填写或选择域名'), 'smtp_service_id' => 0];
        }
        $dns = $enable->assertDnsResolved($parsed['smtp_hostname'], $expectedIp);
        if (empty($dns['success'])) {
            return [
                'success' => false,
                'message' => (string)($dns['message'] ?? __('DNS 未通过')),
                'smtp_service_id' => 0,
            ];
        }
        $originIp = trim($expectedIp) !== '' ? trim($expectedIp) : (string)($dns['resolved_ip'] ?? '');
        // 已在域名池（用户从池选或历史入池）：允许登记服务；ensure/出证失败不挡保存。
        // 未入池的自填域名：仍必须 ensure 成功（需 DNS 商凭据）。
        $existingPoolId = $this->resolveExistingPoolId($parsed['root'], $parsed['smtp_hostname']);
        $pool = null;
        $poolWarn = '';
        try {
            $pool = w_query('websites', 'ensurePublicDnsHostnamesInPool', [
                'root_domain' => $parsed['root'],
                'hostnames' => [$parsed['smtp_hostname']],
                'origin_ip' => $originIp,
                'request_certificate' => true,
                'source' => 'smtp_service_self',
            ]);
        } catch (\Throwable $e) {
            if ($existingPoolId <= 0) {
                return ['success' => false, 'message' => (string)__('入池失败：%{1}', [$e->getMessage()]), 'smtp_service_id' => 0];
            }
            $poolWarn = (string)__('入池/出证暂未能同步：%{1}', [$e->getMessage()]);
        }
        if (is_array($pool) && empty($pool['success']) && $existingPoolId <= 0) {
            return [
                'success' => false,
                'message' => (string)($pool['message'] ?? __('入池失败')),
                'smtp_service_id' => 0,
            ];
        }
        if (is_array($pool) && empty($pool['success']) && $existingPoolId > 0) {
            $poolWarn = (string)($pool['message'] ?? __('入池/出证暂未能同步'));
        }
        $poolDomainId = (int)($pool['domain_id'] ?? 0);
        if ($poolDomainId <= 0) {
            $poolDomainId = $existingPoolId;
        }
        $now = date('Y-m-d H:i:s');
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class);
        $svc->clear()
            ->setData(SmtpService::schema_fields_NAME, $name)
            ->setData(SmtpService::schema_fields_MODE, SmtpService::MODE_SELF)
            ->setData(SmtpService::schema_fields_STATUS, 'active')
            ->setData(SmtpService::schema_fields_DOMAIN_NAME, $parsed['root'])
            ->setData(SmtpService::schema_fields_SMTP_HOSTNAME, $parsed['smtp_hostname'])
            ->setData(SmtpService::schema_fields_PORT, 587)
            ->setData(SmtpService::schema_fields_TLS, 'starttls')
            ->setData(SmtpService::schema_fields_RELAY_SERVICE, $relayService ? 1 : 0)
            ->setData(SmtpService::schema_fields_POOL_DOMAIN_ID, $poolDomainId)
            ->setData(SmtpService::schema_fields_CREATED_AT, $now)
            ->setData(SmtpService::schema_fields_UPDATED_AT, $now)
            ->save();
        $id = (int)$svc->getId();
        if ($id <= 0) {
            return ['success' => false, 'message' => (string)__('保存失败'), 'smtp_service_id' => 0];
        }
        $msg = $poolWarn !== ''
            ? (string)__('本机 Smtp 服务已启用（域名已在池中）。%{1}', [$poolWarn])
            : (string)__('本机 Smtp 服务已启用并入池出证');
        // 默认创建 submit 账号 smtp@{根域}，密码仅本次返回。
        $acct = $this->createAccountOnService($id, 'smtp', SmtpAccount::ROLE_SUBMIT);
        $passwordOnce = '';
        $email = '';
        if (!empty($acct['success'])) {
            $passwordOnce = (string)($acct['password_once'] ?? '');
            $email = (string)($acct['email'] ?? '');
            if ($passwordOnce !== '') {
                $msg .= ' ' . (string)__('已默认创建发信账号 %{1}，密码（仅一次）：%{2}', [$email, $passwordOnce]);
            } else {
                $msg .= ' ' . (string)__('已默认创建发信账号 %{1}', [$email !== '' ? $email : 'smtp@' . $parsed['root']]);
            }
        } elseif (!empty($acct['message'])) {
            $msg .= ' ' . (string)__('默认账号未创建：%{1}', [(string)$acct['message']]);
        }

        return [
            'success' => true,
            'message' => $msg,
            'smtp_service_id' => $id,
            'email' => $email,
            'password_once' => $passwordOnce,
        ];
    }

    /**
     * @return array{success: bool, message: string, smtp_service_id: int}
     */
    public function createExternalService(
        string $name,
        string $host,
        int $port,
        string $username,
        string $password,
        string $tls = 'starttls',
    ): array {
        $name = trim($name) !== '' ? trim($name) : (string)__('外部 Smtp');
        $host = strtolower(trim($host));
        if ($host === '') {
            return ['success' => false, 'message' => (string)__('请填写外部 SMTP 主机'), 'smtp_service_id' => 0];
        }
        if ($port < 1 || $port > 65535) {
            $port = 587;
        }
        if (!in_array($tls, ['starttls', 'smtps', 'none'], true)) {
            $tls = 'starttls';
        }
        $sealed = null;
        if (trim($password) !== '') {
            try {
                /** @var MailOutboundRelaySecretService $secrets */
                $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                $sealed = $secrets->seal($password);
            } catch (\Throwable $e) {
                return ['success' => false, 'message' => (string)__('密码密封失败：%{1}', [$e->getMessage()]), 'smtp_service_id' => 0];
            }
        }
        $now = date('Y-m-d H:i:s');
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class);
        $svc->clear()
            ->setData(SmtpService::schema_fields_NAME, $name)
            ->setData(SmtpService::schema_fields_MODE, SmtpService::MODE_EXTERNAL)
            ->setData(SmtpService::schema_fields_STATUS, 'active')
            ->setData(SmtpService::schema_fields_SMTP_HOSTNAME, $host)
            ->setData(SmtpService::schema_fields_PORT, $port)
            ->setData(SmtpService::schema_fields_TLS, $tls)
            ->setData(SmtpService::schema_fields_USERNAME, trim($username) !== '' ? trim($username) : null)
            ->setData(SmtpService::schema_fields_PASSWORD_SEALED, $sealed)
            ->setData(SmtpService::schema_fields_RELAY_SERVICE, 0)
            ->setData(SmtpService::schema_fields_CREATED_AT, $now)
            ->setData(SmtpService::schema_fields_UPDATED_AT, $now)
            ->save();
        $id = (int)$svc->getId();

        return [
            'success' => $id > 0,
            'message' => $id > 0
                ? (string)__('外部 Smtp 已登记（不入本机域名池；可拷贝给邮箱外投或传输）')
                : (string)__('保存失败'),
            'smtp_service_id' => $id,
        ];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function setStatus(int $serviceId, string $status): array
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            return ['success' => false, 'message' => (string)__('状态无效')];
        }
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()) {
            return ['success' => false, 'message' => (string)__('服务不存在')];
        }
        $svc->setData(SmtpService::schema_fields_STATUS, $status)
            ->setData(SmtpService::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return ['success' => true, 'message' => (string)__('状态已更新')];
    }

    /**
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string}
     */
    public function createAccountOnService(int $serviceId, string $localPart, string $role = SmtpAccount::ROLE_SUBMIT): array
    {
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()) {
            return $this->acctFail(__('服务不存在'));
        }
        if ((string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF) {
            return $this->acctFail(__('外部填入服务不建本机账户；请直接拷贝主机凭据'));
        }
        if ((string)$svc->getData(SmtpService::schema_fields_STATUS) !== 'active') {
            return $this->acctFail(__('服务未启用'));
        }
        if ($role === SmtpAccount::ROLE_RELAY && !(int)$svc->getData(SmtpService::schema_fields_RELAY_SERVICE)) {
            return $this->acctFail(__('请先在该服务开启中继'));
        }
        $domain = (string)$svc->getData(SmtpService::schema_fields_DOMAIN_NAME);
        $localPart = strtolower(trim($localPart));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $localPart) || $domain === '') {
            return $this->acctFail(__('账号或域名无效'));
        }
        $email = $localPart . '@' . $domain;
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class);
        $existing = $model->clear()->where(SmtpAccount::schema_fields_EMAIL, $email)->find()->fetch();
        if ($existing->getId()) {
            return $this->acctFail(__('账户已存在'), (int)$existing->getId(), $email);
        }
        $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '._'), '=');
        try {
            /** @var MailOutboundRelaySecretService $secrets */
            $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
            $sealed = $secrets->seal($password);
        } catch (\Throwable $e) {
            return $this->acctFail(__('密码密封失败：%{1}', [$e->getMessage()]));
        }
        $now = date('Y-m-d H:i:s');
        $role = in_array($role, [SmtpAccount::ROLE_SUBMIT, SmtpAccount::ROLE_RELAY], true)
            ? $role
            : SmtpAccount::ROLE_SUBMIT;
        $model->clear()
            ->setData(SmtpAccount::schema_fields_SERVICE_ID, $serviceId)
            ->setData(SmtpAccount::schema_fields_DOMAIN_NAME, $domain)
            ->setData(SmtpAccount::schema_fields_EMAIL, $email)
            ->setData(SmtpAccount::schema_fields_DISPLAY_NAME, $localPart)
            ->setData(SmtpAccount::schema_fields_ROLE, $role)
            ->setData(SmtpAccount::schema_fields_STATUS, 'active')
            ->setData(SmtpAccount::schema_fields_PASSWORD_SEALED, $sealed)
            ->setData(SmtpAccount::schema_fields_CREATED_AT, $now)
            ->setData(SmtpAccount::schema_fields_UPDATED_AT, $now)
            ->save();
        $id = (int)$model->getId();

        return [
            'success' => $id > 0,
            'message' => $id > 0 ? (string)__('账户已创建，请立即复制密码') : (string)__('保存失败'),
            'smtp_account_id' => $id,
            'email' => $email,
            'password_once' => $id > 0 ? $password : '',
        ];
    }

    /**
     * @return list<SmtpAccount>
     */
    public function listAccountsForService(int $serviceId): array
    {
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class);
        $collection = $model->clear()
            ->where(SmtpAccount::schema_fields_SERVICE_ID, $serviceId)
            ->order(SmtpAccount::schema_fields_EMAIL, 'ASC')
            ->select()
            ->fetch();
        $rows = is_object($collection) && method_exists($collection, 'getItems')
            ? $collection->getItems()
            : (is_iterable($collection) ? $collection : []);
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof SmtpAccount) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * 已在域名池则返回可用 pool_id（优先精确匹配 smtp 主机，其次根域）。
     */
    private function resolveExistingPoolId(string $root, string $smtpHostname): int
    {
        $root = strtolower(trim($root));
        $smtpHostname = strtolower(trim($smtpHostname));
        if ($root === '') {
            return 0;
        }
        try {
            $list = w_query('websites', 'getDomainPoolList', [
                'root_domain' => $root,
                'limit' => 200,
            ]);
        } catch (\Throwable) {
            return 0;
        }
        if (!is_array($list)) {
            return 0;
        }
        $rows = isset($list['items']) && is_array($list['items']) ? $list['items'] : $list;
        if (!is_array($rows)) {
            return 0;
        }
        $rootId = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $domain = strtolower(trim((string)($row['domain'] ?? $row['domain_name'] ?? '')));
            $pid = (int)($row['pool_id'] ?? $row['domain_id'] ?? $row['id'] ?? 0);
            if ($pid <= 0 || $domain === '') {
                continue;
            }
            if ($smtpHostname !== '' && $domain === $smtpHostname) {
                return $pid;
            }
            if ($domain === $root && $rootId <= 0) {
                $rootId = $pid;
            }
        }

        return $rootId;
    }

    /**
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string}
     */
    private function acctFail(string $message, int $id = 0, string $email = ''): array
    {
        return [
            'success' => false,
            'message' => $message,
            'smtp_account_id' => $id,
            'email' => $email,
            'password_once' => '',
        ];
    }
}
