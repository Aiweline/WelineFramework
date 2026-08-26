<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Service\Security\SecurityPolicyStateStore;

/**
 * Merge @Attack annotation rules into WLS path_rate_limits.
 */
final class AnnotationAttackRuleApplier
{
    public function __construct(
        private readonly SecurityPolicyStateStore $stateStore,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    public function apply(array $rules): void
    {
        $state = $this->stateStore->readRulesState();
        if (!is_array($state)) {
            return;
        }
        $securityRules = is_array($state['rules'] ?? null) ? $state['rules'] : $state;
        $pathRate = is_array($securityRules['path_rate_limits'] ?? null)
            ? $securityRules['path_rate_limits']
            : ['enabled' => true, 'rules' => []];
        $rows = is_array($pathRate['rules'] ?? null) ? $pathRate['rules'] : [];
        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $path = trim((string)($row['path'] ?? ''));
            if ($path !== '') {
                $indexed[$path] = $row;
            }
        }

        foreach ($rules as $rule) {
            if (!is_array($rule) || !is_array($rule['attack'] ?? null)) {
                continue;
            }
            if (($rule['attack']['enabled'] ?? true) === false) {
                continue;
            }
            $path = trim((string)($rule['path_pattern'] ?? ''));
            if ($path === '') {
                continue;
            }
            [$max, $window] = $this->parseRateLimit((string)($rule['attack']['rate_limit'] ?? ''));
            if ($max < 1) {
                continue;
            }
            $indexed[$path] = [
                'enabled' => true,
                'path' => $path,
                'window' => $window,
                'max_requests' => $max,
                'block_duration' => 120,
                'source' => 'controller_annotation',
                'description' => (string)($rule['attack']['description'] ?? $rule['description'] ?? ''),
            ];
        }

        $pathRate['enabled'] = true;
        $pathRate['rules'] = array_values($indexed);
        $securityRules['path_rate_limits'] = $pathRate;
        $payload = is_array($state['rules'] ?? null) ? $state : ['rules' => $securityRules];
        if (is_array($state['rules'] ?? null)) {
            $payload['rules'] = $securityRules;
        } else {
            $payload = $securityRules;
        }
        $this->stateStore->writeRules($payload);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function parseRateLimit(string $raw): array
    {
        if (!preg_match('/^(\d+)\/(\d+)([smhd])?$/', trim($raw), $matches)) {
            return [0, 60];
        }
        $max = (int)$matches[1];
        $amount = (int)$matches[2];
        $unit = $matches[3] ?? 'm';
        $window = match ($unit) {
            's' => $amount,
            'h' => $amount * 3600,
            'd' => $amount * 86400,
            default => $amount * 60,
        };

        return [$max, max(1, $window)];
    }
}
