<?php

declare(strict_types=1);

namespace WeShop\Base\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeContextService;

class ThemeCompatibilityService
{
    private const PREVIEW_BANNER_MARKER = 'weshop-theme-compatibility-banner';

    public function __construct(
        private readonly ThemeCompatibilityManifestProvider $manifestProvider,
        private readonly WelineTheme $welineTheme,
        private readonly ?ThemeContextService $themeContextService = null
    ) {
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function inspectFromRequest(Request $request, array $overrides = []): array
    {
        $area = $this->normalizeArea((string) ($overrides['area'] ?? $request->getParam(
            'editor_area',
            $request->getParam('area', 'frontend')
        )));
        $layoutType = trim((string) ($overrides['layout_type'] ?? $request->getParam(
            'layout_type',
            $request->getParam('page_type', 'homepage')
        )));
        if ($layoutType === '') {
            $layoutType = 'homepage';
        }

        $scope = $this->resolveScope(
            $area,
            (string) ($overrides['scope'] ?? $request->getParam('scope', 'default'))
        );

        $themeId = (int) ($overrides['theme_id'] ?? 0);
        if ($themeId <= 0) {
            $themeId = $area === ThemeContextService::AREA_BACKEND
                ? (int) $request->getParam('backend_theme_id', 0)
                : (int) $request->getParam('frontend_theme_id', 0);
        }
        if ($themeId <= 0) {
            $themeId = (int) $request->getParam('theme_id', 0);
        }

        $theme = $this->resolveTheme($themeId, $area);
        $layoutOption = trim((string) ($overrides['layout_option'] ?? $request->getParam('layout_option', '')));
        if ($layoutOption === '' && $theme) {
            $layoutOption = $this->resolveConfiguredLayoutOption($theme, $area, $layoutType, $scope);
        }

        return $this->inspectTheme($theme, $area, $layoutType, $layoutOption, $scope);
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectTheme(
        ?WelineTheme $theme,
        string $area,
        string $layoutType,
        ?string $layoutOption = null,
        string $scope = 'default'
    ): array {
        $area = $this->normalizeArea($area);
        $layoutType = trim($layoutType) !== '' ? trim($layoutType) : 'homepage';
        $layoutOption = trim((string) $layoutOption) !== '' ? trim((string) $layoutOption) : 'default';
        $scope = $this->resolveScope($area, $scope);

        if (!$theme || !$theme->getId()) {
            return $this->buildCompatibilityResult(null, $area, $layoutType, $layoutOption, $scope, null, []);
        }

        $templateDefinitions = $this->getTemplateDefinitions($area, $layoutType);
        $layoutFiles = [];
        $layoutContents = [];
        foreach ($templateDefinitions as $templateDefinition) {
            $layoutFile = $this->resolveLayoutFilePath($theme, $area, $layoutType, $layoutOption, $templateDefinition);
            if (!$layoutFile || !is_file($layoutFile)) {
                continue;
            }

            $layoutFiles[] = $layoutFile;
            $layoutContents[] = (string) file_get_contents($layoutFile);
        }
        $layoutFiles = array_values(array_unique($layoutFiles));
        $layoutFile = $layoutFiles[0] ?? null;
        $layoutContent = implode(PHP_EOL, $layoutContents);

        $missingModules = [];
        $missingHosts = [];

        foreach ($this->getLayoutManifest($area, $layoutType) as $module => $definition) {
            if (!$this->isModuleEnabled($module)) {
                continue;
            }

            $moduleMissingHosts = [];
            foreach ((array) ($definition['hosts'] ?? []) as $hostDefinition) {
                $host = $this->normalizeHostDefinition($hostDefinition);
                if ($host === null || !($host['required'] ?? true)) {
                    continue;
                }

                if ($this->layoutContainsHost($layoutContent, $host)) {
                    continue;
                }

                $missingHost = [
                    'module' => $module,
                    'type' => $host['type'],
                    'name' => $host['name'],
                ];
                $moduleMissingHosts[] = $missingHost;
                $missingHosts[] = $missingHost;
            }

            if ($moduleMissingHosts) {
                $missingModules[] = [
                    'module' => $module,
                    'description' => (string) ($definition['description'] ?? ''),
                    'hosts' => $moduleMissingHosts,
                ];
            }
        }

        return $this->buildCompatibilityResult(
            $theme,
            $area,
            $layoutType,
            $layoutOption,
            $scope,
            $layoutFile,
            [
                'layout_files' => $layoutFiles,
                'missing_hosts' => $missingHosts,
                'missing_modules' => $missingModules,
            ]
        );
    }

    /**
     * @param array<string, mixed> $compatibility
     */
    public function emitWarning(array $compatibility, string $action): void
    {
        if (empty($compatibility['has_missing_hosts'])) {
            return;
        }

        $lines = [];
        foreach ((array) ($compatibility['missing_hosts'] ?? []) as $host) {
            $type = (string) ($host['type'] ?? 'hook');
            $name = (string) ($host['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $lines[] = sprintf('%s: %s', ucfirst($type), $name);
        }

        $content = (string) ($compatibility['warning_message'] ?? '');
        if ($lines) {
            $content .= PHP_EOL . implode(PHP_EOL, $lines);
        }

        w_msg(
            'weshop_theme_compatibility_warning',
            'warning',
            (string) __('Theme compatibility warning'),
            $content,
            [
                'source_module' => 'WeShop_Base',
                'metadata' => [
                    'action' => $action,
                    'theme_id' => (int) ($compatibility['theme_id'] ?? 0),
                    'theme_name' => (string) ($compatibility['theme_name'] ?? ''),
                    'area' => (string) ($compatibility['area'] ?? ''),
                    'scope' => (string) ($compatibility['scope'] ?? ''),
                    'layout_type' => (string) ($compatibility['layout_type'] ?? ''),
                    'layout_option' => (string) ($compatibility['layout_option'] ?? ''),
                    'layout_file' => (string) ($compatibility['layout_file'] ?? ''),
                    'layout_files' => (array) ($compatibility['layout_files'] ?? []),
                    'missing_hosts' => (array) ($compatibility['missing_hosts'] ?? []),
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $compatibility
     */
    public function injectPreviewBanner(string $html, array $compatibility): string
    {
        if ($html === '' || empty($compatibility['has_missing_hosts']) || str_contains($html, self::PREVIEW_BANNER_MARKER)) {
            return $html;
        }

        $banner = $this->buildPreviewBannerHtml($compatibility);
        if ($banner === '') {
            return $html;
        }

        if (preg_match('/<body[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $fullMatch = $matches[0][0];
            $offset = (int) $matches[0][1] + strlen($fullMatch);
            return substr($html, 0, $offset) . $banner . substr($html, $offset);
        }

        return $banner . $html;
    }

    /**
     * @param array<string, mixed> $compatibility
     * @return array<string, mixed>
     */
    public function buildPayload(array $compatibility, string $action): array
    {
        return [
            'action' => $action,
            'theme_id' => (int) ($compatibility['theme_id'] ?? 0),
            'theme_name' => (string) ($compatibility['theme_name'] ?? ''),
            'area' => (string) ($compatibility['area'] ?? ''),
            'scope' => (string) ($compatibility['scope'] ?? ''),
            'layout_type' => (string) ($compatibility['layout_type'] ?? ''),
            'layout_option' => (string) ($compatibility['layout_option'] ?? ''),
            'layout_file' => (string) ($compatibility['layout_file'] ?? ''),
            'layout_files' => (array) ($compatibility['layout_files'] ?? []),
            'has_missing_hosts' => !empty($compatibility['has_missing_hosts']),
            'missing_count' => (int) ($compatibility['missing_count'] ?? 0),
            'missing_hosts' => (array) ($compatibility['missing_hosts'] ?? []),
            'missing_modules' => (array) ($compatibility['missing_modules'] ?? []),
            'warning_message' => (string) ($compatibility['warning_message'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCompatibilityResult(
        ?WelineTheme $theme,
        string $area,
        string $layoutType,
        string $layoutOption,
        string $scope,
        ?string $layoutFile,
        array $data
    ): array {
        $layoutFiles = array_values(array_filter(array_map(
            static fn (mixed $file): string => is_string($file) ? $file : '',
            (array) ($data['layout_files'] ?? [])
        )));
        if ($layoutFile === null && $layoutFiles !== []) {
            $layoutFile = $layoutFiles[0];
        }

        $missingHosts = (array) ($data['missing_hosts'] ?? []);
        $missingModules = (array) ($data['missing_modules'] ?? []);
        $themeName = $theme && $theme->getId()
            ? (string) ($theme->getName() ?: ('#' . $theme->getId()))
            : '';

        return [
            'theme_id' => (int) ($theme?->getId() ?? 0),
            'theme_name' => $themeName,
            'area' => $area,
            'scope' => $scope,
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'layout_file' => $layoutFile,
            'layout_files' => $layoutFiles,
            'has_missing_hosts' => $missingHosts !== [],
            'missing_count' => count($missingHosts),
            'missing_hosts' => $missingHosts,
            'missing_modules' => $missingModules,
            'warning_message' => $missingHosts !== []
                ? (string) __('Theme compatibility warning: template %{layout_type}/%{layout_option} in theme %{theme} is missing %{count} required hook or slot hosts. Please extend the inherited layout instead of deleting default hooks or slots.', [
                    'layout_type' => $layoutType,
                    'layout_option' => $layoutOption,
                    'theme' => $themeName !== '' ? $themeName : '#0',
                    'count' => count($missingHosts),
                ])
                : '',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getLayoutManifest(string $area, string $layoutType): array
    {
        $layoutManifest = $this->getLayoutEntry($area, $layoutType);
        foreach (array_keys($layoutManifest) as $key) {
            if (is_string($key) && str_starts_with($key, '_')) {
                unset($layoutManifest[$key]);
            }
        }

        return $layoutManifest;
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));

        return $area === ThemeContextService::AREA_BACKEND
            ? ThemeContextService::AREA_BACKEND
            : ThemeContextService::AREA_FRONTEND;
    }

    private function resolveScope(string $area, string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '') {
            return ThemeContextService::DEFAULT_SCOPE;
        }

        if ($this->themeContextService === null) {
            if (str_contains($scope, '/')) {
                [, $scope] = explode('/', $scope, 2);
            }

            return trim($scope) !== '' ? trim($scope) : ThemeContextService::DEFAULT_SCOPE;
        }

        return $this->themeContextService->extractScopeForArea($area, $scope)
            ?? ThemeContextService::DEFAULT_SCOPE;
    }

    private function resolveTheme(int $themeId, string $area): ?WelineTheme
    {
        if ($themeId > 0) {
            $theme = clone $this->welineTheme;
            $theme->clearData()->clearQuery();
            $theme->load($themeId);

            return $theme->getId() ? $theme : null;
        }

        if ($this->themeContextService !== null) {
            return $this->themeContextService->resolveTheme($area);
        }

        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery();
        $theme->getActiveTheme($area);

        return $theme->getId() ? $theme : null;
    }

    private function resolveConfiguredLayoutOption(
        WelineTheme $theme,
        string $area,
        string $layoutType,
        string $scope
    ): string {
        try {
            ThemeData::setCurrentTheme($theme);
            ThemeData::setCurrentArea($area);
            $config = ThemeData::getLayoutConfig($area, $scope);
            $layoutOption = trim((string) ($config[$layoutType] ?? ''));
            return $layoutOption !== '' ? $layoutOption : 'default';
        } catch (\Throwable) {
            return 'default';
        } finally {
            ThemeData::clearCache();
        }
    }

    private function resolveLayoutFilePath(
        WelineTheme $theme,
        string $area,
        string $layoutType,
        string $layoutOption,
        array $templateDefinition = []
    ): ?string {
        $themeChain = method_exists($theme, 'getThemeChain') ? $theme->getThemeChain() : [$theme];
        if (!is_array($themeChain) || $themeChain === []) {
            $themeChain = [$theme];
        }

        $candidates = [];
        foreach (array_reverse($themeChain) as $chainTheme) {
            if (!$chainTheme instanceof WelineTheme) {
                continue;
            }

            $themePath = rtrim((string) $chainTheme->getPath(), DIRECTORY_SEPARATOR);
            if ($themePath === '') {
                continue;
            }

            $candidates = array_merge(
                $candidates,
                $this->buildLayoutCandidates($themePath, $area, $layoutType, $layoutOption, false, $templateDefinition)
            );
        }

        $frameworkLayoutBase = APP_CODE_PATH . 'Weline' . DIRECTORY_SEPARATOR . 'Theme';
        $candidates = array_merge(
            $candidates,
            $this->buildLayoutCandidates($frameworkLayoutBase, $area, $layoutType, $layoutOption, true, $templateDefinition)
        );

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            return realpath($candidate) ?: $candidate;
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function buildLayoutCandidates(
        string $basePath,
        string $area,
        string $layoutType,
        string $layoutOption,
        bool $moduleViewTheme = false,
        array $templateDefinition = []
    ): array {
        $kind = strtolower(trim((string) ($templateDefinition['kind'] ?? 'layout')));
        if ($kind === 'page') {
            return $this->buildPageCandidates(
                $basePath,
                $area,
                (string) ($templateDefinition['path'] ?? ''),
                $layoutType,
                $moduleViewTheme
            );
        }

        $ds = DIRECTORY_SEPARATOR;
        $base = rtrim($basePath, '/\\');
        $optionFile = $layoutOption . '.phtml';
        $layoutFile = $layoutType . '.phtml';

        if ($moduleViewTheme) {
            $candidates = [
                $base . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutType . $ds . $optionFile,
            ];
            if ($layoutOption === 'default') {
                $candidates[] = $base . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutFile;
            }

            return $candidates;
        }

        $candidates = [
            $base . $ds . $area . $ds . 'layouts' . $ds . $layoutType . $ds . $optionFile,
            $base . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutType . $ds . $optionFile,
            $base . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutType . $ds . $optionFile,
        ];

        if ($layoutOption === 'default') {
            $candidates[] = $base . $ds . $area . $ds . 'layouts' . $ds . $layoutFile;
            $candidates[] = $base . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutFile;
            $candidates[] = $base . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutFile;
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTemplateDefinitions(string $area, string $layoutType): array
    {
        $layoutManifest = $this->getLayoutEntry($area, $layoutType);
        $templateDefinitions = [];

        $multiTemplateDefinitions = $layoutManifest['_templates'] ?? [];
        if (is_array($multiTemplateDefinitions)) {
            $definitions = array_is_list($multiTemplateDefinitions)
                ? $multiTemplateDefinitions
                : [$multiTemplateDefinitions];

            foreach ($definitions as $templateDefinition) {
                if (!is_array($templateDefinition)) {
                    continue;
                }

                $templateDefinitions[] = $this->normalizeTemplateDefinition($templateDefinition);
            }
        }

        if ($templateDefinitions === []) {
            $templateDefinition = $layoutManifest['_template'] ?? [];
            if (!is_array($templateDefinition)) {
                $templateDefinition = [];
            }

            $templateDefinitions[] = $this->normalizeTemplateDefinition($templateDefinition);
        }

        return $templateDefinitions;
    }

    /**
     * @param array<string, mixed> $templateDefinition
     * @return array<string, mixed>
     */
    private function normalizeTemplateDefinition(array $templateDefinition): array
    {
        return [
            'kind' => strtolower(trim((string) ($templateDefinition['kind'] ?? 'layout'))) ?: 'layout',
            'path' => trim((string) ($templateDefinition['path'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getLayoutEntry(string $area, string $layoutType): array
    {
        $manifest = $this->manifestProvider->getManifest();
        $areaManifest = $manifest[$area] ?? [];
        $layoutManifest = $areaManifest[$layoutType] ?? [];

        return is_array($layoutManifest) ? $layoutManifest : [];
    }

    /**
     * @return string[]
     */
    private function buildPageCandidates(
        string $basePath,
        string $area,
        string $relativePath,
        string $layoutType,
        bool $moduleViewTheme = false
    ): array {
        $path = trim($relativePath);
        if ($path === '') {
            $path = $layoutType . '/index.phtml';
        }
        if (!str_ends_with(strtolower($path), '.phtml')) {
            $path .= '.phtml';
        }

        $ds = DIRECTORY_SEPARATOR;
        $base = rtrim($basePath, '/\\');
        $path = str_replace(['/', '\\'], $ds, $path);

        if ($moduleViewTheme) {
            return [
                $base . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'pages' . $ds . $path,
            ];
        }

        return [
            $base . $ds . $area . $ds . 'pages' . $ds . $path,
            $base . $ds . 'theme' . $ds . $area . $ds . 'pages' . $ds . $path,
            $base . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'pages' . $ds . $path,
        ];
    }

    /**
     * @param mixed $definition
     * @return array<string, mixed>|null
     */
    private function normalizeHostDefinition(mixed $definition): ?array
    {
        if (is_string($definition)) {
            $name = trim($definition);
            return $name !== '' ? ['type' => 'hook', 'name' => $name, 'required' => true] : null;
        }

        if (!is_array($definition)) {
            return null;
        }

        $name = trim((string) ($definition['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return [
            'type' => strtolower(trim((string) ($definition['type'] ?? 'hook'))) ?: 'hook',
            'name' => $name,
            'required' => !array_key_exists('required', $definition) || (bool) $definition['required'],
        ];
    }

    /**
     * @param array<string, mixed> $host
     */
    private function layoutContainsHost(string $layoutContent, array $host): bool
    {
        if ($layoutContent === '') {
            return false;
        }

        $name = preg_quote((string) $host['name'], '/');

        return match ($host['type']) {
            'slot' => preg_match('/<w:slot\b[^>]*\bname=(["\'])' . $name . '\1[^>]*>/u', $layoutContent) === 1
                || preg_match('/<w:slot(?:\s+[^>]*)?>\s*' . $name . '\s*<\/w:slot>/u', $layoutContent) === 1
                || preg_match('/\bdata-wslot=(["\'])' . $name . '\1/u', $layoutContent) === 1,
            default => preg_match('/<w:hook\b[^>]*\bname=(["\'])' . $name . '\1[^>]*>/u', $layoutContent) === 1
                || preg_match('/<w:hook(?:\s+[^>]*)?>\s*' . $name . '\s*<\/w:hook>/u', $layoutContent) === 1,
        };
    }

    /**
     * @param array<string, mixed> $compatibility
     */
    private function buildPreviewBannerHtml(array $compatibility): string
    {
        $warning = trim((string) ($compatibility['warning_message'] ?? ''));
        if ($warning === '') {
            return '';
        }

        $items = '';
        foreach ((array) ($compatibility['missing_hosts'] ?? []) as $host) {
            $type = ucfirst((string) ($host['type'] ?? 'hook'));
            $name = (string) ($host['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $items .= '<li style="margin:0 0 4px;">'
                . htmlspecialchars($type . ': ' . $name, ENT_QUOTES)
                . '</li>';
        }

        $listHtml = $items !== ''
            ? '<div style="margin-top:8px;"><strong>' . htmlspecialchars((string) __('Missing hosts'), ENT_QUOTES) . ':</strong><ul style="margin:8px 0 0 18px;padding:0;">' . $items . '</ul></div>'
            : '';

        return '<div class="' . self::PREVIEW_BANNER_MARKER . '" style="position:sticky;top:0;z-index:9999;padding:12px 16px;border-bottom:1px solid #f59e0b;background:#fff7ed;color:#7c2d12;font:14px/1.5 sans-serif;">'
            . '<strong>' . htmlspecialchars((string) __('Preview compatibility notice'), ENT_QUOTES) . '</strong>'
            . '<div style="margin-top:6px;">' . htmlspecialchars($warning, ENT_QUOTES) . '</div>'
            . $listHtml
            . '<div style="margin-top:8px;">' . htmlspecialchars((string) __('Please extend the inherited layout instead of deleting default hooks or slots.'), ENT_QUOTES) . '</div>'
            . '</div>';
    }

    protected function isModuleEnabled(string $module): bool
    {
        return (bool) Env::getInstance()->getModuleStatus($module);
    }
}
