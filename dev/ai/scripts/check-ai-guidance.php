#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Static integrity gate for repository AI rules and skill discovery.
 *
 * This script deliberately has no Composer or YAML dependency. It validates the
 * small frontmatter contract used by SKILL.md files rather than implementing a
 * general YAML parser.
 */

$repositoryRoot = dirname(__DIR__, 3);
$errors = [];

/**
 * @return array{name: string, description: string, keys: list<string>, body: string}|null
 */
function parseSkill(string $path, array &$errors): ?array
{
    $content = file_get_contents($path);
    if ($content === false) {
        $errors[] = "Unable to read {$path}";
        return null;
    }

    if (!preg_match('/\A---\r?\n(.*?)\r?\n---(?:\r?\n|$)(.*)\z/su', $content, $matches)) {
        $errors[] = "Missing or malformed frontmatter: {$path}";
        return null;
    }

    $frontmatter = $matches[1];
    if (
        preg_match('/^description:\s*(.+)$/m', $frontmatter, $descriptionLine) === 1
        && preg_match('/^[>|\'"]/', ltrim($descriptionLine[1])) !== 1
        && preg_match('/(?:^|\s)#/', $descriptionLine[1]) === 1
    ) {
        $errors[] = "Plain YAML description contains a comment marker; quote it or use a block scalar: {$path}";
    }

    $keys = [];
    foreach (preg_split('/\r\n|\n|\r/', $frontmatter) ?: [] as $line) {
        if ($line === '' || preg_match('/^\s/', $line) === 1 || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):/', $line, $keyMatch) === 1) {
            $keys[] = $keyMatch[1];
        }
    }

    $readField = static function (string $field) use ($frontmatter): string {
        $lines = preg_split('/\r\n|\n|\r/', $frontmatter) ?: [];
        $collectingBlock = false;
        $parts = [];

        foreach ($lines as $line) {
            if (!$collectingBlock) {
                if (preg_match('/^' . preg_quote($field, '/') . ':\s*(.*)$/', $line, $fieldMatch) !== 1) {
                    continue;
                }

                $value = trim($fieldMatch[1], " \t\"'");
                if (in_array($value, ['|', '|-', '|+', '>', '>-', '>+'], true)) {
                    $collectingBlock = true;
                    continue;
                }
                return $value;
            }

            if ($line !== '' && preg_match('/^\S/', $line) === 1) {
                break;
            }
            $parts[] = trim($line);
        }

        return trim(implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')));
    };

    return [
        'name' => $readField('name'),
        'description' => $readField('description'),
        'keys' => $keys,
        'body' => $matches[2],
    ];
}

/**
 * @return list<string>
 */
function skillFiles(string $pattern): array
{
    $files = glob($pattern) ?: [];
    sort($files, SORT_STRING);
    return array_values($files);
}

/**
 * @param list<string> $files
 */
function checkExplicitMarkdownReferences(array $files, string $repositoryRoot, array &$errors): void
{
    $genericReferences = [
        'README.md',
        'SKILL.md',
        'AI-INDEX.md',
        'doc/AI-INDEX.md',
    ];

    foreach ($files as $path) {
        $content = file_get_contents($path);
        if ($content === false) {
            continue;
        }

        preg_match_all('/`([^`\n]+\.md)`|\]\(([^)\n]+\.md)(?:#[^)\n]*)?\)/u', $content, $matches, PREG_SET_ORDER);
        $references = [];
        foreach ($matches as $match) {
            $candidate = trim((string)($match[1] !== '' ? $match[1] : $match[2]));
            $references[$candidate] = true;
        }

        foreach (array_keys($references) as $reference) {
            if (
                in_array($reference, $genericReferences, true)
                || preg_match('/[<>{}*|]/u', $reference) === 1
                || str_contains($reference, '...')
                || preg_match('#^[a-z][a-z0-9+.-]*://#i', $reference) === 1
            ) {
                continue;
            }

            $reference = preg_replace('/:\d+$/', '', $reference) ?? $reference;
            $repositoryRelative = preg_match(
                '#^(?:dev/ai|app/code|\.codex|\.cursor|\.agents)/#',
                $reference
            ) === 1 || in_array($reference, ['AGENTS.md', 'AI-README.md', 'AI-ENTRY.md', 'CLAUDE.md'], true);
            $resolved = $repositoryRelative
                ? $repositoryRoot . '/' . $reference
                : dirname($path) . '/' . $reference;

            if (!is_file($resolved)) {
                $errors[] = "Broken repository Markdown reference in {$path}: {$reference}";
            }
        }
    }
}

$canonicalSkills = skillFiles($repositoryRoot . '/dev/ai/skills/*/SKILL.md');
$codexSkills = skillFiles($repositoryRoot . '/.codex/skills/*/SKILL.md');
$pluginSkills = skillFiles(
    $repositoryRoot . '/dev/ai/codex/plugins/weline-codex-plugin/skills/*/SKILL.md'
);

if ($canonicalSkills === []) {
    $errors[] = 'No canonical skills found under dev/ai/skills.';
}

$canonicalNames = [];
$canonicalDefinitions = [];
foreach ($canonicalSkills as $path) {
    $skill = parseSkill($path, $errors);
    if ($skill === null) {
        continue;
    }

    $relativePath = substr($path, strlen($repositoryRoot) + 1);
    $directoryName = basename(dirname($path));
    $keyCounts = array_count_values($skill['keys']);
    $unexpectedKeys = array_values(array_diff(array_keys($keyCounts), ['name', 'description']));

    if ($skill['name'] === '') {
        $errors[] = "Empty canonical skill name: {$relativePath}";
    } elseif ($skill['name'] !== $directoryName) {
        $errors[] = "Canonical skill name must match its directory: {$relativePath}";
    }
    if ($skill['description'] === '') {
        $errors[] = "Empty canonical skill description: {$relativePath}";
    }
    foreach (['name', 'description'] as $requiredKey) {
        if (($keyCounts[$requiredKey] ?? 0) !== 1) {
            $errors[] = "Canonical frontmatter must contain exactly one {$requiredKey}: {$relativePath}";
        }
    }
    if ($unexpectedKeys !== []) {
        $errors[] = "Canonical frontmatter may only contain name/description ({$relativePath}): "
            . implode(', ', $unexpectedKeys);
    }
    if (preg_match(
        '/^#{1,6}\s+(?:Role|When To Use|Shared Collaboration Contract|Responsibilities|Inputs Required|Expected Output|Weline Rules|Source Material|Constraints|何时使用|适用场景|触发条件)\s*$/mi',
        $skill['body']
    ) === 1) {
        $errors[] = "Generic trigger/role/template boilerplate must not live in canonical skill body: {$relativePath}";
    }

    if ($skill['name'] !== '') {
        if (isset($canonicalNames[$skill['name']])) {
            $errors[] = "Duplicate canonical skill name: {$skill['name']}";
        }
        $canonicalNames[$skill['name']] = $relativePath;
        $canonicalDefinitions[$skill['name']] = $skill;
    }
}

$directAdapterMap = [
    'deploy-release-system' => 'CI发布工程师-部署发布系统',
    'fencang-release' => 'CI发布工程师-分仓发布',
    'fenxiang-update' => 'CI发布工程师-分项更新',
    'framework-taglib-catalog' => 'framework-taglib-catalog',
    'payment-provider-development' => 'payment-provider-development',
    'planning' => 'planning',
    'queue' => 'queue',
    'system-config-scope' => 'system-config-scope',
    'unified-query-provider' => 'unified-query-provider',
];

$discoveredNames = [];
$discoveredDefinitions = [];
foreach (array_merge($codexSkills, $pluginSkills) as $path) {
    $skill = parseSkill($path, $errors);
    if ($skill === null) {
        continue;
    }

    $relativePath = substr($path, strlen($repositoryRoot) + 1);
    if ($skill['name'] === '') {
        $errors[] = "Empty discovered skill name: {$relativePath}";
        continue;
    }
    if ($skill['description'] === '') {
        $errors[] = "Empty discovered skill description: {$relativePath}";
    }
    $keyCounts = array_count_values($skill['keys']);
    foreach (['name', 'description'] as $requiredKey) {
        if (($keyCounts[$requiredKey] ?? 0) !== 1) {
            $errors[] = "Discovered frontmatter must contain exactly one {$requiredKey}: {$relativePath}";
        }
    }
    $discoveredNames[$skill['name']][] = $relativePath;
    $discoveredDefinitions[$skill['name']] = $skill;
}

foreach ($discoveredNames as $name => $paths) {
    if (count($paths) > 1) {
        $errors[] = "Skill is exposed by more than one discovery layer ({$name}): " . implode(', ', $paths);
    }
}

foreach ($canonicalNames as $name => $canonicalRelativePath) {
    if (isset($discoveredNames[$name])) {
        continue;
    }

    $aliases = array_keys(array_filter(
        $directAdapterMap,
        static fn (string $canonicalName): bool => $canonicalName === $name
    ));
    $hasAdapter = array_any(
        $aliases,
        static fn (string $alias): bool => isset($discoveredNames[$alias])
    );
    if (!$hasAdapter) {
        $errors[] = "Canonical skill has no explicit Codex/plugin discovery path: {$canonicalRelativePath}";
    }
}

foreach ($codexSkills as $path) {
    $relativePath = substr($path, strlen($repositoryRoot) + 1);
    $directoryName = basename(dirname($path));
    $canonicalName = $directAdapterMap[$directoryName] ?? null;
    if ($canonicalName === null || !isset($canonicalNames[$canonicalName])) {
        $errors[] = "Direct Codex skill is not mapped to one canonical owner: {$relativePath}";
        continue;
    }
    $content = file_get_contents($path) ?: '';
    $canonicalRelativePath = $canonicalNames[$canonicalName];
    if (!str_contains($content, $canonicalRelativePath)) {
        $errors[] = "Direct adapter does not reference its canonical owner ({$canonicalName}): {$relativePath}";
    }
    $bodyLineCount = substr_count(trim($discoveredDefinitions[$directoryName]['body'] ?? ''), "\n") + 1;
    if ($bodyLineCount > 12) {
        $errors[] = "Direct adapter body must stay thin ({$bodyLineCount} lines): {$relativePath}";
    }
    if (
        $directoryName === $canonicalName
        && ($discoveredDefinitions[$directoryName]['description'] ?? '')
            !== ($canonicalDefinitions[$canonicalName]['description'] ?? '')
    ) {
        $errors[] = "Exact-name direct adapter description drifted from canonical: {$relativePath}";
    }
}

foreach ($pluginSkills as $path) {
    $relativePath = substr($path, strlen($repositoryRoot) + 1);
    $directoryName = basename(dirname($path));
    if ($directoryName === 'weline-framework') {
        continue;
    }
    if (!isset($canonicalNames[$directoryName])) {
        $errors[] = "Plugin adapter has no same-name canonical owner: {$relativePath}";
        continue;
    }
    $content = file_get_contents($path) ?: '';
    if (!str_contains($content, $canonicalNames[$directoryName])) {
        $errors[] = "Plugin adapter does not reference its canonical owner: {$relativePath}";
    }
    if (
        ($discoveredDefinitions[$directoryName]['description'] ?? '')
        !== ($canonicalDefinitions[$directoryName]['description'] ?? '')
    ) {
        $errors[] = "Plugin adapter description drifted from canonical: {$relativePath}";
    }
}

$requiredRootGuidance = [
    $repositoryRoot . '/AGENTS.md',
    $repositoryRoot . '/AI-README.md',
    $repositoryRoot . '/AI-ENTRY.md',
    $repositoryRoot . '/CLAUDE.md',
    $repositoryRoot . '/.cursorrules',
    $repositoryRoot . '/dev/ai/AI-RULES-PACK.md',
    $repositoryRoot . '/dev/ai/PROJECT_QUALITY_SYSTEM.md',
    $repositoryRoot . '/dev/ai/AI-开发与测试指南.md',
    $repositoryRoot . '/dev/ai/codex/SOUL.md',
    $repositoryRoot . '/dev/ai/codex/USER.md',
    $repositoryRoot . '/dev/ai/codex/MEMORY.md',
    $repositoryRoot . '/dev/ai/global-constraints.md',
    $repositoryRoot . '/dev/ai/rules/README.md',
    $repositoryRoot . '/dev/ai/skills/_index.md',
];
$rootGuidance = array_merge(
    $requiredRootGuidance,
    glob($repositoryRoot . '/.cursor/rules/*.mdc') ?: []
);
foreach ($requiredRootGuidance as $path) {
    if (!is_file($path)) {
        $errors[] = 'Required guidance entry is missing: ' . substr($path, strlen($repositoryRoot) + 1);
    }
}

$pluginManifestPath = $repositoryRoot . '/dev/ai/codex/plugins/weline-codex-plugin/.codex-plugin/plugin.json';
$pluginManifest = is_file($pluginManifestPath)
    ? json_decode((string)file_get_contents($pluginManifestPath), true)
    : null;
if (
    !is_array($pluginManifest)
    || ($pluginManifest['name'] ?? null) !== 'weline-codex-plugin'
    || ($pluginManifest['skills'] ?? null) !== './skills/'
) {
    $errors[] = 'Invalid or missing Weline Codex plugin manifest.';
}

$activeGuidance = array_values(array_filter(
    array_merge($rootGuidance, $canonicalSkills, $codexSkills, $pluginSkills),
    'is_file'
));

$legacyActiveRules = glob($repositoryRoot . '/dev/ai/rules/*.mdc') ?: [];
foreach ($legacyActiveRules as $path) {
    $errors[] = 'Legacy dev/ai/rules/*.mdc must be archived or replaced by a conditional host adapter: '
        . substr($path, strlen($repositoryRoot) + 1);
}

$bannedText = [
    'admin/admin' => 'Repository guidance must not publish credentials.',
    'command:simpleBrowser.api.open' => 'Host-specific Cursor commands do not belong in shared guidance.',
    'cursor-app-control.open_resource' => 'Host-specific Cursor commands do not belong in shared guidance.',
    '默认尽可能开启 6' => 'Concurrency must be selected by task shape, not a fixed agent count.',
    '@Weline-技术主管' => 'Skills must use the active runtime collaboration model, not a fictional role mention.',
    'Technical Director' => 'Skills must report to the current task owner, not a fictional hierarchy.',
    'global-constraints.md §16' => 'Removed global section references must not return.',
    '必须有当前用户明确的测试产物授权' => 'Focused proportionate tests do not require a separate test-only request.',
];

foreach ($activeGuidance as $path) {
    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }
    foreach ($bannedText as $needle => $reason) {
        if (str_contains($content, $needle)) {
            $relativePath = substr($path, strlen($repositoryRoot) + 1);
            $errors[] = "{$reason} Found '{$needle}' in {$relativePath}";
        }
    }
}

checkExplicitMarkdownReferences(
    array_merge($rootGuidance, $canonicalSkills, $codexSkills, $pluginSkills),
    $repositoryRoot,
    $errors
);

if ($errors !== []) {
    fwrite(STDERR, "AI guidance check failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

printf(
    "AI guidance check passed: %d canonical skills, %d direct Codex skills, %d plugin skills, %d unique discovered names.\n",
    count($canonicalSkills),
    count($codexSkills),
    count($pluginSkills),
    count($discoveredNames)
);
