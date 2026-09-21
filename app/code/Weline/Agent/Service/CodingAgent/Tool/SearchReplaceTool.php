<?php
declare(strict_types=1);

namespace Weline\Agent\Service\CodingAgent\Tool;

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
        return __('在文件中搜索并替换文本。old_string 必须精确匹配。用于精确改代码。');
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => __('要编辑的文件路径'),
                ],
                'old_string' => [
                    'type' => 'string',
                    'description' => __('要查找的精确字符串（含空白必须匹配）'),
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => __('替换字符串'),
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
            return ['error' => __('path、old_string 与 new_string 必填')];
        }

        $fullPath = $this->resolvePath($path);
        if ($fullPath === null) {
            return ['error' => __('访问被拒绝：路径必须在工作区内')];
        }

        if (!file_exists($fullPath)) {
            return ['error' => __('文件未找到：%{1}', [$path])];
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            return ['error' => __('读取文件失败：%{1}', [$path])];
        }

        if (!str_contains($content, $oldString)) {
            return ['error' => __('文件中未找到 old_string。请确保含空白与换行精确匹配。')];
        }

        $newContent = str_replace($oldString, $newString, $content, $count);
        if ($count === 0) {
            return ['error' => __('替换失败')];
        }

        if (file_put_contents($fullPath, $newContent) === false) {
            return ['error' => __('写入文件失败：%{1}', [$path])];
        }

        return [
            'path' => $path,
            'replacements' => $count,
            'message' => __('已应用 %{1} 处替换', [$count]),
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
