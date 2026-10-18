<?php
declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailDomain;
use Weline\Mail\Service\MailOutboundRelaySecretService;
use Weline\Mail\Service\StalwartManagementAdapter;
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
        if ($port < 1 || $port > 65535 || $port === 25) {
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
     * 更新外部填入凭据；密码留空则保留原密封值。中继端口禁止 25 → 587。
     *
     * @return array{success: bool, message: string, smtp_service_id: int}
     */
    public function updateExternalService(
        int $serviceId,
        string $name,
        string $host,
        int $port,
        string $username,
        string $password,
        string $tls = 'starttls',
    ): array {
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()) {
            return ['success' => false, 'message' => (string)__('服务不存在'), 'smtp_service_id' => 0];
        }
        if ((string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_EXTERNAL) {
            return ['success' => false, 'message' => (string)__('仅外部填入服务可在此改主机凭据'), 'smtp_service_id' => $serviceId];
        }
        $name = trim($name) !== '' ? trim($name) : (string)$svc->getData(SmtpService::schema_fields_NAME);
        $host = strtolower(trim($host));
        if ($host === '') {
            return ['success' => false, 'message' => (string)__('请填写外部 SMTP 主机'), 'smtp_service_id' => $serviceId];
        }
        if ($port < 1 || $port > 65535 || $port === 25) {
            $port = 587;
        }
        if (!in_array($tls, ['starttls', 'smtps', 'none'], true)) {
            $tls = 'starttls';
        }
        $svc->setData(SmtpService::schema_fields_NAME, $name)
            ->setData(SmtpService::schema_fields_SMTP_HOSTNAME, $host)
            ->setData(SmtpService::schema_fields_PORT, $port)
            ->setData(SmtpService::schema_fields_TLS, $tls)
            ->setData(SmtpService::schema_fields_USERNAME, trim($username) !== '' ? trim($username) : null)
            ->setData(SmtpService::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'));
        if (trim($password) !== '') {
            try {
                /** @var MailOutboundRelaySecretService $secrets */
                $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                $svc->setData(SmtpService::schema_fields_PASSWORD_SEALED, $secrets->seal($password));
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => (string)__('密码密封失败：%{1}', [$e->getMessage()]),
                    'smtp_service_id' => $serviceId,
                ];
            }
        }
        $svc->save();

        return [
            'success' => true,
            'message' => (string)__('外部 Smtp 凭据已更新'),
            'smtp_service_id' => $serviceId,
        ];
    }

    /**
     * 删除 Smtp 服务：外部直接删；本机启用先按账户卸本机 Stalwart，再删目录下属账户，最后删服务。
     *
     * @return array{success: bool, message: string}
     */
    public function deleteService(int $serviceId): array
    {
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()) {
            return ['success' => false, 'message' => (string)__('服务不存在')];
        }
        $mode = (string)$svc->getData(SmtpService::schema_fields_MODE);
        $removedAccounts = 0;
        if ($mode === SmtpService::MODE_SELF) {
            $accounts = $this->listAccountsForService($serviceId);
            /** @var StalwartManagementAdapter $mgmt */
            $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
            foreach ($accounts as $one) {
                if (!$one instanceof SmtpAccount || !(int)$one->getId()) {
                    continue;
                }
                $email = strtolower(trim((string)$one->getData(SmtpAccount::schema_fields_EMAIL)));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    try {
                        $mgmt->deleteAccount($email);
                    } catch (\Throwable $e) {
                        return [
                            'success' => false,
                            'message' => (string)__(
                                'Stalwart 删除账户 %{1} 失败，服务与目录未改：%{2}',
                                [$email, $e->getMessage()]
                            ),
                        ];
                    }
                }
                $one->delete();
                $removedAccounts++;
            }
        }
        $svc->delete();

        return [
            'success' => true,
            'message' => $mode === SmtpService::MODE_EXTERNAL
                ? (string)__('外部 Smtp 已删除')
                : (string)__(
                    '本机 Smtp 服务已删除，下属账户 %{1} 个已从目录与本机 Stalwart 移除',
                    [(string)$removedAccounts]
                ),
        ];
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
        // 本机 Smtp 账号必须先落 Stalwart，再写目录库——禁止「库里有密码、邮局登不上」的假开通。
        try {
            $this->provisionSelfMailboxToStalwart($email, $password);
        } catch (\Throwable $e) {
            return $this->acctFail(__('Stalwart 开通失败：%{1}', [$e->getMessage()]));
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
            'message' => $id > 0 ? (string)__('账户已创建（已同步 Stalwart），请立即复制密码') : (string)__('保存失败'),
            'smtp_account_id' => $id,
            'email' => $email,
            'password_once' => $id > 0 ? $password : '',
        ];
    }

    /**
     * 修改本机服务账户：角色 / 状态 / 显示名（不改邮箱前缀；改密码请用重置）。
     * 状态变更与本机 Stalwart 联动：active→确保邮局有账号；disabled/needs_password→从邮局移除（目录保留）。
     *
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string}
     */
    public function updateAccountOnService(
        int $accountId,
        string $role = '',
        string $status = '',
        string $displayName = '',
    ): array {
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class)->clear()->load($accountId);
        if (!$model->getId()) {
            return $this->acctFail(__('账户不存在'));
        }
        $email = strtolower(trim((string)$model->getData(SmtpAccount::schema_fields_EMAIL)));
        $serviceId = (int)$model->getData(SmtpAccount::schema_fields_SERVICE_ID);
        $prevStatus = strtolower(trim((string)$model->getData(SmtpAccount::schema_fields_STATUS)));
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()) {
            return $this->acctFail(__('服务不存在'), $accountId, $email);
        }
        if ((string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF) {
            return $this->acctFail(__('外部填入服务不在此修改本机账户'), $accountId, $email);
        }
        $role = strtolower(trim($role));
        if ($role !== '') {
            if (!in_array($role, [SmtpAccount::ROLE_SUBMIT, SmtpAccount::ROLE_RELAY], true)) {
                return $this->acctFail(__('角色无效'), $accountId, $email);
            }
            if ($role === SmtpAccount::ROLE_RELAY && !(int)$svc->getData(SmtpService::schema_fields_RELAY_SERVICE)) {
                return $this->acctFail(__('请先在该服务开启中继'), $accountId, $email);
            }
            $model->setData(SmtpAccount::schema_fields_ROLE, $role);
        }
        $status = strtolower(trim($status));
        if ($status !== '') {
            if (!in_array($status, ['active', 'disabled', 'needs_password'], true)) {
                return $this->acctFail(__('状态无效'), $accountId, $email);
            }
            $model->setData(SmtpAccount::schema_fields_STATUS, $status);
        }
        if ($displayName !== '') {
            $model->setData(SmtpAccount::schema_fields_DISPLAY_NAME, trim($displayName));
        }

        $nextStatus = $status !== '' ? $status : $prevStatus;
        $stalwartNote = '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                /** @var StalwartManagementAdapter $mgmt */
                $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
                if ($nextStatus === 'active') {
                    $sealed = trim((string)$model->getData(SmtpAccount::schema_fields_PASSWORD_SEALED));
                    if ($sealed === '') {
                        return $this->acctFail(
                            __('启用前须有密封密码：请先「重置密码」再保存为 active'),
                            $accountId,
                            $email
                        );
                    }
                    /** @var MailOutboundRelaySecretService $secrets */
                    $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                    $plain = $secrets->open($sealed);
                    $this->provisionSelfMailboxToStalwart($email, $plain);
                    $stalwartNote = (string)__('；已同步写入本机 Stalwart');
                } elseif (in_array($nextStatus, ['disabled', 'needs_password'], true)) {
                    // 停用/待设密码：从邮局卸下，避免仍能 AUTH；目录行保留便于再启用。
                    if ($prevStatus === 'active' || $mgmt->accountExists($email)) {
                        $mgmt->deleteAccount($email);
                        $stalwartNote = (string)__('；已从本机 Stalwart 移除登录');
                    }
                }
            } catch (\Throwable $e) {
                return $this->acctFail(
                    __('Stalwart 同步失败，目录未改：%{1}', [$e->getMessage()]),
                    $accountId,
                    $email
                );
            }
        }

        $model->setData(SmtpAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))->save();

        return [
            'success' => true,
            'message' => (string)__(
                '账户已更新：%{1}%{2}',
                [$email !== '' ? $email : (string)$accountId, $stalwartNote]
            ),
            'smtp_account_id' => $accountId,
            'email' => $email,
            'password_once' => '',
        ];
    }

    /**
     * 删除本机服务账户；默认同步从 Stalwart 移除。
     *
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string}
     */
    public function deleteAccountOnService(int $accountId, bool $alsoStalwart = true): array
    {
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class)->clear()->load($accountId);
        if (!$model->getId()) {
            return $this->acctFail(__('账户不存在'));
        }
        $email = strtolower(trim((string)$model->getData(SmtpAccount::schema_fields_EMAIL)));
        $serviceId = (int)$model->getData(SmtpAccount::schema_fields_SERVICE_ID);
        if ($serviceId > 0) {
            /** @var SmtpService $svc */
            $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
            if ($svc->getId()
                && (string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF
            ) {
                return $this->acctFail(__('外部填入服务不在此删除本机账户'), $accountId, $email);
            }
        }
        if ($alsoStalwart && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                /** @var StalwartManagementAdapter $mgmt */
                $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
                $mgmt->deleteAccount($email);
            } catch (\Throwable $e) {
                return $this->acctFail(
                    __('Stalwart 删除失败，目录未改：%{1}', [$e->getMessage()]),
                    $accountId,
                    $email
                );
            }
        }
        $model->delete();

        return [
            'success' => true,
            'message' => (string)__(
                '已删除账户 %{1}%{2}',
                [
                    $email !== '' ? $email : (string)$accountId,
                    $alsoStalwart ? (string)__('（含本机 Stalwart）') : '',
                ]
            ),
            'smtp_account_id' => $accountId,
            'email' => $email,
            'password_once' => '',
        ];
    }

    /**
     * 本机 submit/relay 账号：ensure 域名后写入/重置 Stalwart 凭据。
     */
    private function provisionSelfMailboxToStalwart(string $email, string $password): void
    {
        $email = strtolower(trim($email));
        $password = (string)$password;
        if ($email === '' || $password === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Smtp 邮箱或密码无效。');
        }
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '') {
            throw new \InvalidArgumentException('Smtp 邮箱域名无效。');
        }
        /** @var StalwartManagementAdapter $mgmt */
        $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
        $mgmt->ensureDomain($domain);
        $mgmt->provisionOrResetAccount($email, $password, 128);
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
     * 外投下拉选项（不含密码；保存时用 resolveOutboundPick 取密封密码）。
     *
     * @return list<array{key: string, label: string, host: string, port: int, username: string, tls: string, mode: string}>
     */
    public function listOutboundPicks(): array
    {
        $picks = [];
        foreach ($this->listServices() as $svc) {
            if (!$svc instanceof SmtpService) {
                continue;
            }
            if ((string)$svc->getData(SmtpService::schema_fields_STATUS) !== 'active') {
                continue;
            }
            $sid = (int)$svc->getId();
            $mode = (string)$svc->getData(SmtpService::schema_fields_MODE);
            $host = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
            $port = (int)$svc->getData(SmtpService::schema_fields_PORT);
            if ($port < 1 || $port > 65535) {
                $port = 587;
            }
            $tls = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_TLS))) ?: 'starttls';
            $name = trim((string)$svc->getData(SmtpService::schema_fields_NAME));
            if ($mode === SmtpService::MODE_EXTERNAL) {
                if ($host === '') {
                    continue;
                }
                $user = trim((string)$svc->getData(SmtpService::schema_fields_USERNAME));
                $picks[] = [
                    'key' => 'ext:' . $sid,
                    'label' => (string)__('外部 · %{1}（%{2}:%{3}）', [
                        $name !== '' ? $name : $host,
                        $host,
                        (string)$port,
                    ]),
                    'host' => $host,
                    'port' => $port,
                    'username' => $user,
                    'tls' => $tls,
                    'mode' => 'external',
                ];
                continue;
            }
            if ($mode !== SmtpService::MODE_SELF) {
                continue;
            }
            foreach ($this->listAccountsForService($sid) as $acct) {
                if (!$acct instanceof SmtpAccount) {
                    continue;
                }
                if ((string)$acct->getData(SmtpAccount::schema_fields_STATUS) !== 'active') {
                    continue;
                }
                $email = strtolower(trim((string)$acct->getData(SmtpAccount::schema_fields_EMAIL)));
                if ($email === '' || $host === '') {
                    continue;
                }
                $role = (string)$acct->getData(SmtpAccount::schema_fields_ROLE);
                $picks[] = [
                    'key' => 'acct:' . (int)$acct->getId(),
                    'label' => (string)__('本机 · %{1}（%{2} → %{3}:%{4}）', [
                        $email,
                        $role !== '' ? $role : 'submit',
                        $host,
                        (string)$port,
                    ]),
                    'host' => $host,
                    'port' => $port,
                    'username' => $email,
                    'tls' => $tls,
                    'mode' => 'self',
                ];
            }
        }

        return $picks;
    }

    /**
     * @return array{success: bool, message: string, host: string, port: int, username: string, password: string, tls: string}
     */
    public function resolveOutboundPick(string $pickKey): array
    {
        $pickKey = trim($pickKey);
        $empty = [
            'success' => false,
            'message' => (string)__('请选择 Smtp 账户或服务'),
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'tls' => 'starttls',
        ];
        if ($pickKey === '') {
            return $empty;
        }
        if (str_starts_with($pickKey, 'acct:')) {
            $accountId = (int)substr($pickKey, 5);
            if ($accountId <= 0) {
                return $empty;
            }
            /** @var SmtpAccount $acct */
            $acct = ObjectManager::getInstance(SmtpAccount::class)->clear()->load($accountId);
            if (!$acct->getId()) {
                return array_merge($empty, ['message' => (string)__('Smtp 账户不存在')]);
            }
            $sid = (int)$acct->getData(SmtpAccount::schema_fields_SERVICE_ID);
            /** @var SmtpService $svc */
            $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($sid);
            if (!$svc->getId() || (string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF) {
                return array_merge($empty, ['message' => (string)__('所属本机 Smtp 服务无效')]);
            }
            $revealed = $this->revealAccountPassword($accountId);
            if (empty($revealed['success'])) {
                return array_merge($empty, ['message' => (string)($revealed['message'] ?? __('无法读取账户密码'))]);
            }
            $host = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
            $port = (int)$svc->getData(SmtpService::schema_fields_PORT);
            $tls = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_TLS))) ?: 'starttls';

            return [
                'success' => true,
                'message' => '',
                'host' => $host,
                'port' => ($port >= 1 && $port <= 65535) ? $port : 587,
                'username' => strtolower(trim((string)$acct->getData(SmtpAccount::schema_fields_EMAIL))),
                'password' => (string)($revealed['password_once'] ?? ''),
                'tls' => in_array($tls, ['starttls', 'smtps', 'none'], true) ? $tls : 'starttls',
            ];
        }
        if (str_starts_with($pickKey, 'ext:')) {
            $serviceId = (int)substr($pickKey, 4);
            if ($serviceId <= 0) {
                return $empty;
            }
            /** @var SmtpService $svc */
            $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
            if (!$svc->getId() || (string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_EXTERNAL) {
                return array_merge($empty, ['message' => (string)__('外部 Smtp 服务不存在')]);
            }
            $host = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
            $port = (int)$svc->getData(SmtpService::schema_fields_PORT);
            $tls = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_TLS))) ?: 'starttls';
            $user = trim((string)$svc->getData(SmtpService::schema_fields_USERNAME));
            $password = '';
            $sealed = (string)$svc->getData(SmtpService::schema_fields_PASSWORD_SEALED);
            if ($sealed !== '') {
                try {
                    /** @var MailOutboundRelaySecretService $secrets */
                    $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                    $password = $secrets->open($sealed);
                } catch (\Throwable $e) {
                    return array_merge($empty, ['message' => (string)__('无法解密外部 Smtp 密码：%{1}', [$e->getMessage()])]);
                }
            }

            return [
                'success' => true,
                'message' => '',
                'host' => $host,
                'port' => ($port >= 1 && $port <= 65535) ? $port : 587,
                'username' => $user,
                'password' => $password,
                'tls' => in_array($tls, ['starttls', 'smtps', 'none'], true) ? $tls : 'starttls',
            ];
        }

        return $empty;
    }

    /**
     * 解密展示（不落明文）；页面只 flash 一次。
     *
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string}
     */
    public function revealAccountPassword(int $accountId): array
    {
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class)->clear()->load($accountId);
        if (!$model->getId()) {
            return $this->acctFail(__('账户不存在'));
        }
        $sealed = (string)$model->getData(SmtpAccount::schema_fields_PASSWORD_SEALED);
        if ($sealed === '') {
            return $this->acctFail(__('该账户无密封密码'), $accountId, (string)$model->getData(SmtpAccount::schema_fields_EMAIL));
        }
        try {
            /** @var MailOutboundRelaySecretService $secrets */
            $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
            $plain = $secrets->open($sealed);
        } catch (\Throwable $e) {
            return $this->acctFail(__('无法解密密码：%{1}', [$e->getMessage()]), $accountId, (string)$model->getData(SmtpAccount::schema_fields_EMAIL));
        }

        return [
            'success' => true,
            'message' => (string)__('密码如下（请立即复制，页面不会常显）'),
            'smtp_account_id' => $accountId,
            'email' => (string)$model->getData(SmtpAccount::schema_fields_EMAIL),
            'password_once' => $plain,
        ];
    }

    /**
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string}
     */
    public function resetAccountPassword(int $accountId): array
    {
        /** @var SmtpAccountManageService $manage */
        $manage = ObjectManager::getInstance(SmtpAccountManageService::class);
        $result = $manage->resetPassword($accountId);

        return [
            'success' => !empty($result['success']),
            'message' => (string)($result['message'] ?? __('重置失败')),
            'smtp_account_id' => (int)($result['smtp_account_id'] ?? $accountId),
            'email' => (string)($result['email'] ?? ''),
            'password_once' => (string)($result['password_once'] ?? ''),
        ];
    }

    /**
     * 探测本机服务账户是否已存在于本机 Stalwart 邮局（一次按域拉清单，避免逐账号往返）。
     *
     * @return array{
     *   by_account_id: array<int, bool>,
     *   probe_ok: bool,
     *   target_label: string,
     *   stalwart_local_parts: list<string>
     * }
     */
    public function probeStalwartPresenceForService(int $serviceId): array
    {
        $empty = [
            'by_account_id' => [],
            'probe_ok' => false,
            'target_label' => (string)__('本机 Stalwart 邮局'),
            'stalwart_local_parts' => [],
        ];
        if ($serviceId <= 0) {
            return $empty;
        }
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()
            || (string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF
        ) {
            return $empty;
        }
        $host = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
        $domain = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_DOMAIN_NAME)));
        $target = $host !== ''
            ? (string)__('本机 Stalwart 邮局（%{1}）', [$host])
            : (string)__('本机 Stalwart 邮局');
        $empty['target_label'] = $target;
        if ($domain === '') {
            return $empty;
        }
        try {
            /** @var StalwartManagementAdapter $mgmt */
            $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
            $locals = $mgmt->listLocalPartsForDomain($domain);
            $set = [];
            foreach ($locals as $local) {
                $set[strtolower(trim((string)$local))] = true;
            }
            $byId = [];
            foreach ($this->listAccountsForService($serviceId) as $acct) {
                $aid = (int)$acct->getId();
                if ($aid <= 0) {
                    continue;
                }
                $email = strtolower(trim((string)$acct->getData(SmtpAccount::schema_fields_EMAIL)));
                $at = strrpos($email, '@');
                $local = $at === false ? '' : substr($email, 0, $at);
                $byId[$aid] = $local !== '' && isset($set[$local]);
            }

            return [
                'by_account_id' => $byId,
                'probe_ok' => true,
                'target_label' => $target,
                'stalwart_local_parts' => array_keys($set),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * 把企业邮箱「域名与 DNS」里已登记的本机邮局（MailDomain / Stalwart）同步成 Smtp 服务目录行。
     * 不新建密码、不改 Stalwart；已有同根域或同主机的服务跳过；新建后尽量同步账号目录。
     *
     * @return array{
     *   success: bool,
     *   message: string,
     *   imported: int,
     *   skipped: int,
     *   accounts_imported: int,
     *   services: list<array{smtp_service_id:int,domain:string,smtp_hostname:string}>
     * }
     */
    public function importSelfServicesFromMailDomains(): array
    {
        /** @var MailDomain $domainModel */
        $domainModel = ObjectManager::getInstance(MailDomain::class);
        $collection = $domainModel->clear()->order(MailDomain::schema_fields_ID, 'ASC')->select()->fetch();
        $rows = is_object($collection) && method_exists($collection, 'getItems')
            ? $collection->getItems()
            : (is_iterable($collection) ? $collection : []);

        $existingByDomain = [];
        $existingByHost = [];
        foreach ($this->listServices() as $svc) {
            $d = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_DOMAIN_NAME)));
            $h = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
            if ($d !== '') {
                $existingByDomain[$d] = (int)$svc->getId();
            }
            if ($h !== '') {
                $existingByHost[$h] = (int)$svc->getId();
            }
        }

        $imported = 0;
        $skipped = 0;
        $accountsImported = 0;
        $created = [];
        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            if (!$row instanceof MailDomain) {
                continue;
            }
            $domain = strtolower(trim((string)$row->getData(MailDomain::schema_fields_DOMAIN_NAME)));
            if ($domain === '') {
                continue;
            }
            $hostname = strtolower(trim((string)$row->getData(MailDomain::schema_fields_HOSTNAME)));
            if ($hostname === '') {
                $hostname = 'mail.' . $domain;
            }
            if (isset($existingByDomain[$domain]) || isset($existingByHost[$hostname])) {
                $skipped++;
                continue;
            }

            $poolDomainId = $this->resolveExistingPoolId($domain, $hostname);
            $name = (string)__('本机 Smtp · %{1}', [$hostname]);
            /** @var SmtpService $svc */
            $svc = ObjectManager::getInstance(SmtpService::class);
            $svc->clear()
                ->setData(SmtpService::schema_fields_NAME, $name)
                ->setData(SmtpService::schema_fields_MODE, SmtpService::MODE_SELF)
                ->setData(SmtpService::schema_fields_STATUS, 'active')
                ->setData(SmtpService::schema_fields_DOMAIN_NAME, $domain)
                ->setData(SmtpService::schema_fields_SMTP_HOSTNAME, $hostname)
                ->setData(SmtpService::schema_fields_PORT, 587)
                ->setData(SmtpService::schema_fields_TLS, 'starttls')
                ->setData(SmtpService::schema_fields_RELAY_SERVICE, 0)
                ->setData(SmtpService::schema_fields_POOL_DOMAIN_ID, $poolDomainId)
                ->setData(SmtpService::schema_fields_CREATED_AT, $now)
                ->setData(SmtpService::schema_fields_UPDATED_AT, $now)
                ->save();
            $id = (int)$svc->getId();
            if ($id <= 0) {
                continue;
            }
            $imported++;
            $existingByDomain[$domain] = $id;
            $existingByHost[$hostname] = $id;
            $created[] = [
                'smtp_service_id' => $id,
                'domain' => $domain,
                'smtp_hostname' => $hostname,
            ];
            $acctSync = $this->importAccountsFromStalwart($id);
            if (!empty($acctSync['success'])) {
                $accountsImported += (int)($acctSync['imported'] ?? 0);
            }
        }

        if ($imported === 0 && $skipped === 0) {
            return [
                'success' => false,
                'message' => (string)__('企业邮箱域名池为空，请先在「域名与 DNS」登记本机邮局域名'),
                'imported' => 0,
                'skipped' => 0,
                'accounts_imported' => 0,
                'services' => [],
            ];
        }

        return [
            'success' => true,
            'message' => (string)__(
                '已从企业邮箱域名同步：新增 %{1} 个 Smtp 服务，已有 %{2} 个跳过；同步账号目录 %{3} 个。',
                [(string)$imported, (string)$skipped, (string)$accountsImported]
            ),
            'imported' => $imported,
            'skipped' => $skipped,
            'accounts_imported' => $accountsImported,
            'services' => $created,
        ];
    }

    /**
     * 把本机 Stalwart 里已有、但 Smtp 目录缺失的账号同步进目录（无密码，需重置后才能再「写入」）。
     *
     * @return array{success: bool, message: string, imported: int, skipped: int}
     */
    public function importAccountsFromStalwart(int $serviceId): array
    {
        if ($serviceId <= 0) {
            return ['success' => false, 'message' => (string)__('服务不存在'), 'imported' => 0, 'skipped' => 0];
        }
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()) {
            return ['success' => false, 'message' => (string)__('服务不存在'), 'imported' => 0, 'skipped' => 0];
        }
        if ((string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF) {
            return [
                'success' => false,
                'message' => (string)__('外部填入服务无需从本机 Stalwart 同步账号'),
                'imported' => 0,
                'skipped' => 0,
            ];
        }
        $domain = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_DOMAIN_NAME)));
        if ($domain === '') {
            return ['success' => false, 'message' => (string)__('服务域名无效'), 'imported' => 0, 'skipped' => 0];
        }
        try {
            /** @var StalwartManagementAdapter $mgmt */
            $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
            $locals = $mgmt->listLocalPartsForDomain($domain);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__('读取本机 Stalwart 账号失败：%{1}', [$e->getMessage()]),
                'imported' => 0,
                'skipped' => 0,
            ];
        }
        $existing = [];
        foreach ($this->listAccountsForService($serviceId) as $acct) {
            $email = strtolower(trim((string)$acct->getData(SmtpAccount::schema_fields_EMAIL)));
            if ($email !== '') {
                $existing[$email] = true;
            }
        }
        $imported = 0;
        $skipped = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($locals as $local) {
            $local = strtolower(trim((string)$local));
            if ($local === '') {
                continue;
            }
            $email = $local . '@' . $domain;
            if (isset($existing[$email])) {
                $skipped++;
                continue;
            }
            /** @var SmtpAccount $dupCheck */
            $dupCheck = ObjectManager::getInstance(SmtpAccount::class);
            $dup = $dupCheck->clear()->where(SmtpAccount::schema_fields_EMAIL, $email)->find()->fetch();
            if ($dup instanceof SmtpAccount && $dup->getId()) {
                $skipped++;
                continue;
            }
            /** @var SmtpAccount $model */
            $model = ObjectManager::getInstance(SmtpAccount::class);
            $model->clear()
                ->setData(SmtpAccount::schema_fields_SERVICE_ID, $serviceId)
                ->setData(SmtpAccount::schema_fields_DOMAIN_NAME, $domain)
                ->setData(SmtpAccount::schema_fields_EMAIL, $email)
                ->setData(SmtpAccount::schema_fields_DISPLAY_NAME, $local)
                ->setData(SmtpAccount::schema_fields_ROLE, SmtpAccount::ROLE_SUBMIT)
                ->setData(SmtpAccount::schema_fields_STATUS, 'needs_password')
                ->setData(SmtpAccount::schema_fields_PASSWORD_SEALED, '')
                ->setData(SmtpAccount::schema_fields_CREATED_AT, $now)
                ->setData(SmtpAccount::schema_fields_UPDATED_AT, $now)
                ->save();
            if ((int)$model->getId() > 0) {
                $imported++;
                $existing[$email] = true;
            }
        }

        return [
            'success' => true,
            'message' => (string)__(
                '已从本机 Stalwart 同步：新增 %{1} 个目录账号，已有 %{2} 个跳过。无密码账号请先「重置密码」。',
                [(string)$imported, (string)$skipped]
            ),
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    /**
     * 把目录里已有本机账户（密封密码）补写入本机 Stalwart 邮局，不改密码、不重建库行。
     *
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string}
     */
    public function syncAccountToStalwart(int $accountId): array
    {
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class)->clear()->load($accountId);
        if (!$model->getId()) {
            return $this->acctFail(__('账户不存在'));
        }
        $email = strtolower(trim((string)$model->getData(SmtpAccount::schema_fields_EMAIL)));
        $serviceId = (int)$model->getData(SmtpAccount::schema_fields_SERVICE_ID);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->acctFail(__('账户邮箱无效'), $accountId, $email);
        }
        $targetHost = '';
        if ($serviceId > 0) {
            /** @var SmtpService $svc */
            $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
            if ($svc->getId()
                && (string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF
            ) {
                return $this->acctFail(__('外部填入服务无需写入本机 Stalwart'), $accountId, $email);
            }
            if ($svc->getId()) {
                $targetHost = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_SMTP_HOSTNAME)));
            }
        }
        try {
            /** @var StalwartManagementAdapter $mgmt */
            $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
            if ($mgmt->accountExists($email)) {
                return [
                    'success' => true,
                    'message' => (string)__('%{1} 已在本机 Stalwart 邮局%{2}，无需再写入', [
                        $email,
                        $targetHost !== '' ? '（' . $targetHost . '）' : '',
                    ]),
                    'smtp_account_id' => $accountId,
                    'email' => $email,
                    'password_once' => '',
                ];
            }
        } catch (\Throwable) {
            // 探测失败时仍尝试写入（凭据/邮局短暂不可达时不挡补写）
        }
        $sealed = (string)$model->getData(SmtpAccount::schema_fields_PASSWORD_SEALED);
        if ($sealed === '') {
            return $this->acctFail(__('该账户无密封密码，请先重置密码'), $accountId, $email);
        }
        try {
            /** @var MailOutboundRelaySecretService $secrets */
            $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
            $plain = $secrets->open($sealed);
            $this->provisionSelfMailboxToStalwart($email, $plain);
        } catch (\Throwable $e) {
            return $this->acctFail(__('写入本机 Stalwart 邮局失败：%{1}', [$e->getMessage()]), $accountId, $email);
        }
        $model->setData(SmtpAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))->save();

        return [
            'success' => true,
            'message' => (string)__('已将 %{1} 写入本机 Stalwart 邮局%{2}（密码未改）', [
                $email,
                $targetHost !== '' ? '（' . $targetHost . '）' : '',
            ]),
            'smtp_account_id' => $accountId,
            'email' => $email,
            'password_once' => '',
        ];
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
