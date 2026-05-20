<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\DesignDirection;

use GuoLaiRen\PageBuilder\Model\AiSiteDesignDirection;
use Weline\Framework\Manager\ObjectManager;

final class DesignDirectionService
{
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';
    public const MODE_NONE = 'none';
    public const BUILTIN_CARD_GAME_CODE = 'india-card-game-apk-dark-neon';

    /** @var list<string> */
    private const JSON_FIELDS = [
        'industry_tags',
        'match_keywords',
        'visual_keywords',
        'color_system',
        'layout_patterns',
        'image_strategy',
        'forbidden_patterns',
        'block_rules',
        'qa_rules',
        'example_refs',
    ];

    public function __construct(
        private readonly ?AiSiteDesignDirection $directionModel = null
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listDirections(int $adminId, bool $includeDisabled = true): array
    {
        $items = $this->builtinDirections();
        foreach ($this->listCustomDirections($adminId, $includeDisabled) as $code => $direction) {
            $items[$code] = $direction;
        }
        \ksort($items);

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDirection(string $code, int $adminId): ?array
    {
        $code = $this->normalizeCode($code, false);
        if ($code === '') {
            return null;
        }
        $custom = $this->findCustomDirectionArray($code, $adminId);
        if ($custom !== null) {
            return $custom;
        }
        $builtins = $this->builtinDirections();

        return $builtins[$code] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveCustom(array $data, int $adminId): array
    {
        if ($adminId <= 0) {
            throw new \InvalidArgumentException('Admin user is required.');
        }
        $code = $this->normalizeCode((string)($data['code'] ?? ''), true);
        if (isset($this->builtinDirections()[$code])) {
            throw new \InvalidArgumentException('Builtin design directions are read-only. Clone it before editing.');
        }

        $name = \trim((string)($data['name'] ?? $code));
        if ($name === '') {
            throw new \InvalidArgumentException('Design direction name is required.');
        }
        $status = \trim((string)($data['status'] ?? AiSiteDesignDirection::STATUS_ACTIVE));
        if (!\in_array($status, [AiSiteDesignDirection::STATUS_ACTIVE, AiSiteDesignDirection::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('Design direction status must be active or disabled.');
        }

        $normalized = $this->normalizeDirectionPayload($data);
        $this->assertStructuredDirectionPayload($normalized);

        $existing = $this->findCustomDirectionModel($code, $adminId);
        $direction = $existing ?? $this->createModel();
        $now = \date('Y-m-d H:i:s');
        if ($direction->getId() <= 0) {
            $direction->setData(AiSiteDesignDirection::schema_fields_CREATED_AT, $now);
            $direction->setData(AiSiteDesignDirection::schema_fields_VERSION, 1);
        } else {
            $direction->setData(
                AiSiteDesignDirection::schema_fields_VERSION,
                \max(1, (int)$direction->getData(AiSiteDesignDirection::schema_fields_VERSION)) + 1
            );
        }

        $direction->setData(AiSiteDesignDirection::schema_fields_ADMIN_USER_ID, $adminId);
        $direction->setData(AiSiteDesignDirection::schema_fields_CODE, $code);
        $direction->setData(AiSiteDesignDirection::schema_fields_NAME, $name);
        $direction->setData(AiSiteDesignDirection::schema_fields_DESCRIPTION, \trim((string)($data['description'] ?? '')));
        $direction->setData(AiSiteDesignDirection::schema_fields_SOURCE_TYPE, AiSiteDesignDirection::SOURCE_CUSTOM);
        foreach (self::JSON_FIELDS as $field) {
            $direction->setData($field, $this->encodeJsonField($normalized[$field] ?? []));
        }
        $direction->setData(AiSiteDesignDirection::schema_fields_CTA_STYLE, \trim((string)($data['cta_style'] ?? '')));
        $direction->setData(AiSiteDesignDirection::schema_fields_SUPPLEMENTAL_PROMPT, \trim((string)($data['supplemental_prompt'] ?? '')));
        $direction->setData(AiSiteDesignDirection::schema_fields_STATUS, $status);
        $direction->setData(AiSiteDesignDirection::schema_fields_UPDATED_AT, $now);
        $direction->save();

        return $this->findCustomDirectionArray($code, $adminId) ?? $this->modelToArray($direction);
    }

    /**
     * @return array<string, mixed>
     */
    public function disableCustom(string $code, int $adminId): array
    {
        $code = $this->normalizeCode($code, true);
        $direction = $this->findCustomDirectionModel($code, $adminId);
        if (!$direction) {
            if (isset($this->builtinDirections()[$code])) {
                throw new \InvalidArgumentException('Builtin design directions are read-only and cannot be disabled.');
            }
            throw new \InvalidArgumentException('Design direction not found.');
        }

        $direction->setData(AiSiteDesignDirection::schema_fields_STATUS, AiSiteDesignDirection::STATUS_DISABLED);
        $direction->setData(AiSiteDesignDirection::schema_fields_UPDATED_AT, \date('Y-m-d H:i:s'));
        $direction->save();

        return $this->findCustomDirectionArray($code, $adminId) ?? $this->modelToArray($direction);
    }

    /**
     * @return array<string, mixed>
     */
    public function cloneBuiltin(string $code, int $adminId): array
    {
        $source = $this->getDirection($code, $adminId);
        if (!$source || (string)($source['source_type'] ?? '') !== AiSiteDesignDirection::SOURCE_BUILTIN) {
            throw new \InvalidArgumentException('Only builtin design directions can be cloned by this endpoint.');
        }

        $baseCode = $this->normalizeCode((string)$source['code'] . '-custom', true);
        $candidate = $baseCode;
        $suffix = 2;
        while ($this->findCustomDirectionArray($candidate, $adminId) !== null || isset($this->builtinDirections()[$candidate])) {
            $candidate = $this->normalizeCode($baseCode . '-' . $suffix, true);
            $suffix++;
        }

        $payload = $source;
        $payload['code'] = $candidate;
        $payload['name'] = (string)($source['name'] ?? $candidate) . ' Copy';
        $payload['source_type'] = AiSiteDesignDirection::SOURCE_CUSTOM;
        $payload['status'] = AiSiteDesignDirection::STATUS_ACTIVE;

        return $this->saveCustom($payload, $adminId);
    }

    /**
     * @return array{matched:bool,item:array<string,mixed>|null,score:int,matched_keywords:list<string>,reason:string}
     */
    public function matchDirection(string $title, string $brief, int $adminId): array
    {
        $haystack = $this->lowerForMatch($title . "\n" . $brief);
        if (\trim($haystack) === '') {
            return [
                'matched' => false,
                'item' => null,
                'score' => 0,
                'matched_keywords' => [],
                'reason' => '标题和一句话描述为空，未自动套用垂直方向。',
            ];
        }

        $best = null;
        $bestScore = 0;
        $bestKeywords = [];
        foreach ($this->listDirections($adminId, false) as $direction) {
            $keywords = $this->normalizeStringList($direction['match_keywords'] ?? []);
            $score = 0;
            $hits = [];
            foreach ($keywords as $keyword) {
                $needle = $this->lowerForMatch($keyword);
                if ($needle === '' || !\str_contains($haystack, $needle)) {
                    continue;
                }
                $hits[] = $keyword;
                $score += \max(1, \min(6, (int)\ceil(\strlen($needle) / 4)));
            }
            if ($score > $bestScore) {
                $best = $direction;
                $bestScore = $score;
                $bestKeywords = $hits;
            }
        }

        if (!\is_array($best) || $bestScore <= 0) {
            return [
                'matched' => false,
                'item' => null,
                'score' => 0,
                'matched_keywords' => [],
                'reason' => '未命中垂直设计方向，使用通用设计方向。',
            ];
        }

        return [
            'matched' => true,
            'item' => $best,
            'score' => $bestScore,
            'matched_keywords' => $bestKeywords,
            'reason' => '自动推荐：命中关键词 ' . \implode('、', \array_slice($bestKeywords, 0, 6)) . '。',
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resolveSelectionForScope(array $scope, int $adminId, bool $lock = false): array
    {
        $mode = $this->normalizeMode((string)($scope['design_direction_mode'] ?? self::MODE_AUTO));
        $existingSnapshot = $this->normalizeSnapshot($scope['design_direction_snapshot'] ?? []);
        $existingLocked = $this->isTruthy($scope['design_direction_locked'] ?? 0);
        if ($existingLocked && $existingSnapshot !== [] && !$lock) {
            return $this->buildScopePatchFromSnapshot(
                $mode,
                $existingSnapshot,
                (string)($scope['design_direction_match_reason'] ?? '已冻结方向。'),
                true
            );
        }

        if ($mode === self::MODE_NONE) {
            return [
                'design_direction_mode' => self::MODE_NONE,
                'design_direction_code' => '',
                'design_direction_custom_id' => 0,
                'design_direction' => [],
                'design_direction_snapshot' => [],
                'design_direction_version' => 0,
                'design_direction_hash' => '',
                'design_direction_match_reason' => '未选择方向，使用通用设计方向。',
                'design_direction_locked' => $lock ? 1 : 0,
            ];
        }

        $selectedCode = \trim((string)($scope['design_direction_code'] ?? ''));
        $matchReason = '';
        $direction = null;
        if ($mode === self::MODE_MANUAL) {
            $direction = $this->getDirection($selectedCode, $adminId);
            if (!$direction) {
                throw new \InvalidArgumentException('Manual design direction does not exist: ' . $selectedCode);
            }
            $matchReason = '手动选择：' . (string)($direction['name'] ?? $selectedCode);
        } else {
            $match = $this->matchDirection(
                (string)($scope['site_title'] ?? ''),
                (string)($scope['brief_description'] ?? $scope['user_description'] ?? ''),
                $adminId
            );
            $direction = \is_array($match['item'] ?? null) ? $match['item'] : null;
            $matchReason = (string)($match['reason'] ?? '');
        }

        if (!$direction) {
            return [
                'design_direction_mode' => $mode,
                'design_direction_code' => '',
                'design_direction_custom_id' => 0,
                'design_direction' => [],
                'design_direction_snapshot' => [],
                'design_direction_version' => 0,
                'design_direction_hash' => '',
                'design_direction_match_reason' => $matchReason !== '' ? $matchReason : '未命中垂直设计方向，使用通用设计方向。',
                'design_direction_locked' => $lock ? 1 : 0,
            ];
        }

        $snapshot = $this->buildSnapshot($direction, $matchReason);

        return $this->buildScopePatchFromSnapshot($mode, $snapshot, $matchReason, $lock);
    }

    /**
     * @param array<string, mixed> $direction
     * @return array<string, mixed>
     */
    public function buildSnapshot(array $direction, string $reason = ''): array
    {
        $snapshot = [
            'code' => (string)($direction['code'] ?? ''),
            'name' => (string)($direction['name'] ?? ''),
            'description' => (string)($direction['description'] ?? ''),
            'source_type' => (string)($direction['source_type'] ?? ''),
            'version' => \max(1, (int)($direction['version'] ?? 1)),
            'industry_tags' => $this->normalizeStringList($direction['industry_tags'] ?? []),
            'match_keywords' => $this->normalizeStringList($direction['match_keywords'] ?? []),
            'visual_keywords' => $this->normalizeStringList($direction['visual_keywords'] ?? []),
            'color_system' => $this->normalizeFlexibleStructuredField($direction['color_system'] ?? []),
            'layout_patterns' => $this->normalizeStringList($direction['layout_patterns'] ?? []),
            'image_strategy' => $this->normalizeStringList($direction['image_strategy'] ?? []),
            'cta_style' => \trim((string)($direction['cta_style'] ?? '')),
            'forbidden_patterns' => $this->normalizeStringList($direction['forbidden_patterns'] ?? []),
            'block_rules' => $this->normalizeFlexibleStructuredField($direction['block_rules'] ?? []),
            'qa_rules' => $this->normalizeStringList($direction['qa_rules'] ?? []),
            'example_refs' => $this->normalizeFlexibleStructuredField($direction['example_refs'] ?? []),
            'supplemental_prompt' => \trim((string)($direction['supplemental_prompt'] ?? '')),
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
            '- 【系统提示词】defines schema, locale, queue, and PageBuilder hard contracts.',
            '- 【通用提示词】contains shared aesthetic craft guidance such as claude-design; it must not override the user brief or the frozen vertical design direction.',
            '',
            'DESIGN DIRECTION CONTRACT:',
        ];
        if ($snapshot === []) {
            $lines[] = '- No vertical design direction is selected. Use the user brief, theme, and general claude-design craft only; do not import game/APK/card/neon rules unless the user brief explicitly asks for them.';
            $lines[] = '- Still output site-level theme_design.art_direction, page-level page_design_plan, and block-level visual_signature so Stage-3 can execute each block without guessing.';
            return $lines;
        }

        $lines[] = '- Frozen direction code: ' . (string)$snapshot['code'] . '; name: ' . (string)$snapshot['name'] . '; version: ' . (string)$snapshot['version'] . '; hash: ' . (string)$snapshot['hash'];
        $lines[] = '- Match reason: ' . ((string)($snapshot['match_reason'] ?? '') !== '' ? (string)$snapshot['match_reason'] : '-');
        $lines[] = '- Description: ' . ((string)$snapshot['description'] !== '' ? (string)$snapshot['description'] : '-');
        $lines[] = '- Industry tags: ' . $this->joinList($snapshot['industry_tags'] ?? []);
        $lines[] = '- Visual keywords: ' . $this->joinList($snapshot['visual_keywords'] ?? []);
        $lines[] = '- Color system: ' . $this->encodeJsonField($snapshot['color_system'] ?? []);
        $lines[] = '- Layout patterns: ' . $this->joinList($snapshot['layout_patterns'] ?? []);
        $lines[] = '- Image strategy: ' . $this->joinList($snapshot['image_strategy'] ?? []);
        $lines[] = '- CTA style: ' . ((string)($snapshot['cta_style'] ?? '') !== '' ? (string)$snapshot['cta_style'] : '-');
        $lines[] = '- Forbidden patterns: ' . $this->joinList($snapshot['forbidden_patterns'] ?? []);
        $lines[] = '- Block design rules: ' . $this->encodeJsonField($snapshot['block_rules'] ?? []);
        $lines[] = '- Direction QA rules: ' . $this->joinList($snapshot['qa_rules'] ?? []);
        $lines[] = '- Stage-1 must output theme_design.art_direction derived from this direction and the user brief, not a generic theme.';
        $lines[] = '- Every page must include page_design_plan: page_identity, opening_banner_composition, color_layering, section_flow, interaction_notes, anti_monotony_rule.';
        $lines[] = '- Every block must include visual_signature: composition_pattern, spatial_rhythm, media_strategy, surface_treatment, interaction_pattern.';
        $lines[] = '- Page banners are free-form inside the page identity: home/about/contact/policy/game-list banners must not reuse one shell; explain how each opening banner fits that page.';
        $lines[] = '- Do not change or ignore the direction to pass validation. If a block cannot satisfy the direction with available assets, design an honest CSS-based visual treatment from the direction instead of using placeholders.';

        return $lines;
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function buildStageThreePromptAddon(array $scope): string
    {
        $snapshot = $this->resolveSnapshotFromScope($scope);
        if ($snapshot === []) {
            return "CTX_DESIGN_DIRECTION:\n"
                . "- No vertical design direction snapshot is frozen for this session. Use the confirmed plan and general claude-design craft only; do not leak card-game/APK/neon styling into unrelated sites.\n";
        }

        $lines = [
            'CTX_DESIGN_DIRECTION (frozen session snapshot; hard visual contract for this block):',
            '- code=' . (string)$snapshot['code'] . '; name=' . (string)$snapshot['name'] . '; version=' . (string)$snapshot['version'] . '; hash=' . (string)$snapshot['hash'],
            '- Apply this direction through the current block identity, not by copying one reusable shell.',
            '- Page/block identity comes from CTX_FROZEN_TASK and visual_signature; do not infer banner/contact/feature roles by class-name regex or old templates.',
            '- Visual keywords: ' . $this->joinList($snapshot['visual_keywords'] ?? []),
            '- Color system: ' . $this->encodeJsonField($snapshot['color_system'] ?? []),
            '- Layout patterns: ' . $this->joinList($snapshot['layout_patterns'] ?? []),
            '- Image strategy: ' . $this->joinList($snapshot['image_strategy'] ?? []),
            '- CTA style: ' . ((string)($snapshot['cta_style'] ?? '') !== '' ? (string)$snapshot['cta_style'] : '-'),
            '- Forbidden patterns: ' . $this->joinList($snapshot['forbidden_patterns'] ?? []),
            '- Block rules: ' . $this->encodeJsonField($snapshot['block_rules'] ?? []),
            '- Direction QA: ' . $this->joinList($snapshot['qa_rules'] ?? []),
            '- Each generated block must show a different composition/density/focal device when adjacent blocks share the same page. Shared direction is allowed; repeated shells are not.',
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
    public function buildWorkspaceDirectionState(array $scope): array
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
     * @return array<string, array<string, mixed>>
     */
    private function builtinDirections(): array
    {
        return [
            self::BUILTIN_CARD_GAME_CODE => [
                'id' => 0,
                'admin_user_id' => 0,
                'code' => self::BUILTIN_CARD_GAME_CODE,
                'name' => '印度棋牌 APK 暗色霓虹发行页',
                'description' => '用于印度棋牌、Teen Patti、Rummy、Andar Bahar 等 APK 下载站。强调暗色游戏发行页、中心强视觉、金橙 CTA、卡牌/筹码/奖金氛围、深棕/墨蓝/霓虹分区、横向滚动玩家评价，以及游戏官网式 FAQ/评论/游戏列表/博客/法务页面。',
                'source_type' => AiSiteDesignDirection::SOURCE_BUILTIN,
                'industry_tags' => ['印度棋牌', 'APK 下载', '棋牌游戏', '真钱/奖励游戏', '移动应用发行页'],
                'match_keywords' => [
                    '印度棋牌',
                    '印度棋牌官网',
                    '印度棋牌app',
                    '棋牌app',
                    '棋牌游戏',
                    '下载印度棋牌',
                    'APK 下载',
                    'APK',
                    'Teen Patti',
                    'TeenPatti',
                    'Rummy',
                    'Andar Bahar',
                    'Casino',
                    'Poker',
                    'Ludo',
                    'BharatPlay',
                    'Bonus',
                    'App Download',
                    'download apk',
                    'teen patti master',
                    'teen patti apk',
                    'teen patti master apk',
                    'teen patti download',
                    'real cash rummy',
                    'real money game',
                    'UPI withdrawal',
                    'withdrawal',
                    'chaal',
                    'boot value',
                    'blind',
                    'seen',
                    'sideshow',
                    'muflis',
                    'AK47',
                    'private teen patti',
                    'classic teen patti',
                    'player reviews',
                    'FAQ',
                    'privacy policy',
                    'terms',
                    'cookies',
                ],
                'visual_keywords' => [
                    'dark gaming launch page',
                    'floating black rounded navigation bar with app icon and compact download CTA',
                    'center-stage app poster or game lobby visual',
                    'large localized headline with editorial badge above it',
                    'gold/orange conversion CTA',
                    'cards, chips, rupee, jackpot and bonus atmosphere',
                    'deep brown and ink-blue section bands',
                    'neon teal/purple edge light used sparingly',
                    'premium mobile-game campaign density',
                    'side command cards around a hero poster',
                    'floating download pill fixed near the bottom edge',
                    'horizontal player review rail with edge fade, avatars, location, and rating',
                    'FAQ accordion rows with numbered capsules and plus/minus affordance',
                    'legal/policy pages as centered dark document panels with gold headings',
                    'blog/journal cards with recent-post sidebar and dark editorial surfaces',
                    'subtle animated halos, card-token floats, glow rings, and particle dots',
                ],
                'color_system' => [
                    'base' => '#120807 deep casino brown / near-black',
                    'page_black' => '#08090f almost-black app shell',
                    'section_dark' => '#041923 ink blue-green',
                    'section_brown' => '#261108 warm casino brown band',
                    'surface' => '#0b1f28 translucent glass card',
                    'surface_black' => '#050505 high-contrast testimonial/legal card',
                    'accent_gold' => '#ffbf42 jackpot gold',
                    'cta_orange' => '#ff7a2f warm download CTA',
                    'neon_teal' => '#00d5c5 thin active line and FAQ focus',
                    'purple_header' => '#24115f policy/legal header accent used only on document pages',
                    'text' => '#fff4dc warm high-contrast text',
                ],
                'layout_patterns' => [
                    'Home hero: full-bleed dark stage, huge Hindi/target-locale headline, dominant central app/game visual, compact side benefit cards, one primary APK download CTA.',
                    'Hero support cards: use step/signal cards around the central poster for game actions such as pick variant, set boot, play blind/chaal, low-lag dealing, pot clarity, and fast rejoin.',
                    'Benefits: angled or staggered glass cards over ink-blue band, each with game/app proof rather than generic service claims.',
                    'KPI proof band: compact black stat cards may show active players, rating, uptime, or loading speed when those facts exist in the plan.',
                    'Games: editorial game grid with image-led cards, jackpots/cards/chips, varied card sizes and dark surfaces.',
                    'Reviews: use a horizontal scrolling or carousel-like player review rail when the section has 4+ reviews; include edge fades, black cards, neon-teal left border, avatar/initial, location, rating, and gaming-specific comment.',
                    'FAQ: dark accordion with gold/teal dividers and questions about APK install, safety, bonus, withdrawal, gameplay.',
                    'Blog/news: dark octane-journal layout with large story card, smaller stacked story cards, recent posts sidebar, dates/authors, and strategy/update language.',
                    'About: mission/proof page should use a different banner from home: orbit badge, KPI chips, story panel, value cards, and team/operations cards rather than another app-poster hero.',
                    'Contact/CTA: support/download identity, not a plain corporate contact form; separate help, install, and bonus actions.',
                    'Contact: use support-channel cards, service-hour chips, social/contact chips and a dark form panel; contact facts must come from source facts, never invented.',
                    'Legal/policy/cookie pages: use a centered document-card composition with purple/gold header, numbered sections, readable paragraphs, and retained dark gaming shell.',
                    'Persistent conversion: a small floating APK download pill may appear near the bottom-right when the page is long, provided it does not block content on mobile.',
                ],
                'image_strategy' => [
                    'Prefer verified app poster, lobby screenshot, card table, chips, rupee or game tile assets when available.',
                    'If no verified image exists, create CSS-only gaming visuals from cards, chips, jackpot panels, glow rings, and phone/poster frames; never render broken images or generic placeholders.',
                    'The main visual should reveal game/app state, not a blurred stock atmosphere.',
                    'Each page opening visual must fit page identity: about uses mission/proof stage, contact uses support/download channel stage, policy uses calm rulebook/support visual.',
                    'Player-review rails may use avatar photos or safe initials; do not invent real personal identities beyond the plan.',
                    'Blog cards should not show broken thumbnails; if no article image exists, use dark card-table/card-token art direction instead of an empty image box.',
                ],
                'cta_style' => 'Primary CTA is a compact gold/orange APK download button with heavy weight, glow/press affordance, and direct download/install wording when the brief permits it. Long pages may use a fixed bottom-right download pill with icon, two-line copy, and warm glow. Secondary actions may be dark outlined chips, read-more links, or support links.',
                'forbidden_patterns' => [
                    'cream/light corporate layout as the dominant page system',
                    'same banner shell reused across home/about/contact/policy pages',
                    'generic three green-outline cards repeated across sections',
                    'static corporate testimonial grid when the plan calls for player reviews; prefer game-specific review rail or staggered cards',
                    'SaaS feature-grid look, lawyer/consulting/corporate trust-block look, or coffee/editorial calm look',
                    'fake phone/email/WhatsApp values unless provided by source facts',
                    'placeholder image boxes, fake asset URLs, or broken img tags',
                    'blog cards with empty/broken thumbnails',
                    'contact forms or legal pages that look disconnected from the gaming visual system',
                    'emoji-heavy headings or generic AI gradient orbs',
                ],
                'block_rules' => [
                    'opening_banner' => 'Use page identity first. Home can be central game poster; about should show mission/fair-play proof; contact should show support/download channels; FAQ/policy should feel like game rules/help, not a copied hero.',
                    'download_cta' => 'Mention APK/app download and bonus only when the user brief asks for it. CTA must be visually stronger than chips/cards around it.',
                    'benefits' => 'Benefits must include app safety, quick install, game variety, bonus/reward, fair play or support facts derived from the plan.',
                    'games' => 'Use game names and visual cards/tiles, not generic service categories.',
                    'faq' => 'Questions should cover APK install, safety, bonus, gameplay and support; use accordion-like rows with strong dark contrast.',
                    'reviews' => 'Player comments must sound like gaming/app experience, not generic company testimonials. For 4+ reviews, prefer a horizontal scrolling rail or carousel composition with edge fade, avatar/initial, city/region, star/rating, and one concrete app/game detail. Use CSS-only motion where possible and keep all cards readable without relying on motion.',
                    'blog' => 'Use an Octane Journal/newsroom feel: strategy tips, patch/update stories, recent-post sidebar, dates/authors, dark surfaces and gold/teal links. Avoid a generic corporate blog grid.',
                    'about' => 'Use brand story, KPI proof, orbit/ring badge, value cards and operational credibility. The about page must not reuse the home poster hero.',
                    'contact' => 'Use support-channel cards, hours, help categories, and form panels. Do not invent phone/email/social values; if missing, emphasize in-app support/download help instead.',
                    'legal_policy' => 'Terms/privacy/cookie pages should look like a premium rulebook: centered document card, gold numbered sections, purple or ink header, high contrast text, and footer/legal age disclaimer when relevant.',
                    'floating_download' => 'A floating APK download pill can reinforce conversion on long pages. It must be compact, bottom-edge aligned, non-overlapping on mobile, and secondary to the page content.',
                ],
                'qa_rules' => [
                    '首屏必须有暗色游戏质感、强视觉焦点、直接下载/安装 CTA。',
                    '页面之间的 opening banner 构图必须不同，并能看出页面身份。',
                    '至少核心页面要出现 APK/app、游戏、奖励/筹码/卡牌、FAQ/评论/游戏列表等语义。',
                    '评论区如果存在多条玩家评价，优先检查是否具备横向滚动/轮播感、边缘淡出、头像/评分/地区和游戏体验细节。',
                    '长页面允许悬浮下载按钮，但不得遮挡正文、FAQ、表单或移动端底部内容。',
                    '博客、关于、联系、条款、隐私、Cookie 页面必须继承棋牌发行站的暗色游戏外壳，同时拥有各自页面身份。',
                    '不得把棋牌方向污染到非棋牌会话。',
                    '不得用占位图、假图片 URL 或重复 shell 通过验收。',
                ],
                'example_refs' => [
                    [
                        'type' => 'reference',
                        'label' => 'Dark Indian card-game APK landing page reference',
                        'note' => '用户提供的暗色霓虹、中心海报、金色下载 CTA、分区卡片方向。',
                    ],
                    [
                        'type' => 'url',
                        'label' => 'Teen Patti APK reference site',
                        'url' => 'https://www.teenpatti-apk.com/',
                        'note' => '用于提炼方向词：悬浮黑色导航、中心海报、步骤/信号小卡、横向玩家评价、FAQ 手风琴、博客资讯、关于页 KPI、联系支持卡、法务文档卡和固定下载胶囊。',
                    ],
                ],
                'supplemental_prompt' => 'This direction is not a hardcoded page. Treat it as a vertical art-direction contract and recombine it with the actual user brief, selected pages, theme, assets, and block identity. Reference-site details are vocabulary and composition guidance only; never copy exact content, fake contact data, or a fixed page shell.',
                'version' => 2,
                'status' => AiSiteDesignDirection::STATUS_ACTIVE,
                'readonly' => true,
                'selectable' => true,
                'exists' => true,
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function listCustomDirections(int $adminId, bool $includeDisabled): array
    {
        if ($adminId <= 0) {
            return [];
        }
        try {
            $query = $this->createModel()->clearData()->clearQuery()
                ->where(AiSiteDesignDirection::schema_fields_ADMIN_USER_ID, $adminId);
            if (!$includeDisabled) {
                $query->where(AiSiteDesignDirection::schema_fields_STATUS, AiSiteDesignDirection::STATUS_ACTIVE);
            }
            $rows = $query->order(AiSiteDesignDirection::schema_fields_CODE, 'ASC')->select()->fetchArray();
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($rows)) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $item = $this->rowToArray($row);
            $code = (string)($item['code'] ?? '');
            if ($code !== '') {
                $result[$code] = $item;
            }
        }

        return $result;
    }

    private function findCustomDirectionModel(string $code, int $adminId): ?AiSiteDesignDirection
    {
        $code = $this->normalizeCode($code, false);
        if ($code === '' || $adminId <= 0) {
            return null;
        }
        try {
            $direction = $this->createModel();
            $direction->clearData()->clearQuery()
                ->where(AiSiteDesignDirection::schema_fields_ADMIN_USER_ID, $adminId)
                ->where(AiSiteDesignDirection::schema_fields_CODE, $code)
                ->find()
                ->fetch();
        } catch (\Throwable) {
            return null;
        }

        return $direction->getId() > 0 ? $direction : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findCustomDirectionArray(string $code, int $adminId): ?array
    {
        $direction = $this->findCustomDirectionModel($code, $adminId);
        return $direction ? $this->modelToArray($direction) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowToArray(array $row): array
    {
        $item = [
            'id' => (int)($row[AiSiteDesignDirection::schema_fields_ID] ?? 0),
            'admin_user_id' => (int)($row[AiSiteDesignDirection::schema_fields_ADMIN_USER_ID] ?? 0),
            'code' => (string)($row[AiSiteDesignDirection::schema_fields_CODE] ?? ''),
            'name' => (string)($row[AiSiteDesignDirection::schema_fields_NAME] ?? ''),
            'description' => (string)($row[AiSiteDesignDirection::schema_fields_DESCRIPTION] ?? ''),
            'source_type' => (string)($row[AiSiteDesignDirection::schema_fields_SOURCE_TYPE] ?? AiSiteDesignDirection::SOURCE_CUSTOM),
            'cta_style' => (string)($row[AiSiteDesignDirection::schema_fields_CTA_STYLE] ?? ''),
            'supplemental_prompt' => (string)($row[AiSiteDesignDirection::schema_fields_SUPPLEMENTAL_PROMPT] ?? ''),
            'version' => (int)($row[AiSiteDesignDirection::schema_fields_VERSION] ?? 1),
            'status' => (string)($row[AiSiteDesignDirection::schema_fields_STATUS] ?? AiSiteDesignDirection::STATUS_ACTIVE),
            'created_at' => (string)($row[AiSiteDesignDirection::schema_fields_CREATED_AT] ?? ''),
            'updated_at' => (string)($row[AiSiteDesignDirection::schema_fields_UPDATED_AT] ?? ''),
            'readonly' => false,
            'selectable' => (string)($row[AiSiteDesignDirection::schema_fields_STATUS] ?? AiSiteDesignDirection::STATUS_ACTIVE) === AiSiteDesignDirection::STATUS_ACTIVE,
            'exists' => true,
        ];
        foreach (self::JSON_FIELDS as $field) {
            $item[$field] = $this->decodeJsonField($row[$field] ?? null);
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function modelToArray(AiSiteDesignDirection $direction): array
    {
        return $this->rowToArray([
            AiSiteDesignDirection::schema_fields_ID => $direction->getData(AiSiteDesignDirection::schema_fields_ID),
            AiSiteDesignDirection::schema_fields_ADMIN_USER_ID => $direction->getData(AiSiteDesignDirection::schema_fields_ADMIN_USER_ID),
            AiSiteDesignDirection::schema_fields_CODE => $direction->getData(AiSiteDesignDirection::schema_fields_CODE),
            AiSiteDesignDirection::schema_fields_NAME => $direction->getData(AiSiteDesignDirection::schema_fields_NAME),
            AiSiteDesignDirection::schema_fields_DESCRIPTION => $direction->getData(AiSiteDesignDirection::schema_fields_DESCRIPTION),
            AiSiteDesignDirection::schema_fields_SOURCE_TYPE => $direction->getData(AiSiteDesignDirection::schema_fields_SOURCE_TYPE),
            AiSiteDesignDirection::schema_fields_CTA_STYLE => $direction->getData(AiSiteDesignDirection::schema_fields_CTA_STYLE),
            AiSiteDesignDirection::schema_fields_SUPPLEMENTAL_PROMPT => $direction->getData(AiSiteDesignDirection::schema_fields_SUPPLEMENTAL_PROMPT),
            AiSiteDesignDirection::schema_fields_VERSION => $direction->getData(AiSiteDesignDirection::schema_fields_VERSION),
            AiSiteDesignDirection::schema_fields_STATUS => $direction->getData(AiSiteDesignDirection::schema_fields_STATUS),
            AiSiteDesignDirection::schema_fields_CREATED_AT => $direction->getData(AiSiteDesignDirection::schema_fields_CREATED_AT),
            AiSiteDesignDirection::schema_fields_UPDATED_AT => $direction->getData(AiSiteDesignDirection::schema_fields_UPDATED_AT),
            AiSiteDesignDirection::schema_fields_INDUSTRY_TAGS => $direction->getData(AiSiteDesignDirection::schema_fields_INDUSTRY_TAGS),
            AiSiteDesignDirection::schema_fields_MATCH_KEYWORDS => $direction->getData(AiSiteDesignDirection::schema_fields_MATCH_KEYWORDS),
            AiSiteDesignDirection::schema_fields_VISUAL_KEYWORDS => $direction->getData(AiSiteDesignDirection::schema_fields_VISUAL_KEYWORDS),
            AiSiteDesignDirection::schema_fields_COLOR_SYSTEM => $direction->getData(AiSiteDesignDirection::schema_fields_COLOR_SYSTEM),
            AiSiteDesignDirection::schema_fields_LAYOUT_PATTERNS => $direction->getData(AiSiteDesignDirection::schema_fields_LAYOUT_PATTERNS),
            AiSiteDesignDirection::schema_fields_IMAGE_STRATEGY => $direction->getData(AiSiteDesignDirection::schema_fields_IMAGE_STRATEGY),
            AiSiteDesignDirection::schema_fields_FORBIDDEN_PATTERNS => $direction->getData(AiSiteDesignDirection::schema_fields_FORBIDDEN_PATTERNS),
            AiSiteDesignDirection::schema_fields_BLOCK_RULES => $direction->getData(AiSiteDesignDirection::schema_fields_BLOCK_RULES),
            AiSiteDesignDirection::schema_fields_QA_RULES => $direction->getData(AiSiteDesignDirection::schema_fields_QA_RULES),
            AiSiteDesignDirection::schema_fields_EXAMPLE_REFS => $direction->getData(AiSiteDesignDirection::schema_fields_EXAMPLE_REFS),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeDirectionPayload(array $data): array
    {
        $normalized = [];
        foreach (self::JSON_FIELDS as $field) {
            $value = $data[$field] ?? [];
            $normalized[$field] = \in_array($field, ['color_system', 'block_rules', 'example_refs'], true)
                ? $this->normalizeFlexibleStructuredField($value)
                : $this->normalizeStringList($value);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $normalized
     */
    private function assertStructuredDirectionPayload(array $normalized): void
    {
        $structuredNonEmpty = 0;
        foreach (['match_keywords', 'visual_keywords', 'layout_patterns', 'image_strategy', 'forbidden_patterns', 'block_rules', 'qa_rules'] as $field) {
            if ($this->structuredFieldCount($normalized[$field] ?? []) > 0) {
                $structuredNonEmpty++;
            }
        }
        if ($structuredNonEmpty < 2) {
            throw new \InvalidArgumentException('Custom design direction must use structured fields; supplemental notes alone are not enough.');
        }
    }

    private function structuredFieldCount(mixed $value): int
    {
        if (!\is_array($value)) {
            return 0;
        }
        $count = 0;
        foreach ($value as $item) {
            if (\is_array($item)) {
                $count += $this->structuredFieldCount($item);
            } elseif (\trim((string)$item) !== '') {
                $count++;
            }
        }

        return $count;
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
    private function normalizeSnapshot(mixed $raw): array
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
    private function resolveSnapshotFromScope(array $scope): array
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

    private function normalizeCode(string $code, bool $throwOnEmpty): string
    {
        $code = \strtolower(\trim($code));
        $code = (string)\preg_replace('/[^a-z0-9_-]+/', '-', $code);
        $code = \trim($code, '-_');
        if ($code === '') {
            if ($throwOnEmpty) {
                throw new \InvalidArgumentException('Design direction code is required.');
            }
            return '';
        }
        if (\strlen($code) > 96) {
            throw new \InvalidArgumentException('Design direction code cannot exceed 96 characters.');
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = $this->decodeJsonField($raw);
            if (\is_array($decoded) && $decoded !== []) {
                $raw = $decoded;
            } else {
                $raw = \preg_split('/[\r\n,;]+/u', $raw) ?: [];
            }
        }
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (\is_array($value)) {
                foreach ($value as $nested) {
                    $text = \trim(\is_scalar($nested) ? (string)$nested : \json_encode($nested, \JSON_UNESCAPED_UNICODE));
                    if ($text !== '' && !\in_array($text, $out, true)) {
                        $out[] = $text;
                    }
                }
                continue;
            }
            $text = \trim(\is_scalar($value) ? (string)$value : '');
            if ($text !== '' && !\in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @return array<string|int, mixed>
     */
    private function normalizeFlexibleStructuredField(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = $this->decodeJsonField($raw);
            if (\is_array($decoded)) {
                return $this->normalizeNestedStructuredArray($decoded);
            }
            $lines = $this->normalizeStringList($raw);
            return $lines;
        }
        if (!\is_array($raw)) {
            return [];
        }

        return $this->normalizeNestedStructuredArray($raw);
    }

    /**
     * @param array<string|int, mixed> $raw
     * @return array<string|int, mixed>
     */
    private function normalizeNestedStructuredArray(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (\is_array($value)) {
                $nested = $this->normalizeNestedStructuredArray($value);
                if ($nested !== []) {
                    $out[$key] = $nested;
                }
                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                $out[$key] = $text;
            }
        }

        return $out;
    }

    private function encodeJsonField(mixed $value): string
    {
        return (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    private function decodeJsonField(mixed $raw): mixed
    {
        if (\is_array($raw)) {
            return $raw;
        }
        $raw = \is_string($raw) ? \trim($raw) : '';
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    private function lowerForMatch(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if (\function_exists('mb_strtolower')) {
            return \mb_strtolower($value, 'UTF-8');
        }

        return \strtolower($value);
    }

    private function joinList(mixed $items): string
    {
        $list = $this->normalizeStringList($items);
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

    private function createModel(): AiSiteDesignDirection
    {
        return clone ($this->directionModel ?? ObjectManager::getInstance(AiSiteDesignDirection::class));
    }
}
