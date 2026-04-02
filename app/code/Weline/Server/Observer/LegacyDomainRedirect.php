<?php
declare(strict_types=1);

/**
 * Weline Server - 域名白名单验证
 *
 * 拒绝不支持的域名格式访问，防止域名串台导致的协议混乱。
 *
 * 允许的域名格式：
 * - p[hash].weline.local（标准格式）
 * - 127.0.0.1 / localhost（本地开发）
 * - env 配置中的自定义域名
 *
 * 拒绝的域名格式：
 * - weline-p[hash].local（已废弃的旧格式）
 * - 其他未配置的域名
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

class LegacyDomainRedirect implements ObserverInterface
{
    /**
     * 旧域名格式正则：weline-p[8位十六进制].local（已废弃）
     */
    private const LEGACY_DOMAIN_PATTERN = '/^weline-p[0-9a-f]{8}\.local$/i';

    /**
     * 标准域名格式正则：p[8位十六进制].weline.local
     */
    private const STANDARD_DOMAIN_PATTERN = '/^p[0-9a-f]{8}\.weline\.local$/i';

    public function execute(array &$data): void
    {
        /** @var Request $request */
        $request = $data['request'] ?? null;
        if (!$request) {
            return;
        }

        // 获取当前 Host（可能包含端口）
        $host = $request->getHeader('Host') ?? '';
        if ($host === '') {
            return;
        }

        // 分离域名和端口
        $domain = $host;
        if (\str_contains($host, ':')) {
            [$domain, ] = \explode(':', $host, 2);
        }

        // 检查是否为旧格式域名（直接拒绝）
        if (\preg_match(self::LEGACY_DOMAIN_PATTERN, $domain)) {
            $this->rejectRequest($data, 'Legacy domain format is no longer supported. Please use the standard format: p[hash].weline.local');
            return;
        }

        // 允许标准格式域名
        if (\preg_match(self::STANDARD_DOMAIN_PATTERN, $domain)) {
            return;
        }

        // 允许本地开发域名
        if (\in_array($domain, ['127.0.0.1', 'localhost', '::1'], true)) {
            return;
        }

        // 检查是否为 env 配置的自定义域名
        if ($this->isConfiguredDomain($domain)) {
            return;
        }

        // 其他未知域名一律拒绝
        $this->rejectRequest($data, 'Domain not configured. Please check your server configuration.');
    }

    /**
     * 检查域名是否在 env 配置中
     */
    private function isConfiguredDomain(string $domain): bool
    {
        try {
            $env = ObjectManager::getInstance(\Weline\Framework\App\Env::class);
            $wlsConfig = $env->getConfig('wls') ?? [];

            // 检查主配置的 host
            $configuredHost = $wlsConfig['host'] ?? '';
            if ($configuredHost !== '' && \strcasecmp($configuredHost, $domain) === 0) {
                return true;
            }

            // 检查 ssl_domain
            $sslDomain = $wlsConfig['ssl_domain'] ?? '';
            if ($sslDomain !== '' && \strcasecmp($sslDomain, $domain) === 0) {
                return true;
            }

            // 检查多实例配置
            $servers = $wlsConfig['servers'] ?? [];
            foreach ($servers as $serverConfig) {
                if (!\is_array($serverConfig)) {
                    continue;
                }
                $serverHost = $serverConfig['host'] ?? '';
                if ($serverHost !== '' && \strcasecmp($serverHost, $domain) === 0) {
                    return true;
                }
                $serverSslDomain = $serverConfig['ssl_domain'] ?? '';
                if ($serverSslDomain !== '' && \strcasecmp($serverSslDomain, $domain) === 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // 配置读取失败，保守拒绝
            return false;
        }

        return false;
    }

    /**
     * 拒绝请求并返回 403
     */
    private function rejectRequest(array &$data, string $message): void
    {
        $response = $data['response'] ?? null;
        if ($response && \method_exists($response, 'setStatusCode')) {
            $response->setStatusCode(403);
            $response->setHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->setBody($message);
            $data['handled'] = true;
        }
    }
}
