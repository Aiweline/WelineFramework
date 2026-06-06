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
        $existingContextHash = \trim((string)($existing['planning_context_hash'] ?? ''));
        $nextContextHash = \trim((string)($next['planning_context_hash'] ?? ''));
        $samePlanningContext = $existingContextHash !== ''
            && $nextContextHash !== ''
            && \hash_equals($existingContextHash, $nextContextHash);
        $existingFinalUrl = \trim((string)($existing['final_url'] ?? ''));
        if (
            ((int)($existing['locked_by_user'] ?? 0) === 1 && $existingFinalUrl !== '')
            || (
                ($samePlanning || $samePlanningContext)
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
     * 瀵缚顢戞總鎴犲閿涙艾娴橀崓?prompt 韫囧懘銆忔禒?娑撴艾濮熸稉璁崇秼"瀵偓婢惰揪绱漛rand 閸氬秶袨娴犲懍缍斿▎陇顩︾憗鍛淬偘閿涘苯鎯侀崚?AI 娴兼艾鎷烽悾?
     * 閻劍鍩涢惃鍕埂鐎圭偘绗熼崝陇鐦斿Ч鍌︾礄婵″倽顢戞稉姘モ偓浣烽獓閸濅降鈧焦婀囬崝鈥虫簚閺咁垽绱氶敍宀€鍙庨惈鈧?site_title 鐎涙娼伴崙顓犫敄閸欐垶灏?
     * 閸戠儤妫ら崗鍐叉倧缁併儳澧?閹绘帞鏁鹃妴鍌炲櫢閺嬪嫬甯崚娆欑窗
     *   1. PRIMARY SUBJECT 韫囧懘銆忛弰?prompt 閻ㄥ嫮顑?1 鐞涘矉绱?
     *   2. brand name 娴犲懍缍?wordmark text reference / brand context閿涘奔绗夋担婊€瀵屾担鎿勭幢
     *   3. slot.brief 娑擃厼顩ч弸婊€浜?"Generate the official website logo for X" 瀵偓婢惰揪绱?
     *      娴兼艾鍘涢幎濠傜暊閺囨寧宕查幋?subject-first 閸愭瑦纭堕敍宀勪缉閸忓秴鎷?brand 娑撹缍嬬拠顓濈疅閸愯尙鐛婇妴?
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

        // 缁?1 鐞涘矉绱板楦款攽婵傛垹瀹?PRIMARY SUBJECT閿涘牊娓舵妯圭喘閸忓牏楠囬敍澶堚偓?
        if ($primarySubject !== '') {
            if ($isFaviconLikeSlot) {
                $parts[] = 'PRIMARY SUBJECT (CRITICAL 閳?the favicon glyph MUST visually depict this business; do not invent unrelated mascots, animals, or fantasy creatures): ' . $primarySubject;
            } elseif ($isLogoSlot) {
                $parts[] = 'PRIMARY SUBJECT (CRITICAL 閳?the logo mark/glyph MUST visually depict this business; reject any unrelated mascot, animal, or fantasy figure that does not match the industry): ' . $primarySubject;
            } else {
                $parts[] = 'PRIMARY SUBJECT (CRITICAL 閳?the entire scene MUST depict this exactly; every figure, prop, and setting comes from this domain): ' . $primarySubject;
            }
        } elseif ($businessContext !== '') {
            // 閸忔粌绨抽敍姘梾閺堝褰侀崣鏍у煂娑撹缍嬮弮璁圭礉閼峰啿鐨幎?brief 瑜版挻鍨氭稉璁崇秼閼板奔绗夐弰?context閵?
            $parts[] = 'PRIMARY SUBJECT (CRITICAL 閳?depict this domain only): ' . $businessContext;
        }

        // 缁?2 鐞涘矉绱版稉姘閼冲本娅欓敍鍫濐洤閺嬫粈绗?PRIMARY SUBJECT 瀹告煡鍣告径宥呮皑鐠哄疇绻冮敍澶堚偓?
        if ($businessContext !== '' && $businessContext !== $primarySubject) {
            $parts[] = 'Business / cultural context (every figure, environment, and detail MUST match this exactly 閳?never substitute a generic stock-photo subject): ' . $businessContext;
        }

        // 缁?3 鐞涘矉绱版径鍕倞 slot.brief閵嗗倸鍘涢崢缁樺竴 "Generate the official website logo for X" 鏉╂瑧琚?
        // 閹?brand 瑜版挷瀵屾担鎾舵畱閸欍儱绱￠敍灞芥儊閸掓瑤绱版稉?PRIMARY SUBJECT 瑜般垺鍨氭稉璁崇秼閸愯尙鐛婇妴?
        $sanitizedBrief = $this->sanitizeSlotBriefForPrompt($rawBrief, $siteTitle);
        if ($sanitizedBrief !== '') {
            $parts[] = $isLogoSlot
                ? 'Slot brief (reference only 閳?does not override PRIMARY SUBJECT): ' . $sanitizedBrief
                : $sanitizedBrief;
        }

        // 缁?4 鐞涘矉绱癰rand name 閳ユ柡鈧?娴犲懎缍?logo 閺冩湹缍旀稉?wordmark text閿涙稑鍙剧€瑰啩绮庢担婊堫棑閺嶈壈鍎楅弲顖ょ礉娑撳秶鏁鹃弬鍥х摟閵?
        if ($siteTitle !== '') {
            if (!$isLogoSlot) {
                $parts[] = 'Brand context (do not render as text on the image): ' . $siteTitle;
            }
        }

        // 缁?5 鐞涘矉绱皌agline 娴犲懍缍旀稉鐑樺剰缂?妞嬪孩鐗哥拫鍐ㄧ摍閿涘奔绗夐悽缁樻瀮鐎涙ぜ鈧?
        if ($siteTagline !== '') {
            if ($isLogoSlot) {
                $parts[] = 'Identity style hint: derive mood, palette, and subject cues from PRIMARY SUBJECT and confirmed theme only; never draw, spell, abbreviate, or typeset the tagline or user text.';
            } else {
                $parts[] = 'Brand personality (reflect this tone in visual style): ' . $siteTagline;
            }
        }
        $confirmedThemePalettePrompt = $this->buildConfirmedThemePalettePrompt($scope, $isLogoSlot, $isFaviconLikeSlot);
        if ($confirmedThemePalettePrompt !== '') {
            $parts[] = $confirmedThemePalettePrompt;
        }
        if ($this->isNeonCardDomainText($primarySubject . ' ' . $businessContext . ' ' . $rawBrief . ' ' . $siteTagline)) {
            $parts[] = $isLogoSlot || $isFaviconLikeSlot
                ? 'Neon card-game identity style lock: use dark premium card-room energy, electric cyan/magenta/violet accents, restrained gold highlights, and a business-relevant card/mahjong/table glyph. Keep transparency for identity assets.'
                : 'Neon card-game image style lock: use a premium dark card-room atmosphere, electric cyan/magenta/violet glow, restrained gold highlights, green felt or glass table texture, and props such as playing cards, mahjong tiles, poker chips, live table UI, guide cards, or support console details only when they match this exact block. Each image MUST express its own block role; do not reuse the same hero lobby scene for proof, feature, article, about, or support sections.';
        }

        $kind = $this->firstString([$slot['kind'] ?? null, $slot['slot_type'] ?? null]);
        if ($kind !== '') {
            $parts[] = 'Asset kind: ' . $kind;
        }

        $isHeroSlot = $this->slotDeclaresStrictHeroImage($slot);

        if ($isFaviconLikeSlot) {
            $parts[] = 'Favicon output requirements (HARD): generate a production-ready square 1:1 symbol-only identity image with a real transparent background (transparent PNG alpha, or safe SVG with no canvas background). The pictorial symbol is isolated on transparency; there must be no white background, solid color background, rounded square tile, card, gradient backdrop, photo scene, website mockup, watermark, screenshot frame, initials, monogram, readable text, pseudo text, or paragraph text. Keep it recognizable at 16-64px with one bold business-relevant symbol.';
        } elseif ($isLogoSlot) {
            $parts[] = 'Logo output requirements (HARD): generate a production-ready symbol-only identity logo with a real transparent background (transparent PNG alpha, or safe SVG with no canvas background). Keep only non-typographic icon/glyph pixels on transparency. Do NOT include readable letters, initials, monograms, words, brand names, slogans, user requirement text, paragraph text, pseudo text, watermarks, or long typographic strips. Do not place the logo on a white box, colored rectangle, rounded card, wall, photo scene, gradient backdrop, website mockup, screenshot frame, or any other background surface.';
        } elseif ($isHeroSlot) {
            $parts[] = 'Hero banner default output requirements: when the user has not explicitly requested another hero image composition, compose for a 1920x750 website banner crop. Fill the entire canvas edge-to-edge with one immersive full-width scene. A transparent background is not needed 閳?cover the full canvas with the subject matter and keep important subjects inside the center-safe area so CSS object-fit:cover can crop cleanly.';
            $parts[] = 'Hero visual quality bar (CRITICAL): premium cinematic website banner background, very wide horizontal composition, edge-to-edge coverage, strong depth, realistic lighting, high-end commercial art direction. Do NOT generate flat vector art, SVG-like shapes, childish cartoon, icon collage, clip-art, rough geometric placeholder art, cardboard-looking cards, UI mockups, or simplistic low-detail illustration. Prefer realistic/editorial photography or photoreal premium 3D only when the subject cannot be photographed.';
            $parts[] = $this->buildBlockImageArtifactContract(true);
        } else {
            $parts[] = 'Section image output requirements: generate a premium editorial/commercial website image that fills the whole rectangular canvas with intentional composition, depth, and lighting. Do not use transparent cutouts unless the slot explicitly says logo/icon.';
            $parts[] = 'Style-match requirement (CRITICAL): the visual style, color temperature, lighting, and composition MUST align with the overall brand aesthetic described in the reference style keywords/color palette above. Do NOT generate a generic stock photo, overly saturated 3D render, childish cartoon illustration, flat vector/SVG-like shapes, clip-art, rough geometric placeholder art, or dark/gritty image unless those match the brand style. Keep the rendering quality consistent with a premium brand website.';
            $parts[] = $this->buildBlockImageArtifactContract(false);
        }
        $parts[] = $this->buildAssetPromptTeachingExamples($isLogoSlot, $isFaviconLikeSlot, $isHeroSlot);
        $pageType = $this->firstString([$slot['page_type'] ?? null]);
        if ($pageType !== '') {
            $parts[] = 'Page type: ' . $pageType;
        }
        // 瀵缚顢戞總鎴犲閿涙eference_image_insights 娑擃厾娈?layout/component cues 缂佸繐鐖堕幓蹇氬牚
        // "header + hero + columns + footer" 鏉╂瑧琚弫鎾€夌紒鎾寸€敍灞芥澓缂佹瑥宕?block 閸ユ儳鍎氶悽鐔稿灇閺?
        // 娴兼俺顔€ AI 婢跺秴鍩楅幋鎰秹缁?mockup閵嗗倷绮?logo 缁槒绁禍褌绻氶悾娆擃棑閺?reference閿涙稑鍙剧€瑰啳顫嬬憴澶岀閺?
        // 娴犲懎鎯涢弨鍫曨杹閼?閹烘帞澧楅崗鎶芥暛鐠囧稄绱濋崜銉ь瀲鐢啫鐪?缂佸嫪娆㈢仦鍌炴桨閻ㄥ嫰銆夐棃銏㈢波閺嬪嫭娈粈鎭掆偓?
        $referenceInsightsPrompt = $isLogoSlot
            ? $this->buildReferenceInsightsPrompt($scope)
            : $this->buildBlockReferenceInsightsPrompt($scope);
        if ($referenceInsightsPrompt !== '') {
            $parts[] = $referenceInsightsPrompt;
        }

        // 閺€璺虹啲閿涙艾鍟€濞嗏€愁槻鏉?PRIMARY SUBJECT閿涘矂浼╅崗?AI 閸︺劑鏆?prompt 娑?濠曞倻些"韫囨ɑ甯€妫ｆ牞顩︽總鎴犲閵?
        if ($primarySubject !== '') {
            $parts[] = 'Reinforced contract: the visual MUST stay within the PRIMARY SUBJECT domain stated above; reject any drift toward unrelated mascots, generic stock imagery, or off-topic scenery.';
        }

        return \trim(\implode("\n", $parts));
    }

    private function buildAssetPromptTeachingExamples(bool $isLogoSlot, bool $isFaviconLikeSlot, bool $isHeroSlot): string
    {
        if ($isFaviconLikeSlot) {
            return 'Teaching examples: GOOD favicon prompt shape - one bold business-relevant glyph or monogram on transparent alpha, readable at 16px, no tile/background. BAD - a mascot, full website screenshot, rounded app tile, paragraph text, or generic sparkle icon.';
        }
        if ($isLogoSlot) {
            return 'Teaching examples: GOOD logo prompt shape - business-relevant symbol-only mark on transparent alpha, no readable text, no canvas surface. BAD - brand name text strip, slogan, pseudo letters, colored square, unrelated mascot, photo scene, website mockup, or logo placed on a card.';
        }
        if ($isHeroSlot) {
            return 'Teaching examples: GOOD hero prompt shape - real scene/product/interface subject, environment, lighting, wide crop, safe focal area, and brand palette integration. BAD - isolated icon, badge, shield, logo, abstract gradient, placeholder mockup, generic stock photo, or off-topic character.';
        }

        return 'Teaching examples: GOOD section image prompt shape - concrete editorial/product/interface subject, supporting props, crop, lighting, and how it fits the block narrative. BAD - isolated icon, generic decorative pattern, dummy placeholder, fake screenshot, unrelated mascot, or CSS-only motif described as an image.';
    }

    private function isNeonCardDomainText(string $text): bool
    {
        $text = \mb_strtolower($text, 'UTF-8');
        if ($text === '') {
            return false;
        }

        return \preg_match('/(?:neon|casino|card\s*game|poker|mahjong|rummy|teen\s*patti|game\s*lobby|gaming|濡澧潀濡澧濆〒鍛婂灆|闂囨捁娅閻楀本顢憒閻楀苯鐪瑋閹垫垵鍘爘妤硅鐨閻㈢數甯洪崺宸痪澶哥瑐婵炲彉绠皘濞撳憡鍨欓幋鍧楁？|鐠ф稐绨ㄩ幋鍧楁？)/iu', $text) === 1;
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
     * 瀵缚顢戞總鎴犲閹惰棄褰囬敍姘唨娴?brief_description 閹恒劌顕辨稉鈧紒?韫囧懘銆忛崙铏瑰箛閻ㄥ嫬娴橀崓蹇庡瘜娴ｆ挸鍙ч柨顔跨槤"閵?
     * 鏉╂瑦妲告穱顔碱槻 logo/banner 娑撳海鏁ら幋铚傜瑹閸斅ょ様濮瑰倽鍔氶懞鍌滄畱閸忔娊鏁垾鏂衡偓鎿淚 閻鍩岄崗铚傜秼閸氬秷鐦?
     * 閿涘牆鍙挎担鎾查獓閸濅降鈧焦婀囬崝掳鈧焦娼楅弬娆嶁偓浣告簚閺咁垳鐡戦敍澶夌窗娴兼ê鍘涢幎濠傜暊娴狀剛鏁炬潻娑樻禈閿?
     * 閼板苯鍘滈惇?"Business context: ..." 闂€鍨綖閸欏秷鈧奔绱扮悮顐ｉキ濞屄扳偓?
     *
     * 閸忔娊鏁拠宥囨晸閹存劘顫夐崚娆欑窗
     *   1. 娴兼ê鍘涙禒?brief_description 閹惰棄褰囬妴鍌滅叚閸欍儳娲块幒銉ヮ槻閻㈩煉绱遍梹鍨綖娣囨繄鏆€閸?240 鐎涙顑侀妴?
     *   2. 閸氬本妞傞崣鐘插 slot.brief 瀹告彃鐡ㄩ崷銊ф畱妫板棗鐓欓崥宥堢槤閿涘牆顩ч弸婊勬箒閿涘绱濋崙蹇撶毌 prompt 濠曞倻些閵?
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

        $isIdentitySlot = $this->isLogoAssetSlot($slot) || $this->isFaviconLikeSlot($slot);
        if (!$isIdentitySlot && $this->isConcreteBlockVisualSubjectCandidate($slotBrief)) {
            return $this->clipText($slotBrief, 240);
        }

        // 娴兼ê鍘?business context閿涙稓宸遍惇渚€鈧偓閸?tagline閿涙稑鍟€闁偓閸?slot.brief閵?
        if ($businessContext !== '') {
            return $this->clipText($businessContext, 240);
        }
        if ($siteTagline !== '') {
            return $this->clipText($siteTagline, 240);
        }

        return $this->clipText($slotBrief, 240);
    }

    /**
     * 閹?slot.brief 娑?Generate the official website logo for X"鏉╂瑧琚幎?brand 瑜版挷瀵屾担鎾舵畱閸愭瑦纭?
     * 閺囨寧宕查幋鎰厬閹?"Logo output for X"閿涘矂浼╅崗?AI 閸戣櫣骞囨稉璁崇秼閸愯尙鐛婇敍鍦IMARY SUBJECT vs slot.brief閿涘鈧?
     */
    /**
     * True only when slot.brief already carries a concrete block-level generated-image subject.
     */
    private function isConcreteBlockVisualSubjectCandidate(string $slotBrief): bool
    {
        $slotBrief = \trim($slotBrief);
        if ($slotBrief === '') {
            return false;
        }

        $normalized = \mb_strtolower($slotBrief, 'UTF-8');
        $compact = \trim((string)\preg_replace('/[\s\p{P}]+/u', ' ', $normalized));
        if (\mb_strlen($compact, 'UTF-8') < 32) {
            return false;
        }
        if (\preg_match('/^(?:home\s+)?(?:hero|section|block|banner)?\s*(?:visual|image|picture|background|photo)(?:\s+that\s+illustrates\s+the\s+block\s+promise)?$/iu', $compact) === 1) {
            return false;
        }
        if (\preg_match('/^(?:generated\s+)?(?:hero|section|block|banner)\s+(?:visual|image)$/iu', $compact) === 1) {
            return false;
        }

        if (\preg_match('/(?:block visual|stage-1 image intent|block contract media strategy|image intent|media strategy):/iu', $slotBrief) === 1) {
            return true;
        }

        return \preg_match(
            '/(?:scene|photograph|photo|editorial|environment|interface|mockup|room|lobby|table|poker|mahjong|cards|card-game|chips|support desk|guide|players|tournament|neon|cinematic|product visual|service environment)/iu',
            $slotBrief
        ) === 1;
    }

    /**
     * Rewrite logo/favicon briefs that put brand text before the actual visual subject.
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
        $siteTitle = \trim($siteTitle);
        if ($siteTitle !== '') {
            $quotedSiteTitle = \preg_quote($siteTitle, '/');
            $brief = \preg_replace('/["\']?' . $quotedSiteTitle . '["\']?/iu', 'the approved business subject', $brief) ?? $brief;
        }

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
            || \str_contains($kind, 'favicon')
            || \str_contains($label, 'favicon')
            || \str_contains($slotId, 'favicon');
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function syncFromPlanJson(array $scope): array
    {
        $manifest = $this->normalize(\is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : []);
        $manifest = $this->dropLegacyWebsiteLogoIdentitySlot($manifest);
        $currentPlanJsonSlots = $this->buildRequiredContentBlockSlots($scope);
        $manifest = $this->dropStalePlanJsonScopedSlots($manifest, $currentPlanJsonSlots);
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
        foreach ($currentPlanJsonSlots as $slot) {
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
        $contractHash = \trim((string)($scope['plan_json']['signature'] ?? $scope['plan_json']['plan_signature'] ?? ''));
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
     * @param array<string,mixed> $manifest
     * @param list<array<string,mixed>> $currentSlots
     * @return array<string,mixed>
     */
    private function dropStalePlanJsonScopedSlots(array $manifest, array $currentSlots): array
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
        $sources = [
            $scope['plan_json'] ?? [],
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
            ]);
            if ($pageType !== '' && !$this->isScopedSlotId($slotId)) {
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
        $subjectAnchor = $briefDescription !== ''
            ? $briefDescription
            : 'the business subject from the approved user requirement';

        $logoBriefParts = [];
        if ($subjectAnchor !== '') {
            $logoBriefParts[] = 'PRIMARY SUBJECT for the logo glyph (the mark MUST visually depict this exact business; derive concrete iconography only from the approved brief, products, services, materials, culture, and visual plan; never copy example industries or unrelated symbols): '
                . $subjectAnchor;
        }
        $logoBriefParts[] = 'Output requirements (HARD): symbol-only identity logo with a real transparent background (transparent PNG alpha, or safe SVG with no canvas background), production-ready simple brand mark/glyph. Do NOT include readable letters, initials, monograms, words, brand names, slogans, user requirement text, paragraph text, pseudo text, watermark, or long typographic strips. Keep only logo mark pixels on transparency; no white box, colored rectangle, rounded card, gradient backdrop, checkerboard transparency preview, extra scene, mockup, or screenshot frame.';
        if ($siteTagline !== '' && $siteTagline !== $subjectAnchor) {
            $logoBriefParts[] = 'Style/personality hint: derive mood from the approved user requirement and confirmed theme only; never draw, spell, abbreviate, or typeset the tagline or user text.';
        }
        $logoThemePalettePrompt = $this->buildConfirmedThemePalettePrompt($scope, true, false);
        if ($logoThemePalettePrompt !== '') {
            $logoBriefParts[] = $logoThemePalettePrompt;
        }
        if ($briefDescription !== '' && $briefDescription !== $subjectAnchor) {
            $logoBriefParts[] = 'Business context (every glyph element must reflect this domain): ' . $briefDescription;
        }
        $logoBrief = \implode("\n", $logoBriefParts);

        return $this->buildThemeLogoGenerationOptionSlots($scope, $logoBrief);
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function buildLogoTextLanguageInstruction(array $scope): string
    {
        $locale = \strtolower(\str_replace('-', '_', $this->firstString([
            $scope['plan_json']['i18n']['content_locale'] ?? null,
            $scope['plan_json']['i18n']['primary_locale'] ?? null,
            $scope['plan_json']['i18n']['locale'] ?? null,
            $scope['content_locale'] ?? null,
            $scope['plan_generated_locale'] ?? null,
            $scope['plan_locale'] ?? null,
            $scope['website_profile']['content_locale'] ?? null,
            $scope['default_locale'] ?? null,
            $scope['default_language'] ?? null,
        ])));
        if ($locale === '') {
            return 'Visible logo text ban (HARD): generate a symbol-only logo icon. Do not include readable text, initials, monograms, brand names, slogans, user requirement text, or pseudo text in any language.';
        }

        if ($locale === 'ru' || \str_starts_with($locale, 'ru_')) {
            return 'Visible logo text ban (HARD): content_locale=' . $locale . '. Generate a symbol-only logo icon. Do not include Cyrillic, Latin, CJK, initials, monograms, placeholder, pseudo, user requirement, or mixed-language text.';
        }

        if ($locale === 'zh' || \str_starts_with($locale, 'zh_')) {
            return 'Visible logo text ban (HARD): content_locale=' . $locale . '. Generate a symbol-only logo icon. Do not include Chinese characters, Latin words, initials, monograms, placeholder, pseudo, user requirement, or mixed-language text.';
        }

        return 'Visible logo text ban (HARD): content_locale=' . $locale . '. Generate a symbol-only logo icon. Do not include readable letters, initials, monograms, words, brand names, slogans, user requirement text, placeholder text, pseudo text, or CJK characters.';
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildThemeLogoGenerationOptionSlots(array $scope, string $logoBrief): array
    {
        $options = $this->readThemeLogoGenerationOptions($scope);
        $slots = [];
        for ($index = 0; $index < 4; $index++) {
            $number = $index + 1;
            $option = \is_array($options[$index] ?? null) ? $options[$index] : [];
            $optionId = $this->firstString([$option['option_id'] ?? null, 'logo_option_' . $number]);
            if (!\preg_match('/^logo_option_[1-4]$/', $optionId)) {
                $optionId = 'logo_option_' . $number;
            }
            $slotId = $this->normalizeSlotId($this->firstString([
                $option['asset_slot_id'] ?? null,
                $option['slot_id'] ?? null,
                'plan:theme:logo_generation:option_' . $number,
            ]));
            if (!\str_starts_with($slotId, 'plan:theme:logo_generation:option_')) {
                $slotId = 'plan:theme:logo_generation:option_' . $number;
            }
            $styleDirection = $this->firstString([$option['style_direction'] ?? null]);
            $briefParts = [$logoBrief];
            $briefParts[] = 'Option prompt guard (HARD): ignore any plan text that asks to draw, spell, abbreviate, or typeset a site name, brand name, initials, monogram, user requirement, slogan, label, option label, or pseudo text.';
            if ($styleDirection !== '') {
                $briefParts[] = 'Option style direction: ' . $styleDirection;
            }
            $finalUrl = $this->firstString([
                $option['final_url'] ?? null,
                $option['url'] ?? null,
                $option['asset_url'] ?? null,
            ]);
            $status = $this->firstString([$option['status'] ?? null, $finalUrl !== '' ? 'generated' : 'pending']);
            if ($finalUrl !== '') {
                $status = 'generated';
            }
            $slots[] = [
                'slot_id' => $slotId,
                'option_id' => $optionId,
                'logo_option_id' => $optionId,
                'slot_type' => 'logo_icon',
                'kind' => 'logo_option',
                'page_type' => 'global',
                'field' => 'theme.logo_generation.options.' . $optionId,
                'task_key' => 'theme:logo_generation',
                'section_code' => 'theme_logo_generation',
                'label' => $this->firstString([$option['label'] ?? null, 'Logo ' . $number]),
                'target_size' => '1024x512',
                'aspect_ratio' => '2:1',
                'output_format' => 'png',
                'background' => 'transparent',
                'transparent_png_required' => true,
                'identity_transparent_png_required' => true,
                'brief' => \implode("\n", $briefParts),
                'prompt_brief' => \implode("\n", $briefParts),
                'source' => $finalUrl !== '' ? 'generated' : 'planned',
                'status' => $status,
                'final_url' => $finalUrl,
                'url' => $finalUrl,
                'required' => 0,
                'desired_image' => 1,
                'locked_by_user' => 0,
            ];
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function readThemeLogoGenerationOptions(array $scope): array
    {
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $theme = \is_array($planJson['theme'] ?? null) ? $planJson['theme'] : [];
        $logoGeneration = \is_array($theme['logo_generation'] ?? null) ? $theme['logo_generation'] : [];
        $rawOptions = \is_array($logoGeneration['options'] ?? null) ? $logoGeneration['options'] : [];
        $options = [];
        foreach ($rawOptions as $key => $option) {
            if (!\is_array($option)) {
                continue;
            }
            if (!isset($option['option_id']) && \is_string($key) && $key !== '') {
                $option['option_id'] = $key;
            }
            $options[] = $option;
        }

        return \array_slice($options, 0, 4);
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function dropLegacyWebsiteLogoIdentitySlot(array $manifest): array
    {
        if (!\is_array($manifest['slots'] ?? null)) {
            return $manifest;
        }

        $removed = false;
        foreach ($manifest['slots'] as $slotKey => $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            $slotId = \strtolower(\trim((string)($slot['slot_id'] ?? $slotKey)));
            $slotType = \strtolower(\trim((string)($slot['slot_type'] ?? '')));
            $field = \strtolower(\trim((string)($slot['field'] ?? '')));
            $kind = \strtolower(\trim((string)($slot['kind'] ?? '')));
            if (
                !\str_starts_with($slotId, 'identity:')
                || $slotType !== 'logo_icon'
                || (
                    !\in_array($field, ['logo', 'logo.image', 'brand.logo', 'icon', 'favicon', 'site.icon'], true)
                    && !\in_array($kind, ['website_logo', 'brand_logo', 'favicon'], true)
                )
            ) {
                continue;
            }
            unset($manifest['slots'][$slotKey]);
            $removed = true;
        }
        if ($removed) {
            $manifest['updated_at'] = \date('Y-m-d H:i:s');
        }

        return $manifest;
    }

    /**
     * Stage-1 JSON often omits a dedicated image row in {@see collectRequirementRows}, which leaves the
     * asset manifest without a planned hero slot 閳?previews fall back to bare gradients with no banner image.
     * We synthesize a stable hero_image slot per hero section derived from the same blueprint wiring as
     * {@see AiSitePlanJsonTaskService::buildBlueprintFromStageOnePlanJsonGeneration}.
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

            // 瀵缚顢戞總鎴犲閿涙瓬anner slot.brief 韫囧懘銆忔禒銉ょ瑹閸斺€插瘜娴ｆ挸绱戞径杈剧礉閸氾箑鍨?AI 娴兼氨鏁炬稉搴ょ様濮瑰倹妫ら崗宕囨畱
            // 闁氨鏁ゅ〒鎰綁/閹跺€熻杽閸ョ偓顢嶉敍宀冪"濡澧?閸楁澘瀹崇敮鍌氭簚/APK 娑撳娴?缁涘婀＄€圭偠鐦斿Ч鍌濆姎閼哄倶鈧?
            $briefParts = [];
            if ($businessContext !== '') {
                $briefParts[] = 'PRIMARY SUBJECT for the hero banner background (CRITICAL 閳?the entire scene MUST depict this business and culture; do not substitute a generic abstract gradient, generic stock photo, or off-topic figures): '
                    . $businessContext;
            }
            $briefParts[] = 'Format default: 1920x750-style full-width hero banner background image (photography or cinematic illustration) for the above-the-fold section. Unless the user explicitly requests a different hero visual composition, fill the entire canvas edge-to-edge with one immersive wide scene and keep important subjects within the center-safe crop area. Apply a subtle gradient overlay at top and bottom edges (dark-to-transparent) so text and page content can overlay the image naturally. The style and color temperature MUST match the brand identity 閳?not a generic stock photo.';
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
     * Build page-scoped image slots from plan_json.pages dynamic blocks.
     *
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    private function buildRequiredContentBlockSlots(array $scope): array
    {
        $slots = [];
        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        if ($pages === []) {
            return [];
        }

        $businessContext = $this->firstBusinessContextString([
            $scope['website_profile']['brief_description'] ?? null,
            $scope['brief_description'] ?? null,
            $scope['user_description'] ?? null,
        ]);
        $contentItems = \is_array($planJson['content_manifest']['items'] ?? null)
            ? $planJson['content_manifest']['items']
            : [];
        foreach ($pages as $pageType => $page) {
            if (!\is_string($pageType) || !\is_array($page)) {
                continue;
            }
            foreach ($this->extractPlanJsonPageBlocks($page) as $blockKey => $block) {
                $blockKey = $this->normalizeSlotId((string)$blockKey);
            if ($pageType === '' || $blockKey === '') {
                continue;
            }
            $imageIntent = \is_array($block['image_intent'] ?? null)
                ? $block['image_intent']
                : (\is_array($block['visual']['image_intent'] ?? null) ? $block['visual']['image_intent'] : []);
            $blockContract = \is_array($block['block_contract'] ?? null)
                ? $block['block_contract']
                : (\is_array($block['visual']['block_contract'] ?? null) ? $block['visual']['block_contract'] : []);
            $mediaStrategy = \is_array($blockContract['media_strategy'] ?? null) ? $blockContract['media_strategy'] : [];
            if ($imageIntent === [] && $mediaStrategy !== []) {
                $imageIntent = [
                    'needs_image' => !empty($mediaStrategy['needs_real_image']),
                    'image_role' => (string)($block['page_flow_role'] ?? '') === 'opening' ? 'hero_image' : 'section_image',
                    'image_subject' => (string)($mediaStrategy['image_subject'] ?? ''),
                    'placement' => (string)($mediaStrategy['placement'] ?? ''),
                    'visual_atmosphere' => 'aligned with confirmed block contract',
                    'image_treatment' => (string)($mediaStrategy['image_treatment'] ?? ''),
                    'reuse_policy' => 'reuse_when_intent_matches',
                    'css_motif' => (string)($mediaStrategy['css_motif'] ?? ''),
                ];
            }
            if (!$this->blockShouldRequireGeneratedImage($block, $imageIntent)) {
                continue;
            }

            $sectionCode = $this->buildSectionCodeFromBlockKey($pageType, $blockKey);
            $slotId = $this->normalizeSlotId($this->firstString([
                $mediaStrategy['asset_slot_id'] ?? null,
                'page:' . $pageType . ':' . \str_replace('/', '-', $sectionCode),
            ]));
            $titleCopy = $this->extractPlanJsonBlockContentText($block, $contentItems);
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
                    . '; atmosphere=' . $this->firstString([$imageIntent['visual_atmosphere'] ?? null])
                    . '; treatment=' . $this->firstString([$imageIntent['image_treatment'] ?? null])
                    . '; reuse_policy=' . $this->firstString([$imageIntent['reuse_policy'] ?? null]);
            }
            if ($mediaStrategy !== []) {
                $briefParts[] = 'Block contract media strategy: ' . $this->firstString([$mediaStrategy['image_subject'] ?? null])
                    . '; placement=' . $this->firstString([$mediaStrategy['placement'] ?? null])
                    . '; treatment=' . $this->firstString([$mediaStrategy['image_treatment'] ?? null]);
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
                'task_key' => 'page:' . $pageType . ':' . $blockKey,
                'section_code' => $sectionCode,
                'label' => $this->firstString([$titleCopy, $block['goal'] ?? null, $blockKey]),
                'brief' => $brief,
                'prompt_brief' => $brief,
                'status' => 'pending',
                'source' => 'plan_json',
                'final_url' => '',
                'required' => 1,
                'desired_image' => 1,
                'image_intent' => $imageIntent,
                'block_contract' => $blockContract,
                'visual_signature' => \is_array($block['visual_signature'] ?? null) ? $block['visual_signature'] : [],
                'locked_by_user' => 0,
            ];
        }
        }

        return $slots;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string|int, mixed> $contentItems
     */
    private function extractPlanJsonBlockContentText(array $block, array $contentItems): string
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

    /**
     * @param array<string, mixed> $page
     * @return array<string, array<string, mixed>>
     */
    private function extractPlanJsonPageBlocks(array $page): array
    {
        $blocks = [];
        foreach ($page as $blockKey => $block) {
            if (!\is_string($blockKey) || !\is_array($block)) {
                continue;
            }
            if (\in_array($blockKey, [
                'page_key',
                'page_type',
                'type',
                'status',
                'title',
                'label',
                'page_title',
                'page_goal',
                'page_design_plan',
                'theme_alignment_summary',
                'content_locale',
                'seo',
                'meta_title',
                'meta_description',
                'meta_keywords',
                'route',
                'slug',
                'path',
                'layout',
                'sections',
                'section_refinements',
                'blocks',
                'block_previews',
                'updated_at',
                'started_at',
                'finished_at',
                'error',
                'error_message',
            ], true)) {
                continue;
            }
            $blocks[$blockKey] = $block;
        }

        return $blocks;
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
        foreach ([
            'planned atmosphere' => $imageIntent['visual_atmosphere'] ?? null,
            'planned treatment' => $imageIntent['image_treatment'] ?? null,
            'planned placement' => $imageIntent['placement'] ?? null,
        ] as $label => $candidate) {
            $text = $this->firstString([$candidate]);
            if ($text !== '') {
                $parts[] = $label . ': ' . $text;
            }
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
        $blockContract = \is_array($block['block_contract'] ?? null)
            ? $block['block_contract']
            : (\is_array($block['visual']['block_contract'] ?? null) ? $block['visual']['block_contract'] : []);
        if (!empty($blockContract['media_strategy']['needs_real_image'])) {
            return true;
        }
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
            $this->firstString([$imageIntent['image_treatment'] ?? null]),
            $this->firstString([$imageIntent['visual_atmosphere'] ?? null]),
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

        $planJson = \is_array($scope['plan_json'] ?? null) ? $scope['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        $contentItems = \is_array($planJson['content_manifest']['items'] ?? null)
            ? $planJson['content_manifest']['items']
            : [];
        $homePage = \is_array($pages['home_page'] ?? null) ? $pages['home_page'] : [];
        foreach ($this->extractPlanJsonPageBlocks($homePage) as $blockKey => $block) {
            $imageIntent = \is_array($block['image_intent'] ?? null) ? $block['image_intent'] : [];
            $isHero = \in_array(\strtolower(\trim((string)($imageIntent['image_role'] ?? ''))), ['hero_image', 'hero_banner'], true)
                || \strtolower(\trim((string)($block['page_flow_role'] ?? ''))) === 'opening'
                || (int)($block['visual']['strict_hero_cover'] ?? $block['strict_hero_cover'] ?? 0) === 1;
            if (!$isHero) {
                continue;
            }
            $blockKey = \trim((string)($block['section_key'] ?? $block['block_key'] ?? $blockKey));
            if ($blockKey === '') {
                continue;
            }
            $pageType = 'home_page';
            $sectionCode = $this->buildSectionCodeFromBlockKey($pageType, $blockKey);
            $append($pageType, $sectionCode, $this->extractPlanJsonBlockContentText($block, $contentItems));
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
        $role = \strtolower(\trim($role));
        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : $url;
        $path = '/' . \ltrim(\preg_replace('#/+#', '/', \str_replace('\\', '/', $path)) ?? $path, '/');
        $lowerPath = \strtolower($path);
        $isPageBuilderGeneratedAsset = \str_contains($lowerPath, '/pub/media/page-build/')
            && \str_contains($lowerPath, '/ai-generated/');
        if (!$isPageBuilderGeneratedAsset) {
            return true;
        }

        return $role === 'logo' && \str_contains($lowerPath, 'plan-theme-logo-generation-option');
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
            || \in_array($kind, ['website_logo', 'brand_logo', 'logo_option'], true)
            || \str_starts_with($slotId, 'plan:theme:logo_generation:option_')
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
            'output_format' => \trim((string)($slot['output_format'] ?? '')),
            'background' => \trim((string)($slot['background'] ?? '')),
            'image_timeout' => (int)($slot['image_timeout'] ?? $slot['image_generation_timeout'] ?? 0),
            'image_generation_max_attempts' => (int)($slot['image_generation_max_attempts'] ?? $slot['max_image_generation_attempts'] ?? 0),
            'transparent_png_required' => (int)($slot['transparent_png_required'] ?? 0) === 1 ? 1 : 0,
            'identity_transparent_png_required' => (int)($slot['identity_transparent_png_required'] ?? 0) === 1 ? 1 : 0,
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
        if (
            $source === 'generated'
            && \str_contains($lowerFinalUrl, '/ai-generated/')
            && \str_ends_with($lowerFinalUrl, '.svg')
        ) {
            return !$this->isLogoAssetSlot($slot) && !$this->isFaviconLikeSlot($slot);
        }

        return false;
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
     * 閸ф楠囬崶鎯у剼娑撴挾鏁?reference 閹芥顩﹂敍姘矌娣囨繄鏆€閼规彃鍍?鐠愩劍鍔?妞嬪孩鐗哥猾缁樺絹缁€鐚寸礉
     * 閸撱儳顬?layout_cues / component_cues 缁涘绱扮悮?AI 鐟欙綀顕版稉?閻㈢粯鏆ｆい?閻ㄥ嫮绮ㄩ弸鍕繆閸欐灚鈧?
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
            . ' Exception: identity logo/favicon slots use their own stricter symbol-only contract; this block-image contract is not used for identity slots.';
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
            $assetName = $isFaviconLikeSlot ? 'favicon' : 'logo';
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
