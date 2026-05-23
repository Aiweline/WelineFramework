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
            '- Template/example guard: style templates, component default configs, layout JSON, and examples are structure references only. Later outputs must not copy stale template brands or example copy into visible site plans.',
            '- No extra explanatory keys: do not add fields named reason, why, rationale, thinking, analysis, explanation, chain_of_thought, design_reason, or reasoning anywhere unless the current schema explicitly lists the field. Theme selection_reason is allowed only where the theme schema lists it.',
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
            '- Section anchors are separate from route links. Do not output #games, #download, #contact, or href="#" in header/footer plans unless a section_anchor_contract with real rendered target ids is explicitly provided.',
            '- Brand/source-truth rule: use the current site identity from the user brief/source truth. Template brands and example brands are forbidden as visible plan content unless they exactly match the current site identity.',
            '- Do not output page blocks in this theme step. page_type_overviews must prepare each selected page to satisfy page_contracts: ' . $this->json($this->compactPageContracts($contract)) . '.',
            '- Visual quality rules: ' . $this->json($contract['visual_quality_rules'] ?? []) . '.',
            '- Visual diversity rules: ' . $this->json($contract['visual_diversity_rules'] ?? []) . '.',
            $this->noExtraExplanatoryFieldRule(),
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
            'STAGE-1 PAGE CONTRACT (structural contract is non-negotiable; visual/copy guidance is diagnostic unless required structure is missing):',
            '- Contract version/hash: ' . $this->contractId($contract) . '.',
            '- Page contract for ' . $pageType . ': ' . $this->json($pageContract) . '.',
            '- First-pass is mandatory: do not depend on recovery. A response that needs repair is not accepted for first-pass validation even if the repaired page later passes.',
            '- Block count contract: emit exactly target_blocks when present; otherwise emit min_blocks. Do not stop after required blocks when optional blocks are needed to reach target_blocks, and never exceed max_blocks.',
            '- Build handoff contract: Stage 1 block count, block_key order, visual_signature, and image_intent become the build blueprint source of truth. Do not add temporary duplicate blocks and do not expect the builder to infer missing blocks.',
            $this->noExtraExplanatoryFieldRule(),
            '- Required block key contract: every required_block_key must appear exactly once. The list is a coverage contract, not a forced visual sequence; choose a natural section order from page_design_plan.section_flow and place CTA/support blocks where the page narrative requires.',
            '- Required blocks skeleton for coverage, not ordering: ' . $this->json($this->requiredBlockSkeleton($pageContract)) . '.',
            '- Recommended optional blocks to use after required keys: ' . $this->json(\is_array($pageContract['recommended_optional_block_keys'] ?? null) ? $pageContract['recommended_optional_block_keys'] : []) . '.',
            '- Block key lock: required_block_keys are exact machine keys, not labels. Do not translate, pluralize, rename, or replace them with synonyms such as featured_articles, content_list, story_cards, details, overview, list, grid, or cards.',
            '- The page object must include page_goal, theme_alignment_summary, page_design_plan, and a non-empty blocks array. The hard gate checks the object shape and block structure; weak wording is repair guidance, not a structural failure.',
            '- Blocks must stay within min/max, include every required block_key exactly once, hit target_blocks exactly when present, avoid generic block_key values, and keep block_key unique within the page.',
            '- Every block must include the required structural fields: content, design_tags, visual_signature, image_intent, exactly ' . AiSiteStageOneContractService::FIELD_PLAN_COUNT . ' field_plan rows, and execution_script.core_copy. Write visitor-facing copy where possible, but the hard gate is structure plus prompt/placeholder leakage.',
            '- Required design_tags keys for every block: ' . $this->json(\is_array($pageContract['required_design_tag_keys'] ?? null) ? $pageContract['required_design_tag_keys'] : AiSiteStageOneContractService::DESIGN_TAG_KEYS) . '; put implementation_note inside design_tags, not beside it.',
            '- Required visual_signature keys for every block: ' . $this->json(\is_array($pageContract['visual_signature_keys'] ?? null) ? $pageContract['visual_signature_keys'] : AiSiteStageOneContractService::VISUAL_SIGNATURE_KEYS) . '. composition_pattern, spatial_rhythm, media_strategy, surface_treatment, and interaction_pattern must each be concrete and non-empty. Static content should still write a block-specific pattern such as "static reading panel; focus-visible links only; no ambient motion" instead of an empty string. Adjacent duplicate checks are quality diagnostics; they should guide variety but must not be treated as a reason to invent unrelated blocks.',
            '- Visual signature rule: name the actual layout family and treatment for this block, such as split editorial hero, staggered proof rail, icon-led feature matrix, timeline band, form-support split, FAQ accordion, policy checklist, or final CTA stage. Do not repeat the same hero/card/media arrangement across page blocks.',
            '- Visual signature examples (copy shape only; rewrite for current page/block): ' . $this->json($this->visualSignatureExamples()) . '.',
            '- Creativity rule: this contract is a frame, not a template. Use the required structure to invent page-specific composition, rhythm, surface detail, motion, and content intent. Do not copy example wording or make every block look like a checklist/card stack.',
            '- Recommended design_tags keys: ' . $this->json(\is_array($pageContract['recommended_design_tag_keys'] ?? null) ? $pageContract['recommended_design_tag_keys'] : AiSiteStageOneContractService::RECOMMENDED_DESIGN_TAG_KEYS) . '; include them when concise. page_design_plan.color_layering is page-level design guidance, not a wording gate.',
            '- Field plan rules: ' . $this->json($contract['field_plan_rules'] ?? []) . '.',
            '- Field plan shape: exactly 3 rows per block. Row 0 field=headline with a visitor-visible heading sample; row 1 field=supporting_copy with a visitor-visible sentence sample; row 2 field=context_detail unless a more specific key fits (cta_label, proof_detail, image_brief, form_label, or policy_summary).',
            '- Field plan intent examples (choose fields by block intent, not by block_key text alone): ' . $this->json($this->fieldPlanIntentExamples()) . '.',
            '- Visible body copy handoff: every block, including contact_cta/final_cta/download_cta blocks, should contain at least one real visitor-facing body sentence in execution_script.core_copy, field_plan row 1 supporting_copy, realtime_content.supporting_copy, feature_points, or content. This is generation guidance; the hard contract is the presence of the required structure.',
            '- CTA block copy rule: cta/contact_cta/final_cta/download_cta blocks must include both a visible supporting sentence and an explicit action label. The supporting sentence must explain the next visitor step without inventing contact values, response times, deposit/withdrawal promises, or unsupported rewards.',
            '- Every field_plan row must include field, sample, and implementation_note. sample should be the actual text/asset brief the customer could approve as-is; writing guidance is a quality issue unless it leaks into generated visitor HTML.',
            '- field_plan.sample and field_plan.implementation_note must not be placeholders or prompt language, and must not start with write, rewrite, describe the/this block, describe the/this field, use this field, do not output, 围绕, 突出, 说明, 完善, or 优化. Visitor-facing form placeholders like "Describe your issue..." are allowed when they are actual website copy.',
            '- Visible copy should use the Website content locale and reuse concrete nouns/actions from the brief and frozen page/block plan. Schema placeholders, prompt instructions, validator wording, and internal blueprint labels must never become generated visitor HTML.',
            '- Brand/source-truth rule: page_goal, content, field_plan samples, core_copy, visual alt ideas, CTA labels, SEO keywords, and footer/header notes must use the current site identity. Do not reuse style-template/example brands such as LudoEmpire, PokerArena, Poker Arena, Satta King 786, Satta King, BharatPlay, RummyRoyal, or Teen Patti Royal unless one is exactly the user-approved site name.',
            '- Image planning rule: ' . $this->json($contract['image_planning_rules'] ?? []) . '.',
            '- Per-block art planning rule: Stage 1 owns the block art plan. For every block, visual_signature must explain the exact atmosphere and composition, and image_intent must decide whether this block uses a generated image, a CSS-only visual companion, or no media. Build will execute this frozen plan; it must not guess the atmosphere, image subject, or placement later.',
            '- Build role boundary: plan every block as if build can only fill html_content, css, js, php_variables, and editable fields from the frozen block context. Do not leave layout rhythm, media need, image placement, CTA treatment, or atmosphere as an implicit decision for build.',
            '- image_intent.needs_image type rule: needs_image MUST be the JSON boolean true or false. Never return "yes", "no", "maybe", "optional", "CSS-only", an empty string, or explanatory text in needs_image; put visual planning detail in media_strategy, css_motif, visual_atmosphere, and image_treatment.',
            '- needs_image GOOD/BAD examples: GOOD {"needs_image":true}; GOOD {"needs_image":false}; BAD {"needs_image":"CSS-only"}; BAD {"needs_image":"no"}; BAD {"needs_image":{"value":false}}.',
            '- Image intent location rule: output image_intent only at the block top level. Do not output visual.image_intent, nested image_intent copies, rationale, reason, or why fields. Duplicated nested image_intent can fail the same gate twice.',
            '- Image intent rule: every block must include image_intent. If needs_image is true, include image_role, image_subject, placement, visual_atmosphere, image_treatment, and reuse_policy for a stable asset slot, and visual_signature.media_strategy must describe how that image integrates with text/cards/CTA instead of saying CSS-only/no generated image. image_subject must describe a block-level scene, product/editorial photograph, interface/product mockup, environment, people moment, or premium illustration. Page content blocks must not use SVG/icon/glyph/chevron/sparkle/avatar/logo/badge/symbol/line-art subjects as the generated image subject; those are allowed only for shared brand logo/title-icon assets outside pages. Abstract trust/reward/security/payment/download marks are not image subjects by themselves; turn them into a concrete scene such as players at a card table, a phone APK install screen, a support desk, a product interface, or an editorial brand moment. If execution_script.media_assets or visual_signature.media_strategy mentions a photo, image, screenshot, mockup, scene, hero image, banner image, background image, or avatar, then needs_image must be true.',
            '- Icon/decorative visual boundary: small icons, badges, arrows, dividers, chips, rating stars, initials, and abstract marks should normally be CSS/SVG/icon-font motifs inside design_tags or css_motif with needs_image=false. Use needs_image=true only for a real generated scene/product/interface/editorial visual, not for an isolated icon.',
            '- CSS-only image_intent examples by common block_key: ' . $this->json($this->cssOnlyImageIntentExamples()) . '.',
            '- Complete block return examples (copy the shape, not the exact content; rewrite for the current page/locale/block): ' . $this->json($this->blockReturnExamples()) . '.',
            '- Image placement rule: placement must be a concrete relationship to the block layout, such as background_layer, media_panel, side_poster, card_rail, avatar_rail, phone_mockup, rulebook_panel, support_console, or inline_visual. Do not write vague placement such as "nice image" or "visual".',
            '- No-image rule: if needs_image is false, still plan the visual companion. image_role must be css_motif or none, placement must be none/background_layer/inline_visual, css_motif must describe a concrete CSS visual surface, visual_atmosphere must describe the intended mood, and image_treatment must say how CSS replaces the image.',
            '- Page image rhythm rule: non-policy pages should not end up with only the opening/banner image. Major body blocks such as benefits, game showcase, trust/proof, reviews, support/contact, FAQ/rules, and final CTA should each plan either a generated image or a CSS-only visual companion that matches its block identity. Dense legal text may choose no image with a document/rulebook CSS surface.',
            '- Non-policy opening image direction: for home_page, about_page, contact_page, and custom marketing pages, prefer a concrete generated scene/product/interface subject in the first/opening block so the later build has a strong media target.',
            '- Page media placement is flexible: do not force blocks[0] to be the generated-image block when the page narrative works better with text, stats, FAQ, reviews, or support content first.',
            '- Preferred generated-image target examples by page type: ' . $this->json($this->generatedImageTargetExamples()) . '. Prefer these required/opening/support/article blocks when present, but keep narrative fit and still return all required blocks.',
            '- Real media contract: when a block plans an image, screenshot, phone screen, mockup, scene, background image, or media asset, describe the actual generated asset and integration. Never say placeholder, dummy, fake image, temporary image, blank box, 占位, 占位图, 假图, 临时图片, or 占位视觉 in design_tags, visual_signature, image_intent, field_plan, or execution_script.',
            '- Non-policy page visual asset rule: every non-policy page should plan at least one real generated image slot, normally on the first/opening/support block. Contact/support pages should use a real generated support-desk, app-help, product-interface, or customer-service scene on the first support block when no other stronger visual exists.',
            '- CSS-only image intent rule: if needs_image is false, image_role must be css_motif or none, placement must be none/background_layer/inline_visual, css_motif must be a non-empty concrete CSS visual direction, visual_atmosphere/image_treatment must be non-empty, and visual_signature.media_strategy must include the exact ASCII marker "CSS-only/no generated image" before any localized explanation. Do not leave css_motif empty and do not list photo/image/screenshot/mockup/scene media_assets in CSS-only blocks.',
            '- CSS-only media assets rule: when image_intent.needs_image=false, execution_script.media_assets must be an empty array []. Do not put avatar/photo/image/screenshot/mockup/scene asset names in CSS-only review, proof, FAQ, support, or CTA blocks.',
            '- Opening/proof block rule: page_flow_role opening, hero, or proof must either set needs_image=true with a concrete generated subject, or set needs_image=false with the full CSS-only image intent above. Trust/security proof blocks are allowed to be CSS-only, but then css_motif must be non-empty and visual_signature.media_strategy must start with "CSS-only/no generated image". Do not leave proof blocks undecided.',
            '- Policy first-block lock: for privacy_policy and terms_of_service, the first block must be a neutral policy summary only. Do not inherit the site primary CTA or conversion wording such as free download, install now, play, register, claim, reward, bonus, coins, or app-download in content, field_plan samples, or core_copy. A neutral legal applicability sentence may mention that the policy applies when visitors download or use the APK/app.',
            '- Anti-duplicate rule: before sending JSON, compare adjacent blocks. Avoid duplicate headline/core_copy and duplicate visual_signature so later blocks do not reuse one shell. This is a quality diagnostic; structure still comes from block keys and required fields.',
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
                'STAGE-1 FULL PLAN CONTRACT (structural fields are non-negotiable; quality guidance is diagnostic):',
                '- Contract version/hash: ' . $this->contractId($contract) . '.',
                '- Shared sections and links must satisfy: ' . $this->json([
                    'theme_required_sections' => $contract['theme_required_sections'] ?? [],
                    'theme_required_fields' => $contract['theme_required_fields'] ?? [],
                    'shared_link_requirements' => $contract['shared_link_requirements'] ?? [],
                    'page_route_contract' => $this->compactRouteContract($contract),
                ]) . '.',
                '- Header/footer navigation is invalid if any href is not an exact path in allowed_paths for its exact page_route_contract.link_groups field path; domains, query strings, hashes, and anchors are invalid.',
                '- Header/footer navigation must not use page-scroll anchors such as #games/#download or placeholder href="#"; if section anchors are desired, they need a separate explicit section_anchor_contract with real rendered ids.',
                '- Full stage-one plan must include pages for every selected page type and satisfy page_contracts: ' . $this->json($this->compactPageContracts($contract)) . '.',
                '- Copy, image, and visual quality rules: ' . $this->json([
                    'copy_rules' => $contract['copy_rules'] ?? [],
                    'image_planning_rules' => $contract['image_planning_rules'] ?? [],
                    'visual_quality_rules' => $contract['visual_quality_rules'] ?? [],
                    'visual_diversity_rules' => $contract['visual_diversity_rules'] ?? [],
                    'build_handoff_rules' => $contract['build_handoff_rules'] ?? [],
                ]) . '.',
                '- Complete page block examples (copy the shape, not the exact content; rewrite for the current page/locale/block): ' . $this->json($this->blockReturnExamples()) . '.',
                '- Field plan intent examples: ' . $this->json($this->fieldPlanIntentExamples()) . '.',
                '- The examples define structure and intent mapping only. The plan should still be creative inside the framework: page-specific rhythm, visual signature, texture, motion, and visitor copy must come from the brief and page goal.',
                '- Example/template copy rule: examples, style template names, component default values, and old generated site text are not content sources. Rewrite every visible plan string for the current brand/source truth.',
                $this->noExtraExplanatoryFieldRule(),
                '- image_intent.needs_image MUST be JSON boolean true or false, never a string, empty value, or descriptive phrase.',
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
            $this->noExtraExplanatoryFieldRule(),
            '- Rebuild the whole blocks array when any issue mentions blocks, visual_signature, image_intent, field_plan, or target count. Do not append a partial fixed block to the old page and do not leave any placeholder block behind.',
            '- Target block count is mandatory during recovery: emit exactly target_blocks when present, otherwise min_blocks. Every returned block must have block_key, page_flow_role, content, complete design_tags, complete visual_signature, complete image_intent, exactly ' . AiSiteStageOneContractService::FIELD_PLAN_COUNT . ' field_plan rows, and execution_script.core_copy.',
            '- Field plan recovery rule: every field_plan.sample must be final visitor-facing copy or a concrete asset brief, never an instruction. Do not start samples with write, rewrite, describe the/this block, describe the/this field, use this field, explain, create, show, include, highlight, mention, 围绕, 突出, 说明, 完善, or 优化. Visitor-facing form placeholders like "Describe your issue..." are allowed when they are actual website copy.',
            '- Visible body recovery rule: every repaired block must have visitor-facing body copy that BuildPlan can consume. contact_cta/final_cta/download_cta blocks need a supporting_copy/body sentence plus a separate cta_label/action label; do not return only a button label or layout instruction.',
            '- Do not explain the failure. Do not remove required blocks, field_plan rows, design_tags, visual_signature, image_intent, or core_copy.',
            '- Missing-structure issue codes: missing_page_design_plan, malformed_block, missing_block_key, missing_page_flow_role, missing_design_tag, missing_visual_signature, missing_image_intent, missing_field_plan, invalid_field_plan_count, and malformed_field_plan_row all mean the replacement must return a full page object with every required block and every required nested object. Do not patch only the named field.',
            '- Theme issue code missing_theme_field means rebuild the full shared theme artifact with every required theme_design field, color_scheme field, typography_spacing_radius field, navigation_plan, footer_plan, shared_components, and seo_strategy. Do not return page blocks in a theme repair.',
            '- Order issue code required_block_order_mismatch means keep every required block_key unchanged and place it at the expected index from the issue/contract. Do not satisfy order by renaming a different block.',
            '- Link issue codes: missing_link_list, invalid_link_row, link_href_not_exact_route_path, and link_href_not_in_route_contract mean header/footer link rows must be rebuilt as {label, href} with href copied exactly from page_route_contract allowed paths. Do not invent anchors, query strings, domains, or translated slugs.',
            '- Visual issue codes: invalid_visual_signature, adjacent_visual_signature_duplicate, duplicate_block_message, and overused_composition_pattern mean rewrite the affected block with a concrete block-specific composition, spatial rhythm, media strategy, surface treatment, interaction pattern, headline, and core_copy. Do not solve visual diversity by adding unrelated blocks or renaming block keys.',
            '- Image field issue code invalid_image_intent_field means keep the image_intent object and fill the missing/weak field with concrete image role, subject, placement, visual_atmosphere, image_treatment, and reuse_policy. Do not add rationale/reason fields.',
            '- If any issue code is target_block_count_mismatch, rebuild the page with exactly target_blocks entries: required blocks plus recommended optional blocks until the exact target count is reached.',
            '- If any issue code is missing_required_block_key, required_block_key_coverage_missing, or any path says blocks.block_key, rebuild the page blocks from the page_contract required_block_keys. Include each required key exactly once, keep natural page_design_plan order, and never satisfy the issue by renaming unrelated blocks or deleting optional blocks below target count.',
            '- During recovery, preserve the page-specific creative direction where possible. Fix the failed contract fields without flattening the page into generic cards or copying examples verbatim.',
            '- If any issue code is icon_only_image_subject, rewrite that block image_intent.image_subject into a concrete scene/product/editorial/interface/environment/people visual. Do not use icon, logo, badge, glyph, symbol, sparkle, avatar, shield mark, coin mark, download arrow, app mark, or line-art subjects for page blocks.',
            '- If any issue code is page_missing_generated_image_intent, the returned page must include at least one block with image_intent.needs_image=true. Prefer the named block when the issue carries block_key, but do not force blocks[0] to be the generated-image block when another page block is the better media target. Use a concrete generated scene/product/interface/support visual and remove CSS-only/no generated image markers from the generated-image block.',
            '- If any issue code is invalid_image_intent_needs_image, rewrite image_intent.needs_image as the JSON boolean true or false only. Do not use strings such as "yes", "no", "CSS-only", "optional", or any explanatory phrase. Use these examples as the target shape: ' . $this->json($this->blockReturnExamples()) . '.',
            '- If any issue code is missing_css_motif_for_no_image_block, keep needs_image=false only when you add non-empty css_motif, visual_atmosphere, image_treatment, and a visual_signature.media_strategy that includes the exact ASCII marker "CSS-only/no generated image"; otherwise set needs_image=true with a concrete generated subject.',
            '- If any issue code is instruction_like_or_empty, rewrite the affected content, core_copy, field_plan.sample, or field_plan.implementation_note as concrete site copy/rendering detail. Do not repeat validator wording or prompt instructions.',
            '- If any issue code is missing_visible_body_copy, rewrite the affected block with a real visitor-facing body sentence in execution_script.core_copy and field_plan row 1 field=supporting_copy. If the block is a CTA, keep the action label in a separate cta_label/action field and do not use the button label as the only body copy.',
            '- If any issue code is placeholder_image_planning_forbidden, rewrite the affected block as a real generated asset plan or a complete CSS-only motif. Remove all placeholder/dummy/fake/temporary/blank-box language from design_tags, visual_signature, image_intent, field_plan, and execution_script.',
            '- If any issue says image_intent_conflicts_with_block_plan, align the whole block to one media path. For needs_image=true, keep the generated image slot and rewrite visual_signature.media_strategy plus media_assets to describe the real generated asset; remove CSS-only/no generated image markers. For needs_image=false, remove photo/image/screenshot/mockup/scene media planning and provide the full CSS-only image intent: css_motif, visual_atmosphere, image_treatment, and visual_signature.media_strategy starting with "CSS-only/no generated image".',
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

    private function noExtraExplanatoryFieldRule(): string
    {
        return '- BuildPlan no-reason field rule: do not add extra explanatory keys named reason, why, rationale, thinking, analysis, explanation, chain_of_thought, design_reason, or reasoning anywhere inside page/block/design_tags/visual_signature/image_intent/field_plan/execution_script objects. Use only schema-listed keys. Theme selection_reason is allowed only where the theme schema explicitly lists it; do not invent selection_reason on page or block objects.';
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
                'first_block_requires_generated_image' => !empty($pageContract['first_block_requires_generated_image']),
                'first_generated_image_block_key' => \trim((string)($pageContract['first_generated_image_block_key'] ?? '')),
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
     * @return array<string, mixed>
     */
    private function blockReturnExamples(): array
    {
        return [
            'generated_image_block' => [
                'block_key' => 'hero',
                'page_flow_role' => 'opening',
                'content' => 'Download the APK and start playing trusted card games today.',
                'visual_signature' => [
                    'composition_pattern' => 'split hero with phone mockup',
                    'spatial_rhythm' => 'copy left, generated product visual right',
                    'media_strategy' => 'Generated hero image sits beside the CTA as a rounded phone mockup',
                    'surface_treatment' => 'dark felt gradient with gold rim light',
                    'interaction_pattern' => 'CTA hover glow and subtle image parallax',
                ],
                'image_intent' => [
                    'needs_image' => true,
                    'image_role' => 'hero_image',
                    'image_subject' => 'phone APK install screen with playing-card table behind it',
                    'placement' => 'media_panel',
                    'visual_atmosphere' => 'premium trusted game-lobby mood with warm gold lighting',
                    'image_treatment' => 'rounded phone mockup with shallow shadow and gold overlay',
                    'reuse_policy' => 'reuse_when_intent_matches',
                    'css_motif' => '',
                ],
                'field_plan' => [
                    ['field' => 'headline', 'sample' => 'Play Today', 'implementation_note' => 'Render as the main H1.'],
                    ['field' => 'supporting_copy', 'sample' => 'Fast APK download with trusted gameplay.', 'implementation_note' => 'Render below the headline.'],
                    ['field' => 'cta_label', 'sample' => 'Download APK', 'implementation_note' => 'Use for the primary button.'],
                ],
                'execution_script' => [
                    'core_copy' => 'Download the APK and start playing trusted card games today.',
                    'feature_points' => ['Fast download', 'Secure setup'],
                    'typography' => 'Bold display headline with readable body text',
                    'style_tone' => 'Confident and conversion-focused',
                    'background_direction' => 'Dark felt gradient with gold highlights',
                    'media_assets' => ['hero-phone-apk-install.png'],
                ],
            ],
            'css_only_block' => [
                'block_key' => 'player_reviews',
                'page_flow_role' => 'proof',
                'content' => 'Real players trust the app for quick, secure gameplay.',
                'visual_signature' => [
                    'composition_pattern' => 'staggered testimonial cards',
                    'spatial_rhythm' => 'three review cards with rating badges',
                    'media_strategy' => 'CSS-only/no generated image; cards use initials, star badges, and gradient borders',
                    'surface_treatment' => 'glass cards with accent border glow',
                    'interaction_pattern' => 'card hover lift and rating shimmer',
                ],
                'image_intent' => [
                    'needs_image' => false,
                    'image_role' => 'css_motif',
                    'image_subject' => 'none',
                    'placement' => 'background_layer',
                    'visual_atmosphere' => 'secure premium review wall with social proof energy',
                    'image_treatment' => 'CSS gradients, initials, star badges, and border glow replace generated imagery',
                    'reuse_policy' => 'no_generated_image',
                    'css_motif' => 'glass testimonial cards with accent side borders and gold star badges',
                ],
                'field_plan' => [
                    ['field' => 'headline', 'sample' => 'Trusted by Players', 'implementation_note' => 'Render above review cards.'],
                    ['field' => 'supporting_copy', 'sample' => 'Secure gameplay with quick support.', 'implementation_note' => 'Render as proof copy.'],
                    ['field' => 'proof_detail', 'sample' => '4.8 star average rating', 'implementation_note' => 'Render as a badge.'],
                ],
                'execution_script' => [
                    'core_copy' => 'Real players trust the app for quick, secure gameplay.',
                    'feature_points' => ['Verified reviews', 'Quick support'],
                    'typography' => 'Compact headline with badge text',
                    'style_tone' => 'Reassuring and factual',
                    'background_direction' => 'Layered cards on a soft gradient surface',
                    'media_assets' => [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function visualSignatureExamples(): array
    {
        return [
            'generated_media_block' => [
                'composition_pattern' => 'split hero with copy rail and phone media panel',
                'spatial_rhythm' => 'large headline column balanced by one generated visual',
                'media_strategy' => 'Generated phone APK install image anchors the right panel beside CTA copy',
                'surface_treatment' => 'dark felt gradient, gold rim light, and soft glass card depth',
                'interaction_pattern' => 'CTA glow on hover; generated media panel uses reduced-motion parallax',
            ],
            'css_only_static_block' => [
                'composition_pattern' => 'staggered proof cards with compact badge row',
                'spatial_rhythm' => 'three short cards, tight copy, and one accent stat strip',
                'media_strategy' => 'CSS-only/no generated image; initials, chips, dividers, and card-suit motifs create the visual',
                'surface_treatment' => 'glass panels with amber border glow and subtle patterned texture',
                'interaction_pattern' => 'card hover lift, focus-visible links, no ambient motion',
            ],
            'faq_or_rules_block' => [
                'composition_pattern' => 'accordion-style rule rows inside a framed help panel',
                'spatial_rhythm' => 'left intro copy, right stacked questions, compact row gaps',
                'media_strategy' => 'CSS-only/no generated image; rule numbers, suit icons, and divider lines guide scanning',
                'surface_treatment' => 'matte dark panel with gold separators and soft inset shadow',
                'interaction_pattern' => 'accordion row expand, keyboard focus ring, no floating animation',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cssOnlyImageIntentExamples(): array
    {
        return [
            'trust_security' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'background_layer',
                'visual_atmosphere' => 'secure premium install-check mood with warm trust accents',
                'image_treatment' => 'CSS shield chips, check rows, and glow borders replace generated imagery',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'secure-install checklist with amber shields, tick chips, and dark glass panels',
            ],
            'player_reviews' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'inline_visual',
                'visual_atmosphere' => 'social proof wall with compact trustworthy player energy',
                'image_treatment' => 'CSS initials, star badges, and staggered testimonial cards replace player photos',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'initial circles, rating stars, and neon card borders',
            ],
            'faq_or_rules' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'background_layer',
                'visual_atmosphere' => 'calm rulebook panel with clear scanning and responsible-play tone',
                'image_treatment' => 'CSS accordion rows, card-suit bullets, and gold dividers replace images',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'accordion rule rows with suit icons, numbered chips, and divider lines',
            ],
            'article_collection' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'inline_visual',
                'visual_atmosphere' => 'editorial guide index with practical strategy-card mood',
                'image_treatment' => 'CSS article cards, category tabs, and reading badges replace thumbnails',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'editorial card stack with guide labels, suit marks, and read-time chips',
            ],
            'support_faq' => [
                'needs_image' => false,
                'image_role' => 'css_motif',
                'image_subject' => 'none',
                'placement' => 'background_layer',
                'visual_atmosphere' => 'support center clarity with low-friction help cues',
                'image_treatment' => 'CSS help chips, accordion arrows, and soft panels replace support photos',
                'reuse_policy' => 'no_generated_image',
                'css_motif' => 'help-topic chips, FAQ rows, and focus-visible accordion arrows',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function generatedImageTargetExamples(): array
    {
        return [
            'home_page' => 'hero',
            'about_page' => 'origin_story',
            'contact_page' => 'contact_methods',
            'blog_post' => 'article_hero',
            'blog_category' => 'category_hero',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldPlanIntentExamples(): array
    {
        return [
            'cta_block' => [
                ['field' => 'headline', 'sample' => 'Start Playing Today', 'implementation_note' => 'Main CTA heading.'],
                ['field' => 'supporting_copy', 'sample' => 'Download the APK and follow the quick setup steps.', 'implementation_note' => 'Body sentence before button.'],
                ['field' => 'cta_label', 'sample' => 'Download APK', 'implementation_note' => 'Primary action button.'],
            ],
            'proof_block' => [
                ['field' => 'headline', 'sample' => 'Trusted by Players', 'implementation_note' => 'Proof section heading.'],
                ['field' => 'supporting_copy', 'sample' => 'Verified reviews highlight fast setup and secure play.', 'implementation_note' => 'Trust body copy.'],
                ['field' => 'proof_detail', 'sample' => '4.8 average rating', 'implementation_note' => 'Badge or stat chip.'],
            ],
            'media_or_feature_block' => [
                ['field' => 'headline', 'sample' => 'See the Game Flow', 'implementation_note' => 'Feature heading.'],
                ['field' => 'supporting_copy', 'sample' => 'A guided screen shows setup, play, and rewards.', 'implementation_note' => 'Feature body copy.'],
                ['field' => 'image_brief', 'sample' => 'Phone screen with card table behind it', 'implementation_note' => 'Asset brief if needs_image=true.'],
            ],
            'support_or_form_block' => [
                ['field' => 'headline', 'sample' => 'Need Help?', 'implementation_note' => 'Support heading.'],
                ['field' => 'supporting_copy', 'sample' => 'Send your question and the support team will guide you.', 'implementation_note' => 'Support body copy.'],
                ['field' => 'form_label', 'sample' => 'Describe your issue', 'implementation_note' => 'Visitor-facing form label.'],
            ],
            'policy_block' => [
                ['field' => 'headline', 'sample' => 'Privacy Overview', 'implementation_note' => 'Policy heading.'],
                ['field' => 'supporting_copy', 'sample' => 'This page explains how account and app data is handled.', 'implementation_note' => 'Neutral policy body.'],
                ['field' => 'policy_summary', 'sample' => 'Data use, rights, and contact options', 'implementation_note' => 'Policy summary chip.'],
            ],
            'multi_item_blocks_still_use_three_rows' => [
                'player_reviews' => [
                    ['field' => 'headline', 'sample' => 'Players Trust the App', 'implementation_note' => 'Review section heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Ravi likes quick setup; Anika values secure play; Dev trusts the lobby.', 'implementation_note' => 'Intro plus three review snippets.'],
                    ['field' => 'proof_detail', 'sample' => '4.8 average rating from active players', 'implementation_note' => 'Single rating badge, not extra rows.'],
                ],
                'support_faq' => [
                    ['field' => 'headline', 'sample' => 'Support Questions', 'implementation_note' => 'FAQ heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Get help with install steps, account access, and game basics.', 'implementation_note' => 'One FAQ intro sentence.'],
                    ['field' => 'context_detail', 'sample' => 'Install help | Account help | Game rules', 'implementation_note' => 'Multiple FAQ topics inside one row.'],
                ],
            ],
            'common_block_key_examples' => [
                'hero_download' => [
                    ['field' => 'headline', 'sample' => 'Download in Three Clear Steps', 'implementation_note' => 'Heading for download guidance.'],
                    ['field' => 'supporting_copy', 'sample' => 'Follow the install prompts and open the game lobby safely.', 'implementation_note' => 'Body copy before action.'],
                    ['field' => 'cta_label', 'sample' => 'Download APK', 'implementation_note' => 'Primary download action.'],
                ],
                'player_reviews' => [
                    ['field' => 'headline', 'sample' => 'Players Trust the App', 'implementation_note' => 'Review section heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Short reviews highlight smooth setup and secure play.', 'implementation_note' => 'Review intro sentence.'],
                    ['field' => 'proof_detail', 'sample' => '4.8 average rating', 'implementation_note' => 'Rating badge detail.'],
                ],
                'article_collection' => [
                    ['field' => 'headline', 'sample' => 'Latest Strategy Guides', 'implementation_note' => 'Article list heading.'],
                    ['field' => 'supporting_copy', 'sample' => 'Browse practical tips for safer and smarter play.', 'implementation_note' => 'Collection intro.'],
                    ['field' => 'article_teaser', 'sample' => 'Beginner Teen Patti table guide', 'implementation_note' => 'First article teaser.'],
                ],
            ],
        ];
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
