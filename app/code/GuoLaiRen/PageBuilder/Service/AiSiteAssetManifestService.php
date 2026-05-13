<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteAssetManifestService
{
    private const SLOT_TYPES = [
        'hero_image',
        'trust_brand_image',
        'section_image',
        'logo_icon',
    ];

    private const MAX_USAGE_DEFAULT = 1;

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function normalize(array $manifest): array
    {
        $slots = [];
        $rawSlots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : $manifest;
        foreach ($rawSlots as $slotId => $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $normalized = $this->normalizeSlot($slot, (string)$slotId);
            if ($normalized === []) {
                continue;
            }
            $slots[$normalized['slot_id']] = $normalized;
        }

        return [
            'version' => 1,
            'updated_at' => (string)($manifest['updated_at'] ?? \date('Y-m-d H:i:s')),
            'slots' => $slots,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function getSlot(array $manifest, string $slotId): array
    {
        $normalized = $this->normalize($manifest);
        $slotId = $this->normalizeSlotId($slotId);

        return \is_array($normalized['slots'][$slotId] ?? null) ? $normalized['slots'][$slotId] : [];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    public function upsert(array $manifest, array $slot): array
    {
        $normalized = $this->normalize($manifest);
        $next = $this->normalizeSlot($slot, (string)($slot['slot_id'] ?? ''));
        if ($next === []) {
            return $normalized;
        }

        $slotId = $next['slot_id'];
        $existing = \is_array($normalized['slots'][$slotId] ?? null) ? $normalized['slots'][$slotId] : [];
        $existingStatus = \trim((string)($existing['status'] ?? ''));
        if (
            (int)($existing['locked_by_user'] ?? 0) === 1
            || \trim((string)($existing['final_url'] ?? '')) !== ''
            || \in_array($existingStatus, ['queued', 'generating', 'done', 'error', 'locked'], true)
        ) {
            $next = \array_replace($next, [
                'locked_by_user' => (int)($existing['locked_by_user'] ?? 0),
                'final_url' => (string)($existing['final_url'] ?? ''),
                'source' => (string)($existing['source'] ?? $next['source']),
                'status' => (string)($existing['status'] ?? $next['status']),
                'variants' => \is_array($existing['variants'] ?? null) ? $existing['variants'] : [],
                'error_message' => (string)($existing['error_message'] ?? ''),
                'execution_token' => (string)($existing['execution_token'] ?? ''),
            ]);
        }
        $normalized['slots'][$slotId] = \array_replace($existing, $next, [
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);
        $normalized['updated_at'] = \date('Y-m-d H:i:s');

        return $normalized;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function lock(array $manifest, string $slotId, string $finalUrl, string $source = 'uploaded'): array
    {
        $normalized = $this->normalize($manifest);
        $slotId = $this->normalizeSlotId($slotId);
        $slot = \is_array($normalized['slots'][$slotId] ?? null) ? $normalized['slots'][$slotId] : [
            'slot_id' => $slotId,
            'slot_type' => 'section_image',
        ];
        $slot['final_url'] = \trim($finalUrl);
        $slot['source'] = \trim($source) !== '' ? \trim($source) : 'uploaded';
        $slot['locked_by_user'] = 1;
        $slot['status'] = 'locked';
        $slot['updated_at'] = \date('Y-m-d H:i:s');

        $normalized['slots'][$slotId] = $this->normalizeSlot($slot, $slotId) ?: $slot;
        $normalized['updated_at'] = \date('Y-m-d H:i:s');

        return $normalized;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function markQueued(array $manifest, string $slotId, string $executionToken = ''): array
    {
        return $this->updateSlotState($manifest, $slotId, [
            'status' => 'queued',
            'source' => 'planned',
            'execution_token' => $executionToken,
            'error_message' => '',
        ], true);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function markGenerating(array $manifest, string $slotId): array
    {
        return $this->updateSlotState($manifest, $slotId, [
            'status' => 'generating',
            'error_message' => '',
        ], true);
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    public function recordGenerated(array $manifest, string $slotId, string $finalUrl, array $variant = []): array
    {
        $normalized = $this->normalize($manifest);
        $slotId = $this->normalizeSlotId($slotId);
        $slot = \is_array($normalized['slots'][$slotId] ?? null) ? $normalized['slots'][$slotId] : [];
        if ($slot === [] || (int)($slot['locked_by_user'] ?? 0) === 1) {
            return $normalized;
        }
        $variants = \is_array($slot['variants'] ?? null) ? $slot['variants'] : [];
        $variant = \array_replace([
            'url' => $finalUrl,
            'source' => 'generated',
            'created_at' => \date('Y-m-d H:i:s'),
        ], $variant);
        $variants[] = $variant;

        return $this->updateSlotState($normalized, $slotId, [
            'status' => 'done',
            'source' => 'generated',
            'final_url' => $finalUrl,
            'variants' => $variants,
            'error_message' => '',
        ], false);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function recordError(array $manifest, string $slotId, string $message): array
    {
        return $this->updateSlotState($manifest, $slotId, [
            'status' => 'error',
            'error_message' => $message,
        ], true);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function discardPlaceholderGeneratedAssets(array $manifest): array
    {
        $normalized = $this->normalize($manifest);
        $changed = false;
        foreach (($normalized['slots'] ?? []) as $slotId => $slot) {
            if (!\is_array($slot) || (int)($slot['locked_by_user'] ?? 0) === 1) {
                continue;
            }
            if (!$this->slotHasPlaceholderGeneratedAsset($slot)) {
                continue;
            }
            $variants = [];
            foreach (\is_array($slot['variants'] ?? null) ? $slot['variants'] : [] as $variant) {
                if (!\is_array($variant) || $this->isPlaceholderVariant($variant)) {
                    continue;
                }
                $variants[] = $variant;
            }
            $slot['final_url'] = '';
            $slot['source'] = 'planned';
            $slot['status'] = 'pending';
            $slot['variants'] = $variants;
            $slot['error_message'] = '';
            $slot['updated_at'] = \date('Y-m-d H:i:s');
            $normalized['slots'][(string)$slotId] = $this->normalizeSlot($slot, (string)$slotId) ?: $slot;
            $changed = true;
        }
        if ($changed) {
            $normalized['updated_at'] = \date('Y-m-d H:i:s');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<string>
     */
    public function extractPlaceholderAssetUrls(array $manifest): array
    {
        $urls = [];
        foreach (($this->normalize($manifest)['slots'] ?? []) as $slot) {
            if (!\is_array($slot) || !$this->slotHasPlaceholderGeneratedAsset($slot)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '' && !\in_array($finalUrl, $urls, true)) {
                $urls[] = $finalUrl;
            }
        }

        return $urls;
    }

    /**
     * 强行契约：图像 prompt 必须以"业务主体"开头，brand 名称仅作次要装饰，否则 AI 会忽略
     * 用户的真实业务诉求（如"印度棋牌/扑克"），照着 site_title 字面（"Teenipiya"）凭空发挥
     * 出无关吉祥物/插画。重构原则：
     *   1. PRIMARY SUBJECT 必须是 prompt 的第 1 行；
     *   2. brand name 仅作 wordmark text reference / brand context，不作主体；
     *   3. slot.brief 中如果以 "Generate the official website logo for X" 开头，
     *      会先把它替换成 subject-first 写法，避免和 brand 主体语义冲突。
     *
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $scope
     */
    public function buildPrompt(array $slot, array $scope = []): string
    {
        $isLogoSlot = $this->isLogoAssetSlot($slot);
        $isFaviconLikeSlot = $isLogoSlot && $this->isFaviconLikeSlot($slot);
        $rawBrief = $this->firstString([
            $slot['prompt_brief'] ?? null,
            $slot['brief'] ?? null,
            $slot['description'] ?? null,
            $slot['label'] ?? null,
        ]);
        $siteTitle = $this->firstString([
            $scope['website_profile']['site_title'] ?? null,
            $scope['site_title'] ?? null,
        ]);
        $businessContext = $this->firstString([
            $scope['website_profile']['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
        ]);
        $siteTagline = $this->firstString([
            $scope['website_profile']['site_tagline'] ?? null,
            $scope['site_tagline'] ?? null,
        ]);

        $primarySubject = $this->resolvePrimaryVisualSubject($slot, $businessContext, $siteTagline);

        $parts = [];

        // 第 1 行：强行契约 PRIMARY SUBJECT（最高优先级）。
        if ($primarySubject !== '') {
            if ($isFaviconLikeSlot) {
                $parts[] = 'PRIMARY SUBJECT (CRITICAL — the favicon/title icon glyph MUST visually depict this business; do not invent unrelated mascots, animals, or fantasy creatures): ' . $primarySubject;
            } elseif ($isLogoSlot) {
                $parts[] = 'PRIMARY SUBJECT (CRITICAL — the logo mark/glyph MUST visually depict this business; reject any unrelated mascot, animal, or fantasy figure that does not match the industry): ' . $primarySubject;
            } else {
                $parts[] = 'PRIMARY SUBJECT (CRITICAL — the entire scene MUST depict this exactly; every figure, prop, and setting comes from this domain): ' . $primarySubject;
            }
        } elseif ($businessContext !== '') {
            // 兜底：没有提取到主体时，至少把 brief 当成主体而不是 context。
            $parts[] = 'PRIMARY SUBJECT (CRITICAL — depict this domain only): ' . $businessContext;
        }

        // 第 2 行：业务背景（如果与 PRIMARY SUBJECT 已重复就跳过）。
        if ($businessContext !== '' && $businessContext !== $primarySubject) {
            $parts[] = 'Business / cultural context (every figure, environment, and detail MUST match this exactly — never substitute a generic stock-photo subject): ' . $businessContext;
        }

        // 第 3 行：处理 slot.brief。先去掉 "Generate the official website logo for X" 这类
        // 把 brand 当主体的句式，否则会与 PRIMARY SUBJECT 形成主体冲突。
        $sanitizedBrief = $this->sanitizeSlotBriefForPrompt($rawBrief, $siteTitle);
        if ($sanitizedBrief !== '') {
            $parts[] = $isLogoSlot
                ? 'Slot brief (reference only — does not override PRIMARY SUBJECT): ' . $sanitizedBrief
                : $sanitizedBrief;
        }

        // 第 4 行：brand name —— 仅当 logo 时作为 wordmark text；其它仅作风格背景，不画文字。
        if ($siteTitle !== '') {
            if ($isLogoSlot) {
                $parts[] = 'Optional brand wordmark text (only as small typographic accompaniment to the PRIMARY SUBJECT glyph; never as the primary subject itself, never invent characters out of this name): ' . $siteTitle;
            } else {
                $parts[] = 'Brand context (do not render as text on the image): ' . $siteTitle;
            }
        }

        // 第 5 行：tagline 仅作为情绪/风格调子，不画文字。
        if ($siteTagline !== '') {
            $parts[] = $isLogoSlot
                ? 'Brand personality for styling (mood/color palette only; never spell out this tagline inside the logo): ' . $siteTagline
                : 'Brand personality (reflect this tone in visual style): ' . $siteTagline;
        }

        $kind = $this->firstString([$slot['kind'] ?? null, $slot['slot_type'] ?? null]);
        if ($kind !== '') {
            $parts[] = 'Asset kind: ' . $kind;
        }

        $isHeroSlot = (bool)\preg_match('/\b(hero|banner|cover)\b/i', $kind . ' ' . ((string)($slot['label'] ?? '')));

        if ($isLogoSlot) {
            $parts[] = 'Logo output requirements: generate a PNG logo with a transparent alpha background. Keep the logo isolated on transparency; do not place it on a card, wall, photo scene, colored rectangle, gradient backdrop, website mockup, or screenshot frame.';
        } elseif ($isHeroSlot) {
            $parts[] = 'Hero banner default output requirements: when the user has not explicitly requested another hero image composition, compose for a 1920x750 website banner crop. Fill the entire canvas edge-to-edge with one immersive full-width scene. A transparent background is not needed — cover the full canvas with the subject matter and keep important subjects inside the center-safe area so CSS object-fit:cover can crop cleanly.';
            $parts[] = 'Hero visual quality bar (CRITICAL): premium cinematic website banner background, very wide horizontal composition, edge-to-edge coverage, strong depth, realistic lighting, high-end commercial art direction. Do NOT generate flat vector art, SVG-like shapes, childish cartoon, icon collage, clip-art, rough geometric placeholder art, cardboard-looking cards, UI mockups, or simplistic low-detail illustration. Prefer realistic/editorial photography or photoreal premium 3D only when the subject cannot be photographed.';
            $parts[] = 'Block-only visual constraints (very important):'
                . ' generate a single self-contained premium cinematic scene filling the whole canvas.'
                . ' DO NOT draw a website mockup, screenshot frame, browser chrome, mobile-app frame, or any UI surface.'
                . ' DO NOT include a website header, top navigation bar, brand logo, navigation menu, hamburger icon, or language switcher.'
                . ' DO NOT include a website footer, copyright row, or footer link columns.'
                . ' DO NOT draw call-to-action buttons, "Sign up"/"Get Started"/"Explore"/"Buy Now" buttons, badges that look like UI buttons, or any clickable controls.'
                . ' DO NOT render readable English/Chinese paragraph text, slogans, headings, captions, watermarks, price tags, labels, or speech bubbles.'
                . ' DO NOT show multiple separate page sections stitched together (no two-column site previews, no "as seen on" rows, no website screenshots).'
                . ' Only render the subject-matter visual that fits inside one rectangular block area; treat the canvas as the inside of a single content block, not as a whole web page.';
        } else {
            $parts[] = 'Section image output requirements: generate a premium editorial/commercial website image that fills the whole rectangular canvas with intentional composition, depth, and lighting. Do not use transparent cutouts unless the slot explicitly says logo/icon.';
            $parts[] = 'Style-match requirement (CRITICAL): the visual style, color temperature, lighting, and composition MUST align with the overall brand aesthetic described in the reference style keywords/color palette above. Do NOT generate a generic stock photo, overly saturated 3D render, childish cartoon illustration, flat vector/SVG-like shapes, clip-art, rough geometric placeholder art, or dark/gritty image unless those match the brand style. Keep the rendering quality consistent with a premium brand website.';
            $parts[] = 'Block-only visual constraints (very important):'
                . ' generate a single self-contained illustration or photograph filling the whole canvas.'
                . ' DO NOT draw a website mockup, screenshot frame, browser chrome, mobile-app frame, or any UI surface.'
                . ' DO NOT include a website header, top navigation bar, brand logo, navigation menu, hamburger icon, or language switcher.'
                . ' DO NOT include a website footer, copyright row, or footer link columns.'
                . ' DO NOT draw call-to-action buttons, "Sign up"/"Get Started"/"Explore"/"Buy Now" buttons, badges that look like UI buttons, or any clickable controls.'
                . ' DO NOT render readable English/Chinese paragraph text, slogans, headings, captions, watermarks, price tags, labels, or speech bubbles.'
                . ' DO NOT show multiple separate page sections stitched together (no two-column site previews, no "as seen on" rows, no website screenshots).'
                . ' Only render the subject-matter visual that fits inside one rectangular block area; treat the canvas as the inside of a single content block, not as a whole web page.';
        }
        $pageType = $this->firstString([$slot['page_type'] ?? null]);
        if ($pageType !== '') {
            $parts[] = 'Page type: ' . $pageType;
        }
        // 强行契约：reference_image_insights 中的 layout/component cues 经常描述
        // "header + hero + columns + footer" 这类整页结构，喂给单 block 图像生成时
        // 会让 AI 复制成网站 mockup。仅 logo 类资产保留风格 reference；其它视觉素材
        // 仅吸收颜色/排版关键词，剥离布局/组件层面的页面结构暗示。
        $referenceInsightsPrompt = $isLogoSlot
            ? $this->buildReferenceInsightsPrompt($scope)
            : $this->buildBlockReferenceInsightsPrompt($scope);
        if ($referenceInsightsPrompt !== '') {
            $parts[] = $referenceInsightsPrompt;
        }

        // 收尾：再次复述 PRIMARY SUBJECT，避免 AI 在长 prompt 中"漂移"忘掉首要契约。
        if ($primarySubject !== '') {
            $parts[] = 'Reinforced contract: the visual MUST stay within the PRIMARY SUBJECT domain stated above; reject any drift toward unrelated mascots, generic stock imagery, or off-topic scenery.';
        }

        return \trim(\implode("\n", $parts));
    }

    /**
     * 强行契约抽取：基于 brief_description 推导一组"必须出现的图像主体关键词"。
     * 这是修复 logo/banner 与用户业务诉求脱节的关键——AI 看到具体名词
     * （playing cards / dealer / casino chips / India / APK 等）会优先把它们画进图，
     * 而光看 "Business context: ..." 长句反而会被淹没。
     *
     * 关键词生成规则：
     *   1. 优先从 brief_description 抽取。短句直接复用；长句保留前 240 字符。
     *   2. 同时叠加 slot.brief 已存在的领域名词（如果有），减少 prompt 漂移。
     *
     * @param array<string, mixed> $slot
     */
    private function resolvePrimaryVisualSubject(array $slot, string $businessContext, string $siteTagline): string
    {
        $businessContext = \trim($businessContext);
        $siteTagline = \trim($siteTagline);
        $slotBrief = \trim((string)($slot['prompt_brief'] ?? $slot['brief'] ?? ''));

        if ($businessContext === '' && $siteTagline === '' && $slotBrief === '') {
            return '';
        }

        // 优先 business context；缺省退到 tagline；再退到 slot.brief。
        if ($businessContext !== '') {
            return $this->clipText($businessContext, 240);
        }
        if ($siteTagline !== '') {
            return $this->clipText($siteTagline, 240);
        }

        return $this->clipText($slotBrief, 240);
    }

    /**
     * 把 slot.brief 中"Generate the official website logo for X"这类把 brand 当主体的写法
     * 替换成中性 "Logo output for X"，避免 AI 出现主体冲突（PRIMARY SUBJECT vs slot.brief）。
     */
    private function sanitizeSlotBriefForPrompt(string $brief, string $siteTitle): string
    {
        $brief = \trim($brief);
        if ($brief === '') {
            return '';
        }
        $brief = \preg_replace(
            '/^(Generate|Create|Design|Produce)\s+(?:the\s+)?(?:official\s+)?(?:website\s+)?logo\s+for\s+"?[^"]*"?\.?\s*/iu',
            'Logo specification: ',
            $brief
        ) ?? $brief;
        $brief = \preg_replace(
            '/^(Generate|Create|Design|Produce)\s+(?:the\s+)?(?:website\s+)?(?:title\s+)?(?:icon|favicon)(?:\s*\/\s*favicon)?\s+for\s+"?[^"]*"?\.?\s*/iu',
            'Icon specification: ',
            $brief
        ) ?? $brief;
        $brief = \preg_replace(
            '/^(Visual\s+direction\s+hint|Style\s+hint|Business\s+context):\s*/iu',
            '',
            $brief
        ) ?? $brief;

        return \trim($brief);
    }

    private function clipText(string $value, int $limit): string
    {
        $value = \trim(\preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (\mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return \mb_substr($value, 0, \max(0, $limit - 3), 'UTF-8') . '...';
    }

    /**
     * @param array<string, mixed> $slot
     */
    private function isFaviconLikeSlot(array $slot): bool
    {
        $field = \strtolower(\trim((string)($slot['field'] ?? '')));
        $kind = \strtolower(\trim((string)($slot['kind'] ?? '')));
        $label = \strtolower(\trim((string)($slot['label'] ?? '')));
        $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? '')));

        return \in_array($field, ['icon', 'favicon', 'site.icon'], true)
            || \str_contains($kind, 'favicon') || \str_contains($kind, 'title_icon')
            || \str_contains($label, 'favicon') || \str_contains($label, 'title icon')
            || \str_contains($slotId, 'favicon') || \str_contains($slotId, 'title-icon');
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function syncFromBuildPlan(array $scope): array
    {
        $manifest = $this->dropLegacyUnscopedBlockSlots(
            $this->normalize(\is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : [])
        );
        foreach ($this->extractSlotsFromScope($scope) as $slot) {
            $manifest = $this->upsert($manifest, $slot);
        }
        foreach ($this->buildRequiredIdentitySlots($scope) as $slot) {
            $manifest = $this->upsert($manifest, $slot);
        }
        foreach ($this->buildRequiredHeroBannerSlots($scope) as $slot) {
            $manifest = $this->upsert($manifest, $slot);
        }
        foreach ($this->buildRequiredContentBlockSlots($scope) as $slot) {
            $manifest = $this->upsert($manifest, $slot);
        }

        return $manifest;
    }

    /**
     * Full refactor cleanup: page block image slots must be page-scoped.
     * Old stage-one slots such as "hero_download" or "trust_badges" are not
     * valid production slots and must not leak into prompt/quality contracts.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function dropLegacyUnscopedBlockSlots(array $manifest): array
    {
        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        foreach ($slots as $slotId => $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $normalizedSlotId = \trim((string)($slot['slot_id'] ?? $slotId));
            $pageType = \trim((string)($slot['page_type'] ?? ''));
            $slotType = \trim((string)($slot['slot_type'] ?? ''));
            if (!\in_array($slotType, self::SLOT_TYPES, true)) {
                continue;
            }
            if ($pageType === '' && !$this->isScopedSlotId($normalizedSlotId)) {
                unset($slots[$slotId]);
                continue;
            }
            if ($this->isLegacyPageBlockSlot($normalizedSlotId)) {
                unset($slots[$slotId]);
            }
        }
        $manifest['slots'] = $slots;

        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, string>
     */
    public function extractVerifiedAssets(array $manifest): array
    {
        $verified = [];
        foreach (($this->normalize($manifest)['slots'] ?? []) as $slotId => $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $finalUrl = \trim((string)($slot['final_url'] ?? ''));
            if ($finalUrl !== '' && !$this->slotHasPlaceholderGeneratedAsset($slot)) {
                $verified[(string)$slotId] = $finalUrl;
            }
        }

        return $verified;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function extractSlotsFromScope(array $scope): array
    {
        $slots = [];
        $sources = [
            $scope['plan_json'] ?? [],
            $scope['plan_structured'] ?? [],
            $scope['execution_blueprint'] ?? [],
            $scope['build_plan_v2'] ?? [],
            $scope['plan_projection'] ?? [],
            $scope['content_manifest'] ?? [],
        ];
        foreach ($sources as $source) {
            if (\is_array($source)) {
                $this->collectSlotsRecursive($source, $slots, []);
            }
        }

        return \array_values($slots);
    }

    /**
     * @param array<string|int, mixed> $node
     * @param array<string, array<string, mixed>> $slots
     * @param array<string, string> $context
     */
    private function collectSlotsRecursive(array $node, array &$slots, array $context): void
    {
        $context = $this->mergeContext($context, $node);
        foreach (['field_plan', 'asset_requirements'] as $key) {
            if (\is_array($node[$key] ?? null)) {
                $this->collectRequirementRows($node[$key], $slots, $context);
            }
        }
        if (\is_array($node['execution_script']['media_assets'] ?? null)) {
            $this->collectRequirementRows($node['execution_script']['media_assets'], $slots, $context);
        }
        if (\is_array($node['media_assets'] ?? null)) {
            $this->collectRequirementRows($node['media_assets'], $slots, $context);
        }

        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                $childContext = $context;
                if (
                    \is_string($key)
                    && \preg_match('/^[a-z0-9_]+_page$/i', $key) === 1
                    && \trim((string)($childContext['page_type'] ?? '')) === ''
                ) {
                    $childContext['page_type'] = $key;
                }
                $this->collectSlotsRecursive($value, $slots, $childContext);
            }
        }
    }

    /**
     * @param array<string|int, mixed> $rows
     * @param array<string, array<string, mixed>> $slots
     * @param array<string, string> $context
     */
    private function collectRequirementRows(array $rows, array &$slots, array $context): void
    {
        foreach ($rows as $key => $row) {
            if (!\is_array($row)) {
                if (\is_scalar($row)) {
                    $row = ['label' => (string)$key, 'brief' => (string)$row];
                } else {
                    continue;
                }
            }
            $slotType = $this->resolveSlotType($row, (string)$key);
            if ($slotType === '') {
                continue;
            }
            $brief = $this->firstString([
                $row['brief'] ?? null,
                $row['prompt'] ?? null,
                $row['description'] ?? null,
                $row['requirement'] ?? null,
                $row['sample'] ?? null,
                $row['content'] ?? null,
                $row['label'] ?? null,
            ]);
            $slotId = $this->normalizeSlotId($this->firstString([
                $row['slot_id'] ?? null,
                $row['id'] ?? null,
                $row['asset_key'] ?? null,
                $row['key'] ?? null,
                $context['task_key'] ?? '',
                $context['block_key'] ?? '',
                (string)$key,
            ]));
            if ($slotId === '') {
                $slotId = $this->normalizeSlotId($slotType . '-' . \substr(\sha1($brief), 0, 10));
            }
            $pageType = $this->firstString([$row['page_type'] ?? null, $context['page_type'] ?? '']);
            $sectionCode = $this->firstString([$row['section_code'] ?? null, $context['section_code'] ?? '']);
            $blockKeyForSection = $this->firstString([
                $row['block_key'] ?? null,
                $context['block_key'] ?? null,
                $row['key'] ?? null,
                (string)$key,
                $this->extractLegacyPageSlotTail($slotId),
            ]);
            if ($pageType !== '' && !$this->isScopedSlotId($slotId)) {
                if ($sectionCode === '') {
                    $sectionCode = $this->buildSectionCodeFromBlockKey($pageType, $blockKeyForSection);
                }
                $slotId = $this->normalizeSlotId('page:' . $pageType . ':' . \str_replace('/', '-', $sectionCode));
            }
            if ($pageType !== '' && $this->isLegacyPageBlockSlot($slotId)) {
                if ($sectionCode === '') {
                    $sectionCode = $this->buildSectionCodeFromBlockKey($pageType, $blockKeyForSection);
                }
                $slotId = $this->normalizeSlotId('page:' . $pageType . ':' . \str_replace('/', '-', $sectionCode));
            }
            if ($pageType === '' && !$this->isScopedSlotId($slotId)) {
                continue;
            }
            $slots[$slotId] = [
                'slot_id' => $slotId,
                'slot_type' => $slotType,
                'kind' => $slotType,
                'page_type' => $pageType,
                'block_key' => $this->firstString([$row['block_key'] ?? null, $context['block_key'] ?? '']),
                'field' => $this->firstString([$row['field'] ?? null, $row['field_key'] ?? null, 'image']),
                'task_key' => $this->firstString([$row['task_key'] ?? null, $context['task_key'] ?? '']),
                'section_code' => $sectionCode,
                'label' => $this->firstString([$row['label'] ?? null, $row['title'] ?? null, (string)$key]),
                'brief' => $brief,
                'prompt_brief' => $brief,
                'status' => 'pending',
                'source' => 'planned',
                'final_url' => '',
                'locked_by_user' => 0,
            ];
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildRequiredIdentitySlots(array $scope): array
    {
        $siteTitle = $this->firstString([
            $scope['website_profile']['site_title'] ?? null,
            $scope['site_title'] ?? null,
        ]);
        $siteTagline = $this->firstString([
            $scope['website_profile']['site_tagline'] ?? null,
            $scope['site_tagline'] ?? null,
        ]);
        $briefDescription = $this->firstString([
            $scope['website_profile']['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
        ]);
        $brandReference = $siteTitle !== '' ? $siteTitle : ($siteTagline !== '' ? $siteTagline : $briefDescription);
        if ($brandReference === '') {
            $brandReference = 'the website brand';
        }
        $existingLogo = $this->readExistingIdentityAssetUrl($scope, 'logo');
        $existingIcon = $this->readExistingIdentityAssetUrl($scope, 'icon');

        // 强行契约：slot.brief 必须以"业务主体"开头，避免 buildPrompt 时 brand name 抢占
        // PRIMARY SUBJECT 位置（导致出现与诉求脱节的吉祥物 logo）。
        $subjectAnchor = $briefDescription !== ''
            ? $briefDescription
            : ($siteTagline !== '' ? $siteTagline : $brandReference);

        $logoBriefParts = [];
        if ($subjectAnchor !== '') {
            $logoBriefParts[] = 'PRIMARY SUBJECT for the logo glyph (the mark MUST visually depict this business — pick concrete iconography from the domain such as suit symbols, chips, dealer cues for card games; map vertical-specific objects/symbols for other industries): '
                . $subjectAnchor;
        }
        $logoBriefParts[] = 'Output requirements: PNG with transparent alpha background, production-ready horizontal logo or wordmark, simple brand mark, no mockup, no extra scene, no colored rectangle/backdrop, no paragraph text, no watermark, no screenshot frame.';
        if ($brandReference !== '' && $brandReference !== $subjectAnchor) {
            $logoBriefParts[] = 'Optional brand wordmark text alongside the glyph (small, secondary; never the primary subject; never invent characters out of this name): "' . $brandReference . '"';
        }
        if ($siteTagline !== '' && $siteTagline !== $subjectAnchor) {
            $logoBriefParts[] = 'Style/personality hint (mood and palette only, never spell out as text): ' . $siteTagline;
        }
        if ($briefDescription !== '' && $briefDescription !== $subjectAnchor) {
            $logoBriefParts[] = 'Business context (every glyph element must reflect this domain): ' . $briefDescription;
        }
        $logoBrief = \implode("\n", $logoBriefParts);

        $iconBriefParts = [];
        if ($subjectAnchor !== '') {
            $iconBriefParts[] = 'PRIMARY SUBJECT for the favicon/title icon (one bold recognizable symbol from this business — e.g., a card suit/chip/dealer chip cue for card games; a domain-correct symbol for any other vertical): '
                . $subjectAnchor;
        }
        $iconBriefParts[] = 'Output requirements: square 1:1 composition, transparent or clean solid background, highly recognizable at 16-64px, one bold symbol or monogram only, no paragraph text, no mockup, no watermark.';
        if ($brandReference !== '' && $brandReference !== $subjectAnchor) {
            $iconBriefParts[] = 'Optional brand initial alongside the glyph (single letter/monogram only; never the primary subject): "' . $brandReference . '"';
        }
        if ($siteTagline !== '' && $siteTagline !== $subjectAnchor) {
            $iconBriefParts[] = 'Style hint (mood/palette only): ' . $siteTagline;
        }
        if ($briefDescription !== '' && $briefDescription !== $subjectAnchor) {
            $iconBriefParts[] = 'Business context: ' . $briefDescription;
        }
        $iconBrief = \implode("\n", $iconBriefParts);

        return [
            [
                'slot_id' => 'identity:website-logo',
                'slot_type' => 'logo_icon',
                'kind' => 'website_logo',
                'page_type' => 'global',
                'field' => 'logo',
                'task_key' => 'shared:header',
                'section_code' => 'identity',
                'label' => 'Website Logo',
                'brief' => $logoBrief,
                'prompt_brief' => $logoBrief,
                'source' => $existingLogo !== '' ? 'uploaded' : 'planned',
                'status' => $existingLogo !== '' ? 'locked' : 'pending',
                'final_url' => $existingLogo,
                'locked_by_user' => $existingLogo !== '' ? 1 : 0,
            ],
            [
                'slot_id' => 'identity:site-title-icon',
                'slot_type' => 'logo_icon',
                'kind' => 'site_title_icon',
                'page_type' => 'global',
                'field' => 'icon',
                'task_key' => 'shared:header',
                'section_code' => 'identity',
                'label' => 'Website Title Icon',
                'brief' => $iconBrief,
                'prompt_brief' => $iconBrief,
                'source' => $existingIcon !== '' ? 'uploaded' : 'planned',
                'status' => $existingIcon !== '' ? 'locked' : 'pending',
                'final_url' => $existingIcon,
                'locked_by_user' => $existingIcon !== '' ? 1 : 0,
            ],
        ];
    }

    /**
     * Stage-1 JSON often omits a dedicated image row in {@see collectRequirementRows}, which leaves the
     * asset manifest without a planned hero slot — previews fall back to bare gradients with no banner image.
     * We synthesize a stable hero_image slot per hero section derived from the same blueprint wiring as
     * {@see AiSiteBuildTaskService::buildBlueprintFromStageOneExecutionBlueprint}.
     *
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildRequiredHeroBannerSlots(array $scope): array
    {
        $businessContext = $this->firstString([
            $scope['website_profile']['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
        ]);
        $slots = [];
        foreach ($this->collectHeroSectionDescriptorsFromScope($scope) as $descriptor) {
            $pageType = $descriptor['page_type'];
            $sectionCode = $descriptor['section_code'];
            $title = $descriptor['title'];
            $slotId = $this->normalizeSlotId(
                'page:' . $pageType . ':' . \str_replace('/', '-', $sectionCode)
            );

            // 强行契约：banner slot.brief 必须以业务主体开头，否则 AI 会画与诉求无关的
            // 通用渐变/抽象图案，跟"棋牌/印度市场/APK 下载"等真实诉求脱节。
            $briefParts = [];
            if ($businessContext !== '') {
                $briefParts[] = 'PRIMARY SUBJECT for the hero banner background (CRITICAL — the entire scene MUST depict this business and culture; do not substitute a generic abstract gradient, generic stock photo, or off-topic figures): '
                    . $businessContext;
            }
            $briefParts[] = 'Format default: 1920x750-style full-width hero banner background image (photography or cinematic illustration) for the above-the-fold section. Unless the user explicitly requests a different hero visual composition, fill the entire canvas edge-to-edge with one immersive wide scene and keep important subjects within the center-safe crop area. Apply a subtle gradient overlay at top and bottom edges (dark-to-transparent) so text and page content can overlay the image naturally. The style and color temperature MUST match the brand identity — not a generic stock photo.';
            if ($title !== '') {
                $briefParts[] = 'Section headline context (do not render as readable slogan text inside the image): ' . $title;
            }

            $brief = \implode("\n", $briefParts);
            $slots[] = [
                'slot_id' => $slotId,
                'slot_type' => 'hero_image',
                'kind' => 'hero_banner_background',
                'page_type' => $pageType,
                'section_code' => $sectionCode,
                'field' => 'image',
                'label' => 'Hero banner background',
                'target_size' => '1920x750',
                'aspect_ratio' => '1920:750',
                'brief' => $brief,
                'prompt_brief' => $brief,
                'status' => 'pending',
                'source' => 'planned',
                'final_url' => '',
                'locked_by_user' => 0,
            ];
        }

        return $slots;
    }

    /**
     * Every non-hero block needs a page-scoped visual candidate. Otherwise the
     * renderer falls back to the first image on the page and separate blocks
     * become visually identical.
     *
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildRequiredContentBlockSlots(array $scope): array
    {
        $slots = [];
        $businessContext = $this->firstString([
            $scope['website_profile']['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
        ]);
        $pages = \is_array($scope['execution_blueprint']['pages'] ?? null) ? $scope['execution_blueprint']['pages'] : [];
        foreach ($pages as $pageType => $page) {
            if (!\is_array($page)) {
                continue;
            }
            foreach (\is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $blockKey = $this->normalizeSlotId($this->firstString([$block['block_key'] ?? null, $block['key'] ?? null]));
                if ($blockKey === '' || \preg_match('/hero|banner|opening/i', $blockKey) === 1) {
                    continue;
                }
                $sectionCode = $this->buildSectionCodeFromBlockKey((string)$pageType, $blockKey);
                $slotId = $this->normalizeSlotId('page:' . (string)$pageType . ':' . \str_replace('/', '-', $sectionCode));
                $brief = $this->firstString([
                    $block['execution_script']['media_assets'][0] ?? null,
                    $block['design_tags']['visual'][0] ?? null,
                    $block['content'] ?? null,
                    $block['goal'] ?? null,
                    $blockKey,
                ]);
                $briefParts = [];
                if ($businessContext !== '') {
                    $briefParts[] = 'PRIMARY SUBJECT: ' . $businessContext;
                }
                $briefParts[] = 'Block visual for "' . $blockKey . '": ' . ($brief !== '' ? $brief : $blockKey);
                $briefParts[] = 'One polished subject-matter image for this single section only; no website mockup, no text bars, no placeholder UI, no duplicated neighboring block composition.';
                $brief = \implode("\n", $briefParts);
                $slotType = \preg_match('/trust|badge|testimonial|review|client|rating|certificate/i', $blockKey . ' ' . $brief) === 1
                    ? 'trust_brand_image'
                    : 'section_image';
                $slots[] = [
                    'slot_id' => $slotId,
                    'slot_type' => $slotType,
                    'kind' => $slotType,
                    'page_type' => (string)$pageType,
                    'block_key' => $blockKey,
                    'field' => 'image',
                    'task_key' => 'page:' . (string)$pageType . ':' . $blockKey,
                    'section_code' => $sectionCode,
                    'label' => $this->firstString([$block['goal'] ?? null, $blockKey]),
                    'brief' => $brief,
                    'prompt_brief' => $brief,
                    'status' => 'pending',
                    'source' => 'planned',
                    'final_url' => '',
                    'locked_by_user' => 0,
                ];
            }
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array{page_type:string,section_code:string,title:string}>
     */
    private function collectHeroSectionDescriptorsFromScope(array $scope): array
    {
        $out = [];
        $seen = [];

        $append = static function (string $pageType, string $sectionCode, string $title) use (&$out, &$seen): void {
            $pageType = \trim($pageType);
            $sectionCode = \trim($sectionCode);
            if ($pageType === '' || $sectionCode === '') {
                return;
            }
            $key = $pageType . "\n" . $sectionCode;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                'page_type' => $pageType,
                'section_code' => $sectionCode,
                'title' => \trim($title),
            ];
        };

        $pageBlueprints = \is_array($scope['build_blueprint']['page_blueprints'] ?? null)
            ? $scope['build_blueprint']['page_blueprints']
            : [];
        foreach ($pageBlueprints as $pageType => $pb) {
            if (!\is_scalar($pageType) || !\is_array($pb)) {
                continue;
            }
            $pageTypeString = \trim((string)$pageType);
            foreach (\is_array($pb['sections'] ?? null) ? $pb['sections'] : [] as $section) {
                if (!\is_array($section)) {
                    continue;
                }
                $template = \strtolower(\trim((string)($section['template'] ?? '')));
                if (!\in_array($template, ['hero', 'banner'], true)) {
                    continue;
                }
                $sectionCode = \trim((string)($section['code'] ?? ''));
                $title = $this->firstString([
                    $section['name'] ?? null,
                    \is_array($section['config'] ?? null) ? ($section['config']['section_title'] ?? null) : null,
                ]);
                $append($pageTypeString, $sectionCode, $title);
            }
        }

        if ($out !== []) {
            return $out;
        }

        $pages = [];
        $executionBlueprint = \is_array($scope['execution_blueprint'] ?? null) ? $scope['execution_blueprint'] : [];
        if (\is_array($executionBlueprint['pages'] ?? null)) {
            $pages = $executionBlueprint['pages'];
        }

        foreach ($pages as $pageType => $page) {
            if (!\is_scalar($pageType) || !\is_array($page)) {
                continue;
            }
            $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            if ($blocks === [] || !\is_array($blocks[0] ?? null)) {
                continue;
            }
            $block = $blocks[0];
            $blockKey = \trim((string)($block['block_key'] ?? $block['source_block_key'] ?? $block['key'] ?? ''));
            if ($blockKey === '') {
                $blockKey = 'block_1';
            }
            $sectionSlug = $this->slugifySectionToken($blockKey);
            $sectionCode = 'content/' . $this->slugifySectionToken((string)$pageType) . '-' . $sectionSlug;

            $title = '';
            foreach (\is_array($block['field_plan'] ?? null) ? $block['field_plan'] : [] as $field) {
                if (!\is_array($field)) {
                    continue;
                }
                $fieldKey = \strtolower(\trim((string)($field['field'] ?? '')));
                if (\str_contains($fieldKey, 'title') || \str_contains($fieldKey, 'headline')) {
                    $title = \trim((string)($field['sample'] ?? ''));
                    break;
                }
            }
            if ($title === '') {
                $title = \trim((string)($block['title'] ?? $block['goal'] ?? $block['content'] ?? ''));
            }

            $append(\trim((string)$pageType), $sectionCode, $title);
        }

        return $out;
    }

    private function slugifySectionToken(string $value): string
    {
        $value = \strtolower(\trim($value));
        $value = \preg_replace('/[^a-z0-9]+/i', '-', $value) ?? $value;
        $value = \trim($value, '-');

        return $value !== '' ? $value : 'section';
    }

    private function buildSectionCodeFromBlockKey(string $pageType, string $blockKey): string
    {
        return 'content/' . $this->slugifySectionToken($pageType) . '-' . $this->slugifySectionToken($blockKey);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function readExistingIdentityAssetUrl(array $scope, string $role): string
    {
        $candidates = $role === 'logo'
            ? [
                $scope['logo'] ?? null,
                $scope['website_profile']['logo'] ?? null,
            ]
            : [
                $scope['icon'] ?? null,
                $scope['favicon'] ?? null,
                $scope['website_profile']['icon'] ?? null,
                $scope['website_profile']['favicon'] ?? null,
            ];
        $value = $this->firstString($candidates);
        if ($value === '' || \str_starts_with(\strtolower($value), 'data:image/')) {
            return '';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $slot
     */
    private function isLogoAssetSlot(array $slot): bool
    {
        $field = \strtolower(\trim((string)($slot['field'] ?? '')));
        $kind = \strtolower(\trim((string)($slot['kind'] ?? '')));
        $slotType = \strtolower(\trim((string)($slot['slot_type'] ?? '')));
        $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? '')));
        $label = \strtolower(\trim((string)($slot['label'] ?? '')));

        return \in_array($field, ['logo', 'logo.image', 'brand.logo'], true)
            || \in_array($kind, ['website_logo', 'brand_logo'], true)
            || \str_contains($slotId, 'logo')
            || (\str_contains($slotType, 'logo') && !\str_contains($kind, 'favicon'))
            || (\str_contains($label, 'logo') && !\str_contains($label, 'favicon'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveSlotType(array $row, string $fallbackKey): string
    {
        $text = \strtolower($fallbackKey . ' ' . \implode(' ', \array_map(
            static fn($value): string => \is_scalar($value) ? (string)$value : '',
            $row
        )));
        if (\preg_match('/\b(hero|banner|cover)\b/i', $text) === 1) {
            return 'hero_image';
        }
        if (\preg_match('/\b(logo|icon|mark)\b/i', $text) === 1) {
            return 'logo_icon';
        }
        if (\preg_match('/\b(trust|brand|partner|client|testimonial|certificate|certification)\b/i', $text) === 1) {
            return 'trust_brand_image';
        }
        if (\preg_match('/\b(image|photo|visual|media|asset|illustration|section)\b/i', $text) === 1) {
            return 'section_image';
        }

        return '';
    }

    /**
     * @param array<string, string> $context
     * @param array<string|int, mixed> $node
     * @return array<string, string>
     */
    private function mergeContext(array $context, array $node): array
    {
        foreach (['page_type', 'task_key', 'block_key', 'section_code', 'field'] as $key) {
            $value = $this->firstString([$node[$key] ?? null, $context[$key] ?? '']);
            if ($value !== '') {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function normalizeSlot(array $slot, string $fallbackId): array
    {
        $slotId = $this->normalizeSlotId((string)($slot['slot_id'] ?? $fallbackId));
        $slotType = \trim((string)($slot['slot_type'] ?? $slot['type'] ?? ''));
        if (!\in_array($slotType, self::SLOT_TYPES, true)) {
            $slotType = $this->resolveSlotType($slot, $slotId);
        }
        if ($slotId === '' || !\in_array($slotType, self::SLOT_TYPES, true)) {
            return [];
        }

        return [
            'slot_id' => $slotId,
            'slot_type' => $slotType,
            'kind' => \trim((string)($slot['kind'] ?? $slotType)) ?: $slotType,
            'page_type' => \trim((string)($slot['page_type'] ?? '')),
            'block_key' => \trim((string)($slot['block_key'] ?? '')),
            'field' => \trim((string)($slot['field'] ?? 'image')) ?: 'image',
            'task_key' => \trim((string)($slot['task_key'] ?? '')),
            'section_code' => \trim((string)($slot['section_code'] ?? '')),
            'label' => \trim((string)($slot['label'] ?? $slotId)),
            'brief' => \trim((string)($slot['brief'] ?? $slot['prompt_brief'] ?? '')),
            'prompt_brief' => \trim((string)($slot['prompt_brief'] ?? $slot['brief'] ?? '')),
            'final_url' => \trim((string)($slot['final_url'] ?? '')),
            'source' => \trim((string)($slot['source'] ?? 'planned')) ?: 'planned',
            'status' => \trim((string)($slot['status'] ?? 'pending')) ?: 'pending',
            'locked_by_user' => (int)($slot['locked_by_user'] ?? 0) === 1 ? 1 : 0,
            'variants' => \is_array($slot['variants'] ?? null) ? $slot['variants'] : [],
            'error_message' => \trim((string)($slot['error_message'] ?? '')),
            'execution_token' => \trim((string)($slot['execution_token'] ?? '')),
            'updated_at' => \trim((string)($slot['updated_at'] ?? '')),
            'allowed_pages' => \is_array($slot['allowed_pages'] ?? null) ? $slot['allowed_pages'] : ['*'],
            'allowed_blocks' => \is_array($slot['allowed_blocks'] ?? null) ? $slot['allowed_blocks'] : ['*'],
            'max_usage' => (int)($slot['max_usage'] ?? self::MAX_USAGE_DEFAULT),
            'reuse_policy' => \trim((string)($slot['reuse_policy'] ?? 'do_not_repeat_raw_image')),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    public function forBlock(array $manifest, string $pageType, string $blockKey): array
    {
        $normalized = $this->normalize($manifest);
        $allowed = [];
        foreach ($normalized['slots'] ?? [] as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $allowedPages = \is_array($slot['allowed_pages'] ?? null) ? $slot['allowed_pages'] : ['*'];
            if (!\in_array('*', $allowedPages, true) && !\in_array($pageType, $allowedPages, true)) {
                continue;
            }
            $allowedBlocks = \is_array($slot['allowed_blocks'] ?? null) ? $slot['allowed_blocks'] : ['*'];
            if (!\in_array('*', $allowedBlocks, true) && !\in_array($blockKey, $allowedBlocks, true)) {
                continue;
            }
            $allowed[] = $slot;
        }

        return $allowed;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param list<string> $usedAssetIds
     * @return list<string>
     */
    public function validateBlockUsage(array $manifest, string $blockKey, array $usedAssetIds): array
    {
        $violations = [];
        $counts = [];
        foreach ($usedAssetIds as $id) {
            $id = (string)$id;
            if ($id === '') {
                continue;
            }
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        $normalized = $this->normalize($manifest);
        foreach ($counts as $assetId => $usageCount) {
            $slot = null;
            foreach ($normalized['slots'] ?? [] as $s) {
                if (\is_array($s) && ($s['slot_id'] ?? '') === $assetId) {
                    $slot = $s;
                    break;
                }
            }
            if ($slot === null) {
                continue;
            }
            $maxUsage = (int)($slot['max_usage'] ?? self::MAX_USAGE_DEFAULT);
            if ($usageCount > $maxUsage) {
                $violations[] = "{$assetId}: used {$usageCount} times, max {$maxUsage}";
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function updateSlotState(array $manifest, string $slotId, array $patch, bool $skipLocked): array
    {
        $normalized = $this->normalize($manifest);
        $slotId = $this->normalizeSlotId($slotId);
        $slot = \is_array($normalized['slots'][$slotId] ?? null) ? $normalized['slots'][$slotId] : [];
        if ($slot === []) {
            return $normalized;
        }
        if ($skipLocked && (int)($slot['locked_by_user'] ?? 0) === 1) {
            return $normalized;
        }

        $normalized['slots'][$slotId] = $this->normalizeSlot(\array_replace($slot, $patch, [
            'updated_at' => \date('Y-m-d H:i:s'),
        ]), $slotId) ?: \array_replace($slot, $patch);
        $normalized['updated_at'] = \date('Y-m-d H:i:s');

        return $normalized;
    }

    private function normalizeSlotId(string $slotId): string
    {
        $slotId = \strtolower(\trim($slotId));
        $slotId = \preg_replace('/[^a-z0-9:._-]+/', '-', $slotId) ?? '';
        $slotId = \trim($slotId, '-_:.');

        return $slotId;
    }

    private function isScopedSlotId(string $slotId): bool
    {
        $slotId = \strtolower(\trim($slotId));

        return \str_starts_with($slotId, 'page:')
            || \str_starts_with($slotId, 'identity:')
            || \str_starts_with($slotId, 'shared:');
    }

    private function isLegacyPageBlockSlot(string $slotId): bool
    {
        $slotId = \strtolower(\trim($slotId));
        if (!\str_starts_with($slotId, 'page:')) {
            return false;
        }
        $parts = \explode(':', $slotId, 3);
        $slotTail = \trim((string)($parts[2] ?? ''));

        return $slotTail !== '' && !\str_starts_with($slotTail, 'content-');
    }

    private function extractLegacyPageSlotTail(string $slotId): string
    {
        $slotId = \strtolower(\trim($slotId));
        if (!\str_starts_with($slotId, 'page:')) {
            return $slotId;
        }
        $parts = \explode(':', $slotId, 3);

        return \trim((string)($parts[2] ?? ''));
    }

    /**
     * @param array<string,mixed> $slot
     */
    private function slotHasPlaceholderGeneratedAsset(array $slot): bool
    {
        $finalUrl = \trim((string)($slot['final_url'] ?? ''));
        foreach (\is_array($slot['variants'] ?? null) ? $slot['variants'] : [] as $variant) {
            if (
                \is_array($variant)
                && $this->isPlaceholderVariant($variant)
                && ($finalUrl === '' || $this->variantReferencesFinalUrl($variant, $finalUrl))
            ) {
                return true;
            }
        }

        $source = \strtolower(\trim((string)($slot['source'] ?? '')));
        $lowerFinalUrl = \strtolower($finalUrl);
        return $source === 'generated'
            && \str_contains($lowerFinalUrl, '/ai-generated/')
            && \str_ends_with($lowerFinalUrl, '.svg');
    }

    /**
     * @param array<string,mixed> $variant
     */
    private function isPlaceholderVariant(array $variant): bool
    {
        if ((int)($variant['placeholder'] ?? 0) === 1) {
            return true;
        }
        foreach (['mode', 'model', 'source'] as $key) {
            $value = \strtolower(\trim((string)($variant[$key] ?? '')));
            if ($value === 'placeholder'
                || $value === 'local_composed'
                || $value === 'local-premium-composition-v1'
                || \str_contains($value, 'local_composition')
            ) {
                return true;
            }
        }
        if (\trim((string)($variant['generation_fallback_reason'] ?? '')) !== '') {
            return true;
        }

        return \str_contains((string)($variant['revised_prompt'] ?? ''), 'Text-to-image is not connected yet');
    }

    /**
     * @param array<string,mixed> $variant
     */
    private function variantReferencesFinalUrl(array $variant, string $finalUrl): bool
    {
        $finalUrl = \trim($finalUrl);
        foreach (['url', 'final_url'] as $key) {
            if (\trim((string)($variant[$key] ?? '')) === $finalUrl) {
                return true;
            }
        }
        $path = \trim((string)($variant['path'] ?? ''));
        return $path !== '' && '/' . \ltrim(\str_replace('\\', '/', $path), '/') === $finalUrl;
    }

    /**
     * @param list<mixed> $values
     */
    private function firstString(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $value = \trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildReferenceInsightsPrompt(array $scope): string
    {
        $insights = \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [];
        if ($insights === []) {
            return '';
        }

        $lines = [];
        $summary = \trim((string)($insights['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = 'Reference style summary: ' . $summary;
        }
        $styleKeywords = $this->normalizePromptList($insights['style_keywords'] ?? []);
        if ($styleKeywords !== '') {
            $lines[] = 'Reference style keywords: ' . $styleKeywords;
        }
        $colorPalette = $this->normalizePromptList($insights['color_palette'] ?? []);
        if ($colorPalette !== '') {
            $lines[] = 'Reference color palette: ' . $colorPalette;
        }
        $layoutCues = $this->normalizePromptList($insights['layout_cues'] ?? []);
        if ($layoutCues !== '') {
            $lines[] = 'Reference layout cues: ' . $layoutCues;
        }
        $componentCues = $this->normalizePromptList($insights['component_cues'] ?? []);
        if ($componentCues !== '') {
            $lines[] = 'Reference component cues: ' . $componentCues;
        }
        $typographyCues = $this->normalizePromptList($insights['typography_cues'] ?? []);
        if ($typographyCues !== '') {
            $lines[] = 'Reference typography cues: ' . $typographyCues;
        }
        $forbidden = $this->normalizePromptList($insights['do_not_use'] ?? []);
        if ($forbidden !== '') {
            $lines[] = 'Avoid these reference mismatches: ' . $forbidden;
        }

        return \implode("\n", $lines);
    }

    /**
     * 块级图像专用 reference 摘要：仅保留色彩/质感/风格类提示，
     * 剥离 layout_cues / component_cues 等会被 AI 解读为"画整页"的结构信号。
     *
     * @param array<string, mixed> $scope
     */
    private function buildBlockReferenceInsightsPrompt(array $scope): string
    {
        $insights = \is_array($scope['reference_image_insights'] ?? null) ? $scope['reference_image_insights'] : [];
        if ($insights === []) {
            return '';
        }

        $lines = [];
        $styleKeywords = $this->normalizePromptList($insights['style_keywords'] ?? []);
        if ($styleKeywords !== '') {
            $lines[] = 'Reference style keywords (apply to subject only): ' . $styleKeywords;
        }
        $colorPalette = $this->normalizePromptList($insights['color_palette'] ?? []);
        if ($colorPalette !== '') {
            $lines[] = 'Reference color palette (use as subject colors only): ' . $colorPalette;
        }
        $forbidden = $this->normalizePromptList($insights['do_not_use'] ?? []);
        if ($forbidden !== '') {
            $lines[] = 'Avoid these reference mismatches: ' . $forbidden;
        }

        return \implode("\n", $lines);
    }

    private function normalizePromptList(mixed $values): string
    {
        if (!\is_array($values)) {
            return '';
        }

        $items = [];
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text === '' || \in_array($text, $items, true)) {
                continue;
            }
            $items[] = $text;
        }

        return \implode(', ', $items);
    }
}
