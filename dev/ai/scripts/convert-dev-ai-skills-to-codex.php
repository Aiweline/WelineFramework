<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$sourceDir = $root . '/dev/ai/skills';
$targetDir = $root . '/.codex/skills';

if (!is_dir($sourceDir)) {
    fwrite(STDERR, "Missing source dir: {$sourceDir}\n");
    exit(1);
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    fwrite(STDERR, "Cannot create target dir: {$targetDir}\n");
    exit(1);
}

$files = glob($sourceDir . '/*/SKILL.md') ?: [];
sort($files);

$created = 0;
$updated = 0;
$skipped = 0;

foreach ($files as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        fwrite(STDERR, "Cannot read: {$file}\n");
        $skipped++;
        continue;
    }

    $meta = parseFrontmatter($content);
    $name = $meta['name'] ?? basename(dirname($file));
    $description = $meta['description'] ?? '';

    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        fwrite(STDERR, "Invalid skill name {$name} from {$file}\n");
        $skipped++;
        continue;
    }

    $relativeSource = 'dev/ai/skills/' . $name . '/SKILL.md';
    $adapterDir = $targetDir . '/' . $name;
    $adapterFile = $adapterDir . '/SKILL.md';

    if (!is_dir($adapterDir) && !mkdir($adapterDir, 0775, true) && !is_dir($adapterDir)) {
        fwrite(STDERR, "Cannot create adapter dir: {$adapterDir}\n");
        $skipped++;
        continue;
    }

    $adapter = buildAdapter($name, $description, $relativeSource);
    $existed = is_file($adapterFile);
    $old = $existed ? file_get_contents($adapterFile) : null;

    if ($old === $adapter) {
        $skipped++;
        continue;
    }

    if (file_put_contents($adapterFile, $adapter) === false) {
        fwrite(STDERR, "Cannot write adapter: {$adapterFile}\n");
        $skipped++;
        continue;
    }

    $existed ? $updated++ : $created++;
}

echo "created={$created} updated={$updated} skipped={$skipped}\n";

function parseFrontmatter(string $content): array
{
    if (!str_starts_with($content, "---\n")) {
        return [];
    }

    $end = strpos($content, "\n---", 4);
    if ($end === false) {
        return [];
    }

    $frontmatter = substr($content, 4, $end - 4);
    $lines = preg_split('/\R/', $frontmatter) ?: [];
    $meta = [];
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        if (preg_match('/^name:\s*(.+)\s*$/', $line, $m)) {
            $meta['name'] = trim($m[1], " \t\n\r\0\x0B\"'");
            continue;
        }

        if (preg_match('/^description:\s*(.+)\s*$/', $line, $m)) {
            $value = trim($m[1]);
            if (in_array($value, ['>-', '|-', '>', '|'], true)) {
                $parts = [];
                for ($j = $i + 1; $j < $count; $j++) {
                    $next = $lines[$j];
                    if (preg_match('/^[a-zA-Z0-9_-]+:\s*/', $next)) {
                        break;
                    }
                    $trimmed = trim($next);
                    if ($trimmed !== '') {
                        $parts[] = $trimmed;
                    }
                }
                $meta['description'] = trim(implode(' ', $parts));
                continue;
            }

            $meta['description'] = trim($value, " \t\n\r\0\x0B\"'");
        }
    }

    return $meta;
}

function buildAdapter(string $name, string $description, string $relativeSource): string
{
    $description = yamlBlockText("WelineFramework local skill adapter for {$name}. Use when working in this repo on matching Weline, php bin/w, module, WLS, frontend, ORM, testing, routing, ACL, i18n, queue, SSE, theme, template, service, cache, config, debug, docs, command, PHP 8.4, or Codex task workspace topics. Read {$relativeSource} before acting.");

    return <<<MD
---
name: {$name}
description: >-
{$description}
---

# {$name}

This is a lightweight Codex adapter for the workspace-local WelineFramework skill.

## Required Workflow

1. Read `{$relativeSource}` completely before acting on matching tasks.
2. If that skill points to required references, read only the relevant referenced files.
3. Keep `dev/ai/skills` as the single source of truth; do not duplicate detailed rules here.
4. Also respect `dev/ai/global-constraints.md` for cross-skill hard constraints.

MD;
}

function normalizeSpaces(string $text): string
{
    $normalized = preg_replace('/\s+/u', ' ', $text);
    return trim($normalized ?? $text);
}

function yamlBlockText(string $text): string
{
    return '  ' . str_replace("\n", "\n  ", normalizeSpaces($text));
}
