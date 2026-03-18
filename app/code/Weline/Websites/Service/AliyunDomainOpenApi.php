<?php
declare(strict_types=1);

/**
 * 阿里云域名 OpenAPI（RPC + HMAC-SHA1），无额外依赖。
 *
 * @see https://help.aliyun.com/document_detail/67750.html
 */
namespace Weline\Websites\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class AliyunDomainOpenApi
{
    private const ENDPOINT = 'https://domain.aliyuncs.com';

    /**
     * @param array<string, scalar|null> $extra 额外 Action 参数
     * @return array<string, mixed>
     */
    public static function request(string $action, array $extra, string $accessKeyId, string $accessKeySecret): array
    {
        $params = [
            'Action' => $action,
            'Format' => 'JSON',
            'Version' => '2018-01-29',
            'AccessKeyId' => $accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(8)),
        ];
        foreach ($extra as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $params[$k] = $v;
        }
        ksort($params);
        $canonical = '';
        foreach ($params as $k => $v) {
            $canonical .= '&' . self::percentEncode($k) . '=' . self::percentEncode((string) $v);
        }
        $stringToSign = 'GET&%2F&' . self::percentEncode(substr($canonical, 1));
        $key = $accessKeySecret . '&';
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $key, true));
        $params['Signature'] = $signature;
        $client = new Client(['timeout' => 45, 'http_errors' => false]);
        try {
            $r = $client->get(self::ENDPOINT, ['query' => $params]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        $httpCode = $r->getStatusCode();
        $body = (string) $r->getBody();
        if ($httpCode >= 400) {
            throw new \RuntimeException(
                __('阿里云域名 API HTTP %{code}：%{body}', [
                    'code' => (string) $httpCode,
                    'body' => \mb_substr(\preg_replace('/\s+/', ' ', $body) ?? '', 0, 400),
                ])
            );
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException(__('阿里云 API 返回异常：%{1}', [substr($body, 0, 240)]));
        }
        if (isset($data['Code'])) {
            $code = \strtoupper((string) $data['Code']);
            if (!\in_array($code, ['OK', '200', 'SUCCESS'], true)) {
                $msg = (string) ($data['Message'] ?? $data['code'] ?? $body);
                throw new \RuntimeException($msg);
            }
        }
        return $data;
    }

    private static function percentEncode(string $s): string
    {
        $res = rawurlencode($s);
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], $res);
    }
}
