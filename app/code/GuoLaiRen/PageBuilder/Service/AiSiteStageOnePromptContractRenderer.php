<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteStageOnePromptContractRenderer
{
    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    public function renderRequirementBriefing(array $contract): array
    {
        return [
            'DOWNSTREAM STAGE-1 CONTRACT BRIEFING (do not output pages in this step; write requirement_expansion so later prompts can pass this exact contract):',
            '- Contract version/hash: ' . $this->contractId($contract) . '.',
            '- Selected page types: ' . $this->json($contract['page_types'] ?? []) . '.',
            '- Later page_strategy must make every selected page able to satisfy page_contracts: ' . $this->json($this->compactPageContracts($contract)) . '.',
            '- Later theme/page prompts require concrete nouns, offers, proof, CTAs, visual cues, and assumptions from the user brief; abstract methodology text is invalid.',
            '- If a fact is unknown, write a concrete assumption for editable fields instead of placeholders.',
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    public function renderThemeContract(array $contract): array
    {
        return [
            'STAGE-1 SHARED CONTRACT (non-negotiable; this theme response feeds the page contract validator):',
            '- Contract version/hash: ' . $this->contractId($contract) . '.',
            '- Required shared sections: ' . $this->json($contract['theme_required_sections'] ?? []) . '.',
            '- Required theme fields: ' . $this->json($contract['theme_required_fields'] ?? []) . '.',
            '- Required shared link lists: ' . $this->json($contract['shared_link_requirements'] ?? []) . '; each row needs real label and href.',
            '- Do not output page blocks in this theme step. page_type_overviews must prepare each selected page to satisfy page_contracts: ' . $this->json($this->compactPageContracts($contract)) . '.',
            '- Visual quality rules: ' . $this->json($contract['visual_quality_rules'] ?? []) . '.',
            '- Before sending JSON, silently validate the response against this contract. Shorten strings instead of omitting required fields.',
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    public function renderPageContract(array $contract, string $pageType): array
    {
        $pageContract = \is_array($contract['page_contracts'][$pageType] ?? null) ? $contract['page_contracts'][$pageType] : [];

        return [
            'STAGE-1 PAGE CONTRACT (non-negotiable; this page response will be rejected if any item fails):',
            '- Contract version/hash: ' . $this->contractId($contract) . '.',
            '- Page contract for ' . $pageType . ': ' . $this->json($pageContract) . '.',
            '- First-pass is mandatory: do not depend on recovery. A response that needs repair is not accepted for first-pass validation even if the repaired page later passes.',
            '- Required block key order: emit every required_block_key from the page contract first, in the listed order, exactly once; add optional page-specific blocks only after those required blocks and only if max_blocks allows.',
            '- Required blocks skeleton to copy exactly into blocks[].block_key before writing content: ' . $this->json($this->requiredBlockSkeleton($pageContract)) . '.',
            '- Block key lock: required_block_keys are exact machine keys, not labels. Do not translate, pluralize, rename, or replace them with synonyms such as featured_articles, content_list, story_cards, details, overview, list, grid, or cards.',
            '- The page object must include concrete page_goal, theme_alignment_summary, page_design_plan, and a non-empty blocks array.',
            '- Blocks must stay within min/max, include every required block_key exactly once, avoid generic block_key values, and keep block_key unique within the page.',
            '- Every block must include final visitor-facing content, complete design_tags, exactly ' . AiSiteStageOneContractService::FIELD_PLAN_COUNT . ' field_plan rows, and execution_script.core_copy.',
            '- Every field_plan row must include field, sample, and implementation_note; sample must be final website copy or a concrete asset brief, not writing guidance.',
            '- Visible copy must use the Website content locale and reuse concrete nouns/actions from the brief; schema placeholders and prompt instructions are invalid.',
            '- Image planning rule: ' . $this->json($contract['image_planning_rules'] ?? []) . '.',
            '- Before sending JSON, silently validate this page against the contract and rewrite any failing block. Do not output the checklist itself.',
        ];
    }

    /**
     * @param array<string, mixed> $contract
     * @return list<string>
     */
    public function renderFullContract(array $contract): array
    {
        return \array_merge(
            [
                'STAGE-1 FULL PLAN CONTRACT (non-negotiable; the queue will reject the output if any item fails):',
                '- Contract version/hash: ' . $this->contractId($contract) . '.',
                '- Shared sections and links must satisfy: ' . $this->json([
                    'theme_required_sections' => $contract['theme_required_sections'] ?? [],
                    'theme_required_fields' => $contract['theme_required_fields'] ?? [],
                    'shared_link_requirements' => $contract['shared_link_requirements'] ?? [],
                ]) . '.',
                '- Full stage-one plan must include pages for every selected page type and satisfy page_contracts: ' . $this->json($this->compactPageContracts($contract)) . '.',
                '- Copy, image, and visual quality rules: ' . $this->json([
                    'copy_rules' => $contract['copy_rules'] ?? [],
                    'image_planning_rules' => $contract['image_planning_rules'] ?? [],
                    'visual_quality_rules' => $contract['visual_quality_rules'] ?? [],
                ]) . '.',
                '- No local content fallback exists after this response. If the response is long, shorten copy instead of dropping required structure.',
            ]
        );
    }

    /**
     * @param array<string, mixed> $contract
     * @param array<string, mixed> $validationReport
     * @return list<string>
     */
    public function renderRepairContract(array $contract, array $validationReport): array
    {
        $issues = \array_slice(\is_array($validationReport['issues'] ?? null) ? $validationReport['issues'] : [], 0, 20);

        return [
            'RECOVERY CONTRACT: rewrite only the failing Stage-1 artifact and return strict JSON.',
            '- Contract version/hash: ' . $this->contractId($contract) . '.',
            '- Validation issues to fix: ' . $this->json($issues) . '.',
            '- Do not explain the failure. Do not remove required blocks, field_plan rows, design_tags, or core_copy.',
            '- This recovery marks first_pass=false even when it succeeds; normal product flow may continue only if validation passes.',
        ];
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function contractId(array $contract): string
    {
        $version = \trim((string)($contract['contract_version'] ?? AiSiteStageOneContractService::CONTRACT_VERSION));
        $hash = \trim((string)($contract['contract_hash'] ?? ''));

        return $version . ($hash !== '' ? ('#' . \substr($hash, 0, 12)) : '');
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function compactPageContracts(array $contract): array
    {
        $compact = [];
        foreach (\is_array($contract['page_contracts'] ?? null) ? $contract['page_contracts'] : [] as $pageType => $pageContract) {
            if (!\is_array($pageContract)) {
                continue;
            }
            $compact[(string)$pageType] = [
                'min_blocks' => (int)($pageContract['min_blocks'] ?? 0),
                'max_blocks' => (int)($pageContract['max_blocks'] ?? 0),
                'required_block_keys' => \is_array($pageContract['required_block_keys'] ?? null) ? $pageContract['required_block_keys'] : [],
                'field_plan_count' => (int)($pageContract['field_plan_count'] ?? AiSiteStageOneContractService::FIELD_PLAN_COUNT),
            ];
        }

        return $compact;
    }

    private function json(mixed $value): string
    {
        return (string)(\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '[]');
    }

    /**
     * @param array<string, mixed> $pageContract
     * @return list<array<string, mixed>>
     */
    private function requiredBlockSkeleton(array $pageContract): array
    {
        $required = \is_array($pageContract['required_block_keys'] ?? null) ? $pageContract['required_block_keys'] : [];
        $fieldPlanCount = (int)($pageContract['field_plan_count'] ?? AiSiteStageOneContractService::FIELD_PLAN_COUNT);
        $skeleton = [];
        foreach ($required as $blockKey) {
            $blockKey = \trim((string)$blockKey);
            if ($blockKey === '') {
                continue;
            }
            $skeleton[] = [
                'block_key' => $blockKey,
                'field_plan_rows' => $fieldPlanCount,
                'required_fields' => [
                    'content',
                    'design_tags',
                    'execution_script.core_copy',
                ],
            ];
        }

        return $skeleton;
    }
}
