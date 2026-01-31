<?php
declare(strict_types=1);

/**
 * Weline Framework
 * 
 * @DESC         | 源码映射（Source Map）
 * @Author       | Weline Framework
 * @Package      | Weline\Framework\View\Taglib\Debug
 */

namespace Weline\Framework\View\Taglib\Debug;

/**
 * 源码映射
 * 
 * 记录编译后代码到源模板的行号映射，用于调试
 */
final class SourceMap
{
    /**
     * 映射表：生成行号 => [源文件, 源行号]
     * @var array<int, array{file: string, line: int}>
     */
    private array $mappings = [];

    /**
     * 源文件路径
     */
    private string $sourceFile = '';

    /**
     * 设置源文件
     */
    public function setSourceFile(string $file): void
    {
        $this->sourceFile = $file;
    }

    /**
     * 添加映射
     * 
     * @param int $generatedLine 生成代码的行号
     * @param int $sourceLine 源模板的行号
     * @param string|null $sourceFile 源文件（可选，默认使用当前源文件）
     */
    public function addMapping(int $generatedLine, int $sourceLine, ?string $sourceFile = null): void
    {
        $this->mappings[$generatedLine] = [
            'file' => $sourceFile ?? $this->sourceFile,
            'line' => $sourceLine,
        ];
    }

    /**
     * 批量添加映射
     * 
     * @param array<int, int> $mappings 生成行号 => 源行号
     */
    public function addMappings(array $mappings): void
    {
        foreach ($mappings as $generatedLine => $sourceLine) {
            $this->addMapping($generatedLine, $sourceLine);
        }
    }

    /**
     * 获取原始位置
     * 
     * @param int $generatedLine 生成代码的行号
     * @return array{file: string, line: int}|null
     */
    public function getOriginalPosition(int $generatedLine): ?array
    {
        return $this->mappings[$generatedLine] ?? null;
    }

    /**
     * 查找最近的映射
     * 
     * 如果精确行号没有映射，返回最近的上一行映射
     */
    public function findNearestMapping(int $generatedLine): ?array
    {
        // 精确匹配
        if (isset($this->mappings[$generatedLine])) {
            return $this->mappings[$generatedLine];
        }

        // 查找最近的上一行
        $nearestLine = null;
        foreach (array_keys($this->mappings) as $line) {
            if ($line <= $generatedLine) {
                if ($nearestLine === null || $line > $nearestLine) {
                    $nearestLine = $line;
                }
            }
        }

        return $nearestLine !== null ? $this->mappings[$nearestLine] : null;
    }

    /**
     * 格式化错误位置
     * 
     * @param int $generatedLine 生成代码的行号
     * @return string 格式化的位置字符串
     */
    public function formatErrorLocation(int $generatedLine): string
    {
        $mapping = $this->findNearestMapping($generatedLine);

        if ($mapping === null) {
            return "Unknown location (generated line {$generatedLine})";
        }

        return sprintf(
            '%s:%d (generated line %d)',
            $mapping['file'],
            $mapping['line'],
            $generatedLine
        );
    }

    /**
     * 获取所有映射
     */
    public function getMappings(): array
    {
        return $this->mappings;
    }

    /**
     * 清除映射
     */
    public function clear(): void
    {
        $this->mappings = [];
        $this->sourceFile = '';
    }

    /**
     * 导出为 JSON
     */
    public function toJson(): string
    {
        return json_encode([
            'version' => 1,
            'sourceFile' => $this->sourceFile,
            'mappings' => $this->mappings,
        ], JSON_PRETTY_PRINT);
    }

    /**
     * 从 JSON 导入
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        
        $map = new self();
        $map->sourceFile = $data['sourceFile'] ?? '';
        $map->mappings = $data['mappings'] ?? [];
        
        return $map;
    }

    /**
     * 获取统计信息
     */
    public function stats(): array
    {
        return [
            'sourceFile' => $this->sourceFile,
            'mappingCount' => count($this->mappings),
        ];
    }
}
