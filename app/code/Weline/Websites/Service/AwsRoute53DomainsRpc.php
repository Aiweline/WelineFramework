<?php
declare(strict_types=1);

/**
 * Route53 Domains JSON API（SigV4 + Guzzle），不依赖 aws-sdk-php。
 */
namespace Weline\Websites\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class AwsRoute53DomainsRpc
{
    private const SERVICE = 'route53domains';

    /**
     * @return array<string, mixed>
     */
    public static function call(
        string $operation,
        array $body,
        string $accessKey,
        string $secretKey,
        string $region = 'us-east-1',
    ): array {
        $host = self::SERVICE . '.' . $region . '.amazonaws.com';
        $endpoint = 'https://' . $host . '/';
        $target = 'Route53Domains_v20140515.' . $operation;
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);
        $payloadHash = hash('sha256', $payload);

        $canonicalHeaders = "host:{$host}\nx-amz-date:{$amzDate}\nx-amz-target:{$target}\n";
        $signedHeaders = 'host;x-amz-date;x-amz-target';
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = $dateStamp . '/' . $region . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = $algorithm . "\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = self::getSignatureKey($secretKey, $dateStamp, $region, self::SERVICE);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $auth = $algorithm
            . ' Credential=' . $accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        $client = new Client([
            'timeout' => 60,
            'connect_timeout' => 15,
            'http_errors' => false,
        ]);
        try {
            $response = $client->post($endpoint, [
                'headers' => [
                    'Host' => $host,
                    'X-Amz-Date' => $amzDate,
                    'X-Amz-Target' => $target,
                    'Content-Type' => 'application/x-amz-json-1.1',
                    'Authorization' => $auth,
                ],
                'body' => $payload,
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status >= 400) {
            throw new \RuntimeException(
                __('Route53 Domains HTTP %{code}：%{body}', [
                    'code' => (string) $status,
                    'body' => \mb_substr(\preg_replace('/\s+/', ' ', $raw) ?? '', 0, 400),
                ])
            );
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(__('Route53 Domains 返回非 JSON：%{1}', [substr($raw, 0, 200)]));
        }
        if (isset($decoded['__type'])) {
            $msg = (string) ($decoded['message'] ?? $decoded['Message'] ?? __('Route53 Domains 请求失败'));
            throw new \RuntimeException($msg !== '' ? $msg : __('Route53 Domains 请求失败'));
        }
        return $decoded;
    }

    private static function getSignatureKey(string $key, string $dateStamp, string $regionName, string $serviceName): string
    {
        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $key, true);
        $kRegion = hash_hmac('sha256', $regionName, $kDate, true);
        $kService = hash_hmac('sha256', $serviceName, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}
