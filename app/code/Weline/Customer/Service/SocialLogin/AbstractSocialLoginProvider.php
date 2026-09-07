<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\Customer\Interface\SocialLoginProviderInterface;

/**
 * Shared OAuth HTTP helpers and default guide/policy metadata for social-login providers.
 *
 * Prefer extending this class; the sealed contract remains SocialLoginProviderInterface.
 */
abstract class AbstractSocialLoginProvider implements SocialLoginProviderInterface
{
    public function getIcon(): string
    {
        return $this->getCode();
    }

    public function getBrandClass(): string
    {
        return 'account-social-login__btn--' . $this->getCode();
    }

    public function getSortOrder(): int
    {
        return 100;
    }

    public function getSummary(): string
    {
        $label = $this->getLabel();
        if (\function_exists('__')) {
            return (string) \__('了解如何使用 %{1} 登录本站账户，以及绑定、解绑与隐私相关说明。', [$label]);
        }

        return 'Learn how to sign in with ' . $label . '.';
    }

    public function getGuideTitle(): string
    {
        $label = $this->getLabel();
        if (\function_exists('__')) {
            return (string) \__('%{1} 登录指南', [$label]);
        }

        return $label . ' sign-in guide';
    }

    public function getPolicyTitle(): string
    {
        $label = $this->getLabel();
        if (\function_exists('__')) {
            return (string) \__('%{1} 登录政策', [$label]);
        }

        return $label . ' sign-in policy';
    }

    public function getGuideTemplateCode(): string
    {
        return 'guide';
    }

    public function getPolicyTemplateCode(): string
    {
        return 'policy';
    }

    public function getGuideLayoutType(): string
    {
        // Must use a layout that injects controller {{content}} (help layout only shows hero title).
        return 'payment_guide';
    }

    public function getPolicyLayoutType(): string
    {
        return 'payment_guide';
    }

    public function getStorefrontPrivacyPolicyPath(): string
    {
        return 'guide/social-login/' . $this->getCode() . '/policy';
    }

    public function getStorefrontTermsPath(): string
    {
        return 'terms';
    }

    public function getStorefrontDataDeletionPath(): string
    {
        return $this->getStorefrontPrivacyPolicyPath() . '#data-deletion';
    }

    /**
     * @param array<string, scalar> $query
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    protected function httpGet(string $url, array $query = [], array $headers = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        return $this->requestJson('GET', $url, null, $headers);
    }

    /**
     * @param array<string, scalar> $body
     * @return array<string, mixed>
     */
    protected function httpFormPost(string $url, array $body): array
    {
        return $this->requestJson('POST', $url, http_build_query($body), [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    protected function requestJson(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException((string) __('无法初始化社媒 OAuth 请求'));
        }
        $headers[] = 'Accept: application/json';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $this->applyOutboundProxy($ch);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0 || !is_string($raw)) {
            $detail = $error !== '' ? $error : 'network';
            if ($errno === CURLE_OPERATION_TIMEDOUT
                || $errno === CURLE_COULDNT_CONNECT
                || stripos($detail, 'timed out') !== false
                || stripos($detail, 'timeout') !== false
            ) {
                throw new \RuntimeException((string) __(
                    '社媒 OAuth 请求失败：无法连通提供商接口（%{1}）。若服务器无法直连 Google/Meta，请在「顾客社媒登录 → 出站代理」配置 HTTP/SOCKS 代理，或设置 HTTPS_PROXY。',
                    [$detail]
                ));
            }
            throw new \RuntimeException((string) __('社媒 OAuth 请求失败：%{1}', [$detail]));
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException((string) __('社媒 OAuth 响应无效（HTTP %{1}）', [$status]));
        }
        if ($status >= 400 || isset($json['error'])) {
            $message = is_array($json['error'] ?? null)
                ? (string) ($json['error']['message'] ?? json_encode($json['error'], JSON_UNESCAPED_UNICODE))
                : (string) ($json['error_message'] ?? $json['error'] ?? ('HTTP ' . $status));
            throw new \RuntimeException((string) __('社媒 OAuth 请求失败：%{1}', [$message]));
        }

        return $json;
    }

    /**
     * Apply optional SystemConfig / env outbound proxy for token & userinfo calls.
     */
    protected function applyOutboundProxy(\CurlHandle|false $ch): void
    {
        if ($ch === false) {
            return;
        }
        $proxy = SocialLoginOutboundProxy::resolve();
        if ($proxy['proxy'] === '') {
            return;
        }
        curl_setopt($ch, CURLOPT_PROXY, $proxy['proxy']);
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
        if (($proxy['type'] ?? 'http') === 'socks5') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        }
        if ($proxy['userpwd'] !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['userpwd']);
        }
    }
}
