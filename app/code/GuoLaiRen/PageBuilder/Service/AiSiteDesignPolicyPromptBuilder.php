<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteDesignPolicyPromptBuilder
{
    public function __construct(
        private readonly ?AiSiteDesignPolicyRegistry $registry = null
    ) {
    }

    public function buildFullPrompt(string $policyId = AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID): string
    {
        $policy = $this->registry()->get($policyId);

        return (string)$policy['full_policy_prompt'];
    }

    public function buildCompactPrompt(string $policyId = AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID): string
    {
        $policy = $this->registry()->get($policyId);

        return (string)$policy['compact_policy_prompt'];
    }

    /**
     * @param list<string> $ruleIds
     */
    public function buildPolicySlicePrompt(array $ruleIds, string $policyId = AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID): string
    {
        $policy = $this->registry()->get($policyId);
        $catalog = \is_array($policy['rule_catalog'] ?? null) ? $policy['rule_catalog'] : [];
        $lines = [
            'Design policy slice: ' . (string)$policy['policy_id'] . '@' . (string)$policy['version'],
        ];

        foreach ($ruleIds as $ruleId) {
            $ruleId = \trim((string)$ruleId);
            if ($ruleId === '' || !\is_array($catalog[$ruleId] ?? null)) {
                continue;
            }
            $lines[] = '- ' . $ruleId . ': ' . (string)($catalog[$ruleId]['description'] ?? '');
        }

        return \implode("\n", $lines);
    }

    private function registry(): AiSiteDesignPolicyRegistry
    {
        return $this->registry ?? new AiSiteDesignPolicyRegistry();
    }
}
