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

class Tool
{
    static function sql2table($sql, string|array $exclude_expression = '')
    {
        $expression   = '/(SELECT|DELETE)(?:\s*\/\*.*\*\/\s*?)*\s+FROM*\s+([^\s\/*;]+)?|(?:(?:(CREATE|ALTER|DROP)(?:(?:\s*\/\*.*\*\/\s*?)*\s+OR(?:\s*\/\*.*\*\/\s*?)*\s+(REPLACE))?)(?:\s*\/\*.*\*\/\s*?)*\s+TABLE(?:(?:\s*\/\*.*\*\/\s*?)*\s+IF(?:\s*\/\*.*\*\/\s*?)*\s+EXISTS)?|(UPDATE)|(ALTER)|(INSERT)(?:\s*\/\*.*\*\/\s*?)*\s+(?:INTO?))(?:\s*\/\*.*\*\/\s*?)*\s+([^\s\/*;]+)|(?:(REPLACE)(?:\s*\/\*.*\*\/\s*?)*\s+(?:INTO?))(?:\s*\/\*.*\*\/\s*?)*\s+([^\s\/*;]+)(?:\s*\/\*.*\*\/\s*?)*\s+([^\s\/*;]+)/im';
        $ret          = preg_match_all($expression, $sql, $matches);
        $result       = [];
        $mathces_rows = array_shift($matches);
        foreach ($mathces_rows as $match_row_index => $match_row) {
            $have_content_times = 0;
            $action             = '';
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
}