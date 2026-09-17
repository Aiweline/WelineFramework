<?php

declare(strict_types=1);

namespace Weline\Server\Http;

use Weline\Framework\Http\MaintenanceStaticPage;

/**
 * Framework-facing HTTP 503 bodies for maintenance / startup gates.
 *
 * WorkerPolicyKernel and transport adapters must not return bare
 * "Service Unavailable" text for these product states.
 */
final class ServiceUnavailablePage
{
    public const VARIANT_MAINTENANCE = 'maintenance';

    public const VARIANT_STARTUP = 'startup';

    /**
     * @param array<string, string> $headers Lower-cased request headers
     */
    public static function prefersJson(array $headers, string $path = ''): bool
    {
        $accept = \strtolower((string)($headers['accept'] ?? ''));
        if (\str_contains($accept, 'application/json')) {
            return true;
        }

        return \str_contains($path, '/api/') || \str_contains($path, '/rest/');
    }

    /**
     * @param array<string, string> $headers
     */
    public static function httpResponse(
        string $variant = self::VARIANT_MAINTENANCE,
        bool $preferJson = false,
        string $policyDigest = '',
        int $retryAfter = 5,
        array $headers = [],
        string $path = '',
    ): string {
        if (!$preferJson && $headers !== []) {
            $preferJson = self::prefersJson($headers, $path);
        }

        $retryAfter = \max(1, $retryAfter);
        if ($preferJson) {
            $queryString = (string)(\parse_url($path, \PHP_URL_QUERY) ?: '');
            $pathOnly = (string)(\parse_url($path, \PHP_URL_PATH) ?: $path);
            $cookieHeader = (string)($headers['cookie'] ?? '');
            $body = MaintenanceStaticPage::loadJson(null, $pathOnly, $queryString, $cookieHeader, (string)($headers['host'] ?? ''))
                ?? self::jsonBody($variant, $retryAfter);
            $contentType = 'application/json; charset=utf-8';
        } else {
            $body = self::htmlBody($variant, $path, $headers);
            $contentType = 'text/html; charset=UTF-8';
        }

        $responseHeaders = "Content-Type: {$contentType}\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "Cache-Control: no-store, no-cache, must-revalidate\r\n"
            . "Pragma: no-cache\r\n"
            . "Retry-After: {$retryAfter}\r\n"
            . "Connection: close\r\n";
        if ($policyDigest !== '') {
            $responseHeaders .= 'X-WLS-Policy-Digest: ' . $policyDigest . "\r\n";
        }
        // Gate cookie proves the visitor hit a real WLS maintenance response (wait-gift anti-abuse).
        if ($variant === self::VARIANT_MAINTENANCE) {
            $responseHeaders .= "X-Weline-Maintenance: 1\r\n";
            $responseHeaders .= 'Set-Cookie: ' . self::maintenanceGateSetCookie() . "\r\n";
        }

        return "HTTP/1.1 503 Service Unavailable\r\n{$responseHeaders}\r\n{$body}";
    }

    /**
     * Opaque gate cookie for maintenance wait-gift issuance.
     */
    private static function maintenanceGateSetCookie(): string
    {
        $name = 'weline_mw_gate';
        $gate = '';
        if (\class_exists(\Weline\Maintenance\Service\WaitGiftService::class)) {
            $name = \Weline\Maintenance\Service\WaitGiftService::COOKIE_GATE;
            $gate = (new \Weline\Maintenance\Service\WaitGiftService())->mintGateToken();
        }
        if ($gate === '') {
            $gate = \rtrim(\strtr(\base64_encode(\random_bytes(32)), '+/', '-_'), '=');
        }
        if (\class_exists(\Weline\Framework\Http\CookieScope::class)) {
            $name = \Weline\Framework\Http\CookieScope::qualifyName($name);
        }

        return $name . '=' . \rawurlencode($gate)
            . '; Path=/; Max-Age=86400; SameSite=Lax; HttpOnly';
    }

    public static function htmlBody(string $variant = self::VARIANT_MAINTENANCE, string $path = '/', array $headers = []): string
    {
        if ($variant === self::VARIANT_MAINTENANCE) {
            $queryString = (string)(\parse_url($path, \PHP_URL_QUERY) ?: '');
            $pathOnly = (string)(\parse_url($path, \PHP_URL_PATH) ?: $path);
            $cookieHeader = (string)($headers['cookie'] ?? '');
            $static = MaintenanceStaticPage::loadHtml(
                null,
                $pathOnly,
                $queryString,
                $cookieHeader,
                (string)($headers['host'] ?? ''),
            );
            if ($static !== null && $static !== '') {
                return $static;
            }

            return self::fallbackMaintenanceHtml();
        }

        return self::fallbackStartupHtml();
    }

    public static function jsonBody(string $variant, int $retryAfter): string
    {
        $message = $variant === self::VARIANT_STARTUP
            ? 'WLS is starting; please retry shortly.'
            : '系统正在升级维护中，请稍后再试。';
        $payload = [
            'success' => false,
            'ok' => false,
            'status' => 503,
            'code' => $variant === self::VARIANT_STARTUP ? 'wls_starting' : 'maintenance',
            'error' => 'service_unavailable',
            'message' => $message,
            'data' => [
                'retry_after' => \max(1, $retryAfter),
                'variant' => $variant,
            ],
        ];

        return (string)\json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function fallbackMaintenanceHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <title>网站维护</title>
    <style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:linear-gradient(180deg,#0f172a 0%,#1e1b4b 100%);color:#f8fafc}
        main{max-width:560px;text-align:center}
        h1{margin:0 0 12px;font-size:clamp(28px,5vw,40px)}
        p{margin:0;line-height:1.7;color:#94a3b8}
    </style>
    <script>
    (function(){setInterval(function(){if(!document.hidden){location.reload();}},5000);})();
    </script>
</head>
<body>
<main>
    <h1>网站维护</h1>
    <p>系统正在升级维护中，请稍后再试。服务恢复后本页会自动刷新进入。</p>
</main>
</body>
</html>
HTML;
    }

    private static function fallbackStartupHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
    <title>WLS正在启动中...</title>
    <style>
        body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:linear-gradient(180deg,#f6efe4 0%,#fffdf9 100%);color:#2b241c}
        main{max-width:560px;text-align:center}
        h1{margin:0 0 12px;font-size:clamp(28px,5vw,40px)}
        p{margin:0;line-height:1.7;color:#75624c}
    </style>
    <script>
    (function(){setInterval(function(){if(!document.hidden){location.reload();}},5000);})();
    </script>
</head>
<body>
<main>
    <h1>WLS正在启动中...</h1>
    <p>业务 Worker 正在初始化。请稍候，本页会自动刷新直至服务恢复。</p>
</main>
</body>
</html>
HTML;
    }
}
