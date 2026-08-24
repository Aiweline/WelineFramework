<?php
declare(strict_types=1);

namespace Weline\Bot\Service\CodingAgent\Tool;

use Weline\Ai\Interface\ToolInterface;

/**
 * 读取文件工具（Cursor 风格）
 *
 * 在工作区范围内读取文件内容
 */
class ReadFileTool implements ToolInterface
{
    public function getName(): string
    {
        return 'read_file';
    }

    public function getDescription(): string
    {
        return __('Read file contents from the workspace. Path is relative to project root or absolute. Use for inspecting code, configs, templates.');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => __('File path (relative to project root or absolute)'),
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => __('Start line (1-based), optional'),
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => __('Number of lines to read, optional'),
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $args): mixed
    {
        $path = $args['path'] ?? '';
        if (empty($path)) {
            return ['error' => __('path is required')];
        }

        $fullPath = $this->resolvePath($path);
        if ($fullPath === null) {
            return ['error' => __('Access denied: path must be within workspace')];
        }

        if (!file_exists($fullPath)) {
            return ['error' => __('File not found: %{1}', [$path])];
        }

        if (!is_file($fullPath)) {
            return ['error' => __('Path is not a file: %{1}', [$path])];
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            return ['error' => __('Failed to read file: %{1}', [$path])];
        }

        $offset = isset($args['offset']) ? max(1, (int) $args['offset']) : 1;
        $limit = isset($args['limit']) ? max(1, (int) $args['limit']) : null;

        if ($offset > 1 || $limit !== null) {
            $lines = explode("\n", $content);
            $slice = array_slice($lines, $offset - 1, $limit);
            $content = implode("\n", $slice);
        }

        return [
            'path' => $path,
            'content' => $content,
            'lines' => substr_count($content, "\n") + (strlen($content) > 0 ? 1 : 0),
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
