<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

/**
 * Theme layouts/partials 内嵌部件归属扫描器。
 *
 * 仅允许解析归属为 Weline_Theme 的 <w:widget> / fetch(.../widgets/...)；
 * 任一非 Theme 模块在 widget.php 注册的 code 出现在 Theme 布局/partial 内嵌则违规。
 */
final class ThemeLayoutWidgetOwnerScanner
{
    public const TYPE_WIDGET_TAG = 'non-theme-w-widget';
    public const TYPE_FETCH = 'non-theme-widget-fetch';

    private const THEME_MODULE = 'Weline_Theme';

    /**
     * @return list<array{type:string,path:string,line:int,snippet:string,code?:string,module?:string}>
     */
    public function scanProject(?string $codeRoot = null): array
    {
        $root = $this->normalizeRoot($codeRoot);
        if (!is_dir($root)) {
            return [];
        }

        $foreign = $this->collectForeignWidgetCodes($root);
        if ($foreign['by_code'] === [] && $foreign['by_type_code'] === []) {
            return [];
        }

        $violations = [];
        $themeRoot = $root . '/Weline/Theme/view/theme';
        foreach (['frontend/layouts', 'frontend/partials', 'backend/layouts', 'backend/partials'] as $relDir) {
            $dir = $themeRoot . '/' . $relDir;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'phtml') {
                    continue;
                }
                $abs = $file->getPathname();
                $rel = $this->toRelativePath($abs, $root);
                $violations = array_merge($violations, $this->scanFile($abs, $rel, $foreign));
            }
        }

        return $violations;
    }

    /**
     * @param array{by_code:array<string,string>,by_type_code:array<string,string>} $foreign
     * @return list<array{type:string,path:string,line:int,snippet:string,code?:string,module?:string}>
     */
    public function scanFile(string $absolutePath, string $relativePath, array $foreign): array
    {
        $content = @file_get_contents($absolutePath);
        if ($content === false || $content === '') {
            return [];
        }

        $violations = [];
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            if (preg_match_all('/<w:widget\b([^>]*)\/?>/i', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $attrs = $this->parseAttrs($match[1] ?? '');
                    $name = trim((string)($attrs['name'] ?? $attrs['code'] ?? ''));
                    $type = trim((string)($attrs['type'] ?? ''));
                    $module = $this->resolveForeignModule($name, $type, $foreign);
                    if ($module === null) {
                        continue;
                    }
                    $violations[] = [
                        'type' => self::TYPE_WIDGET_TAG,
                        'path' => $relativePath,
                        'line' => $lineNo,
                        'snippet' => $this->snippet($match[0]),
                        'code' => $name !== '' ? $name : ($type !== '' ? $type : ''),
                        'module' => $module,
                    ];
                }
            }
            if (preg_match_all(
                '/fetch\(\s*[\'"]([^\'"]*widgets\/[^\'"]+)[\'"]/',
                $line,
                $fetchMatches,
                PREG_SET_ORDER
            )) {
                foreach ($fetchMatches as $match) {
                    $path = (string)($match[1] ?? '');
                    $owner = $this->moduleFromTemplatePath($path);
                    if ($owner === null || $owner === self::THEME_MODULE) {
                        continue;
                    }
                    $code = $this->codeFromWidgetTemplatePath($path);
                    $violations[] = [
                        'type' => self::TYPE_FETCH,
                        'path' => $relativePath,
                        'line' => $lineNo,
                        'snippet' => $this->snippet($match[0]),
                        'code' => $code,
                        'module' => $owner,
                    ];
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
        $module = (string)($violation['module'] ?? '');
        $extra = '';
        if ($code !== '') {
            $extra .= " code={$code}";
        }
        if ($module !== '') {
            $extra .= " module={$module}";
        }

        return "[{$type}] {$path}:{$line}: {$snippet}{$extra}";
    }

    /**
     * @return array{by_code:array<string,string>,by_type_code:array<string,string>}
     */
    public function collectForeignWidgetCodes(?string $codeRoot = null): array
    {
        $root = $this->normalizeRoot($codeRoot);
        $byCode = [];
        $byTypeCode = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() !== 'widget.php') {
                continue;
            }
            $abs = str_replace('\\', '/', $file->getPathname());
            if (!str_contains($abs, '/extends/module/Weline_Widget/')) {
                continue;
            }
            $module = $this->moduleFromWidgetPhpPath($abs, $root);
            if ($module === null || $module === self::THEME_MODULE) {
                continue;
            }
            $definitions = $this->loadWidgetDefinitions($abs);
            foreach ($definitions as $def) {
                $code = $def['code'];
                $type = $def['type'];
                if ($code === '') {
                    continue;
                }
                $byCode[strtolower($code)] = $module;
                if ($type !== '') {
                    $byTypeCode[strtolower($type . '/' . $code)] = $module;
                }
            }
        }

        return ['by_code' => $byCode, 'by_type_code' => $byTypeCode];
    }

    /**
     * @return list<array{code:string,type:string}>
     */
    private function loadWidgetDefinitions(string $absolutePath): array
    {
        $raw = @include $absolutePath;
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($value)) {
                $tpl = $value;
                $code = is_string($key) && !is_numeric($key)
                    ? $key
                    : $this->codeFromWidgetTemplatePath($tpl);
                $type = $this->typeFromWidgetTemplatePath($tpl);
                $out[] = ['code' => $code, 'type' => $type];
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $tpl = '';
            if (isset($value['template']) && is_string($value['template'])) {
                $tpl = $value['template'];
            }
            $code = trim((string)($value['code'] ?? ''));
            if ($code === '' && is_string($key) && !is_numeric($key)) {
                $code = $key;
            }
            if ($code === '' && $tpl !== '') {
                $code = $this->codeFromWidgetTemplatePath($tpl);
            }
            $type = trim((string)($value['type'] ?? ''));
            if ($type === '' && $tpl !== '') {
                $type = $this->typeFromWidgetTemplatePath($tpl);
            }
            $out[] = ['code' => $code, 'type' => $type];
        }

        return $out;
    }

    /**
     * @param array{by_code:array<string,string>,by_type_code:array<string,string>} $foreign
     */
    private function resolveForeignModule(string $name, string $type, array $foreign): ?string
    {
        if ($name === '') {
            return null;
        }
        $typeCodeKey = strtolower(trim($type) . '/' . $name);
        if ($type !== '' && isset($foreign['by_type_code'][$typeCodeKey])) {
            return $foreign['by_type_code'][$typeCodeKey];
        }
        $codeKey = strtolower($name);
        if (isset($foreign['by_code'][$codeKey])) {
            return $foreign['by_code'][$codeKey];
        }

        return null;
    }

    private function moduleFromWidgetPhpPath(string $absolutePath, string $root): ?string
    {
        $rel = $this->toRelativePath($absolutePath, $root);
        // Vendor/Module/extends/module/Weline_Widget/Vendor_Module/widget.php
        if (preg_match('#^([^/]+)/([^/]+)/extends/module/Weline_Widget/([^/]+)/widget\.php$#', $rel, $m)) {
            return $m[3];
        }
        if (preg_match('#/extends/module/Weline_Widget/([^/]+)/widget\.php$#', $absolutePath, $m)) {
            return $m[1];
        }

        return null;
    }

    private function moduleFromTemplatePath(string $path): ?string
    {
        if (preg_match('/^([A-Za-z0-9_]+)::/', $path, $m)) {
            return $m[1];
        }

        return null;
    }

    private function codeFromWidgetTemplatePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#widgets/([^/]+)/([^/]+)/default\.phtml#', $path, $m)) {
            return $m[2];
        }
        if (preg_match('#widgets/([^/]+)/default\.phtml#', $path, $m)) {
            return $m[1];
        }
        if (preg_match('#widgets/([^/]+)\.phtml#', $path, $m)) {
            return $m[1];
        }

        return '';
    }

    private function typeFromWidgetTemplatePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#widgets/([^/]+)/([^/]+)/default\.phtml#', $path, $m)) {
            return $m[1];
        }

        return '';
    }

    /** @return array<string,string> */
    private function parseAttrs(string $attrBlob): array
    {
        $attrs = [];
        if (preg_match_all('/(\w+)\s*=\s*"([^"]*)"/', $attrBlob, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $attrs[strtolower($row[1])] = $row[2];
            }
        }
        if (preg_match_all("/(\w+)\s*=\s*'([^']*)'/", $attrBlob, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $attrs[strtolower($row[1])] = $row[2];
            }
        }

        return $attrs;
    }

    private function snippet(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);
        if (strlen($text) > 160) {
            return substr($text, 0, 157) . '...';
        }

        return $text;
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
}
