<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

use GuoLaiRen\PageBuilder\Service\AiSiteDesignPolicyRegistry;

final class BuildPlanDesignPolicyLinter
{
    public function __construct(
        private readonly ?AiSiteDesignPolicyRegistry $registry = null,
        private readonly ?BuildPlanContractSchema $schema = null
    ) {
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $contract): array
    {
        $errors = [];
        $policyRef = \is_array($contract['policy_ref'] ?? null) ? $contract['policy_ref'] : [];
        $policyId = \trim((string)($policyRef['policy_id'] ?? ''));
        if ($policyId === '' || !$this->registry()->hasPolicy($policyId)) {
            $errors[] = 'policy_ref.policy_id must reference a registered design policy';
            $policyId = AiSiteDesignPolicyRegistry::DEFAULT_POLICY_ID;
        }
        if (\trim((string)($policyRef['policy_version'] ?? '')) === '') {
            $errors[] = 'policy_ref.policy_version is required';
        }

        $source = \is_array($contract['source_of_truth'] ?? null) ? $contract['source_of_truth'] : [];
        $sourcePolicyId = \trim((string)($source['design_policy_id'] ?? ''));
        if ($sourcePolicyId !== '' && $sourcePolicyId !== $policyId) {
            $errors[] = 'source_of_truth.design_policy_id must match policy_ref.policy_id';
        }

        $projection = \is_array($contract['policy_projection'] ?? null) ? $contract['policy_projection'] : [];
        $appliedRuleIds = $this->stringList($projection['applied_rule_ids'] ?? []);
        if ($appliedRuleIds === []) {
            $errors[] = 'policy_projection.applied_rule_ids must not be empty';
        }
        foreach (\array_merge(
            $appliedRuleIds,
            $this->stringList($projection['banned_rule_ids'] ?? [])
        ) as $ruleId) {
            if (!$this->registry()->hasRule($ruleId, $policyId)) {
                $errors[] = 'Unknown design policy rule id: ' . $ruleId;
            }
        }

        $userStyle = \trim((string)($source['user_style'] ?? $source['explicit_style'] ?? ''));
        if ($userStyle !== '' && !\is_array($projection['user_overrides'] ?? null)) {
            $errors[] = 'policy_projection.user_overrides is required when source_of_truth declares user style';
        }

        $designManifest = \is_array($contract['design_manifest'] ?? null) ? $contract['design_manifest'] : [];
        $tokens = \is_array($designManifest['tokens'] ?? null) ? $designManifest['tokens'] : [];
        foreach ($this->schema()->requiredDesignTokenGroups() as $group) {
            if (!\is_array($tokens[$group] ?? null) || $tokens[$group] === []) {
                $errors[] = 'design_manifest.tokens.' . $group . ' is required';
            }
        }

        foreach ($this->normalizeRecordSet($contract['blocks'] ?? [], ['block_id', 'id']) as $blockId => $block) {
            $blockType = \strtolower((string)($block['block_type'] ?? $block['type'] ?? ''));
            if ($this->isImageRelatedBlock($blockType)) {
                $visual = \is_array($block['visual'] ?? null) ? $block['visual'] : [];
                if (\trim((string)($visual['image_integration'] ?? '')) === '') {
                    $errors[] = 'Image-related block is missing visual.image_integration: ' . $blockId;
                }
            }
        }

        foreach ($this->registry()->get($policyId)['banned_patterns'] ?? [] as $pattern) {
            $pattern = \trim((string)$pattern);
            if ($pattern !== '' && $this->containsPatternInBuildContract($contract, $pattern)) {
                $errors[] = 'Banned design pattern appears in build contract: ' . $pattern;
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => \array_values(\array_unique($errors)),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    /**
     * @param list<string> $idFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRecordSet(mixed $items, array $idFields): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $id = '';
            foreach ($idFields as $field) {
                $id = \trim((string)($item[$field] ?? ''));
                if ($id !== '') {
                    break;
                }
            }
            if ($id === '' && \is_string($key)) {
                $id = $key;
            }
            if ($id !== '') {
                $normalized[$id] = $item;
            }
        }

        return $normalized;
    }

    private function isImageRelatedBlock(string $blockType): bool
    {
        foreach (['image', 'media', 'gallery', 'photo', 'visual'] as $needle) {
            if (\str_contains($blockType, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function containsPatternInBuildContract(array $contract, string $pattern): bool
    {
        $scan = [
            'pages' => $contract['pages'] ?? [],
            'blocks' => $contract['blocks'] ?? [],
        ];
        $haystack = \strtolower((string)\json_encode($scan, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));

        return \str_contains($haystack, \strtolower($pattern));
    }

    private function registry(): AiSiteDesignPolicyRegistry
    {
        return $this->registry ?? new AiSiteDesignPolicyRegistry();
    }

    private function schema(): BuildPlanContractSchema
    {
        return $this->schema ?? new BuildPlanContractSchema();
    }
}
