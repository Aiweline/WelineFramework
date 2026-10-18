<?php
declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailOutboundRelaySecretService;
use Weline\Mail\Service\StalwartManagementAdapter;
use Weline\Smtp\Model\SmtpAccount;
use Weline\Smtp\Model\SmtpService;

/**
 * 本机 Smtp 固定系统账号：启用时幂等创建；禁止删除与改密。
 */
final class SmtpSystemAccountProvisioner
{
    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   created: list<array{email: string, password_once: string}>,
     *   ensured: list<string>
     * }
     */
    public function ensureForService(int $serviceId): array
    {
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()) {
            return $this->emptyResult(false, (string)__('服务不存在'));
        }
        if ((string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF) {
            return $this->emptyResult(false, (string)__('外部填入服务不创建本机系统账号'));
        }
        $domain = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_DOMAIN_NAME)));
        if ($domain === '') {
            return $this->emptyResult(false, (string)__('服务域名无效'));
        }
        $relay = (int)$svc->getData(SmtpService::schema_fields_RELAY_SERVICE) === 1;

        return $this->ensureForDomain($domain, $relay, $serviceId);
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   created: list<array{email: string, password_once: string}>,
     *   ensured: list<string>
     * }
     */
    public function ensureForDomain(string $domain, bool $relayEnabled, int $serviceId = 0): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$/', $domain)) {
            return $this->emptyResult(false, (string)__('域名无效'));
        }
        $created = [];
        $ensured = [];
        $notes = [];
        foreach (SmtpAccount::systemLocalParts($relayEnabled) as $local => $role) {
            $one = $this->ensureOne($domain, $local, $role, $serviceId);
            if (empty($one['success'])) {
                $notes[] = (string)($one['message'] ?? __('系统账号失败'));

                continue;
            }
            $email = (string)($one['email'] ?? '');
            if ($email !== '') {
                $ensured[] = $email;
            }
            $once = (string)($one['password_once'] ?? '');
            if ($once !== '' && $email !== '') {
                $created[] = ['email' => $email, 'password_once' => $once];
            }
        }
        if ($ensured === [] && $notes !== []) {
            return [
                'success' => false,
                'message' => implode('；', $notes),
                'created' => [],
                'ensured' => [],
            ];
        }
        $msg = $ensured !== []
            ? (string)__('系统账号已就绪：%{1}', [implode(', ', $ensured)])
            : (string)__('无需创建系统账号');
        if ($created !== []) {
            $bits = [];
            foreach ($created as $row) {
                $bits[] = $row['email'] . '=' . $row['password_once'];
            }
            $msg .= ' ' . (string)__('新建密码（仅一次）：%{1}', [implode('；', $bits)]);
        }
        if ($notes !== []) {
            $msg .= ' ' . implode('；', $notes);
        }

        return [
            'success' => true,
            'message' => $msg,
            'created' => $created,
            'ensured' => $ensured,
        ];
    }

    /**
     * @return array{success: bool, message: string, email: string, password_once: string, created: bool}
     */
    private function ensureOne(string $domain, string $local, string $role, int $serviceId): array
    {
        $email = $local . '@' . $domain;
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class);
        $existing = $model->clear()->where(SmtpAccount::schema_fields_EMAIL, $email)->find()->fetch();
        if ($existing instanceof SmtpAccount && $existing->getId()) {
            $dirty = false;
            if ((int)$existing->getData(SmtpAccount::schema_fields_IS_SYSTEM) !== 1) {
                $existing->setData(SmtpAccount::schema_fields_IS_SYSTEM, 1);
                $dirty = true;
            }
            if ($serviceId > 0 && (int)$existing->getData(SmtpAccount::schema_fields_SERVICE_ID) <= 0) {
                $existing->setData(SmtpAccount::schema_fields_SERVICE_ID, $serviceId);
                $dirty = true;
            }
            $curRole = (string)$existing->getData(SmtpAccount::schema_fields_ROLE);
            if ($curRole !== $role) {
                $existing->setData(SmtpAccount::schema_fields_ROLE, $role);
                $dirty = true;
            }
            if ($dirty) {
                $existing->setData(SmtpAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))->save();
            }

            return [
                'success' => true,
                'message' => (string)__('系统账号已存在'),
                'email' => $email,
                'password_once' => '',
                'created' => false,
            ];
        }

        $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '._'), '=');
        try {
            /** @var MailOutboundRelaySecretService $secrets */
            $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
            $sealed = $secrets->seal($password);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__('密码密封失败：%{1}', [$e->getMessage()]),
                'email' => $email,
                'password_once' => '',
                'created' => false,
            ];
        }
        try {
            /** @var StalwartManagementAdapter $mgmt */
            $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
            $mgmt->ensureDomain($domain);
            $mgmt->provisionOrResetAccount($email, $password, 128);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__('Stalwart 开通失败：%{1}', [$e->getMessage()]),
                'email' => $email,
                'password_once' => '',
                'created' => false,
            ];
        }
        $now = date('Y-m-d H:i:s');
        $model->clear()
            ->setData(SmtpAccount::schema_fields_SERVICE_ID, $serviceId)
            ->setData(SmtpAccount::schema_fields_DOMAIN_NAME, $domain)
            ->setData(SmtpAccount::schema_fields_EMAIL, $email)
            ->setData(SmtpAccount::schema_fields_DISPLAY_NAME, $local)
            ->setData(SmtpAccount::schema_fields_ROLE, $role)
            ->setData(SmtpAccount::schema_fields_STATUS, 'active')
            ->setData(SmtpAccount::schema_fields_IS_SYSTEM, 1)
            ->setData(SmtpAccount::schema_fields_PASSWORD_SEALED, $sealed)
            ->setData(SmtpAccount::schema_fields_CREATED_AT, $now)
            ->setData(SmtpAccount::schema_fields_UPDATED_AT, $now)
            ->save();
        $id = (int)$model->getId();
        if ($id <= 0) {
            return [
                'success' => false,
                'message' => (string)__('系统账号保存失败'),
                'email' => $email,
                'password_once' => '',
                'created' => false,
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('系统账号已创建'),
            'email' => $email,
            'password_once' => $password,
            'created' => true,
        ];
    }

    /**
     * 本机服务系统账号是否齐全（目录行 + 密封密码 + active；有探测时还须已写入 Stalwart）。
     *
     * @param array<int, bool>|null $stalwartByAccountId probeStalwartPresenceForService.by_account_id
     * @return array{
     *   complete: bool,
     *   show_reset: bool,
     *   missing_locals: list<string>,
     *   gaps: list<string>,
     *   required_emails: list<string>
     * }
     */
    public function inspectForService(int $serviceId, ?array $stalwartByAccountId = null): array
    {
        $empty = [
            'complete' => true,
            'show_reset' => false,
            'missing_locals' => [],
            'gaps' => [],
            'required_emails' => [],
        ];
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        if (!$svc->getId()
            || (string)$svc->getData(SmtpService::schema_fields_MODE) !== SmtpService::MODE_SELF
        ) {
            return $empty;
        }
        $domain = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_DOMAIN_NAME)));
        if ($domain === '') {
            return [
                'complete' => false,
                'show_reset' => true,
                'missing_locals' => [],
                'gaps' => [(string)__('服务域名无效')],
                'required_emails' => [],
            ];
        }
        $relay = (int)$svc->getData(SmtpService::schema_fields_RELAY_SERVICE) === 1;
        $required = SmtpAccount::systemLocalParts($relay);
        $missing = [];
        $gaps = [];
        $requiredEmails = [];
        foreach ($required as $local => $role) {
            $email = $local . '@' . $domain;
            $requiredEmails[] = $email;
            /** @var SmtpAccount $model */
            $model = ObjectManager::getInstance(SmtpAccount::class);
            $row = $model->clear()->where(SmtpAccount::schema_fields_EMAIL, $email)->find()->fetch();
            if (!$row instanceof SmtpAccount || !(int)$row->getId()) {
                $missing[] = $local;
                $gaps[] = (string)__('缺少 %{1}', [$email]);

                continue;
            }
            $aid = (int)$row->getId();
            $sealed = trim((string)$row->getData(SmtpAccount::schema_fields_PASSWORD_SEALED));
            $status = strtolower(trim((string)$row->getData(SmtpAccount::schema_fields_STATUS)));
            if ($sealed === '') {
                $gaps[] = (string)__('%{1} 无密封密码', [$email]);
            }
            if ($status !== 'active') {
                $gaps[] = (string)__('%{1} 状态非 active（%{2}）', [$email, $status !== '' ? $status : '—']);
            }
            if (is_array($stalwartByAccountId) && array_key_exists($aid, $stalwartByAccountId)
                && empty($stalwartByAccountId[$aid])
            ) {
                $gaps[] = (string)__('%{1} 未写入本机 Stalwart', [$email]);
            }
        }
        $complete = $missing === [] && $gaps === [];

        return [
            'complete' => $complete,
            'show_reset' => !$complete,
            'missing_locals' => $missing,
            'gaps' => $gaps,
            'required_emails' => $requiredEmails,
        ];
    }

    /**
     * 补齐/修复不齐全的系统账号（缺则建；无密封则首次制密；有密封未入邮局则同步写入）。
     * 已齐全且已有密封密码的账号不改密。
     *
     * @return array{
     *   success: bool,
     *   message: string,
     *   created: list<array{email: string, password_once: string}>,
     *   ensured: list<string>,
     *   repaired: list<string>
     * }
     */
    public function repairForService(int $serviceId): array
    {
        $base = $this->ensureForService($serviceId);
        $repaired = [];
        if (empty($base['success'])) {
            return [
                'success' => false,
                'message' => (string)($base['message'] ?? __('修复失败')),
                'created' => [],
                'ensured' => [],
                'repaired' => [],
            ];
        }
        /** @var SmtpService $svc */
        $svc = ObjectManager::getInstance(SmtpService::class)->clear()->load($serviceId);
        $domain = strtolower(trim((string)$svc->getData(SmtpService::schema_fields_DOMAIN_NAME)));
        $relay = (int)$svc->getData(SmtpService::schema_fields_RELAY_SERVICE) === 1;
        $created = is_array($base['created'] ?? null) ? $base['created'] : [];
        $ensured = is_array($base['ensured'] ?? null) ? $base['ensured'] : [];
        $notes = [];
        foreach (SmtpAccount::systemLocalParts($relay) as $local => $role) {
            $email = $local . '@' . $domain;
            /** @var SmtpAccount $model */
            $model = ObjectManager::getInstance(SmtpAccount::class);
            $row = $model->clear()->where(SmtpAccount::schema_fields_EMAIL, $email)->find()->fetch();
            if (!$row instanceof SmtpAccount || !(int)$row->getId()) {
                continue;
            }
            $sealed = trim((string)$row->getData(SmtpAccount::schema_fields_PASSWORD_SEALED));
            $status = strtolower(trim((string)$row->getData(SmtpAccount::schema_fields_STATUS)));
            try {
                /** @var StalwartManagementAdapter $mgmt */
                $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
                /** @var MailOutboundRelaySecretService $secrets */
                $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                if ($sealed === '') {
                    $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '._'), '=');
                    $sealed = $secrets->seal($password);
                    $mgmt->ensureDomain($domain);
                    $mgmt->provisionOrResetAccount($email, $password, 128);
                    $row->setData(SmtpAccount::schema_fields_PASSWORD_SEALED, $sealed)
                        ->setData(SmtpAccount::schema_fields_STATUS, 'active')
                        ->setData(SmtpAccount::schema_fields_IS_SYSTEM, 1)
                        ->setData(SmtpAccount::schema_fields_ROLE, $role)
                        ->setData(SmtpAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                        ->save();
                    $created[] = ['email' => $email, 'password_once' => $password];
                    $repaired[] = $email;
                    continue;
                }
                $plain = $secrets->open($sealed);
                $needsSync = $status !== 'active' || !$mgmt->accountExists($email);
                if ($needsSync) {
                    $mgmt->ensureDomain($domain);
                    $mgmt->provisionOrResetAccount($email, $plain, 128);
                    if ($status !== 'active') {
                        $row->setData(SmtpAccount::schema_fields_STATUS, 'active')
                            ->setData(SmtpAccount::schema_fields_IS_SYSTEM, 1)
                            ->setData(SmtpAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                            ->save();
                    }
                    $repaired[] = $email;
                }
            } catch (\Throwable $e) {
                $notes[] = (string)__('%{1}：%{2}', [$email, $e->getMessage()]);
            }
        }
        $msg = (string)__('系统代理账户已修复');
        if ($ensured !== []) {
            $msg .= ' ' . (string)__('就绪：%{1}', [implode(', ', $ensured)]);
        }
        if ($repaired !== []) {
            $msg .= ' ' . (string)__('已补写：%{1}', [implode(', ', $repaired)]);
        }
        if ($created !== []) {
            $bits = [];
            foreach ($created as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $bits[] = (string)($row['email'] ?? '') . '=' . (string)($row['password_once'] ?? '');
            }
            if ($bits !== []) {
                $msg .= ' ' . (string)__('新建/补密封密码（仅一次）：%{1}', [implode('；', $bits)]);
            }
        }
        if ($notes !== []) {
            $msg .= ' ' . implode('；', $notes);
        }

        return [
            'success' => $notes === [],
            'message' => $msg,
            'created' => $created,
            'ensured' => $ensured,
            'repaired' => $repaired,
        ];
    }

    /**
     * @return string|null 拒绝原因；null 表示允许操作
     */
    public function denyMutationReason(SmtpAccount $account, string $operation): ?string
    {
        if (!$account->isSystemAccount()) {
            return null;
        }
        $email = strtolower(trim((string)$account->getData(SmtpAccount::schema_fields_EMAIL)));
        $label = $email !== '' ? $email : (string)$account->getId();

        return match ($operation) {
            'delete' => (string)__('系统账号 %{1} 不可删除（本机 Smtp 固定账号）', [$label]),
            'reset_password' => (string)__('系统账号 %{1} 不可修改密码；请用已密封密码查看/复制', [$label]),
            'disable' => (string)__('系统账号 %{1} 不可停用', [$label]),
            'change_role' => (string)__('系统账号 %{1} 不可改角色', [$label]),
            default => (string)__('系统账号 %{1} 不可执行该操作', [$label]),
        };
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   created: list<array{email: string, password_once: string}>,
     *   ensured: list<string>
     * }
     */
    private function emptyResult(bool $success, string $message): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'created' => [],
            'ensured' => [],
        ];
    }
}
