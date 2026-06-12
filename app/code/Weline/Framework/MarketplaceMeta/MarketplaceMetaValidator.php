<?php

declare(strict_types=1);

namespace Weline\Framework\MarketplaceMeta;

class MarketplaceMetaValidator
{
    public const SUPPORTED_SCHEMA_VERSION = 1;

    /**
     * @return array{errors:string[],warnings:string[]}
     */
    public function validate(array $meta, ?string $expectedModuleName = null, bool $strict = false): array
    {
        $errors = [];
        $warnings = [];

        $schemaVersion = (int)($meta['schema_version'] ?? 0);
        if ($schemaVersion <= 0) {
            $strict ? $errors[] = 'meta_missing_schema_version' : $warnings[] = 'meta_missing_schema_version';
        } elseif ($schemaVersion !== self::SUPPORTED_SCHEMA_VERSION) {
            $strict ? $errors[] = 'meta_schema_version_unsupported' : $warnings[] = 'meta_schema_version_unsupported';
        }

        $moduleName = trim((string)($meta['module_name'] ?? ''));
        if ($moduleName === '') {
            $strict ? $errors[] = 'meta_missing_module_name' : $warnings[] = 'meta_missing_module_name';
        } elseif ($expectedModuleName !== null && $expectedModuleName !== '' && $moduleName !== $expectedModuleName) {
            $errors[] = 'meta_module_mismatch';
        }

        $sourceLocale = '';
        $sourceLocaleData = [];
        $i18n = $meta['i18n'] ?? [];
        if (!is_array($i18n)) {
            $strict ? $errors[] = 'meta_invalid_i18n' : $warnings[] = 'meta_invalid_i18n';
        } else {
            $sourceLocale = trim((string)($i18n['source_locale'] ?? ''));
            $locales = $i18n['locales'] ?? [];
            if ($sourceLocale === '' || !is_array($locales) || !isset($locales[$sourceLocale]) || !is_array($locales[$sourceLocale])) {
                $strict ? $errors[] = 'meta_missing_source_locale' : $warnings[] = 'meta_missing_source_locale';
            } else {
                $sourceLocaleData = $locales[$sourceLocale];
                if (trim((string)($sourceLocaleData['display_name'] ?? '')) === '') {
                    $strict ? $errors[] = 'meta_missing_source_display_name' : $warnings[] = 'meta_missing_source_display_name';
                }
            }
        }

        if (isset($meta['tags']) && !is_array($meta['tags'])) {
            $strict ? $errors[] = 'meta_invalid_tags' : $warnings[] = 'meta_invalid_tags';
        }

        $primaryCount = 0;
        $tags = (array)($meta['tags'] ?? []);
        if ($tags === []) {
            $strict ? $errors[] = 'meta_missing_tags' : $warnings[] = 'meta_missing_tags';
        }

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                $strict ? $errors[] = 'meta_invalid_tag_item' : $warnings[] = 'meta_invalid_tag_item';
                continue;
            }
            $code = trim((string)($tag['code'] ?? ''));
            if ($code === '') {
                $strict ? $errors[] = 'meta_tag_missing_code' : $warnings[] = 'meta_tag_missing_code';
                continue;
            }
            if (!preg_match('/^[a-z0-9_.-]+$/', $code)) {
                $strict ? $errors[] = 'meta_tag_invalid_code' : $warnings[] = 'meta_tag_invalid_code';
            }
            if ($strict && $sourceLocale !== '') {
                $labels = is_array($tag['labels'] ?? null) ? $tag['labels'] : [];
                if (trim((string)($labels[$sourceLocale] ?? '')) === '') {
                    $errors[] = 'meta_tag_missing_source_label';
                }
            }
            if (!empty($tag['primary'])) {
                $primaryCount++;
            }
        }

        if ($primaryCount > 1) {
            $warnings[] = 'meta_multiple_primary';
        } elseif ($primaryCount === 0 && $tags !== []) {
            $warnings[] = 'meta_primary_defaulted';
        }

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
