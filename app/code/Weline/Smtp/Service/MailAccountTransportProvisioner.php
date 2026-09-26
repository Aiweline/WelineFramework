<?php

declare(strict_types=1);

namespace Weline\Smtp\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Smtp\Helper\Data;

/**
 * Smtp 自管：将自建邮局 active 账号写成 mail_account 传输，并补齐未绑定渠道。
 * 幂等；不覆盖已有 external 传输，除非调用方要求 ensure（新建/更新 mail_default）。
 */
class MailAccountTransportProvisioner
{
    public const TRANSPORT_CODE = 'mail_default';

    public function __construct(
        private readonly ?Data $data = null,
        private readonly ?MailChannelCollector $collector = null,
    ) {
    }

    private function data(): Data
    {
        return $this->data ?? ObjectManager::getInstance(Data::class);
    }

    private function collector(): MailChannelCollector
    {
        return $this->collector ?? ObjectManager::getInstance(MailChannelCollector::class);
    }

    /**
     * @param bool $rebindAll 为 true 时把全部已注册渠道绑到自建传输（便于从外部 SMTP 切换）；false 仅补未绑定。
     * @return array{
     *   success: bool,
     *   message: string,
     *   transport_code: string,
     *   mail_account_id: int,
     *   bound_channels: int,
     *   created_transport: bool,
     *   needs_password: bool,
     *   is_fake: bool,
     *   rebind_all: bool
     * }
     */
    public function ensure(
        string $scope = 'default.default.default',
        int $preferredAccountId = 0,
        bool $rebindAll = true,
    ): array {
        $empty = [
            'success' => false,
            'message' => '',
            'transport_code' => '',
            'mail_account_id' => 0,
            'bound_channels' => 0,
            'created_transport' => false,
            'needs_password' => false,
            'is_fake' => false,
            'rebind_all' => $rebindAll,
        ];

        $mailConfig = $this->resolveMailAccountConfig($preferredAccountId);
        if ($mailConfig === null) {
            $empty['message'] = (string)__('未找到可用的自建邮局账号。请先在企业邮箱管理中开通并启用域名与账号。');

            return $empty;
        }

        $accountId = (int)($mailConfig['account_id'] ?? 0);
        $isFake = !empty($mailConfig['is_fake']);
        $email = trim((string)($mailConfig['email'] ?? ''));
        $displayName = trim((string)($mailConfig['display_name'] ?? $email));
        if ($displayName === '') {
            $displayName = $email !== '' ? $email : self::TRANSPORT_CODE;
        }

        $module = 'Weline_Smtp';
        $senders = $this->data()->getSenders($module, $scope);
        $existingMail = $this->findMailAccountSender($senders, $accountId)
            ?? $this->findMailAccountSender($senders, 0);
        $created = false;

        if ($existingMail !== null) {
            $transportCode = (string)($existingMail['code'] ?? self::TRANSPORT_CODE);
            $merged = [];
            foreach ($senders as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((string)($row['code'] ?? '') === $transportCode) {
                    $merged[] = $this->buildSenderRow($transportCode, $displayName, $mailConfig, $row);
                } else {
                    $merged[] = $row;
                }
            }
            $senders = $merged;
        } else {
            $transportCode = self::TRANSPORT_CODE;
            // 若 mail_default 已被外部占用，换新 ID
            if ($this->findSenderByCode($senders, $transportCode) !== null
                && (string)($this->findSenderByCode($senders, $transportCode)['source_type'] ?? '') !== 'mail_account'
            ) {
                $transportCode = Data::generateTransportId();
            }
            $existingSameCode = $this->findSenderByCode($senders, $transportCode);
            if ($existingSameCode !== null && (string)($existingSameCode['source_type'] ?? '') === 'mail_account') {
                $senders = $this->replaceSender(
                    $senders,
                    $transportCode,
                    $this->buildSenderRow($transportCode, $displayName, $mailConfig, $existingSameCode)
                );
            } else {
                $senders[] = $this->buildSenderRow($transportCode, $displayName, $mailConfig, null);
                $created = true;
            }
        }

        $this->data()->setSenders($senders, $module, $scope);

        $bound = $rebindAll
            ? $this->bindAllChannels($transportCode, $module, $scope)
            : $this->bindUnboundChannels($transportCode, $module, $scope);
        $needsPassword = !$isFake;

        if ($created) {
            $message = $rebindAll
                ? (string)__('已创建自建邮局传输账户 %{1}，并将 %{2} 个发信渠道切到该传输。请发送测试邮件确认。', [
                    $transportCode,
                    (string)$bound,
                ])
                : (string)__('已创建自建邮局传输账户 %{1}，并补齐 %{2} 个未绑定渠道。请发送测试邮件确认。', [
                    $transportCode,
                    (string)$bound,
                ]);
        } else {
            $message = $rebindAll
                ? (string)__('已同步自建邮局传输账户 %{1}，并将 %{2} 个发信渠道切到该传输。请发送测试邮件确认。', [
                    $transportCode,
                    (string)$bound,
                ])
                : (string)__('已同步自建邮局传输账户 %{1}，并补齐 %{2} 个未绑定渠道。请发送测试邮件确认。', [
                    $transportCode,
                    (string)$bound,
                ]);
        }
        if ($needsPassword) {
            $message .= ' ' . (string)__('真实邮局账号请在传输账户中填写 SMTP 密码后再测试。');
        }

        return [
            'success' => true,
            'message' => $message,
            'transport_code' => $transportCode,
            'mail_account_id' => $accountId,
            'bound_channels' => $bound,
            'created_transport' => $created,
            'needs_password' => $needsPassword,
            'is_fake' => $isFake,
            'rebind_all' => $rebindAll,
        ];
    }

    /**
     * @return array{path: 'none'|'external'|'mail_account'|'mixed', mail_account_ids: list<int>, has_external: bool}
     */
    public function detectSendPath(string $scope = 'default.default.default'): array
    {
        $senders = $this->data()->getSenders('Weline_Smtp', $scope);
        $mailIds = [];
        $hasExternal = false;
        $hasMail = false;
        foreach ($senders as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string)($row['source_type'] ?? 'external');
            if ($type === 'mail_account') {
                $hasMail = true;
                $id = (int)($row['mail_account_id'] ?? 0);
                if ($id > 0) {
                    $mailIds[] = $id;
                }
            } else {
                $hasExternal = true;
            }
        }
        $path = 'none';
        if ($hasMail && $hasExternal) {
            $path = 'mixed';
        } elseif ($hasMail) {
            $path = 'mail_account';
        } elseif ($hasExternal) {
            $path = 'external';
        }

        return [
            'path' => $path,
            'mail_account_ids' => array_values(array_unique($mailIds)),
            'has_external' => $hasExternal,
        ];
    }

    /**
     * @return list<string> unbound channel codes
     */
    public function unboundChannels(string $scope = 'default.default.default'): array
    {
        $bindings = $this->data()->getChannelBindings('Weline_Smtp', $scope);
        $missing = [];
        foreach ($this->collector()->collect() as $channel) {
            $code = trim((string)($channel['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (trim((string)($bindings[$code] ?? '')) === '') {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    private function bindUnboundChannels(string $transportCode, string $module, string $scope): int
    {
        $bindings = $this->data()->getChannelBindings($module, $scope);
        $added = 0;
        foreach ($this->collector()->collect() as $channel) {
            $code = trim((string)($channel['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (trim((string)($bindings[$code] ?? '')) === '') {
                $bindings[$code] = $transportCode;
                $added++;
            }
        }
        if ($added > 0) {
            $this->data()->setChannelBindings($bindings, $module, $scope);
        }

        return $added;
    }

    /**
     * 将全部已注册渠道绑到指定传输（覆盖旧绑定，便于从外部 SMTP 切到自建邮局）。
     */
    private function bindAllChannels(string $transportCode, string $module, string $scope): int
    {
        $bindings = $this->data()->getChannelBindings($module, $scope);
        $count = 0;
        $changed = false;
        foreach ($this->collector()->collect() as $channel) {
            $code = trim((string)($channel['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $prev = trim((string)($bindings[$code] ?? ''));
            if ($prev !== $transportCode) {
                $changed = true;
            }
            $bindings[$code] = $transportCode;
            $count++;
        }
        if ($changed || $count > 0) {
            $this->data()->setChannelBindings($bindings, $module, $scope);
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $senders
     * @return array<string, mixed>|null
     */
    private function findMailAccountSender(array $senders, int $accountId): ?array
    {
        $fallback = null;
        foreach ($senders as $row) {
            if (!is_array($row) || (string)($row['source_type'] ?? '') !== 'mail_account') {
                continue;
            }
            if ($accountId > 0 && (int)($row['mail_account_id'] ?? 0) === $accountId) {
                return $row;
            }
            if ($fallback === null) {
                $fallback = $row;
            }
        }

        return $accountId > 0 ? null : $fallback;
    }

    /**
     * @param list<array<string, mixed>> $senders
     * @return array<string, mixed>|null
     */
    private function findSenderByCode(array $senders, string $code): ?array
    {
        foreach ($senders as $row) {
            if (is_array($row) && (string)($row['code'] ?? '') === $code) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $senders
     * @param array<string, mixed> $replacement
     * @return list<array<string, mixed>>
     */
    private function replaceSender(array $senders, string $code, array $replacement): array
    {
        $out = [];
        foreach ($senders as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string)($row['code'] ?? '') === $code) {
                $out[] = $replacement;
            } else {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $mailConfig
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function buildSenderRow(string $code, string $displayName, array $mailConfig, ?array $existing): array
    {
        $row = is_array($existing) ? $existing : [];
        $row['code'] = $code;
        $row['name'] = $displayName;
        $row['source_type'] = 'mail_account';
        $row['mail_account_id'] = (string)(int)($mailConfig['account_id'] ?? 0);
        $row['mail_account_email'] = (string)($mailConfig['email'] ?? '');
        $row['mail_domain_id'] = (string)(int)($mailConfig['domain_id'] ?? 0);
        $row['mail_domain_name'] = (string)($mailConfig['domain_name'] ?? '');
        $row['mail_engine'] = (string)($mailConfig['engine'] ?? '');
        $row['mail_is_fake'] = !empty($mailConfig['is_fake']) ? '1' : '0';
        $row['smtp_host'] = (string)($mailConfig['smtp_host'] ?? '');
        $row['smtp_port'] = (string)($mailConfig['smtp_port'] ?? '587');
        $row['smtp_secure'] = (string)($mailConfig['smtp_secure'] ?? 'tls');
        $row['smtp_auth'] = (string)($mailConfig['smtp_auth'] ?? '1');
        $row['smtp_username'] = (string)($mailConfig['email'] ?? '');
        if (!isset($row['smtp_password'])) {
            $row['smtp_password'] = '';
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveMailAccountConfig(int $preferredAccountId): ?array
    {
        if ($preferredAccountId > 0) {
            $one = $this->loadMailAccountConfig($preferredAccountId);
            if ($one !== null) {
                return $one;
            }
        }

        $items = $this->loadMailAccounts();
        if ($items === []) {
            return null;
        }

        $preferredLocals = ['noreply', 'no-reply', 'system', 'notify', 'orders', 'mailer'];
        $scored = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $email = strtolower(trim((string)($item['email'] ?? '')));
            $local = $email !== '' && str_contains($email, '@')
                ? substr($email, 0, (int)strpos($email, '@'))
                : '';
            $score = 0;
            if (empty($item['is_fake'])) {
                $score += 100;
            }
            $idx = array_search($local, $preferredLocals, true);
            if ($idx !== false) {
                $score += 50 - (int)$idx;
            }
            $scored[] = ['score' => $score, 'item' => $item];
        }
        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $best = $scored[0]['item'] ?? null;
        if (!is_array($best)) {
            return null;
        }
        $id = (int)($best['account_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->loadMailAccountConfig($id) ?? $best;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadMailAccounts(): array
    {
        try {
            $result = w_query('mail', 'getSmtpAccounts', ['limit' => 200]);
            if (is_array($result) && !empty($result['success']) && is_array($result['items'] ?? null)) {
                return $result['items'];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMailAccountConfig(int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        try {
            $result = w_query('mail', 'getSmtpAccountConfig', ['account_id' => $accountId]);
            if (is_array($result) && !empty($result['success']) && is_array($result['config'] ?? null)) {
                return $result['config'];
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
