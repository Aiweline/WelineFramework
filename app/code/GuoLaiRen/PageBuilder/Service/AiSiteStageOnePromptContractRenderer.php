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
            '- Block count contract: emit exactly target_blocks when present; otherwise emit min_blocks. Do not stop after required blocks when optional blocks are needed to reach target_blocks, and never exceed max_blocks.',
            '- Build handoff contract: Stage 1 block count, block_key order, visual_signature, and image_intent become the build blueprint source of truth. Do not add temporary duplicate blocks and do not expect the builder to infer missing blocks.',
            '- Required block key contract: every required_block_key must appear exactly once. The list is a coverage contract, not a forced visual sequence; choose a natural section order from page_design_plan.section_flow and place CTA/support blocks where the page narrative requires.',
            '- Required blocks skeleton for coverage, not ordering: ' . $this->json($this->requiredBlockSkeleton($pageContract)) . '.',
            '- Recommended optional blocks to use after required keys: ' . $this->json(\is_array($pageContract['recommended_optional_block_keys'] ?? null) ? $pageContract['recommended_optional_block_keys'] : []) . '.',
            '- Block key lock: required_block_keys are exact machine keys, not labels. Do not translate, pluralize, rename, or replace them with synonyms such as featured_articles, content_list, story_cards, details, overview, list, grid, or cards.',
            '- The page object must include concrete page_goal, theme_alignment_summary, page_design_plan, and a non-empty blocks array.',
            '- Blocks must stay within min/max, include every required block_key exactly once, hit target_blocks exactly when present, avoid generic block_key values, and keep block_key unique within the page.',
            '- Every block must include final visitor-facing content, complete design_tags, visual_signature, image_intent, exactly ' . AiSiteStageOneContractService::FIELD_PLAN_COUNT . ' field_plan rows, and execution_script.core_copy.',
            '- Required design_tags keys for every block: ' . $this->json(\is_array($pageContract['required_design_tag_keys'] ?? null) ? $pageContract['required_design_tag_keys'] : AiSiteStageOneContractService::DESIGN_TAG_KEYS) . '; put implementation_note inside design_tags, not beside it.',
            '- Required visual_signature keys for every block: ' . $this->json(\is_array($pageContract['visual_signature_keys'] ?? null) ? $pageContract['visual_signature_keys'] : AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS) . '. composition_pattern, surface_treatment, and media_strategy must not be identical to the adjacent block.',
            '- Visual signature rule: name the actual layout family and treatment for this block, such as split editorial hero, staggered proof rail, icon-led feature matrix, timeline band, form-support split, FAQ accordion, policy checklist, or final CTA stage. Do not repeat the same hero/card/media arrangement across page blocks.',
            '- Recommended design_tags keys: ' . $this->json(\is_array($pageContract['recommended_design_tag_keys'] ?? null) ? $pageContract['recommended_design_tag_keys'] : AiSiteStageOneContractService::RECOMMENDED_DESIGN_TAG_KEYS) . '; include them when concise, but page_design_plan.color_layering remains the hard page-level color contract.',
            '- Field plan rules: ' . $this->json($contract['field_plan_rules'] ?? []) . '.',
            '- Field plan shape: exactly 3 rows per block. Row 0 field=headline with a visitor-visible heading sample; row 1 field=supporting_copy with a visitor-visible sentence sample; row 2 field=context_detail unless a more specific key fits (cta_label, proof_detail, image_brief, form_label, or policy_summary).',
            '- Every field_plan row must include field, sample, and implementation_note. sample must be the actual text/asset brief the customer could approve as-is, not writing guidance.',
            '- field_plan.sample and field_plan.implementation_note must not be placeholders or prompt language, and must not start with write, rewrite, describe the/this block, describe the/this field, use this field, do not output, 围绕, 突出, 说明, 完善, or 优化. Visitor-facing form placeholders like "Describe your issue..." are allowed when they are actual website copy.',
            '- Visible copy must use the Website content locale and reuse concrete nouns/actions from the brief; schema placeholders and prompt instructions are invalid. Same-page blocks must not reuse the same opening message, headline, or execution_script.core_copy.',
            '- Image planning rule: ' . $this->json($contract['image_planning_rules'] ?? []) . '.',
            '- Image intent rule: every block must include image_intent. If needs_image is true, include image_role, image_subject, placement, and reuse_policy for a stable asset slot. image_subject must describe a block-level scene, product/editorial photograph, interface/product mockup, environment, people moment, or premium illustration. Page content blocks must not use SVG/icon/glyph/chevron/sparkle/avatar/logo/badge/symbol/line-art subjects as the generated image subject; those are allowed only for shared brand logo/title-icon assets outside pages. Abstract trust/reward/security/payment/download marks are not image subjects by themselves; turn them into a concrete scene such as players at a card table, a phone APK install screen, a support desk, a product interface, or an editorial brand moment. If execution_script.media_assets or visual_signature.media_strategy mentions a photo, image, screenshot, mockup, scene, hero image, banner image, background image, or avatar, then needs_image must be true.',
            '- Real media contract: when a block plans an image, screenshot, phone screen, mockup, scene, background image, or media asset, describe the actual generated asset and integration. Never say placeholder, dummy, fake image, temporary image, blank box, 占位, 占位图, 假图, 临时图片, or 占位视觉 in design_tags, visual_signature, image_intent, field_plan, or execution_script.',
            '- Non-policy page visual asset rule: every non-policy page needs at least one real generated image slot, normally on the first/opening/support block. A home/about/contact/custom page with all blocks CSS-only is invalid. Contact/support pages should use a real generated support-desk, app-help, product-interface, or customer-service scene on the first support block when no other stronger visual exists.',
            '- CSS-only image intent rule: if needs_image is false, image_role must be css_motif or none, placement must be none/background_layer/inline_visual, css_motif must be a non-empty concrete CSS visual direction, rationale must explicitly say no generated image is needed, and visual_signature.media_strategy must include the exact ASCII marker "CSS-only/no generated image" before any localized explanation. Do not leave css_motif empty and do not list photo/image/screenshot/mockup/scene media_assets in CSS-only blocks.',
            '- Opening/proof block rule: page_flow_role opening, hero, or proof must either set needs_image=true with a concrete generated subject, or set needs_image=false with the full CSS-only image intent above. Trust/security proof blocks are allowed to be CSS-only, but then css_motif must be non-empty, rationale must explain why CSS conveys trust, and visual_signature.media_strategy must start with "CSS-only/no generated image". Do not leave proof blocks undecided.',
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
            '- Return one complete replacement artifact, not a patch, not only failing blocks, and not an array fragment. The replacement must be self-contained and valid if the previous artifact is discarded.',
            '- Rebuild the whole blocks array when any issue mentions blocks, visual_signature, image_intent, field_plan, or target count. Do not append a partial fixed block to the old page and do not leave any placeholder block behind.',
            '- Target block count is mandatory during recovery: emit exactly target_blocks when present, otherwise min_blocks. Every returned block must have block_key, page_flow_role, content, complete design_tags, complete visual_signature, complete image_intent, exactly ' . AiSiteStageOneContractService::FIELD_PLAN_COUNT . ' field_plan rows, and execution_script.core_copy.',
            '- Field plan recovery rule: every field_plan.sample must be final visitor-facing copy or a concrete asset brief, never an instruction. Do not start samples with write, rewrite, describe the/this block, describe the/this field, use this field, explain, create, show, include, highlight, mention, 围绕, 突出, 说明, 完善, or 优化. Visitor-facing form placeholders like "Describe your issue..." are allowed when they are actual website copy.',
            '- Do not explain the failure. Do not remove required blocks, field_plan rows, design_tags, visual_signature, image_intent, or core_copy.',
            '- If any issue code is target_block_count_mismatch, rebuild the page with exactly target_blocks entries: required blocks plus recommended optional blocks until the exact target count is reached.',
            '- If any issue code is icon_only_image_subject, rewrite that block image_intent.image_subject into a concrete scene/product/editorial/interface/environment/people visual. Do not use icon, logo, badge, glyph, symbol, sparkle, avatar, shield mark, coin mark, download arrow, app mark, or line-art subjects for page blocks.',
            '- If any issue code is page_missing_generated_image_intent, the returned page must include at least one block with image_intent.needs_image=true. If the issue carries block_key, set that exact block to needs_image=true; otherwise set the first/opening block. Use a concrete generated scene/product/interface/support visual and remove CSS-only/no generated image markers from that block.',
            '- If any issue code is missing_css_motif_for_no_image_block, keep needs_image=false only when you add non-empty css_motif, rationale, and a visual_signature.media_strategy that includes the exact ASCII marker "CSS-only/no generated image"; otherwise set needs_image=true with a concrete generated subject.',
            '- If any issue code is instruction_like_or_empty, rewrite the affected content, core_copy, field_plan.sample, or field_plan.implementation_note as concrete site copy/rendering detail. Do not repeat validator wording or prompt instructions.',
            '- If any issue code is invented_exact_contact, remove invented emails, phone numbers, WhatsApp IDs, office addresses, business hours, and response-time claims. Use only contact values present in the source truth; otherwise route users to the selected contact page with neutral support wording.',
            '- If any issue code is policy_page_body_unsafe_cta, keep the policy/legal block neutral and factual. Remove download/install/APK/play/register/claim CTA language; if a policy must mention benefits or rewards as a data-use purpose, phrase it as neutral account/rights/benefit processing rather than a conversion offer.',
            '- If any issue code is placeholder_image_planning_forbidden, rewrite the affected block as a real generated asset plan or a complete CSS-only motif. Remove all placeholder/dummy/fake/temporary/blank-box language from design_tags, visual_signature, image_intent, field_plan, and execution_script.',
            '- If any issue says image_intent_conflicts_with_block_plan, either set needs_image=true with concrete image_role/image_subject/placement/reuse_policy, or remove photo/image/screenshot/mockup/scene media planning and provide the full CSS-only image intent: css_motif, rationale, and visual_signature.media_strategy starting with "CSS-only/no generated image".',
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
