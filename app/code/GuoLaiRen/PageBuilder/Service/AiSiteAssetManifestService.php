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
    private const BLOCK_CACHE_VERSION = 1;

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
        $existingSignature = \trim((string)($existing['planning_signature'] ?? ''));
        if ($existingSignature === '' && $existing !== []) {
            $existingSignature = $this->buildSlotPlanningSignature($existing);
        }
        $nextSignature = \trim((string)($next['planning_signature'] ?? ''));
        $samePlanning = $existingSignature !== '' && $nextSignature !== '' && \hash_equals($existingSignature, $nextSignature);
        $existingFinalUrl = \trim((string)($existing['final_url'] ?? ''));
        if (
            ((int)($existing['locked_by_user'] ?? 0) === 1 && $existingFinalUrl !== '')
            || (
                $samePlanning
                && (
                    $existingFinalUrl !== ''
                    || \in_array($existingStatus, ['queued', 'generating', 'done', 'error', 'locked'], true)
                )
            )
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
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function rememberGeneratedSlotInScope(array $scope, array $manifest, string $slotId): array
    {
        $slot = $this->getSlot($manifest, $slotId);
        if ($slot === [] || (int)($slot['locked_by_user'] ?? 0) === 1) {
            return $scope;
        }

        $slotId = \trim((string)($slot['slot_id'] ?? ''));
        $finalUrl = \trim((string)($slot['final_url'] ?? ''));
        $signature = \trim((string)($slot['planning_signature'] ?? ''));
        if ($slotId === '' || $finalUrl === '' || $signature === '' || $this->slotHasPlaceholderGeneratedAsset($slot)) {
            return $scope;
        }

        $cache = \is_array($scope['asset_block_cache'] ?? null) ? $scope['asset_block_cache'] : [];
        $slots = \is_array($cache['slots'] ?? null) ? $cache['slots'] : [];
        $slots[$slotId] = [
            'slot_id' => $slotId,
            'planning_signature' => $signature,
            'slot_type' => (string)($slot['slot_type'] ?? ''),
            'kind' => (string)($slot['kind'] ?? ''),
            'page_type' => (string)($slot['page_type'] ?? ''),
            'block_key' => (string)($slot['block_key'] ?? ''),
            'section_code' => (string)($slot['section_code'] ?? ''),
            'field' => (string)($slot['field'] ?? ''),
            'label' => (string)($slot['label'] ?? ''),
            'final_url' => $finalUrl,
            'source' => \trim((string)($slot['source'] ?? 'generated')) ?: 'generated',
            'status' => \trim((string)($slot['status'] ?? 'done')) ?: 'done',
            'variants' => \is_array($slot['variants'] ?? null) ? $slot['variants'] : [],
            'planning_context_hash' => \trim((string)($slot['planning_context_hash'] ?? '')),
            'cached_at' => \date('Y-m-d H:i:s'),
        ];

        $scope['asset_block_cache'] = \array_replace($cache, [
            'version' => self::BLOCK_CACHE_VERSION,
            'updated_at' => \date('Y-m-d H:i:s'),
            'slots' => $slots,
        ]);

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public function rememberGeneratedSlotsInScope(array $scope, array $manifest): array
    {
        foreach (($this->normalize($manifest)['slots'] ?? []) as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $slotId = \trim((string)($slot['slot_id'] ?? ''));
            if ($slotId === '' || \trim((string)($slot['final_url'] ?? '')) === '') {
                continue;
            }
            $scope = $this->rememberGeneratedSlotInScope($scope, $manifest, $slotId);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $slot
     */
    public function isReusableSessionBlockAsset(array $scope, array $slot, string $finalUrl = ''): bool
    {
        $normalized = $this->normalizeSlot($slot, (string)($slot['slot_id'] ?? ''));
        if ($normalized === [] || $this->slotHasPlaceholderGeneratedAsset($normalized)) {
            return false;
        }

        $finalUrl = \trim($finalUrl) !== ''
            ? \trim($finalUrl)
            : \trim((string)($normalized['final_url'] ?? ''));
        if ($finalUrl === '') {
            return false;
        }

        $cached = $this->readReusableCachedSlot($scope, $normalized, $finalUrl);
        if ($cached !== []) {
            return true;
        }

        $signature = \trim((string)($normalized['planning_signature'] ?? ''));
        $slotFinalUrl = \trim((string)($normalized['final_url'] ?? ''));

        return $signature !== '' && $slotFinalUrl !== '' && \hash_equals($slotFinalUrl, $finalUrl);
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
     * 用户的真实业务诉求（如行业、产品、服务场景），照着 site_title 字面凭空发挥
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
        $isFaviconLikeSlot = $this->isFaviconLikeSlot($slot);
        $isLogoSlot = $this->isLogoAssetSlot($slot) || $isFaviconLikeSlot;
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
        $businessContext = $this->firstBusinessContextString([
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
        $confirmedThemePalettePrompt = $this->buildConfirmedThemePalettePrompt($scope, $isLogoSlot, $isFaviconLikeSlot);
        if ($confirmedThemePalettePrompt !== '') {
            $parts[] = $confirmedThemePalettePrompt;
        }

        $kind = $this->firstString([$slot['kind'] ?? null, $slot['slot_type'] ?? null]);
        if ($kind !== '') {
            $parts[] = 'Asset kind: ' . $kind;
        }

        $isHeroSlot = $this->slotDeclaresStrictHeroImage($slot);

        if ($isFaviconLikeSlot) {
            $parts[] = 'Title icon / favicon output requirements (HARD): generate a production-ready square 1:1 PNG with a real transparent alpha background. The symbol/monogram is isolated on transparency; there must be no white background, solid color background, rounded square tile, card, gradient backdrop, photo scene, website mockup, watermark, screenshot frame, or paragraph text. Keep it recognizable at 16-64px with one bold business-relevant symbol or monogram.';
        } elseif ($isLogoSlot) {
            $parts[] = 'Logo output requirements (HARD): generate a production-ready PNG logo with a real transparent alpha background. Keep only the brand mark/wordmark pixels on transparency; do not place the logo on a white box, colored rectangle, rounded card, wall, photo scene, gradient backdrop, website mockup, screenshot frame, or any other background surface.';
        } elseif ($isHeroSlot) {
            $parts[] = 'Hero banner default output requirements: when the user has not explicitly requested another hero image composition, compose for a 1920x750 website banner crop. Fill the entire canvas edge-to-edge with one immersive full-width scene. A transparent background is not needed — cover the full canvas with the subject matter and keep important subjects inside the center-safe area so CSS object-fit:cover can crop cleanly.';
            $parts[] = 'Hero visual quality bar (CRITICAL): premium cinematic website banner background, very wide horizontal composition, edge-to-edge coverage, strong depth, realistic lighting, high-end commercial art direction. Do NOT generate flat vector art, SVG-like shapes, childish cartoon, icon collage, clip-art, rough geometric placeholder art, cardboard-looking cards, UI mockups, or simplistic low-detail illustration. Prefer realistic/editorial photography or photoreal premium 3D only when the subject cannot be photographed.';
            $parts[] = $this->buildBlockImageArtifactContract(true);
        } else {
            $parts[] = 'Section image output requirements: generate a premium editorial/commercial website image that fills the whole rectangular canvas with intentional composition, depth, and lighting. Do not use transparent cutouts unless the slot explicitly says logo/icon.';
            $parts[] = 'Style-match requirement (CRITICAL): the visual style, color temperature, lighting, and composition MUST align with the overall brand aesthetic described in the reference style keywords/color palette above. Do NOT generate a generic stock photo, overly saturated 3D render, childish cartoon illustration, flat vector/SVG-like shapes, clip-art, rough geometric placeholder art, or dark/gritty image unless those match the brand style. Keep the rendering quality consistent with a premium brand website.';
            $parts[] = $this->buildBlockImageArtifactContract(false);
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
     * @param array<string, mixed> $slot
     */
    private function slotDeclaresStrictHeroImage(array $slot): bool
    {
        if ((int)($slot['strict_hero_cover'] ?? 0) === 1) {
            return true;
        }

        $pageType = \strtolower(\trim((string)($slot['page_type'] ?? '')));
        $slotType = \strtolower(\trim((string)($slot['slot_type'] ?? '')));
        $kind = \strtolower(\trim((string)($slot['kind'] ?? '')));

        return $pageType === 'home_page'
            && $slotType === 'hero_image'
            && \in_array($kind, ['hero_banner_background', 'hero_image'], true);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function blockDeclaresStrictHeroSlot(string $pageType, array $block): bool
    {
        if (\trim($pageType) !== 'home_page') {
            return false;
        }

        $blockType = $this->normalizeSlotId($this->firstString([
            $block['block_type'] ?? null,
            $block['type'] ?? null,
            $block['template'] ?? null,
        ]));

        return \in_array($blockType, ['hero', 'banner', 'home_hero', 'hero_banner', 'above_fold'], true);
    }

    /**
     * 强行契约抽取：基于 brief_description 推导一组"必须出现的图像主体关键词"。
     * 这是修复 logo/banner 与用户业务诉求脱节的关键——AI 看到具体名词
     * （具体产品、服务、材料、场景等）会优先把它们画进图，
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
        $slotBrief = $this->normalizeAssetBusinessContextString((string)($slot['prompt_brief'] ?? $slot['brief'] ?? ''));

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
        $currentBuildPlanSlots = $this->buildRequiredContentBlockSlots($scope);
        $manifest = $this->dropStaleBuildPlanScopedSlots($manifest, $currentBuildPlanSlots);
        foreach ($this->extractSlotsFromScope($scope) as $slot) {
            $slot = $this->attachPlanningContextToSlot($slot, $scope);
            $manifest = $this->upsert($manifest, $this->hydrateSlotFromSessionBlockCache($slot, $scope));
        }
        foreach ($this->buildRequiredIdentitySlots($scope) as $slot) {
            $slot = $this->attachPlanningContextToSlot($slot, $scope);
            $manifest = $this->upsert($manifest, $this->hydrateSlotFromSessionBlockCache($slot, $scope));
        }
        foreach ($this->buildRequiredHeroBannerSlots($scope) as $slot) {
            $slot = $this->attachPlanningContextToSlot($slot, $scope);
            $manifest = $this->upsert($manifest, $this->hydrateSlotFromSessionBlockCache($slot, $scope));
        }
        foreach ($currentBuildPlanSlots as $slot) {
            $slot = $this->attachPlanningContextToSlot($slot, $scope);
            $manifest = $this->upsert($manifest, $this->hydrateSlotFromSessionBlockCache($slot, $scope));
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function attachPlanningContextToSlot(array $slot, array $scope): array
    {
        $contractHash = \trim((string)($scope['stage1_contract']['contract_hash'] ?? ''));
        if ($contractHash === '') {
            $contractHash = \trim((string)($scope['plan_generated_source_signature'] ?? ''));
        }
        if ($contractHash !== '') {
            $slot['planning_context_hash'] = $contractHash;
        }

        return $slot;
    }

    /**
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function hydrateSlotFromSessionBlockCache(array $slot, array $scope): array
    {
        $normalized = $this->normalizeSlot($slot, (string)($slot['slot_id'] ?? ''));
        if ($normalized === [] || \trim((string)($normalized['final_url'] ?? '')) !== '') {
            return $slot;
        }

        $cached = $this->readReusableCachedSlot($scope, $normalized);
        if ($cached === []) {
            return $slot;
        }

        return \array_replace($normalized, [
            'final_url' => (string)($cached['final_url'] ?? ''),
            'source' => \trim((string)($cached['source'] ?? 'generated')) ?: 'generated',
            'status' => \trim((string)($cached['status'] ?? 'done')) ?: 'done',
            'variants' => \is_array($cached['variants'] ?? null) ? $cached['variants'] : [],
            'error_message' => '',
            'execution_token' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $slot
     * @return array<string, mixed>
     */
    private function readReusableCachedSlot(array $scope, array $slot, string $finalUrl = ''): array
    {
        $slotId = \trim((string)($slot['slot_id'] ?? ''));
        $signature = \trim((string)($slot['planning_signature'] ?? ''));
        if ($slotId === '' || $signature === '') {
            return [];
        }

        $cacheSlots = \is_array($scope['asset_block_cache']['slots'] ?? null)
            ? $scope['asset_block_cache']['slots']
            : [];
        $cached = \is_array($cacheSlots[$slotId] ?? null) ? $cacheSlots[$slotId] : [];
        if ($cached === []) {
            return [];
        }

        $cachedSignature = \trim((string)($cached['planning_signature'] ?? ''));
        $cachedFinalUrl = \trim((string)($cached['final_url'] ?? ''));
        if ($cachedSignature === '' || $cachedFinalUrl === '' || !\hash_equals($cachedSignature, $signature)) {
            return [];
        }
        if (\trim($finalUrl) !== '' && !\hash_equals($cachedFinalUrl, \trim($finalUrl))) {
            return [];
        }
        if ($this->slotHasPlaceholderGeneratedAsset(\array_replace($slot, $cached))) {
            return [];
        }

        return $cached;
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
     * @param array<string,mixed> $manifest
     * @param list<array<string,mixed>> $currentSlots
     * @return array<string,mixed>
     */
    private function dropStaleBuildPlanScopedSlots(array $manifest, array $currentSlots): array
    {
        if ($currentSlots === []) {
            return $manifest;
        }

        $current = [];
        foreach ($currentSlots as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $slotId = $this->normalizeSlotId((string)($slot['slot_id'] ?? ''));
            if ($slotId !== '') {
                $current[$slotId] = true;
            }
        }
        if ($current === []) {
            return $manifest;
        }

        $slots = \is_array($manifest['slots'] ?? null) ? $manifest['slots'] : [];
        foreach ($slots as $slotId => $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $normalizedSlotId = $this->normalizeSlotId((string)($slot['slot_id'] ?? $slotId));
            if (!$this->isScopedSlotId($normalizedSlotId)) {
                continue;
            }
            $pageType = \trim((string)($slot['page_type'] ?? ''));
            if ($pageType === '' || !\str_starts_with($normalizedSlotId, 'page:')) {
                continue;
            }
            if (!isset($current[$normalizedSlotId])) {
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
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        $sources = $buildPlan !== []
            ? [$buildPlan]
            : [
                $scope['plan_json'] ?? [],
                $scope['plan_structured'] ?? [],
                $scope['execution_blueprint'] ?? [],
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
                'required' => 0,
                'desired_image' => 1,
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
        $briefDescription = $this->firstBusinessContextString([
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
            $logoBriefParts[] = 'PRIMARY SUBJECT for the logo glyph (the mark MUST visually depict this exact business; derive concrete iconography only from the approved brief, products, services, materials, culture, and visual plan; never copy example industries or unrelated symbols): '
                . $subjectAnchor;
        }
        $logoBriefParts[] = 'Output requirements (HARD): PNG with real transparent alpha background, production-ready horizontal logo or wordmark, simple brand mark. Keep only logo pixels on transparency; no white box, colored rectangle, rounded card, gradient backdrop, extra scene, mockup, paragraph text, watermark, or screenshot frame.';
        if ($brandReference !== '' && $brandReference !== $subjectAnchor) {
            $logoBriefParts[] = 'Optional brand wordmark text alongside the glyph (small, secondary; never the primary subject; never invent characters out of this name): "' . $brandReference . '"';
        }
        if ($siteTagline !== '' && $siteTagline !== $subjectAnchor) {
            $logoBriefParts[] = 'Style/personality hint (mood and palette only, never spell out as text): ' . $siteTagline;
        }
        $logoThemePalettePrompt = $this->buildConfirmedThemePalettePrompt($scope, true, false);
        if ($logoThemePalettePrompt !== '') {
            $logoBriefParts[] = $logoThemePalettePrompt;
        }
        if ($briefDescription !== '' && $briefDescription !== $subjectAnchor) {
            $logoBriefParts[] = 'Business context (every glyph element must reflect this domain): ' . $briefDescription;
        }
        $logoBrief = \implode("\n", $logoBriefParts);

        $iconBriefParts = [];
        if ($subjectAnchor !== '') {
            $iconBriefParts[] = 'PRIMARY SUBJECT for the favicon/title icon (one bold recognizable symbol from this exact business; choose a domain-correct object, material, service cue, or brand-initial mark from the approved brief only; never copy example industries or unrelated symbols): '
                . $subjectAnchor;
        }
        $iconBriefParts[] = 'Output requirements (HARD): square 1:1 PNG with real transparent alpha background. Keep only one bold symbol or monogram on transparency, highly recognizable at 16-64px; no white box, solid background, rounded tile, gradient backdrop, paragraph text, mockup, watermark, or screenshot frame.';
        if ($brandReference !== '' && $brandReference !== $subjectAnchor) {
            $iconBriefParts[] = 'Optional brand initial alongside the glyph (single letter/monogram only; never the primary subject): "' . $brandReference . '"';
        }
        if ($siteTagline !== '' && $siteTagline !== $subjectAnchor) {
            $iconBriefParts[] = 'Style hint (mood/palette only): ' . $siteTagline;
        }
        $iconThemePalettePrompt = $this->buildConfirmedThemePalettePrompt($scope, true, true);
        if ($iconThemePalettePrompt !== '') {
            $iconBriefParts[] = $iconThemePalettePrompt;
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
        $businessContext = $this->firstBusinessContextString([
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
            $briefParts[] = $this->buildBlockImageArtifactContract(true);
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
                'strict_hero_cover' => 1,
                'brief' => $brief,
                'prompt_brief' => $brief,
                'status' => 'pending',
                'source' => 'planned',
                'final_url' => '',
                'required' => 1,
                'desired_image' => 1,
                'locked_by_user' => 0,
            ];
        }

        return $slots;
    }

    /**
     * Build page-scoped image slots from build_plan_v2 only. The confirmed
     * build plan is the execution contract; older execution blueprints must
     * not create or preserve image slots for blocks the plan did not request.
     *
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildRequiredContentBlockSlots(array $scope): array
    {
        $slots = [];
        $buildPlan = \is_array($scope['build_plan_v2'] ?? null) ? $scope['build_plan_v2'] : [];
        if ($buildPlan === []) {
            return [];
        }

        $businessContext = $this->firstBusinessContextString([
            $scope['website_profile']['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
        ]);
        $contentItems = \is_array($buildPlan['content_manifest']['items'] ?? null)
            ? $buildPlan['content_manifest']['items']
            : [];
        foreach (\is_array($buildPlan['blocks'] ?? null) ? $buildPlan['blocks'] : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $pageType = \trim((string)($block['page_type'] ?? $block['page_id'] ?? ''));
            $blockKey = $this->normalizeSlotId($this->firstString([
                $block['section_key'] ?? null,
                $block['block_key'] ?? null,
                $block['block_id'] ?? null,
            ]));
            if ($pageType === '' || $blockKey === '') {
                continue;
            }
            $imageIntent = \is_array($block['image_intent'] ?? null)
                ? $block['image_intent']
                : (\is_array($block['visual']['image_intent'] ?? null) ? $block['visual']['image_intent'] : []);
            if (!$this->blockShouldRequireGeneratedImage($block, $imageIntent)) {
                continue;
            }

            $sectionCode = $this->buildSectionCodeFromBlockKey($pageType, $blockKey);
            $slotId = $this->normalizeSlotId('page:' . $pageType . ':' . \str_replace('/', '-', $sectionCode));
            $titleCopy = $this->extractBuildPlanBlockContentText($block, $contentItems);
            $brief = $this->buildContentBlockImageSubjectBrief($blockKey, $block, $imageIntent, $businessContext);
            $briefParts = [];
            if ($businessContext !== '') {
                $briefParts[] = 'PRIMARY SUBJECT: ' . $businessContext;
            }
            if ($titleCopy !== '') {
                $briefParts[] = 'Block copy context (do not render readable text inside image): ' . $titleCopy;
            }
            $briefParts[] = 'Block visual for "' . $blockKey . '": ' . ($brief !== '' ? $brief : $blockKey);
            if ($imageIntent !== []) {
                $briefParts[] = 'Stage-1 image intent: role=' . $this->firstString([$imageIntent['image_role'] ?? null])
                    . '; subject=' . $this->firstString([$imageIntent['image_subject'] ?? null])
                    . '; placement=' . $this->firstString([$imageIntent['placement'] ?? null])
                    . '; reuse_policy=' . $this->firstString([$imageIntent['reuse_policy'] ?? null]);
            }
            $briefParts[] = 'Stage-1 visual signature: ' . $this->firstString([
                $block['visual_signature']['composition_pattern'] ?? null,
                $block['visual']['visual_signature']['composition_pattern'] ?? null,
            ]) . '; media=' . $this->firstString([
                $block['visual_signature']['media_strategy'] ?? null,
                $block['visual']['visual_signature']['media_strategy'] ?? null,
            ]);
            $isStrictHero = (int)($block['visual']['strict_hero_cover'] ?? $block['strict_hero_cover'] ?? 0) === 1;
            $briefParts[] = $this->buildBlockImageArtifactContract($isStrictHero);
            $brief = \implode("\n", \array_filter($briefParts, static fn(string $part): bool => \trim($part) !== ''));
            $slotType = $isStrictHero || \in_array($this->firstString([$imageIntent['image_role'] ?? null]), ['hero_image', 'hero_banner'], true)
                ? 'hero_image'
                : (\preg_match('/trust|badge|testimonial|review|client|rating|certificate/i', $blockKey . ' ' . $brief) === 1
                    ? 'trust_brand_image'
                    : 'section_image');
            $slots[] = [
                'slot_id' => $slotId,
                'slot_type' => $slotType,
                'kind' => $slotType,
                'page_type' => $pageType,
                'block_key' => $blockKey,
                'field' => 'image',
                'task_key' => $this->firstString([\is_array($block['task_ids'] ?? null) ? ($block['task_ids'][0] ?? null) : null])
                    ?: ('page:' . $pageType . ':' . $blockKey),
                'section_code' => $sectionCode,
                'label' => $this->firstString([$titleCopy, $block['goal'] ?? null, $blockKey]),
                'brief' => $brief,
                'prompt_brief' => $brief,
                'status' => 'pending',
                'source' => 'build_plan_v2',
                'final_url' => '',
                'required' => 1,
                'desired_image' => 1,
                'image_intent' => $imageIntent,
                'visual_signature' => \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [],
                'locked_by_user' => 0,
            ];
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string|int, mixed> $contentItems
     */
    private function extractBuildPlanBlockContentText(array $block, array $contentItems): string
    {
        $parts = [];
        foreach (\is_array($block['content_keys'] ?? null) ? $block['content_keys'] : [] as $key) {
            $key = \trim((string)$key);
            if ($key === '' || !\array_key_exists($key, $contentItems)) {
                continue;
            }
            $text = $this->extractContentManifestItemText($contentItems[$key]);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $this->clipText(\implode(' ', \array_unique($parts)), 260);
    }

    private function extractContentManifestItemText(mixed $value): string
    {
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            return \trim((string)$value);
        }
        if (!\is_array($value)) {
            return '';
        }
        foreach (['text', 'value', 'copy', 'primary_text', 'content'] as $field) {
            $text = \trim((string)($value[$field] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        if (\is_array($value['locales'] ?? null)) {
            foreach ($value['locales'] as $localeValue) {
                $text = \trim((string)$localeValue);
                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $imageIntent
     */
    private function buildContentBlockImageSubjectBrief(string $blockKey, array $block, array $imageIntent, string $businessContext): string
    {
        $intentSubject = $this->firstString([
            $imageIntent['image_subject'] ?? null,
            $imageIntent['image_role'] ?? null,
        ]);
        if ($intentSubject !== '' && !$this->isIconOnlyBlockImageBrief($intentSubject)) {
            return $intentSubject;
        }

        $goal = $this->firstString([
            $block['goal'] ?? null,
            $block['content'] ?? null,
            $block['execution_script']['core_copy'] ?? null,
            $blockKey,
        ]);
        $mediaCue = '';
        foreach (\is_array($block['execution_script']['media_assets'] ?? null) ? $block['execution_script']['media_assets'] : [] as $asset) {
            $assetText = $this->firstString([$asset]);
            if ($assetText !== '' && !$this->isIconOnlyBlockImageBrief($assetText)) {
                $mediaCue = $assetText;
                break;
            }
        }
        if ($mediaCue === '') {
            $mediaCue = $this->firstString([
                $block['visual_signature']['media_strategy'] ?? null,
                $block['design_tags']['visual'][0] ?? null,
            ]);
            if ($this->isIconOnlyBlockImageBrief($mediaCue)) {
                $mediaCue = '';
            }
        }

        $parts = [];
        $parts[] = 'Block-level subject scene for "' . $blockKey . '"';
        if ($goal !== '') {
            $parts[] = 'visitor purpose: ' . $goal;
        }
        if ($businessContext !== '') {
            $parts[] = 'business domain: ' . $businessContext;
        }
        if ($mediaCue !== '') {
            $parts[] = 'media cue: ' . $mediaCue;
        }
        $parts[] = 'not an icon-only SVG/glyph/chevron/sparkle asset';

        return $this->clipText(\implode('; ', $parts), 520);
    }

    private function isIconOnlyBlockImageBrief(string $brief): bool
    {
        $brief = \mb_strtolower(\trim($brief));
        if ($brief === '') {
            return false;
        }
        $mentionsIcon = \preg_match('/\b(?:svg|icon|glyph|chevron|sparkle|line\s+art|line\s+icon|symbol)\b/u', $brief) === 1;
        if (!$mentionsIcon) {
            return false;
        }

        return \preg_match('/\b(?:scene|photo|photograph|illustration|cinematic|editorial|environment|people|players|table|room|product|device|screenshot|mockup|background)\b/u', $brief) !== 1;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $imageIntent
     */
    private function blockShouldRequireGeneratedImage(array $block, array $imageIntent): bool
    {
        if ($imageIntent !== []) {
            if ($this->imageIntentNeedsImage($imageIntent)) {
                return true;
            }
            return !$this->blockDeclaresCssOnlyVisual($block, $imageIntent)
                && $this->blockPlansConcreteMediaAsset($block);
        }

        $role = \strtolower($this->firstString([$block['page_flow_role'] ?? null]));
        if (\in_array($role, ['opening', 'hero', 'proof'], true)) {
            return true;
        }

        $mediaAssets = \is_array($block['execution_script']['media_assets'] ?? null)
            ? $block['execution_script']['media_assets']
            : (\is_array($block['media_assets'] ?? null) ? $block['media_assets'] : []);
        foreach ($mediaAssets as $asset) {
            $text = \strtolower($this->firstString([$asset]));
            if ($text === '') {
                continue;
            }
            if (\preg_match('/\b(?:image|photo|visual|illustration|screenshot|mockup|scene|hero|banner|card|avatar|icon)\b/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $imageIntent
     */
    private function blockDeclaresCssOnlyVisual(array $block, array $imageIntent): bool
    {
        $text = \mb_strtolower(\trim((string)\preg_replace('/\s+/u', ' ', \implode(' ', \array_filter([
            $this->firstString([$imageIntent['css_motif'] ?? null]),
            $this->firstString([$imageIntent['rationale'] ?? null]),
            $this->firstString([$block['visual_signature']['media_strategy'] ?? null]),
        ])))));
        if ($text === '') {
            return false;
        }

        return \preg_match('/\b(?:css-only|css only|no image|without image|no generated image|gradient|pattern|motif|shape|decorative css|css illustration|css icon)\b/u', $text) === 1;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function blockPlansConcreteMediaAsset(array $block): bool
    {
        $mediaAssets = \is_array($block['execution_script']['media_assets'] ?? null)
            ? $block['execution_script']['media_assets']
            : (\is_array($block['media_assets'] ?? null) ? $block['media_assets'] : []);
        foreach ($mediaAssets as $asset) {
            $text = \strtolower($this->firstString([$asset]));
            if ($text === '') {
                continue;
            }
            if (\preg_match('/\b(?:image|photo|visual|illustration|screenshot|mockup|scene|hero|banner|card|avatar|icon)\b/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $imageIntent
     */
    private function imageIntentNeedsImage(array $imageIntent): bool
    {
        $value = $imageIntent['needs_image'] ?? null;
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return ((int)$value) === 1;
        }
        $normalized = \mb_strtolower(\trim((string)$value));

        return \in_array($normalized, ['true', 'yes', 'y', '1', 'required', 'needed'], true);
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
            if ($pageTypeString !== 'home_page') {
                continue;
            }
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
            if (\trim((string)$pageType) !== 'home_page') {
                continue;
            }
            $blocks = \is_array($page['blocks'] ?? null) ? $page['blocks'] : [];
            if ($blocks === []) {
                continue;
            }
            foreach ($blocks as $blockIndex => $block) {
                if (!\is_array($block) || !$this->blockDeclaresStrictHeroSlot((string)$pageType, $block)) {
                    continue;
                }
                $blockKey = \trim((string)($block['block_key'] ?? $block['source_block_key'] ?? $block['key'] ?? ''));
                if ($blockKey === '') {
                    $blockKey = 'block_' . ((int)$blockIndex + 1);
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
        if (!$this->isExistingIdentityAssetUrlAcceptable($value, $role)) {
            return '';
        }

        return $value;
    }

    private function isExistingIdentityAssetUrlAcceptable(string $url, string $role): bool
    {
        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $url;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        $lowerPath = \strtolower($path);
        if (!\str_contains($lowerPath, '/pub/media/page-build/ai-generated/')) {
            return true;
        }
        $expectedToken = $role === 'logo' ? 'identity-website-logo' : 'identity-site-title-icon';
        if (!\str_contains($lowerPath, $expectedToken) || !\str_ends_with($lowerPath, '.png')) {
            return false;
        }
        $absolutePath = BP . \str_replace('/', \DIRECTORY_SEPARATOR, \ltrim($path, '/'));
        if (!\is_file($absolutePath)) {
            return false;
        }
        $bytes = @\file_get_contents($absolutePath);
        if (!\is_string($bytes) || $bytes === '') {
            return false;
        }

        return $this->isPngImageBytes($bytes) && $this->pngAppearsToHaveTransparentBackground($bytes);
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
        $sectionCode = \strtolower(\trim((string)($slot['section_code'] ?? '')));
        $pageType = \strtolower(\trim((string)($slot['page_type'] ?? '')));
        $isIdentityContext = \str_starts_with($slotId, 'identity:')
            || \in_array($sectionCode, ['identity', 'global'], true)
            || \in_array($pageType, ['global'], true);

        return \in_array($field, ['logo', 'logo.image', 'brand.logo'], true)
            || \in_array($kind, ['website_logo', 'brand_logo'], true)
            || ($isIdentityContext && \str_contains($slotId, 'logo'))
            || ($isIdentityContext && \str_contains($slotType, 'logo') && !\str_contains($kind, 'favicon'))
            || ($isIdentityContext && \str_contains($label, 'logo') && !\str_contains($label, 'favicon'));
    }

    private function isPngImageBytes(string $bytes): bool
    {
        return \strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0;
    }

    private function pngAppearsToHaveTransparentBackground(string $bytes): bool
    {
        if (!$this->isPngImageBytes($bytes)) {
            return false;
        }
        if (\function_exists('imagecreatefromstring')) {
            $image = @\imagecreatefromstring($bytes);
            if ($image !== false) {
                $width = \imagesx($image);
                $height = \imagesy($image);
                $points = [
                    [0, 0],
                    [\max(0, $width - 1), 0],
                    [0, \max(0, $height - 1)],
                    [\max(0, $width - 1), \max(0, $height - 1)],
                    [(int)\floor($width / 2), 0],
                    [(int)\floor($width / 2), \max(0, $height - 1)],
                ];
                $transparent = 0;
                foreach ($points as [$x, $y]) {
                    $color = \imagecolorat($image, $x, $y);
                    $alpha = ($color >> 24) & 0x7F;
                    if ($alpha >= 80) {
                        $transparent++;
                    }
                }
                \imagedestroy($image);

                return $transparent >= 4;
            }
        }

        $colorType = \ord($bytes[25] ?? "\0");
        if (\in_array($colorType, [4, 6], true)) {
            return true;
        }

        return \str_contains($bytes, 'tRNS');
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
        if ((int)($row['strict_hero_cover'] ?? 0) === 1) {
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

        $normalized = [
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
            'required' => (int)($slot['required'] ?? $slot['real_image_required'] ?? 0) === 1 ? 1 : 0,
            'desired_image' => (int)($slot['desired_image'] ?? $slot['recommended'] ?? 0) === 1 ? 1 : 0,
            'source' => \trim((string)($slot['source'] ?? 'planned')) ?: 'planned',
            'status' => \trim((string)($slot['status'] ?? 'pending')) ?: 'pending',
            'locked_by_user' => (int)($slot['locked_by_user'] ?? 0) === 1 ? 1 : 0,
            'variants' => \is_array($slot['variants'] ?? null) ? $slot['variants'] : [],
            'error_message' => \trim((string)($slot['error_message'] ?? '')),
            'execution_token' => \trim((string)($slot['execution_token'] ?? '')),
            'updated_at' => \trim((string)($slot['updated_at'] ?? '')),
            'target_size' => \trim((string)($slot['target_size'] ?? '')),
            'aspect_ratio' => \trim((string)($slot['aspect_ratio'] ?? '')),
            'allowed_pages' => \is_array($slot['allowed_pages'] ?? null) ? $slot['allowed_pages'] : ['*'],
            'allowed_blocks' => \is_array($slot['allowed_blocks'] ?? null) ? $slot['allowed_blocks'] : ['*'],
            'max_usage' => (int)($slot['max_usage'] ?? self::MAX_USAGE_DEFAULT),
            'reuse_policy' => \trim((string)($slot['reuse_policy'] ?? 'do_not_repeat_raw_image')),
            'image_intent' => \is_array($slot['image_intent'] ?? null) ? $slot['image_intent'] : [],
            'planning_context_hash' => \trim((string)($slot['planning_context_hash'] ?? '')),
        ];
        $normalized['planning_signature'] = \trim((string)($slot['planning_signature'] ?? ''));
        if ($normalized['planning_signature'] === '') {
            $normalized['planning_signature'] = $this->buildSlotPlanningSignature($normalized);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $slot
     */
    private function buildSlotPlanningSignature(array $slot): string
    {
        $payload = [
            'slot_id' => \trim((string)($slot['slot_id'] ?? '')),
            'slot_type' => \trim((string)($slot['slot_type'] ?? '')),
            'kind' => \trim((string)($slot['kind'] ?? '')),
            'page_type' => \trim((string)($slot['page_type'] ?? '')),
            'block_key' => \trim((string)($slot['block_key'] ?? '')),
            'field' => \trim((string)($slot['field'] ?? '')),
            'task_key' => \trim((string)($slot['task_key'] ?? '')),
            'section_code' => \trim((string)($slot['section_code'] ?? '')),
            'label' => \trim((string)($slot['label'] ?? '')),
            'brief' => \trim((string)($slot['brief'] ?? '')),
            'prompt_brief' => \trim((string)($slot['prompt_brief'] ?? '')),
            'required' => (int)($slot['required'] ?? 0) === 1 ? 1 : 0,
            'desired_image' => (int)($slot['desired_image'] ?? 0) === 1 ? 1 : 0,
            'target_size' => \trim((string)($slot['target_size'] ?? '')),
            'aspect_ratio' => \trim((string)($slot['aspect_ratio'] ?? '')),
            'allowed_pages' => $this->normalizeSignatureValue(
                \is_array($slot['allowed_pages'] ?? null) ? $slot['allowed_pages'] : ['*']
            ),
            'allowed_blocks' => $this->normalizeSignatureValue(
                \is_array($slot['allowed_blocks'] ?? null) ? $slot['allowed_blocks'] : ['*']
            ),
            'max_usage' => (int)($slot['max_usage'] ?? self::MAX_USAGE_DEFAULT),
            'reuse_policy' => \trim((string)($slot['reuse_policy'] ?? 'do_not_repeat_raw_image')),
            'image_intent' => $this->normalizeSignatureValue(
                \is_array($slot['image_intent'] ?? null) ? $slot['image_intent'] : []
            ),
            'planning_context_hash' => \trim((string)($slot['planning_context_hash'] ?? '')),
        ];
        $json = \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \sha1((string)$json);
    }

    private function normalizeSignatureValue(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return \is_scalar($value) || $value === null ? $value : (string)$value;
        }

        $isList = \array_is_list($value);
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeSignatureValue($item);
        }
        if (!$isList) {
            \ksort($normalized);
        }

        return $normalized;
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

    private function firstBusinessContextString(array $values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $normalized = $this->normalizeAssetBusinessContextString((string)$value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function normalizeAssetBusinessContextString(string $value): string
    {
        $value = \trim(\strip_tags(\html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
        if ($value === '') {
            return '';
        }

        $value = \str_replace(["\r\n", "\r"], "\n", $value);
        foreach ([
            '/(?:^|\n)\s*(?:the\s+website\s+is\s*:|website\s*:|business\s*:|project\s*:)\s*/iu',
            '/(?:^|\n)\s*(?:the\s+site\s+is\s*:|site\s*:|brand\s*:|approved\s+brief\s*:)\s*/iu',
        ] as $pattern) {
            if (\preg_match($pattern, $value, $match, \PREG_OFFSET_CAPTURE) === 1) {
                $value = \substr($value, (int)$match[0][1] + \strlen((string)$match[0][0]));
                break;
            }
        }

        $cutAt = null;
        foreach (["\n# ", "\n## ", "\n### ", "\nYou must", "\nYour task", "\nThe final result", "\nOutput", "\nReturn", "\nRules", "\nRequirements"] as $marker) {
            $pos = \stripos($value, $marker);
            if ($pos !== false && $pos > 80 && ($cutAt === null || $pos < $cutAt)) {
                $cutAt = $pos;
            }
        }
        if ($cutAt !== null) {
            $value = \substr($value, 0, $cutAt);
        }

        $lines = [];
        foreach (\preg_split('/\n+/u', $value) ?: [] as $line) {
            $line = \trim((string)\preg_replace('/^\s*[-*]\s*/u', '', (string)$line));
            if ($line === '') {
                continue;
            }
            if (\preg_match('/^(?:#\s*role|you\s+are\b|you\s+are\s+generating\b|the\s+final\s+result\b|return\s+only\b|output\s+format\b|do\s+not\b|must\s+output\b)/iu', $line) === 1) {
                continue;
            }
            if (\preg_match('/\b(?:world-class\s+AI|SEO-first\s+website\s+architecture|Generative\s+Engine\s+Optimization|AI-generated\s+scalable|production-grade\s+website\s+plan)\b/iu', $line) === 1) {
                continue;
            }
            $lines[] = $line;
            if (\count($lines) >= 8) {
                break;
            }
        }

        $value = \trim((string)\preg_replace('/\s+/u', ' ', \implode('; ', $lines)));
        if ($value === '' || \preg_match('/^(?:#\s*role|you\s+are\b|return\s+only\b)/iu', $value) === 1) {
            return '';
        }

        return $this->clipText($value, 360);
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

    private function buildBlockImageArtifactContract(bool $isHeroSlot): string
    {
        $composition = $isHeroSlot
            ? 'a single self-contained premium cinematic scene filling the whole canvas'
            : 'a single self-contained premium illustration or photograph filling the whole canvas';

        return 'Block-only image artifact contract (HARD):'
            . ' generate ' . $composition . '.'
            . ' Treat this as raw visual media for one page block, not as a rendered website or page screenshot.'
            . ' DO NOT include website chrome: header, navigation, footer, menu items, hamburger icons, language switchers, browser frames, mobile-app frames, UI cards, CTA buttons, clickable controls, badges styled as buttons, or multi-section page previews.'
            . ' DO NOT include any readable text in any language, including brand names, headlines, slogans, captions, labels, watermarks, price tags, readable signage, speech bubbles, pseudo-menu text, or lorem ipsum.'
            . ' Only render subject-matter imagery that can sit behind or beside separately generated HTML text.'
            . ' Exception: identity logo/favicon slots may contain the approved brand wordmark or initial; this block-image contract is not used for identity slots.';
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildConfirmedThemePalettePrompt(array $scope, bool $isLogoSlot, bool $isFaviconLikeSlot): string
    {
        $palette = $this->resolveConfirmedThemePalette($scope);
        if ($palette === []) {
            return '';
        }

        $pairs = [];
        foreach ($palette as $role => $hex) {
            $pairs[] = $role . '=' . $hex;
        }
        $paletteLine = \implode(', ', \array_slice($pairs, 0, 10));
        if ($paletteLine === '') {
            return '';
        }

        if ($isLogoSlot) {
            $assetName = $isFaviconLikeSlot ? 'title icon / favicon' : 'logo';
            return 'Confirmed site theme palette (HARD): ' . $paletteLine . "\n"
                . 'Brand asset color contract (HARD): the ' . $assetName . ' MUST visibly follow the confirmed site theme palette above for glyph, accent, or subtle shadow only. The canvas background stays transparent alpha, not a palette-colored tile. Do not introduce unrelated default brand colors unless the exact hex exists in this palette. If the business subject has natural colors outside the palette, express that subject through silhouette, material, texture, or composition while keeping color usage palette-compatible.';
        }

        return 'Confirmed site theme palette: ' . $paletteLine . "\n"
            . 'Image color contract: keep color temperature and accents compatible with the confirmed site theme palette; do not invent unrelated default brand colors.';
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, string>
     */
    private function resolveConfirmedThemePalette(array $scope): array
    {
        $candidates = [];
        $this->appendPaletteCandidates($candidates, $scope);

        foreach ([
            $scope['plan_json'] ?? null,
            $scope['plan_structured'] ?? null,
            $scope['execution_blueprint'] ?? null,
            $scope['execution_blueprint_draft'] ?? null,
            $scope['theme_context_snapshot'] ?? null,
            $scope['plan_workbench']['confirmed'] ?? null,
            $scope['plan_workbench']['draft'] ?? null,
        ] as $source) {
            if (\is_array($source)) {
                $this->appendPaletteCandidates($candidates, $source);
            }
        }

        $palette = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate as $key => $value) {
                if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                    continue;
                }
                $hex = $this->normalizeThemeHex((string)$value);
                if ($hex === '') {
                    continue;
                }
                $role = \strtolower(\preg_replace('/[^a-z0-9_]+/i', '_', (string)$key) ?: 'token');
                $role = \trim($role, '_') ?: 'token';
                if (!isset($palette[$role])) {
                    $palette[$role] = $hex;
                }
            }
        }

        return $palette;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $source
     */
    private function appendPaletteCandidates(array &$candidates, array $source): void
    {
        foreach (['palette', 'color_palette', 'theme_palette', 'color_scheme'] as $key) {
            if (\is_array($source[$key] ?? null)) {
                $candidates[] = $source[$key];
            }
        }
        if (\is_array($source['theme_design']['color_scheme'] ?? null)) {
            $candidates[] = $source['theme_design']['color_scheme'];
        }
        if (\is_array($source['theme_context_snapshot'] ?? null)) {
            $this->appendPaletteCandidates($candidates, $source['theme_context_snapshot']);
        }
    }

    private function normalizeThemeHex(string $value): string
    {
        $value = \trim($value);
        if (\preg_match('/^#[0-9a-f]{3}$/i', $value) === 1) {
            return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }
        if (\preg_match('/^#[0-9a-f]{6}$/i', $value) === 1) {
            return $value;
        }
        if (\preg_match('/#[0-9a-f]{6}\b/i', $value, $matches) === 1) {
            return (string)$matches[0];
        }

        return '';
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
