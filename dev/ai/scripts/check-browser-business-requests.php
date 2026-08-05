<?php

declare(strict_types=1);

/**
 * Finds browser-side business transports that bypass Weline.Api bin-query.
 *
 * Transport infrastructure (weline-api*, Theme theme.js proxy), third-party libs,
 * tests, pre-bootstrap Installer UI (`/Installer/`), Visitor developer panel
 * (`weline-panel-visitor.js` — REST + DeveloperAccessPolicy, not backend Session),
 * and optional allowlist entries are excluded from true_violations.
 *
 * Usage:
 *   php dev/ai/scripts/check-browser-business-requests.php
 *   php dev/ai/scripts/check-browser-business-requests.php --module=Weline_Cdn
 */
require_once __DIR__ . '/BrowserBusinessRequestRules.php';

$root = dirname(__DIR__, 3);
$moduleFilter = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string)$arg, '--module=')) {
        $moduleFilter = substr((string)$arg, strlen('--module='));
    }
}

$patterns = [
    '/\bfetch\s*\(/i',
    '/\bnew\s+XMLHttpRequest\s*\(/i',
    '/\$\.(?:ajax|get|post)\s*\(/i',
    '/\baxios(?:\.|\s*\()/i',
    '/\b(?:new\s+)?EventSource\s*\(/i',
    '/\bWeline\.Api\.(?:request|get|post)\s*\(/i',
    // Backend pages must poll runtime_task.status; createStream is frontend-only.
    '/\.createStream\s*\(/i',
];

$extensions = ['phtml', 'js', 'ts', 'tsx', 'vue'];

$exactExcluded = [
    '/app/code/Weline/Backend/view/statics/js/weline-api.js',
    '/app/code/Weline/Backend/view/statics/js/weline-api-worker.js',
    '/app/code/Weline/Frontend/view/statics/js/weline-api.js',
    '/app/code/Weline/Frontend/view/statics/js/weline-api-worker.js',
    '/app/code/Weline/Theme/view/theme/backend/assets/js/theme.js',
];

$allowlistPath = $root . '/dev/ai/scripts/browser-business-request-allowlist.json';
$allowlist = [];
if (is_file($allowlistPath)) {
    $decoded = json_decode((string)file_get_contents($allowlistPath), true);
    if (is_array($decoded)) {
        $allowlist = $decoded;
    }
}

$rawHits = [];
$trueViolations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/code', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) {
        continue;
    }

    $path = str_replace('\\', '/', str_replace($root, '', $file->getPathname()));
    if ($moduleFilter !== null && $moduleFilter !== '') {
        $modulePath = '/app/code/' . str_replace('_', '/', $moduleFilter) . '/';
        if (str_contains($moduleFilter, '_')) {
            [$vendor, $module] = explode('_', $moduleFilter, 2);
            $modulePath = '/app/code/' . $vendor . '/' . $module . '/';
        }
        if (!str_starts_with($path, $modulePath)) {
            continue;
        }
    }

    if (BrowserBusinessRequestRules::isExcludedPath($path, $exactExcluded)) {
        continue;
    }

    $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }

    foreach ($lines as $lineNumber => $line) {
        if (preg_match('/(?:->|::|\$this\s*->|\bthis\s*\.)\s*fetch\s*\(/i', $line)) {
            continue;
        }
        if (BrowserBusinessRequestRules::isCommentOrDocLine($line)) {
            continue;
        }

        $matched = false;
        $matchedCreateStream = false;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                $matched = true;
                if (str_contains($pattern, 'createStream')) {
                    $matchedCreateStream = true;
                }
                break;
            }
        }
        if (!$matched) {
            continue;
        }
        // Frontend createStream is allowed; backend must poll runtime_task.status.
        if ($matchedCreateStream && !BrowserBusinessRequestRules::isBackendBusinessPath($path)) {
            continue;
        }

        $entry = sprintf('%s:%d: %s', ltrim($path, '/'), $lineNumber + 1, trim($line));
        $rawHits[] = $entry;

        if (BrowserBusinessRequestRules::isHeaderOnlyLine($line)) {
            continue;
        }
        if (BrowserBusinessRequestRules::isAllowlisted(ltrim($path, '/'), $lineNumber + 1, $allowlist)) {
            continue;
        }
        $trueViolations[] = $entry;
    }
}

if ($trueViolations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $trueViolations) . PHP_EOL);
    fwrite(STDERR, sprintf(
        'Found %d browser request violations (raw_hits=%d true_violations=%d). Use Weline.Api.resource()/graph(); backend long tasks poll runtime_task.status (no createStream/EventSource).%s',
        count($trueViolations),
        count($rawHits),
        count($trueViolations),
        PHP_EOL
    ));
    exit(1);
}

fwrite(STDOUT, sprintf(
    "No browser business request violations found. raw_hits=%d true_violations=0%s",
    count($rawHits),
    PHP_EOL
));
exit(0);
