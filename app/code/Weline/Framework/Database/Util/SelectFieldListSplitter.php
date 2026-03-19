<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Util;

/**
 * SELECT 列表按「顶层逗号」拆分，忽略括号内的逗号（如 COALESCE(SUM(x), 0)）。
 * AST、编译器、各适配器 fields() 合并逻辑须与此一致，禁止对整段 fields 做简单 explode(',')。
 */
final class SelectFieldListSplitter
{
    /**
     * @return list<string>
     */
    public static function split(string $fields): array
    {
        $fields = trim($fields);
        if ($fields === '') {
            return [];
        }

        $parts = [];
        $depth = 0;
        $current = '';
        $len = strlen($fields);
        for ($i = 0; $i < $len; $i++) {
            $c = $fields[$i];
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($c === ',' && 0 === $depth) {
                $t = trim($current);
                if ($t !== '') {
                    $parts[] = $t;
                }
                $current = '';
                continue;
            }
            $current .= $c;
        }
        $t = trim($current);
        if ($t !== '') {
            $parts[] = $t;
        }

        return $parts;
    }
}
