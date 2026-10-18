<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;

final class MailAccountManagementService
{
    public const DEFAULT_PAGE_SIZE = 20;

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

            $isSystem = MailAccount::isReservedSystemLocalPart($localPart)
                || MailAccount::isReservedSystemLocalPart(MailAccount::emailLocalPart($email));
            $now = date('Y-m-d H:i:s');
            $model->clear()
                ->setData(MailAccount::schema_fields_DOMAIN_ID, $domainId)
                ->setData(MailAccount::schema_fields_CUSTOMER_ID, max(0, $customerId))
                ->setData(MailAccount::schema_fields_EMAIL, $email)
                ->setData(MailAccount::schema_fields_DISPLAY_NAME, trim($displayName))
                ->setData(MailAccount::schema_fields_QUOTA_MB, max(128, $quotaMb))
                ->setData(MailAccount::schema_fields_STATUS, 'active')
                ->setData(MailAccount::schema_fields_IS_SYSTEM, $isSystem ? 1 : 0)
                ->setData(MailAccount::schema_fields_CREATED_AT, $now)
                ->setData(MailAccount::schema_fields_UPDATED_AT, $now);
            $password = trim($password);
            if ($password !== '') {
                /** @var MailOutboundRelaySecretService $secrets */
                $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
                $model->setData(MailAccount::schema_fields_PASSWORD_SEALED, $secrets->seal($password));
            }
            $model->save();

            return ['success' => true, 'message' => __('邮箱账号已开通'), 'account_id' => (int)$model->getId()];
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return $this->failure($exception->getMessage());
        } catch (\Throwable) {
            return $this->failure(__('邮箱账号开通失败，请检查 Stalwart 管理连接'), 500);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   success:bool,
     *   items:list<array<string,mixed>>,
     *   total:int,
     *   page:int,
     *   page_size:int,
     *   has_more:bool
     * }
     */
    public function listAccounts(array $params = []): array
    {
        $q = trim((string)($params['q'] ?? $params['search'] ?? ''));
        $status = strtolower(trim((string)($params['status'] ?? '')));
        $domainId = max(0, (int)($params['domain_id'] ?? 0));
        $page = max(1, (int)($params['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($params['page_size'] ?? self::DEFAULT_PAGE_SIZE)));

        /** @var MailAccount $model */
        $model = ObjectManager::getInstance(MailAccount::class);
        $query = $model->clear();
        if ($domainId > 0) {
            $query->where(MailAccount::schema_fields_DOMAIN_ID, $domainId);
        }
        if ($status !== '' && in_array($status, ['active', 'pending', 'suspended'], true)) {
            $query->where(MailAccount::schema_fields_STATUS, $status);
        }
        if ($q !== '') {
            // 仅按邮箱模糊检索，避免 OR 与 domain/status 的 AND 优先级踩坑
            $query->where(MailAccount::schema_fields_EMAIL, '%' . $q . '%', 'LIKE');
        }

        $countQuery = clone $query;
        $total = (int)$countQuery->count();
        $rows = $query
            ->order(MailAccount::schema_fields_DOMAIN_ID, 'ASC')
            ->order(MailAccount::schema_fields_EMAIL, 'ASC')
            ->limit($pageSize, ($page - 1) * $pageSize)
            ->select()
            ->fetch()
            ->getItems();

        $items = [];
        foreach ($rows as $row) {
            if (!$row instanceof MailAccount) {
                continue;
            }
            $items[] = $this->serializeAccount($row);
        }

        return [
            'success' => true,
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => ($page * $pageSize) < $total,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function updateAccount(array $params): array
    {
        $accountId = max(0, (int)($params['account_id'] ?? 0));
        if ($accountId <= 0) {
            return $this->failure(__('邮箱账号参数无效'));
        }

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId()) {
            return $this->failure(__('邮箱账号不存在'), 404);
        }
        $blocked = $this->assertSystemMutable($account, 'update');
        if ($blocked !== null) {
            return $blocked;
        }

        if (array_key_exists('display_name', $params)) {
            $account->setData(MailAccount::schema_fields_DISPLAY_NAME, trim((string)$params['display_name']));
        }
        if (array_key_exists('customer_id', $params)) {
            $account->setData(MailAccount::schema_fields_CUSTOMER_ID, max(0, (int)$params['customer_id']));
        }
        if (array_key_exists('quota_mb', $params)) {
            $account->setData(MailAccount::schema_fields_QUOTA_MB, max(128, (int)$params['quota_mb']));
        }

        $status = array_key_exists('status', $params)
            ? strtolower(trim((string)$params['status']))
            : '';
        if ($status !== '') {
            if (!in_array($status, ['active', 'pending', 'suspended'], true)) {
                return $this->failure(__('邮箱账号状态参数无效'));
            }
            if ($status === 'active') {
                $gate = $this->assertCanActivate($account);
                if ($gate !== null) {
                    return $gate;
                }
            }
            $account->setData(MailAccount::schema_fields_STATUS, $status);
        }

        $account->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))->save();

        return [
            'success' => true,
            'message' => __('邮箱账号已更新'),
            'account' => $this->serializeAccount($account),
        ];
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
        $blocked = $this->assertSystemMutable($account, 'status');
        if ($blocked !== null) {
            return $blocked;
        }

        if ($status === 'active') {
            $gate = $this->assertCanActivate($account);
            if ($gate !== null) {
                return $gate;
            }
        }

        $account->setData(MailAccount::schema_fields_STATUS, $status)
            ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return ['success' => true, 'message' => __('邮箱账号状态已更新')];
    }

    /**
     * @return array<string, mixed>|null failure payload when blocked
     */
    private function assertCanActivate(MailAccount $account): ?array
    {
        $domain = ObjectManager::getInstance(MailDomain::class)
            ->clear()
            ->load((int)$account->getData(MailAccount::schema_fields_DOMAIN_ID));
        $isFake = $domain->getId()
            && ObjectManager::getInstance(MailCustomerAccountService::class)->isFakeDomain($domain);
        if ($isFake) {
            return null;
        }
        try {
            if (!ObjectManager::getInstance(StalwartManagementAdapter::class)->accountExists(
                (string)$account->getData(MailAccount::schema_fields_EMAIL)
            )) {
                return $this->failure(__('请先通过“开通/重置密码”在 Stalwart 中开通该账号'));
            }
        } catch (\Throwable) {
            return $this->failure(__('无法确认 Stalwart 账号状态，请检查管理连接'));
        }

        return null;
    }

    public function deleteAccount(int $accountId): array
    {
        if ($accountId <= 0) {
            return $this->failure(__('邮箱账号参数无效'));
        }

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId()) {
            return $this->failure(__('邮箱账号不存在'), 404);
        }
        $blocked = $this->assertSystemMutable($account, 'delete');
        if ($blocked !== null) {
            return $blocked;
        }

        $email = strtolower(trim((string)$account->getData(MailAccount::schema_fields_EMAIL)));
        $domainId = (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID);
        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        $isFake = $domain->getId()
            && ObjectManager::getInstance(MailCustomerAccountService::class)->isFakeDomain($domain);

        try {
            if (!$isFake && $email !== '') {
                ObjectManager::getInstance(StalwartManagementAdapter::class)->deleteAccount($email);
            }
            $account->delete();
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return $this->failure($exception->getMessage());
        } catch (\Throwable) {
            return $this->failure(__('邮箱账号删除失败，请检查 Stalwart 管理连接'), 500);
        }

        return ['success' => true, 'message' => __('邮箱账号已删除')];
    }

    /**
     * @param list<int|string>|mixed $ids
     * @return array<string, mixed>
     */
    public function batchSetStatus(mixed $ids, string $status): array
    {
        $accountIds = $this->normalizeIds($ids);
        if ($accountIds === []) {
            return $this->failure(__('请选择至少一个邮箱账号'));
        }
        $ok = 0;
        $fail = 0;
        $messages = [];
        foreach ($accountIds as $accountId) {
            $result = $this->setStatus($accountId, $status);
            if (!empty($result['success'])) {
                $ok++;
            } else {
                $fail++;
                $messages[] = '#' . $accountId . ': ' . (string)($result['message'] ?? __('失败'));
            }
        }

        return [
            'success' => $fail === 0,
            'message' => $fail === 0
                ? (string)__('已更新 %{1} 个账号状态', [$ok])
                : (string)__('成功 %{1}，失败 %{2}', [$ok, $fail]),
            'ok' => $ok,
            'fail' => $fail,
            'errors' => $messages,
            'code' => $fail === 0 ? 200 : 422,
        ];
    }

    /**
     * @param list<int|string>|mixed $ids
     * @return array<string, mixed>
     */
    public function batchDelete(mixed $ids): array
    {
        $accountIds = $this->normalizeIds($ids);
        if ($accountIds === []) {
            return $this->failure(__('请选择至少一个邮箱账号'));
        }
        $ok = 0;
        $fail = 0;
        $messages = [];
        foreach ($accountIds as $accountId) {
            $result = $this->deleteAccount($accountId);
            if (!empty($result['success'])) {
                $ok++;
            } else {
                $fail++;
                $messages[] = '#' . $accountId . ': ' . (string)($result['message'] ?? __('失败'));
            }
        }

        return [
            'success' => $fail === 0,
            'message' => $fail === 0
                ? (string)__('已删除 %{1} 个账号', [$ok])
                : (string)__('成功 %{1}，失败 %{2}', [$ok, $fail]),
            'ok' => $ok,
            'fail' => $fail,
            'errors' => $messages,
            'code' => $fail === 0 ? 200 : 422,
        ];
    }

    public function provisionOrResetPassword(int $accountId, string $password): array
    {
        try {
            /** @var MailAccount $account */
            $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
            if (!$account->getId()) {
                return $this->failure(__('邮箱账号不存在'), 404);
            }
            $blocked = $this->assertSystemMutable($account, 'reset_password');
            if ($blocked !== null) {
                return $blocked;
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
            /** @var MailOutboundRelaySecretService $secrets */
            $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
            $account->setData(MailAccount::schema_fields_STATUS, 'active')
                ->setData(MailAccount::schema_fields_PASSWORD_SEALED, $secrets->seal(trim($password)))
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

    /**
     * @return array<string, mixed>
     */
    public function serializeAccount(MailAccount $account): array
    {
        $this->healSystemFlag($account);
        $isSystem = $account->isSystemAccount();

        return [
            'account_id' => (int)$account->getId(),
            'domain_id' => (int)$account->getData(MailAccount::schema_fields_DOMAIN_ID),
            'customer_id' => (int)$account->getData(MailAccount::schema_fields_CUSTOMER_ID),
            'email' => (string)$account->getData(MailAccount::schema_fields_EMAIL),
            'display_name' => (string)$account->getData(MailAccount::schema_fields_DISPLAY_NAME),
            'quota_mb' => (int)$account->getData(MailAccount::schema_fields_QUOTA_MB),
            'status' => (string)$account->getData(MailAccount::schema_fields_STATUS),
            'is_system' => $isSystem ? 1 : 0,
            'system_role_label' => $isSystem ? $account->systemRoleLabel() : '',
            'mutable' => !$isSystem,
            'outbound_relay_enabled' => (int)$account->getData(MailAccount::schema_fields_OUTBOUND_RELAY_ENABLED) === 1 ? 1 : 0,
            'outbound_relay_host' => trim((string)$account->getData(MailAccount::schema_fields_OUTBOUND_RELAY_HOST)),
            'outbound_relay_port' => (int)$account->getData(MailAccount::schema_fields_OUTBOUND_RELAY_PORT),
            'outbound_relay_username' => trim((string)$account->getData(MailAccount::schema_fields_OUTBOUND_RELAY_USERNAME)),
            'outbound_relay_tls' => trim((string)$account->getData(MailAccount::schema_fields_OUTBOUND_RELAY_TLS)) ?: 'starttls',
        ];
    }

    /**
     * @return array<string, mixed>|null failure when system account blocks the action
     */
    private function assertSystemMutable(MailAccount $account, string $action): ?array
    {
        if (!$account->isSystemAccount()) {
            return null;
        }
        $this->healSystemFlag($account);
        $email = (string)$account->getData(MailAccount::schema_fields_EMAIL);
        $label = $email !== '' ? $email : ('#' . (int)$account->getId());

        return match ($action) {
            'delete' => $this->failure(__('系统账号 %{1} 不可删除（邮局固定账号）', [$label])),
            'update' => $this->failure(__('系统账号 %{1} 不可修改身份资料', [$label])),
            'status' => $this->failure(__('系统账号 %{1} 不可暂停或改状态', [$label])),
            'reset_password' => $this->failure(__('系统账号 %{1} 不可在此改密；请用 Smtp/启用流程管理凭据', [$label])),
            default => $this->failure(__('系统账号 %{1} 不可执行该操作', [$label])),
        };
    }

    /** 历史行：保留 local 已是系统账号但未落 is_system=1 时补钉。 */
    private function healSystemFlag(MailAccount $account): void
    {
        if ((int)$account->getData(MailAccount::schema_fields_IS_SYSTEM) === 1) {
            return;
        }
        if (!MailAccount::isReservedSystemLocalPart(
            MailAccount::emailLocalPart((string)$account->getData(MailAccount::schema_fields_EMAIL))
        )) {
            return;
        }
        try {
            $account->setData(MailAccount::schema_fields_IS_SYSTEM, 1)
                ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                ->save();
        } catch (\Throwable) {
            // 列表展示仍按 isSystemAccount() 识别；落库失败不阻断读路径
        }
    }

    /**
     * @param list<int|string>|mixed $ids
     * @return list<int>
     */
    private function normalizeIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            $n = (int)$id;
            if ($n > 0) {
                $out[$n] = $n;
            }
        }

        return array_values($out);
    }

    private function failure(mixed $message, int $code = 422): array
    {
        return ['success' => false, 'message' => $message, 'code' => $code];
    }
}
