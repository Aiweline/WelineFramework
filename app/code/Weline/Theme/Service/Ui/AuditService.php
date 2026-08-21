<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ui;

final class AuditService
{
    private const RUNTIME_EXTENSIONS = ['css', 'html', 'js', 'json', 'mjs', 'php', 'phtml', 'svg', 'xml'];

    private const BANNED_PATH_PATTERN = '/(?:bootstrap|jquery|requirejs|metismenu|simplebar|node-waves|sweetalert|select2|parsley|inputmask|datatables(?:\.net)?|font-?awesome|remixicon|materialdesignicons|jqvmap|jstree|rwd-table|table-edits|chart\.js)/i';

    private const BANNED_CONTENT_PATTERNS = [
        'third_party_reference' => '/(?:bootstrap(?:\.bundle)?(?:\.min)?\.(?:css|js)|jquery(?:\.min)?\.js|require(?:\.min)?\.js|metisMenu|simplebar|node-waves|sweetalert2|select2|parsley|inputmask|jquery\.dataTables|dataTables(?:\.min)?\.(?:css|js)|font-?awesome|remixicon|materialdesignicons|jqvmap|jstree|rwd-table|table-edits|assets\/libs\/(?:apexcharts|chart\.js))/i',
        'bootstrap_attribute' => '/\bdata-bs-[a-z0-9_-]+/i',
        'legacy_theme_attribute' => '/\bdata-(?:theme-mode|layout-mode|layout-width|layout-position|topbar|sidebar-size|sidebar-color|sidebar-image|preloader)\b/i',
        'vendor_icon' => '/\b(?:mdi|fa[brs]?|ri)-[a-z0-9_-]+\b/i',
        'legacy_global' => '/\b(?:BackendToast|AdminToast|BackendConfirm|AdminConfirm|WelineSmartDropdown|Swal)(?:\b|\.)|\bwindow\.(?:bootstrap|Swal|jQuery)\b|\bbootstrap\.(?:Modal|Offcanvas|Collapse|Dropdown|Toast|Tooltip|Popover|Tab|Alert|Carousel)\b/',
        'legacy_ajax_bridge' => '/\b(?:bqAdmin|__bqAdmin_[A-Za-z0-9_]+)\b/',
    ];

    private const LEGACY_CLASS_PATTERN = '/^(?:container(?:-fluid)?|row|col(?:-[a-z]+)?(?:-[0-9]+)?|btn(?:-[a-z0-9_-]+)?|card(?:-[a-z0-9_-]+)?|form-(?:control|select|check(?:-input|-label)?|switch|group)|input-group(?:-[a-z0-9_-]+)?|modal(?:-[a-z0-9_-]+)?|offcanvas(?:-[a-z0-9_-]+)?|dropdown(?:-[a-z0-9_-]+)?|table(?:-[a-z0-9_-]+)?|alert(?:-[a-z0-9_-]+)?|badge|pagination|page-item|page-link|breadcrumb|nav(?:-[a-z0-9_-]+)?|accordion(?:-[a-z0-9_-]+)?|spinner-border|d-(?:flex|grid|none|block|inline(?:-block)?)|align-items-[a-z0-9_-]+|justify-content-[a-z0-9_-]+|[mp][trblxy]?-[0-9]+|text-(?:start|end|center|muted|primary|secondary|success|warning|danger|info|white)|bg-[a-z0-9_-]+|w-100|h-100|sr-only|visually-hidden|invalid-tooltip|waves(?:-light)?|show|fade)$/i';

    private const LAYER_ORDER = '@layer reset, tokens, base, layout, components, utilities, page;';

    public function __construct(private readonly AssetManifest $manifest)
    {
    }

    public function auditRepository(?string $root = null): array
    {
        $root = rtrim($root ?? BP . 'app/code/Weline', '/');
        if (!is_dir($root)) {
            throw new \RuntimeException(__('Weline UI 审计目录不存在：%{1}', [$root]));
        }

        $violations = $this->auditManifest();
        $filesScanned = 0;
        $specializedHashes = [];
        $engineAliases = $this->engineAliases();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) continue;
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = $this->relativeToProject($path);
            $extension = strtolower($file->getExtension());
            if ($this->isExcluded($relative) || !in_array($extension, self::RUNTIME_EXTENSIONS, true)) continue;
            $filesScanned++;

            $engine = $this->specializedEngineForPath($relative);
            if (preg_match(self::BANNED_PATH_PATTERN, $relative) === 1 && $engine === null) {
                $violations[] = $this->violation('third_party_path', $relative, 1, basename($relative));
            }

            if ($engine !== null) {
                if (in_array($extension, ['css', 'js', 'mjs', 'svg'], true) && $file->getSize() > 512) {
                    $hash = hash_file('sha256', $path);
                    if (is_string($hash)) $specializedHashes[$hash][$engine][] = $relative;
                }
                continue;
            }

            $content = @file_get_contents($path);
            if (!is_string($content)) {
                $violations[] = $this->violation('unreadable_source', $relative, 1, 'unreadable');
                continue;
            }

            foreach (self::BANNED_CONTENT_PATTERNS as $code => $pattern) {
                $this->appendPatternViolations($violations, $code, $relative, $content, $pattern);
            }
            if (in_array($extension, ['html', 'js', 'mjs', 'phtml'], true)) {
                $this->appendPatternViolations(
                    $violations,
                    'jquery_api',
                    $relative,
                    $content,
                    '/\bjQuery\b|(?<![A-Za-z0-9_$])\$\s*\(/',
                );
                $this->appendPatternViolations(
                    $violations,
                    'framework_runtime',
                    $relative,
                    $content,
                    '/\bnew\s+Vue\b|\bVue\.(?:createApp|component|use)\b|\brequirejs\b|\brequire\s*\(\s*["\']/',
                );
            }

            $this->auditSpecializedReferences($violations, $relative, $content, $engineAliases);
            if (in_array($extension, ['html', 'php', 'phtml'], true)) {
                $this->auditMarkup($violations, $relative, $content);
            }
            if ($extension === 'css') {
                $this->auditCss($violations, $relative, $content);
            }
        }

        foreach ($specializedHashes as $hash => $engines) {
            if (count($engines) < 2) continue;
            $paths = [];
            foreach ($engines as $enginePaths) array_push($paths, ...$enginePaths);
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                $violations[] = $this->violation('duplicate_specialized_engine_file', $path, 1, substr($hash, 0, 16));
            }
        }

        $budget = $this->auditBudgets();
        array_push($violations, ...$budget['violations']);
        $violations = $this->uniqueViolations($violations);
        usort($violations, static fn(array $a, array $b): int => [$a['path'], $a['line'], $a['code']] <=> [$b['path'], $b['line'], $b['code']]);

        return [
            'schema_version' => 'weline-ui-audit.v1',
            'ok' => $violations === [],
            'files_scanned' => $filesScanned,
            'violations' => $violations,
            'budgets' => $budget['measurements'],
        ];
    }

    private function auditManifest(): array
    {
        $violations = [];
        $outputs = [];
        $sources = [];
        foreach ($this->manifest->bundles() as $name => $bundle) {
            $sourceRoot = $this->manifest->bundleSourceRoot($bundle);
            $output = trim((string)($bundle['output'] ?? ''), '/');
            if ($output === '' || isset($outputs[$output])) {
                $violations[] = $this->violation('duplicate_bundle_output', $this->relativeToProject($this->manifest->path()), 1, $output ?: (string)$name);
            }
            $outputs[$output] = (string)$name;
            if (($bundle['generator'] ?? null) === 'icon_registry') continue;
            foreach ((array)($bundle['sources'] ?? []) as $source) {
                $source = ltrim((string)$source, '/');
                $sourcePath = $sourceRoot . $source;
                if (!is_file($sourcePath)) {
                    $violations[] = $this->violation('missing_bundle_source', $this->relativeToProject($sourcePath), 1, (string)$name);
                }
                $sourceKey = $this->relativeToProject($sourceRoot) . $source;
                if (isset($sources[$sourceKey])) {
                    $violations[] = $this->violation('duplicate_bundle_source', $this->relativeToProject($sourcePath), 1, $sources[$sourceKey] . ',' . $name);
                }
                $sources[$sourceKey] = (string)$name;
            }
        }

        $routeEngines = [];
        foreach ($this->manifest->routes() as $route => $config) {
            foreach ((array)($config['templates'] ?? []) as $template) {
                $path = BP . ltrim((string)$template, '/');
                if (!is_file($path)) {
                    $violations[] = $this->violation('missing_route_template', (string)$template, 1, (string)$route);
                }
            }
            foreach ((array)($config['engines'] ?? []) as $engine) $routeEngines[(string)$engine][] = (string)$route;
        }

        foreach ($this->manifest->specializedEngines() as $name => $engine) {
            foreach ((array)($engine['paths'] ?? []) as $prefix) {
                $path = BP . ltrim((string)$prefix, '/');
                if (!is_dir($path) && !is_file(rtrim($path, '/'))) {
                    $violations[] = $this->violation('missing_specialized_engine', (string)$prefix, 1, (string)$name);
                }
            }
            foreach ((array)($engine['consumers'] ?? []) as $consumer) {
                if (!is_file(BP . ltrim((string)$consumer, '/'))) {
                    $violations[] = $this->violation('missing_engine_consumer', (string)$consumer, 1, (string)$name);
                }
            }
            if (!isset($routeEngines[$name])) {
                $violations[] = $this->violation('engine_without_route', $this->relativeToProject($this->manifest->path()), 1, (string)$name);
            }
        }

        return $violations;
    }

    private function auditMarkup(array &$violations, string $relative, string $content): void
    {
        if (preg_match_all('/<style(?:\s|>)/i', $content, $matches, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($matches[0] ?? [] as [$match, $offset]) {
                $violations[] = $this->atOffset('inline_style_block', $relative, $content, (int)$offset, (string)$match);
            }
        }
        if (preg_match_all('/<script\b([^>]*)>/i', $content, $scripts, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($scripts[0] ?? [] as $index => [$tag, $offset]) {
                $attributes = (string)($scripts[1][$index][0] ?? '');
                if (preg_match('/\bsrc\s*=/', $attributes) === 1) continue;
                if (preg_match('/\btype\s*=\s*["\']application\/json["\']/', $attributes) === 1) continue;
                $violations[] = $this->atOffset('inline_script_block', $relative, $content, (int)$offset, (string)$tag);
            }
        }
        if (preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/is', $content, $classes, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($classes[2] ?? [] as [$value, $offset]) {
                foreach (preg_split('/\s+/', trim(strip_tags((string)$value))) ?: [] as $token) {
                    $token = trim($token, " \t\n\r\0\x0B{}()[]<>=,;:'\"");
                    if ($token !== '' && preg_match(self::LEGACY_CLASS_PATTERN, $token) === 1) {
                        $violations[] = $this->atOffset('legacy_class', $relative, $content, (int)$offset, $token);
                    }
                }
            }
        }
        if (preg_match_all('/\bstyle\s*=\s*(["\'])(.*?)\1/is', $content, $styles, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($styles[2] ?? [] as [$value, $offset]) {
                if (!$this->isAllowedCustomPropertyStyle((string)$value)) {
                    $violations[] = $this->atOffset('inline_style_attribute', $relative, $content, (int)$offset, $this->compactMatch((string)$value));
                }
            }
        }
    }

    private function auditCss(array &$violations, string $relative, string $content): void
    {
        if (!$this->isTokenSource($relative)) {
            $this->appendPatternViolations(
                $violations,
                'raw_color_outside_tokens',
                $relative,
                $content,
                '/#[0-9a-f]{3,8}\b|\b(?:rgb|rgba|hsl|hsla)\s*\(/i',
            );
        }
        if (str_contains($relative, '/Theme/view/ui/css/') && !str_contains($content, self::LAYER_ORDER)) {
            $violations[] = $this->violation('missing_css_layer_contract', $relative, 1, self::LAYER_ORDER);
        }
        $this->appendPatternViolations(
            $violations,
            'legacy_class_selector',
            $relative,
            $content,
            '/\.(?:btn(?:-[a-z0-9_-]+)?|card(?:-[a-z0-9_-]+)?|form-(?:control|select|check|switch)|modal(?:-[a-z0-9_-]+)?|offcanvas(?:-[a-z0-9_-]+)?|dropdown(?:-[a-z0-9_-]+)?|alert(?:-[a-z0-9_-]+)?|spinner-border|d-none|waves-light)(?![a-z0-9_-])/i',
        );
    }

    private function auditSpecializedReferences(array &$violations, string $relative, string $content, array $aliases): void
    {
        foreach ($aliases as $name => $engine) {
            $referenced = false;
            foreach ($engine['aliases'] as $alias) {
                if ($alias !== '' && str_contains($content, $alias)) {
                    $referenced = true;
                    break;
                }
            }
            if (!$referenced || in_array($relative, $engine['consumers'], true)) continue;
            $violations[] = $this->violation('engine_outside_declared_route', $relative, 1, (string)$name);
        }
    }

    private function auditBudgets(): array
    {
        $measurements = [];
        $violations = [];
        $bundles = $this->manifest->bundles();
        $outputRoot = $this->manifest->outputRoot();
        $expectedFiles = ['manifest.json' => true];
        foreach ($bundles as $name => $bundle) {
            $output = (string)($bundle['output'] ?? '');
            $expectedFiles[$output] = true;
            $path = $outputRoot . $output;
            if (!is_file($path)) {
                $violations[] = $this->violation('missing_compiled_bundle', $this->relativeToProject($path), 1, (string)$name);
                continue;
            }
            $content = (string)file_get_contents($path);
            $measurements[$name] = [
                'bytes' => strlen($content),
                'gzip_bytes' => strlen((string)gzencode($content, 9)),
                'sha256' => hash('sha256', $content),
                'type' => (string)($bundle['type'] ?? ''),
            ];
        }
        if (is_dir($outputRoot)) {
            foreach (new \DirectoryIterator($outputRoot) as $file) {
                if (!$file->isFile() || isset($expectedFiles[$file->getFilename()])) continue;
                $violations[] = $this->violation('stale_compiled_bundle', $this->relativeToProject($file->getPathname()), 1, $file->getFilename());
            }
        }

        $this->auditCompiledManifest($violations, $measurements, $outputRoot . 'manifest.json');
        $areas = $this->manifest->areas();
        $budgets = $this->manifest->budgets();
        $backendCss = $this->sumAreaGzip($measurements, $areas['backend'] ?? [], 'css');
        $frontendCss = $this->sumAreaGzip($measurements, $areas['frontend'] ?? [], 'css');
        $backendJs = $this->sumAreaGzip($measurements, $areas['backend'] ?? [], 'js');
        $frontendJs = $this->sumAreaGzip($measurements, $areas['frontend'] ?? [], 'js');
        $backendTotal = $this->sumGzip($measurements, $areas['backend'] ?? []);
        $measurements['area_totals'] = [
            'backend_css_gzip' => $backendCss,
            'frontend_css_gzip' => $frontendCss,
            'backend_js_gzip' => $backendJs,
            'frontend_js_gzip' => $frontendJs,
            'backend_total_gzip' => $backendTotal,
            'backend_requests' => count($areas['backend'] ?? []),
            'frontend_requests' => count($areas['frontend'] ?? []),
        ];

        foreach (['backend_css_gzip' => $backendCss, 'frontend_css_gzip' => $frontendCss, 'backend_total_gzip' => $backendTotal] as $key => $actual) {
            $this->enforceBudget($violations, $key, $actual, (int)($budgets[$key] ?? 0));
        }
        $initialJsLimit = (int)($budgets['initial_js_gzip'] ?? 0);
        $this->enforceBudget($violations, 'backend_initial_js_gzip', $backendJs, $initialJsLimit);
        $this->enforceBudget($violations, 'frontend_initial_js_gzip', $frontendJs, $initialJsLimit);
        $lazyLimit = (int)($budgets['lazy_component_gzip'] ?? 0);
        foreach (array_keys($this->manifest->lazyComponents()) as $bundle) {
            $this->enforceBudget($violations, 'lazy_component_gzip:' . $bundle, (int)($measurements[$bundle]['gzip_bytes'] ?? 0), $lazyLimit);
        }
        $requestLimit = (int)($budgets['global_requests'] ?? 0);
        foreach (['backend', 'frontend'] as $area) {
            $actual = count($areas[$area] ?? []);
            if ($requestLimit > 0 && $actual > $requestLimit) {
                $violations[] = $this->violation('request_budget_exceeded', $this->relativeToProject($this->manifest->path()), 1, "{$area}:{$actual}>{$requestLimit}");
            }
        }

        return ['measurements' => $measurements, 'violations' => $violations];
    }

    private function auditCompiledManifest(array &$violations, array $measurements, string $path): void
    {
        if (!is_file($path)) {
            $violations[] = $this->violation('missing_compiled_manifest', $this->relativeToProject($path), 1, 'manifest.json');
            return;
        }
        try {
            $compiled = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $violations[] = $this->violation('invalid_compiled_manifest', $this->relativeToProject($path), 1, $exception->getMessage());
            return;
        }
        foreach ($measurements as $name => $measurement) {
            if ($name === 'area_totals') continue;
            if (($compiled['bundles'][$name]['sha256'] ?? null) !== ($measurement['sha256'] ?? null)) {
                $violations[] = $this->violation('compiled_hash_mismatch', $this->relativeToProject($path), 1, (string)$name);
            }
        }
        $buildId = (string)($compiled['build_id'] ?? '');
        unset($compiled['build_id']);
        $compiled = $this->canonicalize($compiled);
        $expected = hash('sha256', json_encode($compiled, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($buildId === '' || !hash_equals($expected, $buildId)) {
            $violations[] = $this->violation('compiled_build_id_mismatch', $this->relativeToProject($path), 1, $buildId ?: 'missing');
        }
    }

    private function engineAliases(): array
    {
        $aliases = [];
        foreach ($this->manifest->specializedEngines() as $name => $engine) {
            $aliases[$name] = ['aliases' => [], 'consumers' => array_values((array)($engine['consumers'] ?? []))];
            foreach ((array)($engine['paths'] ?? []) as $prefix) {
                if (preg_match('#^app/code/Weline/([^/]+)/view/statics/(.+)$#', (string)$prefix, $match) === 1) {
                    $aliases[$name]['aliases'][] = 'Weline_' . $match[1] . '::' . trim($match[2], '/');
                }
            }
        }
        return $aliases;
    }

    private function specializedEngineForPath(string $path): ?string
    {
        foreach ($this->manifest->specializedEngines() as $name => $engine) {
            foreach ((array)($engine['paths'] ?? []) as $prefix) {
                if (is_string($prefix) && $prefix !== '' && str_starts_with($path, $prefix)) return (string)$name;
            }
        }
        return null;
    }

    private function isAllowedCustomPropertyStyle(string $style): bool
    {
        if (preg_match('/--(?:w|weline|backend-theme)-[a-z0-9-]+\s*:/i', $style) !== 1) return false;
        if (preg_match('/(?:^|;)\s*(?!\s*--(?:w|weline|backend-theme)-)[a-z-]+\s*:/i', $style) === 1) return false;
        return preg_match('/(?:url\s*\(|expression\s*\(|javascript\s*:|@import)/i', $style) !== 1;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->manifest->auditExcludes() as $fragment) {
            if ($fragment !== '' && str_contains('/' . $path, $fragment)) return true;
        }
        return false;
    }

    private function isTokenSource(string $path): bool
    {
        return str_contains($path, '/tokens/')
            || str_contains($path, '/variables/')
            || str_contains($path, '/colors/')
            || str_ends_with($path, '/Theme/view/ui/css/foundation.css');
    }

    private function appendPatternViolations(array &$violations, string $code, string $relative, string $content, string $pattern): void
    {
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === false) return;
        foreach ($matches[0] ?? [] as [$match, $offset]) {
            $violations[] = $this->atOffset($code, $relative, $content, (int)$offset, (string)$match);
        }
    }

    private function sumGzip(array $measurements, array $names): int
    {
        $sum = 0;
        foreach ($names as $name) $sum += (int)($measurements[$name]['gzip_bytes'] ?? 0);
        return $sum;
    }

    private function sumAreaGzip(array $measurements, array $names, string $type): int
    {
        $sum = 0;
        foreach ($names as $name) {
            if (($measurements[$name]['type'] ?? null) === $type) $sum += (int)($measurements[$name]['gzip_bytes'] ?? 0);
        }
        return $sum;
    }

    private function enforceBudget(array &$violations, string $key, int $actual, int $limit): void
    {
        if ($limit > 0 && $actual > $limit) {
            $violations[] = $this->violation('budget_exceeded', $this->relativeToProject($this->manifest->path()), 1, "{$key}:{$actual}>{$limit}");
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn(mixed $item): mixed => $this->canonicalize($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    private function uniqueViolations(array $violations): array
    {
        $unique = [];
        foreach ($violations as $violation) {
            $key = implode('|', [$violation['code'], $violation['path'], $violation['line'], $violation['match']]);
            $unique[$key] = $violation;
        }
        return array_values($unique);
    }

    private function atOffset(string $code, string $path, string $content, int $offset, string $match): array
    {
        return $this->violation($code, $path, 1 + substr_count(substr($content, 0, $offset), "\n"), $this->compactMatch($match));
    }

    private function relativeToProject(string $path): string
    {
        $base = str_replace('\\', '/', rtrim(BP, '/\\')) . '/';
        $path = str_replace('\\', '/', $path);
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function violation(string $code, string $path, int $line, string $match): array
    {
        return ['code' => $code, 'path' => $path, 'line' => $line, 'match' => $match];
    }

    private function compactMatch(string $match): string
    {
        $match = trim((string)preg_replace('/\s+/', ' ', $match));
        return strlen($match) > 120 ? substr($match, 0, 117) . '...' : $match;
    }
}
