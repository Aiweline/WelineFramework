<?php
declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Server\Api\System\HostsWriter;

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
        return \class_exists(LocalDomainPolicy::class)
            && LocalDomainPolicy::isManagedSingleLabelSubdomain($domain);
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureHostsInjected(string $domain, string $ip = '127.0.0.1'): array
    {
        $domain = \strtolower(\trim($domain));
        // Managed local WLS domains always use loopback. Ignore caller IP / LAN / public detection.
        $ip = '127.0.0.1';

        if (\class_exists(LocalDomainPolicy::class)
            && LocalDomainPolicy::resolvesViaLoopbackSuffix($domain)) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => (string)__('Domain %{1} uses the .localhost loopback suffix and does not need hosts injection', [$domain]),
                'domain' => $domain,
            ];
        }

        if (!$this->isEligibleDomain($domain)) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => (string)__('Only managed local WLS domains are allowed for automatic hosts injection'),
                'domain' => $domain,
            ];
        }

        $result = $this->queryHostsAdd($domain, $ip);
        if (\is_array($result)) {
            return $result + ['domain' => $domain];
        }

        if (!\class_exists(HostsWriter::class)) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => (string)__('Weline Server is required for automatic hosts injection'),
                'domain' => $domain,
            ];
        }

        $fallback = HostsWriter::addDomain($domain, $ip);
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
