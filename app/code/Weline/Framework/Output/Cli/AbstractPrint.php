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
        // 如果数据是命令格式，使用自适应宽度
        if ($this->isCommandListFormat($data)) {
            $this->printAdaptiveCommandList($data, $flag, $pad_length);
            return;
        }
        
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
     * 检查是否为命令列表格式
     */
    private function isCommandListFormat(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['tip']) && isset($value['class'])) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 打印自适应宽度的命令列表
     */
    private function printAdaptiveCommandList(array $data, $flag = '#', $pad_length = 45): void
    {
        // 计算最长命令的长度
        $maxCommandLength = 0;
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['tip'])) {
                $maxCommandLength = max($maxCommandLength, strlen($key));
            }
        }
        
        // 设置命令列宽度（最长命令长度 + 4个字符的额外填充）
        $commandWidth = $maxCommandLength + 4;
        
        $doc_tmp = '';
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['tip'])) {
                $paddedCommand = str_pad($key, $commandWidth);
                $coloredCommand = $this->colorize($paddedCommand, self::SUCCESS);
                $coloredDescription = $this->colorize($value['tip'], self::NOTE);
                $doc_tmp .= $coloredCommand . $coloredDescription . PHP_EOL;
            } else {
                // 非命令格式，使用原有逻辑
                $doc_tmp .= $this->colorize($key, self::WARNING) . PHP_EOL;
                if (is_string($value)) {
                    $doc_tmp .= $this->colorize($value, self::NOTE) . PHP_EOL;
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
            // 检查是否为 CLI 环境
            if (php_sapi_name() !== 'cli') {
                $this->isTerminal = false;
                return $this->isTerminal;
            }
            
            // 检查 posix_isatty 函数是否可用（Windows 下可能不可用）
            if (function_exists('posix_isatty')) {
                $this->isTerminal = posix_isatty(STDOUT);
            } else {
                // Windows 下的替代方案：检查环境变量和流类型
                $this->isTerminal = (
                    stream_isatty(STDOUT) || 
                    (isset($_SERVER['TERM']) && $_SERVER['TERM'] !== 'dumb') ||
                    (isset($_SERVER['ANSICON']) || isset($_SERVER['ConEmuANSI']))
                );
            }
            
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
                // 尝试获取终端宽度
                $width = $this->getTerminalWidthFromSystem();
                $this->terminalWidth = $width ?: 80;
            } else {
                $this->terminalWidth = 80;
            }
        }
        return $this->terminalWidth;
    }
    
    /**
     * 从系统获取终端宽度
     * 
     * @return int
     */
    private function getTerminalWidthFromSystem(): int
    {
        // 检查 shell_exec 是否可用
        if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
            // 如果 shell_exec 不可用，使用默认值
            return PHP_OS_FAMILY === 'Windows' ? 120 : 80;
        }
        
        // Windows 系统
        if (PHP_OS_FAMILY === 'Windows') {
            // 尝试使用 mode 命令获取控制台宽度
            $output = \shell_exec('mode con 2>nul | findstr "列"');
            if ($output && preg_match('/(\d+)/', $output, $matches)) {
                return (int)$matches[1];
            }
            
            // 尝试使用 PowerShell 获取
            $output = \shell_exec('powershell -Command "Write-Host $Host.UI.RawUI.WindowSize.Width" 2>nul');
            if ($output && is_numeric(trim($output))) {
                return (int)trim($output);
            }
            
            // 默认返回 120（Windows 控制台默认宽度）
            return 120;
        }
        
        // Unix/Linux 系统
        // 尝试使用 tput 命令
        $output = \shell_exec('tput cols 2>/dev/null');
        if ($output && is_numeric(trim($output))) {
            return (int)trim($output);
        }
        
        // 尝试使用 stty 命令
        $output = \shell_exec('stty size 2>/dev/null');
        if ($output && preg_match('/\d+\s+(\d+)/', $output, $matches)) {
            return (int)$matches[1];
        }
        
        // 尝试使用 COLUMNS 环境变量
        if (isset($_SERVER['COLUMNS']) && is_numeric($_SERVER['COLUMNS'])) {
            return (int)$_SERVER['COLUMNS'];
        }
        
        return 0;
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
    public function title(string $title, string $char = '-', string $color = self::SUCCESS): void
    {
        $width = $this->getTerminalWidth();
        $titleLength = mb_strlen($title);
        $padding = ($width - $titleLength - 4) / 2;
        
        $line = str_repeat($char, $width);
        $titleLine = str_repeat($char, max(1, (int)$padding)) . ' ' . $title . ' ' . str_repeat($char, max(1, (int)$padding));
        
        // echo $this->colorize($line, $color) . PHP_EOL;
        echo $this->colorize($titleLine, $color) . PHP_EOL;
        // echo $this->colorize($line, $color) . PHP_EOL;
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
     * 树形目录显示命令列表
     * 
     * @param array $recommendations 推荐命令列表，按分组组织
     * @param string $color 颜色
     */
    public function treeList(array $recommendations, string $color = self::NOTE): void
    {
        foreach ($recommendations as $group => $commands) {
            // 显示分组标题
            $this->printing($this->colorize("📁 {$group}", self::WARNING) . PHP_EOL);
            
            // 构建命令树
            $commandTree = $this->buildCommandTree($commands);
            $this->printTree($commandTree, '', true, $color);
            
            // 分组间添加空行
            $this->printing(PHP_EOL);
        }
    }
    
    /**
     * 构建命令树结构
     * 
     * @param array $commands 命令数组
     * @return array 树形结构
     */
    private function buildCommandTree(array $commands): array
    {
        $tree = [];
        
        foreach ($commands as $cmd => $data) {
            $parts = explode(':', $cmd);
            $current = &$tree;
            
            // 构建树形结构，只有当有多个相同前缀的命令时才创建目录
            for ($i = 0; $i < count($parts); $i++) {
                $part = $parts[$i];
                $isLast = ($i === count($parts) - 1);
                
                // 检查是否需要创建目录节点
                $shouldCreateDirectory = $this->shouldCreateDirectory($commands, $parts, $i);
                
                if (!isset($current[$part])) {
                    $current[$part] = [
                        'is_command' => $isLast,
                        'children' => [],
                        'data' => $isLast ? $data : null,
                        'full_command' => $cmd,
                        'is_directory' => !$isLast && $shouldCreateDirectory
                    ];
                }
                
                if ($isLast) {
                    $current[$part]['is_command'] = true;
                    $current[$part]['data'] = $data;
                    $current[$part]['full_command'] = $cmd;
                }
                
                $current = &$current[$part]['children'];
            }
        }
        
        return $tree;
    }

    /**
     * 判断是否应该创建目录节点
     * 
     * @param array $commands 所有命令
     * @param array $parts 当前命令的部分
     * @param int $index 当前索引
     * @return bool 是否应该创建目录
     */
    private function shouldCreateDirectory(array $commands, array $parts, int $index): bool
    {
        if ($index === 0) {
            // 第一级总是创建目录
            return true;
        }
        
        // 构建当前路径
        $currentPath = implode(':', array_slice($parts, 0, $index + 1));
        
        // 统计有多少命令以当前路径开头
        $count = 0;
        foreach ($commands as $cmd => $data) {
            if (strpos($cmd, $currentPath . ':') === 0) {
                $count++;
            }
        }
        
        // 只有当有多个子命令时才创建目录
        return $count > 1;
    }
    
    /**
     * 打印树形结构
     * 
     * @param array $tree 树形数据
     * @param string $prefix 前缀
     * @param bool $isLast 是否为最后一个节点
     * @param string $color 颜色
     * @param string $parentKey 父级键名，用于判断是否重复
     */
    private function printTree(array $tree, string $prefix = '', bool $isLast = true, string $color = self::NOTE, string $parentKey = ''): void
    {
        $keys = array_keys($tree);
        $lastKey = end($keys);
        
        foreach ($tree as $key => $node) {
            $isLastNode = ($key === $lastKey);
            
            // 确定连接符
            $connector = $isLastNode ? '└── ' : '├── ';
            $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
            
            if ($node['is_command']) {
                // 这是一个完整的命令
                $command = $node['full_command'];
                $description = $node['data']['tip'] ?? '';
                
                $coloredCommand = $this->colorize($command, self::SUCCESS);
                $coloredDescription = $description ? $this->colorize(' - ' . $description, $color) : '';
                
                $this->printing($prefix . $connector . $coloredCommand . $coloredDescription . PHP_EOL);
            } elseif (isset($node['is_directory']) && $node['is_directory']) {
                // 只有当目录名称与父级不同时才显示目录节点
                // 第一级目录总是显示，其他级别只有当与父级不同时才显示
                if ($parentKey === '' || $key !== $parentKey) {
                    $coloredDir = $this->colorize($key, self::WARNING);
                    $this->printing($prefix . $connector . $coloredDir . PHP_EOL);
                }
            }
            
            // 递归打印子节点
            if (!empty($node['children'])) {
                $this->printTree($node['children'], $newPrefix, $isLastNode, $color, $key);
            }
        }
    }

    /**
     * 简洁的树形目录显示命令列表
     * 
     * @param array $recommendations 推荐命令列表，按分组组织
     * @param string $color 颜色
     */
    public function simpleTreeList(array $recommendations, string $color = self::NOTE): void
    {
        foreach ($recommendations as $group => $commands) {
            // 显示分组标题
            $this->printing($this->colorize("📁 {$group}", self::WARNING) . PHP_EOL);
            
            // 直接显示命令，使用缩进表示层级
            foreach ($commands as $cmd => $data) {
                $parts = explode(':', $cmd);
                $indent = str_repeat('  ', count($parts) - 1);
                $lastPart = end($parts);
                
                $description = is_array($data) && isset($data['tip']) ? $data['tip'] : '';
                
                $coloredCommand = $this->colorize($cmd, self::SUCCESS);
                $coloredDescription = $description ? $this->colorize(' - ' . $description, $color) : '';
                
                $this->printing($indent . '└── ' . $coloredCommand . $coloredDescription . PHP_EOL);
            }
            
            // 分组间添加空行
            $this->printing(PHP_EOL);
        }
    }

    /**
     * 按命令前缀分组的树形目录显示命令列表
     * 
     * @param array $recommendations 推荐命令列表，按分组组织
     * @param string $color 颜色
     */
    public function groupedTreeList(array $recommendations, string $color = self::NOTE): void
    {
        // 重新组织命令，按命令前缀分组而不是模块分组
        $prefixGroups = $this->reorganizeByCommandPrefix($recommendations);
        
        foreach ($prefixGroups as $prefix => $commands) {
            // 显示分组标题
            $this->printing($this->colorize("📁 {$prefix}", self::WARNING) . PHP_EOL);
            
            // 直接显示命令，不进行二次分组
            $this->printCommandsDirectly($commands, '', true, $color);
            
            // 分组间添加空行
            $this->printing(PHP_EOL);
        }
    }

    /**
     * 按命令前缀重新组织所有命令
     * 
     * @param array $recommendations 原始推荐命令列表
     * @return array 按命令前缀分组的命令
     */
    private function reorganizeByCommandPrefix(array $recommendations): array
    {
        $prefixGroups = [];
        
        foreach ($recommendations as $group => $commands) {
            foreach ($commands as $cmd => $data) {
                $parts = explode(':', $cmd);
                $prefix = $parts[0]; // 取命令的第一个部分作为前缀
                
                if (!isset($prefixGroups[$prefix])) {
                    $prefixGroups[$prefix] = [];
                }
                
                $prefixGroups[$prefix][$cmd] = $data;
            }
        }
        
        return $prefixGroups;
    }

    /**
     * 直接打印命令列表，按冒号重新组织成树形结构
     * 
     * @param array $commands 命令数组
     * @param string $prefix 前缀
     * @param bool $isLast 是否为最后一个节点
     * @param string $color 颜色
     */
    private function printCommandsDirectly(array $commands, string $prefix = '', bool $isLast = true, string $color = self::NOTE): void
    {
        // 按冒号重新组织成树形结构
        $tree = $this->buildColonTree($commands);
        
        // 打印树形结构
        $this->printColonTree($tree, $prefix, $isLast, $color);
    }

    /**
     * 按冒号构建树形结构
     * 
     * @param array $commands 命令数组
     * @return array 树形结构
     */
    private function buildColonTree(array $commands): array
    {
        $tree = [];
        
        foreach ($commands as $cmd => $data) {
            $parts = explode(':', $cmd);
            $current = &$tree;
            
            // 构建树形结构
            for ($i = 0; $i < count($parts); $i++) {
                $part = $parts[$i];
                $isLast = ($i === count($parts) - 1);
                
                if (!isset($current[$part])) {
                    $current[$part] = [
                        'is_leaf' => $isLast,
                        'children' => [],
                        'data' => $isLast ? $data : null,
                        'full_command' => $cmd
                    ];
                }
                
                if ($isLast) {
                    $current[$part]['is_leaf'] = true;
                    $current[$part]['data'] = $data;
                    $current[$part]['full_command'] = $cmd;
                }
                
                $current = &$current[$part]['children'];
            }
        }
        
        return $tree;
    }

    /**
     * 打印冒号树形结构
     * 
     * @param array $tree 树形数据
     * @param string $prefix 前缀
     * @param bool $isLast 是否为最后一个节点
     * @param string $color 颜色
     * @param string $parentKey 父级键名，用于判断是否重复
     * @param bool $isFirstLevel 是否为第一级，用于判断是否显示重复的分组名称
     */
    private function printColonTree(array $tree, string $prefix = '', bool $isLast = true, string $color = self::NOTE, string $parentKey = '', bool $isFirstLevel = true): void
    {
        $keys = array_keys($tree);
        $lastKey = end($keys);
        
        foreach ($tree as $key => $node) {
            $isLastNode = ($key === $lastKey);
            
            // 确定连接符
            $connector = $isLastNode ? '└── ' : '├── ';
            $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
            
            if ($node['is_leaf']) {
                // 这是一个叶子节点（完整命令）
                $command = $node['full_command'];
                $description = $node['data']['tip'] ?? '';
                
                $coloredCommand = $this->colorize($command, self::SUCCESS);
                $coloredDescription = $description ? $this->colorize(' - ' . $description, $color) : '';
                
                $this->printing($prefix . $connector . $coloredCommand . $coloredDescription . PHP_EOL);
            } else {
                // 只有当有多个子节点时才显示分支节点，否则直接显示叶子节点
                if (count($node['children']) > 1) {
                    // 第一级不显示重复的分组名称，其他级别只有当分支名称与父级不同时才显示分支节点
                    if (!$isFirstLevel && $key !== $parentKey) {
                        $coloredBranch = $this->colorize($key, self::WARNING);
                        $this->printing($prefix . $connector . $coloredBranch . PHP_EOL);
                    }
                    
                    // 递归打印子节点
                    $this->printColonTree($node['children'], $newPrefix, $isLastNode, $color, $key, false);
                } else {
                    // 只有一个子节点，直接显示叶子节点
                    $this->printColonTree($node['children'], $prefix, $isLastNode, $color, $key, false);
                }
            }
        }
    }

    /**
     * 按命令前缀分组命令
     * 
     * @param array $commands 命令数组
     * @return array 分组后的命令
     */
    private function groupCommandsByPrefix(array $commands): array
    {
        $grouped = [];
        
        foreach ($commands as $cmd => $data) {
            $parts = explode(':', $cmd);
            
            if (count($parts) === 1) {
                // 单段命令直接显示
                $grouped[$cmd] = $data;
            } else {
                // 多段命令按前缀分组，但避免重复显示前缀
                $prefix = $parts[0];
                $suffix = implode(':', array_slice($parts, 1));
                
                if (!isset($grouped[$prefix])) {
                    $grouped[$prefix] = [];
                }
                
                // 如果后缀不是以相同前缀开头，直接添加
                if (!str_starts_with($suffix, $prefix . ':')) {
                    $grouped[$prefix][$suffix] = $data;
                } else {
                    // 如果后缀以相同前缀开头，去掉重复的前缀
                    $cleanSuffix = substr($suffix, strlen($prefix) + 1);
                    $grouped[$prefix][$cleanSuffix] = $data;
                }
            }
        }
        
        return $grouped;
    }

    /**
     * 打印分组后的命令树
     * 
     * @param array $groupedCommands 分组后的命令
     * @param string $prefix 前缀
     * @param bool $isLast 是否为最后一个节点
     * @param string $color 颜色
     * @param string $parentPrefix 父级前缀，用于判断是否重复
     */
    private function printGroupedCommands(array $groupedCommands, string $prefix = '', bool $isLast = true, string $color = self::NOTE, string $parentPrefix = ''): void
    {
        $keys = array_keys($groupedCommands);
        $lastKey = end($keys);
        
        foreach ($groupedCommands as $key => $value) {
            $isLastNode = ($key === $lastKey);
            
            // 确定连接符
            $connector = $isLastNode ? '└── ' : '├── ';
            $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
            
            if (is_array($value) && !empty($value) && !isset($value['tip'])) {
                // 这是一个分组节点（包含子命令的数组）
                // 如果分组名称和父级前缀相同，直接显示子命令，不显示重复的分组名称
                if ($key === $parentPrefix) {
                    $this->printGroupedCommands($value, $prefix, $isLastNode, $color, $key);
                } else {
                    $coloredGroup = $this->colorize($key, self::WARNING);
                    $this->printing($prefix . $connector . $coloredGroup . PHP_EOL);
                    // 递归打印子节点
                    $this->printGroupedCommands($value, $newPrefix, $isLastNode, $color, $key);
                }
            } else {
                // 这是一个命令节点
                $description = is_array($value) && isset($value['tip']) ? $value['tip'] : '';
                
                $coloredCommand = $this->colorize($key, self::SUCCESS);
                $coloredDescription = $description ? $this->colorize(' - ' . $description, $color) : '';
                
                $this->printing($prefix . $connector . $coloredCommand . $coloredDescription . PHP_EOL);
            }
        }
    }

    /**
     * 自适应宽度的命令列表显示
     * 
     * @param array $items 命令项数组，格式：[['command' => 'cmd', 'description' => 'desc'], ...]
     * @param string $bullet 项目符号
     * @param string $color 颜色
     * @param int $extraPadding 额外填充字符数
     */
    public function adaptiveList(array $items, string $bullet = '•', string $color = self::NOTE, int $extraPadding = 4): void
    {
        if (empty($items)) {
            return;
        }
        
        // 计算最长命令的长度
        $maxCommandLength = 0;
        foreach ($items as $item) {
            if (is_string($item)) {
                // 如果是字符串格式 "command - description"
                $parts = explode(' - ', $item, 2);
                if (count($parts) >= 2) {
                    $commandLength = strlen($parts[0]);
                    $maxCommandLength = max($maxCommandLength, $commandLength);
                }
            } elseif (is_array($item) && isset($item['command'])) {
                // 如果是数组格式 ['command' => 'cmd', 'description' => 'desc']
                $commandLength = strlen($item['command']);
                $maxCommandLength = max($maxCommandLength, $commandLength);
            }
        }
        
        // 设置命令列宽度（最长命令长度 + 额外填充）
        $commandWidth = $maxCommandLength + $extraPadding;
        
        foreach ($items as $item) {
            if (is_string($item)) {
                // 处理字符串格式
                $parts = explode(' - ', $item, 2);
                if (count($parts) >= 2) {
                    $command = $parts[0];
                    $description = $parts[1];
                    $paddedCommand = str_pad($command, $commandWidth);
                    $coloredCommand = $this->colorize($paddedCommand, self::SUCCESS);
                    $coloredDescription = $this->colorize($description, $color);
                    echo $this->colorize($bullet, $color) . ' ' . $coloredCommand . $coloredDescription . PHP_EOL;
                } else {
                    // 如果没有描述，直接显示
                    echo $this->colorize($bullet, $color) . ' ' . $this->colorize($item, self::SUCCESS) . PHP_EOL;
                }
            } elseif (is_array($item) && isset($item['command'])) {
                // 处理数组格式
                $command = $item['command'];
                $description = $item['description'] ?? '';
                $paddedCommand = str_pad($command, $commandWidth);
                $coloredCommand = $this->colorize($paddedCommand, self::SUCCESS);
                $coloredDescription = $this->colorize($description, $color);
                echo $this->colorize($bullet, $color) . ' ' . $coloredCommand . $coloredDescription . PHP_EOL;
            } else {
                // 其他格式直接显示
                echo $this->colorize($bullet, $color) . ' ' . $item . PHP_EOL;
            }
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
