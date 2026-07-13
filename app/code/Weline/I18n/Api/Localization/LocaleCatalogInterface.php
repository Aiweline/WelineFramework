<?php

declare(strict_types=1);

namespace Weline\I18n\Api\Localization;

interface LocaleCatalogInterface
{
    /** @return list<array{code:string,name:string|null}> */
    public function all(string $displayLocale): array;

    /** @return list<array{code:string,name:string,flag:string}> */
    public function installed(string $displayLocale, int $flagWidth = 20, int $flagHeight = 15): array;

    /**
     * Return locale packs that are physically available to the current runtime.
     *
     * This intentionally differs from installed(), which reflects database
     * activation state. Runtime selectors must not advertise a locale whose
     * language pack is absent from disk.
     *
     * @return list<array{code:string,name:string,flag:string}>
     */
    public function installedPackages(
        string $displayLocale,
        int $flagWidth = 24,
        int $flagHeight = 18,
        bool $autoSize = false,
    ): array;
}
