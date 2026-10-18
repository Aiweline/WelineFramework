<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Mail\Service\Mailbox\StalwartMailboxClient;

class StalwartEngineAdapter implements MailEngineInterface
{
    private const LINUX_INSTALL_DIR = '/opt/stalwart';
    private const WINDOWS_INSTALL_DIR = 'C:\\Program Files\\Stalwart';

    public function getName(): string
    {
        return 'stalwart';
    }

    public function buildInstallPlan(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return [
                'platform' => 'windows',
                'install_dir' => self::WINDOWS_INSTALL_DIR,
                'steps' => [
                    __('下载 stalwart-x86_64-pc-windows-msvc.zip'),
                    __('解压 stalwart.exe 到 C:\\Program Files\\Stalwart\\bin'),
                    __('安装 NSSM 并注册 Stalwart Windows 服务'),
                    __('生成 etc\\config.json 并设置服务自启动'),
                    __('启动服务后读取 18080/admin 初始化状态'),
                ],
            ];
        }

        return [
            'platform' => strtolower(PHP_OS_FAMILY),
            'install_dir' => self::LINUX_INSTALL_DIR,
            'steps' => [
                __('下载 Stalwart Linux 二进制'),
                __('创建 /opt/stalwart/{bin,etc,data,logs}'),
                __('生成 Stalwart 配置文件'),
                __('注册 systemd 服务'),
                __('启动服务并检测 SMTP/IMAP/Admin 端口'),
            ],
        ];
    }

    public function checkEnvironment(): array
    {
        $binaryPath = $this->resolveBinaryPath();
        $binaryCheck = [
            'name' => (string)__('Stalwart 可执行文件'),
            'ok' => $binaryPath !== null,
            'detail' => $binaryPath !== null
                ? $binaryPath
                : (string)__('未找到（期望 %{1}/bin/stalwart 或 PATH）', [self::LINUX_INSTALL_DIR]),
        ];

        $checks = [
            $binaryCheck,
            $this->checkPort(25, __('SMTP 25 端口')),
            $this->checkPort(587, __('SMTP Submission 587 端口')),
            $this->checkPort(143, __('IMAP 143 端口')),
            $this->checkPort(993, __('IMAPS 993 端口')),
            $this->checkAdminHttpPort(),
        ];

        if (PHP_OS_FAMILY === 'Windows') {
            array_unshift($checks, $this->checkCommand('nssm', __('NSSM Windows 服务包装器')));
        } else {
            array_unshift($checks, $this->checkCommand('systemctl', __('systemd 服务管理')));
            $checks[] = $this->checkSystemdService();
        }

        // 引擎「已安装」以二进制为准；端口/协议面留给 DNS 与测发任务，避免装完仍永远不绿。
        return [
            'engine' => $this->getName(),
            'platform' => PHP_OS_FAMILY,
            'ok' => $binaryCheck['ok'],
            'checks' => $checks,
            'plan' => $this->buildInstallPlan(),
            'binary' => $binaryPath,
        ];
    }

    public function install(bool $yes = false): array
    {
        if (!$yes) {
            return [
                'ok' => false,
                'dry_run' => true,
                'message' => __('未执行真实安装。确认后运行：php bin/w mail:env:install -y（或系统安装/环境修复时的 env:install stalwart-mail-server -y）'),
                'plan' => $this->buildInstallPlan(),
            ];
        }

        if ($this->resolveBinaryPath() !== null) {
            return [
                'ok' => true,
                'message' => __('Stalwart 已安装：%{1}', [$this->resolveBinaryPath()]),
                'plan' => $this->buildInstallPlan(),
                'binary' => $this->resolveBinaryPath(),
            ];
        }

        $script = PHP_OS_FAMILY === 'Windows'
            ? BP . 'app/code/Weline/Mail/env/script/install_stalwart_windows.ps1'
            : BP . 'app/code/Weline/Mail/env/script/install_stalwart_linux.sh';

        if (!is_file($script)) {
            return [
                'ok' => false,
                'message' => __('安装脚本不存在：%{1}', [$script]),
            ];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -File '
                . escapeshellarg($script) . ' -Action install';
        } else {
            $cmd = 'bash ' . escapeshellarg($script) . ' install';
        }

        $result = $this->runCommand($cmd);
        $binary = $this->resolveBinaryPath();
        $ok = $result['ok'] && $binary !== null;

        return [
            'ok' => $ok,
            'message' => $ok
                ? (string)__('Stalwart 安装成功：%{1}', [$binary])
                : (string)__('Stalwart 安装失败：%{1}', [
                    $result['error'] !== '' ? $result['error'] : ($result['output'] ?: __('脚本退出非零')),
                ]),
            'script' => $script,
            'plan' => $this->buildInstallPlan(),
            'output' => $result['output'],
            'binary' => $binary,
            'exit_code' => $result['exit_code'] ?? null,
        ];
    }

    public function service(string $action): array
    {
        $allowed = ['start', 'stop', 'restart', 'status'];
        if (!in_array($action, $allowed, true)) {
            return ['ok' => false, 'message' => __('不支持的服务动作：%{1}', [$action])];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cmd = match ($action) {
                'start' => 'powershell -NoProfile -Command "Start-Service Stalwart"',
                'stop' => 'powershell -NoProfile -Command "Stop-Service Stalwart"',
                'restart' => 'powershell -NoProfile -Command "Restart-Service Stalwart"',
                default => 'powershell -NoProfile -Command "Get-Service Stalwart"',
            };
        } else {
            $cmd = 'systemctl ' . escapeshellarg($action) . ' stalwart';
        }

        return $this->runCommand($cmd);
    }

    public function clientSettings(string $domain, string $hostname): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $smtpHost = $domain !== '' ? ('smtp.' . $domain) : $hostname;

        return [
            'domain' => $domain,
            'hostname' => $hostname,
            'smtp' => [
                'host' => $smtpHost !== '' ? $smtpHost : $hostname,
                'port' => 587,
                'security' => 'STARTTLS',
                'auth' => true,
            ],
            'imap' => [
                'host' => $hostname,
                'port' => 993,
                'security' => 'TLS',
                'auth' => true,
            ],
            'pop3' => [
                'host' => $hostname,
                'port' => 995,
                'security' => 'TLS',
                'auth' => true,
            ],
        ];
    }

    private const READER_KEY_FILE = '/etc/weline/mail-reader.key';
    private const READER_CREDENTIAL_FILE = '/etc/weline/mail-reader.credential';
    private const ETC_READER_KEY_FILE = '/etc/weline/mail-reader.key';
    private const ETC_READER_CREDENTIAL_FILE = '/etc/weline/mail-reader.credential';
    private const VAR_READER_KEY_REL = 'var/mail/mail-reader.key';
    private const VAR_READER_CREDENTIAL_REL = 'var/mail/mail-reader.credential';
    private const DEFAULT_JMAP_BASE_URL = 'http://127.0.0.1:18080';
    private const JMAP_CORE_CAPABILITY = 'urn:ietf:params:jmap:core';
    private const JMAP_MAIL_CAPABILITY = 'urn:ietf:params:jmap:mail';

    public function hasReaderCredential(): bool
    {
        return $this->resolveReadableReaderCredentialPair() !== null;
    }

    public function createReaderCredentialPayload(
        string $username,
        string $password,
        string $baseUrl = self::DEFAULT_JMAP_BASE_URL,
        ?string $key = null
    ): string {
        $username = strtolower(trim($username));
        if (!$this->isValidReaderUsername($username)) {
            throw new \InvalidArgumentException('邮件读取账号格式无效。');
        }
        if (strlen($password) < 16) {
            throw new \InvalidArgumentException('邮件读取密码至少需要 16 位。');
        }

        $baseUrl = $this->normalizeLoopbackBaseUrl($baseUrl);
        $key ??= $this->ensureReaderKeyAt($this->resolveWritableReaderCredentialPair()['key']);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = $nonce . sodium_crypto_secretbox($password, $nonce, $key);

        return json_encode([
            'version' => 1,
            'username' => $username,
            'base_url' => $baseUrl,
            'sealed_secret' => base64_encode($sealed),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 写入本机 mail-reader 凭据（/etc 与 var/mail 双路径；供按库同步尾步从 contact@ 密封补齐）。
     */
    public function upsertReaderCredential(
        string $username,
        string $password,
        string $baseUrl = self::DEFAULT_JMAP_BASE_URL
    ): void {
        $pair = $this->resolveWritableReaderCredentialPair();
        $key = $this->ensureReaderKeyAt($pair['key']);
        $payload = $this->createReaderCredentialPayload($username, $password, $baseUrl, $key);
        $dir = dirname($pair['credential']);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建邮件读取凭据目录。');
        }
        if (@file_put_contents($pair['credential'], $payload) === false) {
            throw new \RuntimeException('无法写入邮件读取凭据。');
        }
        @chmod($pair['credential'], 0600);
        @chmod(dirname($pair['credential']), 0750);
        @chmod($pair['key'], 0600);
    }

    private function ensureReaderKeyAt(string $keyFile): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }
        if (is_readable($keyFile)) {
            $raw = file_get_contents($keyFile);
            $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
            if (is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        }
        $dir = dirname($keyFile);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建邮件读取密钥目录。');
        }
        $generated = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        if (@file_put_contents($keyFile, base64_encode($generated)) === false) {
            throw new \RuntimeException('无法写入邮件读取密钥。');
        }
        @chmod($keyFile, 0600);

        return $generated;
    }

    private function projectBasePath(): string
    {
        $bp = defined('BP') ? (string)BP : '';
        $bp = rtrim($bp, '/\\');
        if ($bp !== '') {
            $real = realpath($bp);
            if (is_string($real) && $real !== '') {
                return $real;
            }

            return $bp;
        }
        $fallback = dirname(__DIR__, 5);
        $real = realpath($fallback);

        return is_string($real) && $real !== '' ? $real : rtrim($fallback, '/\\');
    }

    /**
     * @return list<array{key:string,credential:string}>
     */
    private function readerCredentialPathCandidates(): array
    {
        $bp = $this->projectBasePath();

        return [
            ['key' => self::ETC_READER_KEY_FILE, 'credential' => self::ETC_READER_CREDENTIAL_FILE],
            [
                'key' => $bp . '/' . self::VAR_READER_KEY_REL,
                'credential' => $bp . '/' . self::VAR_READER_CREDENTIAL_REL,
            ],
            ['key' => self::READER_KEY_FILE, 'credential' => self::READER_CREDENTIAL_FILE],
        ];
    }

    /**
     * @return array{key:string,credential:string}|null
     */
    private function resolveReadableReaderCredentialPair(): ?array
    {
        $seen = [];
        foreach ($this->readerCredentialPathCandidates() as $pair) {
            $sig = $pair['key'] . "\0" . $pair['credential'];
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            if (is_readable($pair['key']) && is_readable($pair['credential'])) {
                return $pair;
            }
        }

        return null;
    }

    /**
     * @return array{key:string,credential:string}
     */
    private function resolveWritableReaderCredentialPair(): array
    {
        $etcDir = dirname(self::ETC_READER_KEY_FILE);
        if ($this->directoryIsWritableOrCreatable($etcDir)) {
            return [
                'key' => self::ETC_READER_KEY_FILE,
                'credential' => self::ETC_READER_CREDENTIAL_FILE,
            ];
        }
        $bp = $this->projectBasePath();
        $varKey = $bp . '/' . self::VAR_READER_KEY_REL;
        $varCred = $bp . '/' . self::VAR_READER_CREDENTIAL_REL;
        $varDir = dirname($varKey);
        if (!$this->directoryIsWritableOrCreatable($varDir)) {
            throw new \RuntimeException(
                '无法写入邮件读取凭据（/etc/weline 与 ' . $varDir . ' 均不可写；Web/CLI 须同用户可读 BP/var/mail）。'
            );
        }

        return ['key' => $varKey, 'credential' => $varCred];
    }

    private function directoryIsWritableOrCreatable(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }
        $parent = dirname($dir);
        if (!is_dir($parent) || !is_writable($parent)) {
            return false;
        }

        return @mkdir($dir, 0700, true) || is_dir($dir);
    }

    /**
     * @deprecated Use MailboxOpsService / StalwartMailboxClient
     * @return list<array<string, mixed>>
     */
    public function listInboxMessages(string $mailbox, int $limit = 50): array
    {
        return $this->mailboxClient()->listMessages($mailbox, 'inbox', $limit);
    }

    /**
     * @deprecated Use MailboxOpsService / StalwartMailboxClient
     */
    public function getInboxMessage(string $mailbox, string $messageId): ?array
    {
        return $this->mailboxClient()->getMessage($mailbox, $messageId);
    }

    /**
     * @deprecated Use MailboxOpsService / StalwartMailboxClient
     * @return list<array<string, mixed>>
     */
    public function listMailboxMessages(string $mailbox, string $folder = 'inbox', int $limit = 50): array
    {
        return $this->mailboxClient()->listMessages($mailbox, $folder, $limit);
    }

    /**
     * @deprecated Use MailboxOpsService / StalwartMailboxClient
     */
    public function getMailboxMessage(string $mailbox, string $messageId): ?array
    {
        return $this->mailboxClient()->getMessage($mailbox, $messageId);
    }

    /**
     * @deprecated Use MailboxOpsService / StalwartMailboxClient
     */
    public function sendMessage(
        string $mailbox,
        string|array $to,
        string $subject,
        string $body
    ): array {
        return $this->mailboxClient()->send($mailbox, $to, $subject, $body);
    }

    private function mailboxClient(): StalwartMailboxClient
    {
        return ObjectManager::getInstance(StalwartMailboxClient::class);
    }

    /**
     * @return list<string>
     */
    private function normalizeSendRecipients(string|array $to): array
    {
        $values = is_array($to) ? $to : (preg_split('/[\s,;]+/', $to) ?: []);
        $result = [];
        foreach ($values as $value) {
            $email = strtolower(trim((string)$value));
            if ($email === '') {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('收件邮箱格式不正确。');
            }
            $result[$email] = $email;
        }
        return array_values($result);
    }

    private function findMailboxIdByRole(array $context, string $role): string
    {
        $result = $this->jmapCall($context, 'Mailbox/query', [
            'accountId' => $context['account_id'],
            'filter' => ['role' => $role],
            'limit' => 1,
        ]);
        $mailboxId = (string)($result['ids'][0] ?? '');
        if ($mailboxId === '') {
            throw new \RuntimeException('目标邮箱缺少 ' . $role . ' 邮件夹。');
        }
        return $mailboxId;
    }

    /**
     * @param list<string> $capabilities
     * @return array<string, mixed>
     */
    private function jmapCallWithCapabilities(
        array $context,
        string $method,
        array $arguments,
        array $capabilities
    ): array {
        $response = $this->requestJson(
            $context['api_url'],
            $context['username'],
            $context['password'],
            [
                'using' => $capabilities,
                'methodCalls' => [[$method, $arguments, 'mail-operation']],
            ]
        );
        $methodResponse = $response['methodResponses'][0] ?? null;
        if (!is_array($methodResponse) || !isset($methodResponse[0], $methodResponse[1])) {
            throw new \RuntimeException('JMAP 返回格式无效。');
        }
        if ((string)$methodResponse[0] === 'error') {
            $type = is_array($methodResponse[1]) ? (string)($methodResponse[1]['type'] ?? '') : '';
            throw new \RuntimeException('JMAP 邮件操作失败' . ($type !== '' ? '：' . $type : '。'));
        }
        if ((string)$methodResponse[0] !== $method || !is_array($methodResponse[1])) {
            throw new \RuntimeException('JMAP 邮件操作响应不匹配。');
        }
        return $methodResponse[1];
    }

    /**
     * @return array{username:string,password:string,base_url:string}
     */
    private function readerCredential(): array
    {
        $pair = $this->resolveReadableReaderCredentialPair();
        if ($pair === null) {
            throw new \RuntimeException('邮件读取凭据尚未配置。');
        }

        $raw = file_get_contents($pair['credential']);
        if (!is_string($raw) || trim($raw) === '') {
            throw new \RuntimeException('邮件读取凭据不可用。');
        }

        try {
            $credential = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('邮件读取凭据格式无效。');
        }
        if (!is_array($credential)) {
            throw new \RuntimeException('邮件读取凭据格式无效。');
        }

        $username = strtolower(trim((string)($credential['username'] ?? '')));
        $baseUrl = $this->normalizeLoopbackBaseUrl((string)($credential['base_url'] ?? ''));
        $sealed = base64_decode((string)($credential['sealed_secret'] ?? ''), true);
        // Stalwart 管理账号可为短名（如 admin）；JMAP 冒充形如 mailbox%admin。
        if (!$this->isValidReaderUsername($username)
            || !is_string($sealed)
            || strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ) {
            throw new \RuntimeException('邮件读取凭据内容无效。');
        }

        $nonce = substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $password = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->readerKeyAt($pair['key']));
        if (!is_string($password) || $password === '') {
            throw new \RuntimeException('邮件读取凭据无法解密。');
        }

        return ['username' => $username, 'password' => $password, 'base_url' => $baseUrl];
    }

    /**
     * 读取代理账号：完整邮箱，或 Stalwart 短名（字母数字._-，用于 mailbox%admin 冒充）。
     */
    private function isValidReaderUsername(string $username): bool
    {
        if ($username === '') {
            return false;
        }
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        return (bool)preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $username);
    }

    private function readerKeyAt(string $keyFile): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }

        $raw = file_get_contents($keyFile);
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('邮件读取密钥无效。');
        }

        return $key;
    }

    private function normalizeLoopbackBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            throw new \RuntimeException('邮件读取服务地址无效。');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string)($parts['path'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)
            || !in_array($host, ['127.0.0.1', '::1'], true)
            || $port < 1
            || $port > 65535
            || ($path !== '' && $path !== '/')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('邮件读取服务必须使用本机回环地址。');
        }

        $displayHost = $host === '::1' ? '[::1]' : $host;
        return $scheme . '://' . $displayHost . ':' . $port;
    }

    /**
     * @return array{username:string,password:string,api_url:string,account_id:string}
     */
    private function openMailboxSession(string $mailbox): array
    {
        $mailbox = strtolower(trim($mailbox));
        if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('邮箱地址格式无效。');
        }

        $credential = $this->readerCredential();
        $authUsername = $mailbox . '%' . $credential['username'];
        $session = $this->requestJson(
            $credential['base_url'] . '/jmap/session',
            $authUsername,
            $credential['password']
        );

        $accountId = (string)($session['primaryAccounts'][self::JMAP_MAIL_CAPABILITY] ?? '');
        if ($accountId === '') {
            foreach ((array)($session['accounts'] ?? []) as $candidateId => $account) {
                if (is_array($account)
                    && isset($account['accountCapabilities'][self::JMAP_MAIL_CAPABILITY])
                ) {
                    $accountId = (string)$candidateId;
                    break;
                }
            }
        }
        if ($accountId === '') {
            throw new \RuntimeException('目标邮箱未启用 JMAP 邮件能力。');
        }

        $apiPath = (string)(parse_url((string)($session['apiUrl'] ?? '/jmap'), PHP_URL_PATH) ?: '/jmap');
        if ($apiPath !== '/jmap' && !str_starts_with($apiPath, '/jmap/')) {
            throw new \RuntimeException('JMAP 服务端点无效。');
        }

        return [
            'username' => $authUsername,
            'password' => $credential['password'],
            'api_url' => $credential['base_url'] . $apiPath,
            'account_id' => $accountId,
        ];
    }

    /**
     * @param array{username:string,password:string,api_url:string,account_id:string} $context
     * @return array<string, mixed>
     */
    private function jmapCall(array $context, string $method, array $arguments): array
    {
        $response = $this->requestJson(
            $context['api_url'],
            $context['username'],
            $context['password'],
            [
                'using' => [self::JMAP_CORE_CAPABILITY, self::JMAP_MAIL_CAPABILITY],
                'methodCalls' => [[$method, $arguments, 'mail']],
            ]
        );
        $methodResponse = $response['methodResponses'][0] ?? null;
        if (!is_array($methodResponse) || !isset($methodResponse[0], $methodResponse[1])) {
            throw new \RuntimeException('JMAP 返回格式无效。');
        }
        if ((string)$methodResponse[0] === 'error') {
            $type = is_array($methodResponse[1]) ? (string)($methodResponse[1]['type'] ?? '') : '';
            throw new \RuntimeException('JMAP 邮件读取失败' . ($type !== '' ? '：' . $type : '。'));
        }
        if ((string)$methodResponse[0] !== $method || !is_array($methodResponse[1])) {
            throw new \RuntimeException('JMAP 邮件读取响应不匹配。');
        }

        return $methodResponse[1];
    }

    /**
     * @param array{username:string,password:string,api_url:string,account_id:string} $context
     */
    private function findInboxId(array $context): string
    {
        $result = $this->jmapCall($context, 'Mailbox/query', [
            'accountId' => $context['account_id'],
            'filter' => ['role' => 'inbox'],
            'limit' => 1,
        ]);
        $inboxId = (string)($result['ids'][0] ?? '');
        if ($inboxId === '') {
            throw new \RuntimeException('目标邮箱没有可用的收件箱。');
        }
        return $inboxId;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(
        string $url,
        string $username,
        string $password,
        ?array $payload = null
    ): array {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!is_array($parts)
            || !in_array($scheme, ['http', 'https'], true)
            || !in_array($host, ['127.0.0.1', '::1'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \RuntimeException('JMAP 请求被限制为本机回环地址。');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL 扩展不可用。');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('无法初始化 JMAP 请求。');
        }
        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $encoded;
            $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/json'];
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('邮件服务认证或读取失败（HTTP ' . $status . '）。');
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('邮件服务返回了无效数据。');
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('邮件服务返回格式无效。');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function normalizeMessage(array $message, bool $includeBody): array
    {
        $keywords = is_array($message['keywords'] ?? null) ? $message['keywords'] : [];
        $subject = trim((string)($message['subject'] ?? ''));
        $preview = $this->limitText(trim((string)($message['preview'] ?? '')), 500);

        $presentation = \Weline\Mail\Service\Mailbox\MailBodyPresentation::fromJmapMessage($message, $includeBody);

        return [
            'id' => (string)($message['id'] ?? ''),
            'thread_id' => (string)($message['threadId'] ?? ''),
            'subject' => $subject !== '' ? $subject : (string)__('（无主题）'),
            'from' => $this->formatAddresses($message['from'] ?? []),
            'to' => $this->formatAddresses($message['to'] ?? []),
            'cc' => $this->formatAddresses($message['cc'] ?? []),
            'bcc' => $this->formatAddresses($message['bcc'] ?? []),
            'received_at' => (string)($message['receivedAt'] ?? $message['sentAt'] ?? ''),
            'preview' => $preview,
            'body' => $presentation['body'],
            'html_body' => $presentation['html_body'],
            'body_is_html' => $presentation['body_is_html'],
            'has_attachment' => !empty($message['hasAttachment']),
            'size' => (int)($message['size'] ?? 0),
            'unread' => !isset($keywords['$seen']),
        ];
    }

    private function formatAddresses(mixed $addresses): string
    {
        $formatted = [];
        foreach ((array)$addresses as $address) {
            if (!is_array($address)) {
                continue;
            }
            $email = trim((string)($address['email'] ?? ''));
            $name = trim((string)($address['name'] ?? ''));
            if ($email === '') {
                continue;
            }
            $formatted[] = $name !== '' ? $name . ' <' . $email . '>' : $email;
        }
        return implode(', ', $formatted);
    }

    private function limitText(string $text, int $limit): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{4,}/u", "\n\n\n", $text) ?? $text;
        $text = trim($text);
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }
        return substr($text, 0, $limit);
    }

    private function binaryName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'stalwart.exe' : 'stalwart';
    }

    private function resolveBinaryPath(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $candidates = [
                self::WINDOWS_INSTALL_DIR . '\\bin\\stalwart.exe',
                'C:\\Program Files\\Stalwart\\bin\\stalwart.exe',
            ];
            foreach ($candidates as $path) {
                if (is_file($path)) {
                    return $path;
                }
            }
            $where = $this->runCommand('where stalwart.exe');
            if ($where['ok'] && $where['output'] !== '') {
                $first = preg_split('/\r\n|\n/', $where['output'])[0] ?? '';
                return $first !== '' ? $first : null;
            }
            return null;
        }

        $candidates = [
            self::LINUX_INSTALL_DIR . '/bin/stalwart',
            '/usr/local/bin/stalwart',
            '/usr/bin/stalwart',
        ];
        foreach ($candidates as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        $which = $this->runCommand('command -v stalwart');
        if ($which['ok'] && $which['output'] !== '') {
            return trim($which['output']);
        }
        return null;
    }

    private function checkAdminHttpPort(): array
    {
        $ports = [8080, 18080];
        foreach ($ports as $port) {
            $check = $this->checkPort($port, __('Stalwart Admin/HTTP %{1} 端口', [$port]));
            if ($check['ok']) {
                return $check;
            }
        }

        return [
            'name' => (string)__('Stalwart Admin/HTTP（8080 或 18080）'),
            'ok' => false,
            'detail' => (string)__('127.0.0.1:8080/18080 均不可连接（官方安装默认 bootstrap 为 8080）'),
        ];
    }

    private function checkSystemdService(): array
    {
        $active = $this->runCommand('systemctl is-active stalwart.service');
        if (!$active['ok']) {
            $active = $this->runCommand('systemctl is-active stalwart');
        }

        return [
            'name' => (string)__('systemd 单元 stalwart'),
            'ok' => $active['ok'] && trim($active['output']) === 'active',
            'detail' => $active['output'] !== '' ? $active['output'] : ($active['error'] ?: 'inactive'),
        ];
    }

    private function checkCommand(string $command, string $label): array
    {
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? 'where ' . escapeshellarg($command)
            : 'command -v ' . escapeshellarg($command);
        $result = $this->runCommand($cmd);

        return [
            'name' => $label,
            'ok' => $result['ok'],
            'detail' => $result['output'] ?: $result['error'],
        ];
    }

    private function checkPort(int $port, string $label): array
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1.0);
        if (is_resource($connection)) {
            fclose($connection);
            return ['name' => $label, 'ok' => true, 'detail' => __('127.0.0.1:%{1} 可连接', [$port])];
        }

        return ['name' => $label, 'ok' => false, 'detail' => trim($errstr . ' #' . $errno)];
    }

    private function runCommand(string $command): array
    {
        $output = [];
        @exec($command . ' 2>&1', $output, $code);

        return [
            'ok' => $code === 0,
            'exit_code' => $code,
            'command' => $command,
            'output' => trim(implode("\n", $output)),
            'error' => $code === 0 ? '' : trim(implode("\n", $output)),
        ];
    }
}
