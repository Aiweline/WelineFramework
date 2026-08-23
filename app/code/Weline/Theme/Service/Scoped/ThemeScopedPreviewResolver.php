<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Theme\Helper\CssVariableInjector;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Api\Scoped\ThemeScopedResourceAdapterInterface;
use Weline\Theme\Api\Scoped\ThemeScopedWorkspaceInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\Disk\ThemeDiskKeys;
use Weline\Theme\Service\Disk\ThemeTokenCatalogService;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemeLayoutService;

/**
 * Controlled Theme Editor preview resolver.
 *
 * A typed, server-validated editor context is mandatory at the call boundary.
 * Draft rendering composes the direct parent's published Release with current
 * patches; published rendering reads Releases only. Legacy rows are used only
 * to attach expendable editor IDs and registry metadata.
 */
final class ThemeScopedPreviewResolver
{
    public function __construct(
        private readonly ThemeScopedWorkspaceInterface $workspace,
        private readonly ThemeScopedResourceAdapterInterface $adapter,
        private readonly ThemeLayoutSnapshotNormalizer $normalizer,
        private readonly ThemeLayoutService $layouts,
        private readonly ThemeLayoutScopeNormalizer $scopeNormalizer,
        private readonly ThemeTokenCatalogService $tokenCatalog,
        private readonly CssVariableInjector $variableInjector,
    ) {
    }

    /** @return array<string,array<string,mixed>> */
    public function resolveLayout(ThemeEditorContext $context, string $status): array
    {
        $context = $context->withResource(ThemeEditorContext::RESOURCE_LAYOUT);
        $includeDraft = $status !== ThemeLayout::STATUS_PUBLISHED;
        $status = $includeDraft ? ThemeLayout::STATUS_DRAFT : ThemeLayout::STATUS_PUBLISHED;
        $state = $this->workspace->load($context, $includeDraft);
        $payload = $state[$includeDraft ? 'draft_payload' : 'published_payload'] ?? null;
        if (!\is_array($payload)) {
            throw new \RuntimeException('theme_scoped_preview_layout_payload_missing');
        }

        // Current-scope shadow rows keep old editor actions from ever receiving
        // an inherited parent's layout_id. They are fully rebuildable and do not
        // establish scoped ownership.
        if ($includeDraft) {
            $this->adapter->projectDraft($context, $payload);
        }

        $layout = $this->normalizer->denormalize($context, $payload);
        $identity = $this->legacyIdentity($context);
        $legacy = $this->layouts->getFullLayout(
            $context->themeId,
            $context->layoutType,
            $status,
            $identity,
        );
        $legacyIds = $this->legacyIdsByUid($legacy);
        $translations = $this->resolveTranslations($context, $includeDraft);

        foreach ($layout as &$areaData) {
            if (!\is_array($areaData['widgets'] ?? null)) {
                continue;
            }
            foreach ($areaData['widgets'] as &$widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                if (isset($legacyIds[$uid])) {
                    $widget['layout_id'] = $legacyIds[$uid];
                } elseif ($includeDraft) {
                    throw new \RuntimeException('theme_scope_draft_projection_node_missing:' . $uid);
                }
                $widget['status'] = $status;
                $widget['scope'] = $identity['scope'];
                $widget['locale_code'] = $identity['locale_code'];
                $widget['target_type'] = $context->targetType;
                $widget['target_id'] = $context->targetId;
                $config = \is_array($widget['config'] ?? null) ? $widget['config'] : [];
                foreach (\is_array($translations[$uid] ?? null) ? $translations[$uid] : [] as $path => $value) {
                    $this->setConfigPath($config, (string)$path, $value);
                }
                // Canonical i18n has already been overlaid. Do not let legacy
                // dictionary state or a browser locale override this preview.
                $config['_skip_translation_merge'] = true;
                $widget['config'] = $config;
            }
            unset($widget);
        }
        unset($areaData);

        return $this->layouts->decorateLayoutForRender($layout, $context->layoutType);
    }

    /** @return array<string,mixed> */
    public function resolveLayoutMeta(ThemeEditorContext $context, string $status): array
    {
        $includeDraft = $status !== ThemeLayout::STATUS_PUBLISHED;
        $metaContext = $this->copyContext(
            $context,
            ThemeEditorContext::RESOURCE_META,
            'default',
        );
        $metaState = $this->workspace->load($metaContext, $includeDraft);
        $metaPayload = $metaState[$includeDraft ? 'draft_payload' : 'published_payload'] ?? [];
        $values = \is_array($metaPayload['values'] ?? null) ? $metaPayload['values'] : [];
        $translations = $this->resolveTranslations($context, $includeDraft);
        foreach (\is_array($translations['layout'] ?? null) ? $translations['layout'] : [] as $path => $value) {
            $this->setConfigPath($values, (string)$path, $value);
        }

        return $values;
    }

    /** @return array<string,mixed> */
    public function resolveAppearance(ThemeEditorContext $context, string $status): array
    {
        $includeDraft = $status !== ThemeLayout::STATUS_PUBLISHED;
        $appearance = $this->copyContext(
            $context,
            ThemeEditorContext::RESOURCE_APPEARANCE,
            'default',
        );
        $state = $this->workspace->load($appearance, $includeDraft);
        $payload = $state[$includeDraft ? 'draft_payload' : 'published_payload'] ?? [];

        return \is_array($payload) ? $payload : [];
    }

    public function renderAppearanceStyle(
        ThemeEditorContext $context,
        WelineTheme $theme,
        string $status,
    ): string {
        $payload = $this->resolveAppearance($context, $status);
        $active = \is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [];
        $custom = \is_array($payload['disks'] ?? null) ? $payload['disks'] : [];
        $catalog = $this->tokenCatalog->getCatalog($context->area, $theme);
        $native = [];
        foreach (\is_array($catalog['disks'] ?? null) ? $catalog['disks'] : [] as $disk) {
            if (!\is_array($disk)) {
                continue;
            }
            $panel = ThemeDiskKeys::normalizePanel((string)($disk['panel'] ?? 'color'));
            $key = \ltrim((string)($disk['key'] ?? ''), '_');
            if ($key !== '') {
                $native[$panel][$key] = $this->tokenMap($disk['tokens'] ?? []);
            }
        }

        $tokens = [];
        foreach ($active as $panel => $ref) {
            $panel = ThemeDiskKeys::normalizePanel((string)$panel);
            $parsed = ThemeDiskKeys::parseActiveRef((string)$ref);
            if (($parsed['kind'] ?? '') === 'file') {
                $tokens = \array_replace($tokens, $native[$panel][(string)$parsed['key']] ?? []);
                continue;
            }
            if (($parsed['kind'] ?? '') !== 'custom') {
                continue;
            }
            $disk = $custom[$panel][(string)$parsed['key']] ?? null;
            if (!\is_array($disk)) {
                continue;
            }
            $base = \ltrim((string)($disk['base_file'] ?? ''), '_');
            if ($base !== '') {
                $tokens = \array_replace($tokens, $native[$panel][$base] ?? []);
            }
            $tokens = \array_replace($tokens, $this->tokenMap($disk['tokens'] ?? []));
        }

        \ksort($tokens);
        $lines = [];
        foreach ($tokens as $name => $value) {
            $value = $this->safeCssValue($value);
            if (!$this->variableInjector->isLateSafeToken($name) || $value === null) {
                continue;
            }
            $lines[] = '  ' . $name . ': ' . $value . ';';
        }
        if ($lines === []) {
            return '';
        }

        return "<style data-theme-scoped-preview-appearance=\"1\">\n:root {\n"
            . \implode("\n", $lines)
            . "\n}\n</style>";
    }

    /** @return array<string,mixed> */
    private function resolveTranslations(ThemeEditorContext $context, bool $includeDraft): array
    {
        if ($context->locale === 'default') {
            return [];
        }
        $i18n = $context->withResource(ThemeEditorContext::RESOURCE_I18N);
        $state = $this->workspace->load($i18n, $includeDraft);
        $payload = $state[$includeDraft ? 'draft_payload' : 'published_payload'] ?? [];

        return \is_array($payload['translations'] ?? null) ? $payload['translations'] : [];
    }

    /** @return array{layout_option:string,scope:string,target_type:string,target_id:int,locale_code:string} */
    private function legacyIdentity(ThemeEditorContext $context): array
    {
        return [
            'layout_option' => $context->layoutOption,
            'scope' => $this->scopeNormalizer->encodeStorageScope(
                $context->scope->storageScope,
                $context->scope->storeMode,
            ),
            'target_type' => $context->targetType,
            'target_id' => $context->targetId,
            'locale_code' => $context->locale === 'default' ? '' : $context->locale,
        ];
    }

    /** @return array<string,int> */
    private function legacyIdsByUid(array $layout): array
    {
        $ids = [];
        foreach ($layout as $areaData) {
            foreach (\is_array($areaData['widgets'] ?? null) ? $areaData['widgets'] : [] as $widget) {
                if (!\is_array($widget)) {
                    continue;
                }
                $uid = \strtolower(\trim((string)($widget['node_uid'] ?? '')));
                $id = (int)($widget['layout_id'] ?? 0);
                if (\preg_match('/^[a-f0-9]{32}$/D', $uid) === 1 && $id > 0) {
                    $ids[$uid] = $id;
                }
            }
        }

        return $ids;
    }

    /** @return array<string,string> */
    private function tokenMap(mixed $tokens): array
    {
        $map = [];
        if (!\is_array($tokens)) {
            return $map;
        }
        if (!\array_is_list($tokens)) {
            foreach ($tokens as $name => $value) {
                $name = (string)$name;
                if (\preg_match('/^--[A-Za-z0-9_-]+$/D', $name) === 1 && \is_scalar($value)) {
                    $map[$name] = (string)$value;
                }
            }
            return $map;
        }
        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                continue;
            }
            $name = \trim((string)($token['variable_name'] ?? $token['name'] ?? ''));
            if (\preg_match('/^--[A-Za-z0-9_-]+$/D', $name) !== 1) {
                continue;
            }
            $value = $token['default_value'] ?? $token['value'] ?? null;
            if (\is_scalar($value)) {
                $map[$name] = (string)$value;
            }
        }

        return $map;
    }

    private function safeCssValue(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }
        $value = \trim((string)$value);
        if ($value === '' || \strlen($value) > 1024) {
            return null;
        }
        if (\preg_match('/[\x00-\x1F\x7F;{}<>\\\\]/', $value) === 1
            || \preg_match('/\/\*|\*\/|@import\b|url\s*\(|expression\s*\(|javascript\s*:|data\s*:/i', $value) === 1
        ) {
            return null;
        }

        return $value;
    }

    /** @param array<string,mixed> $config */
    private function setConfigPath(array &$config, string $path, mixed $value): void
    {
        $path = \trim($path, '.');
        if ($path === '') {
            return;
        }
        $segments = \explode('.', $path);
        $cursor =& $config;
        foreach ($segments as $index => $segment) {
            if ($segment === '' || \preg_match('/^[a-zA-Z0-9_:@-]+$/D', $segment) !== 1) {
                throw new \RuntimeException('theme_scoped_preview_translation_path_invalid');
            }
            if ($index === \count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }
            if (!\is_array($cursor[$segment] ?? null)) {
                $cursor[$segment] = [];
            }
            $cursor =& $cursor[$segment];
        }
        unset($cursor);
    }

    private function copyContext(
        ThemeEditorContext $context,
        string $resourceType,
        string $locale,
    ): ThemeEditorContext {
        return new ThemeEditorContext(
            scope: $context->scope,
            area: $context->area,
            resourceType: $resourceType,
            themeId: $context->themeId,
            layoutType: $context->layoutType,
            layoutOption: $context->layoutOption,
            locale: $locale,
            targetType: $context->targetType,
            targetId: $context->targetId,
        );
    }
}
