<?php

declare(strict_types=1);

namespace Weline\Database\Helper;

/**
 * 备份助手：备份命名与完整性校验
 */
class BackupHelper
{
    /**
     * 生成备份名称
     */
    public static function generateBackupName(string $moduleName, string $tableName, string $type = 'table'): string
    {
        return sprintf(
            '%s_%s_%s_%s',
            str_replace('_', '-', $moduleName),
            $tableName,
            $type,
            date('YmdHis')
        );
    }

    /**
     * 校验 JSON 备份数据完整性
     *
     * @param string $jsonData 备份的 JSON 字符串
     * @return array{valid: bool, count: int, error: string}
     */
    public static function validateBackupData(string $jsonData): array
    {
        if (empty($jsonData)) {
            return ['valid' => false, 'count' => 0, 'error' => 'empty data'];
        }

        $decoded = json_decode($jsonData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['valid' => false, 'count' => 0, 'error' => json_last_error_msg()];
        }

        if (!is_array($decoded)) {
            return ['valid' => false, 'count' => 0, 'error' => 'not an array'];
        }

        return ['valid' => true, 'count' => count($decoded), 'error' => ''];
    }

    /**
     * 估算备份数据大小（字节）
     */
    public static function estimateSize(string $jsonData): int
    {
        return strlen($jsonData);
    }

    /**
     * 格式化字节数为可读字符串
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return round($bytes / 1048576, 2) . ' MB';
    }
}
