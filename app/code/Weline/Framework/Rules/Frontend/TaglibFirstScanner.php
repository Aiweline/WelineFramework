<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

/**
 * 手写领域控件静态扫描器：识别可直接替换为 Taglib 官方控件的表单字段。
 *
 * 零跨模块依赖：属性解析为私有实现。
 */
final class TaglibFirstScanner
{
    public const TYPE_RAW_SELECT = 'hand-rolled-domain-select';
    public const TYPE_RAW_INPUT = 'hand-rolled-domain-input';

    /** @var array<string, string> */
    private const DOMAIN_FIELDS = [
        'website_id' => 'w:websites:website:select',
        'websiteId' => 'w:websites:website:select',
        'site_id' => 'w:websites:website:select',
        'store_id' => 'w:websites:store:select',
        'store_code' => 'w:websites:store:select',
        'channel_code' => 'w:websites:channel:select',
        'channel_id' => 'w:websites:channel:select',
        'domain_id' => 'w:websites:domain:select',
        'domain' => 'w:websites:domain:select',
        'language_code' => 'w:i18n:language:select',
        'locale_code' => 'w:i18n:language:select',
        'currency' => 'w:currency:select',
        'currency_code' => 'w:currency:select',
        'scope_level' => 'w:scope',
        'account_id' => 'w:mail:account:select',
        'country_code' => 'w:theme:address',
        'country' => 'w:theme:address',
    ];

    /** @var array<string, array<string, string>> 模块语境覆盖：模块名 → 字段 → 标签 */
    private const MODULE_FIELD_TAGS = [
        'Mail' => [
            'domain_id'  => 'w:mail:domain:select',
            'domain'     => 'w:mail:domain:select',
            'account_id' => 'w:mail:account:select',
        ],
    ];

    /** @var list<string> */
    private const EXEMPT_TAG_PREFIXES = [
        '<w:websites:',
        '<w:i18n:',
        '<w:currency:',
        '<w:scope',
        '<w:mail:',
        '<w:theme:address',
        '<w:theme:search-select',
    ];

    /**
     * @return list<array{type:string,path:string,line:int,snippet:string,field:string,suggest_tag:string}>
     */
    public function scanProject(?string $codeRoot = null): array
    {
        $root = $this->normalizeRoot($codeRoot);
        if (!is_dir($root)) {
            return [];
        }

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'phtml') {
                continue;
            }
            $abs = $file->getPathname();
            $rel = $this->toRelativePath($abs, $root);
            if (!$this->isIncluded($rel) || $this->isExcluded($rel)) {
                continue;
            }
            $violations = array_merge($violations, $this->scanFile($abs, $rel));
        }

        return $violations;
    }

    /**
     * @param list<string> $absolutePaths
     * @return list<array{type:string,path:string,line:int,snippet:string,field:string,suggest_tag:string}>
     */
    public function scanPaths(array $absolutePaths, ?string $codeRoot = null): array
    {
        $root = $this->normalizeRoot($codeRoot);
        $violations = [];
        foreach ($absolutePaths as $abs) {
            $abs = (string)$abs;
            if ($abs === '' || !is_file($abs)) {
                continue;
            }
            $rel = $this->toRelativePath($abs, $root);
            $violations = array_merge($violations, $this->scanFile($abs, $rel));
        }

        return $violations;
    }

    /**
     * 按文件所属模块解析建议标签。
     */
    private function resolveSuggestTag(string $relativePath, string $field, string $defaultTag): string
    {
        if (preg_match('#^Weline/([A-Za-z0-9_]+)/#', $relativePath, $matches)) {
            $module = $matches[1];
            if (isset(self::MODULE_FIELD_TAGS[$module][$field])) {
                return self::MODULE_FIELD_TAGS[$module][$field];
            }
        }

        return $defaultTag;
    }

    /**
     * 按文件所属模块返回豁免标签前缀。
     *
     * @return list<string>
     */
    private function exemptPrefixesFor(string $relativePath): array
    {
        if (preg_match('#^Weline/([A-Za-z0-9_]+)/#', $relativePath, $matches)
            && isset(self::MODULE_FIELD_TAGS[$matches[1]])) {
            return ['<w:' . strtolower($matches[1]) . ':'];
        }

        return self::EXEMPT_TAG_PREFIXES;
    }

    /**
     * @return list<array{type:string,path:string,line:int,snippet:string,field:string,suggest_tag:string}>
     */
    public function scanFile(string $absolutePath, ?string $relativePath = null): array
    {
        $content = @file_get_contents($absolutePath);
        if ($content === false || $content === '') {
            return [];
        }
        $rel = $relativePath ?? $absolutePath;
        $stripped = $this->stripHtmlComments($content);
        foreach ($this->exemptPrefixesFor($rel) as $prefix) {
            if (str_contains($stripped, $prefix)) {
                return [];
            }
        }

        $violations = [];
        $seenFields = [];
        foreach (["select" => self::TYPE_RAW_SELECT, "input" => self::TYPE_RAW_INPUT] as $tagName => $type) {
            foreach (self::DOMAIN_FIELDS as $field => $tag) {
                if (isset($seenFields[$field])) {
                    continue;
                }
                foreach ($this->matchOpenTags($stripped, $tagName) as $match) {
                    $attrs = $this->parseHtmlAttributes($match['attrs']);
                    $name = preg_replace('/\[\]$/', '', (string)($attrs['name'] ?? ''));
                    if ($name !== $field) {
                        continue;
                    }
                    // 隐藏域只是回传已定 scope，用户并不在此选择，不算手写控件。
                    if ($tagName === 'input' && strtolower((string)($attrs['type'] ?? 'text')) === 'hidden') {
                        continue;
                    }
                    $seenFields[$field] = true;
                    $violations[] = [
                        'type' => $type,
                        'path' => $rel,
                        'line' => $match['line'],
                        'snippet' => $this->snippet($match['full']),
                        'field' => $field,
                        'suggest_tag' => self::resolveSuggestTag($rel, $field, $tag),
                    ];
                    break;
                }
            }
        }

        return $violations;
    }

    public function formatViolation(array $violation): string
    {
        $type = (string)($violation['type'] ?? '');
        $path = (string)($violation['path'] ?? '');
        $line = (int)($violation['line'] ?? 0);
        $snippet = (string)($violation['snippet'] ?? '');
        $tag = (string)($violation['suggest_tag'] ?? '');

        return "[{$type}] {$path}:{$line}: {$snippet} 建议改用 <{$tag} ... />；先运行 php bin/w taglib:catalog:generate，查不到标签时按 app/code/Weline/Taglib/doc/场景映射表.md 的自述块说明理由";
    }

    public function isIncluded(string $relativePath): bool
    {
        $path = str_replace('\\', '/', $relativePath);
        if (str_contains($path, '/view/templates/')
            || str_contains($path, '/view/theme/')
            || str_contains($path, '/view/hooks/')
            || str_contains($path, '/view/backend/')) {
            return true;
        }
        if (preg_match('#(?:^|/)app/design/.+#', $path)) {
            return true;
        }

        return false;
    }

    public function isExcluded(string $relativePath): bool
    {
        $path = str_replace('\\', '/', $relativePath);
        $needles = [
            '/view/tpl/',
            '/generated/',
            '/Backend/',
            '/backend/',
            '/theme/backend/',
            '/doc/',
            '/test/',
            '/prototype/',
        ];
        foreach ($needles as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeRoot(?string $codeRoot): string
    {
        if ($codeRoot !== null && $codeRoot !== '') {
            return rtrim(str_replace('\\', '/', $codeRoot), '/');
        }
        if (defined('BP')) {
            return rtrim(str_replace('\\', '/', BP . '/app/code'), '/');
        }

        return rtrim(str_replace('\\', '/', getcwd() . '/app/code'), '/');
    }

    private function toRelativePath(string $absolutePath, string $root): string
    {
        $abs = str_replace('\\', '/', $absolutePath);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (str_starts_with($abs, $root)) {
            return substr($abs, strlen($root));
        }

        return $abs;
    }

    private function stripHtmlComments(string $content): string
    {
        return (string)preg_replace('/<!--.*?-->/s', '', $content);
    }

    /**
     * @return list<array{full:string,attrs:string,line:int}>
     */
    private function matchOpenTags(string $content, string $tagName): array
    {
        // 允许引号内的属性值包含 >（例如 PHP 短输出属性）。
        $pattern = '/<' . preg_quote($tagName, '/') . '\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/is';
        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }
        $result = [];
        foreach ($matches as $match) {
            $full = (string)$match[0][0];
            $offset = (int)$match[0][1];
            $attrs = (string)($match[1][0] ?? '');
            $result[] = [
                'full' => $full,
                'attrs' => $attrs,
                'line' => $this->offsetToLine($content, $offset),
            ];
        }

        return $result;
    }

    private function offsetToLine(string $content, int $offset): int
    {
        if ($offset <= 0) {
            return 1;
        }

        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }

    /**
     * @return array<string, string>
     */
    private function parseHtmlAttributes(string $html): array
    {
        $attributes = [];
        if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[strtolower((string)$match[1])] = (string)$match[3];
            }
        }

        return $attributes;
    }

    private function snippet(string $full): string
    {
        $oneLine = preg_replace('/\s+/', ' ', trim($full)) ?? trim($full);
        if (strlen($oneLine) > 160) {
            return substr($oneLine, 0, 157) . '...';
        }

        return $oneLine;
    }
}
