<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Model\MailDomain;

/**
 * 一键配置：绑定本机 Stalwart 管理凭据 → 按库同步（全局或单域）。
 *
 * 管理密码不进库；落点 /etc/weline 与 var/mail 双路径。
 * recovery 必须写入进程实际加载的 env（macOS：/opt/stalwart/etc/stalwart.env）并 launchd 重启。
 */
final class MailOneClickSetupService
{
    private const MANAGEMENT_USER = 'admin';
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:8080';

    /**
     * @param callable(string $step, string $message, int $current, int $total): void|null $onProgress
     * @return array{
     *   success: bool,
     *   message: string,
     *   skipped: bool,
     *   password_once?: string,
     *   hint?: string,
     *   code?: int
     * }
     */
    public function bindManagementIfNeeded(?callable $onProgress = null): array
    {
        /** @var StalwartManagementAdapter $mgmt */
        $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
        $emit = static function (string $step, string $message, int $cur = 1, int $tot = 4) use ($onProgress): void {
            if ($onProgress !== null) {
                $onProgress($step, $message, $cur, $tot);
            }
        };

        $password = '';
        $reusingCredential = false;
        if ($mgmt->hasManagementCredential()) {
            $emit('bind', (string)__('检测已有管理凭据…'), 1, 4);
            try {
                $mgmt->probeManagementApi();
                $emit('bind', (string)__('管理凭据已可用，跳过绑定'), 4, 4);

                return [
                    'success' => true,
                    'message' => (string)__('管理凭据已就绪'),
                    'skipped' => true,
                ];
            } catch (\Throwable) {
                // 401/网络失败：用密封中的同密重写 recovery 并重启，禁止跳过直进 sync
                try {
                    $password = $mgmt->openManagementPassword();
                    $reusingCredential = true;
                    $emit('bind', (string)__('凭据存在但 API 未通，将用同密重绑 recovery…'), 1, 4);
                } catch (\Throwable $e) {
                    return [
                        'success' => false,
                        'message' => (string)__('无法打开已有管理凭据：%{1}', [$e->getMessage()]),
                        'skipped' => false,
                        'code' => 500,
                    ];
                }
            }
        }

        if ($password === '') {
            $password = $this->generateManagementPassword();
            $emit('bind', (string)__('正在设置 Stalwart recovery 管理员…'), 1, 4);
        }

        $bind = $this->trySetStalwartRecoveryAdmin($password);
        if (empty($bind['success'])) {
            return [
                'success' => false,
                'message' => (string)($bind['message'] ?? __('无法自动绑定管理凭据')),
                'skipped' => false,
                'hint' => (string)($bind['hint'] ?? ''),
                'password_once' => $password,
                'code' => 500,
            ];
        }

        $emit('bind', (string)__('正在写入本机管理凭据（与 recovery 同密）…'), 2, 4);
        try {
            $baseUrl = $reusingCredential
                ? (string)($mgmt->readManagementCredential()['base_url'] ?? $this->detectManagementBaseUrl())
                : $this->detectManagementBaseUrl();
            $mgmt->upsertManagementCredential(self::MANAGEMENT_USER, $password, $baseUrl);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => (string)__('写入管理凭据失败：%{1}', [$e->getMessage()]),
                'skipped' => false,
                'password_once' => $password,
                'code' => 500,
            ];
        }

        $emit('bind', (string)__('等待服务就绪并探测管理 API…'), 3, 4);
        $probeError = $this->waitForManagementProbe($mgmt, false);
        if ($probeError !== '' && $this->isBootstrapBlocked($probeError)) {
            $emit('bind', (string)__('引擎仍在 bootstrap，正在提交本机路径…'), 3, 4);
            try {
                $needsRestart = $mgmt->completeBootstrapIfNeeded();
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => (string)__('Bootstrap 失败：%{1}', [$e->getMessage()]),
                    'skipped' => false,
                    'password_once' => $password,
                    'code' => 500,
                ];
            }
            if ($needsRestart) {
                $emit('bind', (string)__('Bootstrap 已提交，正在重启服务…'), 3, 4);
                $rebind = $this->trySetStalwartRecoveryAdmin($password);
                if (empty($rebind['success'])) {
                    return [
                        'success' => false,
                        'message' => (string)__('Bootstrap 后重启失败：%{1}', [(string)($rebind['message'] ?? '')]),
                        'skipped' => false,
                        'hint' => (string)($rebind['hint'] ?? ''),
                        'password_once' => $password,
                        'code' => 500,
                    ];
                }
            }
            $probeError = $this->waitForManagementProbe($mgmt, true);
        }
        if ($probeError !== '') {
            return [
                'success' => false,
                'message' => (string)__('管理 API 探测失败：%{1}', [$probeError]),
                'skipped' => false,
                'password_once' => $password,
                'hint' => (string)__(
                    '已写入 %{1} 并尝试重启；仍 401 则 recovery 未生效；仍 bootstrap 则需完成 Bootstrap/set。',
                    ['/opt/stalwart/etc/stalwart.env']
                ),
                'code' => 500,
            ];
        }

        $emit('bind', (string)__('管理连接已绑定并通过探测'), 4, 4);

        $out = [
            'success' => true,
            'message' => (string)__('管理连接已绑定'),
            'skipped' => false,
        ];
        if (!$reusingCredential) {
            $out['password_once'] = $password;
        }

        return $out;
    }

    /**
     * @param callable(string $step, string $message, int $current, int $total): void|null $onProgress
     * @param callable(string $email, string $passwordOnce): void|null $onPasswordOnce
     * @return array<string, mixed>
     */
    public function oneClickGlobal(
        ?callable $onProgress = null,
        ?callable $onPasswordOnce = null
    ): array {
        $bind = $this->bindManagementIfNeeded($onProgress);
        if (!empty($bind['password_once']) && $onPasswordOnce !== null) {
            $onPasswordOnce(self::MANAGEMENT_USER, (string)$bind['password_once']);
        }
        if (empty($bind['success'])) {
            return [
                'success' => false,
                'message' => (string)($bind['message'] ?? __('绑定管理凭据失败')),
                'hint' => (string)($bind['hint'] ?? ''),
                'provisioned' => 0,
                'skipped' => 0,
                'regenerated' => 0,
                'reader_synced' => false,
                'bind_skipped' => !empty($bind['skipped']),
                'code' => (int)($bind['code'] ?? 500),
            ];
        }

        /** @var MailEngineAccountSyncService $sync */
        $sync = ObjectManager::getInstance(MailEngineAccountSyncService::class);
        $result = $sync->syncFromDatabase($onProgress, $onPasswordOnce, false, null);
        $result['bind_skipped'] = !empty($bind['skipped']);
        $result['bind_message'] = (string)($bind['message'] ?? '');

        return $result;
    }

    /**
     * @param callable(string $step, string $message, int $current, int $total): void|null $onProgress
     * @param callable(string $email, string $passwordOnce): void|null $onPasswordOnce
     * @return array<string, mixed>
     */
    public function oneClickDomain(
        int $domainId,
        ?callable $onProgress = null,
        ?callable $onPasswordOnce = null
    ): array {
        if ($domainId < 1) {
            return [
                'success' => false,
                'message' => (string)__('域名 ID 无效'),
                'provisioned' => 0,
                'skipped' => 0,
                'regenerated' => 0,
                'reader_synced' => false,
                'code' => 422,
            ];
        }

        $bind = $this->bindManagementIfNeeded($onProgress);
        if (!empty($bind['password_once']) && $onPasswordOnce !== null) {
            $onPasswordOnce(self::MANAGEMENT_USER, (string)$bind['password_once']);
        }
        if (empty($bind['success'])) {
            return [
                'success' => false,
                'message' => (string)($bind['message'] ?? __('绑定管理凭据失败')),
                'hint' => (string)($bind['hint'] ?? ''),
                'provisioned' => 0,
                'skipped' => 0,
                'regenerated' => 0,
                'reader_synced' => false,
                'code' => (int)($bind['code'] ?? 500),
            ];
        }

        /** @var StalwartManagementAdapter $mgmt */
        $mgmt = ObjectManager::getInstance(StalwartManagementAdapter::class);
        /** @var MailDomain $domain */
        $domain = ObjectManager::getInstance(MailDomain::class)->clear()->load($domainId);
        if (!$domain->getId()) {
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
        $domainName = strtolower(trim((string)$domain->getData(MailDomain::schema_fields_DOMAIN_NAME)));
        if ($domainName === '') {
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

        /** @var MailEngineAccountSyncService $sync */
        $sync = ObjectManager::getInstance(MailEngineAccountSyncService::class);
        $skipEngine = $sync->shouldSkipEngineDomain($domainName)
            || ObjectManager::getInstance(MailCustomerAccountService::class)->isFakeDomain($domain);

        if ($onProgress !== null) {
            $onProgress(
                'domain',
                $skipEngine
                    ? (string)__('跳过假域/测试域引擎录入 %{1}', [$domainName])
                    : (string)__('确保引擎域名 %{1}', [$domainName]),
                1,
                4
            );
        }
        if (!$skipEngine) {
            try {
                $mgmt->ensureDomain($domainName);
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => (string)__('域名 %{1} 录入失败：%{2}', [$domainName, $e->getMessage()]),
                    'provisioned' => 0,
                    'skipped' => 0,
                    'regenerated' => 0,
                    'reader_synced' => false,
                    'code' => 500,
                ];
            }
        }

        if ($onProgress !== null) {
            $onProgress('contact', (string)__('确保 contact@%{1}', [$domainName]), 2, 4);
        }
        /** @var MailAccountEnsureService $ensure */
        $ensure = ObjectManager::getInstance(MailAccountEnsureService::class);
        $contact = $ensure->ensureContactAccount($domainId);
        if (empty($contact['success'])) {
            return [
                'success' => false,
                'message' => (string)($contact['message'] ?? __('确保 contact@ 失败')),
                'provisioned' => 0,
                'skipped' => 0,
                'regenerated' => 0,
                'reader_synced' => false,
                'code' => (int)($contact['code'] ?? 500),
            ];
        }
        $once = trim((string)($contact['smtp_password_once'] ?? ''));
        if ($once !== '' && $onPasswordOnce !== null) {
            $email = strtolower(trim((string)($contact['email'] ?? ('contact@' . $domainName))));
            $onPasswordOnce($email, $once);
        }

        $result = $sync->syncFromDatabase($onProgress, $onPasswordOnce, false, $domainId);
        $result['bind_skipped'] = !empty($bind['skipped']);
        $result['domain_id'] = $domainId;
        $result['domain_name'] = $domainName;
        $result['contact_email'] = (string)($contact['email'] ?? ('contact@' . $domainName));
        $result['engine_skipped'] = $skipEngine;

        return $result;
    }

    /**
     * @return array{success:bool,message?:string,hint?:string}
     */
    private function trySetStalwartRecoveryAdmin(string $password): array
    {
        $script = $this->resolveBindScriptPath();
        if ($script === null) {
            return [
                'success' => false,
                'message' => (string)__('缺少绑定脚本'),
                'hint' => $this->manualBindHint($password),
            ];
        }

        $attempts = [];
        // 先无提权试跑（已是 root / sudo -n 可用时）
        $attempts[] = ['bash', $script, $password];
        if (PHP_OS_FAMILY === 'Darwin') {
            // macOS 无 passwordless sudo：必须走 osascript 提权写 /opt/stalwart/etc/stalwart.env + launchctl
            $attempts[] = ['bash', $script, '--elevate', $password];
        } elseif (@is_executable('/usr/bin/sudo') || @is_executable('/bin/sudo')) {
            $attempts[] = ['sudo', '-n', 'bash', $script, $password];
        }

        $lastOut = '';
        foreach ($attempts as $cmd) {
            $escaped = array_map('escapeshellarg', $cmd);
            $line = implode(' ', $escaped) . ' 2>&1';
            $output = [];
            $code = 1;
            @exec($line, $output, $code);
            $lastOut = trim(implode("\n", $output));
            $bound = str_contains($lastOut, 'BOUND') || str_contains($lastOut, 'OK');
            $restarted = str_contains($lastOut, 'RESTARTED');
            if ($code === 0 && $bound && $restarted) {
                return ['success' => true, 'message' => $lastOut !== '' ? $lastOut : 'OK'];
            }
            // 兼容旧脚本仅 BOUND：仍当失败，强制要求 RESTARTED
            if ($code === 0 && $bound && !$restarted) {
                $lastOut .= "\n(missing RESTARTED — service may not have reloaded)";
            }
        }

        return [
            'success' => false,
            'message' => (string)__('自动设置 STALWART_RECOVERY_ADMIN 失败（须写入 stalwart.env 并重启）'),
            'hint' => $this->manualBindHint($password, $lastOut),
        ];
    }

    private function resolveBindScriptPath(): ?string
    {
        $candidates = [
            dirname(__DIR__) . '/env/script/bind_stalwart_recovery_admin.sh',
        ];
        if (defined('BP')) {
            $candidates[] = rtrim((string)BP, '/\\')
                . '/app/code/Weline/Mail/env/script/bind_stalwart_recovery_admin.sh';
        }
        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function manualBindHint(string $password, string $lastOut = ''): string
    {
        $lines = [
            (string)__('请在本机以管理员权限执行（将 <PWD> 换成下方一次性密码）：'),
            'printf \'%s\\n\' \'STALWART_RECOVERY_ADMIN=admin:<PWD>\' | sudo tee /opt/stalwart/etc/stalwart.env',
            'sudo chown _stalwart:_stalwart /opt/stalwart/etc/stalwart.env',
            'sudo launchctl kickstart -k system/stalwart   # macOS launchd',
            'sudo systemctl restart stalwart               # Linux',
            (string)__('或运行：bash app/code/Weline/Mail/env/script/bind_stalwart_recovery_admin.sh --elevate <PWD>'),
        ];
        if ($lastOut !== '') {
            $lines[] = (string)__('脚本输出：%{1}', [$lastOut]);
        }
        unset($password);

        return implode("\n", $lines);
    }

    private function detectManagementBaseUrl(): string
    {
        // 本机官方安装默认 Admin/HTTP 常在 8080；18080 可能未监听
        foreach ([8080, 18080] as $port) {
            $errno = 0;
            $errstr = '';
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.4);
            if (is_resource($fp)) {
                fclose($fp);

                return 'http://127.0.0.1:' . $port;
            }
        }

        return self::DEFAULT_BASE_URL;
    }

    private function generateManagementPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '._'), '=');
    }

    private function waitForManagementProbe(StalwartManagementAdapter $mgmt, bool $requireDomain): string
    {
        $probeError = '';
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            try {
                usleep(400_000);
                if ($requireDomain) {
                    $mgmt->probeManagementApi();
                } else {
                    $mgmt->probeManagementAuth();
                    // 认证通即可；若 Domain 已可用则更好
                    try {
                        $mgmt->probeManagementApi();
                    } catch (\Throwable $domainErr) {
                        if ($this->isBootstrapBlocked($domainErr->getMessage())) {
                            return $domainErr->getMessage();
                        }
                        throw $domainErr;
                    }
                }
                $probeError = '';
                break;
            } catch (\Throwable $e) {
                $probeError = $e->getMessage();
                if ($this->isAuthFailure($probeError)) {
                    break;
                }
            }
        }

        return $probeError;
    }

    private function isBootstrapBlocked(string $message): bool
    {
        $msg = strtolower($message);

        return str_contains($msg, 'bootstrap') || (
            str_contains($msg, 'forbidden') && str_contains($msg, 'bootstrap')
        ) || (str_contains($msg, 'forbidden') && str_contains($msg, 'only the'));
    }

    private function isAuthFailure(string $message): bool
    {
        $msg = strtolower($message);

        return str_contains($msg, '401') || str_contains($msg, '认证') || str_contains($msg, 'unauthorized');
    }
}
