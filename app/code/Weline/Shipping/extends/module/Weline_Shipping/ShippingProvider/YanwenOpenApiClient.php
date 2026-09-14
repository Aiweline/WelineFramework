<?php

declare(strict_types=1);

namespace Weline\Shipping\Extends\Module\Weline_Shipping\ShippingProvider;

/**
 * Yanwen Open Platform HTTP client. Lives with YanwenProvider (not shell).
 */
final class YanwenOpenApiClient
{
    public const ENV_LIVE = 'live';
    public const ENV_SANDBOX = 'sandbox';

    /** @var callable(string,array<string,string>,string):array{status:int,body:string}|null */
    private $httpHandler;

    /**
     * @param callable(string,array<string,string>,string):array{status:int,body:string}|null $httpHandler
     */
    public function __construct(?callable $httpHandler = null)
    {
        $this->httpHandler = $httpHandler;
    }

    public function endpointForEnvironment(string $environment): string
    {
        $env = strtolower(trim($environment));
        if ($env === self::ENV_SANDBOX || $env === 'fat' || $env === 'test') {
            return 'https://open-fat.yw56.com.cn/api/order';
        }

        return 'https://open.yw56.com.cn/api/order';
    }

    public function sign(
        string $apiToken,
        string $userId,
        string $data,
        string $format,
        string $method,
        string $timestamp,
        string $version,
    ): string {
        return md5($apiToken . $userId . $data . $format . $method . $timestamp . $version . $apiToken);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function call(
        string $userId,
        string $apiToken,
        string $method,
        array $data,
        string $environment = self::ENV_LIVE,
        string $version = 'V1.0',
    ): array {
        $format = 'json';
        $dataJson = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($dataJson === false) {
            throw new \RuntimeException('provider_encode_failed');
        }
        $timestamp = (string)(int)round(microtime(true) * 1000);
        $sign = $this->sign($apiToken, $userId, $dataJson, $format, $method, $timestamp, $version);
        $query = http_build_query([
            'user_id' => $userId,
            'format' => $format,
            'method' => $method,
            'timestamp' => $timestamp,
            'version' => $version,
            'sign' => $sign,
        ]);
        $url = $this->endpointForEnvironment($environment) . '?' . $query;
        $response = ($this->httpHandler ?? [$this, 'defaultHttp'])(
            $url,
            ['Content-Type' => 'application/json'],
            $dataJson,
        );
        $status = (int)($response['status'] ?? 0);
        $body = (string)($response['body'] ?? '');
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('provider_bad_json status=' . $status);
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int,body:string}
     */
    private function defaultHttp(string $url, array $headers, string $body): array
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
            $status = (int)$m[1];
        }

        return [
            'status' => $status,
            'body' => $raw === false ? '' : (string)$raw,
        ];
    }
}
