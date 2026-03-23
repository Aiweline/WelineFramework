<?php

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? [];
array_shift($arguments);

if ($arguments === []) {
    fwrite(STDERR, "Usage: php dev/ai/codex/scripts/init-task.php \"short title\" [--source=\"user request\"]\n");
    exit(1);
}

$title = trim((string) array_shift($arguments));
$source = 'user request';

foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--source=')) {
        $source = trim((string) substr($argument, 9));
    }
}

if ($title === '') {
    fwrite(STDERR, "Error: task title cannot be empty.\n");
    exit(1);
}

$codexRoot = realpath(__DIR__ . '/..');
if ($codexRoot === false) {
    fwrite(STDERR, "Error: unable to resolve dev/ai/codex root.\n");
    exit(1);
}

$timestamp = new DateTimeImmutable('now');
$date = $timestamp->format('Y-m-d');
$time = $timestamp->format('Hi');
$slug = createSlug($title);
$baseTaskId = "{$date}-{$time}-{$slug}";
$taskRoot = $codexRoot . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . $date;

ensureDirectory($taskRoot);

$taskId = ensureUniqueTaskId($taskRoot, $baseTaskId);
$taskDir = $taskRoot . DIRECTORY_SEPARATOR . $taskId;
ensureDirectory($taskDir);
ensureDirectory($taskDir . DIRECTORY_SEPARATOR . 'artifacts');

$replacements = [
    '{{TITLE}}' => $title,
    '{{TASK_ID}}' => $taskId,
    '{{STARTED_AT}}' => $timestamp->format('Y-m-d H:i'),
    '{{SOURCE}}' => $source,
];

$templateNames = ['task.md', 'plan.md', 'progress.md', 'result.md'];
foreach ($templateNames as $templateName) {
    $templatePath = $codexRoot . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . $templateName;
    $targetPath = $taskDir . DIRECTORY_SEPARATOR . $templateName;
    $template = file_get_contents($templatePath);
    if ($template === false) {
        fwrite(STDERR, "Error: unable to read template {$templatePath}.\n");
        exit(1);
    }

    $content = strtr($template, $replacements);
    if (file_put_contents($targetPath, $content) === false) {
        fwrite(STDERR, "Error: unable to write {$targetPath}.\n");
        exit(1);
    }
}

$artifactKeepPath = $taskDir . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . '.gitkeep';
if (file_put_contents($artifactKeepPath, '') === false) {
    fwrite(STDERR, "Error: unable to write {$artifactKeepPath}.\n");
    exit(1);
}

fwrite(STDOUT, $taskDir . PHP_EOL);

function createSlug(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (!is_string($ascii) || $ascii === '') {
        $ascii = $value;
    }

    $slug = strtolower($ascii);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : 'task';
}

function ensureUniqueTaskId(string $taskRoot, string $baseTaskId): string
{
    $candidate = $baseTaskId;
    $suffix = 2;

    while (is_dir($taskRoot . DIRECTORY_SEPARATOR . $candidate)) {
        $candidate = $baseTaskId . '-' . $suffix;
        $suffix++;
    }

    return $candidate;
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        fwrite(STDERR, "Error: unable to create directory {$path}.\n");
        exit(1);
    }
}
