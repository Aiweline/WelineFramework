<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Service;

use Weline\Sticker\Helper\CodeMinifier;

/**
 * 规则解析服务
 * 解析 Sticker 文件中的 w:sticker 标签
 */
class RuleParser
{
    private CodeMinifier $codeMinifier;

    public function __construct(CodeMinifier $codeMinifier)
    {
        $this->codeMinifier = $codeMinifier;
    }

    /**
     * 解析 Sticker 文件，提取所有 w:sticker 规则
     *
     * @param string $stickerFilePath Sticker 文件路径
     * @return array 返回规则数组：
     * [
     *   [
     *     'type' => 'replace',  // action: replace/before/after
     *     'target' => '...',     // 目标代码片段（压缩后）
     *     'code' => '...',       // 修改后的代码
     *     'position' => 'all'    // 位置参数：all/1/2-3
     *   ],
     *   ...
     * ]
     */
    public function parseStickerFile(string $stickerFilePath): array
    {
        if (!file_exists($stickerFilePath)) {
            return [];
        }

        $content = file_get_contents($stickerFilePath);
        if (empty($content)) {
            return [];
        }

        return $this->parseContent($content);
    }

    /**
     * 解析内容，提取 w:sticker 标签
     *
     * @param string $content 文件内容
     * @return array
     */
    private function parseContent(string $content): array
    {
        $rules = [];

        // 使用正则表达式匹配 w:sticker 标签
        // 匹配格式：<w:sticker action="replace" position="1">...</w:sticker>
        $pattern = '/<w:sticker\s+([^>]*)>(.*?)<\/w:sticker>/is';

        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $rules;
        }

        foreach ($matches as $match) {
            $attributes = $match[1];
            $innerContent = $match[2];

            // 解析属性
            $action = $this->parseAttribute($attributes, 'action', 'replace');
            $position = $this->parseAttribute($attributes, 'position', 'all');

            // 解析子标签
            $targetCode = $this->extractSubTagContent($innerContent, 'w:sticker:target');
            $modifyCode = $this->extractSubTagContent($innerContent, 'w:sticker:code');

            if (empty($targetCode)) {
                continue; // 跳过没有目标代码的规则
            }

            // 压缩目标代码和修改代码
            $minifiedTarget = $this->codeMinifier->minify($targetCode);
            $minifiedModify = $this->codeMinifier->minify($modifyCode);

            $rules[] = [
                'type' => $action,
                'target' => $minifiedTarget,
                'code' => $minifiedModify,
                'position' => $position,
                'target_original' => $targetCode, // 保留原始代码用于错误提示
                'code_original' => $modifyCode
            ];
        }

        return $rules;
    }

    /**
     * 解析属性值
     *
     * @param string $attributes 属性字符串
     * @param string $name 属性名
     * @param string $default 默认值
     * @return string
     */
    private function parseAttribute(string $attributes, string $name, string $default = ''): string
    {
        if (preg_match('/' . preg_quote($name, '/') . '=["\']([^"\']*)["\']/i', $attributes, $matches)) {
            return trim($matches[1]);
        }
        return $default;
    }

    /**
     * 提取子标签内容
     *
     * @param string $content 父标签内容
     * @param string $tagName 子标签名
     * @return string
     */
    private function extractSubTagContent(string $content, string $tagName): string
    {
        // 匹配子标签：<w:sticker:target>...</w:sticker:target>
        $pattern = '/<' . preg_quote($tagName, '/') . '>(.*?)<\/' . preg_quote($tagName, '/') . '>/is';

        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * 验证规则是否有效
     *
     * @param array $rule 规则数组
     * @return bool
     */
    public function validateRule(array $rule): bool
    {
        // 必须有类型
        if (empty($rule['type']) || !in_array($rule['type'], ['replace', 'before', 'after'])) {
            return false;
        }

        // 必须有目标代码
        if (empty($rule['target'])) {
            return false;
        }

        // 位置参数验证
        $position = $rule['position'] ?? 'all';
        if ($position !== 'all' && !preg_match('/^\d+(-\d+)?$/', $position)) {
            return false;
        }

        return true;
    }
}

