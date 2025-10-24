<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Console;

/**
 * @DESC         |命令帮助信息工具类
 *
 * @Author       秋枫雁飞
 * @Email        aiweline@qq.com
 * @Forum        https://bbs.aiweline.com
 * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 *
 * Class CommandHelper
 * @package      Weline\Framework\Console
 */
class CommandHelper
{
    /**
     * @DESC         |计算字符串的显示宽度（支持多字节字符）
     *
     * @param string $string 要计算的字符串
     * @return int 显示宽度
     */
    public static function getStringWidth(string $string): int
    {
        if (function_exists('mb_strwidth')) {
            return mb_strwidth($string, 'UTF-8');
        }
        
        // 如果不支持mb_strwidth，使用简单估算
        $width = 0;
        $len = mb_strlen($string, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($string, $i, 1, 'UTF-8');
            // 判断是否为中文、日文、韩文等宽字符
            if (preg_match('/[\x{4e00}-\x{9fa5}\x{3040}-\x{309f}\x{30a0}-\x{30ff}\x{ac00}-\x{d7af}]/u', $char)) {
                $width += 2; // 全角字符宽度为2
            } else {
                $width += 1; // 半角字符宽度为1
            }
        }
        return $width;
    }

    /**
     * @DESC         |填充字符串到指定显示宽度（支持多字节字符）
     *
     * @param string $string 要填充的字符串
     * @param int $width 目标宽度
     * @param string $pad_char 填充字符
     * @param int $pad_type 填充类型（STR_PAD_RIGHT, STR_PAD_LEFT, STR_PAD_BOTH）
     * @return string 填充后的字符串
     */
    public static function padString(string $string, int $width, string $pad_char = ' ', int $pad_type = STR_PAD_RIGHT): string
    {
        $currentWidth = self::getStringWidth($string);
        $padLength = $width - $currentWidth;
        
        if ($padLength <= 0) {
            return $string;
        }
        
        $padding = str_repeat($pad_char, $padLength);
        
        switch ($pad_type) {
            case STR_PAD_LEFT:
                return $padding . $string;
            case STR_PAD_BOTH:
                $leftPad = str_repeat($pad_char, (int)floor($padLength / 2));
                $rightPad = str_repeat($pad_char, (int)ceil($padLength / 2));
                return $leftPad . $string . $rightPad;
            case STR_PAD_RIGHT:
            default:
                return $string . $padding;
        }
    }

    /**
     * @DESC         |格式化命令帮助信息
     *
     * @param string $name 命令名称
     * @param string $description 命令描述
     * @param array $options 选项数组 ['option' => 'description']
     * @param array $arguments 参数数组 ['argument' => 'description']
     * @param array $examples 示例数组 ['description' => 'command']
     * @param string $usage 使用方法（可选）
     * @return string 格式化后的帮助信息
     */
    public static function formatHelp(
        string $name,
        string $description,
        array $options = [],
        array $arguments = [],
        array $examples = [],
        string $usage = ''
    ): string {
        $help = '';
        $lineWidth = 80;
        $separator = str_repeat('─', $lineWidth) . PHP_EOL;
        
        // 标题
        $help .= PHP_EOL;
        $help .= self::padString('命令名称', 12) . ': ' . $name . PHP_EOL;
        $help .= $separator;
        
        // 描述
        if ($description) {
            $help .= PHP_EOL . '📖 ' . __('描述') . ':' . PHP_EOL;
            $help .= self::wrapText($description, 4) . PHP_EOL;
        }
        
        // 使用方法
        if ($usage) {
            $help .= PHP_EOL . '🎯 ' . __('使用方法') . ':' . PHP_EOL;
            $help .= '  ' . $usage . PHP_EOL;
        } else {
            $help .= PHP_EOL . '🎯 ' . __('使用方法') . ':' . PHP_EOL;
            $help .= '  php bin/w ' . $name . ' [选项] [参数]' . PHP_EOL;
        }
        
        // 选项
        if (!empty($options)) {
            $help .= PHP_EOL . '🔧 ' . __('选项') . ':' . PHP_EOL;
            $maxOptionWidth = self::getMaxWidth(array_keys($options));
            foreach ($options as $option => $desc) {
                $paddedOption = self::padString('  ' . $option, $maxOptionWidth + 4);
                $help .= $paddedOption . $desc . PHP_EOL;
            }
        }
        
        // 参数
        if (!empty($arguments)) {
            $help .= PHP_EOL . '📝 ' . __('参数') . ':' . PHP_EOL;
            $maxArgWidth = self::getMaxWidth(array_keys($arguments));
            foreach ($arguments as $arg => $desc) {
                $paddedArg = self::padString('  ' . $arg, $maxArgWidth + 4);
                $help .= $paddedArg . $desc . PHP_EOL;
            }
        }
        
        // 示例
        if (!empty($examples)) {
            $help .= PHP_EOL . '💡 ' . __('示例') . ':' . PHP_EOL;
            foreach ($examples as $desc => $command) {
                if (is_numeric($desc)) {
                    // 如果没有描述，直接显示命令
                    $help .= '  ' . $command . PHP_EOL;
                } else {
                    // 有描述
                    $help .= PHP_EOL . '  ' . $desc . ':' . PHP_EOL;
                    $help .= '    ' . $command . PHP_EOL;
                }
            }
        }
        
        $help .= PHP_EOL . $separator;
        
        return $help;
    }

    /**
     * @DESC         |获取数组中字符串的最大显示宽度
     *
     * @param array $strings 字符串数组
     * @return int 最大宽度
     */
    private static function getMaxWidth(array $strings): int
    {
        $maxWidth = 0;
        foreach ($strings as $string) {
            $width = self::getStringWidth($string);
            if ($width > $maxWidth) {
                $maxWidth = $width;
            }
        }
        return $maxWidth;
    }

    /**
     * @DESC         |自动换行文本
     *
     * @param string $text 要换行的文本
     * @param int $indent 缩进空格数
     * @param int $maxWidth 最大宽度
     * @return string 换行后的文本
     */
    private static function wrapText(string $text, int $indent = 0, int $maxWidth = 76): string
    {
        $lines = explode("\n", $text);
        $result = '';
        $indentStr = str_repeat(' ', $indent);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                $result .= PHP_EOL;
                continue;
            }
            
            $currentLine = '';
            $currentWidth = 0;
            $words = preg_split('/\s+/', $line);
            
            foreach ($words as $word) {
                $wordWidth = self::getStringWidth($word);
                
                if ($currentWidth + $wordWidth + 1 > $maxWidth && $currentLine) {
                    $result .= $indentStr . $currentLine . PHP_EOL;
                    $currentLine = $word;
                    $currentWidth = $wordWidth;
                } else {
                    if ($currentLine) {
                        $currentLine .= ' ' . $word;
                        $currentWidth += $wordWidth + 1;
                    } else {
                        $currentLine = $word;
                        $currentWidth = $wordWidth;
                    }
                }
            }
            
            if ($currentLine) {
                $result .= $indentStr . $currentLine . PHP_EOL;
            }
        }
        
        return $result;
    }

    /**
     * @DESC         |解析数组形式的help信息并格式化
     *
     * @param array $helpData help数据数组
     * @return string 格式化后的帮助信息
     */
    public static function parseHelpArray(array $helpData): string
    {
        return self::formatHelp(
            $helpData['name'] ?? '',
            $helpData['description'] ?? '',
            $helpData['options'] ?? [],
            $helpData['arguments'] ?? [],
            $helpData['examples'] ?? [],
            $helpData['usage'] ?? ''
        );
    }
}

