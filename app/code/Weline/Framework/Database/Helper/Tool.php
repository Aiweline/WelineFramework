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
    static function sql2table($sql, string|array $exclude_expression = '')
    {
        $expression = '/(SELECT|DELETE)(?:\s*\/\*.*\*\/\s*?)*\s+FROM*\s+([^\s\/*;]+)?|(?:(?:(CREATE|ALTER|DROP)(?:(?:\s*\/\*.*\*\/\s*?)*\s+OR(?:\s*\/\*.*\*\/\s*?)*\s+(REPLACE))?)(?:\s*\/\*.*\*\/\s*?)*\s+TABLE(?:(?:\s*\/\*.*\*\/\s*?)*\s+IF(?:\s*\/\*.*\*\/\s*?)*\s+EXISTS)?|(UPDATE)|(ALTER)|(INSERT)(?:\s*\/\*.*\*\/\s*?)*\s+(?:INTO?))(?:\s*\/\*.*\*\/\s*?)*\s+([^\s\/*;]+)|(?:(REPLACE)(?:\s*\/\*.*\*\/\s*?)*\s+(?:INTO?))(?:\s*\/\*.*\*\/\s*?)*\s+([^\s\/*;]+)(?:\s*\/\*.*\*\/\s*?)*\s+([^\s\/*;]+)/im';
        $ret = preg_match_all($expression, $sql, $matches);
        $result = [];
        $mathces_rows = array_shift($matches);
        foreach ($mathces_rows as $match_row_index => $match_row) {
            $have_content_times = 0;
            $action = '';
            array_reverse($matches);
            foreach ($matches as $match) {
                $match_result = $match[$match_row_index];
                if (!empty($match_result) and strtolower($match_result) !== 'replace') {
                    $have_content_times++;
                    # 第一个值是动作
                    if ($have_content_times === 1) {
                        $action = strtolower($match_result);
                    }
                    # 第二个值是表名
                    if ($have_content_times >= 2) {
                        $result[$action][] = $match_result;
                        continue;
                    }
                }
            }
        }
        if ($exclude_expression) {
            if (is_string($exclude_expression)) {
                $exclude_expression = explode(',', $exclude_expression);
            }
            foreach ($exclude_expression as $item) {
                unset($result[$item]);
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