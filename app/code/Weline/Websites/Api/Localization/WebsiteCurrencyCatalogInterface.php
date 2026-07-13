<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Localization;

interface WebsiteCurrencyCatalogInterface
{
    /**
     * Return the currencies enabled for the current website request context.
     *
     * @return list<array{code: string, name: string}>
     */
    public function current(): array;
}
