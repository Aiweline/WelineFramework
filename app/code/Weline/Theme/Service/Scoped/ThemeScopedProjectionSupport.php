<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Scoped;

use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\Theme\Api\Scoped\ThemeEditorContext;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeLayoutScopeNormalizer;
use Weline\Theme\Service\ThemeMetaIdentityService;

/**
 * Shared helpers for scoped Meta/Appearance/I18n projection.
 */
final class ThemeScopedProjectionSupport
{
    public function __construct(
        private readonly WelineTheme $themes,
        private readonly ThemeLayoutScopeNormalizer $scopeNormalizer,
        private readonly ThemeMetaIdentityService $metaIdentities,
    ) {
    }

    public function activeThemeId(string $area): int
    {
        $theme = clone $this->themes;
        try {
            $theme->clearData()->clearQuery()->getActiveTheme($area);

            return (int)$theme->getId();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function contextThemeId(ThemeEditorContext $context): int
    {
        return $context->themeId > 0 ? $context->themeId : $this->activeThemeId($context->area);
    }

    public function legacyWriteScope(ThemeEditorContext $context): string
    {
        return $this->scopeNormalizer->encodeStorageScope(
            $context->scope->storageScope,
            $context->scope->storeMode,
        );
    }

    /** @return list<string> */
    public function legacyReadScopes(ThemeEditorContext $context): array
    {
        return $this->scopeNormalizer->readCandidateScopes($this->legacyWriteScope($context));
    }

    /** @return array{0:string,1:string,2:string} */
    public function metaResourceIdentity(ThemeEditorContext $context): array
    {
        $identify = $this->metaIdentities->targetIdentify(
            $context->area,
            $context->targetType,
            $context->targetId,
            $context->layoutType,
            $context->layoutOption,
        );
        if ($identify === '') {
            $identify = $this->metaIdentities->layoutIdentify(
                $context->layoutType,
                $context->layoutOption,
            );
        }
        if (!str_starts_with($identify, 'theme.')) {
            $identify = 'theme.' . $context->area . '.' . $identify;
        }
        $namespace = 'theme.' . $context->area;
        $prefix = substr($identify, strlen($namespace . '.'));

        return [$namespace, $identify, $prefix];
    }

    /**
     * @param list<MetaConfigRecord> $records
     * @return array<string,MetaConfigRecord>
     */
    public function resolveLegacyRecords(array $records, string $locale): array
    {
        $localeOrder = $locale === '' || $locale === 'default'
            ? [null]
            : array_values(array_unique([$locale, 'zh_Hans_CN', null], SORT_REGULAR));
        $resolved = [];
        $ranks = [];
        foreach ($records as $record) {
            $rank = array_search($record->locale, $localeOrder, true);
            if ($rank === false || (isset($ranks[$record->configKey]) && $ranks[$record->configKey] <= $rank)) {
                continue;
            }
            $ranks[$record->configKey] = $rank;
            $resolved[$record->configKey] = $record;
        }

        return $resolved;
    }

    public function identityForRecord(MetaConfigRecord $record): MetaConfigIdentity
    {
        return new MetaConfigIdentity(
            namespace: $record->namespace,
            configKey: $record->configKey,
            scope: $record->scope,
            locale: $record->locale,
            identifyId: $record->identifyId,
            metaId: $record->metaId,
            metaIdentify: $record->metaIdentify,
        );
    }

    public function encodeLegacyValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    public function decodeLegacyValue(string $value): mixed
    {
        $trimmed = trim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }

    public function isConfigPathSegment(string $segment): bool
    {
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:@-]{0,254}$/D', $segment) === 1;
    }

    public function isAppearanceSegment(string $segment): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $segment) === 1;
    }
}
