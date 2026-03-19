<?php
declare(strict_types=1);

/**
 * Weline Websites - 域名连通性检测服务
 *
 * 通过 HTTP(S) 探测域名是否可访问，用于根域/子域连通性状态与详情（hover 展示）
 */

namespace Weline\Websites\Service;

/**
 * 域名连通性检测
 *
 * 先试 HTTPS，失败则试 HTTP；2xx/3xx 视为 ok，否则 error，并生成简短 detail 供 hover 展示
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

        $https = $this->httpRequest('https://' . $domain . '/');
        if ($https['success']) {
            $ok = true;
            $code = (int) ($https['code'] ?? 0);
            $detail = $code >= 300 && $code < 400
                ? \__('HTTPS 可访问（%{1} 重定向）', [(string) $code])
                : \__('HTTPS 可访问（%{1}）', [(string) $code]);
        } else {
            $http = $this->httpRequest('http://' . $domain . '/');
            if ($http['success']) {
                $ok = true;
                $detail = \__('HTTPS 不可用，HTTP 可访问（%{1}）', [(string) ($http['code'] ?? 0)]);
            } else {
                $detail = $https['error'] ?: $http['error'] ?: \__('无法连接');
            }
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
     * 发送 HTTP 请求（仅头，不取体）
     *
     * @return array{success: bool, code: ?int, error: string}
     */
    protected function httpRequest(string $url): array
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

        \curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Weline-ConnectivityCheck/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
}
