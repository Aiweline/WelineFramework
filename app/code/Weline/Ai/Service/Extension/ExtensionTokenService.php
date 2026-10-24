<?php
declare(strict_types=1);
namespace Weline\Ai\Service\Extension;

class ExtensionTokenService
{
    public const TTL = 900;
    public const ALLOWED_SCOPES = ['model:browser-local', 'capability:translate'];

    /** 统一 secret 来源：env ai_extension.token_secret，缺省用安装密钥派生（本地/单机部署）。 */
    public static function defaultSecret(): string
    {
        return (string)(\Weline\Framework\Env\WelineEnv::get('ai_extension.token_secret')
            ?: hash('sha256', (string)\Weline\Framework\Env\WelineEnv::get('install_key', BP . 'var')));
    }

    public function __construct(private readonly string $secret)
    {
    }

    public function sign(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Invalid token payload');
        }
        return $this->encode($json) . '.' . $this->encode(hash_hmac('sha256', $json, $this->secret, true));
    }

    public function verify(string $token, array $bind = []): array|false
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        $payloadJson = $this->decode($parts[0]);
        $signature = $this->decode($parts[1]);
        if ($payloadJson === null || $signature === null || !hash_equals(hash_hmac('sha256', $payloadJson, $this->secret, true), $signature)) {
            return false;
        }
        try {
            $payload = json_decode($payloadJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        if (!is_array($payload)) {
            return false;
        }
        if (!is_int($payload['exp'] ?? null) || !is_int($payload['iat'] ?? null) || $payload['exp'] <= time()) {
            return false;
        }
        if (!is_array($payload['scopes'] ?? null)) {
            return false;
        }
        foreach ($payload['scopes'] as $scope) {
            if (!is_string($scope) || !in_array($scope, self::ALLOWED_SCOPES, true)) {
                return false;
            }
        }
        foreach (['device_fp', 'backend_session_id', 'grant_id'] as $field) {
            if (array_key_exists($field, $bind) && ($bind[$field] ?? null) !== ($payload[$field] ?? null)) {
                return false;
            }
        }
        return $payload;
    }

    public function renew(string $oldToken, string $backendSessionId, int $now): string|false
    {
        $payload = $this->verify($oldToken);
        if ($payload === false || $backendSessionId === '' || ($payload['backend_session_id'] ?? '') !== $backendSessionId) {
            return false;
        }
        $payload['iat'] = $now;
        $payload['exp'] = $now + self::TTL;
        $payload['backend_session_id'] = $backendSessionId;
        return $this->sign($payload);
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
