<?php

declare(strict_types=1);

/**
 * Weline Framework HTML 异常渲染器
 * 
 * 用于 Web 请求，返回美化的 HTML 错误页面
 */

namespace Weline\Framework\Exception\Renderer;

use Weline\Framework\Exception\ExceptionBootstrap;

class HtmlRenderer implements RendererInterface
{
    /**
     * 渲染异常为 HTML
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
        return 'text/html; charset=utf-8';
    }

    /**
     * 开发模式渲染
     */
    private function renderDev(\Throwable $exception): string
    {
        $class = get_class($exception);
        $message = htmlspecialchars($exception->getMessage());
        $file = htmlspecialchars($this->simplifyPath($exception->getFile()));
        $line = $exception->getLine();
        $code = $exception->getCode();
        $trace = htmlspecialchars($exception->getTraceAsString());
        $timestamp = date('Y-m-d H:i:s');
        $codeSnippet = $this->getCodeSnippet($exception->getFile(), $exception->getLine());

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error: {$class}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace;
            background: #1a1a2e; 
            color: #eee;
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #4a0000 0%, #2d0000 100%);
            padding: 30px;
            border-radius: 10px 10px 0 0;
            border-left: 5px solid #ff4757;
        }
        .header h1 { color: #ff6b6b; font-size: 1.5rem; margin-bottom: 10px; }
        .header .message { font-size: 1.2rem; color: #fff; word-break: break-word; }
        .header .meta { margin-top: 15px; color: #888; font-size: 0.9rem; }
        .content {
            background: #16213e;
            padding: 20px 30px;
            border-radius: 0 0 10px 10px;
        }
        .section { margin-bottom: 25px; }
        .section-title {
            color: #4ecdc4;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #333;
        }
        .location {
            background: #0f0f23;
            padding: 15px;
            border-radius: 5px;
            font-family: 'Fira Code', 'Consolas', monospace;
        }
        .location .file { color: #4ecdc4; }
        .location .line { color: #ff6b6b; }
        .code-snippet {
            background: #0f0f23;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-family: 'Fira Code', 'Consolas', monospace;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .code-line { display: block; }
        .code-line.highlight { background: #4a0000; }
        .line-number { 
            display: inline-block; 
            width: 50px; 
            color: #666;
            user-select: none;
        }
        .trace {
            background: #0f0f23;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            font-family: 'Fira Code', 'Consolas', monospace;
            font-size: 0.85rem;
            line-height: 1.6;
            white-space: pre-wrap;
            color: #888;
        }
        .timestamp { color: #666; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{$class} [{$code}]</h1>
            <div class="message">{$message}</div>
            <div class="meta">
                <span class="timestamp">{$timestamp}</span>
            </div>
        </div>
        <div class="content">
            <div class="section">
                <div class="section-title">Location</div>
                <div class="location">
                    <span class="file">{$file}</span>
                    <span class="line">:{$line}</span>
                </div>
            </div>
            {$codeSnippet}
            <div class="section">
                <div class="section-title">Stack Trace</div>
                <div class="trace">{$trace}</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * 生产模式渲染
     */
    private function renderProduction(\Throwable $exception): string
    {
        $code = $exception->getCode() ?: 500;
        $title = $this->getHttpStatusTitle($code);

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$code} - {$title}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .error-box {
            text-align: center;
            padding: 40px;
        }
        .error-code {
            font-size: 120px;
            font-weight: bold;
            color: #ddd;
            margin: 0;
        }
        .error-title {
            font-size: 24px;
            color: #333;
            margin: 20px 0 10px;
        }
        .error-message {
            color: #666;
            margin-bottom: 30px;
        }
        .back-link {
            display: inline-block;
            padding: 10px 30px;
            border: 0;
            background: #333;
            color: #fff;
            cursor: pointer;
            font: inherit;
            text-decoration: none;
            border-radius: 5px;
        }
        .back-link:hover { background: #555; }
    </style>
</head>
<body>
    <div class="error-box">
        <p class="error-code">{$code}</p>
        <h1 class="error-title">{$title}</h1>
        <p class="error-message">抱歉，服务器发生了错误。请稍后重试。</p>
        <button type="button" class="back-link" data-action="go-back">返回</button>
    </div>
    <script>
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-action="go-back"]');
            if (!button) {
                return;
            }

            if (window.history.length > 1) {
                window.history.go(-1);
                return;
            }

            window.location.href = '/';
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * 获取 HTTP 状态标题
     */
    private function getHttpStatusTitle(int $code): string
    {
        return match ($code) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Error',
        };
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
    private function getCodeSnippet(string $file, int $line): string
    {
        if (!is_readable($file)) {
            return '';
        }

        $lines = @file($file);
        if ($lines === false) {
            return '';
        }

        $start = max(0, $line - 5);
        $end = min(count($lines), $line + 4);

        $snippet = '<div class="section"><div class="section-title">Code</div><div class="code-snippet">';
        
        for ($i = $start; $i < $end; $i++) {
            $lineNum = $i + 1;
            $isHighlight = ($lineNum === $line) ? ' highlight' : '';
            $content = htmlspecialchars($lines[$i] ?? '');
            $snippet .= "<span class=\"code-line{$isHighlight}\"><span class=\"line-number\">{$lineNum}</span>{$content}</span>";
        }
        
        $snippet .= '</div></div>';

        return $snippet;
    }
}
