<?php

declare(strict_types=1);

namespace Weline\I18n\Integration\Admin;

use Weline\Admin\Api\Localization\BackendLocaleCatalogInterface;
use Weline\I18n\Api\Localization\LocaleCatalogInterface;

final class BackendLocaleCatalogProvider implements BackendLocaleCatalogInterface
{
    public function __construct(
        private readonly LocaleCatalogInterface $localeCatalog,
    ) {
    }

    public function selectable(
        string $displayLocale,
        int $flagWidth = 24,
        int $flagHeight = 18,
        bool $autoSize = true,
    ): array {
        $result = [];
        foreach ($this->localeCatalog->installedPackages(
            $displayLocale,
            $flagWidth,
            $flagHeight,
            $autoSize,
        ) as $language) {
            $languageCode = (string)($language['code'] ?? '');
            if ($languageCode === '') {
                continue;
            }
            $result[$languageCode] = [
                'name' => (string)($language['name'] ?? $languageCode),
                'flag' => (string)($language['flag'] ?? ''),
            ];
        }
        return $result;
    }
}
