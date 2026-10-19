<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;
use Weline\Smtp\Model\SmtpAccount;
use Weline\Smtp\Model\SmtpService;

/**
 * 按库将 Mail/Smtp 账户录入本机 Stalwart（SSE 进度回调）。
 *
 * 直调 StalwartManagementAdapter；不经管理服务改密入口（系统账号会被拦）。
 */
final class MailEngineAccountSyncService
{
    /**
     * @param callable(string $step, string $message, int $current, int $total): void|null $onProgress
     * @param callable(string $email, string $passwordOnce): void|null $onPasswordOnce
     * @return array{
     *   success: bool,
     *   message: string,
     *   provisioned: int,
     *   skipped: int,
     *   regenerated: int,
     *   reader_synced: bool,
     *   code?: int
     * }
     */
    public function syncFromDatabase(
        ?callable $onProgress = null,
        ?callable $onPasswordOnce = null,
        bool $force = false,
        ?int $domainId = null
    ): array {
        /** @var StalwartManagementAdapter $mgmt */
        $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
        if (!$mgmt->hasManagementCredential()) {
            return [
                'success' => false,
                'message' => (string)__('请先配置引擎管理连接'),
                'provisioned' => 0,
                'skipped' => 0,
                'regenerated' => 0,
                'reader_synced' => false,
                'code' => 422,
            ];
        }

        $domainId = $domainId !== null && $domainId > 0 ? $domainId : null;
        $domainNameFilter = null;
        if ($domainId !== null) {
            /** @var MailDomain $domainModel */
            $domainModel = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
            if (!$domainModel->getId()) {
                return [
                    'success' => false,
                    'message' => (string)__('邮箱域名不存在'),
                    'provisioned' => 0,
                    'skipped' => 0,
                    'regenerated' => 0,
                    'reader_synced' => false,
                    'code' => 404,
                ];
            }
            $domainNameFilter = strtolower(trim((string)$domainModel->getData(MailDomain::schema_fields_DOMAIN_NAME)));
            if ($domainNameFilter === '') {
                return [
                    'success' => false,
                    'message' => (string)__('邮箱域名无效'),
                    'provisioned' => 0,
                    'skipped' => 0,
                    'regenerated' => 0,
                    'reader_synced' => false,
                    'code' => 422,
                ];
            }
        }

        /** @var MailOutboundRelaySecretService $secrets */
        $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
        /** @var StalwartEngineAdapter $engine */
        $engine = ObjectManager::getInstance(StalwartEngineAdapter::class);

        $mailRows = $this->loadActiveMailAccounts($domainId);
        $smtpRows = $this->loadSelfSmtpAccounts($domainNameFilter);
        $domainNames = $this->collectDomainNames($mailRows, $smtpRows, $domainNameFilter);
        $total = count($domainNames) + count($mailRows) + count($smtpRows) + 1;
        $current = 0;
        $provisioned = 0;
        $skipped = 0;
        $regenerated = 0;
        // Real (non-fake) engine scope touched this run — reader is only mandatory then.
        $realEngineScope = 0;

        $emit = static function (string $step, string $message, int $cur, int $tot) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($step, $message, $cur, $tot);
            }
        };

        foreach ($domainNames as $domainName) {
            $current++;
            if ($this->shouldSkipEngineDomain($domainName)) {
                $emit('domain', (string)__('跳过假域/测试域 %{1}', [$domainName]), $current, $total);
                $skipped++;
                continue;
            }
            $realEngineScope++;
            $emit('domain', (string)__('确保域名 %{1}', [$domainName]), $current, $total);
            try {
                $mgmt->ensureDomain($domainName);
            } catch (\Throwable $e) {
                return $this->fail(
                    (string)__('域名 %{1} 录入失败：%{2}', [$domainName, $e->getMessage()]),
                    $provisioned,
                    $skipped,
                    $regenerated,
                    false
                );
            }
        }

        foreach ($mailRows as $row) {
            $current++;
            $email = (string)$row['email'];
            if ($this->shouldSkipEngineMailbox($email)) {
                $emit('mail', (string)__('跳过假域邮局账号 %{1}', [$email]), $current, $total);
                $skipped++;
                continue;
            }
            $realEngineScope++;
            $emit('mail', (string)__('同步邮局账号 %{1}', [$email]), $current, $total);
            try {
                $result = $this->syncOneMailbox(
                    $mgmt,
                    $secrets,
                    $email,
                    (string)($row['password_sealed'] ?? ''),
                    max(128, (int)($row['quota_mb'] ?? 1024)),
                    $force,
                    static function (string $sealed) use ($row): void {
                        /** @var MailAccount $account */
                        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load((int)$row['account_id']);
                        if (!$account->getId()) {
                            return;
                        }
                        $account->setData(MailAccount::schema_fields_PASSWORD_SEALED, $sealed)
                            ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                            ->save();
                    },
                    $onPasswordOnce
                );
            } catch (\Throwable $e) {
                return $this->fail(
                    (string)__('邮局账号 %{1} 同步失败：%{2}', [$email, $e->getMessage()]),
                    $provisioned,
                    $skipped,
                    $regenerated,
                    false
                );
            }
            if ($result['skipped']) {
                $skipped++;
            } else {
                $provisioned++;
            }
            if ($result['regenerated']) {
                $regenerated++;
            }
        }

        foreach ($smtpRows as $row) {
            $current++;
            $email = (string)$row['email'];
            if ($this->shouldSkipEngineMailbox($email)) {
                $emit('smtp', (string)__('跳过假域 Smtp 账号 %{1}', [$email]), $current, $total);
                $skipped++;
                continue;
            }
            $realEngineScope++;
            $emit('smtp', (string)__('同步 Smtp 账号 %{1}', [$email]), $current, $total);
            try {
                $result = $this->syncOneMailbox(
                    $mgmt,
                    $secrets,
                    $email,
                    (string)($row['password_sealed'] ?? ''),
                    128,
                    $force,
                    static function (string $sealed) use ($row): void {
                        /** @var SmtpAccount $account */
                        $account = ObjectManager::getInstance(SmtpAccount::class)->clear()->load((int)$row['smtp_account_id']);
                        if (!$account->getId()) {
                            return;
                        }
                        $account->setData(SmtpAccount::schema_fields_PASSWORD_SEALED, $sealed)
                            ->setData(SmtpAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                            ->save();
                    },
                    $onPasswordOnce
                );
            } catch (\Throwable $e) {
                return $this->fail(
                    (string)__('Smtp 账号 %{1} 同步失败：%{2}', [$email, $e->getMessage()]),
                    $provisioned,
                    $skipped,
                    $regenerated,
                    false
                );
            }
            if ($result['skipped']) {
                $skipped++;
            } else {
                $provisioned++;
            }
            if ($result['regenerated']) {
                $regenerated++;
            }
        }

        $current++;
        $readerSynced = false;
        $emit('reader', (string)__('检查读取凭据'), $current, $total);
        if (!$engine->hasReaderCredential()) {
            try {
                $readerSynced = $this->syncReaderFromContact(
                    $mgmt,
                    $secrets,
                    $engine,
                    $onPasswordOnce,
                    $domainNameFilter
                );
            } catch (\Throwable $e) {
                if ($realEngineScope > 0) {
                    return $this->fail(
                        (string)__('读取凭据补齐失败：%{1}', [$e->getMessage()]),
                        $provisioned,
                        $skipped,
                        $regenerated,
                        false
                    );
                }
                $emit('reader', (string)__('本轮无真实引擎域，跳过读取凭据硬失败'), $current, $total);
            }
            if (!$readerSynced) {
                if ($realEngineScope > 0) {
                    return $this->fail(
                        (string)__('缺少读取凭据，且库中无可用的 contact@ 密封密码'),
                        $provisioned,
                        $skipped,
                        $regenerated,
                        false
                    );
                }
                // Fake/test-only inventory: management bind + skip is still a successful one-click pass.
                $emit('reader', (string)__('本轮仅假域/测试域，读取凭据可稍后由真实域补齐'), $current, $total);
            }
        } else {
            $readerSynced = true;
            // 已有凭据时仍按活端口重写 base_url（避免历史默认 18080 在 8080 上读邮失败）。
            try {
                $this->syncReaderFromContact(
                    $mgmt,
                    $secrets,
                    $engine,
                    null,
                    $domainNameFilter
                );
            } catch (\Throwable) {
                // 保留已有读取凭据；端口校正失败不阻断本轮同步成功。
            }
        }

        return [
            'success' => true,
            'message' => (string)__(
                '已同步：录入 %{1}，跳过 %{2}，重生密码 %{3}',
                [$provisioned, $skipped, $regenerated]
            ),
            'provisioned' => $provisioned,
            'skipped' => $skipped,
            'regenerated' => $regenerated,
            'reader_synced' => $readerSynced,
        ];
    }

    /**
     * @param callable(string $sealed): void $persistSealed
     * @param callable(string $email, string $passwordOnce): void|null $onPasswordOnce
     * @return array{skipped: bool, regenerated: bool}
     */
    private function syncOneMailbox(
        StalwartManagementAdapter $mgmt,
        MailOutboundRelaySecretService $secrets,
        string $email,
        string $sealed,
        int $quotaMb,
        bool $force,
        callable $persistSealed,
        ?callable $onPasswordOnce
    ): array {
        $email = strtolower(trim($email));
        $sealed = trim($sealed);
        $exists = false;
        try {
            $exists = $mgmt->accountExists($email);
        } catch (\Throwable) {
            $exists = false;
        }

        if ($exists && $sealed !== '' && !$force) {
            // 仍尝试用项目钥重封（旧钥打开成功时）
            try {
                $secrets->open($sealed);
                $resealed = $secrets->consumePendingReseal();
                if ($resealed !== null) {
                    $persistSealed($resealed);
                }
            } catch (\Throwable) {
                // 密封解不开时本波跳过不拦；强制时才会重生
            }

            return ['skipped' => true, 'regenerated' => false];
        }

        $password = '';
        $regenerated = false;
        if ($sealed !== '') {
            try {
                $password = $secrets->open($sealed);
                $resealed = $secrets->consumePendingReseal();
                if ($resealed !== null) {
                    $persistSealed($resealed);
                }
            } catch (\Throwable) {
                $password = '';
            }
        }
        if ($password === '' || strlen($password) < 12) {
            $password = $this->generatePassword();
            $regenerated = true;
            $persistSealed($secrets->seal($password));
            if ($onPasswordOnce !== null) {
                $onPasswordOnce($email, $password);
            }
        }

        $mgmt->provisionOrResetAccount($email, $password, $quotaMb);

        return ['skipped' => false, 'regenerated' => $regenerated];
    }

    /**
     * @param callable(string $email, string $passwordOnce): void|null $onPasswordOnce
     */
    private function syncReaderFromContact(
        StalwartManagementAdapter $mgmt,
        MailOutboundRelaySecretService $secrets,
        StalwartEngineAdapter $engine,
        ?callable $onPasswordOnce,
        ?string $domainNameFilter = null
    ): bool {
        /** @var MailAccount $model */
        $model = ObjectManager::getInstance(MailAccount::class);
        $query = $model->clear()
            ->where(MailAccount::schema_fields_STATUS, 'active')
            ->where(MailAccount::schema_fields_EMAIL, 'contact@%', 'LIKE');
        if ($domainNameFilter !== null && $domainNameFilter !== '') {
            $query->where(MailAccount::schema_fields_EMAIL, 'contact@' . $domainNameFilter);
        }
        $rows = $query->select()->fetchArray();
        if (!is_array($rows) || $rows === []) {
            return false;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string)($row[MailAccount::schema_fields_EMAIL] ?? $row['email'] ?? '')));
            if ($email === '' || !str_starts_with($email, 'contact@')) {
                continue;
            }
            if ($domainNameFilter !== null && $domainNameFilter !== ''
                && !str_ends_with($email, '@' . $domainNameFilter)
            ) {
                continue;
            }
            $accountId = (int)($row[MailAccount::schema_fields_ID] ?? $row['account_id'] ?? 0);
            $sealed = trim((string)($row[MailAccount::schema_fields_PASSWORD_SEALED] ?? $row['password_sealed'] ?? ''));
            $quotaMb = max(128, (int)($row[MailAccount::schema_fields_QUOTA_MB] ?? $row['quota_mb'] ?? 1024));
            $password = '';
            $regenerated = false;
            if ($sealed !== '') {
                try {
                    $password = $secrets->open($sealed);
                    $resealed = $secrets->consumePendingReseal();
                    if ($resealed !== null && $accountId > 0) {
                        /** @var MailAccount $account */
                        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
                        if ($account->getId()) {
                            $account->setData(MailAccount::schema_fields_PASSWORD_SEALED, $resealed)
                                ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                                ->save();
                        }
                    }
                } catch (\Throwable) {
                    $password = '';
                }
            }
            if ($password === '' || strlen($password) < 16) {
                $password = $this->generatePassword();
                $regenerated = true;
                if ($accountId > 0) {
                    /** @var MailAccount $account */
                    $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
                    if ($account->getId()) {
                        $account->setData(MailAccount::schema_fields_PASSWORD_SEALED, $secrets->seal($password))
                            ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                            ->save();
                    }
                }
                if ($onPasswordOnce !== null) {
                    $onPasswordOnce($email, $password);
                }
            }
            try {
                $domain = substr($email, strpos($email, '@') + 1);
                if ($this->shouldSkipEngineDomain($domain)) {
                    continue;
                }
                $mgmt->ensureDomain($domain);
                $mgmt->provisionOrResetAccount($email, $password, $quotaMb);
                $engine->upsertReaderCredential($email, $password);
            } catch (\Throwable) {
                if ($regenerated) {
                    continue;
                }

                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Fake / 测试域不录入本机 Stalwart（.invalid / .test，或 engine=fake）。
     */
    public function shouldSkipEngineDomain(string $domainName): bool
    {
        $domainName = strtolower(trim($domainName));
        if ($domainName === '') {
            return true;
        }
        if (str_ends_with($domainName, '.invalid') || str_ends_with($domainName, '.test')) {
            return true;
        }
        try {
            /** @var MailDomain $model */
            $model = ObjectManager::getInstance(MailDomain::class);
            $row = $model->clear()
                ->where(MailDomain::schema_fields_DOMAIN_NAME, $domainName)
                ->find()
                ->fetch();
            if ($row instanceof MailDomain && $row->getId()) {
                return ObjectManager::getInstance(MailCustomerAccountService::class)->isFakeDomain($row);
            }
        } catch (\Throwable) {
            // 查库失败时仍按后缀判断（上文已覆盖 .invalid/.test）
        }

        return false;
    }

    public function shouldSkipEngineMailbox(string $email): bool
    {
        $email = strtolower(trim($email));
        $at = strpos($email, '@');
        if ($at === false) {
            return true;
        }

        return $this->shouldSkipEngineDomain(substr($email, $at + 1));
    }

    /**
     * 探针：返回库中 active 邮局账户数（与 sync loader 同一查询链）。
     */
    public function countActiveMailAccounts(): int
    {
        return count($this->loadActiveMailAccounts());
    }

    /**
     * @return list<array{account_id:int,email:string,password_sealed:string,quota_mb:int}>
     */
    private function loadActiveMailAccounts(?int $domainId = null): array
    {
        /** @var MailAccount $model */
        $model = ObjectManager::getInstance(MailAccount::class);
        $query = $model->clear()
            ->where(MailAccount::schema_fields_STATUS, 'active');
        if ($domainId !== null && $domainId > 0) {
            $query->where(MailAccount::schema_fields_DOMAIN_ID, $domainId);
        }
        $rows = $query
            ->order(MailAccount::schema_fields_EMAIL, 'ASC')
            ->select()
            ->fetchArray();
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string)($row[MailAccount::schema_fields_EMAIL] ?? $row['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $out[] = [
                'account_id' => (int)($row[MailAccount::schema_fields_ID] ?? $row['account_id'] ?? 0),
                'email' => $email,
                'password_sealed' => trim((string)($row[MailAccount::schema_fields_PASSWORD_SEALED] ?? $row['password_sealed'] ?? '')),
                'quota_mb' => max(128, (int)($row[MailAccount::schema_fields_QUOTA_MB] ?? $row['quota_mb'] ?? 1024)),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{smtp_account_id:int,email:string,password_sealed:string}>
     */
    private function loadSelfSmtpAccounts(?string $domainNameFilter = null): array
    {
        /** @var SmtpService $svcModel */
        $svcModel = ObjectManager::getInstance(SmtpService::class);
        $services = $svcModel->clear()
            ->where(SmtpService::schema_fields_MODE, SmtpService::MODE_SELF)
            ->where(SmtpService::schema_fields_STATUS, 'active')
            ->select()
            ->fetchArray();
        $serviceIds = [];
        if (is_array($services)) {
            foreach ($services as $svc) {
                if (!is_array($svc)) {
                    continue;
                }
                $id = (int)($svc[SmtpService::schema_fields_ID] ?? $svc['smtp_service_id'] ?? 0);
                if ($id > 0) {
                    $serviceIds[$id] = $id;
                }
            }
        }
        if ($serviceIds === []) {
            return [];
        }

        /** @var SmtpAccount $acctModel */
        $acctModel = ObjectManager::getInstance(SmtpAccount::class);
        $rows = $acctModel->clear()
            ->where(SmtpAccount::schema_fields_STATUS, 'active')
            ->order(SmtpAccount::schema_fields_EMAIL, 'ASC')
            ->select()
            ->fetchArray();
        $out = [];
        if (!is_array($rows)) {
            return $out;
        }
        $suffix = ($domainNameFilter !== null && $domainNameFilter !== '')
            ? '@' . strtolower($domainNameFilter)
            : null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $serviceId = (int)($row[SmtpAccount::schema_fields_SERVICE_ID] ?? $row['smtp_service_id'] ?? 0);
            if ($serviceId <= 0 || !isset($serviceIds[$serviceId])) {
                continue;
            }
            $email = strtolower(trim((string)($row[SmtpAccount::schema_fields_EMAIL] ?? $row['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if ($suffix !== null && !str_ends_with($email, $suffix)) {
                continue;
            }
            $out[] = [
                'smtp_account_id' => (int)($row[SmtpAccount::schema_fields_ID] ?? $row['smtp_account_id'] ?? 0),
                'email' => $email,
                'password_sealed' => trim((string)($row[SmtpAccount::schema_fields_PASSWORD_SEALED] ?? $row['password_sealed'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{email:string}> $mailRows
     * @param list<array{email:string}> $smtpRows
     * @return list<string>
     */
    private function collectDomainNames(
        array $mailRows,
        array $smtpRows,
        ?string $domainNameFilter = null
    ): array {
        if ($domainNameFilter !== null && $domainNameFilter !== '') {
            return [strtolower($domainNameFilter)];
        }
        $domains = [];
        /** @var MailDomain $domainModel */
        $domainModel = ObjectManager::getInstance(MailDomain::class);
        $domainRows = $domainModel->clear()
            ->where(MailDomain::schema_fields_STATUS, 'active')
            ->select()
            ->fetchArray();
        if (is_array($domainRows)) {
            foreach ($domainRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = strtolower(trim((string)($row[MailDomain::schema_fields_DOMAIN_NAME] ?? $row['domain_name'] ?? '')));
                if ($name !== '') {
                    $domains[$name] = $name;
                }
            }
        }
        foreach (array_merge($mailRows, $smtpRows) as $row) {
            $email = (string)($row['email'] ?? '');
            $at = strpos($email, '@');
            if ($at === false) {
                continue;
            }
            $name = strtolower(trim(substr($email, $at + 1)));
            if ($name !== '') {
                $domains[$name] = $name;
            }
        }
        $list = array_values($domains);
        sort($list);

        return $list;
    }

    private function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '._'), '=');
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   provisioned: int,
     *   skipped: int,
     *   regenerated: int,
     *   reader_synced: bool,
     *   code: int
     * }
     */
    private function fail(
        string $message,
        int $provisioned,
        int $skipped,
        int $regenerated,
        bool $readerSynced
    ): array {
        return [
            'success' => false,
            'message' => $message,
            'provisioned' => $provisioned,
            'skipped' => $skipped,
            'regenerated' => $regenerated,
            'reader_synced' => $readerSynced,
            'code' => 500,
        ];
    }
}
