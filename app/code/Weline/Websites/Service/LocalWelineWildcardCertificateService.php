<?php
declare(strict_types=1);

namespace Weline\Websites\Service;

class LocalWelineWildcardCertificateService
{
    public const WILDCARD_DOMAIN = '*.weline.local';

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
        if ($domain === '' || $domain === 'weline.local' || $domain === self::WILDCARD_DOMAIN) {
            return false;
        }

        return (bool)\preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.weline\.local$/', $domain);
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureWildcardCertificateForDomain(string $domain, int $websiteId = 0): array
    {
        $domain = \strtolower(\trim($domain));
        if (!$this->isEligibleDomain($domain)) {
            return [
                'success' => false,
                'skipped' => true,
                'message' => (string)__('Only {subdomain}.weline.local uses the shared *.weline.local wildcard certificate'),
                'domain' => $domain,
                'wildcard_domain' => self::WILDCARD_DOMAIN,
            ];
        }

        $resolved = $this->query('server', 'resolveManagedCertificate', [
            'hostname' => self::WILDCARD_DOMAIN,
            'preferred_cert_id' => null,
        ]);
        if (\is_array($resolved) && (string)($resolved['status'] ?? '') === 'active' && !($resolved['is_expired'] ?? true)) {
            return [
                'success' => true,
                'message' => (string)__('Reusing existing *.weline.local wildcard certificate'),
                'domain' => $domain,
                'wildcard_domain' => self::WILDCARD_DOMAIN,
                'certificate' => $resolved,
                'reused' => true,
            ];
        }

        $result = $this->query('server', 'ensureLocalWelineWildcardCertificate', [
            'domain' => self::WILDCARD_DOMAIN,
            'website_id' => \max(0, $websiteId),
        ]);

        if (\is_array($result)) {
            return $result + [
                'domain' => $domain,
                'wildcard_domain' => self::WILDCARD_DOMAIN,
            ];
        }

        return [
            'success' => false,
            'message' => (string)__('Failed to ensure *.weline.local wildcard certificate'),
            'domain' => $domain,
            'wildcard_domain' => self::WILDCARD_DOMAIN,
        ];
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
