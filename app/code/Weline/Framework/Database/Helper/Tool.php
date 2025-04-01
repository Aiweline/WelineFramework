<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：24/10/2023 13:20:05
 */

namespace Weline\Framework\Database\Helper;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Database\Model;

class Tool
{
    /**
     * 提取sql语句中的表名
     *
     * @param string $sql
     * @param string|array $exclude_expression
     * @return void
     */
    static function sql2table(string $sql, string|array $exclude_expression = '')
    {
        $pattern = '/(?:SELECT\s+(?:(?!FROM)[^;])*FROM|INSERT\s+INTO|UPDATE|DELETE\s+FROM|JOIN)\s+([^\s\(\)\,;]+)(?:\s+AS\s+[^\s\(\)\,;]+)?/i';
        preg_match_all($pattern, $sql, $matches);

        $result = [];
        foreach ($matches[1] as $index => $table) {
            $operation = strtolower(trim($matches[0][$index]));

            // 标准化操作类型
            if (strpos($operation, 'select') === 0) {
                $operation = 'select';
            } elseif (strpos($operation, 'insert into') === 0) {
                $operation = 'insert';
            } elseif (strpos($operation, 'delete from') === 0) {
                $operation = 'delete';
            } elseif (strpos($operation, 'update') === 0) {
                $operation = 'update';
            } elseif (strpos($operation, 'join') === 0) {
                $operation = 'select';
            }

            if (!isset($result[$operation])) {
                $result[$operation] = [];
            }
            if (!in_array($table, $result[$operation])) {
                $result[$operation][] = $table;
            }
        }

        return $result;
    }

    static function rm_sql_limit(string $sql): string
    {
        // 正则表达式匹配 LIMIT 子句（包括 OFFSET 的情况，支持大小写）
        $pattern = '/(?i)\s*LIMIT\s+\d+(\s*,\s*\d+)?(\s+OFFSET\s+\d+)?\b/';
        // 使用 preg_replace 删除匹配到的 LIMIT 子句
        $sql = preg_replace($pattern, '', $sql);
        return trim($sql, " ;\r\n") . "\r\n";
    }

    /**
     * 导出模型条件数据
     * @param Model $model
     * @param string $output_file_name
     * @return void
     */
    static function export(Model|AbstractModel $model, bool $is_download = true, string $output_file_name = '', array $columns = []): string
    {
        // 列
        if (!$columns) {
            $col_model = clone $model;
            $columns = $col_model->columns();
            foreach ($columns as &$column) {
                $column = $column['Field'];
            }
        }

        # 生成csv
        // 设置文件名和内容类型
        if (empty($output_file_name)) {
            $output_file_name = md5($model->getTable()) . "-" . time() . ".csv";
        }
        if ($is_download) {
            header("Content-Type: text/csv");
            header("Content-Disposition: attachment; filename=$output_file_name");
            header("Cache-Control: no-cache, no-store, must-revalidate");
            header("Pragma: no-cache");
            header("Expires: 0");
        }
        $model_export_dir = PUB . 'media/export/model/';

        if (is_file($output_file_name)) {
            if (!is_writeable(dirname($output_file_name))) {
                throw new \Exception(__('导出文件目录不可写!'));
            }
            if (!str_contains($output_file_name, $model_export_dir)) {
                throw new \Exception(__('导出文件路径错误! 仅允许导出到%1media/export/model/目录下', PUB));
            }
        } else {
            $output_file_name = $model_export_dir . $output_file_name;
            if (!is_dir(dirname($output_file_name))) {
                mkdir(dirname($output_file_name), 0777, true);
            }
            if (!is_file($output_file_name)) {
                touch($output_file_name);
            }
            if (!is_writeable(dirname($output_file_name))) {
                throw new \Exception(__('导出文件目录不可写! %1', dirname($output_file_name)));
            }
        }
        // 打开 PHP 输出流
        $output = fopen($output_file_name ?: "php://output", "w");

        // 写入 CSV 内容
        if ($model->getQuery() and $model->getQuery()->fetch_type == 'query') {
            $items = $model->fetchArray();
        } else {
            $items = $model->select()->fetchArray();
        }
        $columns_keys = array_keys($columns);
        $first_key = $columns_keys[0]??'';
        $key_is_string = !(is_numeric($first_key)??false);
        if($key_is_string){
            fputcsv($output, array_values($columns));
            $columns = $columns_keys;
        }else{
            fputcsv($output, $columns);
        }
        foreach ($items as $item) {
            foreach ($item as $k => $v) {
                if (!in_array($k, $columns)) {
                    unset($item[$k]);
                }
            }
            fputcsv($output, $item);
        }
        // 关闭输出流
        fclose($output);
        if ($is_download) {
            if ($output_file_name != 'php://output') {
                readfile($output_file_name);
                unlink($output_file_name);
            }
            exit();
        }
        return 'pub/' . str_replace(PUB, '', $output_file_name);
    }
}