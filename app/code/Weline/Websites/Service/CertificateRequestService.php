<?php
declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;

/**
 * 证书申请统一入口
 *
 * 人工申请与自动化任务均经本服务调用 w_query('server', 'requestCertificate', ...)。
 * 默认使用调用方传入的物理 webroot；仅当显式传入 use_wls_virtual_http01 时才走 WLS 虚拟 HTTP-01（与后台 SSL 默认行为一致）。
 */
class CertificateRequestService
{
    private const DEV_SIM_DOMAIN = 'weline-dev.local';
    /**
     * 统一发起证书申请（委托 Server 模块执行，参数集中在此处构造）
     *
     * @param array $options 必填: domain。可选: pool_id, domain_id, webroot, email, website_id,
     *                       provider, cert_type, cert_strategy, challenge_strategy, _on_progress
     * @return array{success: bool, message: string, cert_id?: int, cert_path?: string, cert?: object, ...}
     */
    public function requestCertificate(array $options): array
    {
        $domain = \trim((string) ($options['domain'] ?? ''));
        if ($domain === '') {
            return ['success' => false, 'message' => (string) __('域名不能为空')];
        }
        if ($this->isDevSimulationDomain($domain)) {
            return [
                'success' => true,
                'message' => (string) __('开发环境模拟证书已签发：%{domain}', ['domain' => $domain]),
                'cert_id' => 9990001,
                'cert_path' => '/tmp/weline-dev-local.crt',
                'simulated' => true,
            ];
        }

        $webroot = \trim((string) ($options['webroot'] ?? ''));
        if ($webroot === '') {
            $webroot = \defined('PUB') ? PUB : ((\defined('BP') ? BP : '') . 'pub');
        }

        $email = \trim((string) ($options['email'] ?? ''));
        if ($email === '') {
            $email = (string) (Env::getInstance()->getConfig('ssl.contact_email') ?? '');
        }
        if ($email === '') {
            $email = 'admin@' . $domain;
        }

        $params = [
            'domain' => $domain,
            'webroot' => $webroot,
            'email' => $email,
            'website_id' => (int) ($options['website_id'] ?? 0),
            'provider' => \trim((string) ($options['provider'] ?? 'letsencrypt')) ?: 'letsencrypt',
            'cert_type' => \trim((string) ($options['cert_type'] ?? 'exact')) ?: 'exact',
            'cert_strategy' => \trim((string) ($options['cert_strategy'] ?? '')),
            'pool_id' => (int) ($options['pool_id'] ?? 0),
            'domain_id' => (int) ($options['domain_id'] ?? 0),
            'challenge_strategy' => $this->normalizeChallengeStrategy($options['challenge_strategy'] ?? 'auto'),
        ];
        if (!empty($options['use_wls_virtual_http01'])) {
            $params['use_wls_virtual_http01'] = true;
        }
        if (isset($options['_on_progress']) && $options['_on_progress'] instanceof \Closure) {
            $params['_on_progress'] = $options['_on_progress'];
        }

        return w_query('server', 'requestCertificate', $params);
    }

    private function isDevSimulationDomain(string $domain): bool
    {
        return (\defined('DEV') && DEV)
            && \strtolower(\trim($domain)) === self::DEV_SIM_DOMAIN;
    }

    private function normalizeChallengeStrategy(mixed $v): string
    {
        $s = \is_array($v) ? \trim((string) ($v[0] ?? 'auto')) : \trim((string) $v);
        if ($s === '' || $s === 'Array') {
            return 'auto';
        }
        if (\in_array($s, ['http01', 'dns01', 'auto'], true)) {
            return $s;
        }
        return 'auto';
    }
}
