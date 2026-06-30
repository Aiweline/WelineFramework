<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Style;

use Weline\Ai\Model\AiStyle;
use Weline\Ai\Service\SkillStyleTrace;
use Weline\Framework\Manager\ObjectManager;

final class StyleService
{
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';
    public const MODE_NONE = 'none';

    public function __construct(
        private readonly ?StyleRegistry $registry = null,
        private readonly ?StyleRepository $repository = null,
        private readonly ?AdapterStyleResolver $adapterResolver = null,
        private readonly ?StyleNormalizer $normalizer = null,
        private readonly ?AdapterStyleRepository $adapterStyleRepository = null
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listStyles(int $adminId, bool $includeDisabled = true): array
    {
        return $this->registry()->listAvailableStyles($adminId, $includeDisabled);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStyle(string $code, int $adminId): ?array
    {
        $style = $this->registry()->getStyle($code, $adminId, true);
        return !empty($style['exists']) ? $style : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveCustom(array $data, int $adminId): array
    {
        $code = $this->normalizer()->normalizeCode((string)($data['code'] ?? ''), true);
        if ($this->registry()->isReservedCode($code, $adminId)) {
            throw new \InvalidArgumentException((string)__('内置或模块风格为只读，请克隆后再编辑。'));
        }

        $saved = $this->repository()->saveFromArray($data, $adminId, AiStyle::SOURCE_CUSTOM);
        return $this->repository()->modelToArray($saved);
    }

    /**
     * @return array<string, mixed>
     */
    public function disableCustom(string $code, int $adminId): array
    {
        if ($this->registry()->isReservedCode($code, $adminId)) {
            throw new \InvalidArgumentException((string)__('内置或模块风格为只读，不能禁用。'));
        }
        $style = $this->repository()->disable($code, $adminId);
        if (!$style) {
            throw new \InvalidArgumentException((string)__('风格不存在。'));
        }

        return $this->repository()->modelToArray($style);
    }

    public function deleteCustom(string $code, int $adminId): bool
    {
        if ($this->registry()->isReservedCode($code, $adminId)) {
            throw new \InvalidArgumentException((string)__('内置或模块风格为只读，不能删除。'));
        }

        $style = $this->repository()->findArrayByCode($code, $adminId);
        if (!$style) {
            throw new \InvalidArgumentException((string)__('风格不存在。'));
        }
        $this->adapterStyleRepository()->unbindStyleCode($code);

        if (!$this->repository()->delete($code, $adminId)) {
            throw new \InvalidArgumentException((string)__('风格不存在。'));
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function cloneBuiltin(string $code, int $adminId): array
    {
        $source = $this->getStyle($code, $adminId);
        if (!$source || empty($source['readonly'])) {
            throw new \InvalidArgumentException((string)__('只有内置或模块风格可以通过此入口克隆。'));
        }

        $baseCode = $this->normalizer()->normalizeCode((string)$source['code'] . '-custom', true);
        $candidate = $baseCode;
        $suffix = 2;
        while ($this->getStyle($candidate, $adminId) !== null) {
            $candidate = $this->normalizer()->normalizeCode($baseCode . '-' . $suffix, true);
            $suffix++;
        }

        $payload = $source;
        $payload['code'] = $candidate;
        $payload['name'] = (string)($source['name'] ?? $candidate) . ' Copy';
        $payload['source_type'] = AiStyle::SOURCE_CUSTOM;
        $payload['status'] = AiStyle::STATUS_ACTIVE;

        return $this->saveCustom($payload, $adminId);
    }

    /**
     * @return array{matched:bool,item:array<string,mixed>|null,score:int,matched_keywords:list<string>,reason:string}
     */
    public function matchStyle(string $title, string $brief, int $adminId, string $adapterCode = ''): array
    {
        if (\trim($adapterCode) !== '') {
            $resolved = $this->adapterResolver()->resolvePreferredStyle($adapterCode, $title, $brief, $adminId);
            unset($resolved['source']);
            return $resolved;
        }

        return $this->registry()->matchStyle($title, $brief, $adminId);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resolveSelectionForScope(array $scope, int $adminId, bool $lock = false, string $adapterCode = ''): array
    {
        $mode = $this->normalizeMode((string)($scope['design_direction_mode'] ?? self::MODE_AUTO));
        $existingSnapshot = $this->normalizeSnapshot($scope['design_direction_snapshot'] ?? []);
        $existingLocked = $this->isTruthy($scope['design_direction_locked'] ?? 0);
        if ($existingLocked && $existingSnapshot !== [] && !$lock) {
            $this->logSkillStyleTrace('style_selection.reused_locked_snapshot', [
                'mode' => $mode,
                'style_code' => (string)($existingSnapshot['code'] ?? ''),
                'style_version' => (int)($existingSnapshot['version'] ?? 0),
                'style_hash' => (string)($existingSnapshot['hash'] ?? ''),
            ]);
            return $this->buildScopePatchFromSnapshot(
                $mode,
                $existingSnapshot,
                (string)($scope['design_direction_match_reason'] ?? '已冻结风格。'),
                true
            );
        }

        if ($mode === self::MODE_NONE) {
            $this->logSkillStyleTrace('style_selection.none', [
                'lock' => $lock ? 1 : 0,
            ]);
            return [
                'design_direction_mode' => self::MODE_NONE,
                'design_direction_code' => '',
                'design_direction_custom_id' => 0,
                'design_direction' => [],
                'design_direction_snapshot' => [],
                'design_direction_version' => 0,
                'design_direction_hash' => '',
                'design_direction_match_reason' => '未选择风格，使用通用设计方向。',
                'design_direction_locked' => $lock ? 1 : 0,
            ];
        }

        $selectedCode = \trim((string)($scope['design_direction_code'] ?? ''));
        $matchReason = '';
        $style = null;
        if ($mode === self::MODE_MANUAL) {
            $style = $this->getStyle($selectedCode, $adminId);
            if (!$style) {
                throw new \InvalidArgumentException((string)__('手动选择的风格不存在：%{1}', $selectedCode));
            }
            $matchReason = '手动选择：' . (string)($style['name'] ?? $selectedCode);
        } else {
            $match = $this->matchStyle(
                (string)($scope['site_title'] ?? ''),
                (string)($scope['brief_description'] ?? $scope['user_description'] ?? ''),
                $adminId,
                $adapterCode
            );
            $style = \is_array($match['item'] ?? null) ? $match['item'] : null;
            $matchReason = (string)($match['reason'] ?? '');
        }

        if (!$style) {
            $this->logSkillStyleTrace('style_selection.unmatched', [
                'mode' => $mode,
                'lock' => $lock ? 1 : 0,
                'reason' => $matchReason,
            ]);
            return [
                'design_direction_mode' => $mode,
                'design_direction_code' => '',
                'design_direction_custom_id' => 0,
                'design_direction' => [],
                'design_direction_snapshot' => [],
                'design_direction_version' => 0,
                'design_direction_hash' => '',
                'design_direction_match_reason' => $matchReason !== '' ? $matchReason : '未命中垂直风格，使用通用设计方向。',
                'design_direction_locked' => $lock ? 1 : 0,
            ];
        }

        $snapshot = $this->buildSnapshot($style, $matchReason);
        $this->logSkillStyleTrace('style_selection.resolved', [
            'mode' => $mode,
            'lock' => $lock ? 1 : 0,
            'style_code' => (string)($snapshot['code'] ?? ''),
            'style_name' => (string)($snapshot['name'] ?? ''),
            'style_version' => (int)($snapshot['version'] ?? 0),
            'style_hash' => (string)($snapshot['hash'] ?? ''),
            'reason' => $matchReason,
        ]);

        return $this->buildScopePatchFromSnapshot($mode, $snapshot, $matchReason, $lock);
    }

    /**
     * @param array<string, mixed> $style
     * @return array<string, mixed>
     */
    public function buildSnapshot(array $style, string $reason = ''): array
    {
        $snapshot = [
            'code' => (string)($style['code'] ?? ''),
            'name' => (string)($style['name'] ?? ''),
            'description' => (string)($style['description'] ?? ''),
            'source_type' => (string)($style['source_type'] ?? ''),
            'version' => \max(1, (int)($style['version'] ?? 1)),
            'industry_tags' => $this->normalizer()->normalizeStringList($style['industry_tags'] ?? []),
            'match_keywords' => $this->normalizer()->normalizeStringList($style['match_keywords'] ?? []),
            'visual_keywords' => $this->normalizer()->normalizeStringList($style['visual_keywords'] ?? []),
            'color_system' => $this->normalizer()->normalizeFlexibleStructuredField($style['color_system'] ?? []),
            'layout_patterns' => $this->normalizer()->normalizeStringList($style['layout_patterns'] ?? []),
            'image_strategy' => $this->normalizer()->normalizeStringList($style['image_strategy'] ?? []),
            'cta_style' => \trim((string)($style['cta_style'] ?? '')),
            'forbidden_patterns' => $this->normalizer()->normalizeStringList($style['forbidden_patterns'] ?? []),
            'block_rules' => $this->normalizer()->normalizeFlexibleStructuredField($style['block_rules'] ?? []),
            'qa_rules' => $this->normalizer()->normalizeStringList($style['qa_rules'] ?? []),
            'example_refs' => $this->normalizer()->normalizeFlexibleStructuredField($style['example_refs'] ?? []),
            'supplemental_prompt' => \trim((string)($style['supplemental_prompt'] ?? '')),
            'match_reason' => \trim($reason),
        ];
        $snapshot['hash'] = $this->snapshotHash($snapshot);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    public function buildStageOnePromptLines(array $scope): array
    {
        $snapshot = $this->resolveSnapshotFromScope($scope);
        $lines = [
            '',
            'PROMPT ROLE PRIORITY CONTRACT:',
            '- 【用户提示词】is the primary instruction source. If it conflicts with 【系统提示词】or 【通用提示词】, follow the user requirement unless platform safety/schema/output constraints forbid it.',
            '- 【系统提示词】defines schema, locale, queue, and hard platform contracts.',
            '- 【通用提示词】contains shared aesthetic craft guidance such as claude-design; it must not override the user brief or the frozen vertical style.',
            '',
            'AI STYLE CONTRACT:',
        ];
        if ($snapshot === []) {
            $lines[] = '- No vertical style is selected. Use the user brief, theme, and general claude-design craft only; do not import game/APK/card/neon rules unless the user brief explicitly asks for them.';
            $lines[] = '- Still output site-level theme_design.art_direction, page-level page_design_plan, and block-level visual_signature so later generation can execute each block without guessing.';
            return $lines;
        }

        $lines[] = '- Frozen style code: ' . (string)$snapshot['code'] . '; name: ' . (string)$snapshot['name'] . '; version: ' . (string)$snapshot['version'] . '; hash: ' . (string)$snapshot['hash'];
        $lines[] = '- Match reason: ' . ((string)($snapshot['match_reason'] ?? '') !== '' ? (string)$snapshot['match_reason'] : '-');
        $lines[] = '- Description: ' . ((string)$snapshot['description'] !== '' ? (string)$snapshot['description'] : '-');
        $lines[] = '- Industry tags: ' . $this->joinList($snapshot['industry_tags'] ?? []);
        $lines[] = '- Visual keywords: ' . $this->joinList($snapshot['visual_keywords'] ?? []);
        $lines[] = '- Color system: ' . $this->normalizer()->encodeJsonField($snapshot['color_system'] ?? []);
        $lines[] = '- Layout patterns: ' . $this->joinList($snapshot['layout_patterns'] ?? []);
        $lines[] = '- Image strategy: ' . $this->joinList($snapshot['image_strategy'] ?? []);
        $lines[] = '- CTA style: ' . ((string)($snapshot['cta_style'] ?? '') !== '' ? (string)$snapshot['cta_style'] : '-');
        $lines[] = '- Forbidden patterns: ' . $this->joinList($snapshot['forbidden_patterns'] ?? []);
        $lines[] = '- Block design rules: ' . $this->normalizer()->encodeJsonField($snapshot['block_rules'] ?? []);
        $lines[] = '- Style QA rules: ' . $this->joinList($snapshot['qa_rules'] ?? []);
        $lines[] = '- Stage-1 must output theme_design.art_direction derived from this style and the user brief, not a generic theme.';
        $lines[] = '- Stage-1 must derive theme_design.tone_of_voice and cta_tone from the selected style. If the style asks for conversion energy, block descriptions and button labels must be vivid target-locale marketing copy, not neutral filler.';
        $lines[] = '- Every page must include page_design_plan: page_identity, opening_banner_composition, color_layering, section_flow, interaction_notes, anti_monotony_rule.';
        $lines[] = '- Every block must include visual_signature: composition_pattern, spatial_rhythm, media_strategy, surface_treatment, interaction_pattern. CTA/download/support blocks must name the concrete button motion and description-copy hook they will use.';
        $lines[] = '- Page banners are free-form inside the page identity: home/about/contact/policy/game-list banners must not reuse one shell; explain how each opening banner fits that page.';
        $lines[] = '- Do not change or ignore the style to pass validation. If a block cannot satisfy the style with available assets, design an honest CSS-based visual treatment from the style instead of using placeholders.';

        return $lines;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function buildStageThreePromptAddon(array $scope): string
    {
        $snapshot = $this->resolveSnapshotFromScope($scope);
        if ($snapshot === []) {
            return "CTX_AI_STYLE:\n"
                . "- No vertical style snapshot is frozen for this session. Use the confirmed plan and general claude-design craft only; do not leak card-game/APK/neon styling into unrelated sites.\n";
        }

        $lines = [
            'CTX_AI_STYLE (frozen session snapshot; hard visual contract for this block):',
            '- code=' . (string)$snapshot['code'] . '; name=' . (string)$snapshot['name'] . '; version=' . (string)$snapshot['version'] . '; hash=' . (string)$snapshot['hash'],
            '- Apply this style through the current block identity, not by copying one reusable shell.',
            '- Page/block identity comes from CTX_FROZEN_TASK and visual_signature; do not infer banner/contact/feature roles by class-name regex or old templates.',
            '- Visual keywords: ' . $this->joinList($snapshot['visual_keywords'] ?? []),
            '- Color system: ' . $this->normalizer()->encodeJsonField($snapshot['color_system'] ?? []),
            '- Layout patterns: ' . $this->joinList($snapshot['layout_patterns'] ?? []),
            '- Image strategy: ' . $this->joinList($snapshot['image_strategy'] ?? []),
            '- CTA style: ' . ((string)($snapshot['cta_style'] ?? '') !== '' ? (string)$snapshot['cta_style'] : '-'),
            '- Forbidden patterns: ' . $this->joinList($snapshot['forbidden_patterns'] ?? []),
            '- Block rules: ' . $this->normalizer()->encodeJsonField($snapshot['block_rules'] ?? []),
            '- Style QA: ' . $this->joinList($snapshot['qa_rules'] ?? []),
            '- Each generated block must show a different composition/density/focal device when adjacent blocks share the same page. Shared style is allowed; repeated shells are not.',
            '- If the style asks for eye-catching CTA/copy, write button labels and adjacent descriptions as compact target-locale campaign copy: concrete benefit, urgency, trust/reward cue, and one clear action. Do not output bland labels such as learn more/details unless the block identity requires them.',
            '- If the style asks for CSS motion, implement it inside the block with scoped selectors: CTA shine/pulse/halo, hover lift, active press, card/chip drift, review rail movement, or similar. Include prefers-reduced-motion fallback and keep text readable without motion.',
            '- If the style asks for image-text pairing, do not return text-only body grids. Pair copy with a verified image or a CSS-only visual companion such as a phone/app screen, card table, chip stack, jackpot meter, avatar rail, support-message stack, or document/rulebook surface.',
        ];
        $supplemental = \trim((string)($snapshot['supplemental_prompt'] ?? ''));
        if ($supplemental !== '') {
            $lines[] = '- Supplemental structured note: ' . $supplemental;
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function buildWorkspaceStyleState(array $scope): array
    {
        $snapshot = $this->resolveSnapshotFromScope($scope);
        return [
            'mode' => $this->normalizeMode((string)($scope['design_direction_mode'] ?? self::MODE_AUTO)),
            'code' => (string)($scope['design_direction_code'] ?? ($snapshot['code'] ?? '')),
            'snapshot' => $snapshot,
            'version' => (int)($scope['design_direction_version'] ?? ($snapshot['version'] ?? 0)),
            'hash' => (string)($scope['design_direction_hash'] ?? ($snapshot['hash'] ?? '')),
            'match_reason' => (string)($scope['design_direction_match_reason'] ?? ($snapshot['match_reason'] ?? '')),
            'locked' => $this->isTruthy($scope['design_direction_locked'] ?? 0),
            'label' => $snapshot !== [] ? (string)($snapshot['name'] ?? $snapshot['code'] ?? '') : '通用设计方向',
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function snapshotHash(array $snapshot): string
    {
        $copy = $snapshot;
        unset($copy['hash']);
        \ksort($copy);

        return \hash('sha256', (string)\json_encode($copy, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }

    public function normalizeMode(string $mode): string
    {
        $mode = \strtolower(\trim($mode));
        return \in_array($mode, [self::MODE_AUTO, self::MODE_MANUAL, self::MODE_NONE], true)
            ? $mode
            : self::MODE_AUTO;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildScopePatchFromSnapshot(string $mode, array $snapshot, string $reason, bool $lock): array
    {
        $code = (string)($snapshot['code'] ?? '');
        return [
            'design_direction_mode' => $this->normalizeMode($mode),
            'design_direction_code' => $code,
            'design_direction_custom_id' => (int)($snapshot['id'] ?? 0),
            'design_direction' => [
                'code' => $code,
                'name' => (string)($snapshot['name'] ?? ''),
                'version' => (int)($snapshot['version'] ?? 0),
                'hash' => (string)($snapshot['hash'] ?? ''),
                'match_reason' => $reason,
            ],
            'design_direction_snapshot' => $snapshot,
            'design_direction_version' => (int)($snapshot['version'] ?? 0),
            'design_direction_hash' => (string)($snapshot['hash'] ?? ''),
            'design_direction_match_reason' => $reason,
            'design_direction_locked' => $lock ? 1 : 0,
        ];
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public function normalizeSnapshot(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $code = \trim((string)($raw['code'] ?? ''));
        if ($code === '') {
            return [];
        }
        $snapshot = $this->buildSnapshot($raw, (string)($raw['match_reason'] ?? ''));
        if (isset($raw['hash']) && \is_string($raw['hash']) && \trim($raw['hash']) !== '') {
            $snapshot['hash'] = \trim($raw['hash']);
        }

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resolveSnapshotFromScope(array $scope): array
    {
        $candidates = [
            $scope['design_direction_snapshot'] ?? null,
            $scope['plan_workbench']['confirmed']['contract_context']['design_direction_snapshot'] ?? null,
            $scope['plan_workbench']['contract_context']['design_direction_snapshot'] ?? null,
            $scope['stage1_contract']['contract_context']['design_direction_snapshot'] ?? null,
            $scope['contract_context']['design_direction_snapshot'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $snapshot = $this->normalizeSnapshot($candidate);
            if ($snapshot !== []) {
                return $snapshot;
            }
        }

        return [];
    }

    private function joinList(mixed $items): string
    {
        $list = $this->normalizer()->normalizeStringList($items);
        return $list === [] ? '-' : \implode('; ', \array_slice($list, 0, 18));
    }

    private function isTruthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (\is_string($value)) {
            return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logSkillStyleTrace(string $event, array $context = []): void
    {
        SkillStyleTrace::log($event, $context);
    }

    private function registry(): StyleRegistry
    {
        return $this->registry ?? ObjectManager::getInstance(StyleRegistry::class);
    }

    private function repository(): StyleRepository
    {
        return $this->repository ?? ObjectManager::getInstance(StyleRepository::class);
    }

    private function adapterResolver(): AdapterStyleResolver
    {
        return $this->adapterResolver ?? ObjectManager::getInstance(AdapterStyleResolver::class);
    }

    private function normalizer(): StyleNormalizer
    {
        return $this->normalizer ?? new StyleNormalizer();
    }

    private function adapterStyleRepository(): AdapterStyleRepository
    {
        return $this->adapterStyleRepository ?? ObjectManager::getInstance(AdapterStyleRepository::class);
    }
}
