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
     * @param array<string, mixed> $slot
     * @param array<string, mixed> $scope
     */
    public function buildPrompt(array $slot, array $scope = []): string
    {
        $brief = $this->firstString([
            $slot['prompt_brief'] ?? null,
            $slot['brief'] ?? null,
            $slot['description'] ?? null,
            $slot['label'] ?? null,
        ]);
        $siteTitle = $this->firstString([
            $scope['website_profile']['site_title'] ?? null,
            $scope['site_title'] ?? null,
        ]);
        $parts = [];
        if ($brief !== '') {
            $parts[] = $brief;
        }
        if ($siteTitle !== '') {
            $parts[] = 'Website: ' . $siteTitle;
        }
        $kind = $this->firstString([$slot['kind'] ?? null, $slot['slot_type'] ?? null]);
        if ($kind !== '') {
            $parts[] = 'Asset kind: ' . $kind;
        }
        $pageType = $this->firstString([$slot['page_type'] ?? null]);
        if ($pageType !== '') {
            $parts[] = 'Page type: ' . $pageType;
        }
        $referenceInsightsPrompt = $this->buildReferenceInsightsPrompt($scope);
        if ($referenceInsightsPrompt !== '') {
            $parts[] = $referenceInsightsPrompt;
        }

        return \trim(\implode("\n", $parts));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function syncFromTaskPlan(array $scope): array
    {
        $manifest = $this->normalize(\is_array($scope['asset_manifest'] ?? null) ? $scope['asset_manifest'] : []);
        foreach ($this->extractSlotsFromScope($scope) as $slot) {
            $manifest = $this->upsert($manifest, $slot);
        }
        foreach ($this->buildRequiredIdentitySlots($scope) as $slot) {
            $manifest = $this->upsert($manifest, $slot);
        }

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
            if ($finalUrl !== '') {
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
            $scope['task_plan_structured'] ?? [],
            $scope['virtual_theme_plan']['draft'] ?? [],
            $scope['virtual_theme_plan']['confirmed'] ?? [],
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

        foreach ($node as $value) {
            if (\is_array($value)) {
                $this->collectSlotsRecursive($value, $slots, $context);
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
            $slots[$slotId] = [
                'slot_id' => $slotId,
                'slot_type' => $slotType,
                'kind' => $slotType,
                'page_type' => $this->firstString([$row['page_type'] ?? null, $context['page_type'] ?? '']),
                'block_key' => $this->firstString([$row['block_key'] ?? null, $context['block_key'] ?? '']),
                'field' => $this->firstString([$row['field'] ?? null, $row['field_key'] ?? null, 'image']),
                'task_key' => $this->firstString([$row['task_key'] ?? null, $context['task_key'] ?? '']),
                'section_code' => $this->firstString([$row['section_code'] ?? null, $context['section_code'] ?? '']),
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

        $logoBrief = 'Generate the official website logo for "' . $brandReference . '". '
            . 'Strong constraints: transparent background, production-ready horizontal logo or wordmark, simple brand mark, no mockup, no extra scene, no paragraph text, no watermark, no screenshot frame.';
        if ($siteTagline !== '') {
            $logoBrief .= ' Visual direction hint: ' . $siteTagline . '.';
        }
        if ($briefDescription !== '') {
            $logoBrief .= ' Business context: ' . $briefDescription . '.';
        }

        $iconBrief = 'Generate the website title icon / favicon for "' . $brandReference . '". '
            . 'Strong constraints: square 1:1 composition, transparent or clean solid background, highly recognizable at 16-64px, one bold symbol or monogram only, no paragraph text, no mockup, no watermark.';
        if ($siteTagline !== '') {
            $iconBrief .= ' Style hint: ' . $siteTagline . '.';
        }
        if ($briefDescription !== '') {
            $iconBrief .= ' Business context: ' . $briefDescription . '.';
        }

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
        ];
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
