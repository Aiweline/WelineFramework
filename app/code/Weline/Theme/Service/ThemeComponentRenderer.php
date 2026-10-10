<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Cache\WidgetOutputCache;
use Weline\Framework\View\Template;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Taglib\Slot;

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
        $phase = ThemePdpBudgetPhases::forWidgetCode((string)($definition->code ?? ''))
            ?? ThemePdpBudgetPhases::forWidgetCode((string)($definition->name ?? ''));
        if ($phase !== null) {
            return (string)ThemePdpBudgetPhases::measure(
                $phase,
                fn(): string => $this->doRender($definition, $instanceConfig, $theme, $context),
                [
                    'widget_code' => (string)($definition->code ?? $definition->name ?? ''),
                    'branch' => 'theme_component',
                ],
            );
        }

        return $this->doRender($definition, $instanceConfig, $theme, $context);
    }

    /** The saved config already includes definition and theme defaults. */
    public function renderResolved(ThemeComponentDefinition $definition, array $config, ?WelineTheme $theme = null, array $context = []): string
    {
        $context['configResolved'] = true;
        return $this->render($definition, $config, $theme, $context);
    }

    private function doRender(ThemeComponentDefinition $definition, array $instanceConfig = [], ?WelineTheme $theme = null, array $context = []): string
    {
        // REQ-THEME-0016 / required-default-all-layouts: entity + overlay paths also
        // re-render container widgets (product-info → product-selling-mode). A request-wide
        // Slot registry would throw duplicate id on the second pass (unknown:0 via
        // renderRuntimeTag). Mirror SlotRendererService::doRenderWidget.
        Slot::clearRegisteredSlots();

        $area = $definition->area ?: ((string)($context['area'] ?? 'frontend'));
        $resolved = !empty($context['configResolved']);
        $config = $resolved
            ? $this->normalizeConfigByParamDefinitions($instanceConfig, $definition->params, true)
            : $this->mergeConfig($definition, $instanceConfig, $theme, $area);
        $config = $this->exposeThemeComponentConfigAsMeta($definition, $config, $resolved);
        if (!empty($context['preview_mode'])) {
            $config['preview_mode'] = true;
        }
        $config['theme_component'] = $definition->toArray();
        $config['theme_component_meta'] = $definition->meta;
        // Widget files use both extracted keys and the documented $config variable.
        $dictionary = $config;
        $dictionary['config'] = $config;

        $renderable = !empty($context['block_class'])
            ? new \Weline\Theme\Dto\ThemeRenderable(\Weline\Theme\Dto\ThemeRenderable::MODE_BLOCK_CLASS, blockClass: (string)$context['block_class'])
            : (!empty($context['template_path'])
                ? new \Weline\Theme\Dto\ThemeRenderable(\Weline\Theme\Dto\ThemeRenderable::MODE_TEMPLATE_PATH, templatePath: (string)$context['template_path'])
                : $this->renderableResolver->resolve($definition, $config));
        $assets = ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\WidgetAssetRenderer::class)->render(
            array_merge($definition->meta, $definition->toWidgetArray()), $config, (string)$renderable->templatePath,
        );

        if ($renderable->isTemplateContent()) {
            $origin = (string)($definition->templatePath ?? '');
            if ($origin === '' && $definition->module !== '') {
                $relative = $definition->type === 'theme_component'
                    ? 'components/' . $definition->code . '.phtml'
                    : 'widgets/' . $definition->type . '/' . $definition->code . '/default.phtml';
                try {
                    $origin = (string)($this->template->convertFetchFileName($definition->module . '::theme/' . $area . '/' . $relative)[1] ?? '');
                } catch (\Throwable) {
                    // In-memory definitions may have no registered module source directory.
                }
            }
            return $this->wrapWidgetAssets($this->runtimeTemplateMaterializer->renderContent(
                (string)$renderable->templateContent, $dictionary, $origin, $definition->getIdentity() . ':' . (string)$definition->versionId,
            ), $assets);
        }

        if ($renderable->isBlockClass()) {
            return $this->wrapWidgetAssets($this->renderBlock($renderable->blockClass, $config), $assets);
        }

        $templatePath = (string)$renderable->templatePath;
        if ($templatePath === '') {
            return '';
        }

        $ttl = $this->resolveWidgetOutputCacheTtl($definition, $dictionary, $context);
        $html = WidgetOutputCache::remember(
            $ttl,
            $ttl > 0 ? $this->buildWidgetOutputCacheKey($definition, $templatePath, $dictionary, $ttl, $context) : '',
            function () use ($templatePath, $dictionary): string {
                if (is_file($templatePath)) {
                    return (string)$this->runtimeTemplateMaterializer->renderFile($templatePath, $dictionary);
                }
                $fetched = $this->template->fetchHtml($templatePath, $dictionary);

                return \is_string($fetched) ? $fetched : '';
            },
        );

        return $this->wrapWidgetAssets($html, $assets);
    }

    /**
     * Instance cache TTL wins over @widget.cache meta. No template-policy registry fallback for widgets.
     *
     * @param array<string, mixed> $dictionary
     * @param array<string, mixed> $context
     */
    private function resolveWidgetOutputCacheTtl(
        ThemeComponentDefinition $definition,
        array $dictionary,
        array $context,
    ): int {
        if (!empty($context['preview_mode']) || !empty($context['editor_mode'])) {
            return 0;
        }
        $instance = WidgetOutputCache::normalizeTtl(
            $context['cache'] ?? $dictionary['_cache'] ?? $dictionary['cache'] ?? null,
        );
        if ($instance > 0) {
            return $instance;
        }

        return WidgetOutputCache::normalizeTtl($definition->meta['cache'] ?? 0);
    }

    /**
     * @param array<string, mixed> $dictionary
     * @param array<string, mixed> $context
     */
    private function buildWidgetOutputCacheKey(
        ThemeComponentDefinition $definition,
        string $templatePath,
        array $dictionary,
        int $ttl,
        array $context = [],
    ): string {
        $productId = 0;
        $offerId = 0;
        if (\class_exists(\Weline\Product\Helper\StorefrontOfferResolver::class)) {
            try {
                $offer = \Weline\Product\Helper\StorefrontOfferResolver::currentOffer();
                $productId = max(0, (int)($offer['product_id'] ?? 0));
                $offerId = max(0, (int)($offer['offer_id'] ?? $offer['product_offer_id'] ?? 0));
            } catch (\Throwable) {
            }
        }
        if ($productId <= 0) {
            $productId = max(0, (int)($dictionary['card_product_id'] ?? $dictionary['product_id'] ?? 0));
        }

        $share = WidgetOutputCache::resolveShare(
            ['share' => $context['share'] ?? null],
            \array_merge(
                \is_array($definition->meta ?? null) ? $definition->meta : [],
                $dictionary,
            ),
        );

        return WidgetOutputCache::buildKey([
            'identity' => $definition->getIdentity(),
            'template_path' => $templatePath,
            'node_uid' => (string)($dictionary['node_uid'] ?? $dictionary['_node_uid'] ?? $dictionary['_widget_instance_key'] ?? ''),
            'product_id' => $productId,
            'offer_id' => $offerId,
            'layout_name' => WidgetOutputCache::resolveLayoutName([], $dictionary),
            'share' => $share,
            'ttl' => $ttl,
        ], $dictionary);
    }

    private function wrapWidgetAssets(string $html, string $assets): string
    {
        return ObjectManager::getInstance(\Weline\Theme\Service\LayoutEntity\WidgetAssetRenderer::class)->wrap($html, $assets);
    }

    public function mergeConfig(ThemeComponentDefinition $definition, array $instanceConfig = [], ?WelineTheme $theme = null, string $area = 'frontend'): array
    {
        $config = array_merge(
            $this->extractParamDefaults($definition->params),
            $definition->defaultConfig,
            $this->resolveThemeDefaults($definition, $theme, $area),
            $instanceConfig
        );

        return $this->normalizeConfigByParamDefinitions($config, $definition->params);
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
            $paramName = $this->resolveParamName($key, $param);
            if ($paramName !== null && is_array($param) && array_key_exists('default', $param)) {
                $defaults[$paramName] = $param['default'];
            }
        }

        return $defaults;
    }

    private function exposeThemeComponentConfigAsMeta(ThemeComponentDefinition $definition, array $config, bool $resolved = false): array
    {
        if ($definition->module !== 'Weline_Theme' || $definition->type !== 'theme_component') {
            return $config;
        }

        $meta = is_array($config['meta'] ?? null) ? $config['meta'] : [];
        $meta = $this->normalizeMetaArrayByParamDefinitions($meta, $definition->params, $resolved);
        foreach ($config as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'meta.') || $key === 'meta.') {
                continue;
            }
            $metaPath = substr($key, 5);
            $this->setNestedMetaValue(
                $meta,
                $metaPath,
                $this->normalizeMetaValueByParamDefinition($metaPath, $value, $definition->params, $resolved)
            );
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

    private function normalizeConfigByParamDefinitions(array $config, array $params, bool $resolved = false): array
    {
        foreach ($params as $key => $definition) {
            $paramName = $this->resolveParamName($key, $definition);
            if ($paramName === null || !is_array($definition) || !array_key_exists($paramName, $config)) {
                continue;
            }
            $config[$paramName] = $this->normalizeParamValue($config[$paramName], $definition, $resolved);
        }

        return $config;
    }

    private function normalizeMetaArrayByParamDefinitions(array $meta, array $params, bool $resolved = false): array
    {
        foreach ($params as $key => $definition) {
            $paramName = $this->resolveParamName($key, $definition);
            if ($paramName === null || !is_array($definition) || !array_key_exists($paramName, $meta)) {
                continue;
            }
            $meta[$paramName] = $this->normalizeParamValue($meta[$paramName], $definition, $resolved);
        }

        return $meta;
    }

    private function normalizeMetaValueByParamDefinition(string $metaPath, mixed $value, array $params, bool $resolved = false): mixed
    {
        $definition = $this->findParamDefinition($metaPath, $params);
        if ($definition === null) {
            return $value;
        }

        return $this->normalizeParamValue($value, $definition, $resolved);
    }

    /** Frozen values may be decoded, but an explicit empty value never consults today's defaults. */
    private function normalizeParamValue(mixed $value, array $definition, bool $resolved): mixed
    {
        if (!$resolved) { return ThemeData::normalizeParamValueForDefinition($value, $definition); }
        $expectsArray = strtolower(trim((string)($definition['type'] ?? ''))) === 'array' || is_array($definition['default'] ?? null);
        if (!$expectsArray || !is_string($value) || trim($value) === '') { return $value; }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
    }

    private function findParamDefinition(string $paramName, array $params): ?array
    {
        foreach ($params as $key => $definition) {
            if ($this->resolveParamName($key, $definition) === $paramName && is_array($definition)) {
                return $definition;
            }
        }

        return null;
    }

    private function resolveParamName(string|int $key, mixed $definition): ?string
    {
        if (is_string($key) && $key !== '') {
            return $key;
        }

        if (is_array($definition)) {
            $name = trim((string)($definition['param_name'] ?? $definition['key'] ?? $definition['name'] ?? ''));
            return $name !== '' ? $name : null;
        }

        return null;
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

        $block = clone ObjectManager::getInstance($blockClass);
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
