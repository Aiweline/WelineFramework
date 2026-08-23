<?php

declare(strict_types=1);

namespace Weline\DataTable\Helper;

use Weline\Framework\View\Template;

/**
 * Emits the DataTable route component assets once per request.
 *
 * The request resetter clears this state for long-running WLS workers.
 */
final class UiAssets
{
    /** @var array<string, true> */
    private static array $rendered = [];

    /** @var array<string,string>|null */
    private static ?array $compiledVersions = null;

    /**
     * @param list<'data-table'|'data-table-form'> $components
     */
    public static function render(
        Template $template,
        array $components = ['data-table', 'data-table-form']
    ): string
    {
        $requested = array_fill_keys($components, true);
        $bundles = ['datatable-css'];
        if (isset($requested['data-table'])) {
            $bundles[] = 'datatable-js';
        }
        if (isset($requested['data-table-form'])) {
            $bundles[] = 'datatable-form-js';
        }

        return self::renderBundles($template, $bundles);
    }

    /**
     * Render explicitly declared compiled bundles once per request.
     *
     * @param list<string> $bundleNames
     */
    public static function renderBundles(Template $template, array $bundleNames): string
    {
        $manifest = self::compiledManifest();
        $html = '';
        foreach ($bundleNames as $bundleName) {
            $bundleName = (string)$bundleName;
            $renderKey = 'bundle:' . $bundleName;
            if ($bundleName === '' || isset(self::$rendered[$renderKey])) {
                continue;
            }
            $bundle = $manifest['bundles'][$bundleName] ?? null;
            $file = is_array($bundle) ? (string)($bundle['file'] ?? '') : '';
            $type = is_array($bundle) ? (string)($bundle['type'] ?? '') : '';
            if ($file === '' || !in_array($type, ['css', 'js'], true)) {
                throw new \RuntimeException(__('Weline UI 编译 bundle 不可用：%{1}', [$bundleName]));
            }

            self::$rendered[$renderKey] = true;
            $url = self::url($template, 'Weline_Theme::ui/' . $file);
            $asset = htmlspecialchars($bundleName, ENT_QUOTES, 'UTF-8');
            $html .= $type === 'css'
                ? '<link rel="stylesheet" href="' . $url . '" data-w-asset="' . $asset . '">'
                : '<script type="module" src="' . $url . '" data-w-asset="' . $asset . '"></script>';
        }

        return $html;
    }

    public static function resetRequestState(): void
    {
        self::$rendered = [];
        self::$compiledVersions = null;
    }

    private static function url(Template $template, string $source): string
    {
        $url = $template->fetchTagSource('statics', $source);
        $relative = str_starts_with($source, 'Weline_Theme::ui/')
            ? substr($source, strlen('Weline_Theme::ui/'))
            : '';
        $version = $relative !== '' ? (self::compiledVersions()[$relative] ?? '') : '';
        if ($version !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
        }

        return htmlspecialchars(
            $url,
            ENT_QUOTES,
            'UTF-8'
        );
    }

    /** @return array<string,string> */
    private static function compiledVersions(): array
    {
        if (self::$compiledVersions !== null) {
            return self::$compiledVersions;
        }

        self::$compiledVersions = [];
        foreach ((array)(self::compiledManifest()['bundles'] ?? []) as $bundle) {
            $file = is_array($bundle) ? (string)($bundle['file'] ?? '') : '';
            $sha256 = is_array($bundle) ? (string)($bundle['sha256'] ?? '') : '';
            if ($file !== '' && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1) {
                self::$compiledVersions[$file] = substr($sha256, 0, 12);
            }
        }

        return self::$compiledVersions;
    }

    /** @return array<string,mixed> */
    private static function compiledManifest(): array
    {
        $path = BP . 'app/code/Weline/Theme/view/statics/ui/manifest.json';
        $json = @file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException(__('Weline UI 编译清单不存在，请先运行 resource:compile welineUi'));
        }
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(__('Weline UI 编译清单无效：%{1}', [$exception->getMessage()]), 0, $exception);
        }

        return is_array($manifest) ? $manifest : [];
    }
}
