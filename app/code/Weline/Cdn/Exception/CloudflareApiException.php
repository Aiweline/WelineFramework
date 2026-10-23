<?php

declare(strict_types=1);

namespace Weline\Cdn\Exception;

/**
 * Cloudflare API 拒绝或非 2xx 响应。
 *
 * 只携带 HTTP 状态与 Cloudflare 公开错误码，供调用方按语义分支（例如缺 Cache Rules 入口）；
 * 响应体可能含授权数据，禁止放进本异常。
 */
final class CloudflareApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly string $providerErrorCode,
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function providerErrorCode(): string
    {
        return $this->providerErrorCode;
    }

    /**
     * 沿 previous 链找到首个 Cloudflare API 异常（Adapter 会把它包进框架 Core）。
     */
    public static function findInChain(?\Throwable $e): ?self
    {
        for ($depth = 0; $e !== null && $depth < 16; $depth++, $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e;
            }
        }

        return null;
    }
}
