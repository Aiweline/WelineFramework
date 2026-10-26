<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;
use Weline\Mail\Model\MailDomain;

/**
 * 幂等确保邮局邮箱账号（供 Query / Smtp 编排经 w_query 调用；不跨模块暴露 Model）。
 */
final class MailAccountEnsureService
{
    public const LOCAL_CONTACT = 'contact';

    /** 默认 Smtp 提交账号 local（启域自动开；后台可改绑到其它 active 邮箱） */
    public const LOCAL_SMTP_DEFAULT = 'smtp';

    public function __construct(
        private readonly ?MailAccountManagementService $accounts = null,
        private readonly ?MailCustomerAccountService $customerAccounts = null,
    ) {
    }

    private function accounts(): MailAccountManagementService
    {
        return $this->accounts ?? ObjectManager::getInstance(MailAccountManagementService::class);
    }

    private function customerAccounts(): MailCustomerAccountService
    {
        return $this->customerAccounts ?? ObjectManager::getInstance(MailCustomerAccountService::class);
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   domain_id?: int,
     *   domain_name?: string,
     *   engine?: string,
     *   is_fake?: bool,
     *   code?: int
     * }
     */
    public function resolveActiveDomain(int $domainId = 0, string $host = ''): array
    {
        if ($domainId > 0) {
            /** @var MailDomain $domain */
            $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
            if (!$domain->getId()) {
                return $this->fail(__('邮箱域名不存在'), 404);
            }
            if ((string)$domain->getData(MailDomain::schema_fields_STATUS) !== 'active') {
                return $this->fail(__('请先启用邮箱域名'), 422);
            }

            return $this->domainPayload($domain);
        }

        $host = strtolower(trim($host));
        if ($host !== '' && str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $active = $this->customerAccounts()->getActiveDomains();
        if ($active === []) {
            return $this->fail(__('未找到已启用的邮局域名。请先在企业邮箱中开通并启用域名。'), 404);
        }

        $real = [];
        $fake = [];
        foreach ($active as $domain) {
            if (!$domain instanceof MailDomain || !$domain->getId()) {
                continue;
            }
            if ($this->customerAccounts()->isFakeDomain($domain)) {
                $fake[] = $domain;
            } else {
                $real[] = $domain;
            }
        }

        if ($host !== '') {
            $matched = null;
            $matchedLength = -1;
            foreach ($real as $domain) {
                $name = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME)));
                if ($name === '') {
                    continue;
                }
                if ($host !== $name && !str_ends_with($host, '.' . $name)) {
                    continue;
                }
                if (strlen($name) > $matchedLength) {
                    $matched = $domain;
                    $matchedLength = strlen($name);
                }
            }
            if ($matched !== null) {
                return $this->domainPayload($matched);
            }
        }

        if (count($real) === 1) {
            return $this->domainPayload($real[0]);
        }
        if (count($real) > 1) {
            return $this->fail(
                __('存在多个已启用的真实邮局域名，请指定 domain_id 后再一键配置。'),
                409
            );
        }
        if ($fake === []) {
            return $this->fail(__('未找到可用的邮局域名。'), 404);
        }

        return $this->domainPayload($fake[0]);
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   account_id: int,
     *   email: string,
     *   domain_id: int,
     *   domain_name: string,
     *   created: bool,
     *   is_fake: bool,
     *   smtp_password_once: string,
     *   code?: int
     * }
     */
    public function ensureContactAccount(int $domainId = 0): array
    {
        return $this->ensureMailbox($domainId, self::LOCAL_CONTACT, (string)__('站点联系'));
    }

    /**
     * 幂等确保默认 Smtp 提交账号，并写入域名 default_smtp_account_id（若尚未指向本域 active 账号）。
     *
     * @return array{
     *   success: bool,
     *   message: string,
     *   account_id: int,
     *   email: string,
     *   domain_id: int,
     *   domain_name: string,
     *   created: bool,
     *   is_fake: bool,
     *   smtp_password_once: string,
     *   default_smtp_account_id: int,
     *   code?: int
     * }
     */
    public function ensureDefaultSmtpAccount(int $domainId = 0): array
    {
        $result = $this->ensureMailbox(
            $domainId,
            self::LOCAL_SMTP_DEFAULT,
            (string)__('SMTP 提交')
        );
        if (empty($result['success'])) {
            $result['default_smtp_account_id'] = 0;

            return $result;
        }

        $accountId = (int)($result['account_id'] ?? 0);
        $domainId = (int)($result['domain_id'] ?? 0);
        $pointer = $this->persistDefaultSmtpAccountId($domainId, $accountId, false);
        $result['default_smtp_account_id'] = (int)($pointer['default_smtp_account_id'] ?? $accountId);
        if (!empty($pointer['message'])) {
            $result['message'] = trim((string)$result['message'] . ' ' . (string)$pointer['message']);
        }

        return $result;
    }

    /**
     * 后台改绑：将域名默认 Smtp 账号设为指定本域 active 账号。
     *
     * @return array{success: bool, message: string, domain_id: int, default_smtp_account_id: int, email: string, code?: int}
     */
    public function setDefaultSmtpAccount(int $domainId, int $accountId): array
    {
        $resolved = $this->resolveActiveDomain($domainId);
        if (empty($resolved['success'])) {
            return [
                'success' => false,
                'message' => (string)($resolved['message'] ?? __('域名无效')),
                'domain_id' => 0,
                'default_smtp_account_id' => 0,
                'email' => '',
                'code' => (int)($resolved['code'] ?? 422),
            ];
        }
        $domainId = (int)$resolved['domain_id'];
        if ($accountId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('请选择默认 Smtp 账号'),
                'domain_id' => $domainId,
                'default_smtp_account_id' => 0,
                'email' => '',
                'code' => 422,
            ];
        }

        /** @var MailAccount $account */
        $account = ObjectManager::getInstance(MailAccount::class)->clear()->load($accountId);
        if (!$account->getId()) {
            return [
                'success' => false,
                'message' => (string)__('邮箱账号不存在'),
                'domain_id' => $domainId,
                'default_smtp_account_id' => 0,
                'email' => '',
                'code' => 404,
            ];
        }
        if ((int)$account->getData(MailAccount::schema_fields_DOMAIN_ID) !== $domainId) {
            return [
                'success' => false,
                'message' => (string)__('只能选择本邮局域名下的账号'),
                'domain_id' => $domainId,
                'default_smtp_account_id' => 0,
                'email' => '',
                'code' => 422,
            ];
        }
        if ((string)$account->getData(MailAccount::schema_fields_STATUS) !== 'active') {
            return [
                'success' => false,
                'message' => (string)__('请先启用该邮箱账号'),
                'domain_id' => $domainId,
                'default_smtp_account_id' => 0,
                'email' => '',
                'code' => 422,
            ];
        }

        $pointer = $this->persistDefaultSmtpAccountId($domainId, $accountId, true);
        $email = (string)$account->getData(MailAccount::schema_fields_EMAIL);

        return [
            'success' => !empty($pointer['success']),
            'message' => (string)($pointer['message'] ?: __('已将默认 Smtp 账号设为 %{1}', [$email])),
            'domain_id' => $domainId,
            'default_smtp_account_id' => $accountId,
            'email' => $email,
            'code' => (int)($pointer['code'] ?? 200),
        ];
    }

    /**
     * @return array{success: bool, message: string, default_smtp_account_id: int, code?: int}
     */
    private function persistDefaultSmtpAccountId(int $domainId, int $accountId, bool $force): array
    {
        if ($domainId <= 0 || $accountId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('无法保存默认 Smtp 账号'),
                'default_smtp_account_id' => 0,
                'code' => 422,
            ];
        }

        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
            return [
                'success' => false,
                'message' => (string)__('邮箱域名不存在'),
                'default_smtp_account_id' => 0,
                'code' => 404,
            ];
        }

        $current = (int)$domain->getData(MailDomain::schema_fields_DEFAULT_SMTP_ACCOUNT_ID);
        if (!$force && $current > 0 && $current !== $accountId) {
            /** @var MailAccount $existing */
            $existing = ObjectManager::getInstance(MailAccount::class)->clear()->load($current);
            if (
                $existing->getId()
                && (int)$existing->getData(MailAccount::schema_fields_DOMAIN_ID) === $domainId
                && (string)$existing->getData(MailAccount::schema_fields_STATUS) === 'active'
            ) {
                return [
                    'success' => true,
                    'message' => '',
                    'default_smtp_account_id' => $current,
                ];
            }
        }

        $domain->setData(MailDomain::schema_fields_DEFAULT_SMTP_ACCOUNT_ID, $accountId)
            ->setData(MailDomain::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return [
            'success' => true,
            'message' => $force ? '' : (string)__('已登记默认 Smtp 提交账号。'),
            'default_smtp_account_id' => $accountId,
        ];
    }

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   account_id: int,
     *   email: string,
     *   domain_id: int,
     *   domain_name: string,
     *   created: bool,
     *   is_fake: bool,
     *   smtp_password_once: string,
     *   code?: int
     * }
     */
    public function ensureMailbox(
        int $domainId,
        string $localPart,
        string $displayName = '',
        int $quotaMb = 0,
    ): array {
        $empty = [
            'success' => false,
            'message' => '',
            'account_id' => 0,
            'email' => '',
            'domain_id' => 0,
            'domain_name' => '',
            'created' => false,
            'is_fake' => false,
            'smtp_password_once' => '',
        ];

        $localPart = strtolower(trim($localPart));
        if ($localPart === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $localPart)) {
            $empty['message'] = (string)__('邮箱 local_part 无效');
            $empty['code'] = 422;

            return $empty;
        }

        $resolved = $this->resolveActiveDomain($domainId);
        if (empty($resolved['success'])) {
            $empty['message'] = (string)($resolved['message'] ?? __('域名解析失败'));
            $empty['code'] = (int)($resolved['code'] ?? 422);

            return $empty;
        }

        $domainId = (int)$resolved['domain_id'];
        $domainName = (string)$resolved['domain_name'];
        $isFake = !empty($resolved['is_fake']);
        $email = $localPart . '@' . $domainName;
        if ($displayName === '') {
            $displayName = $localPart === self::LOCAL_CONTACT
                ? (string)__('站点联系')
                : $email;
        }
        if ($quotaMb <= 0) {
            $quotaMb = max(128, (int)($resolved['default_quota_mb'] ?? 1024));
        }

        /** @var MailAccount $model */
        $model = ObjectManager::getInstance(MailAccount::class);
        $existing = $model->clear()->where(MailAccount::schema_fields_EMAIL, $email)->find()->fetch();
        if ($existing->getId()) {
            $status = (string)$existing->getData(MailAccount::schema_fields_STATUS);
            if (
                MailAccount::isSocialMailboxLocalPart($localPart)
                || MailAccount::isFeedMailboxLocalPart($localPart)
            ) {
                $dirty = false;
                if ((int)$existing->getData(MailAccount::schema_fields_IS_SYSTEM) !== 1) {
                    $existing->setData(MailAccount::schema_fields_IS_SYSTEM, 1);
                    $dirty = true;
                }
                if ($status !== 'active') {
                    $existing->setData(MailAccount::schema_fields_STATUS, 'active');
                    $dirty = true;
                }
                if ($dirty) {
                    $existing->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))->save();
                }
            } elseif ($status !== 'active') {
                $activated = $this->accounts()->setStatus((int)$existing->getId(), 'active');
                if (empty($activated['success'])) {
                    $empty['message'] = (string)($activated['message'] ?? __('无法启用已有邮箱账号'));
                    $empty['code'] = (int)($activated['code'] ?? 422);

                    return $empty;
                }
            }

            return [
                'success' => true,
                'message' => (string)__('已复用邮局账号 %{1}', [$email]),
                'account_id' => (int)$existing->getId(),
                'email' => $email,
                'domain_id' => $domainId,
                'domain_name' => $domainName,
                'created' => false,
                'is_fake' => $isFake,
                'smtp_password_once' => '',
            ];
        }

        $passwordOnce = '';
        $password = '';
        if (!$isFake) {
            $passwordOnce = bin2hex(random_bytes(12));
            $password = $passwordOnce;
        }

        $created = $this->accounts()->createAccount(
            $domainId,
            $email,
            $localPart,
            $displayName,
            0,
            $quotaMb,
            $password
        );
        if (empty($created['success'])) {
            $empty['message'] = (string)($created['message'] ?? __('邮箱账号开通失败'));
            $empty['code'] = (int)($created['code'] ?? 422);

            return $empty;
        }

        return [
            'success' => true,
            'message' => (string)__('已开通邮局账号 %{1}', [$email]),
            'account_id' => (int)($created['account_id'] ?? 0),
            'email' => $email,
            'domain_id' => $domainId,
            'domain_name' => $domainName,
            'created' => true,
            'is_fake' => $isFake,
            'smtp_password_once' => $passwordOnce,
        ];
    }

    /**
     * @return array{success:bool,message:string,domain_id:int,domain_name:string,engine:string,is_fake:bool,default_quota_mb:int}
     */
    private function domainPayload(MailDomain $domain): array
    {
        return [
            'success' => true,
            'message' => '',
            'domain_id' => (int)$domain->getId(),
            'domain_name' => strtolower(trim((string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME))),
            'engine' => (string)$domain->getData(MailDomain::schema_fields_ENGINE),
            'is_fake' => $this->customerAccounts()->isFakeDomain($domain),
            'default_quota_mb' => max(128, (int)$domain->getData(MailDomain::schema_fields_DEFAULT_QUOTA_MB)),
        ];
    }

    /**
     * @return array{success:bool,message:string,code:int}
     */
    private function fail(mixed $message, int $code): array
    {
        return [
            'success' => false,
            'message' => (string)$message,
            'code' => $code,
        ];
    }
}
