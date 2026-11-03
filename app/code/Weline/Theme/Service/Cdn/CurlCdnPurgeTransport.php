<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 真实传输：POST JSON 到 CDN purge 接口。
 *
 * 只做「发出去、拿回状态码」，不做重试/熔断（那是 {@see PurgeBatchPlanner} 的职责），
 * 这样失败语义单一，测试里也用 fake 传输替代它。
 */
final class CurlCdnPurgeTransport implements CdnPurgeTransportInterface
{
    public function __construct(private readonly int $timeoutSeconds = 10)
    {
    }

    public function post(string $url, array $payload, array $headers): array
    {
        $body = (string)\json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = \curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => 'curl_init_failed'];
        }
        \curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => \max(1, $this->timeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => \max(1, $this->timeoutSeconds),
        ]);
        $responseBody = \curl_exec($handle);
        $status = (int)\curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        \curl_close($handle);

        return [
            'status' => $status,
            'body' => \is_string($responseBody) ? $responseBody : '',
        ];
    }
}
