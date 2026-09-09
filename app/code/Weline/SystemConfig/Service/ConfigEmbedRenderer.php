<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Data\DataInterface;
use Weline\Framework\View\Template;

/**
 * Render <w:config:embed> HTML from a resolved view model.
 */
final class ConfigEmbedRenderer
{
    public function __construct(
        private readonly ConfigEmbedResolver $resolver,
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function renderFromAttributes(array $attributes): string
    {
        return $this->render($this->resolver->resolve($attributes));
    }

    /**
     * @param array<string, mixed> $view
     */
    public function render(array $view): string
    {
        $custom = trim((string)($view['template'] ?? ''));
        $fetchSource = $this->resolveFetchSource($custom);
        if ($fetchSource === null) {
            $message = $custom !== ''
                ? (string)__('配置嵌入自定义模板不存在：%{1}', [$custom])
                : (string)__('配置嵌入默认模板缺失。');

            return '<div class="w-config-embed is-error" data-w-config-embed data-testid="config-embed-error">'
                . '<div class="w-alert" data-tone="danger">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>'
                . '</div>';
        }

        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        return (string)$template->fetchHtml($fetchSource, [
            'embed' => $view,
            'embedCssUrl' => $this->resolveModuleStaticUrl('Weline_SystemConfig::css/config-embed.css'),
            'embedJsUrl' => $this->resolveModuleStaticUrl('Weline_SystemConfig::js/config-embed.js'),
            'embedFieldSource' => 'Weline_SystemConfig::templates/taglib/config-embed-field.phtml',
        ]);
    }

    private function resolveModuleStaticUrl(string $source): string
    {
        try {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);

            return (string)$template->fetchTagSource(DataInterface::dir_type_STATICS, $source);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Resolve a Template::fetchHtml source so nested Taglibs compile.
     */
    private function resolveFetchSource(string $custom): ?string
    {
        if ($custom === '') {
            $default = $this->moduleViewPath('templates/taglib/config-embed.phtml');

            return is_file($default) ? 'Weline_SystemConfig::templates/taglib/config-embed.phtml' : null;
        }

        if (str_contains($custom, '::')) {
            [$module, $rel] = explode('::', $custom, 2);
            $module = trim($module);
            $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
            if ($module === '' || $rel === '' || str_contains($rel, '..')) {
                return null;
            }
            $base = $this->moduleRoot($module);
            if ($base === null || !is_file($base . '/view/' . $rel)) {
                return null;
            }

            return $module . '::' . $rel;
        }

        $rel = ltrim(str_replace('\\', '/', $custom), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        if (is_file($this->moduleViewPath($rel))) {
            return 'Weline_SystemConfig::' . $rel;
        }
        if (is_file(BP . '/' . $rel)) {
            // Absolute-repo relative files cannot go through module Taglib compile; keep null
            // so callers get a clear missing-template error unless they use Vendor_Module::path.
            return null;
        }

        return null;
    }

    private function moduleViewPath(string $relative): string
    {
        return dirname(__DIR__) . '/view/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    private function moduleRoot(string $moduleName): ?string
    {
        $moduleName = trim($moduleName);
        if ($moduleName === '' || !str_contains($moduleName, '_')) {
            return null;
        }
        [$vendor, $module] = explode('_', $moduleName, 2);
        $path = BP . '/app/code/' . $vendor . '/' . $module;
        if (!is_dir($path)) {
            return null;
        }

        return $path;
    }
}
