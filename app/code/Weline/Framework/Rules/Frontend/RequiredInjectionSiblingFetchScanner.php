<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

/**
 * 同宿主同落点 XOR：布局内嵌与 default_injections 禁止并存。
 *
 * 不做运行时「只留一份」去重。扫描命中后应二选一：
 * - 布局已提供 → 清空 default_injections，并标 `placement=layout`
 * - 应用注入 → 布局只留空 `<w:slot>`，保留 required default_injections
 * 原生 layout 部件可另声明 placement=injection 的外国专用槽关系。
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
        $violations = [];
        if ($index['by_code'] !== []) {
            foreach ([$root, dirname($root) . '/design'] as $source) {
                if (!is_dir($source)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'phtml') {
                        continue;
                    }
                    $abs = str_replace('\\', '/', $file->getPathname());
                    if ($source === $root && !str_contains($abs, '/view/')) {
                        continue;
                    }
                    if ($source !== $root && !preg_match('#/(layouts|partials)/#', $abs)) {
                        continue;
                    }
                    $rel = $source === $root ? $this->toRelativePath($abs, $root) : 'design/' . substr($abs, strlen($source) + 1);
                    $violations = array_merge($violations, $this->scanFile($abs, $rel, $index));
                }
            }
        }

        return array_merge($violations, $this->scanLayoutPlacementDefaultInjections($root), $this->scanDefaultLayoutSeederLayoutPlacement($root));
    }

    /**
     * DefaultLayoutSeeder 不得再列 placement=layout 部件（与布局内嵌同名 = 双路径）。
     *
     * @return list<array{type:string,path:string,line:int,snippet:string,code:string,slot:string,module?:string}>
     */
    public function scanDefaultLayoutSeederLayoutPlacement(?string $codeRoot = null): array
    {
        $root = $this->normalizeRoot($codeRoot);
        $seeder = $root . '/Weline/Theme/Service/DefaultLayoutSeeder.php';
        if (!is_file($seeder)) {
            return [];
        }
        $content = (string)@file_get_contents($seeder);
        if ($content === '') {
            return [];
        }
        $placementByCode = $this->buildPlacementIndex($root);
        if ($placementByCode === []) {
            return [];
        }
        $violations = [];
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        foreach ($lines as $idx => $line) {
            if (!preg_match("/'widget_code'\\s*=>\\s*'([^']+)'/", $line, $m)) {
                continue;
            }
            $code = strtolower(trim((string)$m[1]));
            if ($code === '' || ($placementByCode[$code]['placement'] ?? '') !== 'layout') {
                continue;
            }
            $meta = $placementByCode[$code];
            $violations[] = [
                'type' => 'required-injection-seeder-layout-placement',
                'path' => $this->toRelativePath(str_replace('\\', '/', $seeder), $root),
                'line' => $idx + 1,
                'snippet' => $this->snippet(trim($line)),
                'code' => $code,
                'slot' => (string)($meta['slot'] ?? ''),
                'module' => (string)($meta['module'] ?? ''),
            ];
        }

        return $violations;
    }

    /**
     * @return array<string, array{placement:string,module:string,slot:string}>
     */
    private function buildPlacementIndex(string $root): array
    {
        $out = [];
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
                if ($code === '') {
                    continue;
                }
                $placement = strtolower(trim((string)($value['placement'] ?? 'injection')));
                $out[$code] = [
                    'placement' => $placement !== '' ? $placement : 'injection',
                    'module' => $module,
                    'slot' => strtolower(trim((string)($value['slot'] ?? ''))),
                ];
            }
        }

        return $out;
    }

    /**
     * @param array{
     *   by_code: array<string, array{module:string,slots:list<string>,template:string,native_layout?:bool}>,
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

        $content = preg_replace_callback('/<!--.*?-->/s', static fn(array $m): string => preg_replace('/[^\r\n]/', ' ', $m[0]), $content) ?? $content;
        $active = '';
        foreach (token_get_all($content) as $token) {
            $active .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? preg_replace('/[^\r\n]/', ' ', $token[1]) : $token[1]) : $token;
        }
        $content = $active;
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

    /** Validate native layout defaults and explicit foreign-slot injection relations. */
    private function scanLayoutPlacementDefaultInjections(string $root): array
    {
        $violations = [];
        $slotOwners = $this->layoutSlotOwners($root);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!$file->isFile() || $file->getFilename() !== 'widget.php' || !str_contains($path, '/extends/module/Weline_Widget/')) {
                continue;
            }
            $definitions = @include $path;
            if (!is_array($definitions)) {
                continue;
            }
            foreach ($definitions as $key => $definition) {
                if (!is_array($definition) || strtolower(trim((string)($definition['placement'] ?? ''))) !== 'layout'
                    || !is_array($definition['default_injections'] ?? null) || $definition['default_injections'] === []) {
                    continue;
                }
                $module = (string)$this->moduleFromWidgetPhpPath($path, $root);
                $injections = $definition['default_injections'];
                $injections = array_is_list($injections) ? $injections : [$injections];
                $foreignOnly = true;
                foreach ($injections as $injection) {
                    $slot = is_array($injection) ? strtolower(trim((string)($injection['slot'] ?? $injection['slot_id'] ?? ''))) : '';
                    $owners = $slotOwners[$slot] ?? [];
                    if (!is_array($injection) || ($injection['placement'] ?? '') !== 'injection'
                        || $slot === '' || $owners === [] || in_array($module, $owners, true)) {
                        $foreignOnly = false;
                        break;
                    }
                }
                if ($foreignOnly) {
                    continue;
                }
                $code = trim((string)($definition['code'] ?? (is_string($key) ? $key : '')));
                $content = (string)file_get_contents($path);
                $line = 1;
                if ($code !== '' && preg_match('/[\'"]' . preg_quote($code, '/') . '[\'"]\s*=>/', $content, $match, PREG_OFFSET_CAPTURE)) {
                    $line += substr_count(substr($content, 0, $match[0][1]), "\n");
                }
                $violations[] = [
                    'type' => 'layout-placement-default-injection',
                    'path' => $this->toRelativePath($path, $root),
                    'line' => $line,
                    'snippet' => 'placement=layout default_injections must declare injection into proven foreign layout slots',
                    'code' => $code,
                    'slot' => (string)($definition['slot'] ?? ''),
                    'module' => (string)$this->moduleFromWidgetPhpPath($path, $root),
                ];
            }
        }
        return $violations;
    }

    /** @return array<string,list<string>> */
    private function layoutSlotOwners(string $root): array
    {
        $owners = [];
        foreach ([$root, dirname($root) . '/design'] as $source) {
            if (!is_dir($source)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (!$file->isFile() || $file->getExtension() !== 'phtml' || !preg_match('#/(layouts|partials)/#', $path)) {
                    continue;
                }
                $owner = 'Weline_Theme';
                if ($source === $root) {
                    $relative = $this->toRelativePath($path, $root);
                    if (!preg_match('#^([^/]+)/([^/]+)/view/#', $relative, $match) || str_contains($relative, '/view/tpl/')) {
                        continue;
                    }
                    $owner = $match[1] . '_' . $match[2];
                }
                $content = (string)file_get_contents($path);
                $content = preg_replace('/<!--.*?-->/s', '', $content) ?? $content;
                $active = '';
                foreach (token_get_all($content) as $token) {
                    $active .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
                }
                $content = $active;
                foreach ($this->extractSlotIds($content) as $slot) {
                    $owners[$slot][] = $owner;
                }
            }
        }
        return $owners;
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
     *   by_code: array<string, array{module:string,slots:list<string>,template:string,native_layout?:bool}>,
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
                    'native_layout' => strtolower(trim((string)($value['placement'] ?? ''))) === 'layout',
                ];
                if ($tpl !== '') {
                    $suffix = $this->templateSuffix($tpl);
                    if ($suffix !== '') {
                        $byTpl[$suffix] = $code;
                    }
                    // 禁止用裸 basename（尤其 default.phtml）建索引：大量部件模板都叫 default.phtml，
                    // 否则会把顶栏 top-bar/default 误判成 checkout-delivery-context 等 required 注入。
                    $base = strtolower(basename(str_replace('\\', '/', $tpl), '.phtml'));
                    if ($base !== '' && $base !== 'default') {
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
     * @param array{by_code: array<string, array{module:string,slots:list<string>,template:string,native_layout?:bool}>} $index
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
        // Native layout widgets may also have foreign-slot injection relations.
        // Their own slot's accept=code does not identify the foreign target slot.
        if (!empty($meta['native_layout'])) {
            return null;
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
        // default.phtml 过短且高度冲突，仅允许精确 code / 完整 suffix 命中
        if ($base === 'default') {
            return null;
        }
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
