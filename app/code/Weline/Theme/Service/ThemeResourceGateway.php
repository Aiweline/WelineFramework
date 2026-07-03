<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;
use Weline\Theme\Model\WelineTheme;

final class ThemeResourceGateway
{
    public function __construct(
        private readonly ThemeDirectoryResolver $directoryResolver,
        private readonly ThemeContextService $themeContext,
        private readonly ThemeStaticNamespaceService $themeStaticNamespaceService,
        private readonly Request $request,
    ) {
    }

    public function publishForRequestPath(string $requestPath, ?WelineTheme $theme = null): ?string
    {
        $requestPath = $this->normalizeRequestPath($requestPath);
        if ($requestPath === '') {
            return null;
        }

        $resource = $this->parseThemeRequestPath($requestPath);
        if ($resource === null) {
            return null;
        }

        $relativePath = $resource['relative_path'];
        if ($relativePath === '' || $this->containsParentTraversal($relativePath)) {
            return null;
        }

        $moduleBasePath = $this->resolveModuleBasePath($resource['vendor'], $resource['module']);
        if ($moduleBasePath === '') {
            return null;
        }

        if (($resource['kind'] ?? '') === 'statics') {
            return $this->publishModuleStaticResource($resource, $relativePath, $moduleBasePath, $theme);
        }
        if (($resource['kind'] ?? '') === 'flat_statics') {
            return $this->publishFlatModuleStaticResource($resource, $relativePath, $moduleBasePath);
        }

        $moduleThemeFile = rtrim($moduleBasePath, '\\/') . DIRECTORY_SEPARATOR
            . 'view' . DIRECTORY_SEPARATOR
            . 'theme' . DIRECTORY_SEPARATOR
            . $resource['area'] . DIRECTORY_SEPARATOR
            . $relativePath;

        $resolvedTheme = $this->resolveTheme($resource['area'], $theme);
        if (!$resolvedTheme || !$resolvedTheme->getId()) {
            return null;
        }

        $resolvedFile = $this->directoryResolver->resolveThemeTemplatePath($moduleThemeFile, $resolvedTheme);
        if (!is_file($resolvedFile)) {
            return null;
        }

        $publicThemePath = $resource['public_theme_path']
            ?: $this->themeStaticNamespaceService->resolvePublicThemePath($resolvedTheme);
        if ($publicThemePath === '') {
            return null;
        }

        $publicRelativePath = '/pub/static/'
            . $this->buildThemeModulePublicPath(
                $publicThemePath,
                $resource['vendor'],
                $resource['module']
            )
            . '/'
            . $resource['area']
            . '/'
            . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        $publishedFile = rtrim(BP, '\\/') . DIRECTORY_SEPARATOR
            . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $publicRelativePath), '\\/');
        if (!$this->publishResolvedFile($resolvedFile, $publishedFile)) {
            return null;
        }

        return str_replace('\\', '/', $publicRelativePath);
    }

    private function publishFlatModuleStaticResource(array $resource, string $relativePath, string $moduleBasePath): ?string
    {
        $sourceFile = rtrim($moduleBasePath, '\\/') . DIRECTORY_SEPARATOR
            . 'view' . DIRECTORY_SEPARATOR
            . 'statics' . DIRECTORY_SEPARATOR
            . $relativePath;
        if (!is_file($sourceFile)) {
            return null;
        }

        $publicRelativePath = '/pub/static/'
            . $resource['vendor']
            . '/'
            . $resource['module']
            . '/'
            . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        $publishedFile = rtrim(BP, '\\/') . DIRECTORY_SEPARATOR
            . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $publicRelativePath), '\\/');
        if (!$this->publishResolvedFile($sourceFile, $publishedFile)) {
            return null;
        }

        return str_replace('\\', '/', $publicRelativePath);
    }

    private function publishModuleStaticResource(array $resource, string $relativePath, string $moduleBasePath, ?WelineTheme $theme = null): ?string
    {
        $sourceFile = rtrim($moduleBasePath, '\\/') . DIRECTORY_SEPARATOR
            . 'view' . DIRECTORY_SEPARATOR
            . 'statics' . DIRECTORY_SEPARATOR
            . $relativePath;
        if (!is_file($sourceFile)) {
            return null;
        }

        $publicThemePath = $resource['public_theme_path'] ?: $this->resolvePublicThemePathForStaticRequest($theme);
        if ($publicThemePath === '') {
            return null;
        }

        $publicRelativePath = '/pub/static/'
            . trim(str_replace('\\', '/', $publicThemePath), '/')
            . '/'
            . $resource['vendor']
            . '/'
            . $resource['module']
            . '/view/statics/'
            . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        $publishedFile = rtrim(BP, '\\/') . DIRECTORY_SEPARATOR
            . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $publicRelativePath), '\\/');
        if (!$this->publishResolvedFile($sourceFile, $publishedFile)) {
            return null;
        }

        return str_replace('\\', '/', $publicRelativePath);
    }

    public function buildThemeAssetUrl(
        string $moduleName,
        string $area,
        string $relativePath,
        ?WelineTheme $theme = null,
        bool $absolute = false,
    ): string {
        $module = $this->splitModuleName($moduleName);
        if ($module === null) {
            return '';
        }

        $area = $this->themeContext->normalizeArea($area);
        $relativePath = ltrim(str_replace(['\\', '/'], '/', $relativePath), '/');
        if ($relativePath === '') {
            return '';
        }

        if (defined('PROD') && PROD) {
            $resolvedTheme = $this->resolveTheme($area, $theme);
            if (!$resolvedTheme || !$resolvedTheme->getId()) {
                return '';
            }

            $publicThemePath = $this->themeStaticNamespaceService->resolvePublicThemePath($resolvedTheme);
            if ($publicThemePath === '') {
                return '';
            }

            return $this->buildStaticUrl(
                $this->buildThemeModulePublicPath(
                    $publicThemePath,
                    $module['vendor'],
                    $module['module']
                )
                . '/'
                . $area
                . '/'
                . $relativePath,
                $absolute
            );
        }

        $url = '/'
            . $module['vendor']
            . '/'
            . $module['module']
            . '/view/theme/'
            . $area
            . '/'
            . $relativePath;

        if ($absolute) {
            $url = $this->buildAbsoluteUrl($url);
        }

        return $this->themeStaticNamespaceService->appendPreviewContextQuery($url);
    }

    public function buildLayoutAssetDiskPath(
        string $area,
        string $layoutType,
        string $layoutOption,
        string $extension,
        ?WelineTheme $theme = null,
    ): string {
        $relative = $this->buildLayoutAssetPublicRelativePath($area, $layoutType, $layoutOption, $extension, $theme);
        if ($relative === '') {
            return '';
        }

        return rtrim(BP, '\\/')
            . DIRECTORY_SEPARATOR
            . 'pub'
            . DIRECTORY_SEPARATOR
            . 'static'
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function buildLayoutAssetUrl(
        string $area,
        string $layoutType,
        string $layoutOption,
        string $extension,
        ?WelineTheme $theme = null,
        bool $absolute = false,
    ): string {
        $relative = $this->buildLayoutAssetPublicRelativePath($area, $layoutType, $layoutOption, $extension, $theme);
        if ($relative === '') {
            return '';
        }

        return $this->buildStaticUrl($relative, $absolute);
    }

    public function buildLayoutAssetPublicRelativePath(
        string $area,
        string $layoutType,
        string $layoutOption,
        string $extension,
        ?WelineTheme $theme = null,
    ): string {
        $area = $this->themeContext->normalizeArea($area);
        $layoutType = $this->normalizeLayoutType($layoutType);
        $layoutOption = $this->normalizeLayoutOption($layoutOption);
        $extension = ltrim(trim($extension), '.');
        if ($layoutType === '' || $layoutOption === '' || $extension === '') {
            return '';
        }

        $resolvedTheme = $this->resolveTheme($area, $theme);
        if (!$resolvedTheme || !$resolvedTheme->getId()) {
            return '';
        }

        $publicThemePath = $this->themeStaticNamespaceService->resolvePublicThemePath($resolvedTheme);
        if ($publicThemePath === '') {
            return '';
        }

        return $this->buildThemeModulePublicPath($publicThemePath, 'Weline', 'Theme')
            . '/'
            . $area
            . '/layouts/'
            . str_replace(['\\', '/'], '/', $layoutType)
            . '/'
            . $layoutOption
            . '.'
            . $extension;
    }

    private function normalizeLayoutType(string $layoutType): string
    {
        $normalized = str_replace('\\', '/', trim($layoutType));
        if ($normalized === '') {
            return '';
        }

        $layoutsNeedle = '/layouts/';
        $layoutsPos = strripos($normalized, $layoutsNeedle);
        if ($layoutsPos !== false) {
            $normalized = substr($normalized, $layoutsPos + strlen($layoutsNeedle));
        }

        $segments = array_values(array_filter(
            explode('/', trim($normalized, '/')),
            static fn(string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..'
        ));

        if ($segments === []) {
            return '';
        }

        // 布局选项应位于最后一级，布局类型只保留前置目录。
        if (count($segments) > 1) {
            array_pop($segments);
        }

        return implode('/', $segments);
    }

    private function normalizeLayoutOption(string $layoutOption): string
    {
        $normalized = str_replace('\\', '/', trim($layoutOption));
        if ($normalized === '') {
            return '';
        }

        $basename = basename($normalized);
        $dotPos = strrpos($basename, '.');
        if ($dotPos !== false) {
            $basename = substr($basename, 0, $dotPos);
        }

        $basename = trim($basename);
        if ($basename === '' || $basename === '.' || $basename === '..') {
            return '';
        }

        return $basename;
    }

    private function buildStaticUrl(string $publicRelativePath, bool $absolute): string
    {
        $url = '/static/' . ltrim(str_replace('\\', '/', $publicRelativePath), '/');
        if ($absolute) {
            $url = $this->buildAbsoluteUrl($url);
        }

        return $this->themeStaticNamespaceService->appendPreviewContextQuery($url);
    }

    private function buildThemeModulePublicPath(string $publicThemePath, string $vendor, string $module): string
    {
        $basePath = trim(str_replace('\\', '/', $publicThemePath), '/');
        $moduleThemePath = $vendor . '/' . $module . '/view/theme';
        if ($basePath === '' || $basePath === $moduleThemePath || str_ends_with($basePath, '/' . $moduleThemePath)) {
            return $basePath !== '' ? $basePath : $moduleThemePath;
        }

        return $basePath . '/' . $moduleThemePath;
    }

    private function buildAbsoluteUrl(string $path): string
    {
        $scheme = $this->request->getServer('REQUEST_SCHEME')
            ?: (($this->request->getServer('HTTPS') ?? '') === 'on' ? 'https' : 'http');
        $host = (string)($this->request->getServer('HTTP_HOST') ?: 'localhost');
        $scriptName = (string)($this->request->getServer('SCRIPT_NAME') ?: '/');
        $scriptDir = dirname($scriptName);
        if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
            $scriptDir = '';
        }

        return rtrim($scheme . '://' . $host . $scriptDir, '/') . '/' . ltrim($path, '/');
    }

    private function parseThemeRequestPath(string $requestPath): ?array
    {
        if (preg_match('#^/([^/]+)/([^/]+)/view/theme/(frontend|backend)/(.+)$#i', $requestPath, $matches)) {
            return [
                'kind' => 'theme',
                'public_theme_path' => '',
                'vendor' => (string)$matches[1],
                'module' => (string)$matches[2],
                'area' => strtolower((string)$matches[3]) === 'backend' ? 'backend' : 'frontend',
                'relative_path' => ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$matches[4]), DIRECTORY_SEPARATOR),
            ];
        }

        if (preg_match('#^/(?:pub/)?static/(.+)/([^/]+)/([^/]+)/view/theme/(frontend|backend)/(.+)$#i', $requestPath, $matches)) {
            return [
                'kind' => 'theme',
                'public_theme_path' => trim(str_replace('\\', '/', (string)$matches[1]), '/'),
                'vendor' => (string)$matches[2],
                'module' => (string)$matches[3],
                'area' => strtolower((string)$matches[4]) === 'backend' ? 'backend' : 'frontend',
                'relative_path' => ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$matches[5]), DIRECTORY_SEPARATOR),
            ];
        }

        if (preg_match('#^/([^/]+)/([^/]+)/view/statics/(.+)$#i', $requestPath, $matches)) {
            return [
                'kind' => 'statics',
                'public_theme_path' => '',
                'vendor' => (string)$matches[1],
                'module' => (string)$matches[2],
                'area' => '',
                'relative_path' => ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$matches[3]), DIRECTORY_SEPARATOR),
            ];
        }

        if (preg_match('#^/(?:pub/)?static/(.+)/([^/]+)/([^/]+)/view/statics/(.+)$#i', $requestPath, $matches)) {
            return [
                'kind' => 'statics',
                'public_theme_path' => trim(str_replace('\\', '/', (string)$matches[1]), '/'),
                'vendor' => (string)$matches[2],
                'module' => (string)$matches[3],
                'area' => '',
                'relative_path' => ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$matches[4]), DIRECTORY_SEPARATOR),
            ];
        }

        if (preg_match('#^/(?:pub/)?static/([^/]+)/([^/]+)/(?!view/)(.+)$#i', $requestPath, $matches)) {
            return [
                'kind' => 'flat_statics',
                'public_theme_path' => '',
                'vendor' => (string)$matches[1],
                'module' => (string)$matches[2],
                'area' => '',
                'relative_path' => ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$matches[3]), DIRECTORY_SEPARATOR),
            ];
        }

        return null;
    }

    private function normalizeRequestPath(string $requestPath): string
    {
        $path = parse_url($requestPath, PHP_URL_PATH);
        if (!is_string($path)) {
            return '';
        }

        return '/' . ltrim(str_replace('\\', '/', $path), '/');
    }

    private function containsParentTraversal(string $relativePath): bool
    {
        return (bool)preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $relativePath);
    }

    private function resolveModuleBasePath(string $vendor, string $module): string
    {
        $moduleName = $vendor . '_' . $module;
        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
        $moduleInfo = $modules[$moduleName] ?? null;
        if (is_array($moduleInfo) && !empty($moduleInfo['base_path'])) {
            return (string)$moduleInfo['base_path'];
        }

        foreach ($modules as $registeredName => $registeredInfo) {
            if (strcasecmp((string)$registeredName, $moduleName) === 0 && !empty($registeredInfo['base_path'])) {
                return (string)$registeredInfo['base_path'];
            }
        }

        return '';
    }

    private function publishResolvedFile(string $sourceFile, string $targetFile): bool
    {
        if (is_file($targetFile) && filemtime($targetFile) >= filemtime($sourceFile)) {
            return true;
        }

        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return false;
        }

        return copy($sourceFile, $targetFile);
    }

    private function resolvePublicThemePathForStaticRequest(?WelineTheme $theme = null): string
    {
        $resolvedTheme = $theme ?: $this->resolveTheme('frontend');
        if ($resolvedTheme && $resolvedTheme->getId()) {
            return $this->themeStaticNamespaceService->resolvePublicThemePath($resolvedTheme);
        }

        return '';
    }

    private function resolveTheme(string $area, ?WelineTheme $theme = null): ?WelineTheme
    {
        if ($theme && $theme->getId()) {
            return $theme;
        }

        return $this->themeContext->resolveTheme($area);
    }

    private function splitModuleName(string $moduleName): ?array
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '' || !str_contains($moduleName, '_')) {
            return null;
        }

        [$vendor, $module] = explode('_', $moduleName, 2);
        if ($vendor === '' || $module === '') {
            return null;
        }

        return [
            'vendor' => $vendor,
            'module' => $module,
        ];
    }
}
