<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Nginx;

use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Server\Api\Tls\ActiveCertificateDomainSourceInterface;
use Weline\Server\Service\Edge\Gateway\ProjectCertificateGenerationStore;

/**
 * Expands managed-Nginx server_names with active local WLS hosts and prefers the
 * shared local wildcard certificate so sibling *.test.weline.com sites do not
 * inherit the project-host exact certificate (ERR_CERT_COMMON_NAME_INVALID).
 */
final class ManagedNginxLocalHostCatalog
{
    private const MAX_ACTIVE_DOMAINS = 2000;

    /**
     * @param list<string> $seedNames
     * @param null|callable():list<array{domain?:string,website_id?:int}> $activeDomainLoader
     * @return list<string>
     */
    public function expandServerNames(
        array $seedNames,
        ?callable $activeDomainLoader = null,
    ): array {
        $merged = [];
        foreach ($seedNames as $name) {
            $this->collectHost($merged, (string)$name);
        }

        $loader = $activeDomainLoader ?? [$this, 'loadActiveCertificateDomains'];
        try {
            $rows = $loader();
        } catch (\Throwable) {
            $rows = [];
        }
        if (\is_array($rows)) {
            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }
                $domain = \strtolower(\trim((string)($row['domain'] ?? '')));
                if ($domain === ''
                    || (!LocalDomainPolicy::isManagedLocalDomain($domain)
                        && !LocalDomainPolicy::isManagedWildcardDomain($domain))
                ) {
                    continue;
                }
                $this->collectHost($merged, $domain);
            }
        }

        return \array_values($merged);
    }

    /**
     * When server_names include managed local hosts under a shared wildcard and
     * that wildcard has an active immutable generation, bind the edge to it.
     *
     * @param array<string,mixed>|null $preferredCertificate
     * @param list<string> $serverNames
     * @param null|object{active:callable} $store
     * @return array<string,mixed>|null
     */
    public function resolveEdgeCertificate(
        ?array $preferredCertificate,
        array $serverNames,
        ?object $store = null,
    ): ?array {
        $wildcard = $this->selectPreferredWildcard($serverNames);
        if ($wildcard === null) {
            return $preferredCertificate;
        }

        $store ??= new ProjectCertificateGenerationStore();
        if (!\method_exists($store, 'active')) {
            return $preferredCertificate;
        }
        try {
            $active = $store->active($wildcard);
        } catch (\Throwable) {
            return $preferredCertificate;
        }
        if (!\is_array($active) || (int)($active['generation'] ?? 0) < 1) {
            return $preferredCertificate;
        }
        $active['domain'] = \strtolower(\trim((string)($active['domain'] ?? $wildcard)));
        if ($active['domain'] === '') {
            $active['domain'] = $wildcard;
        }

        return $active;
    }

    /**
     * @param array<string,string> $merged
     */
    private function collectHost(array &$merged, string $raw): void
    {
        $name = \strtolower(\trim($raw));
        if ($name === '' || $name === '_' || !$this->isSafeServerName($name)) {
            return;
        }
        $merged[$name] = $name;
        if (LocalDomainPolicy::isManagedLocalDomain($name)
            || LocalDomainPolicy::isManagedWildcardDomain($name)
        ) {
            $wildcard = LocalDomainPolicy::resolveWildcardDomain($name)
                ?? (LocalDomainPolicy::isManagedWildcardDomain($name) ? $name : null);
            if (\is_string($wildcard) && $wildcard !== '' && $this->isSafeServerName($wildcard)) {
                $merged[$wildcard] = $wildcard;
            }
        }
    }

    /**
     * @param list<string> $serverNames
     */
    private function selectPreferredWildcard(array $serverNames): ?string
    {
        $exactLocal = [];
        $wildcards = [];
        foreach ($serverNames as $name) {
            $name = \strtolower(\trim((string)$name));
            if ($name === '') {
                continue;
            }
            if (LocalDomainPolicy::isManagedWildcardDomain($name)) {
                $wildcards[$name] = $name;
                continue;
            }
            if (LocalDomainPolicy::isManagedSingleLabelSubdomain($name)) {
                $exactLocal[$name] = $name;
                $resolved = LocalDomainPolicy::resolveWildcardDomain($name);
                if (\is_string($resolved) && $resolved !== '') {
                    $wildcards[$resolved] = $resolved;
                }
            }
        }
        if ($exactLocal === [] && $wildcards === []) {
            return null;
        }
        // Prefer the primary managed-local wildcard when present; else first discovered.
        if (isset($wildcards[LocalDomainPolicy::TEST_WILDCARD_DOMAIN])) {
            return LocalDomainPolicy::TEST_WILDCARD_DOMAIN;
        }
        foreach ([
            LocalDomainPolicy::LEGACY_WELINE_TEST_WILDCARD_DOMAIN,
            LocalDomainPolicy::LEGACY_LOCAL_TEST_WILDCARD_DOMAIN,
            LocalDomainPolicy::LOOPBACK_WILDCARD_DOMAIN,
        ] as $candidate) {
            if (isset($wildcards[$candidate])) {
                return $candidate;
            }
        }

        return $wildcards === [] ? null : (string)\reset($wildcards);
    }

    /**
     * @return list<array{domain?:string,website_id?:int}>
     */
    private function loadActiveCertificateDomains(): array
    {
        if (!\class_exists(ObjectManager::class)) {
            return [];
        }
        try {
            $source = ObjectManager::getInstance(ActiveCertificateDomainSourceInterface::class);
        } catch (\Throwable) {
            return [];
        }
        if (!$source instanceof ActiveCertificateDomainSourceInterface) {
            return [];
        }

        $rows = $source->getActiveCertificateDomains(self::MAX_ACTIVE_DOMAINS);

        return \is_array($rows) ? $rows : [];
    }

    private function isSafeServerName(string $name): bool
    {
        if ($name === '_' || $name === 'localhost') {
            return true;
        }
        $ipCandidate = \trim($name, '[]');
        if (\filter_var($ipCandidate, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (\strlen($name) > 253) {
            return false;
        }
        if (\str_starts_with($name, '*.')) {
            $name = \substr($name, 2);
        } elseif (\str_starts_with($name, '.')) {
            $name = \substr($name, 1);
        }
        $labels = \explode('.', $name);
        foreach ($labels as $label) {
            if ($label === ''
                || \strlen($label) > 63
                || \preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label) !== 1
            ) {
                return false;
            }
        }

        return true;
    }
}
