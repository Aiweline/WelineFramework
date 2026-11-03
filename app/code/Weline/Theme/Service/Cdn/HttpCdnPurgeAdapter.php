<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Cdn;

/**
 * 走 HTTP 的 purge 适配器：把「一批目标」翻译成一次「按前缀清除」请求。
 *
 * 真正站点用的是哪家 CDN 不影响这里：endpoint / token 由 zone 配置注入，传输层可替换。
 * 判定只看 HTTP 2xx；非 2xx 交给 {@see PurgeBatchPlanner} 的重试与熔断处理。
 */
final class HttpCdnPurgeAdapter implements CdnPurgeAdapterInterface
{
    /**
     * @param array<string, array{endpoint:string,token:string,field?:string}> $zones zone => 配置
     */
    public function __construct(
        private readonly CdnPurgeTransportInterface $transport,
        private readonly array $zones,
    ) {
    }

    public function purge(string $zone, array $targets): bool
    {
        $config = $this->zones[$zone] ?? null;
        if (!\is_array($config) || (string)($config['endpoint'] ?? '') === '') {
            // 没有配置就等于「这次清不了」：返回 false 让规划器留下续跑点，而不是假装成功。
            return false;
        }
        $field = (string)($config['field'] ?? 'prefixes');
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $token = (string)($config['token'] ?? '');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = $this->transport->post(
            (string)$config['endpoint'],
            [$field => \array_values($targets)],
            $headers,
        );

        $status = (int)($response['status'] ?? 0);

        return $status >= 200 && $status < 300;
    }
}
