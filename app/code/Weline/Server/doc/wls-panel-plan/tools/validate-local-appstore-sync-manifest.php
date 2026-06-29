<?php
declare(strict_types=1);

/**
 * Validates the local AppStore sync manifest before the passphrase-gated
 * DEV-to-App sync is allowed to run.
 *
 * The tool only reads markdown and prints a JSON result. It does not execute
 * sync commands, start WLS, read credentials, or write outside stdout.
 */

const WLS_PANEL_MANIFEST_EXIT_ASSERTION_FAILED = 1;

/**
 * @param array<int, string> $argv
 * @return array<string, string>
 */
function wlsPanelManifestParseArgs(array $argv): array
{
    $args = [];
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = (string)$argv[$i];
        if (!str_starts_with($arg, '--')) {
            continue;
        }

        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
            continue;
        }

        $next = $argv[$i + 1] ?? null;
        if (is_string($next) && !str_starts_with($next, '--')) {
            $args[$arg] = $next;
            $i++;
            continue;
        }

        $args[$arg] = '1';
    }

    return $args;
}

/**
 * @param array<string, mixed> $payload
 */
function wlsPanelManifestFinish(array $payload, int $exitCode): never
{
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($exitCode);
}

function wlsPanelManifestSection(string $content, string $startHeading, string $endHeading): string
{
    $start = strpos($content, $startHeading);
    if ($start === false) {
        return '';
    }

    $start += strlen($startHeading);
    $end = strpos($content, $endHeading, $start);
    if ($end === false) {
        $end = strlen($content);
    }

    return substr($content, $start, $end - $start);
}

/**
 * @return list<string>
 */
function wlsPanelManifestTextBlockPaths(string $section): array
{
    $paths = [];
    $matchCount = preg_match_all('/```(?:text|powershell)?\s*(.*?)```/s', $section, $matches);
    if ($matchCount === false || $matchCount === 0) {
        return [];
    }

    foreach ($matches[1] as $block) {
        foreach (preg_split('/\R/', (string)$block) ?: [] as $line) {
            $line = trim((string)$line, " \t\r\n',");
            if (
                str_starts_with($line, 'app/')
                || str_starts_with($line, 'generated/')
                || str_starts_with($line, 'var/')
                || str_starts_with($line, 'vendor/')
            ) {
                $paths[$line] = true;
            }
        }
    }

    return array_keys($paths);
}

/**
 * @return list<string>
 */
function wlsPanelManifestQuotedValues(string $section): array
{
    $matchCount = preg_match_all("/'([^']+)'/", $section, $matches);
    if ($matchCount === false || $matchCount === 0) {
        return [];
    }

    $values = [];
    foreach ($matches[1] as $value) {
        $values[] = (string)$value;
    }

    return $values;
}

/**
 * @return list<string>
 */
function wlsPanelManifestHardcodedOutOfScopeFingerprints(string $content): array
{
    $matchCount = preg_match_all('/out_of_scope_fingerprint=([0-9a-f]{16})\b/i', $content, $matches);
    if ($matchCount === false || $matchCount === 0) {
        return [];
    }

    $fingerprints = [];
    foreach ($matches[1] as $fingerprint) {
        $fingerprints[strtolower((string)$fingerprint)] = true;
    }

    return array_keys($fingerprints);
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function wlsPanelManifestNormalizePaths(array $paths): array
{
    $normalized = [];
    foreach ($paths as $path) {
        $path = str_replace('\\', '/', trim($path));
        if ($path !== '') {
            $normalized[$path] = true;
        }
    }

    return array_keys($normalized);
}

function wlsPanelManifestIsForbidden(string $path, array $forbiddenPaths): bool
{
    foreach ($forbiddenPaths as $forbidden) {
        $forbidden = str_replace('\\', '/', trim((string)$forbidden));
        if ($forbidden === '') {
            continue;
        }

        if (str_ends_with($forbidden, '/')) {
            if (str_starts_with($path, $forbidden)) {
                return true;
            }
            continue;
        }

        if ($path === $forbidden) {
            return true;
        }
    }

    return false;
}

function wlsPanelManifestIsBroadInclude(string $path): bool
{
    $broadIncludes = [
        'app/code/Weline/AppStore',
        'app/code/Weline/Deploy',
        'app/code/Weline/Server',
        'app/code/Weline/Server/doc/wls-panel-plan',
        'app/code/Weline/Framework',
        'app/code/Weline/Admin',
        'app/code/Weline',
        'app/code',
        'app',
    ];

    return in_array(rtrim($path, '/'), $broadIncludes, true);
}

function wlsPanelManifestNormalizeStatusPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if (str_contains($path, ' -> ')) {
        $parts = explode(' -> ', $path);
        $path = trim((string)end($parts));
    }

    return trim($path, "\"' ");
}

/**
 * @return array{status:string,path:string}|null
 */
function wlsPanelManifestParseGitStatusLine(string $line): ?array
{
    $line = rtrim($line, "\r\n");
    if ($line === '') {
        return null;
    }

    if (preg_match('/^(.{2})\s(.+)$/', $line, $matches) === 1) {
        return [
            'status' => trim((string)$matches[1]),
            'path' => wlsPanelManifestNormalizeStatusPath((string)$matches[2]),
        ];
    }

    if (preg_match('/^(\S+)\s+(.+)$/', $line, $matches) === 1) {
        return [
            'status' => trim((string)$matches[1]),
            'path' => wlsPanelManifestNormalizeStatusPath((string)$matches[2]),
        ];
    }

    return null;
}

/**
 * @param list<string> $lines
 * @param array<string, bool> $allowedLookup
 * @return array<string, mixed>
 */
function wlsPanelManifestParseGitStatusLines(array $lines, array $allowedLookup): array
{
    $rows = [];
    $outOfScopeRows = [];
    $allowedStatusCount = 0;

    foreach ($lines as $line) {
        $parsedLine = wlsPanelManifestParseGitStatusLine((string)$line);
        $status = (string)($parsedLine['status'] ?? '');
        $path = (string)($parsedLine['path'] ?? '');
        if ($path === '') {
            continue;
        }

        $allowed = isset($allowedLookup[$path]);
        $row = [
            'status' => $status,
            'path' => $path,
            'allowed_sync_path' => $allowed,
        ];
        $rows[] = $row;

        if ($allowed) {
            $allowedStatusCount++;
        } else {
            $outOfScopeRows[] = $row;
        }
    }

    $fingerprintPayload = array_map(
        static fn(array $row): array => [
            'status' => (string)$row['status'],
            'path' => (string)$row['path'],
        ],
        $outOfScopeRows
    );

    return [
        'parsed' => true,
        'total_status_count' => count($rows),
        'allowed_status_count' => $allowedStatusCount,
        'out_of_scope_status_count' => count($outOfScopeRows),
        'out_of_scope_fingerprint' => substr(hash('sha256', json_encode($fingerprintPayload, JSON_UNESCAPED_SLASHES)), 0, 16),
        'rows' => $rows,
        'out_of_scope_rows' => $outOfScopeRows,
    ];
}

function wlsPanelManifestPath(string $base, string $relative): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * @return list<string>
 */
function wlsPanelManifestGitCandidates(): array
{
    $candidates = [];
    $envGit = getenv('WLS_PANEL_GIT_BIN');
    if (is_string($envGit) && trim($envGit) !== '') {
        $candidates[] = trim($envGit);
    }

    $candidates[] = 'git';

    foreach ([
        'C:\Program Files\Git\cmd\git.exe',
        'C:\Program Files\Git\bin\git.exe',
        'C:\Program Files (x86)\Git\cmd\git.exe',
        'C:\Program Files (x86)\Git\bin\git.exe',
    ] as $path) {
        if (is_file($path)) {
            $candidates[] = $path;
        }
    }

    return array_values(array_unique($candidates));
}

/**
 * @return array{exit_code:int, command:string, lines:list<string>, attempts:list<array{command:string,exit_code:int}>}
 */
function wlsPanelManifestRunGitStatus(string $appRoot): array
{
    $attempts = [];
    $lastLines = [];
    $lastCommand = [];
    $lastExitCode = 1;

    foreach (wlsPanelManifestGitCandidates() as $git) {
        $command = [$git, '-C', $appRoot, 'status', '--short', '--untracked-files=all'];
        $process = @proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            $attempts[] = [
                'command' => implode(' ', $command),
                'exit_code' => 127,
            ];
            continue;
        }

        $stdout = isset($pipes[1]) ? (string)stream_get_contents($pipes[1]) : '';
        $stderr = isset($pipes[2]) ? (string)stream_get_contents($pipes[2]) : '';
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exitCode = proc_close($process);
        $lines = preg_split('/\R/', rtrim($stdout . ($stderr === '' ? '' : PHP_EOL . $stderr), "\r\n")) ?: [];
        $lines = array_values(array_filter(array_map('strval', $lines), static fn(string $line): bool => $line !== ''));

        $attempts[] = [
            'command' => implode(' ', $command),
            'exit_code' => $exitCode,
        ];

        $lastCommand = $command;
        $lastExitCode = $exitCode;
        $lastLines = $lines;
        if ($exitCode === 0) {
            break;
        }
    }

    return [
        'exit_code' => $lastExitCode,
        'command' => implode(' ', $lastCommand),
        'lines' => $lastLines,
        'attempts' => $attempts,
    ];
}

function wlsPanelManifestFileHash(string $path): ?string
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $hash = hash_file('sha256', $path);
    return is_string($hash) ? $hash : null;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function wlsPanelManifestDriftFingerprint(array $rows): string
{
    $fingerprintRows = [];
    foreach ($rows as $row) {
        $fingerprintRows[] = [
            'path' => (string)($row['path'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'dev_hash' => $row['dev_hash'] ?? null,
            'app_hash' => $row['app_hash'] ?? null,
        ];
    }

    return substr(hash('sha256', json_encode($fingerprintRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
}

/**
 * @param list<string> $paths
 * @return array<string, mixed>
 */
function wlsPanelManifestDriftReport(string $workspaceRoot, string $appRoot, array $paths, bool $summaryOnly): array
{
    $rows = [];
    $fingerprintRows = [];
    $counts = [
        'same' => 0,
        'different' => 0,
        'missing_app' => 0,
        'missing_dev' => 0,
        'missing_both' => 0,
    ];

    foreach ($paths as $path) {
        $devPath = wlsPanelManifestPath($workspaceRoot, $path);
        $appPath = wlsPanelManifestPath($appRoot, $path);
        $devHash = wlsPanelManifestFileHash($devPath);
        $appHash = wlsPanelManifestFileHash($appPath);

        if ($devHash !== null && $appHash !== null) {
            $status = hash_equals($devHash, $appHash) ? 'same' : 'different';
        } elseif ($devHash !== null) {
            $status = 'missing_app';
        } elseif ($appHash !== null) {
            $status = 'missing_dev';
        } else {
            $status = 'missing_both';
        }

        $counts[$status]++;
        $row = [
            'path' => $path,
            'status' => $status,
            'dev_hash' => $devHash === null ? null : substr($devHash, 0, 16),
            'app_hash' => $appHash === null ? null : substr($appHash, 0, 16),
        ];
        $fingerprintRows[] = $row;
        if (!$summaryOnly) {
            $rows[] = $row;
        }
    }

    $report = [
        'workspace_root' => $workspaceRoot,
        'app_root' => $appRoot,
        'total' => count($paths),
        'counts' => $counts,
        'summary_only' => $summaryOnly,
        'review_fingerprint' => wlsPanelManifestDriftFingerprint($fingerprintRows),
        'side_effects' => 'read-only drift report: hashes allowed manifest paths only; no sync, no setup, no WLS start, no writes',
    ];
    if ($summaryOnly) {
        $report['rows_omitted'] = count($paths);
    } else {
        $report['rows'] = $rows;
    }

    return $report;
}

/**
 * @param list<string> $allowedPaths
 * @return array<string, mixed>
 */
function wlsPanelManifestRollbackReview(string $appRoot, array $allowedPaths): array
{
    $allowedLookup = array_fill_keys($allowedPaths, true);
    $gitStatus = wlsPanelManifestRunGitStatus($appRoot);

    $status = $gitStatus['exit_code'] === 0
        ? wlsPanelManifestParseGitStatusLines($gitStatus['lines'], $allowedLookup)
        : [
            'parsed' => false,
            'total_status_count' => 0,
            'allowed_status_count' => 0,
            'out_of_scope_status_count' => 0,
            'out_of_scope_fingerprint' => null,
            'rows' => [],
            'out_of_scope_rows' => [],
            'raw_output' => $gitStatus['lines'],
        ];
    $status['exit_code'] = $gitStatus['exit_code'];
    $status['command'] = $gitStatus['command'];
    $status['attempts'] = $gitStatus['attempts'];

    return [
        'app_root' => $appRoot,
        'app_git_status' => $status,
        'pre_sync_snapshot_command' => 'git -C ' . $appRoot . ' status --short --untracked-files=all',
        'post_sync_compare_rule' => 'Run this rollback review before and after scoped 分项 sync; the out_of_scope_fingerprint should stay unchanged unless the operator intentionally changed unrelated App checkout files.',
        'recovery_rule' => 'Do not reset the App checkout. Preserve unrelated out-of-scope rows, capture the failing command, and revert only reviewed authorized-path changes manually if needed.',
        'side_effects' => 'read-only rollback review: reads App checkout git status only; no sync, no setup, no WLS start, no writes',
    ];
}

$args = wlsPanelManifestParseArgs($argv);

if ((string)($args['self-test'] ?? '0') === '1') {
    $allowedLookup = array_fill_keys([
        'app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php',
        'app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md',
    ], true);
    $status = wlsPanelManifestParseGitStatusLines([
        ' M app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php',
        '?? app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php',
        'R  old/path.php -> app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md',
        '',
    ], $allowedLookup);
    $expectedOutOfScopeFingerprint = substr(hash('sha256', json_encode([
        [
            'status' => '??',
            'path' => 'app/code/Weline/Admin/Service/BackendLoginReturnUrlService.php',
        ],
    ], JSON_UNESCAPED_SLASHES)), 0, 16);
    $driftRows = [
        [
            'path' => 'app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php',
            'status' => 'different',
            'dev_hash' => '1111111111111111',
            'app_hash' => '2222222222222222',
        ],
        [
            'path' => 'app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md',
            'status' => 'missing_app',
            'dev_hash' => '3333333333333333',
            'app_hash' => null,
        ],
    ];
    $changedDriftRows = $driftRows;
    $changedDriftRows[0]['app_hash'] = '4444444444444444';

    $cases = [
        [
            'name' => 'rollback_review_counts_allowed_and_out_of_scope_rows',
            'expected' => true,
            'actual' => ($status['total_status_count'] ?? null) === 3
                && ($status['allowed_status_count'] ?? null) === 2
                && ($status['out_of_scope_status_count'] ?? null) === 1,
        ],
        [
            'name' => 'rollback_review_rename_uses_target_path',
            'expected' => 'app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md',
            'actual' => $status['rows'][2]['path'] ?? null,
        ],
        [
            'name' => 'rollback_review_out_of_scope_fingerprint_is_stable',
            'expected' => $expectedOutOfScopeFingerprint,
            'actual' => $status['out_of_scope_fingerprint'] ?? null,
        ],
        [
            'name' => 'git_status_index_modified_path_preserves_app_prefix',
            'expected' => 'app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php',
            'actual' => wlsPanelManifestParseGitStatusLine('M  app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php')['path'] ?? null,
        ],
        [
            'name' => 'git_status_legacy_trimmed_path_preserves_app_prefix',
            'expected' => 'app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php',
            'actual' => wlsPanelManifestParseGitStatusLine('M app/code/Weline/AppStore/Service/AppStorePlatformUrlResolver.php')['path'] ?? null,
        ],
        [
            'name' => 'drift_review_fingerprint_stable_for_same_rows',
            'expected' => wlsPanelManifestDriftFingerprint($driftRows),
            'actual' => wlsPanelManifestDriftFingerprint($driftRows),
        ],
        [
            'name' => 'drift_review_fingerprint_changes_when_hash_changes',
            'expected' => true,
            'actual' => wlsPanelManifestDriftFingerprint($driftRows) !== wlsPanelManifestDriftFingerprint($changedDriftRows),
        ],
        [
            'name' => 'forbidden_directory_prefix_is_detected',
            'expected' => true,
            'actual' => wlsPanelManifestIsForbidden('generated/code.php', ['generated/']),
        ],
        [
            'name' => 'broad_include_is_rejected',
            'expected' => true,
            'actual' => wlsPanelManifestIsBroadInclude('app/code/Weline/Server/doc/wls-panel-plan'),
        ],
        [
            'name' => 'specific_include_is_not_broad',
            'expected' => false,
            'actual' => wlsPanelManifestIsBroadInclude('app/code/Weline/Server/doc/wls-panel-plan/92-local-appstore-sync-manifest.md'),
        ],
        [
            'name' => 'hardcoded_out_of_scope_fingerprint_is_detected',
            'expected' => ['0863c8ebd5abef29'],
            'actual' => wlsPanelManifestHardcodedOutOfScopeFingerprints(
                'rollback review: out_of_scope_fingerprint=0863c8ebd5abef29'
            ),
        ],
        [
            'name' => 'out_of_scope_fingerprint_field_name_without_value_is_allowed',
            'expected' => [],
            'actual' => wlsPanelManifestHardcodedOutOfScopeFingerprints(
                'Capture the current `out_of_scope_fingerprint` before sync.'
            ),
        ],
    ];

    $passed = true;
    foreach ($cases as &$case) {
        $case['case_ok'] = $case['actual'] === $case['expected'];
        $passed = $passed && $case['case_ok'];
    }
    unset($case);

    wlsPanelManifestFinish([
        'passed' => $passed,
        'self_test' => true,
        'cases' => $cases,
        'side_effects' => 'in-memory self-test: no App checkout read, no git process, no sync, no setup, no WLS start, no writes',
    ], $passed ? 0 : WLS_PANEL_MANIFEST_EXIT_ASSERTION_FAILED);
}

$defaultManifest = dirname(__DIR__) . DIRECTORY_SEPARATOR . '92-local-appstore-sync-manifest.md';
$manifest = trim((string)($args['manifest'] ?? $defaultManifest));
$failOnDrift = (string)($args['fail-on-drift'] ?? '0') === '1';
$withDrift = (string)($args['with-drift'] ?? '0') === '1' || $failOnDrift;
$driftSummaryOnly = (string)($args['drift-summary-only'] ?? '0') === '1';
$rollbackReviewRequested = (string)($args['rollback-review'] ?? '0') === '1';
$workspaceRoot = trim((string)($args['workspace-root'] ?? dirname(__DIR__, 7)));
$appRoot = trim((string)($args['app-root'] ?? 'E:\WelineFramework\Framework-Official\App\weline'));

$errors = [];
$warnings = [];
$checks = [];

if ($manifest === '' || !is_file($manifest) || !is_readable($manifest)) {
    wlsPanelManifestFinish([
        'ok' => false,
        'manifest' => $manifest,
        'errors' => ['manifest_unreadable'],
    ], WLS_PANEL_MANIFEST_EXIT_ASSERTION_FAILED);
}

$content = (string)file_get_contents($manifest);
$requiredText = [
    'local_checkout' => 'E:\WelineFramework\Framework-Official\App\weline',
    'local_url' => 'https://app.weline.test:9523',
    'production_url' => 'https://app.aiweline.com',
    'not_marketplace_local_www' => 'www.weline.test:9518',
    'not_marketplace_prod_www' => 'www.aiweline.com',
];

foreach ($requiredText as $label => $needle) {
    $passed = str_contains($content, $needle);
    $checks[$label] = $passed;
    if (!$passed) {
        $errors[] = 'missing_required_text:' . $label;
    }
}

$allowedSection = wlsPanelManifestSection($content, '## Allowed Sync Paths', '## Forbidden Sync Scope');
$forbiddenSection = wlsPanelManifestSection($content, '## Forbidden Sync Scope', '## Authorized Command Shape');
$commandSection = wlsPanelManifestSection($content, '## Authorized Command Shape', '## Post-Sync App Checkout Validation');

$allowedPaths = wlsPanelManifestNormalizePaths(wlsPanelManifestTextBlockPaths($allowedSection));
$forbiddenPaths = wlsPanelManifestNormalizePaths(wlsPanelManifestTextBlockPaths($forbiddenSection));
$quotedValues = wlsPanelManifestQuotedValues($commandSection);
$includePaths = [];
$sites = [];

foreach ($quotedValues as $value) {
    if (str_starts_with($value, 'app/')) {
        $includePaths[] = str_replace('\\', '/', $value);
        continue;
    }

    if (preg_match('/^[A-Z]:\\\\/i', $value) === 1) {
        $sites[] = $value;
    }
}

$includePaths = wlsPanelManifestNormalizePaths($includePaths);
$sites = array_values(array_unique($sites));
$allowedLookup = array_fill_keys($allowedPaths, true);
$includeLookup = array_fill_keys($includePaths, true);
$requiredSyncPaths = [
    'app/code/Weline/Server/doc/wls-panel-plan/tools/local-appstore-typed-tag-live-gate.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/production-appstore-typed-tag-live-gate.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-endpoint-source-contract.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-appstore-live-e2e-evidence.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/validate-final-workorder-deferred-actions.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-final-workorder.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-goal-completion-gate.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-authorization-pack.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-e2e-capture.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-live-evidence-final-gate.php',
    'app/code/Weline/Server/doc/wls-panel-plan/tools/wls-panel-workorder-authorization-consistency.php',
];

if ($allowedPaths === []) {
    $errors[] = 'allowed_paths_empty';
}

if ($includePaths === []) {
    $errors[] = 'authorized_command_include_paths_empty';
}

foreach ($includePaths as $path) {
    if (!isset($allowedLookup[$path])) {
        $errors[] = 'include_path_not_allowed:' . $path;
    }
    if (wlsPanelManifestIsForbidden($path, $forbiddenPaths)) {
        $errors[] = 'include_path_forbidden:' . $path;
    }
    if (wlsPanelManifestIsBroadInclude($path)) {
        $errors[] = 'include_path_too_broad:' . $path;
    }
}

foreach ($allowedPaths as $path) {
    if (!isset($includeLookup[$path])) {
        $errors[] = 'allowed_path_missing_from_command:' . $path;
    }
}

foreach ($requiredSyncPaths as $path) {
    if (!isset($allowedLookup[$path])) {
        $errors[] = 'required_sync_path_missing_from_allowed:' . $path;
    }
    if (!isset($includeLookup[$path])) {
        $errors[] = 'required_sync_path_missing_from_command:' . $path;
    }
}

if (count($sites) !== 1 || ($sites[0] ?? '') !== 'E:\WelineFramework\Framework-Official\App') {
    $errors[] = 'authorized_command_site_not_app_only';
}

if (!str_contains($commandSection, '-DryRun')) {
    $errors[] = 'authorized_command_missing_dry_run';
}

foreach (['www.weline.test', 'www.aiweline.com'] as $wrongHost) {
    if (str_contains($commandSection, $wrongHost)) {
        $errors[] = 'authorized_command_uses_non_marketplace_host:' . $wrongHost;
    }
}

if (!str_contains($content, 'var/deploy/current.json')) {
    $errors[] = 'missing_deploy_current_json_policy';
}

if (!str_contains($content, 'appstore_environment') || !str_contains($content, 'appstore_platform_url')) {
    $errors[] = 'missing_appstore_deploy_metadata_policy';
}

$hardcodedFingerprints = wlsPanelManifestHardcodedOutOfScopeFingerprints($content);
$checks['no_hardcoded_out_of_scope_fingerprint'] = $hardcodedFingerprints === [];
foreach ($hardcodedFingerprints as $fingerprint) {
    $errors[] = 'hardcoded_out_of_scope_fingerprint:' . $fingerprint;
}

if (count($includePaths) > 60) {
    $warnings[] = 'include_path_count_high:' . count($includePaths);
}

$drift = null;
if ($withDrift) {
    $drift = wlsPanelManifestDriftReport($workspaceRoot, $appRoot, $allowedPaths, $driftSummaryOnly);
    $driftCounts = is_array($drift['counts'] ?? null) ? $drift['counts'] : [];
    $drifted = (int)($driftCounts['different'] ?? 0)
        + (int)($driftCounts['missing_app'] ?? 0)
        + (int)($driftCounts['missing_dev'] ?? 0)
        + (int)($driftCounts['missing_both'] ?? 0);
    $drift['drifted_count'] = $drifted;
    $drift['gate_mode'] = $failOnDrift ? 'fail-on-drift' : 'report-only';
    if ($drifted > 0) {
        $message = 'app_checkout_drift_detected:' . $drifted;
        if ($failOnDrift) {
            $errors[] = $message;
        } else {
            $warnings[] = $message;
        }
    }
}

$ok = $errors === [];
$payload = [
    'ok' => $ok,
    'manifest' => $manifest,
    'site_targets' => $sites,
    'allowed_path_count' => count($allowedPaths),
    'include_path_count' => count($includePaths),
    'required_sync_path_count' => count($requiredSyncPaths),
    'forbidden_path_count' => count($forbiddenPaths),
    'checks' => $checks,
    'errors' => $errors,
    'warnings' => $warnings,
    'fail_on_drift' => $failOnDrift,
];

if ($drift !== null) {
    $payload['drift'] = $drift;
}

if ($rollbackReviewRequested) {
    $payload['rollback_review'] = wlsPanelManifestRollbackReview($appRoot, $allowedPaths);
}

wlsPanelManifestFinish($payload, $ok ? 0 : WLS_PANEL_MANIFEST_EXIT_ASSERTION_FAILED);
