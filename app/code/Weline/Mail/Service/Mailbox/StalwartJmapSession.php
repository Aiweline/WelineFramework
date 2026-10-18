<?php
declare(strict_types=1);

namespace Weline\Mail\Service\Mailbox;

/**
 * Shared Stalwart JMAP session + HTTP helpers for mailbox clients.
 */
final class StalwartJmapSession
{
    private const READER_KEY_FILE = '/etc/weline/mail-reader.key';
    private const READER_CREDENTIAL_FILE = '/etc/weline/mail-reader.credential';
    private const DEFAULT_JMAP_BASE_URL = 'http://127.0.0.1:18080';
    public const JMAP_CORE_CAPABILITY = 'urn:ietf:params:jmap:core';
    public const JMAP_MAIL_CAPABILITY = 'urn:ietf:params:jmap:mail';
    public const JMAP_SUBMISSION_CAPABILITY = 'urn:ietf:params:jmap:submission';

    public const MAX_ATTACHMENT_BYTES = 26214400;

    public function hasReaderCredential(): bool
    {
        return is_readable(self::READER_KEY_FILE) && is_readable(self::READER_CREDENTIAL_FILE);
    }

    /**
     * @return array{username:string,password:string,api_url:string,account_id:string,base_url:string}
     */
    public function openMailboxSession(string $mailbox): array
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
            'base_url' => $credential['base_url'],
        ];
    }

    /**
     * @param array{username:string,password:string,api_url:string,account_id:string} $context
     * @return array<string, mixed>
     */
    public function jmapCall(array $context, string $method, array $arguments): array
    {
        return $this->jmapCallWithCapabilities(
            $context,
            $method,
            $arguments,
            [self::JMAP_CORE_CAPABILITY, self::JMAP_MAIL_CAPABILITY]
        );
    }

    /**
     * @param array{username:string,password:string,api_url:string,account_id:string} $context
     * @param list<string> $capabilities
     * @return array<string, mixed>
     */
    public function jmapCallWithCapabilities(
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
     * @param array{username:string,password:string,api_url:string,account_id:string} $context
     */
    public function findMailboxIdByRole(array $context, string $role): string
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
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public function normalizeMessage(array $message, bool $includeBody, bool $includeAttachments = false): array
    {
        $keywords = is_array($message['keywords'] ?? null) ? $message['keywords'] : [];
        $subject = trim((string)($message['subject'] ?? ''));
        $preview = $this->limitText(trim((string)($message['preview'] ?? '')), 500);
        $presentation = MailBodyPresentation::fromJmapMessage($message, $includeBody);
        $out = [
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
            'attachments' => [],
        ];
        if ($includeAttachments) {
            $out['attachments'] = $this->extractAttachments($message);
            $out['has_attachment'] = $out['has_attachment'] || $out['attachments'] !== [];
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public function normalizeEmailList(string|array $emails): array
    {
        $values = is_array($emails) ? $emails : (preg_split('/[\s,;]+/', (string)$emails) ?: []);
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

    /**
     * Download raw blob via downloadUrl pattern or /jmap/download.
     *
     * @param array{username:string,password:string,api_url:string,account_id:string,base_url:string} $context
     */
    public function downloadBlobBinary(array $context, string $blobId): string
    {
        $blobId = trim($blobId);
        if ($blobId === '' || strlen($blobId) > 512) {
            throw new \InvalidArgumentException('附件标识无效。');
        }
        $url = rtrim($context['base_url'], '/') . '/jmap/download/'
            . rawurlencode($context['account_id']) . '/' . rawurlencode($blobId)
            . '?accept=' . rawurlencode('application/octet-stream');
        return $this->requestBinary($url, $context['username'], $context['password']);
    }

    /**
     * @param array{username:string,password:string,api_url:string,account_id:string,base_url:string} $context
     * @return array{blobId:string,type:string,size:int}
     */
    public function uploadBlobBinary(array $context, string $type, string $binary): array
    {
        $size = strlen($binary);
        if ($size <= 0 || $size > self::MAX_ATTACHMENT_BYTES) {
            throw new \InvalidArgumentException('附件大小无效或超过限制。');
        }
        $type = trim($type) !== '' ? trim($type) : 'application/octet-stream';
        $url = rtrim($context['base_url'], '/') . '/jmap/upload/' . rawurlencode($context['account_id']) . '/';
        $decoded = $this->requestRaw(
            $url,
            $context['username'],
            $context['password'],
            $binary,
            $type
        );
        $blobId = (string)($decoded['blobId'] ?? '');
        if ($blobId === '') {
            throw new \RuntimeException('附件上传失败。');
        }
        return [
            'blobId' => $blobId,
            'type' => (string)($decoded['type'] ?? $type),
            'size' => (int)($decoded['size'] ?? $size),
        ];
    }

    /**
     * @return array{username:string,password:string,base_url:string}
     */
    private function readerCredential(): array
    {
        if (!$this->hasReaderCredential()) {
            throw new \RuntimeException('邮件读取凭据尚未配置。');
        }
        $raw = file_get_contents(self::READER_CREDENTIAL_FILE);
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
        $baseUrl = $this->normalizeLoopbackBaseUrl((string)($credential['base_url'] ?? self::DEFAULT_JMAP_BASE_URL));
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
        $password = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->readerKey());
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

    private function readerKey(): string
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \RuntimeException('PHP Sodium 扩展不可用。');
        }
        $raw = file_get_contents(self::READER_KEY_FILE);
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
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
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
     * @return array<string, mixed>
     */
    private function requestJson(
        string $url,
        string $username,
        string $password,
        ?array $payload = null
    ): array {
        $body = $this->requestRaw(
            $url,
            $username,
            $password,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $payload === null ? null : 'application/json'
        );
        return $body;
    }

    private function requestBinary(string $url, string $username, string $password): string
    {
        $this->assertLoopbackUrl($url);
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL 扩展不可用。');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('无法初始化附件下载。');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPHEADER => ['Accept: application/octet-stream'],
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new \RuntimeException('附件下载失败（HTTP ' . $status . '）。');
        }
        if (strlen($body) > self::MAX_ATTACHMENT_BYTES) {
            throw new \RuntimeException('附件超过大小限制。');
        }
        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestRaw(
        string $url,
        string $username,
        string $password,
        ?string $payload,
        ?string $contentType
    ): array {
        $this->assertLoopbackUrl($url);
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
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $payload;
            if ($contentType !== null) {
                $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: ' . $contentType];
            }
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

    private function assertLoopbackUrl(string $url): void
    {
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

    /**
     * @param array<string, mixed> $message
     * @return list<array{name:string,type:string,size:int,blob_id:string}>
     */
    private function extractAttachments(array $message): array
    {
        $out = [];
        $stack = [];
        if (isset($message['bodyStructure']) && is_array($message['bodyStructure'])) {
            $stack[] = $message['bodyStructure'];
        }
        foreach (['attachments', 'attachedFiles'] as $key) {
            foreach ((array)($message[$key] ?? []) as $part) {
                if (is_array($part)) {
                    $stack[] = $part;
                }
            }
        }
        while ($stack !== []) {
            $part = array_pop($stack);
            if (!is_array($part)) {
                continue;
            }
            foreach ((array)($part['subParts'] ?? []) as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
            $disposition = strtolower((string)($part['disposition'] ?? ''));
            $blobId = (string)($part['blobId'] ?? '');
            $name = trim((string)($part['name'] ?? ''));
            $type = strtolower((string)($part['type'] ?? ''));
            $isAttach = $disposition === 'attachment'
                || ($blobId !== '' && $name !== '' && !str_starts_with($type, 'text/'));
            if (!$isAttach || $blobId === '') {
                continue;
            }
            $out[] = [
                'name' => $name !== '' ? $name : 'attachment',
                'type' => $type !== '' ? $type : 'application/octet-stream',
                'size' => (int)($part['size'] ?? 0),
                'blob_id' => $blobId,
            ];
        }
        return $out;
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
}
