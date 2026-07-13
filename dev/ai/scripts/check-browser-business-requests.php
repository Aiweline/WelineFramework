<?php

declare(strict_types=1);

/**
 * Finds browser-side business transports that bypass Weline.Api bin-query.
 *
 * The built-in weline-api worker and service workers are transport
 * infrastructure and are intentionally excluded. PHP ORM fetch() calls and
 * template include fetch() calls are also excluded.
 */
$root = dirname(__DIR__, 3);
$patterns = [
    '/\bfetch\s*\(/i',
    '/\bXMLHttpRequest\b/i',
    '/\$\.(?:ajax|get|post)\s*\(/i',
    '/\baxios(?:\.|\s*\()/i',
    '/\bEventSource\s*\(/i',
    '/\bWeline\.Api\.(?:request|get|post)\s*\(/i',
];
$extensions = ['phtml', 'js', 'ts', 'tsx', 'vue'];
$excluded = [
    '/app/code/Weline/Backend/view/statics/js/weline-api.js',
    '/app/code/Weline/Backend/view/statics/js/weline-api-worker.js',
];

$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/code', FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) {
        continue;
    }

    $path = str_replace($root, '', $file->getPathname());
    if (str_contains($path, '/view/tpl/') || str_contains($path, '/view/statics/libs/') || str_contains($path, '/Test/') || str_contains($path, '/test/') || in_array($path, $excluded, true)) {
        continue;
    }
    if (str_ends_with($path, '/sw.js') || str_contains($path, '/vendor/')) {
        continue;
    }

    $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        continue;
    }
    foreach ($lines as $lineNumber => $line) {
        if (preg_match('/(?:->|::|\$this\s*->)\s*fetch\s*\(/i', $line)) {
            continue;
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line)) {
                $violations[] = sprintf('%s:%d: %s', ltrim($path, '/'), $lineNumber + 1, trim($line));
                break;
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, implode(PHP_EOL, $violations) . PHP_EOL);
    fwrite(STDERR, sprintf('Found %d browser request violations. Use Weline.Api.resource(), graph(), or stream().%s', count($violations), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, "No browser business request violations found." . PHP_EOL);
