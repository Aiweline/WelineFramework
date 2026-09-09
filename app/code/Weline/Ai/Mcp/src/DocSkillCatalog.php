<?php

declare(strict_types=1);

namespace LearningMcp;

/**
 * Extracts development skills from module doc skill indexes into MCP catalog entries.
 *
 * Sources (read-only; never writes repository skill projections):
 * - app/code/{Vendor}/{Module}/doc/ai/INDEX.json skills array
 * - app/code/{Vendor}/{Module}/doc/ai/skills/{name}/SKILL.md
 * - Modules with doc/AI-INDEX.md synthesize a locator skill when no INDEX skill exists
 * - dev/ai-command markdown files as command catalog (not skills)
 */
final class DocSkillCatalog
{
    public const KIND_DOC_FILE = 'module_doc_skill';

    public const KIND_DOC_INDEX = 'module_ai_index';

    /**
     * @return list<array<string, mixed>>
     */
    public static function extractSkills(string $repository): array
    {
        $repository = self::normalizeRoot($repository);
        if ($repository === '') {
            return [];
        }

        $byId = [];
        foreach (self::scanIndexJsonSkills($repository) as $skill) {
            $byId[(string) $skill['skill_id']] = $skill;
        }
        foreach (self::scanSkillMarkdownFiles($repository) as $skill) {
            $id = (string) $skill['skill_id'];
            if (!isset($byId[$id])) {
                $byId[$id] = $skill;
            } else {
                $byId[$id] = self::mergeSkill($byId[$id], $skill);
            }
        }
        foreach (self::synthesizeAiIndexLocators($repository, $byId) as $skill) {
            $byId[(string) $skill['skill_id']] = $skill;
        }

        $skills = array_values($byId);
        usort(
            $skills,
            static fn (array $a, array $b): int => strcmp((string) ($a['skill_id'] ?? ''), (string) ($b['skill_id'] ?? ''))
        );

        return $skills;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function extractCommands(string $repository): array
    {
        $repository = self::normalizeRoot($repository);
        if ($repository === '') {
            return [];
        }
        $root = $repository . '/dev/ai-command';
        if (!is_dir($root)) {
            return [];
        }

        $commands = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = self::relativePath($repository, $absolute);
            $raw = @file_get_contents($absolute);
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $title = self::firstHeading($raw) ?: basename($absolute, '.md');
            $hardTrigger = self::extractHardTrigger($raw);
            $triggers = self::extractTriggers($raw);
            if (is_array($hardTrigger) && is_array($hardTrigger['triggers'] ?? null)) {
                foreach ($hardTrigger['triggers'] as $extra) {
                    $extra = trim((string) $extra);
                    if ($extra !== '') {
                        $triggers[] = $extra;
                    }
                }
            }
            $commands[] = [
                'command_id' => self::commandId($relative, $absolute),
                'title' => $title,
                'path' => $relative,
                'triggers' => array_values(array_unique(array_filter($triggers))),
                'hard_trigger' => $hardTrigger,
                'description' => self::firstParagraph($raw),
            ];
        }
        usort(
            $commands,
            static fn (array $a, array $b): int => strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? ''))
        );

        return $commands;
    }

    /**
     * Greeting / extract-skills response payload.
     *
     * @return array<string, mixed>
     */
    public static function greetingCatalog(string $repository, array $workflowSummary): array
    {
        $docSkills = self::extractSkills($repository);
        $commands = self::extractCommands($repository);
        $docSummary = [];
        foreach ($docSkills as $skill) {
            $docSummary[] = [
                'skill_id' => $skill['skill_id'],
                'name' => $skill['name'],
                'kind' => $skill['kind'] ?? self::KIND_DOC_FILE,
                'module' => $skill['module'] ?? '',
                'path' => $skill['relative_path'] ?? '',
            ];
        }

        return [
            'schema_version' => 'mcp-greeting-catalog.v1',
            'when' => ['hi', '你好', 'hello', '提取技能', 'list skills'],
            'skills' => [
                'workflow' => $workflowSummary,
                'module_doc' => $docSummary,
                'total' => count($workflowSummary) + count($docSummary),
            ],
            'commands' => $commands,
            'how_to_load' => [
                'discover' => 'resolve_skill',
                'load' => 'get_skill',
                'list_all' => 'resolve_skill(list_all=true) or task=提取技能',
            ],
            'note' => 'Module doc skills are read from doc/ai indexes into MCP memory only; knowledge.auto_generate_skills stays false.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function scanIndexJsonSkills(string $repository): array
    {
        $skills = [];
        $pattern = $repository . '/app/code/*/*/doc/ai/INDEX.json';
        foreach (glob($pattern) ?: [] as $indexPath) {
            if (!is_string($indexPath) || !is_file($indexPath)) {
                continue;
            }
            $json = Json::decode((string) file_get_contents($indexPath), []);
            if (!is_array($json)) {
                continue;
            }
            $module = trim((string) ($json['module'] ?? ''));
            foreach (is_array($json['skills'] ?? null) ? $json['skills'] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $path = trim((string) ($row['path'] ?? ''));
                if ($name === '' || $path === '') {
                    continue;
                }
                $absolute = $repository . '/' . ltrim($path, '/');
                $body = is_file($absolute) ? (string) file_get_contents($absolute) : '';
                $parsed = self::parseSkillMarkdown($body, $name);
                $skills[] = self::skillRow(
                    skillId: 'doc:' . $name,
                    name: $parsed['name'] !== '' ? $parsed['name'] : $name,
                    description: $parsed['description'] !== ''
                        ? $parsed['description']
                        : ('Module doc skill for ' . ($module !== '' ? $module : $name)),
                    aliases: [$name],
                    triggers: array_values(array_unique(array_filter([
                        $name,
                        $module,
                        ...$parsed['triggers'],
                    ]))),
                    module: $module,
                    relativePath: $path,
                    kind: self::KIND_DOC_FILE,
                    content: $body !== '' ? $body : self::locatorBody($module, $path, $name),
                    metaPath: trim((string) ($row['meta_path'] ?? '')),
                    status: trim((string) ($row['status'] ?? 'validated')),
                );
            }
        }

        return $skills;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function scanSkillMarkdownFiles(string $repository): array
    {
        $skills = [];
        $pattern = $repository . '/app/code/*/*/doc/ai/skills/*/SKILL.md';
        foreach (glob($pattern) ?: [] as $skillPath) {
            if (!is_string($skillPath) || !is_file($skillPath)) {
                continue;
            }
            $relative = self::relativePath($repository, $skillPath);
            $body = (string) file_get_contents($skillPath);
            $parsed = self::parseSkillMarkdown($body, basename(dirname($skillPath)));
            $name = $parsed['name'] !== '' ? $parsed['name'] : basename(dirname($skillPath));
            $module = self::moduleFromPath($relative);
            $skills[] = self::skillRow(
                skillId: 'doc:' . $name,
                name: $name,
                description: $parsed['description'] !== ''
                    ? $parsed['description']
                    : ('Module doc skill at ' . $relative),
                aliases: [$name],
                triggers: array_values(array_unique(array_filter([
                    $name,
                    $module,
                    ...$parsed['triggers'],
                ]))),
                module: $module,
                relativePath: $relative,
                kind: self::KIND_DOC_FILE,
                content: $body,
                metaPath: '',
                status: 'validated',
            );
        }

        return $skills;
    }

    /**
     * @param array<string, array<string, mixed>> $existing
     * @return list<array<string, mixed>>
     */
    private static function synthesizeAiIndexLocators(string $repository, array $existing): array
    {
        $skills = [];
        $pattern = $repository . '/app/code/*/*/doc/AI-INDEX.md';
        foreach (glob($pattern) ?: [] as $indexPath) {
            if (!is_string($indexPath) || !is_file($indexPath)) {
                continue;
            }
            $relative = self::relativePath($repository, $indexPath);
            $module = self::moduleFromPath($relative);
            if ($module === '') {
                continue;
            }
            $slug = strtolower(str_replace('_', '-', $module));
            $skillId = 'doc-index:' . $slug;
            // Skip if a file-backed skill already covers this module name.
            foreach ($existing as $row) {
                if (($row['module'] ?? '') === $module && ($row['kind'] ?? '') === self::KIND_DOC_FILE) {
                    continue 2;
                }
            }
            $body = (string) file_get_contents($indexPath);
            $skills[] = self::skillRow(
                skillId: $skillId,
                name: $module . ' AI-INDEX',
                description: 'Locator skill synthesized from ' . $relative . ' (MCP memory only; not a repository SKILL.md projection).',
                aliases: [$module, $slug, 'AI-INDEX:' . $module],
                triggers: [$module, 'AI-INDEX', basename(dirname(dirname($relative)))],
                module: $module,
                relativePath: $relative,
                kind: self::KIND_DOC_INDEX,
                content: self::locatorBody($module, $relative, $skillId) . "\n\n## AI-INDEX excerpt\n\n"
                    . mb_substr($body, 0, 4000, 'UTF-8'),
                metaPath: '',
                status: 'validated',
            );
        }

        return $skills;
    }

    /**
     * @param list<string> $aliases
     * @param list<string> $triggers
     * @return array<string, mixed>
     */
    private static function skillRow(
        string $skillId,
        string $name,
        string $description,
        array $aliases,
        array $triggers,
        string $module,
        string $relativePath,
        string $kind,
        string $content,
        string $metaPath,
        string $status,
    ): array {
        return [
            'skill_id' => $skillId,
            'name' => $name,
            'description' => $description,
            'aliases' => array_values(array_unique(array_filter($aliases))),
            'surface_id' => '',
            'triggers' => array_values(array_unique(array_filter($triggers))),
            'authoritative_docs' => $relativePath !== '' ? [$relativePath] : [],
            'norms' => [],
            'verification_commands' => [],
            'template_surface_rules' => [],
            'feature_delivery_urls' => null,
            'provider' => McpSkillCatalog::PROVIDER,
            'static_skill_files' => false,
            'kind' => $kind,
            'module' => $module,
            'relative_path' => $relativePath,
            'meta_path' => $metaPath,
            'status' => $status,
            'content_source' => $content,
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function mergeSkill(array $base, array $extra): array
    {
        $base['aliases'] = array_values(array_unique(array_merge(
            is_array($base['aliases'] ?? null) ? $base['aliases'] : [],
            is_array($extra['aliases'] ?? null) ? $extra['aliases'] : []
        )));
        $base['triggers'] = array_values(array_unique(array_merge(
            is_array($base['triggers'] ?? null) ? $base['triggers'] : [],
            is_array($extra['triggers'] ?? null) ? $extra['triggers'] : []
        )));
        if (($base['content_source'] ?? '') === '' && ($extra['content_source'] ?? '') !== '') {
            $base['content_source'] = $extra['content_source'];
        }
        if (($base['relative_path'] ?? '') === '' && ($extra['relative_path'] ?? '') !== '') {
            $base['relative_path'] = $extra['relative_path'];
        }

        return $base;
    }

    /**
     * @return array{name: string, description: string, triggers: list<string>}
     */
    private static function parseSkillMarkdown(string $body, string $fallbackName): array
    {
        $name = $fallbackName;
        $description = '';
        if (preg_match('/^---\s*\n(.*?)\n---\s*/s', $body, $match) === 1) {
            $front = $match[1];
            if (preg_match('/^name:\s*["\']?([^"\'\n]+)["\']?\s*$/mi', $front, $m) === 1) {
                $name = trim($m[1]);
            }
            if (preg_match('/^description:\s*["\'](.+?)["\']\s*$/mis', $front, $m) === 1) {
                $description = trim($m[1]);
            } elseif (preg_match('/^description:\s*>-?\s*\n((?:[ \t]+.*\n?)+)/mi', $front, $m) === 1) {
                $description = trim(preg_replace('/^[ \t]+/m', '', $m[1]) ?? '');
            } elseif (preg_match('/^description:\s*(.+)$/mi', $front, $m) === 1) {
                $description = trim($m[1], " \t\"'");
            }
        }

        return [
            'name' => $name,
            'description' => $description,
            'triggers' => self::extractTriggers($body),
        ];
    }

    /** @return list<string> */
    private static function extractTriggers(string $raw): array
    {
        $triggers = [];
        if (preg_match('/触发词[^\n]*\n((?:\s*-\s*.+\n?)+)/u', $raw, $match) === 1) {
            if (preg_match_all('/^\s*-\s*`?([^`\n]+)`?\s*$/mu', $match[1], $items) > 0) {
                foreach ($items[1] as $item) {
                    $item = trim((string) $item);
                    if ($item !== '') {
                        $triggers[] = $item;
                    }
                }
            }
        }

        return $triggers;
    }

    /**
     * Parse **硬触发** lines so Agents/MCP get machine-readable attachment triggers
     * (e.g. 审图: any image attachment), not only backtick trigger words.
     *
     * @return array{kind: string, summary: string, triggers: list<string>}|null
     */
    private static function extractHardTrigger(string $raw): ?array
    {
        if (preg_match('/\*\*硬触发\*\*[：:]\s*(.+)/u', $raw, $match) !== 1) {
            return null;
        }
        $summary = trim(preg_replace('/\*+/', '', (string) $match[1]) ?? '');
        if ($summary === '') {
            return null;
        }
        $kind = 'custom';
        $extraTriggers = [];
        if (preg_match('/图片|截图|附件|粘贴图|image|screenshot|attachment/iu', $summary) === 1) {
            $kind = 'image_attachment';
            $extraTriggers = [
                'image_attachment',
                'screenshot_attachment',
                '用户附图',
                '粘贴图',
                '截图附件',
            ];
        }

        return [
            'kind' => $kind,
            'summary' => mb_substr($summary, 0, 240, 'UTF-8'),
            'triggers' => $extraTriggers,
        ];
    }

    private static function commandId(string $relative, string $absolute): string
    {
        $base = basename($absolute, '.md');
        $slug = preg_replace('~[^\p{L}\p{N}._-]+~u', '-', $base) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '' || $slug === '-') {
            $slug = 'path-' . substr(sha1($relative), 0, 12);
        }

        return 'cmd:' . $slug;
    }

    private static function firstHeading(string $raw): string
    {
        if (preg_match('/^#\s+(.+)$/mu', $raw, $match) === 1) {
            return trim($match[1]);
        }

        return '';
    }

    private static function firstParagraph(string $raw): string
    {
        $stripped = preg_replace('/^---\s*\n.*?\n---\s*/s', '', $raw) ?? $raw;
        $stripped = preg_replace('/^#.*$/mu', '', $stripped) ?? $stripped;
        foreach (preg_split('/\n\s*\n/', trim($stripped)) ?: [] as $block) {
            $block = trim((string) $block);
            if ($block !== '' && !str_starts_with($block, '#')) {
                return mb_substr($block, 0, 240, 'UTF-8');
            }
        }

        return '';
    }

    private static function locatorBody(string $module, string $path, string $name): string
    {
        return "# {$name}\n\n"
            . "- provider: MCP\n"
            . "- kind: module doc locator\n"
            . "- module: `{$module}`\n"
            . "- path: `{$path}`\n"
            . "- static_skill_files: false（MCP 内存索引；不复活仓库 Skill 投影）\n\n"
            . "## 取用\n\n"
            . "1. `resolve_skill(task=…)` / `resolve_skill(list_all=true)`\n"
            . "2. `get_skill(skill_id=…)`\n"
            . "3. 任务文档仍用 `resolve_task_context`\n";
    }

    private static function moduleFromPath(string $relative): string
    {
        if (preg_match('~app/code/([^/]+)/([^/]+)/~', $relative, $match) === 1) {
            return $match[1] . '_' . $match[2];
        }

        return '';
    }

    private static function relativePath(string $repository, string $absolute): string
    {
        $repository = rtrim(str_replace('\\', '/', $repository), '/');
        $absolute = str_replace('\\', '/', $absolute);
        if (str_starts_with($absolute, $repository . '/')) {
            return substr($absolute, strlen($repository) + 1);
        }

        return $absolute;
    }

    private static function normalizeRoot(string $repository): string
    {
        $repository = trim($repository);
        if ($repository === '' || !is_dir($repository)) {
            return '';
        }

        return rtrim(str_replace('\\', '/', realpath($repository) ?: $repository), '/');
    }
}
