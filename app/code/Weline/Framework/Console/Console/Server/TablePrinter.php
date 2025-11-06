<?php

namespace Weline\Framework\Console\Console\Server;

/**
 * 表格打印工具
 * 用于在命令行中打印美观的表格
 */
trait TablePrinter
{
    /**
     * 打印表格
     * 
     * @param string $title 表格标题
     * @param array $data 数据数组 [['key', 'value'], ...]
     * @param bool $showBorder 是否显示边框
     * @param int $maxWidth 最大总宽度（0表示自动检测终端宽度）
     * @param bool $truncateUrls 是否截断URL（默认true，设为false时完整显示URL，允许超出终端宽度）
     */
    protected function printTable(string $title, array $data, bool $showBorder = true, int $maxWidth = 0, bool $truncateUrls = true): void
    {
        if (empty($data)) {
            return;
        }
        
        // 如果没有设置最大宽度，自动检测终端宽度
        if ($maxWidth === 0) {
            $maxWidth = $this->getTerminalWidth();
        }
        
        // 计算最大显示宽度（支持多语言字符）
        $maxKeyWidth = $this->getDisplayWidth($title);
        $maxValueWidth = 0;
        
        foreach ($data as $row) {
            $keyWidth = $this->getDisplayWidth((string)$row[0]);
            $valueWidth = $this->getDisplayWidth((string)$row[1]);
            if ($keyWidth > $maxKeyWidth) $maxKeyWidth = $keyWidth;
            if ($valueWidth > $maxValueWidth) $maxValueWidth = $valueWidth;
        }
        
        // 根据实际内容设置列宽（自适应内容）
        $keyWidth = $maxKeyWidth + 2;  // 左右各留1个空格
        $valueWidth = $maxValueWidth + 2;  // 左右各留1个空格
        
        // 计算总宽度：左边框(1) + 键列 + 中间边框(1) + 值列 + 右边框(1)
        $totalWidth = 1 + $keyWidth + 1 + $valueWidth + 1;
        
        // 确保最小宽度（至少能显示标题）
        $minTitleWidth = $this->getDisplayWidth($title) + 4;
        if ($totalWidth < $minTitleWidth) {
            // 调整总宽度以容纳标题
            $totalWidth = $minTitleWidth;
            // 重新分配键列和值列宽度
            $availableWidth = $totalWidth - 3;  // 减去3个边框字符
            $keyWidth = min($keyWidth, (int)($availableWidth * 0.4));
            $valueWidth = $availableWidth - $keyWidth;
        }
        
        // 如果超出终端宽度且允许截断URL，则进行调整
        // 如果不允许截断URL，也要限制最大宽度，避免str_repeat生成过长字符串导致卡住
        // 设置一个合理的最大宽度限制（500字符），即使不截断URL内容，边框也不应该超过这个宽度
        // 安全限制：最大宽度不超过1000字符，避免生成过长字符串导致卡住或内存问题
        $safeMaxWidth = min($maxWidth, 1000); // 安全上限：1000字符
        $maxAllowedWidth = max($safeMaxWidth, 500); // 至少500字符，但不超过安全上限
        
        if ($totalWidth > $maxAllowedWidth) {
            if ($truncateUrls) {
                // 允许截断URL时，严格限制在终端宽度内
                $totalWidth = $safeMaxWidth;
                // 可用宽度 = 总宽度 - 3个边框字符
                $availableWidth = $totalWidth - 3;
                // 键列占30%，但至少15个字符
                $keyWidth = max(15, min($maxKeyWidth + 2, (int)($availableWidth * 0.3)));
                $valueWidth = $availableWidth - $keyWidth;
            } else {
                // 不截断URL时，限制最大宽度避免卡住，但值列宽度保持足够大以显示完整URL
                $totalWidth = $maxAllowedWidth;
                // 键列保持固定大小
                $keyWidth = min($maxKeyWidth + 2, 50); // 键列最多50字符
                // 值列使用剩余空间
                $valueWidth = $totalWidth - $keyWidth - 3; // 减去3个边框字符
            }
        }
        
        if ($showBorder) {
            // 打印顶部边框
            echo "┌" . str_repeat("─", $totalWidth) . "┐\n";
            
            // 打印标题（居中）
            $titleWidth = $this->getDisplayWidth($title);
            $titlePadding = (int)floor(($totalWidth - $titleWidth) / 2);
            $titlePaddingRight = $totalWidth - $titlePadding - $titleWidth;
            echo "│" . str_repeat(" ", $titlePadding) . $title . 
                 str_repeat(" ", max(0, $titlePaddingRight)) . "│\n";
            
            // 打印分隔线
            echo "├" . str_repeat("─", $keyWidth) . "┬" . str_repeat("─", $valueWidth) . "┤\n";
        }
        
        // 打印数据行
        foreach ($data as $index => $row) {
            $key = (string)$row[0];
            $originalValue = (string)$row[1];
            $value = $originalValue;
            
            // 智能处理超长文本（按显示宽度）
            $availableValueWidth = $valueWidth - 2;
            $currentValueWidth = $this->getDisplayWidth($value);
            
            // 只有在允许截断URL时才进行截断
            if ($truncateUrls && $currentValueWidth > $availableValueWidth) {
                // 对于URL等长文本，智能截断并保留重要部分
                if ($this->isUrl($value)) {
                    // URL保留协议和域名，中间部分省略
                    $value = $this->truncateUrl($value, $availableValueWidth);
                } else {
                    // 普通文本按显示宽度截断
                    $value = $this->truncateByDisplayWidth($value, $availableValueWidth - 3) . '...';
                }
            }
            
            // 计算填充空格（按显示宽度）
            $keyDisplayWidth = $this->getDisplayWidth($key);
            $valueDisplayWidth = $this->getDisplayWidth($value);
            
            // 精确计算填充：列宽 - 内容显示宽度 - 左右各1个空格
            $keyPadding = $keyWidth - $keyDisplayWidth - 2;
            $valuePadding = $valueWidth - $valueDisplayWidth - 2;
            
            // 确保填充不为负数
            $keyPadding = max(0, $keyPadding);
            $valuePadding = max(0, $valuePadding);
            
            if ($showBorder) {
                // 格式：│ 内容 填充 │ 内容 填充 │
                echo "│ " . $key . str_repeat(" ", $keyPadding) . " " . 
                     "│ " . $value . str_repeat(" ", $valuePadding) . " │\n";
                
                // 如果不是最后一行，打印分隔线
                if ($index < count($data) - 1) {
                    echo "├" . str_repeat("─", $keyWidth) . "┼" . str_repeat("─", $valueWidth) . "┤\n";
                }
            } else {
                // 无边框模式，简洁输出
                echo "  " . $key . str_repeat(" ", $keyPadding) . $value . "\n";
            }
        }
        
        if ($showBorder) {
            // 打印底部边框
            echo "└" . str_repeat("─", $keyWidth) . "┴" . str_repeat("─", $valueWidth) . "┘\n";
        }
    }
    
    /**
     * 打印简单列表（URL列表等）
     * 
     * @param string $title 标题
     * @param array $items 项目数组
     * @param int $maxWidth 最大宽度（0表示自动检测）
     */
    protected function printList(string $title, array $items, int $maxWidth = 0): void
    {
        if (empty($items)) {
            return;
        }
        
        // 如果没有设置最大宽度，自动检测终端宽度
        if ($maxWidth === 0) {
            $maxWidth = $this->getTerminalWidth();
        }
        
        // 计算内容的最大显示宽度（支持多语言字符）
        $contentMaxWidth = $this->getDisplayWidth($title);
        foreach ($items as $item) {
            $itemWidth = $this->getDisplayWidth((string)$item);
            if ($itemWidth > $contentMaxWidth) $contentMaxWidth = $itemWidth;
        }
        
        // 根据内容自适应宽度（左右各留2个空格）
        $width = $contentMaxWidth + 4;
        
        // 如果超出终端宽度，则限制为终端宽度
        if ($width > $maxWidth) {
            $width = $maxWidth;
        }
        
        // 打印顶部边框
        echo "┌" . str_repeat("─", $width) . "┐\n";
        
        // 打印标题
        $titleWidth = $this->getDisplayWidth($title);
        $titlePadding = floor(($width - $titleWidth) / 2);
        echo "│" . str_repeat(" ", $titlePadding) . $title . 
             str_repeat(" ", $width - $titlePadding - $titleWidth) . "│\n";
        
        // 打印分隔线
        echo "├" . str_repeat("─", $width) . "┤\n";
        
        // 打印项目
        foreach ($items as $index => $item) {
            $itemStr = (string)$item;
            $itemWidth = $this->getDisplayWidth($itemStr);
            
            // 如果内容超出宽度，需要截断
            if ($itemWidth > ($width - 4)) {
                if ($this->isUrl($itemStr)) {
                    $itemStr = $this->truncateUrl($itemStr, $width - 4);
                } else {
                    $itemStr = $this->truncateByDisplayWidth($itemStr, $width - 7) . '...';
                }
                $itemWidth = $this->getDisplayWidth($itemStr);
            }
            
            $padding = $width - $itemWidth - 2;
            echo "│ " . $itemStr . str_repeat(" ", $padding) . " │\n";
            
            if ($index < count($items) - 1) {
                echo "├" . str_repeat("─", $width) . "┤\n";
            }
        }
        
        // 打印底部边框
        echo "└" . str_repeat("─", $width) . "┘\n";
    }
    
    /**
     * 打印信息框
     * 
     * @param string $title 标题
     * @param string $message 消息内容
     * @param string $type 类型: info, success, warning, error
     * @param int $maxWidth 最大宽度（0表示自动检测）
     */
    protected function printBox(string $title, string $message, string $type = 'info', int $maxWidth = 0): void
    {
        // 如果没有设置最大宽度，自动检测终端宽度
        if ($maxWidth === 0) {
            $maxWidth = $this->getTerminalWidth();
        }
        
        $lines = explode("\n", $message);
        $contentMaxWidth = $this->getDisplayWidth($title);
        
        foreach ($lines as $line) {
            $lineWidth = $this->getDisplayWidth((string)$line);
            if ($lineWidth > $contentMaxWidth) $contentMaxWidth = $lineWidth;
        }
        
        // 根据内容自适应宽度（左右各留2个空格）
        $width = $contentMaxWidth + 4;
        
        // 如果超出终端宽度，则限制为终端宽度
        if ($width > $maxWidth) {
            $width = $maxWidth;
        }
        
        // 根据类型选择边框字符
        $border = match($type) {
            'success' => ['╔', '═', '╗', '║', '╚', '╝'],
            'warning' => ['╔', '═', '╗', '║', '╚', '╝'],
            'error' => ['╔', '═', '╗', '║', '╚', '╝'],
            default => ['┌', '─', '┐', '│', '└', '┘'],
        };
        
        // 打印顶部
        echo $border[0] . str_repeat($border[1], $width) . $border[2] . "\n";
        
        // 打印标题
        $titleWidth = $this->getDisplayWidth($title);
        $titlePadding = floor(($width - $titleWidth) / 2);
        echo $border[3] . str_repeat(" ", $titlePadding) . $title . 
             str_repeat(" ", $width - $titlePadding - $titleWidth) . $border[3] . "\n";
        
        // 打印分隔线
        echo $border[0] . str_repeat($border[1], $width) . $border[2] . "\n";
        
        // 打印内容
        foreach ($lines as $line) {
            $lineWidth = $this->getDisplayWidth($line);
            
            // 如果内容超出宽度，需要截断
            if ($lineWidth > ($width - 4)) {
                $line = $this->truncateByDisplayWidth($line, $width - 7) . '...';
                $lineWidth = $this->getDisplayWidth($line);
            }
            
            $padding = $width - $lineWidth - 2;
            echo $border[3] . " " . $line . str_repeat(" ", $padding) . " " . $border[3] . "\n";
        }
        
        // 打印底部
        echo $border[4] . str_repeat($border[1], $width) . $border[5] . "\n";
    }
    
    /**
     * 获取字符串的显示宽度
     * 支持中文、日文等多字节字符的正确宽度计算
     * 
     * @param string $text 文本
     * @return int 显示宽度
     */
    protected function getDisplayWidth(string $text): int
    {
        // 使用 mb_strwidth 获取东亚字符的显示宽度
        // 中文、日文等CJK字符占2个显示位置，英文占1个
        return mb_strwidth($text, 'UTF-8');
    }
    
    /**
     * 按显示宽度截断字符串
     * 
     * @param string $text 文本
     * @param int $width 目标显示宽度
     * @return string 截断后的文本
     */
    protected function truncateByDisplayWidth(string $text, int $width): string
    {
        if ($this->getDisplayWidth($text) <= $width) {
            return $text;
        }
        
        // 使用 mb_strimwidth 按显示宽度截断
        // 第4个参数为空字符串，避免自动添加省略号
        return mb_strimwidth($text, 0, $width, '', 'UTF-8');
    }
    
    /**
     * 获取终端宽度
     * 
     * @return int 终端宽度（默认120）
     */
    protected function getTerminalWidth(): int
    {
        // Windows系统 - 直接返回默认值，避免执行可能阻塞的命令
        // 在 PowerShell 环境中，exec('mode con') 可能会卡住
        // 为了确保命令不阻塞，直接返回合理的默认宽度
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows 系统：直接返回默认宽度，避免执行可能阻塞的命令
            return 120;
        } else {
            // Unix/Linux/Mac系统
            $width = @exec('tput cols 2>/dev/null');
            if (is_numeric($width) && $width > 0) {
                return (int)$width;
            }
        }
        
        // 默认宽度
        return 120;
    }
    
    /**
     * 判断是否为URL
     * 
     * @param string $text 文本
     * @return bool
     */
    protected function isUrl(string $text): bool
    {
        return preg_match('/^https?:\/\//', $text) === 1;
    }
    
    /**
     * 智能截断URL（按显示宽度）
     * 保留协议、域名和端口，中间部分省略
     * 
     * @param string $url URL
     * @param int $maxWidth 最大显示宽度
     * @return string 截断后的URL
     */
    protected function truncateUrl(string $url, int $maxWidth): string
    {
        $currentWidth = $this->getDisplayWidth($url);
        if ($currentWidth <= $maxWidth) {
            return $url;
        }
        
        // 解析URL
        $parts = parse_url($url);
        if (!$parts) {
            // 解析失败，直接按显示宽度截断
            return $this->truncateByDisplayWidth($url, $maxWidth - 3) . '...';
        }
        
        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        
        // 计算前缀长度（协议+域名+端口）
        $prefix = "{$scheme}://{$host}{$port}";
        $prefixWidth = $this->getDisplayWidth($prefix);
        
        // 如果前缀就超长了，直接截断
        if ($prefixWidth >= $maxWidth - 3) {
            return $this->truncateByDisplayWidth($url, $maxWidth - 3) . '...';
        }
        
        // 计算可用于路径的宽度
        $availableWidth = $maxWidth - $prefixWidth - 3; // 3是省略号的宽度
        $pathWidth = $this->getDisplayWidth($path);
        
        if ($pathWidth <= $availableWidth) {
            return $url;
        }
        
        // 路径太长，保留开头和结尾
        if ($availableWidth > 10) {
            $keepStartWidth = (int)($availableWidth * 0.3);
            $keepEndWidth = (int)($availableWidth * 0.3);
            $pathStart = $this->truncateByDisplayWidth($path, $keepStartWidth);
            
            // 从结尾截取指定宽度的内容
            $pathEnd = $this->getEndByDisplayWidth($path, $keepEndWidth);
            
            return "{$prefix}{$pathStart}...{$pathEnd}";
        }
        
        // 宽度太短，只保留开头
        return "{$prefix}" . $this->truncateByDisplayWidth($path, $availableWidth) . '...';
    }
    
    /**
     * 从字符串末尾获取指定显示宽度的内容
     * 
     * @param string $text 文本
     * @param int $width 目标显示宽度
     * @return string 结果文本
     */
    protected function getEndByDisplayWidth(string $text, int $width): string
    {
        $len = mb_strlen($text, 'UTF-8');
        $result = '';
        $currentWidth = 0;
        
        // 从后往前遍历字符
        for ($i = $len - 1; $i >= 0; $i--) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $charWidth = $this->getDisplayWidth($char);
            
            if ($currentWidth + $charWidth > $width) {
                break;
            }
            
            $result = $char . $result;
            $currentWidth += $charWidth;
        }
        
        return $result;
    }
}

