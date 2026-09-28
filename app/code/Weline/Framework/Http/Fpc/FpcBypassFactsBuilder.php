<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\App\Env as WelineEnv;
use Weline\Framework\Manager\ObjectManager;

/**
 * App Coordinator 与 Worker 早路径共用的 FPC bypass facts 组装（R1）。
 */
final class FpcBypassFactsBuilder
{
    /**
     * @param array<string, string> $headersLower 已小写化的请求头（可选；Worker 传入）
     * @param array<string, mixed> $query
     * @return array{
     *   query: array<string, mixed>,
     *   cookie_header: string,
     *   headers: array<string, string>,
     *   env: array<string, mixed>
     * }
     */
    public static function build(
        string $requestUriOrFullUrl = '',
        array $query = [],
        string $cookieHeader = '',
        array $headersLower = [],
        ?bool $cdnFpcDevModeOverride = null,
    ): array {
        if ($query === [] && $requestUriOrFullUrl !== '') {
            $queryString = (string)(\parse_url($requestUriOrFullUrl, \PHP_URL_QUERY) ?: '');
            if ($queryString !== '') {
                \parse_str($queryString, $query);
            }
        }

        $cdnFlag = $cdnFpcDevModeOverride;
        if ($cdnFlag === null) {
            $cdnFlag = self::resolveCdnFpcDevModeFlag();
        }

        $editorMode = '';
        try {
            if (\defined('BP')) {
                $editorMode = (string)WelineEnv::get('editor_mode', '');
            }
        } catch (\Throwable) {
            $editorMode = '';
        }

        return [
            'query' => $query,
            'cookie_header' => $cookieHeader,
            'headers' => $headersLower,
            'env' => [
                'editor_mode' => $editorMode,
                'cdn_fpc_dev_mode' => $cdnFlag ? '1' : '',
            ],
        ];
    }

    /**
     * 从当前 PHP 请求环境组装（Coordinator 路径）。
     *
     * @return array{
     *   query: array<string, mixed>,
     *   cookie_header: string,
     *   headers: array<string, string>,
     *   env: array<string, mixed>
     * }
     */
    public static function buildFromCurrentRequest(string $fullUri): array
    {
        $query = [];
        $queryString = (string)(\parse_url($fullUri, \PHP_URL_QUERY) ?: '');
        if ($queryString === '') {
            $queryString = (string)WelineEnv::server('QUERY_STRING', '');
        }
        if ($queryString !== '') {
            \parse_str($queryString, $query);
        }
        $getParams = WelineEnv::getGet(null, []);
        if (\is_array($getParams) && $getParams !== []) {
            $query = \array_merge($query, $getParams);
        }

        $headers = [];
        foreach ([
            'HTTP_X_WLS_FPC_BYPASS' => 'x-wls-fpc-bypass',
            'HTTP_X_WLS_INTERNAL_FPC_BYPASS' => 'x-wls-internal-fpc-bypass',
            'HTTP_X_WLS_DYNAMIC_WARMUP' => 'x-wls-dynamic-warmup',
            'HTTP_X_WLS_DYNAMIC_BENCHMARK' => 'x-wls-dynamic-benchmark',
        ] as $serverKey => $headerName) {
            $value = (string)WelineEnv::server($serverKey, '');
            if ($value !== '') {
                $headers[$headerName] = $value;
            }
        }

        return self::build(
            $fullUri,
            $query,
            (string)(WelineEnv::server('HTTP_COOKIE', '') ?: WelineEnv::get('server.http_cookie', '')),
            $headers,
            null,
        );
    }

    public static function resolveCdnFpcDevModeFlag(): bool
    {
        try {
            $resolver = ObjectManager::getInstance(CdnFpcDevModeFlagResolverInterface::class);
            if (!$resolver instanceof CdnFpcDevModeFlagResolverInterface) {
                return false;
            }

            return $resolver->isEnabledForCurrentRequest();
        } catch (\Throwable) {
            return false;
        }
    }
}
