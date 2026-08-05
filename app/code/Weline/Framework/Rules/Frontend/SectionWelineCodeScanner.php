<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

/**
 * 前台 section / w:slot[wrapper=section] 的 weline-code 静态扫描器。
 *
 * 零跨模块依赖：属性解析为私有实现，不引用 Theme 服务。
 */
final class SectionWelineCodeScanner
{
    public const TYPE_LITERAL = 'literal-section';
    public const TYPE_SLOT = 'slot-section';
    public const TYPE_DUPLICATE = 'duplicate-code';

    /**
     * @return list<array{type:string,path:string,line:int,snippet:string,code?:string}>
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
     * @return list<array{type:string,path:string,line:int,snippet:string,code?:string}>
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
     * @return list<array{type:string,path:string,line:int,snippet:string,code?:string}>
     */
    public function scanFile(string $absolutePath, ?string $relativePath = null): array
    {
        $content = @file_get_contents($absolutePath);
        if ($content === false || $content === '') {
            return [];
        }
        $rel = $relativePath ?? $absolutePath;
        $stripped = $this->stripHtmlComments($content);
        $violations = [];
        $seenCodes = [];

        foreach ($this->matchOpenTags($stripped, 'section') as $match) {
            $attrs = $this->parseHtmlAttributes($match['attrs']);
            $codeInfo = $this->inspectWelineCode($attrs);
            if (!$codeInfo['configured'] || $codeInfo['empty_literal']) {
                $violations[] = [
                    'type' => self::TYPE_LITERAL,
                    'path' => $rel,
                    'line' => $match['line'],
                    'snippet' => $this->snippet($match['full']),
                ];
                continue;
            }
            if ($codeInfo['literal'] !== null && $codeInfo['literal'] !== '') {
                $key = strtolower($codeInfo['literal']);
                if (isset($seenCodes[$key])) {
                    $violations[] = [
                        'type' => self::TYPE_DUPLICATE,
                        'path' => $rel,
                        'line' => $match['line'],
                        'snippet' => $this->snippet($match['full']),
                        'code' => $codeInfo['literal'],
                    ];
                } else {
                    $seenCodes[$key] = true;
                }
            }
        }

        foreach ($this->matchOpenTags($stripped, 'w:slot') as $match) {
            $attrs = $this->parseHtmlAttributes($match['attrs']);
            $wrapper = strtolower(trim((string)($attrs['wrapper'] ?? 'div')));
            if ($wrapper !== 'section') {
                continue;
            }
            $codeInfo = $this->inspectWelineCode($attrs);
            if (!$codeInfo['configured'] || $codeInfo['empty_literal']) {
                $violations[] = [
                    'type' => self::TYPE_SLOT,
                    'path' => $rel,
                    'line' => $match['line'],
                    'snippet' => $this->snippet($match['full']),
                ];
                continue;
            }
            if ($codeInfo['literal'] !== null && $codeInfo['literal'] !== '') {
                $key = strtolower($codeInfo['literal']);
                if (isset($seenCodes[$key])) {
                    $violations[] = [
                        'type' => self::TYPE_DUPLICATE,
                        'path' => $rel,
                        'line' => $match['line'],
                        'snippet' => $this->snippet($match['full']),
                        'code' => $codeInfo['literal'],
                    ];
                } else {
                    $seenCodes[$key] = true;
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
        $code = (string)($violation['code'] ?? '');
        $extra = $code !== '' ? " code={$code}" : '';

        return "[{$type}] {$path}:{$line}: {$snippet}{$extra}";
    }

    public function isIncluded(string $relativePath): bool
    {
        $path = str_replace('\\', '/', $relativePath);
        if (str_contains($path, '/frontend/')
            || str_contains($path, '/Frontend/')
            || str_contains($path, '/theme/frontend/')) {
            return true;
        }
        if (str_contains($path, '/view/hooks/')) {
            return true;
        }
        if (str_contains($path, '/Weline/Index/view/templates/')
            || str_starts_with($path, 'Weline/Index/view/templates/')
            || str_contains($path, '/Index/view/templates/')) {
            return true;
        }

        return false;
    }

    public function isExcluded(string $relativePath): bool
    {
        $path = str_replace('\\', '/', $relativePath);
        $needles = [
            '/Backend/',
            '/backend/',
            '/theme/backend/',
            '/Admin/',
            'dashboard/widgets',
            '/view/tpl/',
            '/generated/',
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
        // Allow '>' inside quoted attribute values (PHP short-echo attributes).
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

    /**
     * @param array<string, string> $attrs
     * @return array{configured:bool,empty_literal:bool,literal:?string}
     */
    private function inspectWelineCode(array $attrs): array
    {
        if (!array_key_exists('weline-code', $attrs)) {
            return ['configured' => false, 'empty_literal' => false, 'literal' => null];
        }
        $raw = (string)$attrs['weline-code'];
        if (str_contains($raw, '<?')) {
            return ['configured' => true, 'empty_literal' => false, 'literal' => null];
        }
        $literal = trim($raw);
        if ($literal === '') {
            return ['configured' => true, 'empty_literal' => true, 'literal' => ''];
        }

        return ['configured' => true, 'empty_literal' => false, 'literal' => $literal];
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
