<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

use Weline\Server\Service\ServerInstanceManager;

/**
 * Publishes project-owned ACME desired state only when the target domain is
 * currently served by the trusted host gateway.
 */
final class GatewayAcmeChallengePublisher
{
    /**
     * @param (\Closure(): array<string,array<string,mixed>>)|null $endpointProvider
     * @param (\Closure(string): array<string,mixed>)|null $registrationProvider
     * @param (\Closure(string,int,array,string): bool)|null $sync
     */
    public function __construct(
        private readonly ?\Closure $endpointProvider = null,
        private readonly ?\Closure $registrationProvider = null,
        private readonly ?\Closure $sync = null,
    ) {
    }

    /**
     * @param array{generation:int,digest:string,challenges:list<array<string,mixed>>} $desired
     */
    public function publish(array $desired, ?string $requiredDomain = null): bool
    {
        $generation = (int)($desired['generation'] ?? 0);
        $challenges = \is_array($desired['challenges'] ?? null)
            ? \array_values($desired['challenges'])
            : [];
        $claimedDigest = \strtolower(\trim((string)($desired['digest'] ?? '')));
        $computedDigest = \hash('sha256', GatewayClient::canonicalJson($challenges));
        if ($generation < 1
            || \preg_match('/\A[a-f0-9]{64}\z/D', $claimedDigest) !== 1
            || !\hash_equals($computedDigest, $claimedDigest)
        ) {
            return false;
        }
        $requiredDomain = $requiredDomain !== null
            ? $this->normalizeDomain($requiredDomain)
            : null;
        if ($requiredDomain === '') {
            return false;
        }

        try {
            $endpoints = $this->endpointProvider !== null
                ? ($this->endpointProvider)()
                : $this->endpoints();
        } catch (\Throwable) {
            return false;
        }
        if (!\is_array($endpoints)) {
            return false;
        }

        $gatewayObservedForRequiredDomain = false;
        $projectUuid = '';
        $allowedDomains = [];
        foreach ($endpoints as $instanceName => $endpoint) {
            if (!\is_array($endpoint)) {
                continue;
            }
            $gateway = \is_array($endpoint['gateway'] ?? null)
                ? $endpoint['gateway']
                : [];
            if (!\hash_equals(
                    'gateway',
                    \strtolower(\trim((string)($gateway['mode'] ?? ''))),
                )
                || !\hash_equals(
                    GatewayPaths::PROTOCOL,
                    (string)($gateway['protocol'] ?? ''),
                )
            ) {
                continue;
            }
            $endpointDomain = $this->normalizeDomain((string)(
                $endpoint['public_host'] ?? ''
            ));
            if ($requiredDomain !== null
                && $endpointDomain !== ''
                && \hash_equals($requiredDomain, $endpointDomain)
            ) {
                $gatewayObservedForRequiredDomain = true;
            }
            try {
                $registration = $this->registrationProvider !== null
                    ? ($this->registrationProvider)((string)$instanceName)
                    : (new GatewayRegistrationBuilder())->build((string)$instanceName);
            } catch (\Throwable) {
                if ($requiredDomain === null
                    || $endpointDomain === ''
                    || \hash_equals($requiredDomain, $endpointDomain)
                ) {
                    return false;
                }
                continue;
            }
            if (!\is_array($registration)) {
                if ($requiredDomain === null
                    || $endpointDomain === ''
                    || \hash_equals($requiredDomain, $endpointDomain)
                ) {
                    return false;
                }
                continue;
            }
            $registrationProjectUuid = \strtolower(\trim((string)(
                $registration['project_uuid'] ?? ''
            )));
            if ($registrationProjectUuid === '') {
                return false;
            }
            if ($projectUuid !== '' && !\hash_equals($projectUuid, $registrationProjectUuid)) {
                return false;
            }
            $projectUuid = $registrationProjectUuid;
            foreach ((array)($registration['routes'] ?? []) as $route) {
                if (!\is_array($route)) {
                    continue;
                }
                $domain = $this->normalizeDomain((string)($route['domain'] ?? ''));
                if ($domain === '' || \str_starts_with($domain, '*.')) {
                    continue;
                }
                $allowedDomains[$domain] = true;
                if ($requiredDomain !== null && \hash_equals($requiredDomain, $domain)) {
                    $gatewayObservedForRequiredDomain = true;
                }
            }
        }

        if ($requiredDomain !== null && !$gatewayObservedForRequiredDomain) {
            return true;
        }
        if ($projectUuid === '') {
            return $requiredDomain === null || !$gatewayObservedForRequiredDomain;
        }

        $filtered = [];
        $requiredLeaseFound = $requiredDomain === null;
        foreach ($challenges as $challenge) {
            if (!\is_array($challenge)) {
                return false;
            }
            $domain = $this->normalizeDomain((string)($challenge['domain'] ?? ''));
            if ($domain === '' || !isset($allowedDomains[$domain])) {
                continue;
            }
            $challenge['domain'] = $domain;
            $filtered[] = $challenge;
            if ($requiredDomain !== null && \hash_equals($requiredDomain, $domain)) {
                $requiredLeaseFound = true;
            }
        }
        if (!$requiredLeaseFound) {
            return false;
        }
        \usort(
            $filtered,
            static fn (array $left, array $right): int => [
                (string)($left['domain'] ?? ''),
                (string)($left['token'] ?? ''),
            ] <=> [
                (string)($right['domain'] ?? ''),
                (string)($right['token'] ?? ''),
            ],
        );
        $filteredDigest = \hash('sha256', GatewayClient::canonicalJson($filtered));
        try {
            if ($this->sync !== null) {
                return (bool)($this->sync)(
                    $projectUuid,
                    $generation,
                    $filtered,
                    $filteredDigest,
                );
            }
            (new GatewayHostManager())->syncAcmeChallenges(
                $projectUuid,
                $generation,
                $filtered,
                $filteredDigest,
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function endpoints(): array
    {
        $instances = new ServerInstanceManager();
        $endpoints = [];
        foreach ($instances->listPersistedInstanceNames() as $instanceName) {
            $endpoint = $instances->getRawInstanceData($instanceName);
            if (\is_array($endpoint)) {
                $endpoints[$instanceName] = $endpoint;
            }
        }
        return $endpoints;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = \strtolower(\rtrim(\trim($domain), '.'));
        if ($domain === '') {
            return '';
        }
        if (\function_exists('idn_to_ascii')) {
            $ascii = \idn_to_ascii(
                $domain,
                IDNA_DEFAULT,
                INTL_IDNA_VARIANT_UTS46,
            );
            if (!\is_string($ascii) || $ascii === '') {
                return '';
            }
            $domain = \strtolower($ascii);
        }
        return $domain;
    }
}
