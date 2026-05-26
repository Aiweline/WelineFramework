<?php

declare(strict_types=1);

namespace WeShop\Payment\Service;

class PaymentDocumentationService
{
    public const REQUIRED_HEADINGS = [
        '适用国家/地区',
        '支持币种',
        '沙盒入口',
        '正式入口',
        '商户后台开通步骤',
        '后台字段说明',
        'Webhook/回调 URL',
        '签名/证书要求',
        '测试卡/测试账号',
        '上线检查清单',
        '常见错误',
        '官方文档链接',
    ];

    public function getBasePath(): string
    {
        return $this->getWelineBasePath();
    }

    public function hasDocumentation(array $method): bool
    {
        $path = $this->resolvePath($method);

        return $path !== '' && is_file($path) && $this->validateMarkdown((string) file_get_contents($path))['valid'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocumentation(array $method): array
    {
        $path = $this->resolvePath($method);
        $markdown = $path !== '' && is_file($path) ? (string) file_get_contents($path) : '';
        $validation = $this->validateMarkdown($markdown);

        return [
            'path' => $path,
            'relative_path' => $this->relativePath($path),
            'exists' => $path !== '' && is_file($path),
            'valid' => (bool) ($validation['valid'] ?? false),
            'missing_sections' => $validation['missing_sections'] ?? self::REQUIRED_HEADINGS,
            'has_required_fields' => (bool) ($validation['has_required_fields'] ?? false),
            'has_sandbox_url' => (bool) ($validation['has_sandbox_url'] ?? false),
            'has_live_url' => (bool) ($validation['has_live_url'] ?? false),
            'has_webhook' => (bool) ($validation['has_webhook'] ?? false),
            'has_official_links' => (bool) ($validation['has_official_links'] ?? false),
            'markdown' => $markdown,
            'html' => $markdown !== '' ? $this->renderMarkdown($markdown) : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateMethod(array $method): array
    {
        return $this->getDocumentation($method);
    }

    /**
     * @return array<string, mixed>
     */
    public function validateMarkdown(string $markdown): array
    {
        $missingSections = [];
        foreach (self::REQUIRED_HEADINGS as $heading) {
            if (!preg_match('/^#{1,4}\s*' . preg_quote($heading, '/') . '\s*$/mu', $markdown)) {
                $missingSections[] = $heading;
            }
        }

        $hasRequiredFields = (bool) preg_match('/required[_ -]?fields|必填字段|Required fields/iu', $markdown);
        $hasSandboxUrl = (bool) preg_match('/https?:\/\/[^\s)]+/i', $this->sectionText($markdown, '沙盒入口'));
        $hasLiveUrl = (bool) preg_match('/https?:\/\/[^\s)]+/i', $this->sectionText($markdown, '正式入口'));
        $hasWebhook = (bool) preg_match('/webhook|notify|notification|callback|回调|通知|监听/iu', $this->sectionText($markdown, 'Webhook/回调 URL'));
        $hasOfficialLinks = (bool) preg_match('/https?:\/\/[^\s)]+/i', $this->sectionText($markdown, '官方文档链接'));

        return [
            'valid' => $markdown !== ''
                && $missingSections === []
                && $hasRequiredFields
                && $hasSandboxUrl
                && $hasLiveUrl
                && $hasWebhook
                && $hasOfficialLinks,
            'missing_sections' => $missingSections,
            'has_required_fields' => $hasRequiredFields,
            'has_sandbox_url' => $hasSandboxUrl,
            'has_live_url' => $hasLiveUrl,
            'has_webhook' => $hasWebhook,
            'has_official_links' => $hasOfficialLinks,
        ];
    }

    public function renderMarkdown(string $markdown): string
    {
        $lines = preg_split('/\R/', str_replace(["\r\n", "\r"], "\n", $markdown));
        if (!\is_array($lines)) {
            return '';
        }

        $html = [];
        $inList = false;
        $inCode = false;
        $codeLines = [];

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '```')) {
                if ($inCode) {
                    $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8') . '</code></pre>';
                    $codeLines = [];
                    $inCode = false;
                    continue;
                }
                $this->closeList($html, $inList);
                $inCode = true;
                continue;
            }

            if ($inCode) {
                $codeLines[] = $line;
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                $this->closeList($html, $inList);
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+)$/', $trimmed, $matches)) {
                $this->closeList($html, $inList);
                $level = min(4, strlen((string) $matches[1]) + 1);
                $html[] = '<h' . $level . '>' . $this->renderInline((string) $matches[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $matches)) {
                if (!$inList) {
                    $html[] = '<ul>';
                    $inList = true;
                }
                $html[] = '<li>' . $this->renderInline((string) $matches[1]) . '</li>';
                continue;
            }

            $this->closeList($html, $inList);
            $html[] = '<p>' . $this->renderInline($trimmed) . '</p>';
        }

        if ($inCode) {
            $html[] = '<pre><code>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES, 'UTF-8') . '</code></pre>';
        }
        $this->closeList($html, $inList);

        return implode("\n", $html);
    }

    public function getSearchText(array $method): string
    {
        $documentation = $this->getDocumentation($method);

        return strtolower(implode(' ', [
            (string) ($method['code'] ?? ''),
            (string) ($method['title'] ?? ''),
            (string) ($method['provider_code'] ?? ''),
            implode(' ', (array) ($method['countries'] ?? [])),
            implode(' ', (array) ($method['country_tags'] ?? [])),
            implode(' ', array_map(static fn(array $field): string => (string) ($field['key'] ?? '') . ' ' . (string) ($field['label'] ?? ''), (array) ($method['config_fields'] ?? []))),
            strip_tags((string) ($documentation['html'] ?? '')),
        ]));
    }

    private function resolvePath(array $method): string
    {
        $relative = trim(str_replace(['..', '\\'], ['', '/'], (string) ($method['documentation_path'] ?? '')), '/');
        if ($relative === '') {
            $provider = trim((string) ($method['provider_code'] ?? ''));
            $code = trim((string) ($method['code'] ?? ''));
            if ($provider === '' || $code === '') {
                return '';
            }
            $relative = $provider . '/' . $code . '.md';
        }

        $relativePath = str_replace('/', DIRECTORY_SEPARATOR, $relative);
        foreach ($this->getCandidateBasePaths() as $basePath) {
            $path = $basePath . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($path)) {
                return $path;
            }
        }

        return $this->getBasePath() . DIRECTORY_SEPARATOR . $relativePath;
    }

    private function relativePath(string $path): string
    {
        foreach ($this->getCandidateBasePaths() as $basePath) {
            $base = $basePath . DIRECTORY_SEPARATOR;
            if ($path !== '' && str_starts_with($path, $base)) {
                return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($base)));
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function getCandidateBasePaths(): array
    {
        $paths = [
            $this->getWelineBasePath(),
            $this->getLegacyWeShopBasePath(),
        ];
        $unique = [];
        foreach ($paths as $path) {
            if ($path === '' || isset($unique[$path])) {
                continue;
            }
            $unique[$path] = $path;
        }

        return array_values($unique);
    }

    private function getWelineBasePath(): string
    {
        return dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'Weline'
            . DIRECTORY_SEPARATOR . 'Payment'
            . DIRECTORY_SEPARATOR . 'doc'
            . DIRECTORY_SEPARATOR . 'payment-methods';
    }

    private function getLegacyWeShopBasePath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'doc' . DIRECTORY_SEPARATOR . 'payment-methods';
    }

    private function sectionText(string $markdown, string $heading): string
    {
        if (!preg_match('/^#{1,4}\s*' . preg_quote($heading, '/') . '\s*$([\s\S]*?)(?=^#{1,4}\s+|\z)/mu', $markdown, $matches)) {
            return '';
        }

        return (string) ($matches[1] ?? '');
    }

    /**
     * @param array<int, string> $html
     */
    private function closeList(array &$html, bool &$inList): void
    {
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
    }

    private function renderInline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return (string) preg_replace_callback(
            '/\[([^\]]+)]\((https?:\/\/[^)\s]+)\)/',
            static fn(array $matches): string => '<a href="' . htmlspecialchars((string) $matches[2], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars((string) $matches[1], ENT_QUOTES, 'UTF-8') . '</a>',
            $escaped
        );
    }
}
