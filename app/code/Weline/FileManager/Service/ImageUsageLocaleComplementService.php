<?php

declare(strict_types=1);

namespace Weline\FileManager\Service;

use Weline\FileManager\Api\Data\ImageUsage;
use Weline\FileManager\Model\FileAssetLocale;

/**
 * Merges page ImageUsage copy with FileAssetLocale defaults for the same locale.
 * Usage fields win when non-empty; locale defaults fill gaps only when complement is enabled.
 */
final class ImageUsageLocaleComplementService
{
    /**
     * @return array{alt:string,caption:?string}
     */
    public function resolve(ImageUsage $usage, FileAssetLocale $locale, bool $allowDraftLocale): array
    {
        if ($usage->decorative) {
            return [
                'alt' => '',
                'caption' => $this->normalizeCaption($usage->caption),
            ];
        }

        $alt = trim($usage->alt);
        $caption = $this->normalizeCaption($usage->caption);

        if (!$usage->complement) {
            return ['alt' => $alt, 'caption' => $caption];
        }

        $localeUsable = $allowDraftLocale || $locale->isReviewed();
        if (!$localeUsable) {
            return ['alt' => $alt, 'caption' => $caption];
        }

        if ($alt === '') {
            $defaultAlt = trim((string)$locale->getData(FileAssetLocale::schema_fields_DEFAULT_ALT));
            if ($defaultAlt !== '') {
                $alt = $defaultAlt;
            }
        }

        if ($caption === null) {
            $rawCaption = $locale->getData(FileAssetLocale::schema_fields_DEFAULT_CAPTION);
            $defaultCaption = $rawCaption === null ? '' : trim((string)$rawCaption);
            if ($defaultCaption !== '') {
                $caption = $defaultCaption;
            }
        }

        return ['alt' => $alt, 'caption' => $caption];
    }

    private function normalizeCaption(?string $caption): ?string
    {
        if ($caption === null) {
            return null;
        }
        $caption = trim($caption);

        return $caption === '' ? null : $caption;
    }
}
