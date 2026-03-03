<?php

declare(strict_types=1);

/**
 * Weline Framework CLI 异常渲染器
 * 
 * 用于命令行，返回带颜色的文本输出
 */

namespace Weline\Framework\Exception\Renderer;

use Weline\Framework\Exception\ExceptionBootstrap;

class CliRenderer implements RendererInterface
{
    /**
     * ANSI 颜色代码
     */
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";
    private const CYAN = "\033[36m";
    private const WHITE = "\033[37m";
    private const GRAY = "\033[90m";
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const BG_RED = "\033[41m";

    /**
     * 渲染异常为 CLI 输出
     */
    public function render(\Throwable $exception): string
    {
        if (ExceptionBootstrap::isDevMode()) {
            return $this->renderDev($exception);
        }

        return $this->renderProduction($exception);
    }

    /**
     * 获取内容类型
     */
    public function getContentType(): string
    {
        return 'text/plain; charset=utf-8';
    }

    /**
     * 开发模式渲染
     */
    private function renderDev(\Throwable $exception): string
    {
        $class = get_class($exception);
        $message = $exception->getMessage();
        $file = $this->simplifyPath($exception->getFile());
        $line = $exception->getLine();
        $code = $exception->getCode();

        $output = PHP_EOL;
        $output .= self::BG_RED . self::WHITE . self::BOLD . ' EXCEPTION ' . self::RESET . PHP_EOL . PHP_EOL;
        $output .= self::RED . self::BOLD . $class . self::RESET;
        
        if ($code) {
            $output .= self::GRAY . " [{$code}]" . self::RESET;
        }
        
        $output .= PHP_EOL . PHP_EOL;
        $output .= self::WHITE . $message . self::RESET . PHP_EOL . PHP_EOL;
        $output .= self::CYAN . "at " . self::RESET . $file . self::YELLOW . ":{$line}" . self::RESET . PHP_EOL;

        // 代码片段
        $snippet = $this->getCodeSnippet($exception->getFile(), $exception->getLine());
        if ($snippet) {
            $output .= PHP_EOL . $snippet;
        }

        // 堆栈追踪
        $output .= PHP_EOL . self::GRAY . "Stack Trace:" . self::RESET . PHP_EOL;
        $output .= $this->formatTrace($exception->getTrace());

        // 上一个异常
        if ($exception->getPrevious()) {
            $output .= PHP_EOL . self::YELLOW . "Caused by:" . self::RESET . PHP_EOL;
            $prev = $exception->getPrevious();
            $output .= self::RED . get_class($prev) . self::RESET . ": " . $prev->getMessage() . PHP_EOL;
            $output .= self::GRAY . "at " . $this->simplifyPath($prev->getFile()) . ":" . $prev->getLine() . self::RESET . PHP_EOL;
        }

        $output .= PHP_EOL;

        return $output;
    }

    /**
     * 生产模式渲染
     */
    private function renderProduction(\Throwable $exception): string
    {
        return PHP_EOL 
            . self::RED . "Error: " . self::RESET 
            . "An error occurred. Please check the logs for details." . PHP_EOL 
            . self::GRAY . "Log file: var/log/exception.log" . self::RESET . PHP_EOL 
            . PHP_EOL;
    }

    /**
     * 简化文件路径
     */
    private function simplifyPath(string $path): string
    {
        if (defined('BP') && str_starts_with($path, BP)) {
            return substr($path, strlen(BP));
        }
        return $path;
    }

    /**
     * 获取代码片段
     */
    private function getCodeSnippet(string $file, int $errorLine): string
    {
        if (!is_readable($file)) {
            return '';
        }

        $lines = @file($file);
        if ($lines === false) {
            return '';
        }

        $start = max(0, $errorLine - 4);
        $end = min(count($lines), $errorLine + 3);

        $output = '';
        $maxLineNumWidth = strlen((string)$end);

        for ($i = $start; $i < $end; $i++) {
            $lineNum = $i + 1;
            $lineNumStr = str_pad((string)$lineNum, $maxLineNumWidth, ' ', STR_PAD_LEFT);
            $content = rtrim($lines[$i] ?? '');

            if ($lineNum === $errorLine) {
                $output .= self::BG_RED . self::WHITE . " > {$lineNumStr} | " . self::RESET;
                $output .= self::RED . $content . self::RESET . PHP_EOL;
            } else {
                $output .= self::GRAY . "   {$lineNumStr} | " . self::RESET;
                $output .= $content . PHP_EOL;
            }
        }

        return $output;
    }

    /**
     * 格式化堆栈追踪
     */
    private function formatTrace(array $trace): string
    {
        $output = '';
        $limit = min(15, count($trace));

        for ($i = 0; $i < $limit; $i++) {
            $frame = $trace[$i];
            $num = str_pad((string)$i, 2, ' ', STR_PAD_LEFT);
            
            $file = isset($frame['file']) ? $this->simplifyPath($frame['file']) : '[internal]';
            $line = $frame['line'] ?? 0;
            $function = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');

            $output .= self::GRAY . "  #{$num} " . self::RESET;
            $output .= self::CYAN . $function . self::RESET;
            $output .= PHP_EOL;
            $output .= self::GRAY . "      at {$file}:{$line}" . self::RESET . PHP_EOL;
        }

        if (count($trace) > $limit) {
            $more = count($trace) - $limit;
            $output .= self::GRAY . "  ... and {$more} more frames" . self::RESET . PHP_EOL;
        }

        return $output;
    }
}
