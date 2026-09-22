<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

/**
 * 同身份 XOR：布局/宿主内嵌 与 default_injections 禁止并存。
 *
 * 不做运行时「只留一份」去重。扫描命中后应二选一：
 * - 布局已提供 → 清空 default_injections，并标 `placement=layout`
 * - 应用注入 → 布局只留空 `<w:slot>`，保留 required default_injections
 */
final class RequiredInjectionSiblingFetchScanner
{
    public const TYPE_FETCH = 'required-injection-sibling-fetch';
    public const TYPE_WIDGET_TAG = 'required-injection-sibling-w-widget';

    /**
     * @return list<array{type:string,path:string,line:int,snippet:string,code:string,slot:string,module?:string}>
     */
    public function scanProject(?string $codeRoot = null): array
    {
        $root = $this->normalizeRoot($codeRoot);
        if (!is_dir($root)) {
            return [];
        }

        $index = $this->buildRequiredInjectionIndex($root);
        if ($index['by_code'] === []) {
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
            $abs = str_replace('\\', '/', $file->getPathname());
            if (!str_contains($abs, '/view/')) {
                continue;
            }
            $rel = $this->toRelativePath($abs, $root);
            $violations = array_merge($violations, $this->scanFile($abs, $rel, $index));
        }

        return $violations;
    }

    /**
     * @param array{
     *   by_code: array<string, array{module:string,slots:list<string>,template:string}>,
     *   by_template_suffix: array<string, string>
     * } $index
     * @return list<array{type:string,path:string,line:int,snippet:string,code:string,slot:string,module?:string}>
     */
    public function scanFile(string $absolutePath, string $relativePath, array $index): array
    {
        $content = @file_get_contents($absolutePath);
        if ($content === false || $content === '') {
            return [];
        }

        $slotIds = $this->extractSlotIds($content);
        $acceptTokens = $this->extractAcceptTokens($content);
        if ($slotIds === [] && $acceptTokens === []) {
            return [];
        }

        $violations = [];
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            if (preg_match_all(
                '/fetch\(\s*[\'"]([^\'"]*widgets\/[^\'"]+)[\'"]/',
                $line,
                $fetchMatches,
                PREG_SET_ORDER
            )) {
                foreach ($fetchMatches as $match) {
                    $tpl = (string)($match[1] ?? '');
                    $code = $this->resolveCodeFromTemplate($tpl, $index);
                    if ($code === null) {
                        continue;
                    }
                    $hit = $this->conflictSlot($code, $slotIds, $acceptTokens, $index);
                    if ($hit === null) {
                        continue;
                    }
                    $meta = $index['by_code'][$code];
                    $violations[] = [
                        'type' => self::TYPE_FETCH,
                        'path' => $relativePath,
                        'line' => $lineNo,
                        'snippet' => $this->snippet($match[0]),
                        'code' => $code,
                        'slot' => $hit,
                        'module' => $meta['module'],
                    ];
                }
            }
            if (preg_match_all('/<w:widget\b([^>]*)\/?>/i', $line, $widgetMatches, PREG_SET_ORDER)) {
                foreach ($widgetMatches as $match) {
                    $attrs = $this->parseAttrs($match[1] ?? '');
                    $name = strtolower(trim((string)($attrs['name'] ?? $attrs['code'] ?? '')));
                    if ($name === '' || !isset($index['by_code'][$name])) {
                        continue;
                    }
                    $hit = $this->conflictSlot($name, $slotIds, $acceptTokens, $index);
                    if ($hit === null) {
                        continue;
                    }
                    $meta = $index['by_code'][$name];
                    $violations[] = [
                        'type' => self::TYPE_WIDGET_TAG,
                        'path' => $relativePath,
                        'line' => $lineNo,
                        'snippet' => $this->snippet($match[0]),
                        'code' => $name,
                        'slot' => $hit,
                        'module' => $meta['module'],
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
        $slot = (string)($violation['slot'] ?? '');
        $module = (string)($violation['module'] ?? '');

        return "[{$type}] {$path}:{$line}: {$snippet} code={$code} slot={$slot} module={$module}";
    }

    /**
     * @return array{
     *   by_code: array<string, array{module:string,slots:list<string>,template:string}>,
     *   by_template_suffix: array<string, string>
     * }
     */
    public function buildRequiredInjectionIndex(?string $codeRoot = null): array
    {
        $root = $this->normalizeRoot($codeRoot);
        $byCode = [];
        $byTpl = [];

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
            if ($module === null) {
                continue;
            }
            $raw = @include $abs;
            if (!is_array($raw)) {
                continue;
            }
            foreach ($raw as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $code = strtolower(trim((string)($value['code'] ?? (is_string($key) ? $key : ''))));
                $tpl = trim((string)($value['template'] ?? ''));
                $injections = $value['default_injections'] ?? null;
                if ($code === '' || !is_array($injections)) {
                    continue;
                }
                $list = $injections === [] || array_keys($injections) === range(0, count($injections) - 1)
                    ? $injections
                    : [$injections];
                $slots = [];
                foreach ($list as $injection) {
                    if (!is_array($injection) || !$this->isRequired($injection['required'] ?? false)) {
                        continue;
                    }
                    $slot = strtolower(trim((string)($injection['slot'] ?? '')));
                    if ($slot !== '') {
                        $slots[] = $slot;
                    }
                }
                if ($slots === []) {
                    continue;
                }
                $slots = array_values(array_unique($slots));
                $byCode[$code] = [
                    'module' => $module,
                    'slots' => $slots,
                    'template' => $tpl,
                ];
                if ($tpl !== '') {
                    $suffix = $this->templateSuffix($tpl);
                    if ($suffix !== '') {
                        $byTpl[$suffix] = $code;
                    }
                    $base = strtolower(basename(str_replace('\\', '/', $tpl), '.phtml'));
                    if ($base !== '') {
                        $byTpl['widgets/' . $base . '.phtml'] = $code;
                        $byTpl[$base] = $code;
                    }
                }
                $byTpl[$code] = $code;
            }
        }

        return ['by_code' => $byCode, 'by_template_suffix' => $byTpl];
    }

    /**
     * @param list<string> $slotIds
     * @param list<string> $acceptTokens
     * @param array{by_code: array<string, array{module:string,slots:list<string>,template:string}>} $index
     */
    private function conflictSlot(
        string $code,
        array $slotIds,
        array $acceptTokens,
        array $index,
    ): ?string {
        $meta = $index['by_code'][$code] ?? null;
        if ($meta === null) {
            return null;
        }
        foreach ($meta['slots'] as $slot) {
            if (in_array($slot, $slotIds, true)) {
                return $slot;
            }
        }
        if (in_array($code, $acceptTokens, true)) {
            return $meta['slots'][0] ?? $code;
        }
        foreach ($meta['slots'] as $slot) {
            if (in_array($slot, $acceptTokens, true)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * @param array{by_template_suffix: array<string, string>} $index
     */
    private function resolveCodeFromTemplate(string $templatePath, array $index): ?string
    {
        $norm = strtolower(str_replace('\\', '/', trim($templatePath)));
        $suffix = $this->templateSuffix($norm);
        if ($suffix !== '' && isset($index['by_template_suffix'][$suffix])) {
            return $index['by_template_suffix'][$suffix];
        }
        $base = basename($norm, '.phtml');
        if ($base !== '' && isset($index['by_code'][$base])) {
            return $base;
        }
        if (isset($index['by_template_suffix'][$base])) {
            return $index['by_template_suffix'][$base];
        }

        return null;
    }

    /** @return list<string> */
    private function extractSlotIds(string $content): array
    {
        $ids = [];
        if (preg_match_all('/<w:slot\b([^>]*)>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs = $this->parseAttrs($match[1] ?? '');
                $id = strtolower(trim((string)($attrs['id'] ?? '')));
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<string> */
    private function extractAcceptTokens(string $content): array
    {
        $tokens = [];
        if (preg_match_all('/\baccept\s*=\s*([\'"])([^\'"]+)\1/i', $content, $matches)) {
            foreach ($matches[2] as $raw) {
                foreach (preg_split('/\s*,\s*/', strtolower((string)$raw)) ?: [] as $token) {
                    $token = trim($token);
                    if ($token !== '') {
                        $tokens[] = $token;
                    }
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    private function isRequired(mixed $required): bool
    {
        return $required === true || $required === 1 || $required === '1' || $required === 'true';
    }

    private function templateSuffix(string $template): string
    {
        $norm = strtolower(str_replace('\\', '/', $template));
        if (preg_match('#(?:^|::)(?:templates/|theme/)?(.+/widgets/.+\.phtml)$#', $norm, $m)) {
            return $m[1];
        }
        if (str_contains($norm, '/widgets/')) {
            $pos = strpos($norm, 'widgets/');

            return $pos === false ? '' : substr($norm, $pos);
        }

        return '';
    }

    private function moduleFromWidgetPhpPath(string $absolutePath, string $root): ?string
    {
        $rel = $this->toRelativePath($absolutePath, $root);
        // Vendor/Module/extends/module/Weline_Widget/...
        if (preg_match('#^([^/]+/[^/]+)/extends/module/Weline_Widget/#', $rel, $m)) {
            return str_replace('/', '_', $m[1]);
        }

        return null;
    }

    /** @return array<string, string> */
    private function parseAttrs(string $attrBlob): array
    {
        $attrs = [];
        if (preg_match_all('/([:@\w.-]+)\s*=\s*([\'"])(.*?)\2/u', $attrBlob, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs[strtolower($match[1])] = $match[3];
            }
        }

        return $attrs;
    }

    private function snippet(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? trim($text);

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '...' : $text;
    }

    private function normalizeRoot(?string $codeRoot): string
    {
        if ($codeRoot !== null && $codeRoot !== '') {
            return rtrim(str_replace('\\', '/', $codeRoot), '/');
        }
        if (defined('BP')) {
            return rtrim(str_replace('\\', '/', BP . '/app/code'), '/');
        }

        return rtrim(str_replace('\\', '/', dirname(__DIR__, 5) . '/app/code'), '/');
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
