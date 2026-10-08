<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\Data;

/**
 * Smtp 自管编排：经 mail Query 确保 contact@ / 分流邮箱，再写 mail_account 传输与渠道绑定。
 * 禁止直调 Mail Service/Model。
 */
class MailSmtpBootstrapService
{
    public function __construct(
        private readonly ?Data $data = null,
        private readonly ?MailAccountTransportProvisioner $provisioner = null,
        private readonly ?MailChannelCollector $collector = null,
    ) {
    }

    private function data(): Data
    {
        return $this->data ?? ObjectManager::getInstance(Data::class);
    }

    private function provisioner(): MailAccountTransportProvisioner
    {
        return $this->provisioner ?? ObjectManager::getInstance(MailAccountTransportProvisioner::class);
    }

    private function collector(): MailChannelCollector
    {
        return $this->collector ?? ObjectManager::getInstance(MailChannelCollector::class);
    }

    /**
     * 是否具备一键入口（有 active 域或已有邮局账号）。
     */
    public function canEnsure(): bool
    {
        try {
            $domain = w_query('mail', 'resolveActiveMailDomain', []);
            if (is_array($domain) && !empty($domain['success'])) {
                return true;
            }
        } catch (\Throwable) {
        }
        try {
            $accounts = w_query('mail', 'getSmtpAccounts', ['limit' => 1]);
            if (is_array($accounts) && !empty($accounts['success']) && !empty($accounts['items'])) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureDefaultContactPath(
        string $scope = 'default.default.default',
        int $domainId = 0,
        ?bool $rebindAll = null,
    ): array {
        $contact = $this->queryMail('ensureContactAccount', [
            'domain_id' => $domainId,
        ]);
        if ($contact === null || empty($contact['success'])) {
            return [
                'success' => false,
                'message' => (string)($contact['message'] ?? __('无法确保 contact@ 邮局账号')),
                'account_id' => 0,
                'transport_code' => '',
                'bound_channels' => 0,
                'created_mailbox' => false,
                'rebind_all' => false,
            ];
        }

        $accountId = (int)($contact['account_id'] ?? 0);
        if ($accountId <= 0) {
            return [
                'success' => false,
                'message' => (string)__('contact@ 账号创建结果缺少 account_id'),
                'account_id' => 0,
                'transport_code' => '',
                'bound_channels' => 0,
                'created_mailbox' => !empty($contact['created']),
                'rebind_all' => false,
            ];
        }

        $doRebind = $rebindAll ?? !$this->hasMailboxRouting($scope);
        $ensured = $this->provisioner()->ensure($scope, $accountId, $doRebind);
        if (empty($ensured['success'])) {
            return array_merge($ensured, [
                'created_mailbox' => !empty($contact['created']),
                'account_id' => $accountId,
            ]);
        }

        $passwordOnce = trim((string)($contact['smtp_password_once'] ?? ''));
        if ($passwordOnce !== '') {
            $this->applySmtpPasswordToTransport(
                $scope,
                (string)($ensured['transport_code'] ?? MailAccountTransportProvisioner::TRANSPORT_CODE),
                $passwordOnce
            );
        }

        $message = (string)($ensured['message'] ?? '');
        if (!empty($contact['created'])) {
            $message = (string)__('已确保 %{1}。', [(string)($contact['email'] ?? 'contact@')])
                . ' ' . $message;
        }

        return [
            'success' => true,
            'message' => $message,
            'account_id' => $accountId,
            'email' => (string)($contact['email'] ?? ''),
            'transport_code' => (string)($ensured['transport_code'] ?? ''),
            'bound_channels' => (int)($ensured['bound_channels'] ?? 0),
            'created_transport' => !empty($ensured['created_transport']),
            'created_mailbox' => !empty($contact['created']),
            'needs_password' => !empty($ensured['needs_password']) && $passwordOnce === '',
            'is_fake' => !empty($contact['is_fake']),
            'rebind_all' => $doRebind,
        ];
    }

    /**
     * 分流：建箱 → mail_account 传输 → 仅绑所选渠道。
     *
     * @param list<string> $channelCodes
     * @return array<string, mixed>
     */
    public function routeMailboxToChannels(
        string $scope,
        string $localPart,
        array $channelCodes,
        int $domainId = 0,
        string $displayName = '',
    ): array {
        $channels = [];
        foreach ($channelCodes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $channels[$code] = true;
            }
        }
        $channels = array_keys($channels);
        if ($channels === []) {
            return [
                'success' => false,
                'message' => (string)__('请至少选择一个发信渠道'),
            ];
        }

        $registered = [];
        foreach ($this->collector()->collect() as $row) {
            $c = trim((string)($row['code'] ?? ''));
            if ($c !== '') {
                $registered[$c] = true;
            }
        }
        foreach ($channels as $code) {
            if (!isset($registered[$code])) {
                return [
                    'success' => false,
                    'message' => (string)__('未知发信渠道：%{1}', [$code]),
                ];
            }
        }

        $mailbox = $this->queryMail('ensureMailbox', [
            'domain_id' => $domainId,
            'local_part' => $localPart,
            'display_name' => $displayName,
        ]);
        if ($mailbox === null || empty($mailbox['success'])) {
            return [
                'success' => false,
                'message' => (string)($mailbox['message'] ?? __('分流邮箱开通失败')),
            ];
        }

        $accountId = (int)($mailbox['account_id'] ?? 0);
        $email = (string)($mailbox['email'] ?? '');
        if ($accountId <= 0 || $email === '') {
            return [
                'success' => false,
                'message' => (string)__('分流邮箱结果无效'),
            ];
        }

        $config = $this->queryMail('getSmtpAccountConfig', ['account_id' => $accountId]);
        $mailConfig = is_array($config['config'] ?? null) ? $config['config'] : [
            'account_id' => $accountId,
            'email' => $email,
            'display_name' => $displayName !== '' ? $displayName : $email,
            'domain_id' => (int)($mailbox['domain_id'] ?? 0),
            'domain_name' => (string)($mailbox['domain_name'] ?? ''),
            'is_fake' => !empty($mailbox['is_fake']),
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_secure' => 'tls',
            'smtp_auth' => '1',
        ];

        $module = 'Weline_Smtp';
        $senders = $this->data()->getSenders($module, $scope);
        $transportCode = $this->findOrCreateMailTransport($senders, $mailConfig, $localPart);
        $this->data()->setSenders($senders, $module, $scope);

        $passwordOnce = trim((string)($mailbox['smtp_password_once'] ?? ''));
        if ($passwordOnce !== '') {
            $this->applySmtpPasswordToTransport($scope, $transportCode, $passwordOnce);
            $senders = $this->data()->getSenders($module, $scope);
        }

        $bindings = $this->data()->getChannelBindings($module, $scope);
        $changed = 0;
        foreach ($channels as $code) {
            if (trim((string)($bindings[$code] ?? '')) !== $transportCode) {
                $changed++;
            }
            $bindings[$code] = $transportCode;
        }
        $this->data()->setChannelBindings($bindings, $module, $scope);

        return [
            'success' => true,
            'message' => (string)__('已将 %{1} 挂到 %{2} 个渠道（传输 %{3}）。', [
                $email,
                (string)count($channels),
                $transportCode,
            ]),
            'account_id' => $accountId,
            'email' => $email,
            'transport_code' => $transportCode,
            'bound_channels' => count($channels),
            'changed_bindings' => $changed,
            'created_mailbox' => !empty($mailbox['created']),
            'is_fake' => !empty($mailbox['is_fake']),
        ];
    }

    /**
     * 是否已有「非 mail_default」的 mail_account 渠道分流。
     */
    public function hasMailboxRouting(string $scope = 'default.default.default'): bool
    {
        $senders = $this->data()->getSenders('Weline_Smtp', $scope);
        $routedCodes = [];
        foreach ($senders as $row) {
            if (!is_array($row) || (string)($row['source_type'] ?? '') !== 'mail_account') {
                continue;
            }
            $code = trim((string)($row['code'] ?? ''));
            if ($code !== '' && $code !== MailAccountTransportProvisioner::TRANSPORT_CODE) {
                $routedCodes[$code] = true;
            }
        }
        if ($routedCodes === []) {
            return false;
        }
        foreach ($this->data()->getChannelBindings('Weline_Smtp', $scope) as $tid) {
            $tid = trim((string)$tid);
            if ($tid !== '' && isset($routedCodes[$tid])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $senders
     * @param array<string, mixed> $mailConfig
     */
    private function findOrCreateMailTransport(array &$senders, array $mailConfig, string $localPart): string
    {
        $accountId = (int)($mailConfig['account_id'] ?? 0);
        foreach ($senders as $idx => $row) {
            if (!is_array($row) || (string)($row['source_type'] ?? '') !== 'mail_account') {
                continue;
            }
            if ((int)($row['mail_account_id'] ?? 0) === $accountId && $accountId > 0) {
                $code = trim((string)($row['code'] ?? ''));
                if ($code === '') {
                    $code = $this->mailTransportCode($localPart);
                    $row['code'] = $code;
                }
                $senders[$idx] = $this->mergeMailSenderRow($row, $mailConfig);

                return (string)$senders[$idx]['code'];
            }
        }

        $code = $this->mailTransportCode($localPart);
        if ($this->senderCodeExists($senders, $code)) {
            $code = Data::generateTransportId();
        }
        $senders[] = $this->mergeMailSenderRow([
            'code' => $code,
            'smtp_password' => '',
        ], $mailConfig);

        return $code;
    }

    private function mailTransportCode(string $localPart): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($localPart))) ?? '';
        $slug = trim($slug, '_');
        if ($slug === '') {
            return Data::generateTransportId();
        }
        $code = 'mail_' . $slug;
        if (!Data::isValidTransportId($code) && !preg_match('/^[A-Za-z][A-Za-z0-9_.:-]*$/', $code)) {
            return Data::generateTransportId();
        }

        return $code;
    }

    /**
     * @param list<array<string, mixed>> $senders
     */
    private function senderCodeExists(array $senders, string $code): bool
    {
        foreach ($senders as $row) {
            if (is_array($row) && (string)($row['code'] ?? '') === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $mailConfig
     * @return array<string, mixed>
     */
    private function mergeMailSenderRow(array $row, array $mailConfig): array
    {
        $email = (string)($mailConfig['email'] ?? $mailConfig['mail_account_email'] ?? '');
        $display = trim((string)($mailConfig['display_name'] ?? $email));
        if ($display === '') {
            $display = $email !== '' ? $email : (string)($row['code'] ?? 'mail');
        }
        $row['name'] = $display;
        $row['source_type'] = 'mail_account';
        $row['mail_account_id'] = (string)(int)($mailConfig['account_id'] ?? 0);
        $row['mail_account_email'] = $email;
        $row['mail_domain_id'] = (string)(int)($mailConfig['domain_id'] ?? 0);
        $row['mail_domain_name'] = (string)($mailConfig['domain_name'] ?? '');
        $row['mail_engine'] = (string)($mailConfig['engine'] ?? '');
        $row['mail_is_fake'] = !empty($mailConfig['is_fake']) ? '1' : '0';
        $row['smtp_host'] = (string)($mailConfig['smtp_host'] ?? '');
        $row['smtp_port'] = (string)($mailConfig['smtp_port'] ?? '587');
        $row['smtp_secure'] = (string)($mailConfig['smtp_secure'] ?? 'tls');
        $row['smtp_auth'] = (string)($mailConfig['smtp_auth'] ?? '1');
        $row['smtp_username'] = $email;
        if (!isset($row['smtp_password'])) {
            $row['smtp_password'] = '';
        }

        return $row;
    }

    private function applySmtpPasswordToTransport(string $scope, string $transportCode, string $password): void
    {
        if ($transportCode === '' || $password === '') {
            return;
        }
        $module = 'Weline_Smtp';
        $senders = $this->data()->getSenders($module, $scope);
        $changed = false;
        foreach ($senders as $idx => $row) {
            if (!is_array($row) || (string)($row['code'] ?? '') !== $transportCode) {
                continue;
            }
            $row['smtp_password'] = $password;
            $senders[$idx] = $row;
            $changed = true;
            break;
        }
        if ($changed) {
            $this->data()->setSenders($senders, $module, $scope);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function queryMail(string $op, array $params): ?array
    {
        try {
            $result = w_query('mail', $op, $params);
            return is_array($result) ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
