<?php

declare(strict_types=1);

/*
 * AI 建站技能注册表（Skill Registry）
 *
 * 让 AI 建站工作台的提示词具备显式“技能加载能力”：
 * 1) 把 app/code/GuoLaiRen/PageBuilder/skills/ 下的每个目录视为一个可加载技能；
 * 2) 解析其 SKILL.md frontmatter（name/description）作为提示词中的“能力声明”；
 * 3) 默认强制加载 claude-design 技能（设计纪律、反 AI-slop、内容真实性等）；
 * 4) 兼容已有的 prompt_guides/frontend-design 技能，作为 plan_json 任务规划的设计指引。
 *
 * 注：本服务只负责把技能描述、本地路径、硬约束转成提示词行；具体生成走老路径
 * （Stage1 在 buildAiPlanPrompt 注入；PlanJson 在 buildSkillRegistryPromptGuide 注入）。
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

use GuoLaiRen\PageBuilder\Service\AI\Skill\BuiltinSkillProvider;
use GuoLaiRen\PageBuilder\Service\AI\Skill\CustomSkillProvider;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillNormalizer;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillSelectionResolver;
use GuoLaiRen\PageBuilder\Service\AI\Skill\SkillSnapshotBuilder;
use Weline\Ai\Service\Skill\AdapterSkillResolver;
use Weline\Ai\Service\Skill\SkillRegistry as CoreSkillRegistry;
use Weline\Framework\Manager\ObjectManager;

final class AiSiteSkillRegistry
{
    public const SKILLS_RELATIVE_ROOT = BuiltinSkillProvider::SKILLS_RELATIVE_ROOT;

    public const FRONTEND_DESIGN_SKILL_LOCAL_PATH = 'app/code/GuoLaiRen/PageBuilder/Service/AI/prompt_guides/frontend-design/SKILL.md';

    public const FRONTEND_DESIGN_SKILL_SOURCE = 'https://github.com/anthropics/claude-code/blob/main/plugins/frontend-design/skills/frontend-design/SKILL.md';

    /**
     * 默认强制加载的技能 code（按顺序排在提示词前面，越靠前越优先）。
     * 老板要求：默认加载 claude-design。
     *
     * @var list<string>
     */
    private const DEFAULT_SKILL_CODES = ['claude-design'];

    private readonly SkillNormalizer $normalizer;
    private readonly BuiltinSkillProvider $builtinProvider;
    private readonly CustomSkillProvider $customProvider;
    private readonly SkillSelectionResolver $selectionResolver;
    private readonly SkillSnapshotBuilder $snapshotBuilder;
    private readonly ?CoreSkillRegistry $coreSkillRegistry;
    private readonly ?AdapterSkillResolver $adapterSkillResolver;

    public function __construct(
        ?SkillNormalizer $normalizer = null,
        ?BuiltinSkillProvider $builtinProvider = null,
        ?CustomSkillProvider $customProvider = null,
        ?SkillSelectionResolver $selectionResolver = null,
        ?SkillSnapshotBuilder $snapshotBuilder = null,
        ?CoreSkillRegistry $coreSkillRegistry = null,
        ?AdapterSkillResolver $adapterSkillResolver = null
    ) {
        $this->normalizer = $normalizer ?? new SkillNormalizer();
        $this->builtinProvider = $builtinProvider ?? new BuiltinSkillProvider($this->normalizer);
        $this->customProvider = $customProvider ?? new CustomSkillProvider();
        $this->selectionResolver = $selectionResolver
            ?? new SkillSelectionResolver($this->builtinProvider, $this->customProvider);
        $this->snapshotBuilder = $snapshotBuilder
            ?? new SkillSnapshotBuilder($this->selectionResolver, $this->normalizer);
        $this->coreSkillRegistry = $coreSkillRegistry;
        $this->adapterSkillResolver = $adapterSkillResolver;
    }

    /**
     * @return list<string>
     */
    public function getDefaultSkillCodes(): array
    {
        try {
            $codes = $this->adapterSkillResolver()->getLockedSkillCodes('pagebuilder_component_generation');
            return $codes !== [] ? $codes : self::DEFAULT_SKILL_CODES;
        } catch (\Throwable) {
            return self::DEFAULT_SKILL_CODES;
        }
    }

    /**
     * 列出 skills 根目录下所有可加载技能（每个目录内必须含 SKILL.md）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function listAvailableSkills(): array
    {
        $skills = [];
        try {
            $skills = $this->coreSkillRegistry()->listAvailableSkills(true);
        } catch (\Throwable) {
            $skills = [];
        }

        foreach ($this->selectionResolver->listAvailableSkills() as $code => $skill) {
            if (!isset($skills[$code])) {
                $skills[$code] = $skill;
            }
        }
        \ksort($skills);

        return $skills;
    }

    /**
     * 单个技能元信息。
     *
     * @return array<string, mixed>
     */
    public function getSkill(string $code): array
    {
        $code = \trim($code);
        $skills = $this->listAvailableSkills();
        if ($code !== '' && isset($skills[$code])) {
            return $skills[$code];
        }

        return [
            'code' => $code,
            'name' => $code,
            'description' => '',
            'body' => '',
            'normalized_body' => '',
            'body_hash' => '',
            'status' => 'missing',
            'source' => '',
            'local_path' => self::SKILLS_RELATIVE_ROOT . '/' . $code . '/SKILL.md',
            'abs_path' => '',
            'exists' => false,
        ];
    }

    /**
     * @param list<string> $selectedCodes Empty means the default claude-design selection.
     * @return list<array{code:string,name:string,description:string,source:string,normalized_body:string,body_hash:string}>
     */
    public function buildSkillSnapshots(array $selectedCodes = []): array
    {
        $requestedCodes = $this->normalizeSkillCodeList($selectedCodes);
        if ($requestedCodes === []) {
            $requestedCodes = self::DEFAULT_SKILL_CODES;
        }
        try {
            $snapshots = $this->coreSkillRegistry()->buildSkillSnapshots($requestedCodes);
            $snapshotCodes = \array_map(static fn(array $snapshot): string => (string)($snapshot['code'] ?? ''), $snapshots);
            $missingCodes = \array_diff($requestedCodes, $snapshotCodes);
            if ($missingCodes === []) {
                return $snapshots;
            }
        } catch (\Throwable) {
        }

        return $this->snapshotBuilder->buildSnapshots($requestedCodes);
    }

    /**
     * @param list<string> $selectedCodes
     * @return list<string>
     */
    public function resolveSelectedSkillCodes(array $selectedCodes = []): array
    {
        $codes = $this->normalizeSkillCodeList($selectedCodes);
        if ($codes === []) {
            return [];
        }

        $skills = $this->listAvailableSkills();
        $resolved = [];
        foreach ($codes as $code) {
            $skill = $skills[$code] ?? null;
            if (\is_array($skill) && !empty($skill['exists']) && (string)($skill['status'] ?? 'active') === 'active') {
                $resolved[] = $code;
            }
        }

        return $resolved;
    }

    /**
     * 输出注入到 Stage1 / PlanJson 提示词的“技能加载能力”段。
     *
     * @param string $stage 'stage1' | 'plan_json' | 'build' | 'qa' | 'repair'
     * @param list<string> $extraCodes 额外要求加载的技能 code（与默认列表合并、去重）
     * @param list<array<string, mixed>> $skillSnapshots 已冻结的技能快照，优先于当前 DB/文件内容
     * @return list<string>
     */
    public function buildPromptGuideLines(string $stage, array $extraCodes = [], array $skillSnapshots = []): array
    {
        $codes = \array_values(\array_unique(\array_merge(
            $this->resolveAdapterSkillCodesForStage($stage, $extraCodes),
            $this->extractCodesFromSnapshots($skillSnapshots)
        )));
        $skills = $this->listAvailableSkills();

        $lines = [];
        $lines[] = '';
        $lines[] = 'AI BUILDER SKILL CAPABILITY (mandatory, default-loaded):';
        $lines[] = '- You operate inside the Weline PageBuilder AI site builder. The platform exposes a SKILL LOADING capability: every directory under "' . self::SKILLS_RELATIVE_ROOT . '/<skill_code>/" with a SKILL.md is a loadable skill that constrains your design and planning behavior.';
        $lines[] = '- For every request you MUST treat the skills below as already loaded into your active toolbelt; their rules override generic AI defaults.';
        $lines[] = '- Default-loaded skills (do NOT skip):';
        foreach ($codes as $code) {
            $skill = $skills[$code] ?? $this->getSkill($code);
            $name = $skill['name'] !== '' ? $skill['name'] : $code;
            $desc = $this->compactDescription((string)$skill['description']);
            $lines[] = '  * ' . $name . ' (code=' . $code . ', local=' . (string)$skill['local_path'] . ')';
            if ($desc !== '') {
                $lines[] = '    summary: ' . $desc;
            }
        }
        $lines[] = '- Skill loading protocol: before producing any structured output, silently re-check the loaded skills, apply their non-negotiable rules, and refuse to emit content that violates them.';
        $lines[] = '- Skill audit (silent, before output): for every block/page/task, verify it satisfies (a) the default-loaded claude-design discipline; (b) the user one-line requirement; (c) any stage-specific contract above. If a check fails, rewrite that unit before returning.';

        // claude-design 的核心硬约束（精炼自 SKILL.md / references/design-principles.md）
        $lines[] = '';
        $lines[] = 'CLAUDE-DESIGN HARD RULES (always-on craft + anti-slop discipline):';
        $lines[] = '- Commit to a system before placing pixels: declare type scale, 1-2 background colors, layout rhythm, section-header pattern, and stick to them across the whole plan.';
        $lines[] = '- Ground hi-fi in real context: reuse the resolved theme_design palette/typography/spacing/radius and the user one-line requirement; do not invent a per-page palette or voice.';
        $lines[] = '- Palette role discipline: assign palette colors to roles (page base, elevated surface, text, muted text, CTA, accent, divider) instead of flooding every block with the same theme color.';
        $lines[] = '- Contrast gate: dark backgrounds require light readable text and light backgrounds require dark readable text; never emit dark-on-dark or light-on-light text, links, chips, or buttons.';
        $lines[] = '- Layering gate: adjacent sections must alternate surface weight, depth, divider, illustration, or spacing rhythm so the page has hierarchy instead of one flat color slab.';
        $lines[] = '- Variations should mix conservative + novel; do not converge on a single safe template across pages or blocks.';
        $lines[] = '- Anti-slop (forbidden by default unless the brief explicitly demands them):';
        $lines[] = '  * aggressive multi-hue gradient backgrounds (purple-to-blue, sunset, conic rainbows) used as decoration;';
        $lines[] = '  * emoji as bullet markers or in headlines when the brand does not already use emoji;';
        $lines[] = '  * rounded-corner cards with a left-border accent stripe used as the default container;';
        $lines[] = '  * SVG-drawn imagery standing in for real product shots, hero illustrations, or photos;';
        $lines[] = '  * decorative gradient orbs as stand-ins for "AI magic";';
        $lines[] = '  * over-iconified bullet lists (one icon per row of plain text) as decoration;';
        $lines[] = '  * decoration-by-dataviz: invented numbers, fake stats, decorative charts representing nothing;';
        $lines[] = '  * generic three-column feature grid as the default landing structure;';
        $lines[] = '  * overused font stacks (Inter / Roboto / Arial / system-ui / Fraunces) unless the brand actually uses them.';
        $lines[] = '- PageBuilder media contract overrides generic placeholder habits: when an asset (icon, photo, logo, product shot, chart) is missing, never output placeholder/fake/dummy media. Choose one explicit path instead: plan a real generated asset slot with image_intent.needs_image=true and a concrete scene/product/interface subject, or plan a complete CSS-only motif with image_intent.needs_image=false, non-empty css_motif/visual_atmosphere/image_treatment, and no placeholder language.';
        $lines[] = '- Media richness preference: for marketing/campaign sites, lean toward more real generated imagery during planning. Prefer a strong hero image plus additional block-level section images where they clarify trust, product/action, support, policies, articles, or local market context. This is guidance for richer design, not a completion gate: if image generation is unstable, keep the queue resumable and never mark a page complete unless its planned blocks are complete.';
        $lines[] = '- No filler content: every section must earn its place. Do not pad with dummy "Why choose us / Our values / Team" sections, fake testimonials, decorative stats, or feature grids invented to fill space.';
        $lines[] = '- Use the user voice: reuse the exact nouns, offers, numbers, and proof points from the user one-line requirement; do not replace them with abstract marketing-speak.';
        $lines[] = '- Visual rhythm: alternate heavy and light sections, give full-bleed imagery breathing room, use 1-2 background colors with intent across a page system.';
        $lines[] = '- Precision gate: before returning any plan or component, name at least three concrete brief-derived visual anchors internally (audience, product/action, trust/proof, culture/market, device/context) and make them visible through copy, layout, media treatment, or motif. If the result would still work for a different business after swapping the logo, it is invalid.';
        $lines[] = '- Finished-site gate: the above-fold experience must look like a publishable campaign page, not a wireframe. It needs strong scale contrast, a deliberate focal area, real CTA hierarchy, readable overlay/surface treatment, and enough domain-specific proof/detail to feel designed for this exact site.';
        $lines[] = '- Repetition gate: do not solve multiple blocks with the same centered heading, three rounded cards, generic metric row, or decorative gradient/orb pattern. Adjacent blocks must change composition, density, or media role.';
        $lines[] = '- Code craft gate: generated HTML fragments must be structurally balanced, component-scoped, and safe to embed; close every non-void tag before returning JSON.';
        $lines[] = '- Final gut check (silently before output): would this look like it came from a specific designer for this exact brief, or like generic AI? If generic, rebalance toward specificity (bolder color, heavier type weight, bigger hero, fewer decorative sections).';
        $lines[] = '';
        \array_push($lines, ...$this->buildFrontendDesignPromptGuideLines());
        \array_push($lines, ...$this->buildSelectedSkillRuleLines($codes, $skillSnapshots));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function buildFrontendDesignPromptGuideLines(): array
    {
        $path = $this->resolveRepositoryRelativePath(self::FRONTEND_DESIGN_SKILL_LOCAL_PATH);
        if ($path === '' || !\is_file($path)) {
            return [];
        }

        $body = (string)\file_get_contents($path);
        $body = \trim($body);
        if ($body === '') {
            return [];
        }

        return [
            'FRONTEND-DESIGN COMPATIBILITY RULES (user-provided total design guide):',
            '- Local guide: ' . self::FRONTEND_DESIGN_SKILL_LOCAL_PATH,
            '- Apply this guide to every plan page, block task, generated HTML/CSS fragment, visual treatment, and image intent.',
            'Skill body begins:',
            $this->excerptSkillBody($body),
            'Skill body ends.',
            '',
        ];
    }

    private function resolveRepositoryRelativePath(string $relativePath): string
    {
        $relativePath = \trim($relativePath);
        if ($relativePath === '') {
            return '';
        }

        $normalized = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        if (\defined('BP')) {
            return \rtrim((string)\constant('BP'), '\\/') . DIRECTORY_SEPARATOR . $normalized;
        }

        return \dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . $normalized;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    public function resolveSelectedSkillCodesFromScope(array $scope): array
    {
        $candidates = [
            $scope['_plan_sse_request']['selected_skill_codes'] ?? null,
            $scope['selected_skill_codes'] ?? null,
            $scope['plan_json']['contract_context']['selected_skill_codes'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $codes = $this->normalizeSkillCodeList($candidate);
            if ($codes !== []) {
                return $this->resolveSelectedSkillCodes($codes);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<array<string, mixed>>
     */
    public function resolveSkillSnapshotsFromScope(array $scope): array
    {
        $candidates = [
            $scope['plan_json']['contract_context']['skill_snapshots'] ?? null,
            $scope['contract_context']['skill_snapshots'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $snapshots = $this->normalizeSkillSnapshots($candidate);
            if ($snapshots !== []) {
                return $snapshots;
            }
        }

        return [];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function normalizeSkillCodeList(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $decoded = \json_decode($raw, true);
            $items = \is_array($decoded) ? $decoded : \preg_split('/[\s,;]+/', $raw);
            if (!\is_array($items)) {
                $items = [];
            }
        } else {
            $items = [];
        }

        $codes = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $code = \trim((string)$item);
            if ($code === '' || !\preg_match('/^[A-Za-z0-9_.-]+$/', $code) || \in_array($code, $codes, true)) {
                continue;
            }
            $codes[] = $code;
        }

        return $codes;
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    private function normalizeSkillSnapshots(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $snapshots = [];
        foreach ($raw as $snapshot) {
            if (!\is_array($snapshot)) {
                continue;
            }
            $code = \trim((string)($snapshot['code'] ?? ''));
            if ($code === '' || !\preg_match('/^[A-Za-z0-9_.-]+$/', $code)) {
                continue;
            }
            $snapshots[] = [
                'code' => $code,
                'name' => \trim((string)($snapshot['name'] ?? $code)),
                'description' => \trim((string)($snapshot['description'] ?? '')),
                'source' => \trim((string)($snapshot['source'] ?? 'snapshot')),
                'normalized_body' => (string)($snapshot['normalized_body'] ?? $snapshot['body'] ?? ''),
                'body_hash' => \trim((string)($snapshot['body_hash'] ?? '')),
            ];
        }

        return $snapshots;
    }

    /**
     * @param list<array<string, mixed>> $skillSnapshots
     * @return list<string>
     */
    private function extractCodesFromSnapshots(array $skillSnapshots): array
    {
        $codes = [];
        foreach ($this->normalizeSkillSnapshots($skillSnapshots) as $snapshot) {
            $code = (string)$snapshot['code'];
            if ($code !== '' && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @param list<string> $codes
     * @param list<array<string, mixed>> $skillSnapshots
     * @return list<string>
     */
    private function buildSelectedSkillRuleLines(array $codes, array $skillSnapshots): array
    {
        $snapshotByCode = [];
        foreach ($this->normalizeSkillSnapshots($skillSnapshots) as $snapshot) {
            $snapshotByCode[(string)$snapshot['code']] = $snapshot;
        }

        $rules = [];
        $validatedSkills = [];
        $lookupCodes = [];
        foreach ($codes as $code) {
            $code = \trim((string)$code);
            if ($code === '' || \in_array($code, self::DEFAULT_SKILL_CODES, true) || isset($snapshotByCode[$code])) {
                continue;
            }
            $lookupCodes[] = $code;
        }
        if ($lookupCodes !== []) {
            $availableSkills = $this->listAvailableSkills();
            foreach (\array_values(\array_unique($lookupCodes)) as $lookupCode) {
                $skill = $availableSkills[$lookupCode] ?? null;
                if (\is_array($skill) && !empty($skill['exists']) && (string)($skill['status'] ?? 'active') === 'active') {
                    $validatedSkills[(string)$skill['code']] = $skill;
                }
            }
        }

        foreach ($codes as $code) {
            $code = \trim((string)$code);
            if ($code === '' || \in_array($code, self::DEFAULT_SKILL_CODES, true)) {
                continue;
            }
            $skill = $snapshotByCode[$code] ?? $validatedSkills[$code] ?? null;
            if (!\is_array($skill)) {
                continue;
            }
            $body = \trim((string)($skill['normalized_body'] ?? $skill['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            $rules[] = '';
            $rules[] = 'SELECTED AI BUILDER SKILL RULES (must override generic behavior):';
            $rules[] = '- Skill code: ' . $code;
            $rules[] = '- Skill name: ' . \trim((string)($skill['name'] ?? $code));
            $rules[] = '- Skill source: ' . \trim((string)($skill['source'] ?? ''));
            $hash = \trim((string)($skill['body_hash'] ?? ''));
            if ($hash !== '') {
                $rules[] = '- Skill body hash: ' . $hash;
            }
            $rules[] = '- Apply this skill to every generated contract, task, and visible content field in this stage.';
            $rules[] = 'Skill body begins:';
            $rules[] = $this->excerptSkillBody($body);
            $rules[] = 'Skill body ends.';
        }

        return $rules;
    }

    /**
     * @param list<string> $extraCodes
     * @param list<array<string, mixed>> $skillSnapshots
     */
    public function buildPromptGuideText(string $stage, array $extraCodes = [], array $skillSnapshots = []): string
    {
        return \trim(\implode("\n", $this->buildPromptGuideLines($stage, $extraCodes, $skillSnapshots))) . "\n";
    }

    /**
     * @param list<string> $extraCodes
     * @param list<array<string, mixed>> $skillSnapshots
     */
    public function prependPromptGuide(string $prompt, string $stage = 'pagebuilder', array $extraCodes = [], array $skillSnapshots = []): string
    {
        if ($this->hasPromptGuide($prompt) && $this->promptContainsSelectedSkills($prompt, $extraCodes, $skillSnapshots)) {
            return $prompt;
        }

        $prompt = \ltrim($prompt);
        if ($this->hasPromptGuide($prompt)) {
            $guide = \trim(\implode("\n", $this->buildSelectedSkillRuleLines(
                \array_values(\array_unique(\array_merge($extraCodes, $this->extractCodesFromSnapshots($skillSnapshots)))),
                $skillSnapshots
            ))) . "\n";
        } else {
            $guide = $this->buildPromptGuideText($stage, $extraCodes, $skillSnapshots);
        }
        return $prompt === '' ? $guide : $guide . "\n" . $prompt;
    }

    /**
     * @return list<string>
     */
    public function buildPromptGuideLinesForScope(string $stage, array $scope): array
    {
        return $this->buildPromptGuideLines(
            $stage,
            $this->resolveSelectedSkillCodesFromScope($scope),
            $this->resolveSkillSnapshotsFromScope($scope)
        );
    }

    public function prependPromptGuideForScope(string $prompt, string $stage, array $scope): string
    {
        return $this->prependPromptGuide(
            $prompt,
            $stage,
            $this->resolveSelectedSkillCodesFromScope($scope),
            $this->resolveSkillSnapshotsFromScope($scope)
        );
    }

    private function hasPromptGuide(string $prompt): bool
    {
        return \str_contains($prompt, 'AI BUILDER SKILL CAPABILITY')
            || \str_contains($prompt, 'CLAUDE-DESIGN HARD RULES')
            || \str_contains($prompt, 'code=claude-design');
    }

    /**
     * @param list<string> $extraCodes
     * @param list<array<string, mixed>> $skillSnapshots
     */
    private function promptContainsSelectedSkills(string $prompt, array $extraCodes, array $skillSnapshots): bool
    {
        $codes = \array_values(\array_unique(\array_merge($extraCodes, $this->extractCodesFromSnapshots($skillSnapshots))));
        foreach ($codes as $code) {
            $code = \trim((string)$code);
            if ($code === '' || \in_array($code, self::DEFAULT_SKILL_CODES, true)) {
                continue;
            }
            if (!\str_contains($prompt, 'Skill code: ' . $code) && !\str_contains($prompt, 'code=' . $code)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 兼容既有调用：返回包含 frontend-design 与默认技能的注入行（plan_json 用）。
     *
     * @param array<string, mixed> $batch
     * @param list<string> $extraCodes
     * @param list<array<string, mixed>> $skillSnapshots
     * @return list<string>
     */
    private function compactDescription(string $description): string
    {
        if ($description === '') {
            return '';
        }
        $description = (string)\preg_replace('/\s+/u', ' ', $description);
        if (\function_exists('mb_strlen') && \mb_strlen($description) > 360) {
            return \mb_substr($description, 0, 357) . '...';
        }
        if (\strlen($description) > 360) {
            return \substr($description, 0, 357) . '...';
        }

        return $description;
    }

    private function excerptSkillBody(string $body): string
    {
        $body = \trim((string)\preg_replace('/\R/u', "\n", $body));
        if ($body === '') {
            return '';
        }
        $max = 6000;
        if (\function_exists('mb_strlen') && \mb_strlen($body) > $max) {
            return \mb_substr($body, 0, $max - 3) . '...';
        }
        if (\strlen($body) > $max) {
            return \substr($body, 0, $max - 3) . '...';
        }

        return $body;
    }

    /**
     * @param list<string> $temporaryCodes
     * @return list<string>
     */
    private function resolveAdapterSkillCodesForStage(string $stage, array $temporaryCodes): array
    {
        $adapterCode = $this->adapterCodeForStage($stage);
        try {
            $resolved = $this->adapterSkillResolver()->resolveSkillBindings($adapterCode, $temporaryCodes);
            $codes = $resolved['codes'];
            $availableSkills = $this->listAvailableSkills();
            foreach ($temporaryCodes as $temporaryCode) {
                $skill = $availableSkills[$temporaryCode] ?? null;
                if (\is_array($skill) && !empty($skill['exists']) && (string)($skill['status'] ?? 'active') === 'active') {
                    $codes[] = $temporaryCode;
                }
            }
            return \array_values(\array_unique($codes));
        } catch (\Throwable $throwable) {
            if (\str_contains($throwable->getMessage(), 'Locked adapter skill')) {
                throw $throwable;
            }
            return \array_values(\array_unique(\array_merge($this->getDefaultSkillCodes(), $temporaryCodes)));
        }
    }

    private function adapterCodeForStage(string $stage): string
    {
        $stage = \strtolower(\trim($stage));
        return \in_array($stage, ['stage1', 'profile', 'plan_json', 'plan'], true)
            ? 'pagebuilder_plan_generation'
            : 'pagebuilder_component_generation';
    }

    private function coreSkillRegistry(): CoreSkillRegistry
    {
        return $this->coreSkillRegistry ?? ObjectManager::getInstance(CoreSkillRegistry::class);
    }

    private function adapterSkillResolver(): AdapterSkillResolver
    {
        return $this->adapterSkillResolver ?? ObjectManager::getInstance(AdapterSkillResolver::class);
    }

    private function resolveSkillsAbsoluteRoot(): string
    {
        if (\defined('BP')) {
            $base = (string)\constant('BP');
            if ($base !== '') {
                return \rtrim($base, "\\/") . DIRECTORY_SEPARATOR
                    . \str_replace('/', DIRECTORY_SEPARATOR, self::SKILLS_RELATIVE_ROOT);
            }
        }
        // 回退：__DIR__ 推算 PageBuilder 模块根 -> skills
        return \dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'skills';
    }
}
