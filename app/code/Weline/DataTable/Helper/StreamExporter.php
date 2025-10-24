<?php

namespace Weline\DataTable\Helper;

use Weline\Framework\Manager\ObjectManager;

/**
 * 流式数据导出器
 * 支持大数据量的分页导出，避免内存溢出
 */
class StreamExporter
{
    /**
     * 每页导出的记录数
     */
    private int $pageSize = 1000;

    /**
     * 导出格式
     */
    private string $format = 'csv';

    /**
     * 导出字段配置
     */
    private array $fields = [];

    /**
     * 导出进度回调
     */
    private $progressCallback = null;

    /**
     * 临时文件路径
     */
    private ?string $tempFilePath = null;

    /**
     * 文件句柄
     */
    private $fileHandle = null;

    /**
     * 导出统计信息
     */
    private array $stats = [
        'total_records' => 0,
        'exported_records' => 0,
        'current_page' => 0,
        'total_pages' => 0,
        'start_time' => null,
        'end_time' => null,
        'file_size' => 0
    ];

    public function __construct()
    {
        $this->stats['start_time'] = microtime(true);
    }

    /**
     * 开始流式导出
     */
    public function export(string $modelClass, array $conditions = [], array $options = []): array
    {
        try {
            // 初始化配置
            $this->initializeExport($options);

            // 验证模型类
            if (!class_exists($modelClass)) {
                throw new \Exception("模型类不存在: {$modelClass}");
            }

            $modelInstance = new $modelClass();

            // 计算总记录数
            $this->calculateTotalRecords($modelInstance, $conditions);

            // 创建临时文件
            $this->createTempFile();

            // 写入文件头部
            $this->writeHeader();

            // 分页导出数据
            $this->exportDataInPages($modelInstance, $conditions);

            // 完成导出
            $this->finalizeExport();

            return $this->getExportResult();

        } catch (\Exception $e) {
            $this->cleanup();
            throw $e;
        }
    }

    /**
     * 初始化导出配置
     */
    private function initializeExport(array $options): void
    {
        $this->pageSize = $options['page_size'] ?? 1000;
        $this->format = $options['format'] ?? 'csv';
        $this->fields = $options['fields'] ?? [];
        $this->progressCallback = $options['progress_callback'] ?? null;

        // 验证格式
        if (!in_array($this->format, ['csv', 'excel', 'json'])) {
            throw new \Exception("不支持的导出格式: {$this->format}");
        }
    }

    /**
     * 计算总记录数
     */
    private function calculateTotalRecords($modelInstance, array $conditions): void
    {
        $query = clone $modelInstance;
        
        // 应用查询条件
        $this->applyConditions($query, $conditions);
        
        $this->stats['total_records'] = $query->count();
        $this->stats['total_pages'] = ceil($this->stats['total_records'] / $this->pageSize);
    }

    /**
     * 应用查询条件
     */
    private function applyConditions($query, array $conditions): void
    {
        foreach ($conditions as $condition) {
            if (isset($condition['field'], $condition['operator'], $condition['value'])) {
                $query->where($condition['field'], $condition['operator'], $condition['value']);
            }
        }
    }

    /**
     * 创建临时文件
     */
    private function createTempFile(): void
    {
        $tempDir = sys_get_temp_dir();
        $filename = 'export_' . uniqid() . '.' . $this->getFileExtension();
        $this->tempFilePath = $tempDir . DIRECTORY_SEPARATOR . $filename;
        
        $this->fileHandle = fopen($this->tempFilePath, 'w');
        if (!$this->fileHandle) {
            throw new \Exception("无法创建临时文件: {$this->tempFilePath}");
        }

        // 设置UTF-8 BOM（用于Excel正确显示中文）
        if ($this->format === 'csv') {
            fwrite($this->fileHandle, "\xEF\xBB\xBF");
        }
    }

    /**
     * 写入文件头部
     */
    private function writeHeader(): void
    {
        switch ($this->format) {
            case 'csv':
                $this->writeCsvHeader();
                break;
            case 'json':
                fwrite($this->fileHandle, "[\n");
                break;
            case 'excel':
                // Excel格式可以使用CSV作为基础
                $this->writeCsvHeader();
                break;
        }
    }

    /**
     * 写入CSV头部
     */
    private function writeCsvHeader(): void
    {
        if (!empty($this->fields)) {
            $headers = [];
            foreach ($this->fields as $field) {
                $headers[] = $field['label'] ?? $field['name'] ?? $field;
            }
            fputcsv($this->fileHandle, $headers);
        }
    }

    /**
     * 分页导出数据
     */
    private function exportDataInPages($modelInstance, array $conditions): void
    {
        $isFirstJsonRecord = true;

        for ($page = 1; $page <= $this->stats['total_pages']; $page++) {
            $this->stats['current_page'] = $page;

            // 获取当前页数据
            $query = clone $modelInstance;
            $this->applyConditions($query, $conditions);
            
            $data = $query->pagination($page, $this->pageSize)->select()->fetch();

            if (empty($data)) {
                break;
            }

            // 写入数据
            foreach ($data as $record) {
                $this->writeRecord($record, $isFirstJsonRecord);
                $this->stats['exported_records']++;
                $isFirstJsonRecord = false;
            }

            // 更新进度
            $this->updateProgress();

            // 释放内存
            unset($data);
            gc_collect_cycles();
        }
    }

    /**
     * 写入单条记录
     */
    private function writeRecord(array $record, bool $isFirstJsonRecord = false): void
    {
        switch ($this->format) {
            case 'csv':
            case 'excel':
                $this->writeCsvRecord($record);
                break;
            case 'json':
                $this->writeJsonRecord($record, $isFirstJsonRecord);
                break;
        }
    }

    /**
     * 写入CSV记录
     */
    private function writeCsvRecord(array $record): void
    {
        if (!empty($this->fields)) {
            $row = [];
            foreach ($this->fields as $field) {
                $fieldName = $field['name'] ?? $field;
                $row[] = $record[$fieldName] ?? '';
            }
            fputcsv($this->fileHandle, $row);
        } else {
            fputcsv($this->fileHandle, array_values($record));
        }
    }

    /**
     * 写入JSON记录
     */
    private function writeJsonRecord(array $record, bool $isFirst): void
    {
        if (!$isFirst) {
            fwrite($this->fileHandle, ",\n");
        }

        // 如果指定了字段，只导出指定字段
        if (!empty($this->fields)) {
            $filteredRecord = [];
            foreach ($this->fields as $field) {
                $fieldName = $field['name'] ?? $field;
                $filteredRecord[$fieldName] = $record[$fieldName] ?? null;
            }
            $record = $filteredRecord;
        }

        fwrite($this->fileHandle, json_encode($record, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 更新导出进度
     */
    private function updateProgress(): void
    {
        if ($this->progressCallback && is_callable($this->progressCallback)) {
            $progress = [
                'current_page' => $this->stats['current_page'],
                'total_pages' => $this->stats['total_pages'],
                'exported_records' => $this->stats['exported_records'],
                'total_records' => $this->stats['total_records'],
                'percentage' => round(($this->stats['exported_records'] / $this->stats['total_records']) * 100, 2),
                'elapsed_time' => microtime(true) - $this->stats['start_time']
            ];

            call_user_func($this->progressCallback, $progress);
        }
    }

    /**
     * 完成导出
     */
    private function finalizeExport(): void
    {
        // 写入文件尾部
        if ($this->format === 'json') {
            fwrite($this->fileHandle, "\n]");
        }

        // 关闭文件句柄
        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }

        // 更新统计信息
        $this->stats['end_time'] = microtime(true);
        $this->stats['file_size'] = filesize($this->tempFilePath);
    }

    /**
     * 获取导出结果
     */
    private function getExportResult(): array
    {
        return [
            'success' => true,
            'file_path' => $this->tempFilePath,
            'filename' => basename($this->tempFilePath),
            'stats' => $this->stats,
            'duration' => $this->stats['end_time'] - $this->stats['start_time']
        ];
    }

    /**
     * 获取文件扩展名
     */
    private function getFileExtension(): string
    {
        switch ($this->format) {
            case 'excel':
                return 'xlsx';
            case 'json':
                return 'json';
            case 'csv':
            default:
                return 'csv';
        }
    }

    /**
     * 清理资源
     */
    private function cleanup(): void
    {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }

        if ($this->tempFilePath && file_exists($this->tempFilePath)) {
            unlink($this->tempFilePath);
            $this->tempFilePath = null;
        }
    }

    /**
     * 设置页面大小
     */
    public function setPageSize(int $size): self
    {
        $this->pageSize = max(100, min(10000, $size));
        return $this;
    }

    /**
     * 设置导出格式
     */
    public function setFormat(string $format): self
    {
        $this->format = $format;
        return $this;
    }

    /**
     * 设置导出字段
     */
    public function setFields(array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * 设置进度回调
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * 获取导出统计信息
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * 析构函数，确保资源被清理
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
