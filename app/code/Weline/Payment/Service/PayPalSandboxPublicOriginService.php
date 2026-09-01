<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;

/**
 * 解析 WLS 实例公网 Origin，供 CLI / 非 HTTP 上下文生成 PayPal OAuth redirect_uri。
 */
final class PayPalSandboxPublicOriginService
{
    public function resolvePublicOrigin(?Request $request = null): string
    {
        $fromRequest = $this->resolveFromRequest($request);
        if ($fromRequest !== '') {
            return $fromRequest;
        }

        $fromWlsInstance = $this->resolveFromWlsInstanceFile();
        if ($fromWlsInstance !== '') {
            return $fromWlsInstance;
        }

        $fromEnv = trim((string) Env::get('wls.public_origin', ''));
        if ($fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        return '';
    }

    public function buildFrontendPathUrl(string $path, ?Request $request = null): string
    {
        $path = ltrim(trim($path), '/');
        if ($path === '') {
            return '';
        }

        $origin = $this->resolvePublicOrigin($request);
        if ($origin === '') {
            return '';
        }

        return rtrim($origin, '/') . '/' . $path;
    }

    private function resolveFromRequest(?Request $request): string
    {
        if ($request === null) {
            try {
                $request = w_obj(Request::class);
            } catch (\Throwable) {
                return '';
            }
        }

        try {
            $baseHost = trim((string) $request->getBaseHost());
        } catch (\Throwable) {
            return '';
        }

        if ($baseHost === '' || $this->isLocalhostOrigin($baseHost) || !$this->isUsablePublicOrigin($baseHost)) {
            return '';
        }

        return rtrim($baseHost, '/');
    }

    /**
     * 拒绝残缺 Origin（如 CLI 污染后的 "http:" → 拼出 http:/payment/...）。
     */
    private function isUsablePublicOrigin(string $baseHost): bool
    {
        $candidate = trim($baseHost);
        if ($candidate === '') {
            return false;
        }

        $parts = parse_url($candidate);
        if (!\is_array($parts) || empty($parts['host'])) {
            if (!str_contains($candidate, '://')) {
                $parts = parse_url('https://' . ltrim($candidate, '/'));
            }
        }
        if (!\is_array($parts)) {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? '')));
        if ($host === '' || $host === 'http' || $host === 'https') {
            return false;
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? 'https')));

        return \in_array($scheme, ['http', 'https'], true);
    }

    private function resolveFromWlsInstanceFile(): string
    {
        $instanceName = trim((string) (getenv('WLS_INSTANCE') ?: 'default'));
        $safeName = (string) preg_replace('/[^A-Za-z0-9_.-]/', '', $instanceName);
        if ($safeName === '' || $safeName !== $instanceName) {
            $safeName = 'default';
        }

        $instanceFile = BP . 'var' . DIRECTORY_SEPARATOR . 'server'
            . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR . $safeName . '.json';
        if (!is_file($instanceFile)) {
            return '';
        }

        $raw = file_get_contents($instanceFile);
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return '';
        }

        $lifecycleState = strtolower(trim((string) ($data['lifecycle_state'] ?? '')));
        if (in_array($lifecycleState, ['stopped', 'failed', 'master_exited'], true)) {
            return '';
        }

        $publicOrigin = trim((string) ($data['public_origin'] ?? ''));
        if ($publicOrigin !== '' && $this->isUsablePublicOrigin($publicOrigin)) {
            return rtrim($publicOrigin, '/');
        }

        $publicHost = trim((string) ($data['public_host'] ?? ''));
        $port = (int) ($data['port'] ?? ($data['main_port'] ?? 0));
        $sslEnabled = (bool) ($data['ssl_enabled'] ?? true);
        if ($publicHost === '' || $port <= 0) {
            return '';
        }

        $scheme = $sslEnabled ? 'https' : 'http';

        return $scheme . '://' . $publicHost . ':' . $port;
    }

    private function isLocalhostOrigin(string $baseHost): bool
    {
        $host = parse_url($baseHost, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = $baseHost;
        }

        $host = strtolower(trim($host));

        return in_array($host, ['localhost', '127.0.0.1', '[::1]', '0.0.0.0'], true);
    }
}
