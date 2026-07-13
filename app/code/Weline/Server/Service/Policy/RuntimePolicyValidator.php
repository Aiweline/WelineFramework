<?php

declare(strict_types=1);

namespace Weline\Server\Service\Policy;

use Weline\Framework\Runtime\Policy\RuntimePolicyBundle;
use Weline\Framework\Runtime\Policy\RuntimePolicyDescriptor;
use Weline\Server\Security\CanonicalClientIdentity;

final class RuntimePolicyValidator
{
    /**
     * Critical descriptors are accepted only when a concrete runtime
     * executor owns the complete stage + matcher + action combination.
     * Non-critical descriptors may target future stages and are ignored by
     * runtimes that do not yet implement them.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const CRITICAL_EXECUTION_CONTRACTS = [
        'connection' => [
            'ip_policy' => ['allow_or_deny'],
        ],
        'mandatory_request' => [
            'host_guard' => ['reject'],
            'request_limits' => ['reject'],
            'backend_key' => ['not_found'],
            'origin_token' => ['reject'],
            'token_bucket' => ['rate_limit'],
            'path_token_bucket' => ['rate_limit'],
            'attack_rules' => ['block_and_log'],
            'maintenance_epoch' => ['maintenance_response'],
        ],
        'deep_request' => [
            'body_attack_rules' => ['block_and_log'],
        ],
    ];

    /**
     * Runtime capabilities and the topologies in which they can be fulfilled.
     * This is intentionally closed: a critical descriptor may not turn an
     * unknown capability string into an implicit best-effort policy.
     *
     * @var array<string, list<string>>
     */
    private const SUPPORTED_CAPABILITIES = [
        'worker_policy_kernel' => ['direct', 'dispatcher'],
        'policy_application_drain' => ['direct', 'dispatcher'],
        'policy_accept_gate' => ['direct', 'dispatcher'],
        'shared_atomic_state' => ['direct', 'dispatcher'],
        'token_lease' => ['direct', 'dispatcher'],
        'connection_partition' => ['direct', 'dispatcher'],
        'slowloris_deferred_shared_accounting' => ['direct', 'dispatcher'],
        'async_attack_log' => ['direct', 'dispatcher'],
        'epoch_broadcast' => ['direct', 'dispatcher'],
        'cache_epoch' => ['direct', 'dispatcher'],
        'shared_cache' => ['direct', 'dispatcher'],
        'ip_cidr_guard' => ['direct', 'dispatcher'],
        'dispatcher_l4_policy' => ['dispatcher'],
        'topology:dispatcher' => ['dispatcher'],
    ];

    /**
     * @return list<string>
     */
    public function validate(RuntimePolicyBundle $bundle, ?string $topology = null): array
    {
        $errors = [];
        if ($topology !== null && !$bundle->supportsTopology($topology)) {
            $errors[] = "Policy bundle {$bundle->digest} does not support topology {$topology}.";
        }

        $requiredTopologies = $topology === null || $topology === 'both'
            ? ['direct', 'dispatcher']
            : [$topology];
        $ids = [];
        foreach ($bundle->descriptors as $descriptor) {
            if (isset($ids[$descriptor->id])) {
                $errors[] = 'Duplicate policy id: ' . $descriptor->id;
            }
            $ids[$descriptor->id] = true;
            foreach ($requiredTopologies as $requiredTopology) {
                if (!\in_array($requiredTopology, $descriptor->supportedTopologies, true)
                    && $descriptor->critical
                ) {
                    $errors[] = "Critical policy {$descriptor->id} does not support topology {$requiredTopology}.";
                }
            }
            if ($descriptor->state === 'shared_atomic'
                && !\in_array('shared_atomic_state', $descriptor->capabilities, true)) {
                $errors[] = "Policy {$descriptor->id} uses shared_atomic state without shared_atomic_state capability.";
            }
            $errors = [...$errors, ...$this->validateIpPolicyMatcher($descriptor)];
            $errors = [...$errors, ...$this->validateRegexMatchers($descriptor)];
            if (!$descriptor->critical) {
                continue;
            }

            $stage = $descriptor->stage->value;
            $matcherType = \trim((string)($descriptor->matcher['type'] ?? ''));
            $actionType = \trim((string)($descriptor->action['type'] ?? ''));
            $stageContract = self::CRITICAL_EXECUTION_CONTRACTS[$stage] ?? null;
            if ($stageContract === null) {
                $errors[] = "Critical policy {$descriptor->id} uses unsupported execution stage {$stage}.";
            } elseif (!isset($stageContract[$matcherType])) {
                $errors[] = "Critical policy {$descriptor->id} uses unsupported matcher {$matcherType} in stage {$stage}.";
            } elseif (!\in_array($actionType, $stageContract[$matcherType], true)) {
                $errors[] = "Critical policy {$descriptor->id} uses unsupported action {$actionType} for {$stage}/{$matcherType}.";
            }

            foreach ($descriptor->capabilities as $capability) {
                $capabilityTopologies = self::SUPPORTED_CAPABILITIES[$capability] ?? null;
                if ($capabilityTopologies === null) {
                    $errors[] = "Critical policy {$descriptor->id} requires unsupported capability {$capability}.";
                    continue;
                }
                foreach ($requiredTopologies as $requiredTopology) {
                    if (!\in_array($requiredTopology, $descriptor->supportedTopologies, true)) {
                        continue;
                    }
                    if (!\in_array($requiredTopology, $capabilityTopologies, true)) {
                        $errors[] = "Critical policy {$descriptor->id} capability {$capability} is unavailable in topology {$requiredTopology}.";
                    }
                }
            }
        }
        return $errors;
    }

    /**
     * Reject malformed address material in the control plane. In particular,
     * PHP's integer cast must never turn an illegal prefix into /0 and make a
     * Dispatcher or Worker trust the entire address family.
     *
     * @return list<string>
     */
    private function validateIpPolicyMatcher(RuntimePolicyDescriptor $descriptor): array
    {
        if (($descriptor->matcher['type'] ?? '') !== 'ip_policy') {
            return [];
        }

        $errors = [];
        $identity = new CanonicalClientIdentity();
        foreach (['trusted_proxy_cidrs', 'whitelist_cidrs', 'deny_cidrs'] as $field) {
            $values = $descriptor->matcher[$field] ?? [];
            if (!\is_array($values) || !\array_is_list($values)) {
                $errors[] = "Policy {$descriptor->id} matcher.{$field} must be a list.";
                continue;
            }
            foreach ($values as $index => $value) {
                $path = "matcher.{$field}[{$index}]";
                if (!\is_string($value) || $value === '' || $value !== \trim($value)) {
                    $errors[] = "Policy {$descriptor->id} {$path} must be a canonical IP or CIDR string.";
                    continue;
                }
                if (!\str_contains($value, '/')) {
                    if ($identity->normalizeIp($value) === '') {
                        $errors[] = "Policy {$descriptor->id} {$path} contains an invalid IP address.";
                    }
                    continue;
                }
                if (\substr_count($value, '/') !== 1) {
                    $errors[] = "Policy {$descriptor->id} {$path} contains an invalid CIDR.";
                    continue;
                }
                [$network, $prefix] = \explode('/', $value, 2);
                $packed = @\inet_pton($network);
                if (!\is_string($packed)
                    || !\preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $prefix)
                    || (int)$prefix > \strlen($packed) * 8
                ) {
                    $errors[] = "Policy {$descriptor->id} {$path} contains an invalid CIDR.";
                }
            }
        }

        return $errors;
    }

    /**
     * Validate only the PCRE fields executed by WorkerPolicyKernel. Invalid
     * expressions must fail policy check/compile instead of reaching a Worker.
     *
     * @return list<string>
     */
    private function validateRegexMatchers(RuntimePolicyDescriptor $descriptor): array
    {
        $matcherType = (string)($descriptor->matcher['type'] ?? '');
        $groups = match ($matcherType) {
            'attack_rules' => ['malicious_patterns', 'bad_user_agents'],
            'body_attack_rules' => ['malicious_patterns'],
            default => [],
        };
        $errors = [];
        foreach ($groups as $groupName) {
            if (!\array_key_exists($groupName, $descriptor->matcher)) {
                continue;
            }
            $group = $descriptor->matcher[$groupName];
            if (!\is_array($group)) {
                $errors[] = "Policy {$descriptor->id} matcher.{$groupName} must be an array.";
                continue;
            }
            $patterns = $group['patterns'] ?? [];
            if (!\is_array($patterns) || !\array_is_list($patterns)) {
                $errors[] = "Policy {$descriptor->id} matcher.{$groupName}.patterns must be a list.";
                continue;
            }
            foreach ($patterns as $index => $pattern) {
                $path = "matcher.{$groupName}.patterns[{$index}]";
                if (!\is_string($pattern) || $pattern === '') {
                    $errors[] = "Policy {$descriptor->id} {$path} must be a non-empty PCRE string.";
                    continue;
                }
                if (\strlen($pattern) > 1024) {
                    $errors[] = "Policy {$descriptor->id} {$path} exceeds the 1024-byte PCRE limit.";
                    continue;
                }
                if (@\preg_match($pattern, '') === false) {
                    $reason = \function_exists('preg_last_error_msg')
                        ? \preg_last_error_msg()
                        : 'PCRE compilation failed';
                    $errors[] = "Policy {$descriptor->id} {$path} is invalid: {$reason}.";
                }
            }
        }

        return $errors;
    }

    public function assertValid(RuntimePolicyBundle $bundle, ?string $topology = null): void
    {
        $errors = $this->validate($bundle, $topology);
        if ($errors !== []) {
            throw new \RuntimeException("Runtime policy validation failed:\n- " . \implode("\n- ", $errors));
        }
    }
}
