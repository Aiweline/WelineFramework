<?php
declare(strict_types=1);

namespace Weline\Agent\Service\CodingAgent\Tool;

use Weline\Ai\Interface\ToolInterface;

/**
 * 列出目录工具（Cursor 风格）
 */
class ListDirTool implements ToolInterface
{
    public function getName(): string
    {
        return 'list_dir';
    }

    public function getDescription(): string
    {
        return __('列出目录中的文件与子目录。');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => __('目录路径（相对项目根）'),
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

        if (!is_dir($fullPath)) {
            return ['error' => __('不是目录：%{1}', [$path])];
        }

        $items = scandir($fullPath);
        if ($items === false) {
            return ['error' => __('列出目录失败')];
        }

        $result = [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $itemPath = $fullPath . DIRECTORY_SEPARATOR . $name;
            $result[] = [
                'name' => $name,
                'type' => is_dir($itemPath) ? 'directory' : 'file',
                'path' => ltrim(str_replace('\\', '/', $path . '/' . $name), '/'),
            ];
        }

        usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'path' => $path,
            'items' => $result,
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
}
