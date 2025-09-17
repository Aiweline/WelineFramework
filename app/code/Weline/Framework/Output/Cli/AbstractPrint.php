<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Output\Cli;

abstract class AbstractPrint implements PrintInterface
{
    public $out;
    
    // 进度条相关属性
    private $progressBar = null;
    private $spinnerChars = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private $spinnerIndex = 0;
    private $lastProgressTime = 0;
    
    // 终端检测
    private $isTerminal = null;
    private $terminalWidth = null;

    /**
     * @DESC         |错误
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array|string $data
     * @param string       $message
     * @param string       $color
     * @param int          $pad_length
     *
     * @return mixed|void
     */
    public function error($data = 'CLI Error!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::ERROR);
    }

    /**
     * @DESC         |错误
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array|string $data
     * @param string       $message
     * @param string       $color
     * @param int          $pad_length
     *
     * @return mixed|void
     */
    public function setup($data = 'CLI Red!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::ERROR);
    }

    /**
     * @DESC         |成功
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $data
     * @param string $message
     * @param string $color
     * @param int    $pad_length
     *
     * @return mixed|void
     */
    public function success(string $data = 'CLI Success!', string $message = '', string $color = self::ERROR, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::SUCCESS);
    }

    /**
     * @DESC         |警告
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $data
     * @param string $message
     * @param string $color
     * @param int    $pad_length
     *
     * @return mixed|void
     */
    public function warning(string $data = 'CLI Warning!', string $message = '', string $color = self::WARNING, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::WARNING);
    }

    /**
     * @DESC         |提示
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $data
     * @param string $message
     * @param string $color
     * @param int    $pad_length
     *
     * @return mixed|void
     */
    public function note(string $data = 'CLI Note!', string $message = '', string $color = self::NOTE, int $pad_length = 25)
    {
        $this->doPrint($data, $message, self::NOTE);
    }

    /**
     * ----------------辅助方法-------------------
     *
     * @param mixed $data
     * @param mixed $message
     * @param mixed $color
     * @param mixed $pad_length
     */

    /**
     * @DESC         |方法描述
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string|array $data
     * @param string       $message
     * @param string       $color
     * @param int          $pad_length
     */
    private function doPrint($data, $message, $color, $pad_length = 0)
    {
        $message = $message ? $this->colorize($message, $color) : '';
        if (is_array($data)) {
            foreach ($data as $msg) {
                $this->printing($msg, $message, $color, $pad_length);
            }
        }
        $this->printing($data, $message, $color, $pad_length);
    }

    /**
     * @DESC         |打印消息
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $data
     * @param string $message
     * @param string $color
     * @param int    $pad_length
     */
    public function printing(string $data = 'CLI Printing!', string $message = '', string $color = self::NOTE, int $pad_length = 0)
    {
        $doc_tmp = ($message ? '【' . $message . '】：' : '') . $this->colorize(($pad_length ? str_pad($data, $pad_length) : $data), $color);
        $enter   = PHP_EOL;
        $doc     = <<<COMMAND_LIST
{$doc_tmp}{$enter}
COMMAND_LIST;
        echo $doc;
    }

    /**
     * @DESC         |打印消息
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param array  $data
     * @param string $flag
     * @param int    $pad_length
     */
    public function printList(array $data, $flag = '#', $pad_length = 45)
    {
        $doc_tmp = '';
        foreach ($data as $key => $datum) {
            if (is_int(strpos($key, $flag))) {
                $key = explode($flag, $key);
                $key = str_pad($key[0], $pad_length / 1.5) . 'module # ' . (str_replace('\\', '_', $key[1]));
            }
            $doc_tmp .= $this->colorize($key, self::WARNING) . PHP_EOL;
            if (is_string($datum)) {
                $doc_tmp .= $this->colorize($datum, self::NOTE) . PHP_EOL;
            }
            if (is_array($datum)) {
                foreach ($datum as $datum_key => $datum_value) {
                    if (!is_string($datum_value)) {
                        if (isset($datum_value['tip'])) {
                            $datum_value = $datum_value['tip'];
                        } else {
                            if (is_object($datum_value)) {
                                $datum_value = json_encode($datum_value);
                            }
                            if (is_array($datum_value)) {
                                $datum_value = json_encode($datum_value);
                            }
                        }
                    }
                    $doc_tmp .= '-' . str_pad($this->colorize($datum_key, self::SUCCESS), $pad_length) . $this->colorize($flag . ' ' . $datum_value, self::NOTE) . PHP_EOL;
                }
            }
        }
        $this->printing($doc_tmp);
    }

    /**
     * @DESC         |终端输出颜色字体
     *
     * @Author       秋枫雁飞
     * @Email        aiweline@qq.com
     * @Forum        https://bbs.aiweline.com
     * @Description  此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
     *
     * 参数区：
     *
     * @param string $text
     * @param string $status
     *
     * @return string
     */
    public function colorize($text, $status = 'Blue'): string
    {
        switch ($status) {
            case self::SUCCESS:
            case 'Green':
                $this->out = '[32m'; //Green

                break;
            case self::ERROR:
            case self::FAILURE:
            case 'Red':
                $this->out = '[31m'; //Red

                break;
            case self::WARNING:
            case 'Yellow':
                $this->out = '[33m'; //Yellow

                break;
            case self::NOTE:
            case 'Blue':
                $this->out = '[34m'; //Blue

                break;
            default:
                $this->out = '[31m'; //默认错误信息

                break;
        }

        return chr(27) . "{$this->out}" . "{$text}" . chr(27) . '[0m';
    }
    
    // ==================== 新增功能方法 ====================
    
    /**
     * 检测是否为终端环境
     * 
     * @return bool
     */
    private function isTerminal(): bool
    {
        if ($this->isTerminal === null) {
            $this->isTerminal = (php_sapi_name() === 'cli' && posix_isatty(STDOUT));
        }
        return $this->isTerminal;
    }
    
    /**
     * 获取终端宽度
     * 
     * @return int
     */
    private function getTerminalWidth(): int
    {
        if ($this->terminalWidth === null) {
            if ($this->isTerminal()) {
                $this->terminalWidth = (int)shell_exec('tput cols 2>/dev/null') ?: 80;
            } else {
                $this->terminalWidth = 80;
            }
        }
        return $this->terminalWidth;
    }
    
    /**
     * 清屏
     */
    public function clearScreen(): void
    {
        if ($this->isTerminal()) {
            echo "\033[2J\033[H";
        }
    }
    
    /**
     * 隐藏光标
     */
    public function hideCursor(): void
    {
        if ($this->isTerminal()) {
            echo "\033[?25l";
        }
    }
    
    /**
     * 显示光标
     */
    public function showCursor(): void
    {
        if ($this->isTerminal()) {
            echo "\033[?25h";
        }
    }
    
    /**
     * 移动光标到指定位置
     * 
     * @param int $row 行
     * @param int $col 列
     */
    public function moveCursor(int $row, int $col): void
    {
        if ($this->isTerminal()) {
            echo "\033[{$row};{$col}H";
        }
    }
    
    /**
     * 进度条显示
     * 
     * @param int $current 当前进度
     * @param int $total 总数
     * @param string $message 消息
     * @param int $width 进度条宽度
     */
    public function progressBar(int $current, int $total, string $message = '', int $width = 50): void
    {
        if (!$this->isTerminal()) {
            return;
        }
        
        $percentage = $total > 0 ? round(($current / $total) * 100) : 0;
        $filled = round(($current / $total) * $width);
        $bar = str_repeat('█', $filled) . str_repeat('░', $width - $filled);
        
        $output = "\r";
        if ($message) {
            $output .= $this->colorize($message, self::NOTE) . ' ';
        }
        $output .= "[{$bar}] {$percentage}% ({$current}/{$total})";
        
        echo $output;
        
        if ($current >= $total) {
            echo PHP_EOL;
        }
    }
    
    /**
     * 旋转加载动画
     * 
     * @param string $message 消息
     * @param bool $newLine 是否换行
     */
    public function spinner(string $message = '', bool $newLine = false): void
    {
        if (!$this->isTerminal()) {
            return;
        }
        
        $spinner = $this->spinnerChars[$this->spinnerIndex];
        $this->spinnerIndex = ($this->spinnerIndex + 1) % count($this->spinnerChars);
        
        $output = "\r" . $this->colorize($spinner, self::SUCCESS);
        if ($message) {
            $output .= ' ' . $this->colorize($message, self::NOTE);
        }
        
        echo $output;
        
        if ($newLine) {
            echo PHP_EOL;
        }
    }
    
    /**
     * 表格输出
     * 
     * @param array $headers 表头
     * @param array $rows 数据行
     * @param array $options 选项
     */
    public function table(array $headers, array $rows, array $options = []): void
    {
        $style = $options['style'] ?? 'default';
        $padding = $options['padding'] ?? 1;
        $border = $options['border'] ?? true;
        $maxWidth = $options['maxWidth'] ?? $this->getTerminalWidth();
        
        // 计算列宽（去除ANSI颜色代码）
        $columnWidths = [];
        foreach ($headers as $i => $header) {
            $columnWidths[$i] = $this->getStringLength($header);
        }
        
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $columnWidths[$i] = max($columnWidths[$i] ?? 0, $this->getStringLength($cell));
            }
        }
        
        // 添加padding
        foreach ($columnWidths as $i => $width) {
            $columnWidths[$i] += $padding * 2;
        }
        
        // 检查总宽度是否超过终端宽度，如果超过则调整列宽
        $totalWidth = array_sum($columnWidths) + count($columnWidths) + 1;
        if ($totalWidth > $maxWidth) {
            $columnWidths = $this->adjustColumnWidths($columnWidths, $maxWidth, count($headers));
        }
        
        // 输出表格
        if ($border) {
            $this->printTableBorder(array_sum($columnWidths) + count($columnWidths) + 1, $style);
        }
        
        // 表头
        $this->printTableRow($headers, $columnWidths, $style, true);
        
        if ($border) {
            $this->printTableSeparator($columnWidths, $style);
        }
        
        // 数据行
        foreach ($rows as $row) {
            $this->printTableRow($row, $columnWidths, $style);
        }
        
        if ($border) {
            $this->printTableBorder(array_sum($columnWidths) + count($columnWidths) + 1, $style);
        }
    }
    
    /**
     * 调整列宽以适应终端宽度
     * 
     * @param array $columnWidths 原始列宽
     * @param int $maxWidth 最大宽度
     * @param int $columnCount 列数
     * @return array 调整后的列宽
     */
    private function adjustColumnWidths(array $columnWidths, int $maxWidth, int $columnCount): array
    {
        $availableWidth = $maxWidth - $columnCount - 1; // 减去分隔符和边框
        $totalContentWidth = array_sum($columnWidths);
        
        if ($totalContentWidth <= $availableWidth) {
            return $columnWidths;
        }
        
        // 为不同列设置优先级和最大宽度（基于实际内容自适应）
        $columnPriorities = [
            0 => ['max' => 35, 'min' => 15], // 标识列
            1 => ['max' => 8, 'min' => 6],   // 状态列
            2 => ['max' => 18, 'min' => 8],  // 占用空间列
            3 => ['max' => 15, 'min' => 6],  // 可清理列
            4 => ['max' => 35, 'min' => 10]  // 描述列
        ];
        
        $adjustedWidths = [];
        $remainingWidth = $availableWidth;
        
        // 首先分配最小宽度
        foreach ($columnWidths as $i => $width) {
            $minWidth = $columnPriorities[$i]['min'] ?? 8;
            $adjustedWidths[$i] = $minWidth;
            $remainingWidth -= $minWidth;
        }
        
        // 然后按优先级分配剩余宽度，优先满足内容较长的列
        $totalExtra = array_sum($columnWidths) - array_sum($adjustedWidths);
        if ($totalExtra > 0 && $remainingWidth > 0) {
            // 按内容长度排序，优先分配空间给内容较长的列
            $sortedColumns = [];
            foreach ($columnWidths as $i => $width) {
                $sortedColumns[] = ['index' => $i, 'width' => $width, 'priority' => $columnPriorities[$i] ?? ['max' => $width, 'min' => 8]];
            }
            
            // 按内容长度降序排序
            usort($sortedColumns, function($a, $b) {
                return $b['width'] - $a['width'];
            });
            
            foreach ($sortedColumns as $column) {
                $i = $column['index'];
                $width = $column['width'];
                $maxWidth = $column['priority']['max'];
                $currentWidth = $adjustedWidths[$i];
                $extraNeeded = min($width - $currentWidth, $maxWidth - $currentWidth);
                $extraAllocated = min($extraNeeded, $remainingWidth);
                
                $adjustedWidths[$i] += $extraAllocated;
                $remainingWidth -= $extraAllocated;
                
                if ($remainingWidth <= 0) break;
            }
        }
        
        return $adjustedWidths;
    }
    
    /**
     * 获取字符串长度（去除ANSI颜色代码）
     * 
     * @param string $str 字符串
     * @return int 实际显示长度
     */
    private function getStringLength(string $str): int
    {
        // 去除ANSI颜色代码
        $cleanStr = preg_replace('/\033\[[0-9;]*m/', '', $str);
        return $this->getDisplayWidth($cleanStr);
    }
    
    /**
     * 获取字符串的显示宽度（考虑中英文字符宽度差异）
     * 
     * @param string $str 字符串
     * @return int 显示宽度
     */
    private function getDisplayWidth(string $str): int
    {
        $width = 0;
        $length = mb_strlen($str, 'UTF-8');
        
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $charWidth = $this->getCharWidth($char);
            $width += $charWidth;
        }
        
        return $width;
    }
    
    /**
     * 获取单个字符的显示宽度
     * 
     * @param string $char 字符
     * @return int 字符宽度
     */
    private function getCharWidth(string $char): int
    {
        // 使用 mb_strwidth 获取字符的显示宽度
        $width = mb_strwidth($char, 'UTF-8');
        
        // 特殊字符处理
        if ($width === 0) {
            // 控制字符、零宽度字符等
            return 0;
        } elseif ($width === 1) {
            // 半角字符：英文字母、数字、标点符号等
            return 1;
        } elseif ($width === 2) {
            // 全角字符：中文、日文、韩文、全角标点等
            return 2;
        } else {
            // 其他情况，按实际宽度返回
            return $width;
        }
    }
    
    /**
     * 打印表格行
     */
    private function printTableRow(array $row, array $columnWidths, string $style, bool $isHeader = false): void
    {
        $output = '│';
        foreach ($row as $i => $cell) {
            $width = $columnWidths[$i] ?? 10;
            $padded = $this->padString($cell, $width);
            if ($isHeader) {
                $padded = $this->colorize($padded, self::WARNING);
            }
            $output .= ' ' . $padded . ' │';
        }
        echo $output . PHP_EOL;
    }
    
    /**
     * 填充字符串到指定显示宽度
     * 
     * @param string $str 原字符串
     * @param int $width 目标宽度
     * @return string 填充后的字符串
     */
    private function padString(string $str, int $width): string
    {
        $currentWidth = $this->getDisplayWidth($str);
        if ($currentWidth >= $width) {
            return $str;
        }
        
        $padding = $width - $currentWidth;
        return $str . str_repeat(' ', $padding);
    }
    
    /**
     * 打印表格分隔线
     */
    private function printTableSeparator(array $columnWidths, string $style): void
    {
        $output = '├';
        foreach ($columnWidths as $width) {
            $output .= str_repeat('─', $width + 2) . '┼';
        }
        $output = rtrim($output, '┼') . '┤';
        echo $this->colorize($output, self::NOTE) . PHP_EOL;
    }
    
    /**
     * 打印表格边框
     */
    private function printTableBorder(int $width, string $style): void
    {
        $border = '┌' . str_repeat('─', $width - 2) . '┐';
        echo $this->colorize($border, self::NOTE) . PHP_EOL;
    }
    
    /**
     * 打印带颜色的文本
     * 
     * @param string $text 文本
     * @param string $color 颜色
     * @param string $style 样式
     */
    public function coloredText(string $text, string $color = self::NOTE, string $style = ''): void
    {
        $styledText = $this->colorize($text, $color);
        if ($style) {
            $styledText = $this->applyStyle($styledText, $style);
        }
        echo $styledText . PHP_EOL;
    }
    
    /**
     * 应用文本样式
     * 
     * @param string $text 文本
     * @param string $style 样式
     * @return string
     */
    private function applyStyle(string $text, string $style): string
    {
        if (!$this->isTerminal()) {
            return $text;
        }
        
        $styles = [
            'bold' => '1',
            'dim' => '2',
            'italic' => '3',
            'underline' => '4',
            'blink' => '5',
            'reverse' => '7',
            'strikethrough' => '9'
        ];
        
        if (isset($styles[$style])) {
            return "\033[{$styles[$style]}m{$text}\033[0m";
        }
        
        return $text;
    }
    
    /**
     * 打印分隔线
     * 
     * @param string $char 字符
     * @param int $length 长度
     * @param string $color 颜色
     */
    public function separator(string $char = '─', int $length = 0, string $color = self::NOTE): void
    {
        if ($length === 0) {
            $length = $this->getTerminalWidth();
        }
        
        $line = str_repeat($char, $length);
        echo $this->colorize($line, $color) . PHP_EOL;
    }
    
    /**
     * 打印标题
     * 
     * @param string $title 标题
     * @param string $char 装饰字符
     * @param string $color 颜色
     */
    public function title(string $title, string $char = '=', string $color = self::SUCCESS): void
    {
        $width = $this->getTerminalWidth();
        $titleLength = mb_strlen($title);
        $padding = ($width - $titleLength - 4) / 2;
        
        $line = str_repeat($char, $width);
        $titleLine = str_repeat($char, max(1, (int)$padding)) . ' ' . $title . ' ' . str_repeat($char, max(1, (int)$padding));
        
        echo $this->colorize($line, $color) . PHP_EOL;
        echo $this->colorize($titleLine, $color) . PHP_EOL;
        echo $this->colorize($line, $color) . PHP_EOL;
    }
    
    /**
     * 打印列表
     * 
     * @param array $items 项目列表
     * @param string $bullet 项目符号
     * @param string $color 颜色
     */
    public function list(array $items, string $bullet = '•', string $color = self::NOTE): void
    {
        foreach ($items as $item) {
            echo $this->colorize($bullet, $color) . ' ' . $item . PHP_EOL;
        }
    }
    
    /**
     * 打印键值对
     * 
     * @param array $pairs 键值对
     * @param string $separator 分隔符
     * @param int $keyWidth 键宽度
     */
    public function keyValue(array $pairs, string $separator = ':', int $keyWidth = 20): void
    {
        foreach ($pairs as $key => $value) {
            $paddedKey = str_pad($key, $keyWidth);
            $coloredKey = $this->colorize($paddedKey, self::WARNING);
            $coloredValue = $this->colorize($value, self::NOTE);
            echo "{$coloredKey} {$separator} {$coloredValue}" . PHP_EOL;
        }
    }
    
    /**
     * 打印成功消息（带图标）
     * 
     * @param string $message 消息
     */
    public function successIcon(string $message): void
    {
        echo $this->colorize('✅ ', self::SUCCESS) . $message . PHP_EOL;
    }
    
    /**
     * 打印错误消息（带图标）
     * 
     * @param string $message 消息
     */
    public function errorIcon(string $message): void
    {
        echo $this->colorize('❌ ', self::ERROR) . $message . PHP_EOL;
    }
    
    /**
     * 打印警告消息（带图标）
     * 
     * @param string $message 消息
     */
    public function warningIcon(string $message): void
    {
        echo $this->colorize('⚠️  ', self::WARNING) . $message . PHP_EOL;
    }
    
    /**
     * 打印信息消息（带图标）
     * 
     * @param string $message 消息
     */
    public function infoIcon(string $message): void
    {
        echo $this->colorize('ℹ️  ', self::NOTE) . $message . PHP_EOL;
    }
    
    /**
     * 打印加载消息（带图标）
     * 
     * @param string $message 消息
     */
    public function loadingIcon(string $message): void
    {
        echo $this->colorize('⏳ ', self::NOTE) . $message . PHP_EOL;
    }
    
    /**
     * 打印完成消息（带图标）
     * 
     * @param string $message 消息
     */
    public function doneIcon(string $message): void
    {
        echo $this->colorize('🎉 ', self::SUCCESS) . $message . PHP_EOL;
    }
    
    /**
     * 打印步骤消息
     * 
     * @param int $step 步骤号
     * @param int $total 总步骤数
     * @param string $message 消息
     */
    public function step(int $step, int $total, string $message): void
    {
        $stepText = "[{$step}/{$total}]";
        $coloredStep = $this->colorize($stepText, self::WARNING);
        echo "{$coloredStep} {$message}" . PHP_EOL;
    }
    
    /**
     * 打印时间戳消息
     * 
     * @param string $message 消息
     * @param string $color 颜色
     */
    public function timestamp(string $message, string $color = self::NOTE): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $coloredTimestamp = $this->colorize("[{$timestamp}]", self::WARNING);
        $coloredMessage = $this->colorize($message, $color);
        echo "{$coloredTimestamp} {$coloredMessage}" . PHP_EOL;
    }
    
    /**
     * 打印JSON格式数据
     * 
     * @param mixed $data 数据
     * @param bool $pretty 是否美化
     */
    public function json($data, bool $pretty = true): void
    {
        $flags = $pretty ? (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : JSON_UNESCAPED_UNICODE;
        $json = json_encode($data, $flags);
        echo $this->colorize($json, self::NOTE) . PHP_EOL;
    }
    
    /**
     * 打印调试信息
     * 
     * @param mixed $data 数据
     * @param string $label 标签
     */
    public function debug($data, string $label = 'DEBUG'): void
    {
        $coloredLabel = $this->colorize("[{$label}]", self::WARNING);
        echo "{$coloredLabel} ";
        
        if (is_string($data)) {
            echo $this->colorize($data, self::NOTE);
        } else {
            $this->json($data);
        }
        echo PHP_EOL;
    }
}
