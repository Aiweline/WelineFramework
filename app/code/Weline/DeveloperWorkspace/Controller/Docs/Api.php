<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Controller\Docs;

class Api extends \Weline\DeveloperWorkspace\Controller\Docs
{
    public function index(): string
    {
        return $this->api();
    }

    public function sdkDownload(): string
    {
        $sdk = \strtolower(\trim((string)$this->request->getParam('sdk', '')));
        $packages = [
            'php' => [
                'directory' => BP . 'pub' . DS . 'source' . DS . 'binquery-php',
                'filename' => 'binquery-php-sdk.zip',
            ],
            'js' => [
                'directory' => BP . 'pub' . DS . 'source' . DS . 'binquery-js',
                'filename' => 'binquery-js-sdk.zip',
            ],
        ];

        if (!isset($packages[$sdk])) {
            return $this->jsonError(__('SDK 类型不支持'), 400);
        }

        $directory = (string)$packages[$sdk]['directory'];
        if (!\is_dir($directory)) {
            return $this->jsonError(__('SDK 目录不存在'), 404);
        }

        if (!\class_exists(\ZipArchive::class)) {
            return $this->jsonError(__('当前 PHP 环境未启用 ZipArchive，无法生成 SDK 下载包'), 500);
        }

        $zipPath = \tempnam(\sys_get_temp_dir(), 'weline-binquery-sdk-');
        if ($zipPath === false) {
            return $this->jsonError(__('创建临时下载文件失败'), 500);
        }

        try {
            $zip = new \ZipArchive();
            $openResult = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($openResult !== true) {
                return $this->jsonError(__('创建 SDK 下载包失败'), 500);
            }

            $this->addDirectoryToZip($zip, $directory, \basename($directory));
            $zip->close();

            $content = \file_get_contents($zipPath);
            if ($content === false) {
                return $this->jsonError(__('读取 SDK 下载包失败'), 500);
            }

            $filename = (string)$packages[$sdk]['filename'];
            $response = $this->request->getResponse();
            $response->setHttpResponseCode(200);
            $response->setHeader('Content-Type', 'application/zip');
            $response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->setHeader('Content-Length', (string)\strlen($content));
            $response->setHeader('Cache-Control', 'no-store, max-age=0');
            $response->setHeader('X-Content-Type-Options', 'nosniff');

            return $content;
        } catch (\Throwable $exception) {
            return $this->jsonError(__('生成 SDK 下载包失败：%{1}', $exception->getMessage()), 500);
        } finally {
            if (\is_file($zipPath)) {
                @\unlink($zipPath);
            }
        }
    }

    public function sdkGuide(): string
    {
        $doc = \strtolower(\trim((string)$this->request->getParam('doc', 'sdk')));
        $documents = [
            'sdk' => [
                'path' => BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Framework' . DS . 'doc' . DS . 'BinQuery' . DS . 'SDK使用指南.md',
                'filename' => 'binquery-sdk-guide.md',
                'title' => (string)__('BinQuery SDK 使用指南'),
                'description' => (string)__('PHP/JS SDK 安装、下载、连接、查询、调用和 Graph 示例。'),
            ],
            'protocol' => [
                'path' => BP . 'app' . DS . 'code' . DS . 'Weline' . DS . 'Framework' . DS . 'doc' . DS . 'BinQuery' . DS . '协议对接指南.md',
                'filename' => 'binquery-protocol-guide.md',
                'title' => (string)__('BinQuery 协议对接指南'),
                'description' => (string)__('非 PHP/JS 语言自对接 /bin/query 的协议、Header、错误结构和缓存 marker 说明。'),
            ],
        ];

        if (!isset($documents[$doc])) {
            return $this->jsonError(__('文档类型不支持'), 400);
        }

        $path = (string)$documents[$doc]['path'];
        if (!\is_file($path)) {
            return $this->jsonError(__('SDK 指南不存在'), 404);
        }

        $content = \file_get_contents($path);
        if ($content === false) {
            return $this->jsonError(__('读取 SDK 指南失败'), 500);
        }

        $format = \strtolower(\trim((string)$this->request->getParam('format', 'html')));
        $filename = (string)$documents[$doc]['filename'];
        $response = $this->request->getResponse();
        $response->setHttpResponseCode(200);
        $response->setHeader('Cache-Control', 'no-store, max-age=0');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        if (\in_array($format, ['md', 'markdown', 'raw'], true)) {
            $response->setHeader('Content-Type', 'text/markdown; charset=utf-8');
            $response->setHeader('Content-Disposition', 'inline; filename="' . $filename . '"');

            return $content;
        }

        $htmlFilename = \str_ends_with($filename, '.md') ? \substr($filename, 0, -3) . '.html' : $filename . '.html';
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setHeader('Content-Disposition', 'inline; filename="' . $htmlFilename . '"');

        return $this->renderGuideHtml($content, $documents[$doc], $doc);
    }

    /**
     * @param array{title?: string, description?: string} $document
     */
    private function renderGuideHtml(string $markdown, array $document, string $doc): string
    {
        $title = (string)($document['title'] ?? __('BinQuery 文档'));
        $description = (string)($document['description'] ?? __('BinQuery 开发文档'));
        $apiDocsUrl = '/dev/tool/docs/api';
        $phpDownloadUrl = '/dev/tool/docs/api/sdk-download?sdk=php';
        $jsDownloadUrl = '/dev/tool/docs/api/sdk-download?sdk=js';
        $rawUrl = '/dev/tool/docs/api/sdk-guide?doc=' . \rawurlencode($doc) . '&format=md';
        $body = $this->markdownToHtml($markdown);

        return '<!doctype html>
<html lang="zh-Hans">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $this->escapeHtml($title) . '</title>
    <style>
        :root {
            color-scheme: light;
            --wq-bg: #f8fafc;
            --wq-panel: #ffffff;
            --wq-text: #1e293b;
            --wq-muted: #64748b;
            --wq-border: #e2e8f0;
            --wq-soft: #f1f5f9;
            --wq-accent: #2563eb;
            --wq-accent-dark: #1d4ed8;
            --wq-code-bg: #0f172a;
            --wq-code-text: #e2e8f0;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            background: var(--wq-bg);
            color: var(--wq-text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            font-size: 16px;
            line-height: 1.65;
        }
        a {
            color: var(--wq-accent);
            text-decoration: none;
        }
        a:hover,
        a:focus {
            color: var(--wq-accent-dark);
            text-decoration: underline;
        }
        .guide-top {
            border-bottom: 1px solid var(--wq-border);
            background: var(--wq-panel);
        }
        .guide-top-inner,
        .guide-shell {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
        }
        .guide-top-inner {
            display: flex;
            gap: 24px;
            align-items: flex-end;
            justify-content: space-between;
            padding: 28px 0 22px;
        }
        .guide-kicker {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            border: 1px solid var(--wq-border);
            border-radius: 6px;
            padding: 3px 9px;
            color: var(--wq-muted);
            background: var(--wq-soft);
            font-size: 13px;
            font-weight: 600;
        }
        .guide-title {
            margin: 12px 0 6px;
            font-size: clamp(26px, 4vw, 42px);
            line-height: 1.15;
            letter-spacing: 0;
        }
        .guide-desc {
            max-width: 760px;
            margin: 0;
            color: var(--wq-muted);
        }
        .guide-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: flex-end;
        }
        .guide-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border: 1px solid var(--wq-border);
            border-radius: 6px;
            padding: 7px 12px;
            background: var(--wq-panel);
            color: var(--wq-text);
            font-size: 14px;
            font-weight: 600;
            transition: border-color 180ms ease, color 180ms ease, background 180ms ease;
        }
        .guide-button.primary {
            border-color: var(--wq-accent);
            background: var(--wq-accent);
            color: #ffffff;
        }
        .guide-button:hover,
        .guide-button:focus {
            border-color: var(--wq-accent);
            color: var(--wq-accent-dark);
            text-decoration: none;
        }
        .guide-button.primary:hover,
        .guide-button.primary:focus {
            background: var(--wq-accent-dark);
            color: #ffffff;
        }
        .guide-shell {
            padding: 28px 0 56px;
        }
        .markdown-body {
            border: 1px solid var(--wq-border);
            border-radius: 8px;
            background: var(--wq-panel);
            padding: clamp(22px, 4vw, 42px);
            overflow-wrap: anywhere;
            box-shadow: 0 16px 42px rgba(15, 23, 42, 0.06);
        }
        .markdown-body h1,
        .markdown-body h2,
        .markdown-body h3 {
            color: var(--wq-text);
            line-height: 1.28;
            letter-spacing: 0;
        }
        .markdown-body h1 {
            margin: 0 0 18px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--wq-border);
            font-size: 30px;
        }
        .markdown-body h2 {
            margin: 34px 0 12px;
            font-size: 23px;
        }
        .markdown-body h3 {
            margin: 24px 0 10px;
            font-size: 18px;
        }
        .markdown-body p {
            margin: 0 0 16px;
        }
        .markdown-body ul,
        .markdown-body ol {
            margin: 0 0 18px 22px;
            padding: 0;
        }
        .markdown-body li + li {
            margin-top: 6px;
        }
        .markdown-body code {
            border-radius: 5px;
            padding: 2px 5px;
            background: var(--wq-soft);
            color: #0f172a;
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
            font-size: 0.92em;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .markdown-body pre {
            overflow-x: auto;
            max-width: 100%;
            margin: 14px 0 22px;
            border-radius: 8px;
            background: var(--wq-code-bg);
            padding: 18px;
        }
        .markdown-body pre code {
            display: block;
            padding: 0;
            background: transparent;
            color: var(--wq-code-text);
            font-size: 14px;
            line-height: 1.65;
            white-space: pre;
            overflow-wrap: normal;
            word-break: normal;
        }
        @media (max-width: 760px) {
            .guide-top-inner {
                align-items: flex-start;
                flex-direction: column;
            }
            .guide-actions {
                justify-content: flex-start;
            }
            .guide-button {
                flex: 1 1 auto;
            }
        }
        @media (prefers-reduced-motion: reduce) {
            .guide-button {
                transition: none;
            }
        }
    </style>
</head>
<body>
    <header class="guide-top">
        <div class="guide-top-inner">
            <div>
                <a class="guide-kicker" href="' . $this->escapeHtml($apiDocsUrl) . '">' . $this->escapeHtml((string)__('API 文档管理')) . '</a>
                <h1 class="guide-title">' . $this->escapeHtml($title) . '</h1>
                <p class="guide-desc">' . $this->escapeHtml($description) . '</p>
            </div>
            <nav class="guide-actions" aria-label="' . $this->escapeHtml((string)__('BinQuery 文档操作')) . '">
                <a class="guide-button" href="' . $this->escapeHtml($apiDocsUrl) . '">' . $this->escapeHtml((string)__('返回 API 文档')) . '</a>
                <a class="guide-button primary" href="' . $this->escapeHtml($phpDownloadUrl) . '">' . $this->escapeHtml((string)__('下载 PHP SDK')) . '</a>
                <a class="guide-button primary" href="' . $this->escapeHtml($jsDownloadUrl) . '">' . $this->escapeHtml((string)__('下载 JS SDK')) . '</a>
                <a class="guide-button" href="' . $this->escapeHtml($rawUrl) . '">' . $this->escapeHtml((string)__('查看 Markdown')) . '</a>
            </nav>
        </div>
    </header>
    <main class="guide-shell">
        <article class="markdown-body">
            ' . $body . '
        </article>
    </main>
</body>
</html>';
    }

    private function markdownToHtml(string $markdown): string
    {
        $lines = \preg_split('/\R/u', $markdown);
        if ($lines === false) {
            return '<p>' . $this->renderMarkdownInline($markdown) . '</p>';
        }

        $html = [];
        $paragraph = [];
        $listType = null;
        $inCode = false;
        $codeLanguage = '';
        $codeLines = [];

        $flushParagraph = function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }

            $html[] = '<p>' . $this->renderMarkdownInline(\implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        };

        $closeList = function () use (&$listType, &$html): void {
            if ($listType === null) {
                return;
            }

            $html[] = '</' . $listType . '>';
            $listType = null;
        };

        $openList = function (string $type) use (&$listType, &$html, $closeList): void {
            if ($listType === $type) {
                return;
            }

            $closeList();
            $html[] = '<' . $type . '>';
            $listType = $type;
        };

        foreach ($lines as $line) {
            $line = \rtrim((string)$line, "\r\n");
            $trimmed = \trim($line);

            if (\preg_match('/^```([A-Za-z0-9_-]*)\s*$/', $trimmed, $matches) === 1) {
                if ($inCode) {
                    $html[] = $this->renderCodeBlock($codeLines, $codeLanguage);
                    $inCode = false;
                    $codeLanguage = '';
                    $codeLines = [];
                    continue;
                }

                $flushParagraph();
                $closeList();
                $inCode = true;
                $codeLanguage = (string)($matches[1] ?? '');
                $codeLines = [];
                continue;
            }

            if ($inCode) {
                $codeLines[] = $line;
                continue;
            }

            if ($trimmed === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            if (\preg_match('/^(#{1,3})\s+(.+)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $closeList();
                $level = \strlen((string)$matches[1]);
                $html[] = '<h' . $level . '>' . $this->renderMarkdownInline((string)$matches[2]) . '</h' . $level . '>';
                continue;
            }

            if (\preg_match('/^\s*[-*]\s+(.+)$/u', $line, $matches) === 1) {
                $flushParagraph();
                $openList('ul');
                $html[] = '<li>' . $this->renderMarkdownInline((string)$matches[1]) . '</li>';
                continue;
            }

            if (\preg_match('/^\s*\d+\.\s+(.+)$/u', $line, $matches) === 1) {
                $flushParagraph();
                $openList('ol');
                $html[] = '<li>' . $this->renderMarkdownInline((string)$matches[1]) . '</li>';
                continue;
            }

            $paragraph[] = $trimmed;
        }

        if ($inCode) {
            $html[] = $this->renderCodeBlock($codeLines, $codeLanguage);
        }

        $flushParagraph();
        $closeList();

        return \implode("\n", $html);
    }

    /**
     * @param string[] $lines
     */
    private function renderCodeBlock(array $lines, string $language): string
    {
        $language = (string)\preg_replace('/[^A-Za-z0-9_-]/', '', $language);
        $class = $language !== '' ? ' class="language-' . $this->escapeHtml($language) . '"' : '';

        return '<pre><code' . $class . '>' . $this->escapeHtml(\implode("\n", $lines)) . '</code></pre>';
    }

    private function renderMarkdownInline(string $text): string
    {
        $segments = \preg_split('/(`[^`]*`)/u', $text, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($segments === false) {
            return $this->escapeHtml($text);
        }

        $html = '';
        foreach ($segments as $segment) {
            if (\strlen($segment) >= 2 && $segment[0] === '`' && \substr($segment, -1) === '`') {
                $html .= '<code>' . $this->escapeHtml(\substr($segment, 1, -1)) . '</code>';
                continue;
            }

            $escaped = $this->escapeHtml($segment);
            $escaped = \preg_replace_callback(
                '/\[([^\]]+)\]\(([^)]+)\)/u',
                function (array $matches): string {
                    $href = \html_entity_decode((string)$matches[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
                    if (\preg_match('#^(https?://|/|mailto:)#i', $href) !== 1) {
                        return (string)$matches[1];
                    }

                    return '<a href="' . $this->escapeHtml($href) . '">' . (string)$matches[1] . '</a>';
                },
                $escaped
            ) ?? $escaped;
            $escaped = \preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
            $html .= $escaped;
        }

        return $html;
    }

    private function escapeHtml(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $directory, string $rootName): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $baseLength = \strlen(\rtrim($directory, "\\/")) + 1;
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relativePath = \substr($item->getPathname(), $baseLength);
            if ($relativePath === false || $relativePath === '') {
                continue;
            }

            $zipPath = \str_replace('\\', '/', $rootName . '/' . $relativePath);
            if ($item->isDir()) {
                $zip->addEmptyDir($zipPath);
                continue;
            }

            if ($item->isFile()) {
                $zip->addFile($item->getPathname(), $zipPath);
            }
        }
    }

    private function jsonError(string $message, int $statusCode): string
    {
        $response = $this->request->getResponse();
        $response->setHttpResponseCode($statusCode);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store, max-age=0');
        $response->setHeader('X-Content-Type-Options', 'nosniff');

        return \json_encode(
            [
                'ok' => false,
                'error' => $message,
            ],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        ) ?: '{"ok":false}';
    }
}
