<?php
declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Server\Api\Domain\LocalDomainPolicy;

class LocalWelineWildcardCertificateService
{
    public const WILDCARD_DOMAIN = '*.weline.test';

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
    public function ensureWildcardCertificateForDomain(string $domain, int $websiteId = 0): array
    {
        $domain = \strtolower(\trim($domain));
        $wildcardDomain = $this->resolveWildcardDomain($domain)
            ?? (\class_exists(LocalDomainPolicy::class)
                ? LocalDomainPolicy::currentWildcardDomain()
                : self::WILDCARD_DOMAIN);
        if (!$this->isEligibleDomain($domain)) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => (string)__('Only managed local WLS subdomains use the shared local wildcard certificate'),
                'domain' => $domain,
                'wildcard_domain' => $wildcardDomain,
            ];
        }

        $resolved = $this->query('server', 'resolveManagedCertificate', [
            'hostname' => $wildcardDomain,
            'preferred_cert_id' => null,
        ]);
        if (\is_array($resolved) && (string)($resolved['status'] ?? '') === 'active' && !($resolved['is_expired'] ?? true)) {
            return [
                'success' => true,
                'message' => (string)__('Reusing existing %{1} wildcard certificate', [$wildcardDomain]),
                'domain' => $domain,
                'wildcard_domain' => $wildcardDomain,
                'certificate' => $resolved,
                'reused' => true,
            ];
        }

        $result = $this->query('server', 'ensureLocalWelineWildcardCertificate', [
            'domain' => $wildcardDomain,
            'website_id' => \max(0, $websiteId),
        ]);

        if (\is_array($result)) {
            return $result + [
                'domain' => $domain,
                'wildcard_domain' => $wildcardDomain,
            ];
        }

        return [
            'success' => false,
            'message' => (string)__('Failed to ensure %{1} wildcard certificate', [$wildcardDomain]),
            'domain' => $domain,
            'wildcard_domain' => $wildcardDomain,
        ];
    }

    public function resolveWildcardDomain(string $domain): ?string
    {
        return \class_exists(LocalDomainPolicy::class)
            ? LocalDomainPolicy::resolveWildcardDomain($domain)
            : null;
    }

    /**
     * @return mixed
     */
    private function query(string $provider, string $operation, array $params): mixed
    {
        if ($this->queryExecutor instanceof \Closure) {
            return ($this->queryExecutor)($provider, $operation, $params);
        }

        if (\function_exists('w_query')) {
            return \w_query($provider, $operation, $params);
        }

        return null;
    }
}
