<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

/**
 * Small Cloudflare transport with deliberately redacted failures.
 */
final class CloudflareHttpClient
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';
    private const TOKEN_URL = 'https://dash.cloudflare.com/oauth2/token';

    /**
     * @param array<string, scalar> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function api(
        string $accessToken,
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
    ): array {
        if (trim($accessToken) === '') {
            throw new \RuntimeException((string)__('Cloudflare 账户未配置可用令牌。'));
        }
        if (!str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Cloudflare API path must be absolute.');
        }

        $url = self::API_BASE . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $payload = $body === null
            ? null
            : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->request(
            strtoupper($method),
            $url,
            [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            $payload,
            true,
        );
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    public function oauthToken(
        array $form,
        string $clientId,
        string $clientSecret,
        string $authenticationMethod,
    ): array {
        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException((string)__('Cloudflare OAuth 客户端未配置，请先设置服务器环境变量。'));
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ];
        if ($authenticationMethod === 'client_secret_basic') {
            $headers[] = 'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret);
            $form['client_id'] = $clientId;
        } elseif ($authenticationMethod === 'client_secret_post') {
            $form['client_id'] = $clientId;
            $form['client_secret'] = $clientSecret;
        } else {
            throw new \RuntimeException('Unsupported Cloudflare OAuth client authentication method.');
        }

        $response = $this->request(
            'POST',
            self::TOKEN_URL,
            $headers,
            http_build_query($form, '', '&', PHP_QUERY_RFC3986),
            false,
        );
        if (!is_string($response['access_token'] ?? null) || trim((string)$response['access_token']) === '') {
            throw new \RuntimeException((string)__('Cloudflare OAuth 令牌交换失败。'));
        }

        return $response;
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $url,
        array $headers,
        ?string $payload,
        bool $cloudflareEnvelope,
    ): array {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException((string)__('Cloudflare 网络请求初始化失败。'));
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Weline-Cdn/1.0',
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($handle);
        $curlError = curl_errno($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($raw === false) {
            throw new \RuntimeException(
                (string)__('Cloudflare 网络请求失败（cURL %{1}）。', $curlError)
            );
        }

        try {
            $decoded = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException(
                (string)__('Cloudflare 返回了无法解析的响应（HTTP %{1}）。', $status)
            );
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException((string)__('Cloudflare 返回了无效响应。'));
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(
                (string)__(
                    'Cloudflare 请求失败（HTTP %{1}，代码 %{2}）。',
                    $status,
                    $this->publicErrorCode($decoded),
                )
            );
        }
        if ($cloudflareEnvelope && ($decoded['success'] ?? false) !== true) {
            throw new \RuntimeException(
                (string)__('Cloudflare API 拒绝请求（代码 %{1}）。', $this->publicErrorCode($decoded))
            );
        }
        if (!$cloudflareEnvelope && isset($decoded['error'])) {
            throw new \RuntimeException(
                (string)__('Cloudflare OAuth 请求失败（代码 %{1}）。', (string)$decoded['error'])
            );
        }

        return $decoded;
    }

    /**
     * Return only a provider error code. Never echo a response body that could
     * contain authorization data.
     *
     * @param array<string, mixed> $response
     */
    private function publicErrorCode(array $response): string
    {
        $errors = $response['errors'] ?? [];
        if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
            return (string)($errors[0]['code'] ?? 'unknown');
        }

        return (string)($response['error'] ?? 'unknown');
    }
}
