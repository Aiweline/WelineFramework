<?php
declare(strict_types=1);

namespace Weline\Bot\Service\CodingAgent\Tool;

use Weline\Ai\Interface\ToolInterface;

/**
 * 搜索替换工具（Cursor 风格）
 *
 * 在指定文件中搜索并替换文本
 */
class SearchReplaceTool implements ToolInterface
{
    public function getName(): string
    {
        return 'search_replace';
    }

    public function getDescription(): string
    {
        return __('Search and replace text in a file. old_string must match exactly. Use for precise code edits.');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => __('File path to edit'),
                ],
                'old_string' => [
                    'type' => 'string',
                    'description' => __('Exact string to find (must match including whitespace)'),
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => __('Replacement string'),
                ],
            ],
            'required' => ['path', 'old_string', 'new_string'],
        ];
    }

    public function execute(array $args): mixed
    {
        $path = $args['path'] ?? '';
        $oldString = $args['old_string'] ?? '';
        $newString = $args['new_string'] ?? '';

        if (empty($path) || $oldString === '') {
            return ['error' => __('path, old_string and new_string are required')];
        }

        $fullPath = $this->resolvePath($path);
        if ($fullPath === null) {
            return ['error' => __('Access denied: path must be within workspace')];
        }

        if (!file_exists($fullPath)) {
            return ['error' => __('File not found: %{1}', [$path])];
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            return ['error' => __('Failed to read file: %{1}', [$path])];
        }

        if (!str_contains($content, $oldString)) {
            return ['error' => __('old_string not found in file. Ensure exact match including whitespace and newlines.')];
        }

        $newContent = str_replace($oldString, $newString, $content, $count);
        if ($count === 0) {
            return ['error' => __('Replacement failed')];
        }

        if (file_put_contents($fullPath, $newContent) === false) {
            return ['error' => __('Failed to write file: %{1}', [$path])];
        }

        return [
            'path' => $path,
            'replacements' => $count,
            'message' => __('%{1} replacement(s) applied', [$count]),
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
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('#^[a-zA-Z]:\\\\#', $path)) {
            $full = realpath($path) ?: $path;
        } else {
            $full = realpath($root . DIRECTORY_SEPARATOR . $path) ?: $root . DIRECTORY_SEPARATOR . $path;
        }

        if ($full === false || !str_starts_with(str_replace('\\', '/', $full), str_replace('\\', '/', $root))) {
            return null;
        }

        return $full;
    }
}
