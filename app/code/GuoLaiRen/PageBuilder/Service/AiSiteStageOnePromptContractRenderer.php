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
            '- Page route contract: ' . $this->json($this->compactRouteContract($contract)) . '.',
            '- Later page_strategy must make every selected page able to satisfy page_contracts: ' . $this->json($this->compactPageContracts($contract)) . '.',
            '- Later page blocks must carry distinct visual_signature and image_intent objects so build tasks do not reuse one section as another.',
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
            '- Exact page route contract: ' . $this->json($this->compactRouteContract($contract)) . '.',
            '- Header/footer href rule: every navigation_plan.header_items, footer_plan.featured, and footer_plan.policies href must be copied as an exact path from that field path in page_route_contract.link_groups; no domains, query strings, hashes, or anchors. When a group is empty, use only page_route_contract.allowed_internal_paths and existing selected pages. Do not invent product, studio, preorder, gift, service, singular/plural, anchor, or campaign paths unless that exact path is listed.',
            '- Do not output page blocks in this theme step. page_type_overviews must prepare each selected page to satisfy page_contracts: ' . $this->json($this->compactPageContracts($contract)) . '.',
            '- Visual quality rules: ' . $this->json($contract['visual_quality_rules'] ?? []) . '.',
            '- Visual diversity rules: ' . $this->json($contract['visual_diversity_rules'] ?? []) . '.',
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
            '- Block count contract: emit target_blocks when present; otherwise choose a count between min_blocks and max_blocks. Do not emit fewer than min_blocks or more than max_blocks.',
            '- Build handoff contract: Stage 1 block count, block_key order, visual_signature, and image_intent become the build blueprint source of truth. Do not add temporary duplicate blocks and do not expect the builder to infer missing blocks.',
            '- Required block key order: emit every required_block_key from the page contract first, in the listed order, exactly once; then add recommended_optional_block_keys in order until the target block count is reached. Add custom optional block keys only if recommended options are exhausted and max_blocks still allows.',
            '- Required blocks skeleton to copy exactly into blocks[].block_key before writing content: ' . $this->json($this->requiredBlockSkeleton($pageContract)) . '.',
            '- Recommended optional blocks to use after required keys: ' . $this->json(\is_array($pageContract['recommended_optional_block_keys'] ?? null) ? $pageContract['recommended_optional_block_keys'] : []) . '.',
            '- Block key lock: required_block_keys are exact machine keys, not labels. Do not translate, pluralize, rename, or replace them with synonyms such as featured_articles, content_list, story_cards, details, overview, list, grid, or cards.',
            '- The page object must include concrete page_goal, theme_alignment_summary, page_design_plan, and a non-empty blocks array.',
            '- Blocks must stay within min/max, include every required block_key exactly once, avoid generic block_key values, and keep block_key unique within the page.',
            '- Every block must include final visitor-facing content, complete design_tags, visual_signature, image_intent, exactly ' . AiSiteStageOneContractService::FIELD_PLAN_COUNT . ' field_plan rows, and execution_script.core_copy.',
            '- Required design_tags keys for every block: ' . $this->json(\is_array($pageContract['required_design_tag_keys'] ?? null) ? $pageContract['required_design_tag_keys'] : AiSiteStageOneContractService::DESIGN_TAG_KEYS) . '; put implementation_note inside design_tags, not beside it.',
            '- Required visual_signature keys for every block: ' . $this->json(\is_array($pageContract['visual_signature_keys'] ?? null) ? $pageContract['visual_signature_keys'] : AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS) . '. composition_pattern, surface_treatment, and media_strategy must not be identical to the adjacent block.',
            '- Visual signature rule: name the actual layout family and treatment for this block, such as split editorial hero, staggered proof rail, icon-led feature matrix, timeline band, form-support split, FAQ accordion, policy checklist, or final CTA stage. Do not repeat the same hero/card/media arrangement across page blocks.',
            '- Recommended design_tags keys: ' . $this->json(\is_array($pageContract['recommended_design_tag_keys'] ?? null) ? $pageContract['recommended_design_tag_keys'] : AiSiteStageOneContractService::RECOMMENDED_DESIGN_TAG_KEYS) . '; include them when concise, but page_design_plan.color_layering remains the hard page-level color contract.',
            '- Field plan rules: ' . $this->json($contract['field_plan_rules'] ?? []) . '.',
            '- Field plan shape: exactly 3 rows per block. Row 0 field=headline with a visitor-visible heading sample; row 1 field=supporting_copy with a visitor-visible sentence sample; row 2 field=context_detail unless a more specific key fits (cta_label, proof_detail, image_brief, form_label, or policy_summary).',
            '- Every field_plan row must include field, sample, and implementation_note. sample must be the actual text/asset brief the customer could approve as-is, not writing guidance.',
            '- field_plan.sample and field_plan.implementation_note must not be placeholders or prompt language, and must not start with write, rewrite, describe, use this field, do not output, 围绕, 突出, 说明, 完善, or 优化.',
            '- Visible copy must use the Website content locale and reuse concrete nouns/actions from the brief; schema placeholders and prompt instructions are invalid. Same-page blocks must not reuse the same opening message, headline, or execution_script.core_copy.',
            '- Image planning rule: ' . $this->json($contract['image_planning_rules'] ?? []) . '.',
            '- Image intent rule: every block must include image_intent. If needs_image is true, include image_role, image_subject, placement, and reuse_policy for a stable asset slot. image_subject must describe a block-level scene, product/editorial photograph, or premium illustration; do not use SVG/icon/glyph/chevron/sparkle/avatar badge names as the generated image subject. Opening, proof, or media_assets-driven blocks default to needs_image=true unless the block deliberately uses a CSS-only motif; do not list image/photo/screenshot/mockup/scene/icon media_assets while setting needs_image=false. If needs_image is false, include css_motif or rationale so the builder renders a deliberate CSS motif instead of a placeholder image.',
            '- Anti-duplicate rule: before sending JSON, compare adjacent blocks. If two blocks share the same headline, core_copy, composition_pattern, surface_treatment, or media_strategy, rewrite one block to a different role-specific treatment.',
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
                    'page_route_contract' => $this->compactRouteContract($contract),
                ]) . '.',
                '- Header/footer navigation is invalid if any href is not an exact path in allowed_paths for its exact page_route_contract.link_groups field path; domains, query strings, hashes, and anchors are invalid.',
                '- Full stage-one plan must include pages for every selected page type and satisfy page_contracts: ' . $this->json($this->compactPageContracts($contract)) . '.',
                '- Copy, image, and visual quality rules: ' . $this->json([
                    'copy_rules' => $contract['copy_rules'] ?? [],
                    'image_planning_rules' => $contract['image_planning_rules'] ?? [],
                    'visual_quality_rules' => $contract['visual_quality_rules'] ?? [],
                    'visual_diversity_rules' => $contract['visual_diversity_rules'] ?? [],
                    'build_handoff_rules' => $contract['build_handoff_rules'] ?? [],
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
            '- Page route contract to use for all header/footer hrefs: ' . $this->json($this->compactRouteContract($contract)) . '.',
            '- Validation issues to fix: ' . $this->json($issues) . '.',
            '- Do not explain the failure. Do not remove required blocks, field_plan rows, design_tags, visual_signature, image_intent, or core_copy.',
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
                'target_blocks' => (int)($pageContract['target_blocks'] ?? 0),
                'required_block_keys' => \is_array($pageContract['required_block_keys'] ?? null) ? $pageContract['required_block_keys'] : [],
                'recommended_optional_block_keys' => \is_array($pageContract['recommended_optional_block_keys'] ?? null) ? $pageContract['recommended_optional_block_keys'] : [],
                'field_plan_count' => (int)($pageContract['field_plan_count'] ?? AiSiteStageOneContractService::FIELD_PLAN_COUNT),
                'visual_signature_keys' => \is_array($pageContract['visual_signature_keys'] ?? null) ? $pageContract['visual_signature_keys'] : AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS,
                'image_intent_keys' => \is_array($pageContract['image_intent_keys'] ?? null) ? $pageContract['image_intent_keys'] : AiSiteStageOneContractService::IMAGE_INTENT_KEYS,
                'block_count_handoff_required' => !empty($pageContract['block_count_handoff_required']),
            ];
        }

        return $compact;
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, mixed>
     */
    private function compactRouteContract(array $contract): array
    {
        $routeContract = \is_array($contract['page_route_contract'] ?? null) ? $contract['page_route_contract'] : [];

        return [
            'routes_by_type' => \is_array($routeContract['routes_by_type'] ?? null) ? $routeContract['routes_by_type'] : [],
            'allowed_internal_paths' => \is_array($routeContract['allowed_internal_paths'] ?? null) ? $routeContract['allowed_internal_paths'] : [],
            'link_groups' => \is_array($routeContract['link_groups'] ?? null) ? $routeContract['link_groups'] : [],
            'header_route_types' => \is_array($routeContract['header_route_types'] ?? null) ? $routeContract['header_route_types'] : [],
            'footer_featured_route_types' => \is_array($routeContract['footer_featured_route_types'] ?? null) ? $routeContract['footer_featured_route_types'] : [],
            'footer_policy_route_types' => \is_array($routeContract['footer_policy_route_types'] ?? null) ? $routeContract['footer_policy_route_types'] : [],
        ];
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
