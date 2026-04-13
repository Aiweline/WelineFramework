<?php
declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Server\Service\HostsFileManager;

class LocalWelineHostsSyncService
{
    /**
     * @var null|\Closure(string, string, array<string, mixed>): mixed
     */
    private ?\Closure $queryExecutor;

    /**
     * @param null|\Closure(string, string, array<string, mixed>): mixed $queryExecutor
     */
    public function __construct(?\Closure $queryExecutor = null)
    {
        $this->queryExecutor = $queryExecutor;
    }

    public function isEligibleDomain(string $domain): bool
    {
        $domain = \strtolower(\trim($domain));
        if ($domain === '' || $domain === 'weline.local') {
            return false;
        }

        return (bool)\preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.weline\.local$/', $domain);
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureHostsInjected(string $domain, string $ip = '127.0.0.1'): array
    {
        $domain = \strtolower(\trim($domain));
        $ip = \trim($ip) !== '' ? \trim($ip) : '127.0.0.1';

        if (!$this->isEligibleDomain($domain)) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => (string)__('Only {subdomain}.weline.local is allowed for automatic hosts injection'),
                'domain' => $domain,
            ];
        }

        $result = $this->queryHostsAdd($domain, $ip);
        if (\is_array($result)) {
            return $result + ['domain' => $domain];
        }

        $fallback = HostsFileManager::addDomain($domain, $ip);
        return $fallback + ['domain' => $domain, 'fallback' => true];
    }

    /**
     * @return mixed
     */
    private function queryHostsAdd(string $domain, string $ip): mixed
    {
        if ($this->queryExecutor instanceof \Closure) {
            return ($this->queryExecutor)('server', 'hostsAdd', [
                'domain' => $domain,
                'ip' => $ip,
            ]);
        }

        if (\function_exists('w_query')) {
            return \w_query('server', 'hostsAdd', [
                'domain' => $domain,
                'ip' => $ip,
            ]);
        }

        return null;
    }
}
