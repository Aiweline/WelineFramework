<?php

declare(strict_types=1);

/*
 * AI 建站技能注册表（Skill Registry）
 *
 * 让 AI 建站工作台的提示词具备显式“技能加载能力”：
 * 1) 把 app/code/GuoLaiRen/PageBuilder/skills/ 下的每个目录视为一个可加载技能；
 * 2) 解析其 SKILL.md frontmatter（name/description）作为提示词中的“能力声明”；
 * 3) 默认强制加载 claude-design 技能（设计纪律、反 AI-slop、内容真实性等）；
 * 4) 兼容已有的 prompt_guides/frontend-design 技能，作为 stage2 任务规划的设计指引。
 *
 * 注：本服务只负责把技能描述、本地路径、硬约束转成提示词行；具体生成走老路径
 * （Stage1 在 buildAiPlanPrompt 注入；Stage2 在 buildSkillRegistryPromptGuide 注入）。
 */

namespace GuoLaiRen\PageBuilder\Service\AI;

final class AiSiteSkillRegistry
{
    public const SKILLS_RELATIVE_ROOT = 'app/code/GuoLaiRen/PageBuilder/skills';

    public const FRONTEND_DESIGN_SKILL_LOCAL_PATH = 'app/code/GuoLaiRen/PageBuilder/Service/AI/prompt_guides/frontend-design/SKILL.md';

    public const FRONTEND_DESIGN_SKILL_SOURCE = 'https://github.com/anthropics/claude-code/blob/main/plugins/frontend-design/skills/frontend-design/SKILL.md';

    /**
     * 默认强制加载的技能 code（按顺序排在提示词前面，越靠前越优先）。
     * 老板要求：默认加载 claude-design。
     *
     * @var list<string>
     */
    private const DEFAULT_SKILL_CODES = ['claude-design'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $skillCache = null;

    /**
     * @return list<string>
     */
    public function getDefaultSkillCodes(): array
    {
        return self::DEFAULT_SKILL_CODES;
    }

    /**
     * 列出 skills 根目录下所有可加载技能（每个目录内必须含 SKILL.md）。
     *
     * @return array<string, array{code:string,name:string,description:string,local_path:string,abs_path:string,exists:bool}>
     */
    public function listAvailableSkills(): array
    {
        if (self::$skillCache !== null) {
            return self::$skillCache;
        }

        $absRoot = $this->resolveSkillsAbsoluteRoot();
        $result = [];
        if ($absRoot !== '' && \is_dir($absRoot)) {
            $entries = @\scandir($absRoot) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $skillDir = $absRoot . DIRECTORY_SEPARATOR . $entry;
                if (!\is_dir($skillDir)) {
                    continue;
                }
                $skillFile = $skillDir . DIRECTORY_SEPARATOR . 'SKILL.md';
                if (!\is_file($skillFile)) {
                    continue;
                }
                $front = $this->parseSkillFrontmatter($skillFile);
                $code = (string)$entry;
                $localPath = self::SKILLS_RELATIVE_ROOT . '/' . $code . '/SKILL.md';
                $result[$code] = [
                    'code' => $code,
                    'name' => (string)($front['name'] ?? $code),
                    'description' => (string)($front['description'] ?? ''),
                    'local_path' => $localPath,
                    'abs_path' => $skillFile,
                    'exists' => true,
                ];
            }
        }
        \ksort($result);

        return self::$skillCache = $result;
    }

    /**
     * 单个技能元信息。
     *
     * @return array{code:string,name:string,description:string,local_path:string,abs_path:string,exists:bool}
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
            'local_path' => self::SKILLS_RELATIVE_ROOT . '/' . $code . '/SKILL.md',
            'abs_path' => '',
            'exists' => false,
        ];
    }

    /**
     * 输出注入到 Stage1 / Stage2 提示词的“技能加载能力”段。
     *
     * @param string $stage 'stage1' | 'stage2' | 'stage2_shared' | 'stage2_page' | 'stage3'
     * @param list<string> $extraCodes 额外要求加载的技能 code（与默认列表合并、去重）
     * @return list<string>
     */
    public function buildPromptGuideLines(string $stage, array $extraCodes = []): array
    {
        $codes = \array_values(\array_unique(\array_merge($this->getDefaultSkillCodes(), $extraCodes)));
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
        $lines[] = '- Placeholders beat fakes: when an asset (icon, photo, logo, product shot, chart) is missing, output a clearly labeled placeholder description instead of fabricating it.';
        $lines[] = '- No filler content: every section must earn its place. Do not pad with dummy "Why choose us / Our values / Team" sections, fake testimonials, decorative stats, or feature grids invented to fill space.';
        $lines[] = '- Use the user voice: reuse the exact nouns, offers, numbers, and proof points from the user one-line requirement; do not replace them with abstract marketing-speak.';
        $lines[] = '- Visual rhythm: alternate heavy and light sections, give full-bleed imagery breathing room, use 1-2 background colors with intent across a page system.';
        $lines[] = '- Code craft gate: generated HTML fragments must be structurally balanced, component-scoped, and safe to embed; close every non-void tag before returning JSON.';
        $lines[] = '- Final gut check (silently before output): would this look like it came from a specific designer for this exact brief, or like generic AI? If generic, rebalance toward specificity (bolder color, heavier type weight, bigger hero, fewer decorative sections).';
        $lines[] = '';

        return $lines;
    }

    /**
     * 兼容旧调用：返回包含 frontend-design 与默认技能的注入行（stage2 用）。
     *
     * @param array<string, mixed> $batch
     * @return list<string>
     */
    public function buildStageTwoComponentSkillGuide(array $batch): array
    {
        $batchType = (string)($batch['type'] ?? '');
        $componentScope = $batchType === 'shared'
            ? 'shared theme component such as header/footer'
            : 'page-owned theme block component';

        $lines = $this->buildPromptGuideLines('stage2');
        $lines[] = 'Frontend design skill reference (mandatory for every generated theme component task):';
        $lines[] = '- Local skill file: ' . self::FRONTEND_DESIGN_SKILL_LOCAL_PATH;
        $lines[] = '- Source skill: ' . self::FRONTEND_DESIGN_SKILL_SOURCE;
        $lines[] = '- Scope for this batch: ' . $componentScope;
        $lines[] = '- Apply frontend-design skill before writing task_script/style_plan: pick a clear aesthetic direction that matches the site purpose, audience, page role, and block goal.';
        $lines[] = '- Treat stage-1 theme_design.style_signature and art_direction as mandatory inputs when present; convert them into executable style_plan details instead of falling back to a generic template.';
        $lines[] = '- Avoid generic AI aesthetics: no default Inter/Roboto/Arial/system-font look, no timid purple-gradient-on-white templates, no cookie-cutter card grids, no interchangeable SaaS hero patterns unless stage-1 explicitly demands that visual language.';
        $lines[] = '- Visual quality bar: the task must give stage 3 enough detail to build a polished customer-ready block, including composition motif, background/texture system, surface treatment, visual motif, CTA state, and mobile rhythm.';
        $lines[] = '- Customer-fit rule: every style_plan must explain how the visual choices fit the user brief and the specific page/block role, not just the global palette.';
        $lines[] = '- Make each component memorable through a deliberate typography, color, spatial composition, motion, texture, and visual-detail decision that can be implemented by stage 3.';
        $lines[] = '- Match complexity to the chosen aesthetic: refined/minimal components need precise spacing, type scale, and restraint; expressive/maximal components need purposeful layering, motion, and atmosphere.';
        $lines[] = '- Interaction/effects must be executable, not decorative prose: name the target element, default/hover/focus state, transition or transform, and reduced-motion behavior inside style_plan or task_script.';
        $lines[] = '- Customer-intent lock: style_plan must show how the block satisfies the original customer request through UI affordances, CTA wording, motifs, and interaction behavior; do not merely restate the global theme.';
        $lines[] = '- Page-owned blocks must start from page_design_plan before component styling: use its color_layering, section_flow, and interaction_notes to make the page feel intentionally art-directed rather than theme-colored uniformly.';
        $lines[] = '- Each style_plan must name exact contrast pairings for background/surface/text/CTA states and a neighboring-section contrast strategy; missing contrast pairings are invalid.';
        $lines[] = '- For each returned task, encode the skill outcome in block_task.style_plan and task_script.responsive_contract/accessibility_contract/asset_requirements; do not merely mention this skill in prose.';
        $lines[] = '- style_plan must include concrete typography, color/theme, motion, spatial composition, background/texture/detail, responsive behavior, and accessibility notes when relevant to the component.';
        $lines[] = '- Shared components must keep the same aesthetic system while adapting interaction density: header navigation clarity, footer trust/compliance structure, and mobile ergonomics are mandatory.';
        $lines[] = '- Page block components must translate stage-1 block_goal/realtime_content/style_direction into visible frontend decisions, not into instructions for a future designer.';
        $lines[] = '';

        return $lines;
    }

    /**
     * 解析 SKILL.md 顶部 YAML frontmatter，返回 name/description。
     *
     * @return array{name?:string,description?:string}
     */
    private function parseSkillFrontmatter(string $absSkillFile): array
    {
        $contents = @\file_get_contents($absSkillFile);
        if (!\is_string($contents) || $contents === '') {
            return [];
        }
        $contents = \ltrim($contents);
        if (!\str_starts_with($contents, '---')) {
            return [];
        }
        $end = \strpos($contents, "\n---", 3);
        if ($end === false) {
            return [];
        }
        $front = \substr($contents, 3, $end - 3);
        $front = \str_replace(["\r\n", "\r"], "\n", $front);
        $lines = \explode("\n", $front);

        $result = [];
        $currentKey = '';
        $accumulator = '';
        foreach ($lines as $rawLine) {
            $line = \rtrim($rawLine);
            if ($line === '') {
                continue;
            }
            if (\preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/u', $line, $m) === 1) {
                if ($currentKey !== '') {
                    $result[$currentKey] = \trim($accumulator);
                }
                $currentKey = (string)$m[1];
                $value = (string)$m[2];
                if ($value === '>-' || $value === '>' || $value === '|') {
                    $accumulator = '';
                } else {
                    $accumulator = $value;
                }
            } else {
                $accumulator .= ' ' . \trim($line);
            }
        }
        if ($currentKey !== '') {
            $result[$currentKey] = \trim($accumulator);
        }

        $clean = [];
        if (isset($result['name'])) {
            $clean['name'] = $this->stripQuotes((string)$result['name']);
        }
        if (isset($result['description'])) {
            $clean['description'] = $this->stripQuotes((string)$result['description']);
        }

        return $clean;
    }

    private function stripQuotes(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if ((\str_starts_with($value, '"') && \str_ends_with($value, '"'))
            || (\str_starts_with($value, "'") && \str_ends_with($value, "'"))) {
            return \trim(\substr($value, 1, -1));
        }

        return $value;
    }

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
