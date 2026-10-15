<?php
declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\MailOutboundRelaySecretService;
use Weline\Smtp\Helper\Data;
use Weline\Smtp\Model\SmtpAccount;

/**
 * Smtp 账户 CRUD（归属 Smtp，非 MailAccount）。
 */
final class SmtpAccountManageService
{
    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   smtp_account_id: int,
     *   email: string,
     *   password_once: string,
     *   created: bool
     * }
     */
    public function createAccount(
        string $localPart,
        string $role = SmtpAccount::ROLE_SUBMIT,
        string $displayName = '',
        ?string $scope = null,
    ): array {
        /** @var Data $data */
        $data = ObjectManager::getInstance(Data::class);
        $state = $data->getServiceState($scope);
        if (empty($state['enabled']) || (string)($state['domain_name'] ?? '') === '') {
            return $this->fail(__('请先启用 Smtp 并完成域名入池'), 0, '', false);
        }
        if ($role === SmtpAccount::ROLE_RELAY && empty($state['relay_service'])) {
            return $this->fail(__('请先开启中继服务再创建中继账号'), 0, '', false);
        }
        $localPart = strtolower(trim($localPart));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $localPart)) {
            return $this->fail(__('账号名格式无效'), 0, '', false);
        }
        $domain = (string)$state['domain_name'];
        $email = $localPart . '@' . $domain;
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class);
        $existing = $model->clear()->where(SmtpAccount::schema_fields_EMAIL, $email)->find()->fetch();
        if ($existing->getId()) {
            return $this->fail(__('该 Smtp 账户已存在'), (int)$existing->getId(), $email, false);
        }
        $password = $this->generatePassword();
        /** @var MailOutboundRelaySecretService $secrets */
        $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
        try {
            $sealed = $secrets->seal($password);
        } catch (\Throwable $e) {
            return $this->fail(__('密码密封失败：%{1}', [$e->getMessage()]), 0, $email, false);
        }
        $now = date('Y-m-d H:i:s');
        $role = in_array($role, [SmtpAccount::ROLE_SUBMIT, SmtpAccount::ROLE_RELAY], true)
            ? $role
            : SmtpAccount::ROLE_SUBMIT;
        $model->clear()
            ->setData(SmtpAccount::schema_fields_DOMAIN_NAME, $domain)
            ->setData(SmtpAccount::schema_fields_EMAIL, $email)
            ->setData(SmtpAccount::schema_fields_DISPLAY_NAME, $displayName !== '' ? $displayName : $localPart)
            ->setData(SmtpAccount::schema_fields_ROLE, $role)
            ->setData(SmtpAccount::schema_fields_STATUS, 'active')
            ->setData(SmtpAccount::schema_fields_PASSWORD_SEALED, $sealed)
            ->setData(SmtpAccount::schema_fields_CREATED_AT, $now)
            ->setData(SmtpAccount::schema_fields_UPDATED_AT, $now)
            ->save();
        $id = (int)$model->getId();
        if ($id <= 0) {
            return $this->fail(__('Smtp 账户保存失败'), 0, $email, false);
        }

        return [
            'success' => true,
            'message' => (string)__('Smtp 账户已创建，请立即复制密码'),
            'smtp_account_id' => $id,
            'email' => $email,
            'password_once' => $password,
            'created' => true,
        ];
    }

    /**
     * @return list<SmtpAccount>
     */
    public function listAccounts(string $domainName = ''): array
    {
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class);
        $q = $model->clear();
        if ($domainName !== '') {
            $q->where(SmtpAccount::schema_fields_DOMAIN_NAME, strtolower(trim($domainName)));
        }
        $rows = $q->order(SmtpAccount::schema_fields_EMAIL, 'ASC')->select()->fetch();
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof SmtpAccount) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string, created: bool}
     */
    public function resetPassword(int $accountId): array
    {
        /** @var SmtpAccount $model */
        $model = ObjectManager::getInstance(SmtpAccount::class)->clear()->load($accountId);
        if (!$model->getId()) {
            return $this->fail(__('Smtp 账户不存在'), 0, '', false);
        }
        $password = $this->generatePassword();
        /** @var MailOutboundRelaySecretService $secrets */
        $secrets = ObjectManager::getInstance(MailOutboundRelaySecretService::class);
        try {
            $sealed = $secrets->seal($password);
        } catch (\Throwable $e) {
            return $this->fail(__('密码密封失败：%{1}', [$e->getMessage()]), $accountId, (string)$model->getData(SmtpAccount::schema_fields_EMAIL), false);
        }
        $model->setData(SmtpAccount::schema_fields_PASSWORD_SEALED, $sealed)
            ->setData(SmtpAccount::schema_fields_UPDATED_AT, date('Y-m-d H:i:s'))
            ->save();

        return [
            'success' => true,
            'message' => (string)__('密码已重置，请立即复制'),
            'smtp_account_id' => $accountId,
            'email' => (string)$model->getData(SmtpAccount::schema_fields_EMAIL),
            'password_once' => $password,
            'created' => false,
        ];
    }

    private function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '._'), '=');
    }

    /**
     * @return array{success: bool, message: string, smtp_account_id: int, email: string, password_once: string, created: bool}
     */
    private function fail(string $message, int $id, string $email, bool $created): array
    {
        return [
            'success' => false,
            'message' => $message,
            'smtp_account_id' => $id,
            'email' => $email,
            'password_once' => '',
            'created' => $created,
        ];
    }
}
