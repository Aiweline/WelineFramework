<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Helper;

/**
 * 代码压缩工具
 * 压缩代码：去掉非引号内的非标签空格和换行
 */
class CodeMinifier
{
    /**
     * 压缩代码
     * 规则：
     * 1. 保留字符串内容（单引号、双引号内的内容）
     * 2. 保留 HTML/XML 标签内的结构
     * 3. 去除其他位置的空白字符和换行
     *
     * @param string $code 原始代码
     * @return string 压缩后的代码
     */
    public function minify(string $code): string
    {
        if (empty($code)) {
            return '';
        }

        $result = '';
        $length = strlen($code);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inTag = false;
        $inComment = false;
        $commentType = ''; // 'html' or 'php'
        $i = 0;

        while ($i < $length) {
            $char = $code[$i];
            $nextChar = $i + 1 < $length ? $code[$i + 1] : '';
            $prevChar = $i > 0 ? $code[$i - 1] : '';

            // 处理 HTML 注释 <!-- -->
            if (!$inSingleQuote && !$inDoubleQuote && !$inTag && $char === '<' && $nextChar === '!' && $i + 3 < $length && substr($code, $i, 4) === '<!--') {
                $inComment = true;
                $commentType = 'html';
                $result .= '<!--';
                $i += 4;
                continue;
            }

            if ($inComment && $commentType === 'html') {
                $result .= $char;
                if ($char === '-' && $nextChar === '-' && $i + 2 < $length && $code[$i + 2] === '>') {
                    $result .= '-->';
                    $i += 3;
                    $inComment = false;
                    continue;
                }
                $i++;
                continue;
            }

            // 处理 PHP 注释 // 和 /* */
            if (!$inSingleQuote && !$inDoubleQuote && !$inTag && !$inComment) {
                if ($char === '/' && $nextChar === '/') {
                    // 单行注释，跳过到行尾
                    while ($i < $length && $code[$i] !== "\n") {
                        $i++;
                    }
                    continue;
                }
                if ($char === '/' && $nextChar === '*') {
                    // 多行注释
                    $inComment = true;
                    $commentType = 'php';
                    $i += 2;
                    continue;
                }
                if ($inComment && $commentType === 'php' && $char === '*' && $nextChar === '/') {
                    $inComment = false;
                    $i += 2;
                    continue;
                }
                if ($inComment && $commentType === 'php') {
                    $i++;
                    continue;
                }
            }

            // 处理标签 < >
            if (!$inSingleQuote && !$inDoubleQuote && !$inComment) {
                if ($char === '<' && !$inTag) {
                    $inTag = true;
                    $result .= $char;
                    $i++;
                    continue;
                }
                if ($char === '>' && $inTag) {
                    $inTag = false;
                    $result .= $char;
                    $i++;
                    continue;
                }
            }

            // 在标签内，保留所有内容
            if ($inTag) {
                $result .= $char;
                $i++;
                continue;
            }

            // 处理单引号字符串
            if (!$inDoubleQuote && !$inComment && $char === "'" && ($i === 0 || $code[$i - 1] !== '\\')) {
                $inSingleQuote = !$inSingleQuote;
                $result .= $char;
                $i++;
                continue;
            }

            // 处理双引号字符串
            if (!$inSingleQuote && !$inComment && $char === '"' && ($i === 0 || $code[$i - 1] !== '\\')) {
                $inDoubleQuote = !$inDoubleQuote;
                $result .= $char;
                $i++;
                continue;
            }

            // 在字符串内，保留所有内容
            if ($inSingleQuote || $inDoubleQuote) {
                $result .= $char;
                $i++;
                continue;
            }

            // 去除空白字符和换行（非引号内、非标签内）
            if (preg_match('/\s/', $char)) {
                $i++;
                continue;
            }

            // 保留其他字符
            $result .= $char;
            $i++;
        }

        return $result;
    }

    /**
     * 查找代码片段在压缩后的代码中的所有匹配位置
     *
     * @param string $minifiedCode 压缩后的代码
     * @param string $targetSnippet 目标代码片段（需要先压缩）
     * @return array 匹配位置数组，每个元素包含 ['start' => 开始位置, 'end' => 结束位置, 'index' => 索引（从1开始）]
     */
    public function findMatches(string $minifiedCode, string $targetSnippet): array
    {
        $matches = [];
        $targetMinified = $this->minify($targetSnippet);
        $targetLength = strlen($targetMinified);

        if (empty($targetMinified)) {
            return $matches;
        }

        $offset = 0;
        $index = 1;

        while (($pos = strpos($minifiedCode, $targetMinified, $offset)) !== false) {
            $matches[] = [
                'start' => $pos,
                'end' => $pos + $targetLength - 1,
                'index' => $index++
            ];
            $offset = $pos + 1;
        }

        return $matches;
    }

    /**
     * 根据位置参数获取要匹配的索引列表
     *
     * @param string $position 位置参数：all/1/2-3
     * @param int $totalMatches 总匹配数
     * @return array 索引列表（从1开始）
     */
    public function getPositionIndexes(string $position, int $totalMatches): array
    {
        if ($position === 'all' || empty($position)) {
            return range(1, $totalMatches);
        }

        // 处理范围：1-3
        if (strpos($position, '-') !== false) {
            [$start, $end] = explode('-', $position, 2);
            $start = (int)trim($start);
            $end = (int)trim($end);
            if ($start < 1) {
                $start = 1;
            }
            if ($end > $totalMatches) {
                $end = $totalMatches;
            }
            if ($start > $end) {
                return [];
            }
            return range($start, $end);
        }

        // 处理单个索引：1
        $index = (int)trim($position);
        if ($index < 1 || $index > $totalMatches) {
            return [];
        }
        return [$index];
    }
}

