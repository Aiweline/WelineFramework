<?php
declare(strict_types=1);

namespace Weline\Agent\Service\CodingAgent\Tool;

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
        return __('读取工作区文件内容。路径可为相对项目根或绝对路径。用于查看代码、配置与模板。');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => __('文件路径（相对项目根或绝对路径）'),
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => __('起始行（从 1 开始），可选'),
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => __('读取行数，可选'),
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $args): mixed
    {
        $path = $args['path'] ?? '';
        if (empty($path)) {
            return ['error' => __('path 必填')];
        }

        $fullPath = $this->resolvePath($path);
        if ($fullPath === null) {
            return ['error' => __('访问被拒绝：路径必须在工作区内')];
        }

        if (!file_exists($fullPath)) {
            return ['error' => __('文件未找到：%{1}', [$path])];
        }

        if (!is_file($fullPath)) {
            return ['error' => __('路径不是文件：%{1}', [$path])];
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            return ['error' => __('读取文件失败：%{1}', [$path])];
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
