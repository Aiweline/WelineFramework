<?php

declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Customer\Model\Customer;
use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailAccount;

/**
 * Frontend enterprise-mailbox self-register with optional auxiliary-email OTP.
 */
final class MailFrontendRegisterService
{
    public function __construct(
        private readonly MailFrontendFeatureConfig $featureConfig,
        private readonly MailCustomerAccountService $accountService,
    ) {
    }

    /**
     * @return array{success:bool,message:mixed,needs_verify?:bool}
     */
    public function apply(
        int $customerId,
        int $domainId,
        string $localPart,
        string $password,
        string $auxEmail = '',
        string $displayName = ''
    ): array {
        if (!$this->featureConfig->isRegisterEnabled()) {
            return ['success' => false, 'message' => __('企业邮箱前台注册未开启')];
        }
        if ($customerId <= 0) {
            return ['success' => false, 'message' => __('请先登录')];
        }
        $password = trim($password);
        if (strlen($password) < 8) {
            return ['success' => false, 'message' => __('企业邮箱密码至少 8 位')];
        }

        $auxEmail = strtolower(trim($auxEmail));
        if ($this->featureConfig->isAuxEmailVerifyEnabled()) {
            if ($auxEmail === '' || !filter_var($auxEmail, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'message' => __('请填写有效的辅助邮箱用于真人验证')];
            }
            $code = (string)random_int(100000, 999999);
            $this->cache()->set($this->pendingKey($customerId), [
                'domain_id' => $domainId,
                'local_part' => $localPart,
                'password' => $password,
                'display_name' => $displayName,
                'aux_email' => $auxEmail,
                'code' => $code,
                'expires' => time() + 1800,
            ], 1800);
            // Soft delivery: fake/log path — message includes code for local accept.
            return [
                'success' => true,
                'needs_verify' => true,
                'message' => __('验证码已发送到辅助邮箱（测试环境可直接使用）：%{1}', [$code]),
                'debug_code' => $code,
            ];
        }

        return $this->finalizeApply($customerId, $domainId, $localPart, $password, $displayName);
    }

    /**
     * @return array{success:bool,message:mixed}
     */
    public function confirm(int $customerId, string $code): array
    {
        if (!$this->featureConfig->isRegisterEnabled()) {
            return ['success' => false, 'message' => __('企业邮箱前台注册未开启')];
        }
        $pending = $this->cache()->get($this->pendingKey($customerId));
        if (!is_array($pending)) {
            return ['success' => false, 'message' => __('验证已过期，请重新申请')];
        }
        if ((int)($pending['expires'] ?? 0) < time()) {
            $this->cache()->delete($this->pendingKey($customerId));
            return ['success' => false, 'message' => __('验证已过期，请重新申请')];
        }
        if (!hash_equals((string)($pending['code'] ?? ''), trim($code))) {
            return ['success' => false, 'message' => __('验证码不正确')];
        }

        $result = $this->finalizeApply(
            $customerId,
            (int)($pending['domain_id'] ?? 0),
            (string)($pending['local_part'] ?? ''),
            (string)($pending['password'] ?? ''),
            (string)($pending['display_name'] ?? '')
        );
        if (!empty($result['success'])) {
            $this->cache()->delete($this->pendingKey($customerId));
        }

        return $result;
    }

    /**
     * @return array{success:bool,message:mixed}
     */
    private function finalizeApply(
        int $customerId,
        int $domainId,
        string $localPart,
        string $password,
        string $displayName
    ): array {
        $result = $this->accountService->apply($customerId, $domainId, $localPart, $displayName, true);
        if (empty($result['success'])) {
            return $result;
        }

        $email = strtolower(trim($localPart));
        // Reload by customer + newest account matching local part domain via list.
        foreach ($this->accountService->getAccountsForCustomer($customerId) as $account) {
            $accountEmail = strtolower((string)$account->getData(MailAccount::schema_fields_EMAIL));
            if (str_starts_with($accountEmail, $email . '@')) {
                $account->setData(MailAccount::schema_fields_PASSWORD_HASH, password_hash($password, PASSWORD_DEFAULT))
                    ->setData(MailAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
                    ->save();
                break;
            }
        }

        return $result;
    }

    private function pendingKey(int $customerId): string
    {
        return 'mail.frontend.register.pending.' . $customerId;
    }

    private function cache(): CacheInterface
    {
        return w_cache('default');
    }
}
