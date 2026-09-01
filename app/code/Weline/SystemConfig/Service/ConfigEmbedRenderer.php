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
        $templateFile = $this->resolveTemplateFile($custom);
        if ($templateFile === null) {
            $message = $custom !== ''
                ? (string)__('配置嵌入自定义模板不存在：%{1}', [$custom])
                : (string)__('配置嵌入默认模板缺失。');

            return '<div class="w-config-embed is-error" data-w-config-embed data-testid="config-embed-error">'
                . '<div class="w-alert" data-tone="danger">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>'
                . '</div>';
        }

        $fieldPartial = $this->moduleViewPath('templates/taglib/config-embed-field.phtml');
        $embedCssUrl = $this->resolveModuleStaticUrl('Weline_SystemConfig::css/config-embed.css');
        $embedJsUrl = $this->resolveModuleStaticUrl('Weline_SystemConfig::js/config-embed.js');
        ob_start();
        $embed = $view;
        $embedFieldPartial = $fieldPartial;
        include $templateFile;

        return (string)ob_get_clean();
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

    private function resolveTemplateFile(string $custom): ?string
    {
        if ($custom === '') {
            $default = $this->moduleViewPath('templates/taglib/config-embed.phtml');

            return is_file($default) ? $default : null;
        }

        $candidates = [];
        if (str_contains($custom, '::')) {
            [$module, $rel] = explode('::', $custom, 2);
            $module = trim($module);
            $rel = ltrim(str_replace('\\', '/', trim($rel)), '/');
            if ($module !== '' && $rel !== '' && !str_contains($rel, '..')) {
                $base = $this->moduleRoot($module);
                if ($base !== null) {
                    $candidates[] = $base . '/view/' . $rel;
                }
            }
        } else {
            $rel = ltrim(str_replace('\\', '/', $custom), '/');
            if ($rel !== '' && !str_contains($rel, '..')) {
                $candidates[] = $this->moduleViewPath($rel);
                $candidates[] = BP . '/' . $rel;
            }
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
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
