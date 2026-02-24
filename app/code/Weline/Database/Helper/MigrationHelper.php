<?php

declare(strict_types=1);

namespace Weline\Database\Helper;

/**
 * 迁移助手：按命名规范解析迁移文件元信息
 */
class MigrationHelper
{
    /**
     * 从文件名解析迁移元信息
     *
     * 命名格式: {action}__{description}_{date}-{version}.php
     * 示例: drop_column__raw_data_20250101-v1.0.1.php
     *
     * @return array{action: string, description: string, date: string, version: string}
     */
    public static function parseFilename(string $filename): array
    {
        $result = ['action' => '', 'description' => '', 'date' => '', 'version' => ''];

        $base = basename($filename, '.php');

        if (str_contains($base, '__')) {
            [$result['action'], $rest] = explode('__', $base, 2);
        } else {
            $rest = $base;
        }

        if (preg_match('/^(.+?)_(\d{8})-v([\d.]+)$/', $rest, $m)) {
            $result['description'] = $m[1];
            $result['date']        = $m[2];
            $result['version']     = $m[3];
        } else {
            $result['description'] = $rest;
        }

        return $result;
    }

    /**
     * 生成规范文件名
     */
    public static function buildFilename(string $action, string $description, string $date, string $version): string
    {
        return sprintf('%s__%s_%s-v%s.php', $action, $description, $date, $version);
    }

    /**
     * 扫描模块迁移目录并按文件名排序返回文件列表
     *
     * @return string[] 绝对路径数组
     */
    public static function scanMigrationFiles(string $migrationDir): array
    {
        if (!is_dir($migrationDir)) {
            return [];
        }

        $files = glob(rtrim($migrationDir, '/\\') . '/*.php');
        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);
        return $files;
    }
}
