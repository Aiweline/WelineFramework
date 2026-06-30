<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
$scanRoots = [
    $root . '/app/code',
    $root . '/app/design',
    $root . '/docs',
    $root . '/dev/ai',
];
$extensions = ['js' => true, 'phtml' => true, 'md' => true];
$patterns = [
    'fetch(',
    'XMLHttpRequest',
    '$.ajax',
    'axios',
    'EventSource(',
    'window.api(',
    'frontend_api(',
    '/api/rest',
    '/api/framework/query',
];
$skipDirs = ['.git', 'vendor', 'node_modules', 'var', 'generated', 'pub/static'];
$skipPathFragments = [
    '/view/statics/assets/libs/',
    '/view/statics/frontend/lib/',
    '/view/statics/lib/',
    '/view/statics/libs/',
    '/view/tpl/',
    '/tests/lib/',
    '/dev/ai/codex/tasks/',
    '/dev/ai/codex/artifacts/',
];
$defaultBaselinePath = $root . '/dev/ai/baselines/frontend-api-request-audit-baseline.json';
$baselinePath = $defaultBaselinePath;
$writeBaseline = false;
$failOnNew = false;
$jsonOutput = false;
$summaryOutput = false;

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $jsonOutput = true;
        continue;
    }
    if ($arg === '--summary') {
        $summaryOutput = true;
        continue;
    }
    if ($arg === '--fail-on-new') {
        $failOnNew = true;
        continue;
    }
    if ($arg === '--write-baseline') {
        $writeBaseline = true;
        continue;
    }
    if (\str_starts_with($arg, '--write-baseline=')) {
        $writeBaseline = true;
        $baselinePath = resolve_path($root, \substr($arg, \strlen('--write-baseline=')));
        continue;
    }
    if (\str_starts_with($arg, '--baseline=')) {
        $baselinePath = resolve_path($root, \substr($arg, \strlen('--baseline=')));
        continue;
    }
}

$hits = [];
foreach ($scanRoots as $scanRoot) {
    if (!is_dir($scanRoot)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file) use ($skipDirs, $skipPathFragments): bool {
                $path = str_replace('\\', '/', $file->getPathname());
                foreach ($skipDirs as $skipDir) {
                    if (str_contains($path, '/' . $skipDir . '/')) {
                        return false;
                    }
                }
                foreach ($skipPathFragments as $fragment) {
                    if (str_contains($path, $fragment)) {
                        return false;
                    }
                }
                return true;
            }
        )
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $extension = strtolower($file->getExtension());
        if (!isset($extensions[$extension])) {
            continue;
        }
        if ($extension === 'js' && str_ends_with(strtolower($file->getFilename()), '.min.js')) {
            continue;
        }

        $path = $file->getPathname();
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            continue;
        }

        $lines = preg_split('/\R/', $content) ?: [];
        foreach ($lines as $index => $line) {
            foreach ($patterns as $pattern) {
                if (stripos($line, $pattern) === false) {
                    continue;
                }

                $hits[] = [
                    'file' => str_replace('\\', '/', substr($path, strlen($root) + 1)),
                    'line' => $index + 1,
                    'pattern' => $pattern,
                    'classification' => classify_hit($path, $extension, $line, $pattern),
                    'snippet' => trim($line),
                ];
            }
        }
    }
}

foreach ($hits as &$hit) {
    $hit['fingerprint'] = fingerprint_hit($hit);
}
unset($hit);

if ($writeBaseline) {
    write_baseline($baselinePath, $hits);
}

if ($failOnNew) {
    fail_on_new_migrate_hits($baselinePath, $hits);
    if (!$jsonOutput && !$summaryOutput) {
        echo "Frontend API request audit gate passed.\n";
        exit(0);
    }
}

if ($summaryOutput) {
    echo json_encode(summarize_counts($hits), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    exit(0);
}

if ($jsonOutput) {
    echo json_encode($hits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
    exit(0);
}

echo "# Frontend API Request Surface Audit\n\n";
echo "| Classification | File | Line | Pattern | Snippet |\n";
echo "|---|---:|---:|---|---|\n";
foreach ($hits as $hit) {
    echo '| ' . esc($hit['classification'])
        . ' | ' . esc($hit['file'])
        . ' | ' . (int)$hit['line']
        . ' | `' . esc($hit['pattern']) . '`'
        . ' | ' . esc(shorten($hit['snippet'])) . " |\n";
}

function classify_hit(string $path, string $extension, string $line, string $pattern): string
{
    $normalized = str_replace('\\', '/', $path);
    $lower = strtolower($normalized);
    $lineLower = strtolower($line);

    if ($extension === 'md') {
        if (str_ends_with($lower, '/dev/ai/global-constraints.md')) {
            return 'docs_only';
        }
        if (str_contains($lineLower, 'oauth') || str_contains($lineLower, 'external')) {
            return 'external_api';
        }
        if (str_contains($lineLower, '->fetch') || str_contains($lineLower, '$this->fetch') || str_contains($lineLower, 'fetchhtml')) {
            return 'docs_only';
        }
        if (str_contains($lineLower, '禁止')
            || str_contains($lineLower, '不得')
            || str_contains($lineLower, '不允许')
            || str_contains($lineLower, 'ban')
            || str_contains($lineLower, 'must not')
            || str_contains($lineLower, 'deprecated')
        ) {
            return 'docs_only';
        }
        if (str_contains($lineLower, 'fetch(')
            || str_contains($lineLower, 'xmlhttprequest')
            || str_contains($lineLower, '$.ajax')
            || str_contains($lineLower, 'axios')
            || str_contains($lineLower, 'eventsource(')
            || str_contains($lineLower, 'window.api(')
            || str_contains($lineLower, 'frontend_api(')
            || str_contains($lineLower, '/api/rest')
            || str_contains($lineLower, '/api/framework/query')
        ) {
            return 'migrate_worker';
        }
        return 'docs_only';
    }

    if ($extension === 'php' || str_ends_with($lower, '.php') || str_contains($lineLower, '$this->fetch(') || str_contains($lineLower, '->fetch(')) {
        return 'server_side_php';
    }

    if (str_contains($lower, '/backend/') || str_contains($lower, 'app/code/weline/admin/') || str_contains($lineLower, 'api_admin') || str_contains($lineLower, 'backend-url')) {
        return 'backend_admin_browser';
    }

    if (str_contains($lineLower, 'eventsource(')) {
        return 'migrate_worker';
    }

    if (preg_match('/https?:\/\/|oauth|webhook|external/i', $line) === 1) {
        return 'external_api';
    }

    if (preg_match('/<a\s|<form\s/i', $line) === 1) {
        return 'allowed_navigation_or_form';
    }

    return 'migrate_worker';
}

function esc(string $value): string
{
    return str_replace('|', '\\|', $value);
}

function shorten(string $value): string
{
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    return mb_strlen($value) > 140 ? mb_substr($value, 0, 137) . '...' : $value;
}

/**
 * @param array{file:string,pattern:string,snippet:string} $hit
 */
function fingerprint_hit(array $hit): string
{
    $snippet = preg_replace('/\s+/', ' ', trim($hit['snippet'])) ?? trim($hit['snippet']);
    return sha1($hit['file'] . "\0" . $hit['pattern'] . "\0" . $snippet);
}

function resolve_path(string $root, string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') {
        return $root . '/dev/ai/baselines/frontend-api-request-audit-baseline.json';
    }
    if (preg_match('/^[A-Za-z]:\//', $path) === 1 || str_starts_with($path, '/')) {
        return $path;
    }
    return $root . '/' . $path;
}

/**
 * @param list<array<string, mixed>> $hits
 */
function write_baseline(string $baselinePath, array $hits): void
{
    $dir = dirname($baselinePath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Unable to create baseline directory: {$dir}\n");
        exit(2);
    }

    $migrateHits = array_values(array_filter(
        $hits,
        static fn(array $hit): bool => ($hit['classification'] ?? '') === 'migrate_worker'
    ));
    $baseline = [
        'version' => 1,
        'generated_at' => gmdate('c'),
        'rule' => 'fail-on-new migrate_worker fingerprints',
        'fingerprints' => array_values(array_unique(array_map(
            static fn(array $hit): string => (string)$hit['fingerprint'],
            $migrateHits
        ))),
        'counts' => summarize_counts($hits),
    ];

    file_put_contents(
        $baselinePath,
        json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL
    );
}

/**
 * @param list<array<string, mixed>> $hits
 */
function fail_on_new_migrate_hits(string $baselinePath, array $hits): void
{
    if (!is_file($baselinePath)) {
        fwrite(STDERR, "Missing frontend API request audit baseline: {$baselinePath}\n");
        exit(2);
    }

    $baseline = json_decode((string)file_get_contents($baselinePath), true);
    $allowed = array_fill_keys(array_map('strval', (array)($baseline['fingerprints'] ?? [])), true);
    $newHits = array_values(array_filter(
        $hits,
        static fn(array $hit): bool => ($hit['classification'] ?? '') === 'migrate_worker'
            && !isset($allowed[(string)($hit['fingerprint'] ?? '')])
    ));

    if ($newHits === []) {
        return;
    }

    fwrite(STDERR, "New direct frontend API request surfaces detected. Use Weline.Api.resource()/graph()/stream() instead.\n");
    foreach (\array_slice($newHits, 0, 50) as $hit) {
        fwrite(STDERR, sprintf(
            "- %s:%d [%s] %s\n",
            (string)$hit['file'],
            (int)$hit['line'],
            (string)$hit['pattern'],
            shorten((string)$hit['snippet'])
        ));
    }
    if (\count($newHits) > 50) {
        fwrite(STDERR, '- ... +' . (\count($newHits) - 50) . " more\n");
    }
    exit(1);
}

/**
 * @param list<array<string, mixed>> $hits
 * @return array<string, int>
 */
function summarize_counts(array $hits): array
{
    $counts = [];
    foreach ($hits as $hit) {
        $classification = (string)($hit['classification'] ?? 'unknown');
        $counts[$classification] = ($counts[$classification] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}
