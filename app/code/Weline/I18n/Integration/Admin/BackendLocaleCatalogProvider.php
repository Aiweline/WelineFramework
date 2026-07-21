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
        // Use Locale 表「已安装+已激活」集合，而不是语言包目录（目录可能只有默认语言）。
        foreach ($this->localeCatalog->installed(
            $displayLocale,
            $flagWidth,
            $flagHeight,
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
