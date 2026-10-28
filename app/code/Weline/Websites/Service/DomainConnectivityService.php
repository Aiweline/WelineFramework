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
    /** 轻探测超时（秒）：只做 DNS → TCP/443 → TLS 握手，取短超时 */
    private int $lightTimeout = 4;

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
     * 轻探测：DNS 解析 → 单次 TCP/443 连接 → TLS 握手读证书 notAfter
     *
     * 不拉 HTTP body、不跟随重定向、不做完整页面请求；失败返回结构化结果，不抛异常。
     * 证书不校验（只看有效期），证书是否有效仍以 SSL 证书管理为准。
     *
     * @return array{status: 'ok'|'error', checked_at: string, detail: string, cert_expires_at: ?string, cert_days_left: ?int}
     */
    public function probeLight(string $domain): array
    {
        $checkedAt = \date('Y-m-d H:i:s');
        $domain = \strtolower(\trim($domain));
        if ($domain === '') {
            return $this->buildLightResult('error', $checkedAt, (string) \__('域名为空'));
        }

        $ips = $this->lightResolveIps($domain);
        if ($ips === []) {
            return $this->buildLightResult('error', $checkedAt, (string) \__('DNS 解析失败'));
        }

        $tls = $this->lightTlsHandshake($domain);
        if (!$tls['ok']) {
            return $this->buildLightResult(
                'error',
                $checkedAt,
                (string) \__('DNS 解析到 %{1} 个地址，%{2}', [(string) \count($ips), $tls['error']])
            );
        }

        $parsed = $tls['certificate'] !== null ? @\openssl_x509_parse($tls['certificate']) : false;
        $expiresTs = \is_array($parsed) ? (int) ($parsed['validTo_time_t'] ?? 0) : 0;
        if ($expiresTs <= 0) {
            return $this->buildLightResult('error', $checkedAt, (string) \__('TLS 已握手但无法读取证书有效期'));
        }

        $daysLeft = (int) \floor(($expiresTs - \time()) / 86400);
        $expiresAt = \date('Y-m-d H:i:s', $expiresTs);

        return $this->buildLightResult(
            'ok',
            $checkedAt,
            $daysLeft >= 0
                ? (string) \__('轻探测可连通：证书 %{1} 天后到期（%{2}）', [(string) $daysLeft, $expiresAt])
                : (string) \__('轻探测可连通：证书已于 %{1} 过期', [$expiresAt]),
            $expiresAt,
            $daysLeft
        );
    }

    /**
     * @return array{status: 'ok'|'error', checked_at: string, detail: string, cert_expires_at: ?string, cert_days_left: ?int}
     */
    private function buildLightResult(
        string $status,
        string $checkedAt,
        string $detail,
        ?string $certExpiresAt = null,
        ?int $certDaysLeft = null
    ): array {
        return [
            'status' => $status,
            'checked_at' => $checkedAt,
            'detail' => $detail,
            'cert_expires_at' => $certExpiresAt,
            'cert_days_left' => $certDaysLeft,
        ];
    }

    /**
     * 轻探测第一步：DNS 解析（A/AAAA），无记录时回退 gethostbynamel
     *
     * @return list<string>
     */
    protected function lightResolveIps(string $domain): array
    {
        $ips = [];
        $records = @\dns_get_record($domain, \DNS_A | \DNS_AAAA);
        if (\is_array($records)) {
            foreach ($records as $record) {
                $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                if ($ip !== '') {
                    $ips[$ip] = true;
                }
            }
        }
        if ($ips === []) {
            $v4 = @\gethostbynamel($domain);
            foreach (\is_array($v4) ? $v4 : [] as $ip) {
                $ips[(string) $ip] = true;
            }
        }

        return \array_keys($ips);
    }

    /**
     * 轻探测第二步：单次 TCP/443 连接后直接 TLS 握手并捕获对端证书
     *
     * @return array{ok: bool, error: string, certificate: mixed}
     */
    protected function lightTlsHandshake(string $domain): array
    {
        $context = \stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $domain,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $stream = @\stream_socket_client(
            'tcp://' . $domain . ':443',
            $errno,
            $errstr,
            (float) $this->lightTimeout,
            \STREAM_CLIENT_CONNECT,
            $context
        );
        if (!\is_resource($stream)) {
            return [
                'ok' => false,
                'error' => (string) \__('TCP 443 连接失败：%{1}', [$errstr !== '' ? $errstr : ('errno ' . $errno)]),
                'certificate' => null,
            ];
        }

        \stream_set_timeout($stream, $this->lightTimeout);
        $cryptoOk = @\stream_socket_enable_crypto($stream, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $options = \stream_context_get_options($stream);
        @\fclose($stream);
        if ($cryptoOk !== true) {
            return [
                'ok' => false,
                'error' => (string) \__('TLS 握手失败'),
                'certificate' => null,
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'certificate' => $options['ssl']['peer_certificate'] ?? null,
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
