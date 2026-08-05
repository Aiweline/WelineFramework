<?php

declare(strict_types=1);

/**
 * Pure rules for browser business-request gate scanning.
 * Kept next to check-browser-business-requests.php for shared CLI + PHPUnit use.
 */
final class BrowserBusinessRequestRules
{
    /**
     * @param list<string> $exactExcluded
     */
    public static function isExcludedPath(string $path, array $exactExcluded): bool
    {
        if (\in_array($path, $exactExcluded, true)) {
            return true;
        }
        $needles = [
            '/view/tpl/',
            '/view/statics/libs/',
            '/assets/libs/',
            '/ThemeFancy/view/statics/assets/js/',
            '/Admin/view/statics/assets/js/pages/',
            '/Admin/view/statics/assets/js/app.js',
            '/lib/requirejs/',
            '/CKEditorEditorManager/view/statics/build/',
            '/ElFinderFileManager/view/statics/js/proxy/',
            '/view/tpl/',
            '/Installer/',
            '/Visitor/view/statics/js/weline-panel-visitor.js',
            '/Test/',
            '/test/',
            '/vendor/',
            '/tinymce/',
        ];
        foreach ($needles as $needle) {
            if (\str_contains($path, $needle)) {
                return true;
            }
        }
        if (\str_ends_with($path, '/sw.js')) {
            return true;
        }
        if (\str_contains($path, '/ElFinderFileManager/view/statics/js/elfinder')) {
            return true;
        }
        if (\str_contains($path, '/ElFinderFileManager/view/statics/jquery')) {
            return true;
        }
        if (\str_ends_with($path, '/weline-api.js') || \str_ends_with($path, '/weline-api-worker.js')) {
            return true;
        }

        return false;
    }

    /**
     * Backend business templates must not call createStream (poll runtime_task.status).
     */
    public static function isBackendBusinessPath(string $path): bool
    {
        if (\str_contains($path, '/Frontend/') || \str_contains($path, '/frontend/')) {
            return false;
        }

        return \str_contains($path, '/Backend/')
            || \str_contains($path, '/backend/')
            || \str_contains($path, '/templates/backend/')
            || \str_contains($path, '/view/templates/Admin/');
    }

    public static function isCommentOrDocLine(string $line): bool
    {
        $trimmed = \ltrim($line);
        if ($trimmed === '') {
            return true;
        }
        if (\str_starts_with($trimmed, '//')
            || \str_starts_with($trimmed, '*')
            || \str_starts_with($trimmed, '/*')
            || \str_starts_with($trimmed, '#')
        ) {
            return true;
        }
        if (\str_starts_with($trimmed, '<!--')) {
            return true;
        }

        return false;
    }

    public static function isHeaderOnlyLine(string $line): bool
    {
        $trimmed = \trim($line);
        if ($trimmed === '') {
            return true;
        }
        if (\preg_match('/^[\'"]X-Requested-With[\'"]\s*:/i', $trimmed)) {
            return true;
        }
        if (\preg_match('/headers\s*:\s*\{[^}]*X-Requested-With/i', $trimmed)
            && !\preg_match('/\bfetch\s*\(|\bWeline\.Api\.(?:request|get|post)\s*\(|\$\.(?:ajax|get|post)\s*\(|\baxios(?:\.|\s*\()|\b(?:new\s+)?EventSource\s*\(|\bnew\s+XMLHttpRequest\s*\(/i', $trimmed)
        ) {
            return true;
        }
        if (\str_contains($trimmed, 'X-Maintenance-Recovery-Check')
            || \str_contains($trimmed, '_maintenance_recovery_probe')
            || \str_contains($trimmed, 'getProbeUrl()')
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string,mixed>> $allowlist
     */
    public static function isAllowlisted(string $relativePath, int $line, array $allowlist): bool
    {
        $today = new \DateTimeImmutable('today');
        foreach ($allowlist as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $path = (string)($row['path'] ?? '');
            if ($path === '' || $path !== $relativePath) {
                continue;
            }
            $removeBy = (string)($row['remove_by'] ?? '');
            if ($removeBy !== '') {
                try {
                    $deadline = new \DateTimeImmutable($removeBy);
                    if ($today > $deadline) {
                        continue;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
            $from = isset($row['line_from']) ? (int)$row['line_from'] : null;
            $to = isset($row['line_to']) ? (int)$row['line_to'] : null;
            if ($from !== null && $to !== null) {
                if ($line < $from || $line > $to) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }
}
