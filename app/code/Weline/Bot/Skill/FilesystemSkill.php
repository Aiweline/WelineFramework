<?php
declare(strict_types=1);

namespace Weline\Bot\Skill;

use Weline\Bot\Interface\SkillInterface;
use Weline\Bot\Service\SkillContext;
use Weline\Bot\Service\SkillResult;

/**
 * 文件系统技能
 *
 * 提供文件读写能力
 */
class FilesystemSkill implements SkillInterface
{
    public function getCode(): string
    {
        return 'filesystem.read'; // 默认返回第一个技能代码，实际执行由 action 参数决定
    }

    /**
     * 获取所有子技能代码
     */
    public function getSubSkills(): array
    {
        return [
            'filesystem.read',
            'filesystem.write',
            'filesystem.list',
            'filesystem.delete',
            'filesystem.exists',
        ];
    }

    public function getName(): string
    {
        return __('文件系统');
    }

    public function getDescription(): string
    {
        return __('文件读写操作');
    }

    public function getCategory(): string
    {
        return 'filesystem';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['read', 'write', 'list', 'delete', 'exists'],
                    'description' => __('操作类型'),
                ],
                'path' => [
                    'type' => 'string',
                    'description' => __('文件路径'),
                ],
                'content' => [
                    'type' => 'string',
                    'description' => __('写入内容（仅 write 操作）'),
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['write', 'append'],
                    'default' => 'write',
                    'description' => __('写入模式'),
                ],
                'encoding' => [
                    'type' => 'string',
                    'default' => 'utf-8',
                    'description' => __('文件编码'),
                ],
            ],
            'required' => ['action', 'path'],
        ];
    }

    public function getPermissionRequired(): array
    {
        return ['fs.read', 'fs.write'];
    }

    public function execute(array $params, SkillContext $context): SkillResult
    {
        $action = $params['action'] ?? '';
        $path = $params['path'] ?? '';

        if (empty($path)) {
            return SkillResult::error('Path is required');
        }

        // 安全检查：防止路径遍历攻击
        $path = $this->sanitizePath($path);

        return match ($action) {
            'read' => $this->readFile($path, $params['encoding'] ?? 'utf-8'),
            'write' => $this->writeFile($path, $params['content'] ?? '', $params['mode'] ?? 'write'),
            'list' => $this->listFiles($path),
            'delete' => $this->deleteFile($path),
            'exists' => $this->fileExists($path),
            default => SkillResult::error("Unknown action: {$action}"),
        };
    }

    public function isDangerous(): bool
    {
        return false;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * 读取文件
     */
    private function readFile(string $path, string $encoding): SkillResult
    {
        if (!file_exists($path)) {
            return SkillResult::error("File not found: {$path}");
        }

        if (!is_readable($path)) {
            return SkillResult::error("File is not readable: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return SkillResult::error("Failed to read file: {$path}");
        }

        // 转换编码（如果需要）
        if ($encoding !== 'utf-8') {
            $content = mb_convert_encoding($content, 'utf-8', $encoding);
        }

        return SkillResult::success([
            'path' => $path,
            'content' => $content,
            'size' => strlen($content),
        ]);
    }

    /**
     * 写入文件
     */
    private function writeFile(string $path, string $content, string $mode): SkillResult
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return SkillResult::error("Failed to create directory: {$dir}");
            }
        }

        $flags = $mode === 'append' ? FILE_APPEND : 0;
        $result = file_put_contents($path, $content, $flags);

        if ($result === false) {
            return SkillResult::error("Failed to write file: {$path}");
        }

        return SkillResult::success([
            'path' => $path,
            'bytes_written' => $result,
            'mode' => $mode,
        ], __('文件写入成功'));
    }

    /**
     * 列出文件
     */
    private function listFiles(string $path): SkillResult
    {
        if (!is_dir($path)) {
            return SkillResult::error("Directory not found: {$path}");
        }

        $items = scandir($path);
        if ($items === false) {
            return SkillResult::error("Failed to list directory: {$path}");
        }

        // 过滤 . 和 ..
        $items = array_filter($items, fn($item) => !in_array($item, ['.', '..']));

        $files = [];
        foreach ($items as $item) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $item;
            $files[] = [
                'name' => $item,
                'path' => $fullPath,
                'type' => is_dir($fullPath) ? 'directory' : 'file',
                'size' => is_file($fullPath) ? filesize($fullPath) : 0,
            ];
        }

        return SkillResult::success([
            'path' => $path,
            'items' => $files,
            'count' => count($files),
        ]);
    }

    /**
     * 删除文件
     */
    private function deleteFile(string $path): SkillResult
    {
        if (!file_exists($path)) {
            return SkillResult::error("File not found: {$path}");
        }

        if (is_dir($path)) {
            return SkillResult::error("Cannot delete directory with this action");
        }

        if (!unlink($path)) {
            return SkillResult::error("Failed to delete file: {$path}");
        }

        return SkillResult::success([
            'path' => $path,
            'deleted' => true,
        ], __('文件删除成功'));
    }

    /**
     * 检查文件是否存在
     */
    private function fileExists(string $path): SkillResult
    {
        return SkillResult::success([
            'path' => $path,
            'exists' => file_exists($path),
            'is_file' => is_file($path),
            'is_dir' => is_dir($path),
        ]);
    }

    /**
     * 清理路径（安全措施）
     */
    private function sanitizePath(string $path): string
    {
        // 规范化路径
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);

        // 移除路径中的 .. 和连续的斜杠
        $path = preg_replace('#/\.\.(/|$)#', '/', $path);
        $path = preg_replace('#/+#', '/', $path);

        return $path;
    }
}
