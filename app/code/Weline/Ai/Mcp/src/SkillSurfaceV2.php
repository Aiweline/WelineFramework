<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * skill-v2 surface layer: per-surface override files + budget lint.
 *
 * Why: legacy surfaces (GuidanceWorkflowCatalog) pile hard-constraint policy text into
 * `description`/`norms` and never declare a procedure. skill-v2 keeps the same surface
 * id/trigger contract but replaces the body with a small executable program:
 * when -> inputs -> procedure -> checklist -> anti_patterns -> evidence -> docs.
 *
 * Files live in src/SkillSurfaceV2/{surface_id}.php and return a plain array. A file may
 * either override an existing surface or add a new one (e.g. design_taste).
 *
 * Standard: app/code/Weline/Ai/doc/AI技能编写标准.md
 * Test: app/code/Weline/Ai/Mcp/tests/skill-surface-standard.php
 */
final class SkillSurfaceV2
{
    public const SPEC = 'skill-v2';

    public const DIR = __DIR__ . '/SkillSurfaceV2';

    /**
     * Hard budgets; exceeding a budget is a FAIL, not a warning.
     *
     * @var array<string, int>
     */
    public const BUDGET = [
        'description_chars' => 280,
        'when_items' => 6,
        'when_item_chars' => 60,
        'inputs_items' => 5,
        'procedure_items' => 7,
        'procedure_min' => 3,
        'procedure_item_chars' => 90,
        'checklist_items' => 8,
        'checklist_min' => 3,
        'checklist_item_chars' => 60,
        'anti_pattern_items' => 8,
        'anti_pattern_item_chars' => 60,
        'evidence_items' => 4,
        'norms_items' => 12,
        'norm_summary_chars' => 200,
        'norm_ids_items' => 60,
        'norm_id_chars' => 80,
        'trigger_items' => 40,
        'docs_items' => 10,
        'body_chars' => 3600,
    ];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * All skill-v2 surface files keyed by surface id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = [];
        $files = glob(self::DIR . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $data = (static function (string $path): mixed {
                return require $path;
            })($file);
            if (!is_array($data)) {
                continue;
            }
            $id = trim((string) ($data['id'] ?? ''));
            if ($id === '') {
                $id = basename($file, '.php');
                $data['id'] = $id;
            }
            $data['spec'] = self::SPEC;
            $rows[$id] = $data;
        }

        return self::$cache = $rows;
    }

    /**
     * Merge skill-v2 files over the legacy surface map (replace, never deep-merge lists).
     *
     * @param array<string, array<string, mixed>> $surfaces
     *
     * @return array<string, array<string, mixed>>
     */
    public static function apply(array $surfaces): array
    {
        foreach (self::all() as $id => $override) {
            if (!isset($surfaces[$id])) {
                $surfaces[$id] = $override;
                continue;
            }
            $surfaces[$id] = array_replace($surfaces[$id], $override);
            $surfaces[$id]['spec'] = self::SPEC;
        }

        return $surfaces;
    }

    /**
     * Surface ids still on legacy bodies (only shrink; touching one must migrate it).
     *
     * @return list<string>
     */
    public static function legacySurfaceIds(): array
    {
        $ids = [];
        foreach (GuidanceWorkflowCatalog::allSurfaces() as $id => $surface) {
            if ((string) ($surface['spec'] ?? '') !== self::SPEC) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Approximate rendered body size in characters (mirrors McpSkillCatalog::renderBody order).
     *
     * @param array<string, mixed> $skill
     */
    public static function bodyChars(array $skill): int
    {
        $total = mb_strlen((string) ($skill['description'] ?? ''));
        foreach (['when', 'inputs', 'procedure', 'checklist', 'anti_patterns', 'evidence', 'verification_commands', 'authoritative_docs'] as $key) {
            foreach (self::listOf($skill[$key] ?? null) as $item) {
                $total += mb_strlen($item);
            }
        }
        foreach ((array) ($skill['norms'] ?? []) as $norm) {
            if (is_array($norm)) {
                $total += mb_strlen((string) ($norm['summary'] ?? ''));
            }
        }
        foreach (self::listOf($skill['norm_ids'] ?? null) as $normId) {
            $total += mb_strlen($normId) + 1;
        }
        $rules = (array) ($skill['template_surface_rules'] ?? []);
        foreach (['required', 'forbidden'] as $bucket) {
            foreach (self::listOf($rules[$bucket] ?? null) as $item) {
                $total += mb_strlen($item);
            }
        }

        return $total;
    }

    /**
     * Budget + structure violations for one skill-v2 surface.
     *
     * @param array<string, mixed> $skill
     *
     * @return list<string>
     */
    public static function lint(array $skill): array
    {
        $id = (string) ($skill['id'] ?? '?');
        $v = [];
        $budget = self::BUDGET;

        foreach (['label', 'description'] as $key) {
            if (trim((string) ($skill[$key] ?? '')) === '') {
                $v[] = $id . ': missing ' . $key;
            }
        }
        if (self::listOf($skill['triggers'] ?? null) === []) {
            $v[] = $id . ': missing triggers';
        }

        $descChars = mb_strlen((string) ($skill['description'] ?? ''));
        if ($descChars > $budget['description_chars']) {
            $v[] = sprintf('%s: description %d chars > %d', $id, $descChars, $budget['description_chars']);
        }

        $checks = [
            ['when', $budget['when_items'], $budget['when_item_chars']],
            ['inputs', $budget['inputs_items'], 90],
            ['procedure', $budget['procedure_items'], $budget['procedure_item_chars']],
            ['checklist', $budget['checklist_items'], $budget['checklist_item_chars']],
            ['anti_patterns', $budget['anti_pattern_items'], $budget['anti_pattern_item_chars']],
            ['evidence', $budget['evidence_items'], 160],
        ];
        foreach ($checks as [$key, $maxItems, $maxChars]) {
            $items = self::listOf($skill[$key] ?? null);
            if (count($items) > $maxItems) {
                $v[] = sprintf('%s: %s has %d items > %d', $id, $key, count($items), $maxItems);
            }
            foreach ($items as $index => $item) {
                if (mb_strlen($item) > $maxChars) {
                    $v[] = sprintf('%s: %s[%d] %d chars > %d', $id, $key, $index, mb_strlen($item), $maxChars);
                }
            }
        }

        if (count(self::listOf($skill['procedure'] ?? null)) < $budget['procedure_min']) {
            $v[] = sprintf('%s: procedure needs >= %d steps', $id, $budget['procedure_min']);
        }
        if (count(self::listOf($skill['checklist'] ?? null)) < $budget['checklist_min']) {
            $v[] = sprintf('%s: checklist needs >= %d items', $id, $budget['checklist_min']);
        }
        if (self::listOf($skill['evidence'] ?? null) === []) {
            $v[] = $id . ': evidence is required';
        }

        $triggers = self::listOf($skill['triggers'] ?? null);
        if (count($triggers) > $budget['trigger_items']) {
            $v[] = sprintf('%s: triggers %d > %d', $id, count($triggers), $budget['trigger_items']);
        }

        $norms = is_array($skill['norms'] ?? null) ? $skill['norms'] : [];
        if (count($norms) > $budget['norms_items']) {
            $v[] = sprintf('%s: norms %d > %d', $id, count($norms), $budget['norms_items']);
        }
        foreach ($norms as $index => $norm) {
            $summary = is_array($norm) ? (string) ($norm['summary'] ?? '') : '';
            if (mb_strlen($summary) > $budget['norm_summary_chars']) {
                $v[] = sprintf('%s: norms[%d] summary %d chars > %d', $id, $index, mb_strlen($summary), $budget['norm_summary_chars']);
            }
        }

        // norm_ids: pointer index to hard_constraints rule ids (no summaries, keeps ids contract).
        $normIds = self::listOf($skill['norm_ids'] ?? null);
        if (count($normIds) > $budget['norm_ids_items']) {
            $v[] = sprintf('%s: norm_ids %d > %d', $id, count($normIds), $budget['norm_ids_items']);
        }
        foreach ($normIds as $index => $normId) {
            if (!preg_match('/^[a-z0-9_]+$/', $normId)) {
                $v[] = sprintf('%s: norm_ids[%d] not a rule id: %s', $id, $index, $normId);
            }
            if (mb_strlen($normId) > $budget['norm_id_chars']) {
                $v[] = sprintf('%s: norm_ids[%d] too long', $id, $index);
            }
        }

        $docs = self::listOf($skill['authoritative_docs'] ?? null);
        if (count($docs) > $budget['docs_items']) {
            $v[] = sprintf('%s: authoritative_docs %d > %d', $id, count($docs), $budget['docs_items']);
        }
        foreach ($docs as $doc) {
            if (!is_file($doc)) {
                $v[] = $id . ': authoritative doc missing: ' . $doc;
            }
        }

        $bodyChars = self::bodyChars($skill);
        if ($bodyChars > $budget['body_chars']) {
            $v[] = sprintf('%s: body %d chars > %d', $id, $bodyChars, $budget['body_chars']);
        }

        return $v;
    }

    /**
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item)) {
                $text = trim((string) ($item['text'] ?? $item['summary'] ?? $item['id'] ?? ''));
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }
}
