<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Scoped;

use Weline\SystemConfig\Api\Scope\ScopeContext;

/** Immutable identity for one scoped Theme resource. */
final readonly class ThemeEditorContext
{
    public const RESOURCE_THEME_BINDING = 'theme_binding';
    public const RESOURCE_LAYOUT = 'layout';
    public const RESOURCE_META = 'meta';
    public const RESOURCE_APPEARANCE = 'appearance';
    public const RESOURCE_I18N = 'i18n';

    public const RESOURCES = [
        self::RESOURCE_THEME_BINDING,
        self::RESOURCE_LAYOUT,
        self::RESOURCE_META,
        self::RESOURCE_APPEARANCE,
        self::RESOURCE_I18N,
    ];

    public function __construct(
        public ScopeContext $scope,
        public string $area,
        public string $resourceType = self::RESOURCE_LAYOUT,
        public int $themeId = 0,
        public string $layoutType = 'default',
        public string $layoutOption = 'default',
        public string $locale = 'default',
        public string $targetType = 'global',
        public int $targetId = 0,
    ) {
        if (!\in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException('theme_editor_context_area_invalid');
        }
        if (!\in_array($resourceType, self::RESOURCES, true)) {
            throw new \InvalidArgumentException('theme_editor_context_resource_invalid');
        }
        foreach ([
            'layout_type' => $layoutType,
            'layout_option' => $layoutOption,
            'locale' => $locale,
            'target_type' => $targetType,
        ] as $field => $value) {
            if (\preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:@-]{0,127}$/D', $value) !== 1) {
                throw new \InvalidArgumentException('theme_editor_context_' . $field . '_invalid');
            }
        }
        if ($themeId < 0 || $targetId < 0) {
            throw new \InvalidArgumentException('theme_editor_context_id_invalid');
        }
    }

    public function withResource(string $resourceType): self
    {
        return new self(
            scope: $this->scope,
            area: $this->area,
            resourceType: $resourceType,
            themeId: $resourceType === self::RESOURCE_THEME_BINDING ? 0 : $this->themeId,
            layoutType: \in_array($resourceType, [self::RESOURCE_THEME_BINDING, self::RESOURCE_APPEARANCE], true)
                ? 'default'
                : $this->layoutType,
            layoutOption: \in_array($resourceType, [self::RESOURCE_THEME_BINDING, self::RESOURCE_APPEARANCE], true)
                ? 'default'
                : $this->layoutOption,
            locale: \in_array($resourceType, [self::RESOURCE_LAYOUT, self::RESOURCE_I18N], true)
                ? $this->locale
                : 'default',
            targetType: \in_array($resourceType, [self::RESOURCE_THEME_BINDING, self::RESOURCE_APPEARANCE], true)
                ? 'global'
                : $this->targetType,
            targetId: \in_array($resourceType, [self::RESOURCE_THEME_BINDING, self::RESOURCE_APPEARANCE], true)
                ? 0
                : $this->targetId,
        );
    }

    public function withScope(ScopeContext $scope): self
    {
        return new self(
            scope: $scope,
            area: $this->area,
            resourceType: $this->resourceType,
            themeId: $this->themeId,
            layoutType: $this->layoutType,
            layoutOption: $this->layoutOption,
            locale: $this->locale,
            targetType: $this->targetType,
            targetId: $this->targetId,
        );
    }

    /** Binding identity intentionally ignores all downstream selectors. */
    public function identityParts(): array
    {
        return [
            $this->scope->storageScope,
            $this->scope->storeMode,
            $this->area,
            $this->resourceType,
            (string)$this->identityThemeId(),
            $this->identityLayoutType(),
            $this->identityLayoutOption(),
            $this->identityLocale(),
            $this->identityTargetType(),
            (string)$this->identityTargetId(),
        ];
    }

    public function identityThemeId(): int
    {
        return $this->resourceType === self::RESOURCE_THEME_BINDING ? 0 : $this->themeId;
    }

    public function identityLayoutType(): string
    {
        return \in_array($this->resourceType, [self::RESOURCE_THEME_BINDING, self::RESOURCE_APPEARANCE], true)
            ? 'default'
            : $this->layoutType;
    }

    public function identityLayoutOption(): string
    {
        return \in_array($this->resourceType, [self::RESOURCE_THEME_BINDING, self::RESOURCE_APPEARANCE], true)
            ? 'default'
            : $this->layoutOption;
    }

    public function identityTargetType(): string
    {
        return \in_array($this->resourceType, [self::RESOURCE_THEME_BINDING, self::RESOURCE_APPEARANCE], true)
            ? 'global'
            : $this->targetType;
    }

    public function identityTargetId(): int
    {
        return \in_array($this->resourceType, [self::RESOURCE_THEME_BINDING, self::RESOURCE_APPEARANCE], true)
            ? 0
            : $this->targetId;
    }

    public function identityHash(): string
    {
        return \hash('sha256', \implode("\0", $this->identityParts()));
    }

    public function identityLocale(): string
    {
        return \in_array($this->resourceType, [self::RESOURCE_LAYOUT, self::RESOURCE_I18N], true)
            ? $this->locale
            : 'default';
    }

    public function canonicalKey(): string
    {
        return $this->scope->canonicalKey() . '|theme-resource=' . \implode('|', \array_slice($this->identityParts(), 2));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scope' => $this->scope->toArray(),
            'area' => $this->area,
            'resource_type' => $this->resourceType,
            'theme_id' => $this->identityThemeId(),
            'layout_type' => $this->identityLayoutType(),
            'layout_option' => $this->identityLayoutOption(),
            'locale' => $this->identityLocale(),
            'target_type' => $this->identityTargetType(),
            'target_id' => $this->identityTargetId(),
            'identity_hash' => $this->identityHash(),
            'canonical_key' => $this->canonicalKey(),
        ];
    }
}
