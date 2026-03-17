<?php
declare(strict_types=1);

namespace Weline\Bot\Service\CodingAgent\Tool;

use Weline\Ai\Interface\ToolInterface;

/**
 * 代码库搜索工具（Cursor 风格）
 *
 * 在工作区中按模式搜索文件内容
 */
class GrepTool implements ToolInterface
{
    public function getName(): string
    {
        return 'grep';
    }

    public function getDescription(): string
    {
        return __('Search for a pattern in files under path. Returns matching lines with file path and line number.');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => __('Search pattern (regex supported)'),
                ],
                'path' => [
                    'type' => 'string',
                    'description' => __('Directory to search (relative to project root), default app/code'),
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => __('File glob filter, e.g. *.php'),
                ],
                'max_results' => [
                    'type' => 'integer',
                    'default' => 50,
                    'description' => __('Maximum results to return'),
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $args): mixed
    {
        $pattern = $args['pattern'] ?? '';
        $path = $args['path'] ?? 'app/code';
        $glob = $args['glob'] ?? '*';
        $maxResults = min(200, max(10, (int) ($args['max_results'] ?? 50)));

        if (empty($pattern)) {
            return ['error' => __('pattern is required')];
        }

        $fullPath = $this->resolvePath($path);
        if ($fullPath === null || !is_dir($fullPath)) {
            return ['error' => __('Invalid or inaccessible path: %{1}', [$path])];
        }

        $iterator = $this->buildIterator($fullPath, $glob);
        $results = [];
        $count = 0;

        foreach ($iterator as $file) {
            if ($count >= $maxResults) {
                break;
            }
            if (!$file->isFile() || !$file->isReadable()) {
                continue;
            }
            $relPath = substr($file->getPathname(), strlen(BP));
            $lines = @file($file->getPathname(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }
            foreach ($lines as $num => $line) {
                if ($count >= $maxResults) {
                    break;
                }
                if (preg_match('/' . str_replace('/', '\\/', $pattern) . '/', $line)) {
                    $results[] = [
                        'path' => ltrim(str_replace('\\', '/', $relPath), '/'),
                        'line' => $num + 1,
                        'content' => $line,
                    ];
                    $count++;
                }
            }
        }

        return [
            'pattern' => $pattern,
            'path' => $path,
            'results' => $results,
            'count' => count($results),
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    private function resolvePath(string $path): ?string
    {
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, trim($path));
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        $root = rtrim(BP, DIRECTORY_SEPARATOR);
        $full = realpath($root . DIRECTORY_SEPARATOR . $path) ?: $root . DIRECTORY_SEPARATOR . $path;

        if ($full === false || !is_dir($full) || !str_starts_with(str_replace('\\', '/', realpath($full) ?: $full), str_replace('\\', '/', $root))) {
            return null;
        }

        return $full;
    }

    private function buildIterator(string $dir, string $glob): \Iterator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        return new \CallbackFilterIterator($iterator, function ($entry) use ($glob) {
            if (!$entry->isFile()) {
                return false;
            }
            return fnmatch($glob, $entry->getFilename());
        });
    }
}
