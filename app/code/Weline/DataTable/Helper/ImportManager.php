<?php
/**
 * DataTable 数据导入管理器
 * 处理Excel、CSV文件的数据导入
 */

namespace Weline\DataTable\Helper;

use Weline\DataTable\Exception\DataTableException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

class ImportManager
{
    /**
     * 支持的导入格式
     */
    const FORMAT_EXCEL = 'excel';
    const FORMAT_CSV = 'csv';
    const FORMAT_JSON = 'json';

    /**
     * 导入文件
     *
     * @param string $filePath 文件路径
     * @param string $format 文件格式
     * @return array 解析后的数据
     * @throws DataTableException
     */
    public static function parseFile(string $filePath, string $format = self::FORMAT_EXCEL): array
    {
        if (!file_exists($filePath)) {
            throw DataTableException::importFailed("文件不存在: {$filePath}");
        }

        switch ($format) {
            case self::FORMAT_EXCEL:
                return self::parseExcel($filePath);
            case self::FORMAT_CSV:
                return self::parseCsv($filePath);
            case self::FORMAT_JSON:
                return self::parseJson($filePath);
            default:
                throw DataTableException::importFailed("不支持的格式: {$format}");
        }
    }

    /**
     * 解析Excel文件
     *
     * @param string $filePath 文件路径
     * @return array
     * @throws DataTableException
     */
    private static function parseExcel(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $data = [];

            // 获取表头（第一行）
            $headers = [];
            $highestColumn = $worksheet->getHighestColumn();
            $highestRow = $worksheet->getHighestRow();

            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $cellValue = $worksheet->getCell($col . '1')->getValue();
                if (!empty($cellValue)) {
                    $headers[] = trim($cellValue);
                }
            }

            if (empty($headers)) {
                throw DataTableException::importFailed("Excel文件没有表头");
            }

            // 读取数据行
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                $colIndex = 0;
                for ($col = 'A'; $col <= $highestColumn; $col++) {
                    $cellValue = $worksheet->getCell($col . $row)->getValue();
                    if (isset($headers[$colIndex])) {
                        $rowData[$headers[$colIndex]] = $cellValue;
                    }
                    $colIndex++;
                }
                
                // 跳过空行
                if (!empty(array_filter($rowData, function($value) {
                    return $value !== null && $value !== '';
                }))) {
                    $data[] = $rowData;
                }
            }

            return [
                'headers' => $headers,
                'data' => $data,
                'total_rows' => count($data)
            ];
        } catch (\Exception $e) {
            throw DataTableException::importFailed("Excel解析失败: " . $e->getMessage());
        }
    }

    /**
     * 解析CSV文件
     *
     * @param string $filePath 文件路径
     * @return array
     * @throws DataTableException
     */
    private static function parseCsv(string $filePath): array
    {
        try {
            $data = [];
            $headers = [];
            $handle = fopen($filePath, 'r');
            
            if ($handle === false) {
                throw DataTableException::importFailed("无法打开CSV文件");
            }

            // 检测编码并转换
            $content = file_get_contents($filePath);
            $encoding = mb_detect_encoding($content, ['UTF-8', 'GBK', 'GB2312', 'ISO-8859-1'], true);
            if ($encoding !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $encoding);
                file_put_contents($filePath . '.utf8', $content);
                $handle = fopen($filePath . '.utf8', 'r');
            }

            $rowIndex = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if ($rowIndex === 0) {
                    // 第一行是表头
                    $headers = array_map('trim', $row);
                } else {
                    // 数据行
                    $rowData = [];
                    foreach ($headers as $index => $header) {
                        $rowData[$header] = $row[$index] ?? '';
                    }
                    
                    // 跳过空行
                    if (!empty(array_filter($rowData, function($value) {
                        return $value !== null && $value !== '';
                    }))) {
                        $data[] = $rowData;
                    }
                }
                $rowIndex++;
            }

            fclose($handle);
            
            // 清理临时文件
            if (file_exists($filePath . '.utf8')) {
                unlink($filePath . '.utf8');
            }

            if (empty($headers)) {
                throw DataTableException::importFailed("CSV文件没有表头");
            }

            return [
                'headers' => $headers,
                'data' => $data,
                'total_rows' => count($data)
            ];
        } catch (\Exception $e) {
            throw DataTableException::importFailed("CSV解析失败: " . $e->getMessage());
        }
    }

    /**
     * 解析JSON文件
     *
     * @param string $filePath 文件路径
     * @return array
     * @throws DataTableException
     */
    private static function parseJson(string $filePath): array
    {
        try {
            $content = file_get_contents($filePath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw DataTableException::importFailed("JSON解析失败: " . json_last_error_msg());
            }

            if (empty($data) || !is_array($data)) {
                throw DataTableException::importFailed("JSON文件格式不正确");
            }

            // 如果是对象数组，提取表头
            $headers = [];
            if (isset($data[0]) && is_array($data[0])) {
                $headers = array_keys($data[0]);
            }

            return [
                'headers' => $headers,
                'data' => $data,
                'total_rows' => count($data)
            ];
        } catch (\Exception $e) {
            throw DataTableException::importFailed("JSON解析失败: " . $e->getMessage());
        }
    }

    /**
     * 字段映射
     *
     * @param array $data 原始数据
     * @param array $fieldMapping 字段映射配置 ['excel_field' => 'model_field']
     * @return array 映射后的数据
     */
    public static function mapFields(array $data, array $fieldMapping): array
    {
        $mappedData = [];
        
        foreach ($data as $row) {
            $mappedRow = [];
            foreach ($fieldMapping as $sourceField => $targetField) {
                $mappedRow[$targetField] = $row[$sourceField] ?? null;
            }
            $mappedData[] = $mappedRow;
        }

        return $mappedData;
    }

    /**
     * 验证数据
     *
     * @param array $data 数据
     * @param array $rules 验证规则
     * @param object $modelInstance 模型实例
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateData(array $data, array $rules, $modelInstance): array
    {
        $errors = [];
        $validData = [];

        foreach ($data as $rowIndex => $row) {
            $rowErrors = [];
            
            foreach ($rules as $field => $rule) {
                $value = $row[$field] ?? null;
                
                // 必填验证
                if (isset($rule['required']) && $rule['required'] && empty($value)) {
                    $rowErrors[] = "第" . ($rowIndex + 2) . "行，字段 {$field} 不能为空";
                    continue;
                }

                // 类型验证
                if (isset($rule['type'])) {
                    if (!self::validateType($value, $rule['type'])) {
                        $rowErrors[] = "第" . ($rowIndex + 2) . "行，字段 {$field} 类型不正确";
                        continue;
                    }
                }

                // 唯一性验证
                if (isset($rule['unique']) && $rule['unique']) {
                    if (self::checkExists($modelInstance, $field, $value)) {
                        $rowErrors[] = "第" . ($rowIndex + 2) . "行，字段 {$field} 的值已存在";
                        continue;
                    }
                }
            }

            if (empty($rowErrors)) {
                $validData[] = $row;
            } else {
                $errors[$rowIndex] = $rowErrors;
            }
        }

        return [
            'valid' => empty($errors),
            'valid_data' => $validData,
            'errors' => $errors,
            'total_rows' => count($data),
            'valid_rows' => count($validData),
            'error_rows' => count($errors)
        ];
    }

    /**
     * 验证字段类型
     *
     * @param mixed $value 值
     * @param string $type 类型
     * @return bool
     */
    private static function validateType($value, string $type): bool
    {
        if ($value === null || $value === '') {
            return true; // 空值由必填验证处理
        }

        switch ($type) {
            case 'int':
            case 'integer':
                return is_numeric($value) && (int)$value == $value;
            case 'float':
            case 'decimal':
                return is_numeric($value);
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            case 'date':
                return strtotime($value) !== false;
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false;
            default:
                return true;
        }
    }

    /**
     * 检查值是否已存在
     *
     * @param object $modelInstance 模型实例
     * @param string $field 字段名
     * @param mixed $value 值
     * @return bool
     */
    private static function checkExists($modelInstance, string $field, $value): bool
    {
        try {
            $count = $modelInstance->where($field, '=', $value)->count();
            return $count > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 批量导入数据
     *
     * @param object $modelInstance 模型实例
     * @param array $data 数据
     * @param int $batchSize 批次大小
     * @return array 导入结果
     */
    public static function batchImport($modelInstance, array $data, int $batchSize = 100): array
    {
        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        $batches = array_chunk($data, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $rowIndex => $row) {
                try {
                    $model = clone $modelInstance;
                    $model->setData($row);
                    if ($model->save()) {
                        $successCount++;
                    } else {
                        $failedCount++;
                        $errors[] = "批次 " . ($batchIndex + 1) . "，行 " . ($rowIndex + 1) . " 保存失败";
                    }
                } catch (\Exception $e) {
                    $failedCount++;
                    $errors[] = "批次 " . ($batchIndex + 1) . "，行 " . ($rowIndex + 1) . " 错误: " . $e->getMessage();
                }
            }
        }

        return [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'total_count' => count($data),
            'errors' => $errors
        ];
    }
}

