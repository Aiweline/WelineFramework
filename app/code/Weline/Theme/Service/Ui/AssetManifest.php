<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ui;

final class AssetManifest
{
    public const SCHEMA_VERSION = 'weline-ui-assets.v1';

    private ?array $data = null;

    public function __construct(private readonly ?string $manifestPath = null)
    {
    }

    public function path(): string
    {
        return $this->manifestPath
            ?? BP . 'app/code/Weline/Theme/etc/weline-ui-assets.json';
    }

    public function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $path = $this->path();
        $json = @file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException(__('无法读取 Weline UI 资源清单：%{1}', [$path]));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(
                __('Weline UI 资源清单不是有效 JSON：%{1}', [$exception->getMessage()]),
                0,
                $exception,
            );
        }

        if (!is_array($data) || ($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException(__('Weline UI 资源清单版本无效'));
        }
        if (!is_array($data['bundles'] ?? null) || $data['bundles'] === []) {
            throw new \RuntimeException(__('Weline UI 资源清单未声明 bundle'));
        }
        $this->validateReferences($data);

        $this->data = $data;
        return $this->data;
    }

    public function moduleRoot(): string
    {
        return BP . 'app/code/Weline/Theme/';
    }

    public function sourceRoot(): string
    {
        return $this->moduleRoot() . trim((string)($this->load()['source_root'] ?? ''), '/') . '/';
    }

    /**
     * Resolve a bundle-owned source directory.
     *
     * Theme bundles inherit the manifest source root. Cross-module component
     * bundles use an explicit project-relative root so their source remains
     * owned by the module that implements the component.
     */
    public function bundleSourceRoot(array $bundle): string
    {
        $sourceRoot = trim((string)($bundle['source_root'] ?? ''), '/');
        return $sourceRoot === '' ? $this->sourceRoot() : BP . $sourceRoot . '/';
    }

    public function outputRoot(): string
    {
        return $this->moduleRoot() . trim((string)($this->load()['output_root'] ?? ''), '/') . '/';
    }

    public function bundles(): array
    {
        return $this->load()['bundles'];
    }

    public function areas(): array
    {
        return is_array($this->load()['areas'] ?? null) ? $this->load()['areas'] : [];
    }

    public function budgets(): array
    {
        return is_array($this->load()['budgets'] ?? null) ? $this->load()['budgets'] : [];
    }

    public function lazyComponents(): array
    {
        return is_array($this->load()['lazy_components'] ?? null)
            ? $this->load()['lazy_components']
            : [];
    }

    public function componentAssets(): array
    {
        return is_array($this->load()['component_assets'] ?? null)
            ? $this->load()['component_assets']
            : [];
    }

    public function routes(): array
    {
        return is_array($this->load()['routes'] ?? null) ? $this->load()['routes'] : [];
    }

    public function specializedEngines(): array
    {
        return is_array($this->load()['specialized_engines'] ?? null)
            ? $this->load()['specialized_engines']
            : [];
    }

    public function auditExcludes(): array
    {
        return array_values(array_filter(
            is_array($this->load()['audit_excludes'] ?? null) ? $this->load()['audit_excludes'] : [],
            'is_string',
        ));
    }

    private function validateReferences(array $data): void
    {
        $bundles = $data['bundles'];
        foreach ($bundles as $name => $bundle) {
            if (!is_string($name)
                || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1
                || !is_array($bundle)) {
                throw new \RuntimeException(__('Weline UI bundle 声明无效：%{1}', [(string)$name]));
            }
            $sourceRoot = trim((string)($bundle['source_root'] ?? ''), '/');
            if ($sourceRoot !== '' && !$this->isOwnedProjectPath($sourceRoot)) {
                throw new \RuntimeException(__('Weline UI bundle 源目录越过模块边界：%{1}', [$name]));
            }
        }

        foreach ((array)($data['areas'] ?? []) as $area => $names) {
            if (!is_string($area) || !is_array($names) || $names === []) {
                throw new \RuntimeException(__('Weline UI 区域资源声明无效：%{1}', [(string)$area]));
            }
            foreach ($names as $name) {
                if (!is_string($name) || !isset($bundles[$name])) {
                    throw new \RuntimeException(__('Weline UI 区域引用了未定义 bundle：%{1}', [(string)$name]));
                }
            }
        }

        foreach ((array)($data['lazy_components'] ?? []) as $bundle => $components) {
            if (!is_string($bundle) || !isset($bundles[$bundle]) || !is_array($components) || $components === []) {
                throw new \RuntimeException(__('Weline UI 懒加载组件声明无效：%{1}', [(string)$bundle]));
            }
            foreach ($components as $component) {
                if (!is_string($component) || preg_match('/^[a-z][a-z0-9-]*$/', $component) !== 1) {
                    throw new \RuntimeException(__('Weline UI 懒加载组件名无效：%{1}', [(string)$component]));
                }
            }
        }

        foreach ((array)($data['component_assets'] ?? []) as $name => $componentAsset) {
            if (!is_string($name)
                || preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1
                || !is_array($componentAsset)) {
                throw new \RuntimeException(__('Weline UI 组件资源声明无效：%{1}', [(string)$name]));
            }
            $owner = (string)($componentAsset['owner'] ?? '');
            $assetBundles = $componentAsset['bundles'] ?? null;
            $components = $componentAsset['components'] ?? null;
            if (preg_match('/^Weline_[A-Za-z0-9]+$/', $owner) !== 1
                || !is_array($assetBundles) || $assetBundles === []
                || !is_array($components) || $components === []) {
                throw new \RuntimeException(__('Weline UI 组件资源缺少 owner、bundles 或 components：%{1}', [$name]));
            }
            $ownerPrefix = 'app/code/Weline/' . substr($owner, strlen('Weline_')) . '/';
            foreach ($assetBundles as $bundleName) {
                if (!is_string($bundleName) || !isset($bundles[$bundleName])) {
                    throw new \RuntimeException(__('Weline UI 组件资源引用了未定义 bundle：%{1}', [(string)$bundleName]));
                }
                $bundleRoot = trim((string)($bundles[$bundleName]['source_root'] ?? ''), '/');
                if ($bundleRoot !== '' && !str_starts_with($bundleRoot . '/', $ownerPrefix)) {
                    throw new \RuntimeException(__('Weline UI 组件 bundle 不属于声明模块：%{1}', [$bundleName]));
                }
            }
            foreach ($components as $component) {
                if (!is_string($component) || preg_match('/^[a-z][a-z0-9-]*$/', $component) !== 1) {
                    throw new \RuntimeException(__('Weline UI 组件资源名无效：%{1}', [(string)$component]));
                }
            }
        }

        $engines = (array)($data['specialized_engines'] ?? []);
        foreach ($engines as $name => $engine) {
            if (!is_string($name) || !is_array($engine)) {
                throw new \RuntimeException(__('Weline UI 专用引擎声明无效：%{1}', [(string)$name]));
            }
            $owner = (string)($engine['owner'] ?? '');
            $paths = $engine['paths'] ?? null;
            $consumers = $engine['consumers'] ?? null;
            if (preg_match('/^Weline_[A-Za-z0-9]+$/', $owner) !== 1
                || !is_array($paths) || $paths === []
                || !is_array($consumers) || $consumers === []) {
                throw new \RuntimeException(__('Weline UI 专用引擎缺少 owner、paths 或 consumers：%{1}', [$name]));
            }
            $module = substr($owner, strlen('Weline_'));
            $ownerPrefix = 'app/code/Weline/' . $module . '/';
            foreach (array_merge($paths, $consumers) as $path) {
                if (!is_string($path) || !str_starts_with($path, $ownerPrefix) || str_contains($path, '..')) {
                    throw new \RuntimeException(__('Weline UI 专用引擎越过模块归属边界：%{1}', [$name]));
                }
            }
        }

        foreach ((array)($data['routes'] ?? []) as $route => $config) {
            if (!is_string($route) || !is_array($config) || !isset(($data['areas'] ?? [])[$config['area'] ?? ''])) {
                throw new \RuntimeException(__('Weline UI 路由资源声明无效：%{1}', [(string)$route]));
            }
            foreach ((array)($config['engines'] ?? []) as $engine) {
                if (!is_string($engine) || !isset($engines[$engine])) {
                    throw new \RuntimeException(__('Weline UI 路由引用了未定义专用引擎：%{1}', [(string)$engine]));
                }
            }
            foreach ((array)($config['bundles'] ?? []) as $bundle) {
                if (!is_string($bundle) || !isset($bundles[$bundle])) {
                    throw new \RuntimeException(__('Weline UI 路由引用了未定义 bundle：%{1}', [(string)$bundle]));
                }
            }
            foreach ((array)($config['templates'] ?? []) as $template) {
                if (!is_string($template) || $template === '' || str_contains($template, '..')) {
                    throw new \RuntimeException(__('Weline UI 路由模板路径无效：%{1}', [(string)$template]));
                }
            }
        }
    }

    private function isOwnedProjectPath(string $path): bool
    {
        return str_starts_with($path, 'app/code/Weline/')
            && !str_contains($path, '..')
            && preg_match('#^app/code/Weline/[A-Za-z0-9]+(?:/[A-Za-z0-9._-]+)*/?$#', $path) === 1;
    }
}
