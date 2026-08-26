<?php
declare(strict_types=1);

namespace Weline\Mail\Service;

final class StalwartManagementAdapter
{
    private const KEY_FILE = '/etc/weline/mail-admin.key';
    private const CREDENTIAL_FILE = '/etc/weline/mail-admin.credential';
    private const DEFAULT_BASE_URL = 'http://127.0.0.1:18080';
    private const CORE_CAPABILITY = 'urn:ietf:params:jmap:core';
    private const MANAGEMENT_CAPABILITY = 'urn:stalwart:jmap';

    public function hasManagementCredential(): bool
    {
        return is_readable(self::KEY_FILE) && is_readable(self::CREDENTIAL_FILE);
    }

    public function createManagementCredentialPayload(
        string $username,
        string $password,
        string $baseUrl = self::DEFAULT_BASE_URL
    ): string {
        $username = trim($username);
        if ($username === '' || strlen($username) > 190) {
            throw new \InvalidArgumentException('Stalwart 管理账号无效。');
        }
        if (strlen($password) < 16) {
            throw new \InvalidArgumentException('Stalwart 管理密码至少需要 16 位。');
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed = $nonce . sodium_crypto_secretbox($password, $nonce, $this->managementKey());

        return json_encode([
            'version' => 1,
            'username' => $username,
            'base_url' => $this->normalizeLoopbackBaseUrl($baseUrl),
            'sealed_secret' => base64_encode($sealed),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUserObject(
        string $localPart,
        string $domainId,
        string $password,
        int $quotaMb
    ): array {
        $localPart = strtolower(trim($localPart));
        $domainId = trim($domainId);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $localPart)) {
            throw new \InvalidArgumentException('邮箱账号名称格式无效。');
        }
        if ($domainId === '') {
            throw new \InvalidArgumentException('Stalwart 邮箱域名标识无效。');
        }
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('邮箱密码至少需要 12 位。');
        }
        $quotaMb = max(128, min(1048576, $quotaMb));

        return [
            '@type' => 'User',
            'name' => $localPart,
            'domainId' => $domainId,
            'credentials' => (object)[
                '0' => ['@type' => 'Password', 'secret' => $password],
            ],
            'memberGroupIds' => (object)[],
            'roles' => ['@type' => 'User'],
            'permissions' => ['@type' => 'Inherit'],
            'quotas' => (object)['maxDiskQuota' => $quotaMb * 1024 * 1024],
            'aliases' => (object)[],
            'encryptionAtRest' => ['@type' => 'Disabled'],
        ];
    }

    public function accountExists(string $mailbox): bool
    {
        [$localPart, $domainName] = $this->splitMailbox($mailbox);
        $domainId = $this->domainId($domainName);
        return $this->accountId($localPart, $domainId) !== null;
    }

    public function provisionAccount(string $mailbox, string $password, int $quotaMb): string
    {
        [$localPart, $domainName] = $this->splitMailbox($mailbox);
        $domainId = $this->domainId($domainName);
        if ($this->accountId($localPart, $domainId) !== null) {
            throw new \DomainException('该邮箱已存在于 Stalwart。');
        }

        $result = $this->managementCall('x:Account/set', [
            'create' => [
                'mailbox' => $this->buildUserObject($localPart, $domainId, $password, $quotaMb),
            ],
        ]);
        $id = (string)($result['created']['mailbox']['id'] ?? '');
        if ($id === '') {
            $type = (string)($result['notCreated']['mailbox']['type'] ?? '');
            throw new \RuntimeException('Stalwart 邮箱开通失败' . ($type !== '' ? '：' . $type : '。'));
        }
        return $id;
    }

    public function provisionOrResetAccount(string $mailbox, string $password, int $quotaMb): string
    {
        [$localPart, $domainName] = $this->splitMailbox($mailbox);
        $domainId = $this->domainId($domainName);
        $accountId = $this->accountId($localPart, $domainId);
        if ($accountId === null) {
            return $this->provisionAccount($mailbox, $password, $quotaMb);
        }

        $object = $this->buildUserObject($localPart, $domainId, $password, $quotaMb);
        $result = $this->managementCall('x:Account/set', [
            'update' => [
                $accountId => [
                    'credentials' => $object['credentials'],
                    'quotas' => $object['quotas'],
                ],
            ],
        ]);
        if (isset($result['notUpdated'][$accountId])) {
            $type = (string)($result['notUpdated'][$accountId]['type'] ?? '');
            throw new \RuntimeException('Stalwart 邮箱密码重置失败' . ($type !== '' ? '：' . $type : '。'));
        }
        return $accountId;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitMailbox(string $mailbox): array
    {
        $mailbox = strtolower(trim($mailbox));
        if (!filter_var($mailbox, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('邮箱地址格式不正确。');
        }
        [$localPart, $domain] = explode('@', $mailbox, 2);
        return [$localPart, $domain];
    }

    private function domainId(string $domainName): string
    {
        $result = $this->managementCall('x:Domain/query', [
            'filter' => ['name' => strtolower(trim($domainName))],
            'limit' => 1,
        ]);
        $domainId = (string)($result['ids'][0] ?? '');
        if ($domainId === '') {
            throw new \DomainException('该域名尚未在 Stalwart 中开通。');
        }
        return $domainId;
    }

    private function accountId(string $localPart, string $domainId): ?string
    {
        $result = $this->managementCall('x:Account/query', [
            'filter' => ['name' => $localPart, 'domainId' => $domainId],
            'limit' => 1,
        ]);
        $id = (string)($result['ids'][0] ?? '');
        return $id !== '' ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function managementCall(string $method, array $arguments): array
    {
        $credential = $this->managementCredential();
        $response = $this->requestJson(
            $credential['base_url'] . '/jmap/',
            $credential['username'],
            $credential['password'],
            [
                'using' => [self::CORE_CAPABILITY, self::MANAGEMENT_CAPABILITY],
                'methodCalls' => [[$method, $arguments, 'management']],
            ]
        );
        $methodResponse = $response['methodResponses'][0] ?? null;
        if (!is_array($methodResponse) || !isset($methodResponse[0], $methodResponse[1])) {
            throw new \RuntimeException('Stalwart 管理接口返回格式无效。');
        }
        if ((string)$methodResponse[0] === 'error') {
            $type = is_array($methodResponse[1]) ? (string)($methodResponse[1]['type'] ?? '') : '';
            throw new \RuntimeException('Stalwart 管理操作失败' . ($type !== '' ? '：' . $type : '。'));
        }
        if ((string)$methodResponse[0] !== $method || !is_array($methodResponse[1])) {
            throw new \RuntimeException('Stalwart 管理接口响应不匹配。');
        }
        return $methodResponse[1];
    }

    /**
     * @return array{username:string,password:string,base_url:string}
     */
    private function managementCredential(): array
    {
        if (!$this->hasManagementCredential()) {
            throw new \RuntimeException('Stalwart 管理凭据尚未配置。');
        }
        $raw = file_get_contents(self::CREDENTIAL_FILE);
        try {
            $credential = json_decode((string)$raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Stalwart 管理凭据格式无效。');
        }
        $username = trim((string)($credential['username'] ?? ''));
        $sealed = base64_decode((string)($credential['sealed_secret'] ?? ''), true);
        if ($username === '' || !is_string($sealed) || strlen($sealed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stalwart 管理凭据内容无效。');
        }
        $nonce = substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $password = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->managementKey());
        if (!is_string($password) || $password === '') {
            throw new \RuntimeException('Stalwart 管理凭据无法解密。');
        }
        return [
            'username' => $username,
            'password' => $password,
            'base_url' => $this->normalizeLoopbackBaseUrl((string)($credential['base_url'] ?? '')),
        ];
    }

    private function managementKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }
        $raw = file_get_contents(self::KEY_FILE);
        $key = is_string($raw) ? base64_decode(trim($raw), true) : false;
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Stalwart 管理密钥无效。');
        }
        return $key;
    }

    private function normalizeLoopbackBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts)) {
            throw new \RuntimeException('Stalwart 管理地址无效。');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $path = (string)($parts['path'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)
            || !in_array($host, ['127.0.0.1', '::1'], true)
            || $port < 1 || $port > 65535
            || ($path !== '' && $path !== '/')
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new \RuntimeException('Stalwart 管理地址必须使用本机回环地址。');
        }
        $displayHost = $host === '::1' ? '[::1]' : $host;
        return $scheme . '://' . $displayHost . ':' . $port;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $url, string $username, string $password, array $payload): array
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!is_array($parts)
            || !in_array($host, ['127.0.0.1', '::1'], true)
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            throw new \RuntimeException('Stalwart 管理请求被限制为本机回环地址。');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL 扩展不可用。');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('无法初始化 Stalwart 管理请求。');
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Stalwart 管理认证或请求失败（HTTP ' . $status . '）。');
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Stalwart 管理接口返回了无效数据。');
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Stalwart 管理接口返回格式无效。');
        }
        return $decoded;
    }
}
