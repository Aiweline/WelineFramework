<?php
declare(strict_types=1);

namespace Weline\Bot\Service\CodingAgent\Tool;

use Weline\Ai\Interface\ToolInterface;

/**
 * 全局文件搜索工具（Cursor 风格）
 *
 * 按 glob 模式查找文件
 */
class GlobFileSearchTool implements ToolInterface
{
    public function getName(): string
    {
        return 'glob_file_search';
    }

    public function getDescription(): string
    {
        return __('Find files matching a glob pattern (e.g. **/*.php, app/code/**/*.phtml).');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'glob_pattern' => [
                    'type' => 'string',
                    'description' => __('Glob pattern, e.g. **/*.php or app/code/**/*.php'),
                ],
                'path' => [
                    'type' => 'string',
                    'description' => __('Base path to search, default app/code'),
                ],
                'max_results' => [
                    'type' => 'integer',
                    'default' => 50,
                    'description' => __('Maximum files to return'),
                ],
            ],
            'required' => ['glob_pattern'],
        ];
    }

    public function execute(array $args): mixed
    {
        $globPattern = $args['glob_pattern'] ?? '';
        $basePath = $args['path'] ?? 'app/code';
        $maxResults = min(100, max(10, (int) ($args['max_results'] ?? 50)));

        if (empty($globPattern)) {
            return ['error' => __('glob_pattern is required')];
        }

        $fullBase = $this->resolvePath($basePath);
        if ($fullBase === null || !is_dir($fullBase)) {
            return ['error' => __('Invalid path: %{1}', [$basePath])];
        }

        $results = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullBase, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $baseLen = strlen(BP);
        foreach ($iterator as $file) {
            if (count($results) >= $maxResults) {
                break;
            }
            if (!$file->isFile()) {
                continue;
            }
            $relPath = ltrim(str_replace('\\', '/', substr($file->getPathname(), $baseLen)), '/');
            if ($this->matchGlob($relPath, $globPattern)) {
                $results[] = ['path' => $relPath];
            }
        }

        return [
            'glob_pattern' => $globPattern,
            'base_path' => $basePath,
            'files' => $results,
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
        $full = realpath($root . DIRECTORY_SEPARATOR . $path);
        if ($full === false || !str_starts_with(str_replace('\\', '/', $full), str_replace('\\', '/', $root))) {
            return null;
        }

        return $full;
    }

    private function matchGlob(string $path, string $glob): bool
    {
        $path = str_replace('\\', '/', $path);
        $escaped = preg_quote($glob, '#');
        $pattern = str_replace(
            ['\*\*', '\*', '\?'],
            ['.*', '[^/]*', '.'],
            $escaped
        );
        return (bool) preg_match('#^' . $pattern . '$#', $path);
    }
}
