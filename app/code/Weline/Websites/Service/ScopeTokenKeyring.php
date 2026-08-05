<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;

/** Loads a pre-provisioned keyring; request paths never create keys. */
final class ScopeTokenKeyring
{
    private const MAX_BYTES = 65536;
    private const MAX_KEYS = 32;
    private const ENV_KEYRING_BASE64 = 'WELINE_SCOPE_TOKEN_KEYRING_B64';
    private const KID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D';

    private static ?int $processVersion = null;
    private static ?string $processVersionDigest = null;

    /** @var array{active_kid:string,version:int,keys:array<string,array{status:string,signing_not_after:int,verify_until:int,secret:string}>}|null */
    private ?array $snapshot = null;
    private ?string $snapshotIdentity = null;

    /** @return array{kid:string,secret:string,version:int} */
    public function active(): array
    {
        $snapshot = $this->load();
        $kid = $snapshot['active_kid'];
        $key = $snapshot['keys'][$kid] ?? null;
        if (!is_array($key) || $key['status'] !== 'active') {
            throw new \RuntimeException(__('Scope Token keyring 缺少 active key'));
        }
        return ['kid' => $kid, 'secret' => $key['secret'], 'version' => $snapshot['version']];
    }

    /** @return array{secret:string,status:string,signing_not_after:int,verify_until:int,version:int}|null */
    public function verification(string $kid, int $now): ?array
    {
        $snapshot = $this->load();
        $key = $snapshot['keys'][$kid] ?? null;
        if (!is_array($key)) {
            return null;
        }
        if ($key['status'] === 'active' && $kid !== $snapshot['active_kid']) {
            return null;
        }
        if ($key['status'] === 'verify_only' && $key['verify_until'] < $now) {
            return null;
        }
        return $key + ['version' => $snapshot['version']];
    }

    /** @return array{active_kid:string,version:int,keys:array<string,array{status:string,signing_not_after:int,verify_until:int,secret:string}>} */
    private function load(): array
    {
        $environment = getenv(self::ENV_KEYRING_BASE64);
        if (is_string($environment) && $environment !== '') {
            return $this->loadEnvironmentPayload($environment);
        }

        $path = $this->configuredPath();
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)
            || (((int)$stat['mode'] & 0170000) !== 0100000)
            || (int)$stat['size'] <= 0
            || (int)$stat['size'] > self::MAX_BYTES) {
            throw new \RuntimeException(__('Scope Token keyring 必须是有效普通文件'));
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            throw new \RuntimeException(__('Windows 下文件 ACL 无法在 PHP 内可靠验证，请使用进程密钥注入'));
        }
        if (((int)$stat['mode'] & 0077) !== 0) {
            throw new \RuntimeException(__('Scope Token keyring 必须是 owner-only 普通文件'));
        }
        if (function_exists('posix_geteuid') && (int)$stat['uid'] !== (int)posix_geteuid()) {
            throw new \RuntimeException(__('Scope Token keyring 文件所有者无效'));
        }

        $identity = implode(':', [
            'file',
            (string)$stat['dev'],
            (string)$stat['ino'],
            (string)$stat['mtime'],
            (string)$stat['ctime'],
            (string)$stat['size'],
        ]);
        if ($this->snapshot !== null && hash_equals((string)$this->snapshotIdentity, $identity)) {
            return $this->snapshot;
        }

        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException(__('Scope Token keyring 无法打开'));
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened)
                || (((int)$opened['mode'] & 0170000) !== 0100000)
                || (((int)$opened['mode'] & 0077) !== 0)
                || (int)$opened['dev'] !== (int)$stat['dev']
                || (int)$opened['ino'] !== (int)$stat['ino']
                || (int)$opened['mtime'] !== (int)$stat['mtime']
                || (int)$opened['ctime'] !== (int)$stat['ctime']
                || (int)$opened['size'] !== (int)$stat['size']
                || (function_exists('posix_geteuid') && (int)$opened['uid'] !== (int)posix_geteuid())) {
                throw new \RuntimeException(__('Scope Token keyring 在读取期间发生替换'));
            }
            $payload = stream_get_contents($handle, self::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($payload) || $payload === '' || strlen($payload) > self::MAX_BYTES) {
            throw new \RuntimeException(__('Scope Token keyring 内容无效'));
        }

        $snapshot = $this->acceptMonotonicSnapshot($this->decodeSnapshot($payload));
        $this->snapshot = $snapshot;
        $this->snapshotIdentity = $identity;
        return $snapshot;
    }

    /** @return array{active_kid:string,version:int,keys:array<string,array{status:string,signing_not_after:int,verify_until:int,secret:string}>} */
    private function loadEnvironmentPayload(string $encoded): array
    {
        $payload = base64_decode($encoded, true);
        if (!is_string($payload)
            || $payload === ''
            || strlen($payload) > self::MAX_BYTES
            || !hash_equals(base64_encode($payload), $encoded)) {
            throw new \RuntimeException(__('Scope Token 进程密钥注入无效'));
        }
        $identity = 'env:' . hash('sha256', $payload);
        if ($this->snapshot !== null && hash_equals((string)$this->snapshotIdentity, $identity)) {
            return $this->snapshot;
        }
        $snapshot = $this->acceptMonotonicSnapshot($this->decodeSnapshot($payload));
        $this->snapshot = $snapshot;
        $this->snapshotIdentity = $identity;
        return $snapshot;
    }

    private function configuredPath(): string
    {
        $path = trim((string)Env::get('security.scope_token.keyring_file', ''));
        if ($path === '') {
            $module = Env::module_env('Weline_Websites', 'scope_token');
            $path = is_array($module) ? trim((string)($module['keyring_file'] ?? '')) : '';
        }
        if ($path === '' || str_contains($path, "\0") || !$this->isAbsolutePath($path)) {
            throw new \RuntimeException(__('Scope Token keyring 未配置绝对路径'));
        }
        return $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return str_starts_with($path, '/');
        }
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\[^\\\\\/]+[\\\\\/][^\\\\\/]+)/D', $path) === 1;
    }

    /** @return array{active_kid:string,version:int,keys:array<string,array{status:string,signing_not_after:int,verify_until:int,secret:string}>} */
    private function decodeSnapshot(string $payload): array
    {
        try {
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(__('Scope Token keyring JSON 无效'), 0, $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException(__('Scope Token keyring JSON 无效'));
        }
        return $this->validateSnapshot($decoded);
    }

    /** @param array<string,mixed> $decoded @return array{active_kid:string,version:int,keys:array<string,array{status:string,signing_not_after:int,verify_until:int,secret:string}>} */
    private function validateSnapshot(array $decoded): array
    {
        if (!$this->hasExactKeys($decoded, ['active_kid', 'keys', 'version'])) {
            throw new \RuntimeException(__('Scope Token keyring 元数据字段无效'));
        }
        $activeKid = (string)($decoded['active_kid'] ?? '');
        $version = $decoded['version'] ?? null;
        $keys = $decoded['keys'] ?? null;
        if (preg_match(self::KID_PATTERN, $activeKid) !== 1
            || !is_int($version)
            || $version < 1
            || !is_array($keys)
            || array_is_list($keys)
            || $keys === []
            || count($keys) > self::MAX_KEYS) {
            throw new \RuntimeException(__('Scope Token keyring 元数据无效'));
        }
        $validated = [];
        $activeCount = 0;
        $secretFingerprints = [];
        foreach ($keys as $kid => $config) {
            if (!is_string($kid) || preg_match(self::KID_PATTERN, $kid) !== 1 || !is_array($config)) {
                throw new \RuntimeException(__('Scope Token keyring kid 无效'));
            }
            if (!$this->hasExactKeys($config, ['secret_base64', 'signing_not_after', 'status', 'verify_until'])) {
                throw new \RuntimeException(__('Scope Token keyring key 字段无效'));
            }
            $status = (string)($config['status'] ?? '');
            $signingNotAfter = $config['signing_not_after'] ?? null;
            $verifyUntil = $config['verify_until'] ?? 0;
            $secretBase64 = (string)($config['secret_base64'] ?? '');
            $secret = base64_decode($secretBase64, true);
            if (!in_array($status, ['active', 'verify_only'], true)
                || !is_int($signingNotAfter)
                || !is_int($verifyUntil)
                || !is_string($secret)
                || strlen($secret) < 32
                || strlen($secret) > 128
                || !hash_equals(base64_encode($secret), $secretBase64)) {
                throw new \RuntimeException(__('Scope Token keyring key 无效'));
            }
            if (($status === 'active' && ($signingNotAfter !== 0 || $verifyUntil !== 0))
                || ($status === 'verify_only' && ($signingNotAfter < 1 || $verifyUntil < $signingNotAfter))) {
                throw new \RuntimeException(__('Scope Token keyring 轮换时间窗口无效'));
            }
            $activeCount += $status === 'active' ? 1 : 0;
            $secretFingerprint = hash('sha256', $secret);
            if (isset($secretFingerprints[$secretFingerprint])) {
                throw new \RuntimeException(__('Scope Token keyring 不允许多个 kid 共用密钥'));
            }
            $secretFingerprints[$secretFingerprint] = true;
            $validated[$kid] = [
                'status' => $status,
                'signing_not_after' => $signingNotAfter,
                'verify_until' => $verifyUntil,
                'secret' => $secret,
            ];
        }
        if ($activeCount !== 1 || !isset($validated[$activeKid]) || $validated[$activeKid]['status'] !== 'active') {
            throw new \RuntimeException(__('Scope Token active_kid 未指向 active key'));
        }
        ksort($validated, SORT_STRING);
        return ['active_kid' => $activeKid, 'version' => $version, 'keys' => $validated];
    }

    /**
     * WLS/FPM 长生命进程内拒绝 keyring 回退。跨进程最低版本由发布门禁持久化。
     *
     * @param array{active_kid:string,version:int,keys:array<string,array{status:string,signing_not_after:int,verify_until:int,secret:string}>} $snapshot
     * @return array{active_kid:string,version:int,keys:array<string,array{status:string,signing_not_after:int,verify_until:int,secret:string}>}
     */
    private function acceptMonotonicSnapshot(array $snapshot): array
    {
        $digest = $this->semanticDigest($snapshot);
        if (self::$processVersion !== null) {
            if ($snapshot['version'] < self::$processVersion) {
                throw new \RuntimeException(__('Scope Token keyring version 不允许回退'));
            }
            if ($snapshot['version'] === self::$processVersion
                && !hash_equals((string)self::$processVersionDigest, $digest)) {
                throw new \RuntimeException(__('Scope Token keyring 同版本内容不一致'));
            }
        }
        self::$processVersion = $snapshot['version'];
        self::$processVersionDigest = $digest;
        return $snapshot;
    }

    /**
     * @param array{active_kid:string,version:int,keys:array<string,array{status:string,signing_not_after:int,verify_until:int,secret:string}>} $snapshot
     */
    private function semanticDigest(array $snapshot): string
    {
        $keys = [];
        foreach ($snapshot['keys'] as $kid => $key) {
            $keys[$kid] = [
                'status' => $key['status'],
                'signing_not_after' => $key['signing_not_after'],
                'verify_until' => $key['verify_until'],
                'secret_sha256' => hash('sha256', $key['secret']),
            ];
        }
        ksort($keys, SORT_STRING);
        $semantic = [
            'active_kid' => $snapshot['active_kid'],
            'version' => $snapshot['version'],
            'keys' => $keys,
        ];
        return hash('sha256', json_encode($semantic, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $data @param list<string> $expected */
    private function hasExactKeys(array $data, array $expected): bool
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }
}
