<?php
declare(strict_types=1);

/**
 * Weline Server host allowlist guard.
 *
 * Allowed hosts:
 * - `p[hash].weline.test`
 * - `p[hash].weline.localhost`
 * - `127.0.0.1` / `localhost` / `::1`
 * - custom domains configured in env
 *
 * Rejected hosts:
 * - legacy `weline-p[hash].local`
 * - unconfigured domains
 */

namespace Weline\Server\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\LocalDomainPolicy;

class LegacyDomainRedirect implements ObserverInterface
{
    private const LEGACY_DOMAIN_PATTERN = '/^weline-p[0-9a-f]{8}\.local$/i';

    private const STANDARD_DOMAIN_PATTERN = '/^p[0-9a-f]{8}\.(?:weline\.test|weline\.localhost)$/i';

    public function execute(array &$data): void
    {
        /** @var Request $request */
        $request = $data['request'] ?? null;
        if (!$request) {
            return;
        }

        $host = $request->getHeader('Host') ?? '';
        if ($host === '') {
            return;
        }

        $domain = $host;
        if (\str_contains($host, ':')) {
            [$domain, ] = \explode(':', $host, 2);
        }

        if (\preg_match(self::LEGACY_DOMAIN_PATTERN, $domain)) {
            $this->rejectRequest(
                $data,
                'Legacy domain format is no longer supported. Please use p[hash].weline.test or p[hash].weline.localhost.'
            );
            return;
        }

        if (\preg_match(self::STANDARD_DOMAIN_PATTERN, $domain) || LocalDomainPolicy::isStandardProjectHost($domain)) {
            return;
        }

        if (\in_array($domain, ['127.0.0.1', 'localhost', '::1'], true)) {
            return;
        }

        if ($this->isConfiguredDomain($domain)) {
            return;
        }

        $this->rejectRequest($data, 'Domain not configured. Please check your server configuration.');
    }

    private function isConfiguredDomain(string $domain): bool
    {
        try {
            $env = ObjectManager::getInstance(\Weline\Framework\App\Env::class);
            $wlsConfig = $env->getConfig('wls') ?? [];

            $configuredHost = $wlsConfig['host'] ?? '';
            if ($configuredHost !== '' && \strcasecmp($configuredHost, $domain) === 0) {
                return true;
            }

            $sslDomain = $wlsConfig['ssl_domain'] ?? '';
            if ($sslDomain !== '' && \strcasecmp($sslDomain, $domain) === 0) {
                return true;
            }

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
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

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
