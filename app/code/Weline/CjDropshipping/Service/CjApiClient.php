<?php

declare(strict_types=1);

namespace Weline\CjDropshipping\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Minimal CJ API client (token + GET/POST). Credentials from SystemConfig.
 */
class CjApiClient
{
    public const MODULE = 'Weline_CjDropshipping';
    public const AREA = 'backend';
    public const KEY_EMAIL = 'dropship/channel/cj/email';
    public const KEY_API_KEY = 'dropship/channel/cj/api_key';
    public const KEY_TOKEN = 'dropship/channel/cj/access_token';
    public const KEY_ORDER_SANDBOX = 'dropship/channel/cj/order_sandbox';
    public const BASE = 'https://developers.cjdropshipping.com/api2.0/v1';

    /**
     * Whether createOrderV3 should send isSandbox=1 (CJ official sandbox order).
     */
    public function isOrderSandboxEnabled(): bool
    {
        $raw = strtolower($this->cfg(self::KEY_ORDER_SANDBOX));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public function probe(): array
    {
        $email = $this->cfg(self::KEY_EMAIL);
        $key = $this->cfg(self::KEY_API_KEY);
        if ($email === '' || $key === '') {
            return ['ok' => false, 'message' => 'credentials_missing'];
        }
        try {
            $token = $this->ensureToken($email, $key);
            if ($token === '') {
                return ['ok' => false, 'message' => 'token_empty'];
            }

            return ['ok' => true, 'message' => 'probe_ok'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = self::BASE . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $this->request('GET', $url, null);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function post(string $path, ?array $body = null): array
    {
        return $this->request('POST', self::BASE . $path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function patch(string $path, ?array $body = null): array
    {
        return $this->request('PATCH', self::BASE . $path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $body): array
    {
        $email = $this->cfg(self::KEY_EMAIL);
        $key = $this->cfg(self::KEY_API_KEY);
        $token = $this->ensureToken($email, $key);
        $ch = curl_init($url);
        $headers = [
            'Content-Type: application/json',
            'CJ-Access-Token: ' . $token,
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($errno) {
            throw new \RuntimeException('cj_http:' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('cj_invalid_json');
        }

        return $decoded;
    }

    private function ensureToken(string $email, string $apiKey): string
    {
        $cached = $this->cfg(self::KEY_TOKEN);
        if ($cached !== '') {
            return $cached;
        }
        $ch = curl_init(self::BASE . '/authentication/getAccessToken');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $apiKey]),
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $decoded = json_decode((string)$raw, true) ?: [];
        $token = (string)($decoded['data']['accessToken'] ?? $decoded['data']['access_token'] ?? '');
        if ($token !== '') {
            $this->writeToken($token);
        }

        return $token;
    }

    private function cfg(string $key): string
    {
        try {
            /** @var SystemConfig $config */
            $config = ObjectManager::getInstance(SystemConfig::class);

            return trim((string)$config->getConfig($key, self::MODULE, self::AREA, '', 'default.default.default'));
        } catch (\Throwable) {
        }

        return '';
    }

    private function writeToken(string $token): void
    {
        try {
            /** @var SystemConfig $config */
            $config = ObjectManager::getInstance(SystemConfig::class);
            if (method_exists($config, 'setConfig')) {
                $config->setConfig(self::KEY_TOKEN, $token, self::MODULE, self::AREA);
            }
        } catch (\Throwable) {
        }
    }
}
