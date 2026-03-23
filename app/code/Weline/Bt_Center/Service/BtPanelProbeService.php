<?php

declare(strict_types=1);

namespace Weline\Bt_Center\Service;

use Weline\Bt_Center\Model\BtServer;

class BtPanelProbeService
{
    public function probe(string $url, int $timeout = 10): array
    {
        $checkedAt = date('Y-m-d H:i:s');
        $result = [
            'status' => BtServer::CHECK_STATUS_DOWN,
            'reachable' => false,
            'http_code' => null,
            'response_time_ms' => null,
            'error_message' => '',
            'checked_at' => $checkedAt,
        ];

        if ($url === '') {
            $result['error_message'] = __('监控地址为空');
            return $result;
        }

        $ch = curl_init();
        if ($ch === false) {
            $result['error_message'] = __('curl 初始化失败');
            return $result;
        }

        $startedAt = microtime(true);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Weline-BtCenter-HealthCheck/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADER => false,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $responseTimeMs = (int) round((microtime(true) - $startedAt) * 1000);
        $result['http_code'] = $httpCode > 0 ? $httpCode : null;
        $result['response_time_ms'] = $responseTimeMs > 0 ? $responseTimeMs : null;

        if ($errno !== 0) {
            $result['error_message'] = $error !== '' ? $error : __('访问失败');
            return $result;
        }

        $isReachable = ($httpCode >= 200 && $httpCode < 400) || in_array($httpCode, [401, 403], true);
        if ($isReachable) {
            $result['status'] = BtServer::CHECK_STATUS_UP;
            $result['reachable'] = true;
            return $result;
        }

        $result['error_message'] = $httpCode > 0
            ? __('HTTP 状态码 %{1}', (string) $httpCode)
            : __('无响应');

        if ($body === false && $result['error_message'] === '') {
            $result['error_message'] = __('无响应');
        }

        return $result;
    }
}
