<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名连通性检测服务
 *
 * 通过 HTTP(S) 探测域名是否可访问，用于根域/子域连通性状态与详情（hover 展示）。
 * 不以「带证书校验的 HTTPS 请求」推断证书是否有效；证书结论仅见 detail 中「证书管理」摘要（读 SSL 证书表）。
 */

namespace Weline\Websites\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * 域名连通性检测
 *
 * 先探测 HTTP；失败时再探测 HTTPS 且关闭 TLS 证书校验（仅判断 443 是否可响应，不代表证书有效）。
 * 2xx/3xx 视为 ok；detail 末尾附证书管理表摘要。
 */
class DomainConnectivityService
{
    private int $timeout = 10;
    private int $connectTimeout = 5;

    /**
     * 探测单个域名
     *
     * @return array{status: 'ok'|'error'|'pending', detail: string, checked_at: string}
     */
    public function probe(string $domain): array
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return [
                'status' => 'error',
                'detail' => (string) \__('域名为空'),
                'checked_at' => \date('Y-m-d H:i:s'),
            ];
        }

        $detail = '';
        $ok = false;

        $http = $this->httpRequest('http://' . $domain . '/');
        if ($http['success']) {
            $ok = true;
            $code = (int) ($http['code'] ?? 0);
            $detail = $code >= 300 && $code < 400
                ? \__('HTTP 可访问（%{1} 重定向）', [(string) $code])
                : \__('HTTP 可访问（%{1}）', [(string) $code]);
        } else {
            // 仅判断 443 是否有 HTTP 响应，不校验证书（证书状态以 SSL 证书管理为准）
            $https = $this->httpRequest('https://' . $domain . '/', false);
            if ($https['success']) {
                $ok = true;
                $code = (int) ($https['code'] ?? 0);
                $detail = $code >= 300 && $code < 400
                    ? \__('HTTPS 可连通（未校验证书，%{1} 重定向）', [(string) $code])
                    : \__('HTTPS 可连通（未校验证书，%{1}）', [(string) $code]);
            } else {
                $errParts = \array_filter(
                    [$http['error'] ?? '', $https['error'] ?? ''],
                    static fn (string $s): bool => $s !== ''
                );
                $detail = $errParts !== []
                    ? \implode(' / ', $errParts)
                    : (string) \__('无法连接');
            }
        }

        $certHint = $this->buildSslManagementDetailSuffix($domain);
        if ($certHint !== '') {
            $detail .= ' | ' . $certHint;
        }

        return [
            'status' => $ok ? 'ok' : 'error',
            'detail' => $detail,
            'checked_at' => \date('Y-m-d H:i:s'),
        ];
    }

    /**
     * 设置超时（秒）
     */
    public function setTimeout(int $timeout, int $connectTimeout = 5): self
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        return $this;
    }

    /**
     * 发送 HTTP(S) 请求（仅头，不取体）
     *
     * @param bool $verifySsl HTTPS 时是否校验对端证书；为 false 时仅用于连通性探测，不能用于判断证书是否有效
     * @return array{success: bool, code: ?int, error: string}
     */
    protected function httpRequest(string $url, bool $verifySsl = true): array
    {
        $result = [
            'success' => false,
            'code' => null,
            'error' => '',
        ];

        $ch = \curl_init();
        if ($ch === false) {
            $result['error'] = \__('curl 初始化失败');
            return $result;
        }

        $isHttps = \str_starts_with(\strtolower($url), 'https://');
        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Weline-ConnectivityCheck/1.0',
            CURLOPT_SSL_VERIFYPEER => $isHttps ? $verifySsl : false,
            CURLOPT_SSL_VERIFYHOST => ($isHttps && $verifySsl) ? 2 : 0,
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
        ]);
        HealthCheckService::applyLocalEndpointProbeToCurl($ch, $url);

        \curl_exec($ch);
        $httpCode = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = \curl_errno($ch);
        $error = \curl_error($ch);
        \curl_close($ch);

        $result['code'] = $httpCode;
        if ($errno === 0 && $httpCode > 0 && $httpCode < 500) {
            $result['success'] = true;
        } else {
            $result['error'] = $error !== '' ? $error : \__('HTTP %{1}', [(string) $httpCode]);
        }

        return $result;
    }

    /**
     * 证书状态摘要：与全站统一走 {@see WebsiteSslCertificateStatusService}
     */
    private function buildSslManagementDetailSuffix(string $domain): string
    {
        try {
            $svc = ObjectManager::getInstance(WebsiteSslCertificateStatusService::class);

            return $svc->getManagementSummaryLabel($domain, null);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
