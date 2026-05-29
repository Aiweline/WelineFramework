<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

class ThemeComponentRenderer
{
    public function __construct(
        private readonly Template $template,
        private readonly RuntimeTemplateMaterializer $runtimeTemplateMaterializer,
        private readonly ThemeRenderableResolver $renderableResolver,
    ) {
    }

    public function render(ThemeComponentDefinition $definition, array $instanceConfig = [], ?WelineTheme $theme = null, array $context = []): string
    {
        $area = $definition->area ?: ((string)($context['area'] ?? 'frontend'));
        $config = $this->mergeConfig($definition, $instanceConfig, $theme, $area);
        $config = $this->exposeThemeComponentConfigAsMeta($definition, $config);
        if (!empty($context['preview_mode'])) {
            $config['preview_mode'] = true;
        }
        $config['theme_component'] = $definition->toArray();
        $config['theme_component_meta'] = $definition->meta;

        $renderable = $this->renderableResolver->resolve($definition, $config);

        if ($renderable->isTemplateContent()) {
            return $this->runtimeTemplateMaterializer->renderContent((string)$renderable->templateContent, $config);
        }

        if ($renderable->isBlockClass()) {
            return $this->renderBlock($renderable->blockClass, $config);
        }

        $templatePath = (string)$renderable->templatePath;
        if ($templatePath === '') {
            return '';
        }

        if (is_file($templatePath)) {
            return $this->runtimeTemplateMaterializer->renderFile($templatePath, $config);
        }

        $this->template->unsetData();
        $html = $this->template->fetchHtml($templatePath, $config);

        return is_string($html) ? $html : '';
    }

    public function mergeConfig(ThemeComponentDefinition $definition, array $instanceConfig = [], ?WelineTheme $theme = null, string $area = 'frontend'): array
    {
        $config = array_merge(
            $this->extractParamDefaults($definition->params),
            $definition->defaultConfig,
            $this->resolveThemeDefaults($definition, $theme, $area),
            $instanceConfig
        );

        return $config;
    }

    private function resolveThemeDefaults(ThemeComponentDefinition $definition, ?WelineTheme $theme, string $area): array
    {
        try {
            if ($theme && $theme->getId()) {
                ThemeData::setCurrentTheme($theme);
            }
            ThemeData::setCurrentArea($area);

            if ($definition->module === 'Weline_Theme' && $definition->type === 'theme_component') {
                return ThemeData::getParamValues($definition->getMetaIdentify());
            }

            return ThemeData::getWidgetParams($definition->module, $definition->code, null, $area);
        } catch (\Throwable $throwable) {
            return [];
        }
    }

    private function extractParamDefaults(array $params): array
    {
        $defaults = [];
        foreach ($params as $key => $param) {
            if (is_array($param) && array_key_exists('default', $param)) {
                $defaults[$key] = $param['default'];
            }
        }

        return $defaults;
    }

    private function exposeThemeComponentConfigAsMeta(ThemeComponentDefinition $definition, array $config): array
    {
        if ($definition->module !== 'Weline_Theme' || $definition->type !== 'theme_component') {
            return $config;
        }

        $meta = is_array($config['meta'] ?? null) ? $config['meta'] : [];
        foreach ($config as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'meta.') || $key === 'meta.') {
                continue;
            }
            $this->setNestedMetaValue($meta, substr($key, 5), $value);
        }

        $paramKeys = array_unique(array_merge(
            array_keys($definition->params),
            array_keys($definition->defaultConfig),
            array_keys($definition->configSchema)
        ));

        foreach ($paramKeys as $key) {
            if (is_string($key) && array_key_exists($key, $config)) {
                $meta[$key] = $config[$key];
            }
        }

        $config['meta'] = $meta;
        return $config;
    }

    private function setNestedMetaValue(array &$meta, string $path, mixed $value): void
    {
        $parts = array_values(array_filter(explode('.', $path), static fn(string $part): bool => $part !== ''));
        if ($parts === []) {
            return;
        }

        $cursor = &$meta;
        $last = array_pop($parts);
        foreach ($parts as $part) {
            if (!isset($cursor[$part]) || !is_array($cursor[$part])) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }

        $cursor[$last] = $value;
    }

    private function renderBlock(?string $blockClass, array $config): string
    {
        if (!$blockClass) {
            return '';
        }

        $block = ObjectManager::getInstance($blockClass);
        if (method_exists($block, 'setData')) {
            foreach ($config as $key => $value) {
                $block->setData($key, $value);
            }
        }

        if (method_exists($block, 'toHtml')) {
            $html = $block->toHtml();
            return is_string($html) ? $html : '';
        }

        if (method_exists($block, 'fetch')) {
            $html = $block->fetch();
            return is_string($html) ? $html : '';
        }

        return '';
    }
}
